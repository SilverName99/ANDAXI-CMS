<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Support\NewsletterService;
use PDO;
use Throwable;

/**
 * „Vezi în browser” al emailurilor trimise de MailPoet (pista E; specificația §3.6.1): capătul `view_in_browser`, pe arhiva
 * campaniilor importate (`newsletter_campaigns` cu `sursa` = `mailpoet`).
 *
 * `data` = `[newsletter, hash, abonat|0, jeton|0, coadă, previzualizare]` (sau obiectul cu cheile lui). Orice eșec → 404 cu corpul gol:
 * id-ul și hash-ul campaniei lipsă, campania necunoscută, hash-ul diferit (comparat strict, ca text); un abonat găsit (după
 * `mailpoet_id`) fără jeton sau cu jeton greșit; o coadă a altei campanii; un abonat care n-a primit emailul (afară de previzualizare).
 * Un abonat care nu există se ignoră: pagina iese anonimă.
 *
 * Randarea: HTML-ul arhivat, cu legăturile `[mailpoet_click_data]-<hash>`: pentru un abonat găsit (fără previzualizare), adresele
 * de urmărire ale lui MailPoet cu jetonul cererii; altfel adresele importate (codurile `link:` devin adresele de previzualizare; un hash
 * fără adresă în coadă, prima pagină: importul le numără în avertismente), iar
 * pixelul de deschidere se scoate (sau duce la adresa de deschidere, pentru un abonat găsit care nu a refuzat urmărirea). Fără bara
 * de partajare și fără niciun script (VC-MP-13), cu o politică CSP proprie: doar imagini, stiluri inline și, dacă le are arhiva,
 * fonturile și foile gazdelor permise la import (setarea `mailpoet_arhiva_gazde`), fără fonturile Google (GAZDE_FONTURI_GOOGLE).
 */
final class Arhiva
{
    public const CHEIE_GAZDE = 'mailpoet_arhiva_gazde';

    /**
     * Fonturile Google ale unui email (`<link href="https://fonts.googleapis.com/…">` în head, care cere fișierele de pe
     * `fonts.gstatic.com`): pagina arhivei nu le cere, ca restul site-ului, care își servește fonturile de pe el (fără adresa IP a
     * cititorului la Google). Legătura se scoate la randare, iar CSP-ul nu le permite; emailul se vede cu fonturile de rezervă din
     * stilurile lui (VC-MP-13).
     */
    public const GAZDE_FONTURI_GOOGLE = ['fonts.googleapis.com', 'fonts.gstatic.com'];

    /** @param array<mixed> $date */
    public static function raspunde(PDO $db, array $date, bool $peste): void
    {
        $d = self::transforma($date);
        $previzualizare = !empty($d['preview']);
        if (empty($d['newsletter_id']) || empty($d['newsletter_hash'])) {
            Router::gol(404);
            return;
        }
        $campanie = self::campanie($db, $d['newsletter_id']);
        if ($campanie === null || !is_string($d['newsletter_hash']) || $d['newsletter_hash'] !== (string) $campanie['mailpoet_hash']) {
            Router::gol(404);
            return;
        }
        $abonat = null;
        $jeton = '';
        if (!empty($d['subscriber_id'])) {
            $idMp = is_int($d['subscriber_id']) || (is_string($d['subscriber_id']) && ctype_digit($d['subscriber_id'])) ? (int) $d['subscriber_id'] : 0;
            $abonat = $idMp > 0 ? Urmarire::abonatMailPoet($db, $idMp) : null;
            if ($abonat !== null) {
                $v = (empty($d['subscriber_token']) || $peste) ? null : Jetoane::verifica($db, $abonat, $d['subscriber_token']);
                if ($v === null) {
                    Router::gol(404);
                    return;
                }
                $jeton = $v;
            }
        }
        $coada = null;
        if (!empty($d['queue_id'])) {
            $coada = Abonati::coadaDupaId($db, is_numeric($d['queue_id']) ? (int) $d['queue_id'] : 0);
            if ($coada !== null && (int) $coada['newsletter_mailpoet_id'] !== (int) $campanie['mailpoet_id']) {
                Router::gol(404);
                return;
            }
        }
        if (!$previzualizare && $coada !== null && $abonat !== null && !Abonati::procesat($db, $coada, (int) $abonat['id'])) {
            Router::gol(404);
            return;
        }
        $html = self::randeaza($db, $campanie, $previzualizare ? null : $abonat, $jeton, $coada, $previzualizare);
        http_response_code(200);
        Router::antete('text/html; charset=utf-8');
        header_remove('Content-Security-Policy-Report-Only');
        header('Content-Security-Policy: ' . self::csp($db), true);
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        echo $html;
    }

    /**
     * HTML-ul arhivat al campaniei, randat pentru abonatul dat (null = anonim).
     *
     * @param array<string, mixed> $campanie
     * @param array<string, mixed>|null $abonat
     * @param array<string, mixed>|null $coada
     */
    public static function randeaza(PDO $db, array $campanie, ?array $abonat, string $jeton, ?array $coada, bool $previzualizare): string
    {
        $html = self::faraFonturiGoogle((string) $campanie['html_content']);
        $coadaLinkuri = $coada ?? self::coadaCampaniei($db, (int) $campanie['id']);
        $linkuri = $coadaLinkuri !== null ? self::linkuri($db, (int) $coadaLinkuri['coada_id']) : [];
        $urmarit = $abonat !== null && $coada !== null && (int) ($abonat['mailpoet_id'] ?? 0) > 0;
        $html = (string) preg_replace_callback('/\[mailpoet_click_data\]-(\w+)/', static function (array $m) use ($db, $urmarit, $abonat, $jeton, $coada, $linkuri): string {
            if ($urmarit) {
                return htmlspecialchars(Url::urmarire('click', (int) $abonat['mailpoet_id'], $jeton, (int) $coada['coada_id'], $m[1]), ENT_QUOTES | ENT_HTML401, 'UTF-8');
            }
            // Anonim: adresa importată; un cod `link:` rămâne cod și devine mai jos adresa de previzualizare. Un hash fără adresă în coadă
            // (MailPoet lasă textul `[mailpoet_click_data]-…`, o adresă relativă stricată): prima pagină, ca un clic pe un hash necunoscut.
            // Adresele păstrate de MailPoet sunt cele din `href`, deja codate HTML (`&amp;`): o entitate existentă nu se mai codează o dată
            // (E-PAR-02), restul caracterelor speciale da.
            return isset($linkuri[$m[1]]) ? htmlspecialchars($linkuri[$m[1]], ENT_QUOTES | ENT_HTML401, 'UTF-8', false)
                : htmlspecialchars(NewsletterService::appBaseUrl() . '/', ENT_QUOTES | ENT_HTML401, 'UTF-8');
        }, $html);
        $deschidere = $urmarit && (int) ($abonat['urmarire_refuzata'] ?? 0) === 0
            ? htmlspecialchars(Url::urmarire('open', (int) $abonat['mailpoet_id'], $jeton, (int) $coada['coada_id'], null), ENT_QUOTES | ENT_HTML401, 'UTF-8') : null;
        if ($deschidere !== null) {
            $html = str_replace('[mailpoet_open_data]', $deschidere, $html);
        } else {
            $html = (string) preg_replace('/<img\b[^>]*\[mailpoet_open_data\][^>]*>/i', '', $html);
            $html = str_replace('[mailpoet_open_data]', '', $html);
        }
        return Shortcoduri::inlocuieste($db, $html, ['abonat' => $abonat, 'jeton' => $jeton, 'coada' => $coada, 'campanie' => $campanie,
            'previzualizare' => $previzualizare || $abonat === null]);
    }

    /** Fără legăturile și importurile foilor de fonturi Google (GAZDE_FONTURI_GOOGLE). */
    public static function faraFonturiGoogle(string $html): string
    {
        $gazda = '(?:https?:)?//fonts\.(?:googleapis|gstatic)\.com';
        $html = (string) preg_replace('#<link\b[^>]*?\bhref\s*=\s*["\']?' . $gazda . '(?:[/?"\'\s][^>]*)?>#i', '', $html);
        return (string) preg_replace('#@import\s+(?:url\(\s*)?["\']?' . $gazda . '[^;]*;#i', '', $html);
    }

    /**
     * Politica CSP a paginii: fără scripturi, fără formulare, fără `base`; doar imaginile (https, data:), stilurile inline și gazdele de
     * fonturi și foi găsite la import în HTML-ul arhivat, afară de cele ale fonturilor Google (GAZDE_FONTURI_GOOGLE).
     */
    public static function csp(PDO $db): string
    {
        $gazde = [];
        $v = json_decode((string) Pagini::setare($db, self::CHEIE_GAZDE), true);
        foreach (is_array($v) ? $v : [] as $g) {
            if (is_string($g) && preg_match('/^[a-z0-9][a-z0-9.\-]{0,200}\.[a-z]{2,24}$/D', $g) === 1 && !in_array($g, self::GAZDE_FONTURI_GOOGLE, true)) {
                $gazde[] = 'https://' . $g;
            }
        }
        $extra = $gazde === [] ? '' : ' ' . implode(' ', array_unique($gazde));
        return "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'" . $extra . ($extra !== '' ? '; font-src' . $extra : '')
            . "; base-uri 'none'; form-action 'none'";
    }

    /**
     * @param array<mixed> $date
     * @return array{newsletter_id: mixed, newsletter_hash: mixed, subscriber_id: mixed, subscriber_token: mixed, queue_id: mixed, preview: mixed}
     */
    public static function transforma(array $date): array
    {
        if ($date !== [] && is_int(array_key_first($date))) {
            $date = ['newsletter_id' => !empty($date[0]) ? $date[0] : false, 'newsletter_hash' => !empty($date[1]) ? $date[1] : false,
                'subscriber_id' => !empty($date[2]) ? $date[2] : false, 'subscriber_token' => !empty($date[3]) ? $date[3] : false,
                'queue_id' => !empty($date[4]) ? $date[4] : false, 'preview' => !empty($date[5]) ? $date[5] : false];
        }
        return ['newsletter_id' => $date['newsletter_id'] ?? false, 'newsletter_hash' => $date['newsletter_hash'] ?? false,
            'subscriber_id' => $date['subscriber_id'] ?? false, 'subscriber_token' => $date['subscriber_token'] ?? false,
            'queue_id' => $date['queue_id'] ?? false, 'preview' => $date['preview'] ?? false];
    }

    /** Campania arhivată cu id-ul MailPoet dat. @return array<string, mixed>|null */
    private static function campanie(PDO $db, mixed $idMailPoet): ?array
    {
        $id = is_int($idMailPoet) || (is_string($idMailPoet) && ctype_digit($idMailPoet)) ? (int) $idMailPoet : 0;
        if ($id <= 0) {
            return null;
        }
        try {
            $q = $db->prepare("SELECT id, subject, sent_at, mailpoet_id, mailpoet_hash, html_content FROM newsletter_campaigns
                WHERE mailpoet_id = ? AND sursa = 'mailpoet' AND mailpoet_hash IS NOT NULL LIMIT 1");
            $q->execute([$id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /** Coada campaniei (o campanie standard MailPoet are una), pentru adresele importate ale linkurilor. @return array<string, mixed>|null */
    private static function coadaCampaniei(PDO $db, int $campanie): ?array
    {
        try {
            $q = $db->prepare("SELECT * FROM newsletter_mailpoet_cozi WHERE campanie_id = ? AND tip = 'campanie' ORDER BY coada_id LIMIT 1");
            $q->execute([$campanie]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /** @return array<string, string> hash → adresă */
    private static function linkuri(PDO $db, int $coada): array
    {
        try {
            $q = $db->prepare('SELECT hash, url FROM newsletter_mailpoet_linkuri WHERE coada_id = ?');
            $q->execute([$coada]);
            $r = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $r[(string) $x['hash']] = (string) $x['url'];
            }
            return $r;
        } catch (Throwable) {
            return [];
        }
    }
}
