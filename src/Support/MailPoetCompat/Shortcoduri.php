<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Support\NewsletterService;
use PDO;
use Throwable;

/**
 * Codurile scurte MailPoet (`[categorie:acțiune | argument:valoare]`) din emailurile importate (pista E: arhiva campaniilor, emailul
 * de confirmare; pista J: emailurile automatizărilor), înlocuite ca la MailPoet 5.45.2 (specificația §4.6.3):
 *
 *  - `subscriber:` firstname, lastname (altfel `default:`), email, displayname (numele contului, altfel `default:`), count;
 *  - `newsletter:` subject (subiectul campaniei sau al emailului dat în context; fără el, gol, ca MailPoet fără newsletter), total
 *    (câte articole are conținutul, `data-post-id`), post_title și number (ale notificărilor de articole: gol);
 *  - `site:` title, homepage_url, homepage_link;
 *  - `date:` d, dordinal, dtext, m, mtext, y, custom | format: (în română, în ora României; la o campanie trimisă, data trimiterii);
 *  - `link:` adresele abonării în forma MailPoet (Url), cu jetonul dat; fără abonat, adresele de previzualizare (`{"preview":1}`).
 *
 * Valorile se scapă pentru HTML (ca MailPoet); în modul text (subiectul, partea text) se pun cum sunt. O adresă dintr-un `href` sau
 * `src` care, după înlocuire, are altă schemă decât http(s), mailto, tel sau una relativă se golește. Un cod necunoscut rămâne cum e
 * (importul îl refuză dinainte: necunoscute()).
 *
 * Forma unui cod (coduri(), parti(); regula în docs/PLUGINURI.md, „Codurile scurte”): `[`, categoria (un cuvânt: litere ASCII, cifre,
 * `_`), `:`, apoi orice până la primul `]` de pe același rând; `[cuvânt://` nu e un cod (o adresă între paranteze drepte). Codurile nu
 * se suprapun: după un cod, căutarea continuă după `]`-ul lui. În cod, acțiunea e cuvântul de imediat după `:`; urmează fie `]`, fie,
 * după primul `|`, argumentul (primul cuvânt urmat direct de `:`) și valoarea lui (restul, până la `]`). Altă formă: un cod fără
 * categorie și acțiune, care rămâne cum e.
 */
final class Shortcoduri
{
    /** Caracterele unui cuvânt (categoria, acțiunea, argumentul). */
    private const CUVANT = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    /** Acțiunile știute, pe categorie. */
    public const STIUTE = [
        'subscriber' => ['firstname', 'lastname', 'email', 'displayname', 'count'],
        'newsletter' => ['subject', 'total', 'post_title', 'number'],
        'site' => ['title', 'homepage_url', 'homepage_link'],
        'date' => ['d', 'dordinal', 'dtext', 'm', 'mtext', 'y', 'custom'],
        'link' => ['subscription_unsubscribe_url', 'subscription_instant_unsubscribe_url', 'subscription_manage_url',
            'subscription_tracking_opt_out_url', 'subscription_re_engage_url', 'newsletter_view_in_browser_url'],
    ];

    private const ZILE = ['duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă'];
    private const LUNI = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie',
        'decembrie'];

    /**
     * Codurile scurte necunoscute dintr-un text (pentru NU la import): cele cu o categorie sau o acțiune din afara listei, plus câmpurile
     * personalizate (`subscriber:cf_<id>`: niciunul nu există pe site-ul mutat).
     *
     * @return list<string>
     */
    public static function necunoscute(string $text): array
    {
        $rez = [];
        foreach (self::extrage($text) as $sc) {
            $m = self::parti($sc);
            $cat = strtolower((string) ($m['categorie'] ?? ''));
            if ($cat === 'mailpoet_click_data' || $cat === 'mailpoet_open_data') {
                continue;
            }
            if (!isset(self::STIUTE[$cat]) || !in_array((string) ($m['actiune'] ?? ''), self::STIUTE[$cat], true)
                || ($cat === 'date' && $m['actiune'] === 'custom' && ($m['argument'] ?? '') !== 'format')) {
                $rez[] = $sc;
            }
        }
        return array_values(array_unique($rez));
    }

    /**
     * Înlocuirea codurilor scurte.
     *
     * @param array{abonat?: ?array<string, mixed>, jeton?: string, coada?: ?array<string, mixed>, campanie?: ?array<string, mixed>,
     *   previzualizare?: bool, text?: bool, data?: ?int, continut?: string} $ctx `abonat` null = niciunul (valorile `default:`);
     *   `campanie`: campania (sau emailul) cu `subject`, `sent_at` și cheile MailPoet; `previzualizare`: linkurile abonării de
     *   previzualizare; `text`: fără scăpare HTML; `data`: momentul codurilor `date:` (implicit trimiterea campaniei sau acum);
     *   `continut`: HTML-ul emailului pentru `newsletter:total` (implicit textul dat, ca MailPoet pe corp)
     */
    public static function inlocuieste(PDO $db, string $s, array $ctx): string
    {
        if (!str_contains($s, '[')) {
            return $s;
        }
        $text = (bool) ($ctx['text'] ?? false);
        $ctx += ['continut' => $s];
        $valoare = static fn (string $sc): ?string => self::valoare($db, $sc, $ctx);
        if (!$text) {
            // Adresele din href și src: valoarea pusă acolo, apoi schema verificată (o adresă cu altă schemă se golește).
            $s = (string) preg_replace_callback('/(\s(?:href|src)\s*=\s*)(["\'])(.*?)\2/is', static function (array $m) use ($valoare): string {
                if (self::coduri($m[3]) === []) {
                    return $m[0];
                }
                $v = self::inlocuiesteCoduri($m[3], $valoare);
                $decodat = trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $decodat, $sch) === 1 && !in_array(strtolower($sch[1]), ['http', 'https', 'mailto', 'tel'], true)) {
                    $v = '';
                }
                return $m[1] . $m[2] . $v . $m[2];
            }, $s);
        }
        return self::inlocuiesteCoduri($s, $valoare);
    }

    /**
     * Codurile scurte dintr-un text, în ordine și fără să se suprapună (forma: docblock-ul clasei), cu poziția fiecăruia.
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function coduri(string $s): array
    {
        $rez = [];
        $n = strlen($s);
        $i = 0;
        while (($i = strpos($s, '[', $i)) !== false) {
            $categorie = strspn($s, self::CUVANT, $i + 1);
            $doua = $i + 1 + $categorie;
            if ($categorie > 0 && ($s[$doua] ?? '') === ':' && substr($s, $doua + 1, 2) !== '//') {
                $inchis = $doua + 1 + strcspn($s, "]\n", $doua + 1);
                if ($inchis < $n && $s[$inchis] === ']') {
                    $rez[] = [$i, substr($s, $i, $inchis - $i + 1)];
                    $i = $inchis + 1;
                    continue;
                }
            }
            $i++;
        }
        return $rez;
    }

    /** Textul cu fiecare cod înlocuit de valoarea lui (null: codul rămâne cum e). @param callable(string): ?string $valoare */
    private static function inlocuiesteCoduri(string $s, callable $valoare): string
    {
        $rez = '';
        $poz = 0;
        foreach (self::coduri($s) as [$i, $cod]) {
            $rez .= substr($s, $poz, $i - $poz) . ($valoare($cod) ?? $cod);
            $poz = $i + strlen($cod);
        }
        return $rez . substr($s, $poz);
    }

    /** @return list<string> */
    private static function extrage(string $s): array
    {
        return array_values(array_unique(array_column(self::coduri($s), 1)));
    }

    /**
     * Părțile unui cod (forma: docblock-ul clasei): `categorie`, `actiune` și, după `|`, `argument` și `valoare`; [] pentru altă formă.
     * Un cod cu un `[` în el (`[a:[b:c]`) se citește de la primul `[` de la care are forma (aici `[b:c]`), ca la MailPoet.
     *
     * @return array<string, string>
     */
    public static function parti(string $cod): array
    {
        if ($cod === '' || $cod[strlen($cod) - 1] !== ']') {
            return [];
        }
        for ($p = strpos($cod, '['); $p !== false; $p = strpos($cod, '[', $p + 1)) {
            $rez = self::partiDela(substr($cod, $p + 1, -1));
            if ($rez !== []) {
                return $rez;
            }
        }
        return [];
    }

    /** Părțile interiorului unui cod (fără `[` și `]`), sau []. @return array<string, string> */
    private static function partiDela(string $interior): array
    {
        $categorie = strspn($interior, self::CUVANT);
        $actiune = ($interior[$categorie] ?? '') === ':' ? strspn($interior, self::CUVANT, $categorie + 1) : 0;
        if ($actiune === 0) {
            return [];
        }
        $rez = ['categorie' => substr($interior, 0, $categorie), 'actiune' => substr($interior, $categorie + 1, $actiune)];
        $rest = substr($interior, $categorie + 1 + $actiune);
        if ($rest === '') {
            return $rez;
        }
        $bara = strpos($rest, '|');
        if ($bara === false) {
            return [];
        }
        $dupa = substr($rest, $bara + 1);
        $lung = strlen($dupa);
        for ($k = strcspn($dupa, self::CUVANT); $k < $lung; $k += strcspn($dupa, self::CUVANT, $k)) {
            $cuvant = strspn($dupa, self::CUVANT, $k);
            if (($dupa[$k + $cuvant] ?? '') === ':') {
                return $rez + ['argument' => substr($dupa, $k, $cuvant), 'valoare' => substr($dupa, $k + $cuvant + 1)];
            }
            $k += $cuvant;
        }
        return [];
    }

    /** Valoarea unui cod scurt, sau null (necunoscut: rămâne cum e). @param array<string, mixed> $ctx */
    private static function valoare(PDO $db, string $sc, array $ctx): ?string
    {
        $m = self::parti($sc);
        $cat = strtolower((string) ($m['categorie'] ?? ''));
        $act = (string) ($m['actiune'] ?? '');
        $arg = (string) ($m['argument'] ?? '');
        $argVal = (string) ($m['valoare'] ?? '');
        $text = (bool) ($ctx['text'] ?? false);
        $e = static fn (string $v): string => $text ? $v : htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
        $abonat = is_array($ctx['abonat'] ?? null) ? $ctx['abonat'] : null;
        $implicit = $arg === 'default' ? $argVal : '';
        switch ($cat) {
            case 'subscriber':
                return match ($act) {
                    'firstname' => $e(trim((string) ($abonat['first_name'] ?? '')) !== '' ? (string) $abonat['first_name'] : $implicit),
                    'lastname' => $e(trim((string) ($abonat['last_name'] ?? '')) !== '' ? (string) $abonat['last_name'] : $implicit),
                    'email' => $e((string) ($abonat['email'] ?? '')),
                    'displayname' => $e(self::numeCont($db, $abonat) ?? $implicit),
                    'count' => (string) self::numarAbonati($db),
                    default => null,
                };
            case 'newsletter':
                return match ($act) {
                    'subject' => is_array($ctx['campanie'] ?? null) ? $e((string) ($ctx['campanie']['subject'] ?? '')) : '',
                    'total' => (string) substr_count((string) ($ctx['continut'] ?? ''), 'data-post-id'),
                    'post_title', 'number' => '',
                    default => null,
                };
            case 'site':
                $nume = Pagini::numeSite($db);
                return match ($act) {
                    // Scăpat pentru HTML, ca orice valoare (SEC-E-06); în modul text, cum e.
                    'title' => $e($nume),
                    'homepage_url' => NewsletterService::appBaseUrl(),
                    'homepage_link' => '<a target="_blank" href="' . htmlspecialchars(NewsletterService::appBaseUrl(), ENT_QUOTES | ENT_HTML401, 'UTF-8') . '">'
                        . htmlspecialchars($nume, ENT_QUOTES | ENT_HTML401, 'UTF-8') . '</a>',
                    default => null,
                };
            case 'date':
                $moment = (int) ($ctx['data'] ?? 0);
                if ($moment <= 0) {
                    $trimis = is_array($ctx['campanie'] ?? null) ? strtotime((string) ($ctx['campanie']['sent_at'] ?? '')) : false;
                    $moment = $trimis !== false && $trimis > 0 ? $trimis : time();
                }
                $formate = ['d' => 'd', 'dordinal' => 'jS', 'dtext' => 'l', 'm' => 'm', 'mtext' => 'F', 'y' => 'Y'];
                if (isset($formate[$act])) {
                    return self::data($formate[$act], $moment);
                }
                return $act === 'custom' && $arg === 'format' ? $e(self::data($argVal, $moment)) : null;
            case 'link':
                return self::link($db, $act, $ctx, $text);
        }
        return null;
    }

    /** Adresa unui cod `link:` (forma MailPoet), scăpată pentru HTML în afara modului text. @param array<string, mixed> $ctx */
    private static function link(PDO $db, string $act, array $ctx, bool $text): ?string
    {
        $previzualizare = (bool) ($ctx['previzualizare'] ?? false);
        $abonat = $previzualizare ? null : (is_array($ctx['abonat'] ?? null) ? $ctx['abonat'] : null);
        $jeton = (string) ($ctx['jeton'] ?? '');
        if ($abonat !== null && $jeton === '') {
            $jeton = Jetoane::ax1((int) $abonat['id'], (string) $abonat['email']);
        }
        $coada = is_array($ctx['coada'] ?? null) ? (int) $ctx['coada']['coada_id'] : null;
        $url = match ($act) {
            'subscription_unsubscribe_url' => Url::abonare($db, 'confirm_unsubscribe', $abonat, $jeton, $coada),
            'subscription_instant_unsubscribe_url' => Url::abonare($db, 'unsubscribe', $abonat, $jeton, $coada),
            'subscription_manage_url' => Url::abonare($db, 'manage', $abonat, $jeton),
            'subscription_tracking_opt_out_url' => Url::abonare($db, 'tracking_opt_out', $abonat, $jeton),
            'subscription_re_engage_url' => Url::abonare($db, 're_engagement', $abonat, $jeton),
            'newsletter_view_in_browser_url' => is_array($ctx['campanie'] ?? null) && (int) ($ctx['campanie']['mailpoet_id'] ?? 0) > 0
                ? Url::vizualizare((int) $ctx['campanie']['mailpoet_id'], (string) ($ctx['campanie']['mailpoet_hash'] ?? ''),
                    $abonat !== null ? (int) ($abonat['mailpoet_id'] ?? 0) : 0, $abonat !== null ? $jeton : '', (int) ($coada ?? 0), $previzualizare || $abonat === null)
                : NewsletterService::appBaseUrl() . '/',
            default => null,
        };
        if ($url === null) {
            return null;
        }
        return $text ? $url : htmlspecialchars($url, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    }

    /** Numele contului legat (`wp_user_id` → contul clientului: numele afișat), sau null. @param array<string, mixed>|null $abonat */
    private static function numeCont(PDO $db, ?array $abonat): ?string
    {
        $wp = (int) ($abonat['wp_user_id'] ?? 0);
        if ($wp <= 0) {
            return null;
        }
        try {
            $q = $db->prepare('SELECT * FROM users WHERE wp_user_id = ? LIMIT 1');
            $q->execute([$wp]);
            $u = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($u)) {
            return null;
        }
        foreach (['display_name', 'name'] as $c) {
            if (is_string($u[$c] ?? null) && trim((string) $u[$c]) !== '') {
                return trim((string) $u[$c]);
            }
        }
        return null;
    }

    private static function numarAbonati(PDO $db): int
    {
        try {
            return (int) $db->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active'")->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** O dată în formatul PHP dat, cu numele zilelor și ale lunilor în română, în ora României. */
    public static function data(string $format, int $moment): string
    {
        $d = (new \DateTimeImmutable('@' . $moment))->setTimezone(new \DateTimeZone('Europe/Bucharest'));
        $rez = '';
        $n = strlen($format);
        for ($i = 0; $i < $n; $i++) {
            $c = $format[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $rez .= $format[++$i];
                continue;
            }
            $rez .= match ($c) {
                'l' => self::ZILE[(int) $d->format('w')],
                'D' => mb_substr(self::ZILE[(int) $d->format('w')], 0, 3),
                'F' => self::LUNI[(int) $d->format('n') - 1],
                'M' => mb_substr(self::LUNI[(int) $d->format('n') - 1], 0, 3),
                default => ctype_alpha($c) ? $d->format($c) : $c,
            };
        }
        return $rez;
    }
}
