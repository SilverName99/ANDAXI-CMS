<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Http\Controllers\SiteController;
use App\Support\NewsletterService;
use PDO;
use Throwable;

/**
 * Capătul `track` al routerului MailPoet (pista E; specificația §3.5): clicurile și deschiderile din emailurile trimise de MailPoet.
 *
 * `data` e lista `["<abonat MailPoet>","<jeton>","<coadă>","<hash>"|null,<previzualizare>]` (sau obiectul cu cheile ei); un element
 * „gol” (ca `!empty` în PHP) lipsește. Abonatul e cel cu `mailpoet_id` din listă (id-urile din adresele vechi sunt ale lui MailPoet).
 *
 * Clicul: date greșite, abonat sau coadă necunoscute, jeton greșit, abonat neprocesat în coadă → 404, pagina 404 a site-ului; un link
 * necunoscut al cozii → 302 la prima pagină; altfel clicul se notează (fără cookie-uri, VC-MP-12; nimic cu urmărirea refuzată, în
 * previzualizare sau pe linkul de oprire a urmăririi) și 302 la adresa importată pentru acel hash. Codurile `link:` duc la adresele
 * abonării în forma MailPoet, cu jetonul cererii. Niciodată spre o gazdă venită din date: o adresă a cărei gazdă se schimbă după
 * codurile scurte duce la prima pagină (aceeasiDestinatie).
 *
 * Un POST pe linkul de dezabonare al cozii (antetul `List-Unsubscribe` urmărit al emailurilor din 2024; linkul se găsește după coadă și
 * hash, fără jeton) e dezabonarea „cu un clic”: cu jetonul bun dezabonează pe loc, și răspunde MEREU același 200 cu corpul gol, fără
 * redirecționare (VC-MP-01, RFC 8058 §3.1), oricare ar fi jetonul, sub sau peste bugetul routerului. Fiindcă răspunsul nu spune nimic,
 * jetonul întreg trece și peste limita nepotrivirilor unui jeton scurt (SEC-E-02-R1, ca un-clicul neurmărit, Router::unClic). Peste
 * bugetul routerului (Router::LIMITA_MINUT), un GET primește răspunsul unui jeton greșit (404), iar orice alt POST același 200 cu corpul
 * gol, fără ca jetonul să fie cântărit (SEC-E-02).
 *
 * Un clic pe un link al abonamentului (dezabonarea, administrarea, oprirea urmăririi) se notează, dar nu reactivează un abonat inactiv
 * (E-LEG-04): nu e un semn că vrea emailuri.
 *
 * Deschiderea: mereu 200, exact GIF-ul de 43 de octeți al lui MailPoet; se notează doar o prima deschidere validă.
 */
final class Urmarire
{
    /** GIF-ul transparent al lui MailPoet (43 de octeți). */
    public const GIF = 'R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==';

    /** Codurile linkurilor abonamentului: un clic pe ele nu reactivează (E-LEG-04). */
    private const LINKURI_ABONAMENT = ['subscription_unsubscribe_url', 'subscription_instant_unsubscribe_url', 'subscription_manage_url',
        'subscription_tracking_opt_out_url'];

    /** Caracterele după care se termină gazda unei adrese (sau care nu pot sta în ea). */
    private const SFARSIT_GAZDA = "/?#\\ \t\n\r\f\v";

    /** @param array<mixed> $date */
    public static function raspunde(PDO $db, string $actiune, array $date, bool $estePost, bool $peste): void
    {
        $d = self::transforma($date);
        if ($actiune === 'open') {
            $v = $peste ? null : self::valid($db, $d);
            if ($v !== null && empty($d['preview']) && (int) ($v['abonat']['urmarire_refuzata'] ?? 0) === 0) {
                self::noteazaDeschidere($db, $v['coada'], $v['abonat']);
            }
            http_response_code(200);
            Router::antete('image/gif');
            echo base64_decode(self::GIF);
            return;
        }
        if ($estePost) {
            $coadaPost = self::idPozitiv($d['queue_id']);
            $linkPost = $coadaPost > 0 ? self::link($db, $coadaPost, $d['link_hash']) : null;
            if (self::esteDezabonare($linkPost)) {
                self::dezabonareUrmarita($db, $d, (string) $linkPost);
                return;
            }
            if ($peste) {
                // Orice alt POST peste buget: același 200 gol, fără ca jetonul să fie cântărit (SEC-E-02).
                Router::gol(200, true);
                return;
            }
        }
        $v = $peste ? null : self::valid($db, $d);
        if ($v === null) {
            self::negasit();
            return;
        }
        $link = self::link($db, (int) $v['coada']['coada_id'], $d['link_hash']);
        if ($link === null) {
            Router::redirect(302, NewsletterService::appBaseUrl() . '/');
            return;
        }
        $abonat = $v['abonat'];
        $coada = $v['coada'];
        $jeton = $v['jeton'];
        $noteaza = empty($d['preview']) && (int) ($abonat['urmarire_refuzata'] ?? 0) === 0 && $link !== '[link:subscription_tracking_opt_out_url]';
        $a = self::actiuneLink($link);
        if ($a !== null) {
            if ($a === '') {
                self::negasit();
                return;
            }
            $campanie = self::campanie($db, $coada);
            $tinta = match ($a) {
                'subscription_unsubscribe_url' => Url::abonare($db, 'confirm_unsubscribe', $abonat, $jeton, (int) $coada['coada_id']),
                'subscription_instant_unsubscribe_url' => Url::abonare($db, 'unsubscribe', $abonat, $jeton, (int) $coada['coada_id']),
                'subscription_manage_url' => Url::abonare($db, 'manage', $abonat, $jeton),
                'subscription_tracking_opt_out_url' => Url::abonare($db, 'tracking_opt_out', $abonat, $jeton),
                'subscription_re_engage_url' => Url::abonare($db, 're_engagement', $abonat, $jeton),
                'newsletter_view_in_browser_url' => $campanie !== null && (int) ($campanie['mailpoet_id'] ?? 0) > 0
                    ? Url::vizualizare((int) $campanie['mailpoet_id'], (string) $campanie['mailpoet_hash'], (int) $abonat['mailpoet_id'], $jeton,
                        (int) $coada['coada_id'], false) : null,
                default => null,
            };
            if ($tinta === null) {
                Router::redirect(302, NewsletterService::appBaseUrl() . '/');
                return;
            }
            if ($noteaza) {
                self::noteazaClic($db, $coada, $abonat, $link);
            }
            Router::redirect(302, $tinta);
            return;
        }
        // O adresă simplă: codurile abonatului din ea se înlocuiesc (ca text); o gazdă schimbată de ele = prima pagină.
        $tinta = Shortcoduri::inlocuieste($db, $link, ['abonat' => $abonat, 'jeton' => $jeton, 'coada' => $coada, 'campanie' => self::campanie($db, $coada),
            'text' => true]);
        if ($tinta !== $link && !self::aceeasiDestinatie($link, $tinta)) {
            $tinta = NewsletterService::appBaseUrl() . '/';
        }
        if (preg_match('#^(https?:)?//#i', $tinta) !== 1 && !str_starts_with($tinta, '/') && preg_match('/^(mailto|tel):/i', $tinta) !== 1) {
            $tinta = NewsletterService::appBaseUrl() . '/';
        }
        if ($noteaza) {
            self::noteazaClic($db, $coada, $abonat, $link);
        }
        Router::redirect(302, $tinta);
    }

    /**
     * Lista `data` în forma cu chei (MailPoet `Links::transformUrlDataObject`): un obiect cu chei rămâne cum e.
     *
     * @param array<mixed> $date
     * @return array{subscriber_id: mixed, subscriber_token: mixed, queue_id: mixed, link_hash: mixed, preview: mixed}
     */
    public static function transforma(array $date): array
    {
        if ($date !== [] && is_int(array_key_first($date))) {
            $date = ['subscriber_id' => !empty($date[0]) ? $date[0] : false, 'subscriber_token' => !empty($date[1]) ? $date[1] : false,
                'queue_id' => !empty($date[2]) ? $date[2] : false, 'link_hash' => !empty($date[3]) ? $date[3] : false,
                'preview' => !empty($date[4]) ? $date[4] : false];
        }
        return ['subscriber_id' => $date['subscriber_id'] ?? false, 'subscriber_token' => $date['subscriber_token'] ?? false,
            'queue_id' => $date['queue_id'] ?? false, 'link_hash' => $date['link_hash'] ?? false, 'preview' => $date['preview'] ?? false];
    }

    /**
     * Abonatul (după `mailpoet_id`), coada și jetonul, verificate (jetonul, procesarea în coadă), sau null. `$retragere`: verificarea
     * dezabonării „cu un clic” (Jetoane::verifica: jetonul întreg trece și peste limita nepotrivirilor unui jeton scurt).
     *
     * @param array<string, mixed> $d
     * @return array{abonat: array<string, mixed>, coada: array<string, mixed>, jeton: string}|null
     */
    private static function valid(PDO $db, array $d, bool $retragere = false): ?array
    {
        if (empty($d['subscriber_id']) || empty($d['subscriber_token']) || empty($d['queue_id'])) {
            return null;
        }
        $idMp = self::idPozitiv($d['subscriber_id']);
        $idCoada = self::idPozitiv($d['queue_id']);
        if ($idMp <= 0 || $idCoada <= 0) {
            return null;
        }
        $coada = Abonati::coadaDupaId($db, $idCoada);
        $abonat = self::abonatMailPoet($db, $idMp);
        if ($coada === null || $abonat === null) {
            return null;
        }
        $jeton = Jetoane::verifica($db, $abonat, $d['subscriber_token'], $retragere);
        if ($jeton === null || !Abonati::procesat($db, $coada, (int) $abonat['id'])) {
            return null;
        }
        return ['abonat' => $abonat, 'coada' => $coada, 'jeton' => $jeton];
    }

    /** @return array<string, mixed>|null */
    public static function abonatMailPoet(PDO $db, int $idMailPoet): ?array
    {
        try {
            $q = $db->prepare('SELECT * FROM newsletter_subscribers WHERE mailpoet_id = ? LIMIT 1');
            $q->execute([$idMailPoet]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /**
     * Un POST pe linkul de dezabonare al cozii (dezabonarea „cu un clic” prin antetul `List-Unsubscribe` urmărit): mereu 200 cu corpul
     * gol și antetele un-clicului, oricare ar fi jetonul, sub sau peste bugetul routerului (nimic aflat despre jeton, RFC 8058: nicio
     * redirecționare). Cu jetonul bun (și peste limita nepotrivirilor unui jeton scurt: răspunsul nu arată nimic, SEC-E-02-R1), clicul
     * se notează (ca MailPoet, afară de previzualizare și de urmărirea refuzată) și abonatul se dezabonează, dacă nu era deja. Un jeton
     * greșit se numără ca oricare altul (Jetoane::verifica), deci tot nu ajută la ghicirea lui pe paginile care îl arată.
     *
     * @param array<string, mixed> $d
     */
    private static function dezabonareUrmarita(PDO $db, array $d, string $link): void
    {
        $v = self::valid($db, $d, true);
        if ($v !== null && empty($d['preview'])) {
            // Clicul se notează doar pe cererea care chiar dezabonează. Altfel, peste limita jetoanelor scurte, fiecare ghicire
            // bună ar scrie un rând (iar una greșită nimic): o diferență de timp repetabilă. Așa, o ghicire bună scrie cel mult o
            // dată, dezabonarea însăși (SEC-E-02-R2).
            if ((string) $v['abonat']['status'] !== 'unsubscribed') {
                if ((int) ($v['abonat']['urmarire_refuzata'] ?? 0) === 0) {
                    self::noteazaClic($db, $v['coada'], $v['abonat'], $link);
                }
                Abonati::dezaboneaza($db, $v['abonat'], 'newsletter', 'one_click', $v['coada']);
            }
        }
        Router::gol(200, true);
    }

    /** Un id din `data` (un număr întreg sau un text doar din cifre), altfel 0. */
    private static function idPozitiv(mixed $v): int
    {
        return is_int($v) || (is_string($v) && $v !== '' && ctype_digit($v)) ? (int) $v : 0;
    }

    /**
     * Acțiunea unui cod de link din adresa importată: textul dintre `[link:` și primul `]` de după el, fără spațiile de la capete
     * (`[link:subscription_manage_url]` → `subscription_manage_url`); null dacă adresa nu are un astfel de cod (sau dacă între cele
     * două se rupe rândul: atunci e o adresă obișnuită).
     */
    private static function actiuneLink(?string $link): ?string
    {
        if ($link === null || ($inceput = strpos($link, '[link:')) === false) {
            return null;
        }
        $sfarsit = strpos($link, ']', $inceput + 6);
        if ($sfarsit === false) {
            return null;
        }
        $actiune = substr($link, $inceput + 6, $sfarsit - $inceput - 6);
        return str_contains($actiune, "\n") ? null : trim($actiune);
    }

    /** Linkul importat e unul de dezabonare (`[link:subscription_unsubscribe_url]` sau `…instant_unsubscribe_url]`)? */
    private static function esteDezabonare(?string $link): bool
    {
        return in_array(self::actiuneLink($link), ['subscription_unsubscribe_url', 'subscription_instant_unsubscribe_url'], true);
    }

    /** Adresa importată pentru (coadă, hash), sau null. */
    private static function link(PDO $db, int $coada, mixed $hash): ?string
    {
        if (!is_string($hash) || preg_match('/^[A-Za-z0-9]{1,20}$/D', $hash) !== 1) {
            return null;
        }
        try {
            $q = $db->prepare('SELECT url FROM newsletter_mailpoet_linkuri WHERE coada_id = ? AND hash = ?');
            $q->execute([$coada, $hash]);
            $u = $q->fetchColumn();
        } catch (Throwable) {
            return null;
        }
        return is_string($u) && $u !== '' ? $u : null;
    }

    /** Campania cozii (arhiva MailPoet), sau null (o coadă de automatizare). @param array<string, mixed> $coada @return array<string, mixed>|null */
    public static function campanie(PDO $db, array $coada): ?array
    {
        $id = (int) ($coada['campanie_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        try {
            $q = $db->prepare('SELECT id, subject, sent_at, mailpoet_id, mailpoet_hash, html_content, text_content FROM newsletter_campaigns WHERE id = ?');
            $q->execute([$id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /**
     * O adresă importată, după înlocuirea codurilor abonatului, duce tot acolo unde ducea? Adresa trimisă trebuie să înceapă cu partea
     * fixă a celei importate (inceputFix), citită din adresa așa cum e păstrată sau din forma ei decodată (procente, apoi entități
     * HTML: codurile pot sta codate în adresă). Fără parte fixă, nicio schimbare nu e îngăduită. Regula e descrisă în docs/PLUGINURI.md
     * („Clicurile din emailurile vechi”).
     */
    private static function aceeasiDestinatie(string $stocat, string $trimis): bool
    {
        foreach (array_unique([$stocat, html_entity_decode(rawurldecode($stocat), ENT_QUOTES | ENT_HTML5, 'UTF-8')]) as $forma) {
            $fix = self::inceputFix($forma);
            if ($fix !== null && strncmp($trimis, $fix, strlen($fix)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Partea unei adrese care nu are voie să se schimbe (null: niciuna):
     *  - o adresă cu gazdă (`schemă://gazdă…` sau `//gazdă…`): totul până la primul `/`, `?` sau `#` de după gazdă, inclusiv; dacă după
     *    gazdă nu vine unul dintre ele (vine un cod scurt, de exemplu), gazda nu e fixă (codul ar putea lungi numele ei) și contează
     *    doar regulile de mai jos;
     *  - `mailto:`, `tel:`, `sms:`: schema;
     *  - altă schemă fără gazdă (nu http sau https): schema și primul caracter de după ea, dacă nu e `/`, `\` sau un spațiu.
     * Schema e o literă urmată de litere, cifre, `+`, `.` sau `-`, apoi `:`; o adresă fără schemă și fără gazdă nu are parte fixă.
     */
    private static function inceputFix(string $url): ?string
    {
        $schema = null;
        $doua = strpos($url, ':');
        if ($doua !== false && $doua > 0 && ctype_alpha($url[0])
            && strspn($url, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+.-', 0, $doua) === $doua) {
            $schema = strtolower(substr($url, 0, $doua));
        }
        $dupa = $schema === null ? 0 : $doua + 1;
        if (substr($url, $dupa, 2) === '//') {
            $gazda = strcspn($url, self::SFARSIT_GAZDA, $dupa + 2);
            $urmator = $url[$dupa + 2 + $gazda] ?? '';
            if ($gazda > 0 && ($urmator === '/' || $urmator === '?' || $urmator === '#')) {
                return substr($url, 0, $dupa + 3 + $gazda);
            }
        }
        if ($schema === null || $schema === 'http' || $schema === 'https') {
            return null;
        }
        if (in_array($schema, ['mailto', 'tel', 'sms'], true)) {
            return substr($url, 0, $dupa);
        }
        $primul = $url[$dupa] ?? '';
        return $primul !== '' && strpbrk($primul, "/\\ \t\n\r\f\v") === false ? substr($url, 0, $dupa + 1) : null;
    }

    /**
     * Clicul unei campanii importate: `click_count` + 1 și `clicked_at` (prima dată) pe rândul trimiterii, un rând în
     * `newsletter_campaign_clicks` (adresa sau codul linkului, fără IP), totalul campaniei, apoi și deschiderea (ca MailPoet). La o coadă
     * de automatizare, doar implicarea (statisticile lor sunt ale pistei J). Implicarea reactivează un abonat inactiv, afară de un clic
     * pe un link al abonamentului (dezabonarea, administrarea, oprirea urmăririi; E-LEG-04).
     *
     * @param array<string, mixed> $coada
     * @param array<string, mixed> $abonat
     */
    public static function noteazaClic(PDO $db, array $coada, array $abonat, string $url): void
    {
        try {
            $campanie = (int) ($coada['campanie_id'] ?? 0);
            if ((string) ($coada['tip'] ?? '') === 'campanie' && $campanie > 0) {
                $db->prepare('UPDATE newsletter_campaign_sends SET click_count = COALESCE(click_count, 0) + 1, clicked_at = COALESCE(clicked_at, NOW())
                    WHERE campaign_id = ? AND subscriber_id = ?')->execute([$campanie, (int) $abonat['id']]);
                $db->prepare('INSERT INTO newsletter_campaign_clicks (campaign_id, subscriber_id, email, url, clicked_at, ip) VALUES (?, ?, ?, ?, NOW(), NULL)')
                    ->execute([$campanie, (int) $abonat['id'], (string) $abonat['email'], mb_substr($url, 0, 2048)]);
                $db->prepare('UPDATE newsletter_campaigns SET total_clicks = COALESCE(total_clicks, 0) + 1 WHERE id = ?')->execute([$campanie]);
            }
            self::noteazaDeschidere($db, $coada, $abonat, !in_array(self::actiuneLink($url), self::LINKURI_ABONAMENT, true));
        } catch (Throwable $e) {
            error_log('ANDAXI-CMS: MailPoet: un clic nu s-a putut nota (' . get_class($e) . ')');
        }
    }

    /**
     * Clicul pe linkul de dezabonare al cozii, la o dezabonare „cu un clic” prin antetul neurmărit (ca MailPoet: rândul clicului pe
     * `[link:subscription_instant_unsubscribe_url]` al cozii, dacă există).
     *
     * @param array<string, mixed> $coada
     */
    public static function noteazaClicDezabonare(PDO $db, array $coada, int $abonatId, string $email): void
    {
        try {
            $q = $db->prepare('SELECT url FROM newsletter_mailpoet_linkuri WHERE coada_id = ? AND url = ? LIMIT 1');
            $q->execute([(int) $coada['coada_id'], '[link:subscription_instant_unsubscribe_url]']);
            if ($q->fetchColumn() !== false) {
                self::noteazaClic($db, $coada, ['id' => $abonatId, 'email' => $email, 'status' => 'unsubscribed'], '[link:subscription_instant_unsubscribe_url]');
            }
        } catch (Throwable) {
        }
    }

    /**
     * Deschiderea: doar prima pe (abonat, campanie), ca MailPoet (totalurile importate înseamnă „cititori unici”); implicarea
     * (`last_engagement_at`) de fiecare dată, iar un abonat inactiv redevine abonat pe loc (MailPoet o face la rularea zilnică a regulii
     * inactivității, InactiveSubscribersController::activateSubscribers, care nu rulează pe site-ul mutat), cu un UPDATE simplu, ca acolo:
     * `last_subscribed_at` rămâne cel de dinainte. `$reactiveaza` false: implicarea fără reactivare (un clic pe un link al abonamentului).
     *
     * @param array<string, mixed> $coada
     * @param array<string, mixed> $abonat
     */
    public static function noteazaDeschidere(PDO $db, array $coada, array $abonat, bool $reactiveaza = true): void
    {
        try {
            $campanie = (int) ($coada['campanie_id'] ?? 0);
            if ((string) ($coada['tip'] ?? '') === 'campanie' && $campanie > 0) {
                $q = $db->prepare('UPDATE newsletter_campaign_sends SET opened_at = NOW(), open_count = 1 WHERE campaign_id = ? AND subscriber_id = ? AND opened_at IS NULL');
                $q->execute([$campanie, (int) $abonat['id']]);
                if ($q->rowCount() > 0) {
                    $s = $db->prepare('SELECT id FROM newsletter_campaign_sends WHERE campaign_id = ? AND subscriber_id = ?');
                    $s->execute([$campanie, (int) $abonat['id']]);
                    $db->prepare('INSERT INTO newsletter_campaign_opens (campaign_id, send_id, opened_at, ip) VALUES (?, ?, NOW(), NULL)')
                        ->execute([$campanie, (int) ($s->fetchColumn() ?: 0) ?: null]);
                    $db->prepare('UPDATE newsletter_campaigns SET total_opens = COALESCE(total_opens, 0) + 1 WHERE id = ?')->execute([$campanie]);
                }
            }
            $db->prepare('UPDATE newsletter_subscribers SET last_engagement_at = NOW() WHERE id = ?')->execute([(int) $abonat['id']]);
            if ($reactiveaza) {
                $db->prepare("UPDATE newsletter_subscribers SET status = 'active' WHERE id = ? AND status = 'inactive'")
                    ->execute([(int) $abonat['id']]);
            }
        } catch (Throwable $e) {
            error_log('ANDAXI-CMS: MailPoet: o deschidere nu s-a putut nota (' . get_class($e) . ')');
        }
    }

    /** Pagina 404 a site-ului, cu antetele routerului. */
    private static function negasit(): void
    {
        (new SiteController())->paginaNegasita();
        http_response_code(404);
        Router::antete();
    }
}
