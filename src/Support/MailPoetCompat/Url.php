<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Support\NewsletterService;
use PDO;
use Throwable;

/**
 * Adresele în forma MailPoet construite de CMS (pista E): paginile abonamentului (confirmarea, gestionarea, dezabonarea cu confirmare,
 * dezabonarea, motivul dezabonării, oprirea urmăririi, reangajarea), urmărirea (clic, deschidere) și „vezi în browser”, exact în
 * forma lui MailPoet 5.45.2, ca linkurile din emailurile vechi și din cele noi să meargă la fel.
 *
 *  - pagina abonamentului: setarea `mailpoet_pagina_abonare` (implicit `/?mailpoet_page=subscriptions`), apoi
 *    `&mailpoet_router&endpoint=subscription&action=<acțiune>&data=<b64>`;
 *  - `data`: `queueId` (doar la dezabonări și la motivul dezabonării, când coada e cunoscută), `token`, `email`, în ordinea asta; fără
 *    abonat `{"preview":1}`. Codarea e a lui MailPoet (Date::codifica), necodată pentru URL;
 *  - urmărirea și „vezi în browser” stau pe adresa site-ului fără `/` (`https://site?mailpoet_router=&endpoint=track…`), ca la MailPoet;
 *  - în paginile site-ului (regiunea `mailpoet:pagina`) adresele sunt relative, ca restul documentului capturat; în emailuri și în
 *    redirecționări, absolute (APP_URL).
 */
final class Url
{
    public const PAGINA_IMPLICITA = '/?mailpoet_page=subscriptions';
    public const CHEIE_PAGINA = 'mailpoet_pagina_abonare';

    /** Acțiunile care primesc `queueId` (MailPoet: dezabonările și motivul dezabonării). */
    private const CU_COADA = ['unsubscribe', 'confirm_unsubscribe', 'unsubscribe_reason'];

    private static ?string $pagina = null;

    /** Calea paginii abonamentului (cu query-ul ei), din setări; implicit pagina MailPoet. */
    public static function pagina(?PDO $db = null): string
    {
        if (self::$pagina !== null) {
            return self::$pagina;
        }
        $p = self::PAGINA_IMPLICITA;
        if ($db instanceof PDO) {
            try {
                $q = $db->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
                $q->execute([self::CHEIE_PAGINA]);
                $v = $q->fetchColumn();
                if (is_string($v) && preg_match('#^/[^\s"<>\\\\]*$#D', $v) === 1 && !str_starts_with($v, '//')) {
                    $p = $v;
                }
            } catch (Throwable) {
            }
        }
        return self::$pagina = $p;
    }

    /** Pentru teste: uită pagina citită. */
    public static function uita(): void
    {
        self::$pagina = null;
    }

    /**
     * Adresa unei acțiuni a abonării. `$abonat` null = previzualizarea (`{"preview":1}`). `$jeton`: cel verificat al cererii sau al CMS-ului.
     * `$extra` se adaugă în `data` după `email` (de exemplu `t` la varianta de confirmare).
     *
     * @param array<string, mixed>|null $abonat
     * @param array<string, mixed> $extra
     */
    public static function abonare(?PDO $db, string $actiune, ?array $abonat, string $jeton = '', ?int $coada = null, bool $absoluta = true,
        array $extra = []): string
    {
        if ($abonat === null) {
            $date = ['preview' => 1];
        } else {
            $date = [];
            if ($coada !== null && $coada > 0 && in_array($actiune, self::CU_COADA, true)) {
                $date['queueId'] = $coada;
            }
            $date['token'] = $jeton;
            $date['email'] = (string) ($abonat['email'] ?? '');
            $date += $extra;
        }
        $pagina = self::pagina($db);
        $url = $pagina . (str_contains($pagina, '?') ? '&' : '?') . 'mailpoet_router&endpoint=subscription&action=' . $actiune
            . '&data=' . Date::codifica($date);
        return $absoluta ? NewsletterService::appBaseUrl() . $url : $url;
    }

    /** Adresa de urmărire (clic sau deschidere): `data` = `["<abonat>","<jeton>","<coadă>","<hash>"|null,<previzualizare>]`. */
    public static function urmarire(string $actiune, int $abonatId, string $jeton, int $coada, ?string $hash, bool $previzualizare = false): string
    {
        return NewsletterService::appBaseUrl() . '?mailpoet_router=&endpoint=track&action=' . $actiune . '&data='
            . Date::codifica([(string) $abonatId, $jeton, (string) $coada, $hash, $previzualizare]);
    }

    /** „Vezi în browser”: `data` = `[newsletter, hash, abonat|0, jeton|0, coadă|0, previzualizare]`. */
    public static function vizualizare(int $newsletter, string $hash, int $abonatId, string $jeton, int $coada, bool $previzualizare): string
    {
        return NewsletterService::appBaseUrl() . '?mailpoet_router=&endpoint=view_in_browser&action=view&data='
            . Date::codifica([$newsletter, $hash !== '' ? $hash : 0, $abonatId > 0 ? $abonatId : 0, $abonatId > 0 && $jeton !== '' ? $jeton : 0,
                $coada > 0 ? $coada : 0, (int) $previzualizare]);
    }

    /** Adresa cu un parametru adăugat sau înlocuit (ca add_query_arg pe o adresă cu query): `&nume=valoare`, înaintea unui `#`. */
    public static function cuParametru(string $url, string $nume, string $valoare): string
    {
        $fragment = '';
        $p = strpos($url, '#');
        if ($p !== false) {
            $fragment = substr($url, $p);
            $url = substr($url, 0, $p);
        }
        $q = strpos($url, '?');
        if ($q === false) {
            return $url . '?' . $nume . '=' . $valoare . $fragment;
        }
        $baza = substr($url, 0, $q);
        $parti = substr($url, $q + 1) === '' ? [] : explode('&', substr($url, $q + 1));
        $parti = array_values(array_filter($parti, static fn (string $x): bool => $x !== $nume && !str_starts_with($x, $nume . '=')));
        $parti[] = $nume . '=' . $valoare;
        return $baza . '?' . implode('&', $parti) . $fragment;
    }
}
