<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Http\Controllers\SiteController;
use App\Support\CadruCapturat;
use App\Support\Database;
use App\Support\Env;
use App\Support\GardaOrigine;
use App\Support\NewsletterService;
use PDO;
use Throwable;

/**
 * Routerul MailPoet al unui site mutat de pe WordPress (pista E; docs/PLUGINURI.md, „Newsletter”, adresele vechi MailPoet; specificația
 * mailpoet §3): toate adresele din emailurile trimise de MailPoet, pe `/`, prin dispecerul cheilor (App\Support\CompatWp\Interogari,
 * cheile `mailpoet_router` și `mailpoet_page`, mereu deschise: răspund și cu pluginul `newsletter` oprit).
 *
 *  - `?mailpoet_page=<slug>` fără router: documentul capturat al paginii MailPoet (cadrul P), cum l-a capturat importul;
 *  - `?mailpoet_router…&endpoint=…&action=…&data=…`: capătul și acțiunea, după conversia lui MailPoet (`_x` → `X`; capătul cu prima
 *    literă mare), comparate exact; altceva = 404 cu corpul gol. `subscription` (paginile abonamentului, ca regiunea `mailpoet:pagina`
 *    a cadrului R), `track` (clic și deschidere: Urmarire), `view_in_browser` (Arhiva); `captcha`, `cron_daemon` și capetele de admin
 *    = 404, corpul gol (VC-MP-08);
 *  - antetele lui MailPoet pe fiecare răspuns al routerului (fără cache; paginile abonamentului, un-clicul și refuzul unui formular și
 *    `noindex`, `no-referrer`);
 *  - bugetul: LIMITA_MINUT cereri pe minut pe rețea pentru tot routerul (BugetRouter: doar o amprentă HMAC a rețelei, fără user
 *    agent, ștearsă după o oră); peste el, răspunsul unui jeton greșit (nimic aflat), afară de o retragere: dezabonarea (un-clicul,
 *    pagina dezabonării și butonul ei) și oprirea urmăririi nu se refuză niciodată cu pagina F (E-LEG-05); jetoanele scurte le apără
 *    numărul de nepotriviri al abonatului (Jetoane), care nu oprește niciodată un-clicul (SEC-E-02-R1);
 *  - HEAD pe `action=confirm` nu confirmă nimic (un scaner de linkuri nu dă acordul în locul omului; VC-MP-24).
 *
 * În jurnale (error_log) nu ajunge nicio adresă de email, niciun jeton și niciun IP. Singurul IP scris de router e dovada acordului
 * (`confirmed_ip`, la confirmare); bugetul nu păstrează IP-ul.
 */
final class Router
{
    public const LIMITA_MINUT = 60;

    /** Acțiunile capătului `subscription`, după conversie (MailPoet `Router/Endpoints/Subscription.php`). */
    public const ACTIUNI_ABONARE = ['confirm', 'manage', 'unsubscribe', 'unsubscribeReason', 'confirmUnsubscribe', 'reEngagement', 'trackingOptOut'];

    private const CACHE_CONTROL = 'no-cache, must-revalidate, max-age=0, no-store, private';
    private const EXPIRES = 'Wed, 11 Jan 1984 05:00:00 GMT';

    /** Proprietarul cheilor `mailpoet_router` și `mailpoet_page` (Interogari::CHEI). */
    public static function raspunde(string $cheie): void
    {
        $nivel = ob_get_level();
        ob_start();
        try {
            $db = self::db();
            if ($cheie === 'mailpoet_router') {
                self::router($db, $_GET, $_POST, $_SERVER);
            } else {
                self::paginaSimpla($db, $_GET);
            }
        } catch (Throwable $e) {
            while (ob_get_level() > $nivel) {
                ob_end_clean();
            }
            error_log('ANDAXI-CMS: routerul MailPoet: ' . get_class($e) . ' la ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) {
                http_response_code(500);
                self::antete();
            }
            return;
        } finally {
            StarePagina::seteaza(null);
        }
        while (ob_get_level() > $nivel) {
            ob_end_flush();
        }
    }

    /** Acțiunea după conversia lui MailPoet (`confirm_unsubscribe` → `confirmUnsubscribe`); null dacă nu e text. */
    public static function actiune(mixed $actiune): ?string
    {
        return is_string($actiune) ? (string) preg_replace_callback('/_([a-z])/', static fn (array $m): string => strtoupper($m[1]), $actiune) : null;
    }

    /** Capătul după conversie, cu prima literă mare (ca numele clasei lui MailPoet); null dacă nu e text sau e gol. */
    public static function capat(mixed $capat): ?string
    {
        $a = self::actiune($capat);
        return $a === null || $a === '' ? null : ucfirst($a);
    }

    // ───────────────────────────────────────────────────────────
    // Dispecerul
    // ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    public static function router(?PDO $db, array $get, array $post, array $server): void
    {
        $capat = self::capat($get['endpoint'] ?? null);
        $actiune = self::actiune($get['action'] ?? null);
        $date = Date::decodeaza($get['data'] ?? null);
        $cunoscut = ($capat === 'Subscription' && in_array($actiune, self::ACTIUNI_ABONARE, true))
            || ($capat === 'Track' && in_array($actiune, ['click', 'open'], true))
            || ($capat === 'ViewInBrowser' && $actiune === 'view');
        if (!$cunoscut) {
            self::gol(404);
            return;
        }
        if (!$db instanceof PDO) {
            self::gol(503);
            return;
        }
        $metoda = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($metoda === 'HEAD' && $capat === 'Subscription' && $actiune === 'confirm') {
            // Un HEAD (un scaner de linkuri, o previzualizare a clientului de email) nu confirmă: nicio căutare, nimic scris, corpul gol.
            self::gol(200, true);
            return;
        }
        $post = $metoda === 'POST' ? $post : [];
        $peste = !BugetRouter::incearca($db, self::LIMITA_MINUT);
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        if ($capat === 'Subscription') {
            self::abonare($db, (string) $actiune, $date, $get, $post, $metoda === 'POST' || $post !== [], $peste, $uri, $server);
        } elseif ($capat === 'Track') {
            Urmarire::raspunde($db, (string) $actiune, $date, $metoda === 'POST' || $post !== [], $peste);
        } else {
            Arhiva::raspunde($db, $date, $peste);
        }
    }

    /**
     * `?mailpoet_page=<slug>` fără router: documentul capturat al paginii MailPoet cu acel slug (cadrul P al paginii abonamentului,
     * `subscriptions` sau slug-ul din setările MailPoet, ori oricare altă pagină MailPoet capturată), cum e capturat, cu antetele lui;
     * un slug fără document = 404-ul site-ului (WordPress: 404 pentru o pagină MailPoet care nu există).
     *
     * @param array<string, mixed> $get
     */
    private static function paginaSimpla(?PDO $db, array $get): void
    {
        $doc = null;
        $slug = $get['mailpoet_page'] ?? null;
        if ($db instanceof PDO && is_string($slug) && preg_match('/^[a-z0-9][a-z0-9_-]{0,190}$/D', $slug) === 1) {
            $doc = CadruCapturat::document($db, cale: '/?mailpoet_page=' . $slug);
        }
        if ($doc === null) {
            (new SiteController())->paginaNegasita();
            return;
        }
        CadruCapturat::raspunde($db, $doc, ['cale' => '/', 'nonce' => CadruCapturat::nonceZi(), 'an' => date('Y'), 'seo' => []], 200);
    }

    // ───────────────────────────────────────────────────────────
    // Capătul `subscription`
    // ───────────────────────────────────────────────────────────

    /**
     * Mașina de stări a paginilor (§3.3.2 al specificației, cu diferențele declarate VC-MP-03, -04, -11, -16, -17).
     *
     * @param array<mixed> $date
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    private static function abonare(PDO $db, string $actiune, array $date, array $get, array $post, bool $estePost, bool $peste, string $uri,
        array $server): void
    {
        $previzualizare = array_key_exists('preview', $get) || array_key_exists('preview', $date);
        // Retragerile (dezabonarea, oprirea urmăririi) nu țin seama de buget: un om care vrea să plece nu primește niciodată pagina F
        // pentru că alții au cerut mult (E-LEG-05). Ghicitul jetoanelor scurte îl oprește numărul de nepotriviri (Jetoane::verifica).
        $bugetPagina = in_array($actiune, ['unsubscribe', 'confirmUnsubscribe', 'trackingOptOut'], true) ? false : $peste;
        switch ($actiune) {
            case 'unsubscribe':
                if ($estePost && ($post['type'] ?? null) === 'confirmation') {
                    if ($previzualizare) {
                        // Butonul paginii D în previzualizare: pagina C a previzualizării, 200, fără să caute pe cineva (ca MailPoet,
                        // care arată pagina dezabonării și nu scrie nimic în previzualizare).
                        self::pagina($db, Pagini::dezabonat(null, ''), $uri);
                        return;
                    }
                    $g = self::verificat($db, $date, false);
                    if ($g === null) {
                        self::pagina($db, Pagini::necunoscut(), $uri);
                        return;
                    }
                    Abonati::dezaboneaza($db, $g['abonat'], 'newsletter', 'link', $g['coada']);
                    self::redirect(303, Url::abonare($db, 'unsubscribe', $g['abonat'], $g['jeton'], $g['coada'] !== null ? (int) $g['coada']['coada_id'] : null));
                    return;
                }
                if ($estePost) {
                    self::unClic($db, $date, $previzualizare);
                    return;
                }
                break;
            case 'confirmUnsubscribe':
                if ($estePost) {
                    self::unClic($db, $date, $previzualizare);
                    return;
                }
                break;
            case 'confirm':
                $g = self::verificat($db, $date, $peste, true);
                if ($g !== null && !$previzualizare) {
                    self::confirma($db, $g);
                }
                self::pagina($db, Pagini::stare($db, $actiune, $date, $get, $uri, $g['abonat'] ?? null, $g['jeton'] ?? '', $g['coada'] ?? null), $uri);
                return;
            case 'manage':
                if ($estePost) {
                    self::gestionare($db, $post, $peste, $uri, $server);
                    return;
                }
                break;
            case 'unsubscribeReason':
                if (!$estePost) {
                    self::redirect(302, NewsletterService::appBaseUrl() . '/');
                    return;
                }
                $g = self::verificat($db, $date, $peste);
                if (!self::origineFormular($server) || !Jetoane::nonceBun('motiv', (int) ($g['abonat']['id'] ?? 0), $post['_wpnonce'] ?? null)) {
                    self::interzis();
                    return;
                }
                if ($g === null) {
                    self::redirect(302, NewsletterService::appBaseUrl() . '/');
                    return;
                }
                $motiv = is_string($post['reason'] ?? null) ? strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $post['reason']))) : '';
                $salvat = false;
                if ($motiv !== '' && !$previzualizare && (string) $g['abonat']['status'] === 'unsubscribed' && Pagini::setareSondaj($db)['activ']) {
                    $salvat = Abonati::salveazaMotiv($db, (int) $g['abonat']['id'], $g['coada'], $motiv, $post['reason_text'] ?? null,
                        Pagini::setareSondaj($db)['text_liber']);
                }
                $url = Url::abonare($db, 'unsubscribe', $g['abonat'], $g['jeton'], $g['coada'] !== null ? (int) $g['coada']['coada_id'] : null);
                self::redirect(302, $salvat ? Url::cuParametru($url, 'unsubscribe_reason_saved', '1') : $url);
                return;
            case 'trackingOptOut':
                $g = self::verificat($db, $date, false);
                if ($estePost) {
                    if (!self::origineFormular($server) || !Jetoane::nonceBun('urmarire', (int) ($g['abonat']['id'] ?? 0), $post['_wpnonce'] ?? null)) {
                        self::interzis();
                        return;
                    }
                    if ($g !== null && !$previzualizare && (int) ($g['abonat']['urmarire_refuzata'] ?? 0) === 0) {
                        Abonati::opresteUrmarirea($db, $g['abonat']);
                        $g['abonat'] = Abonati::dupaId($db, (int) $g['abonat']['id']) ?? $g['abonat'];
                    }
                }
                self::pagina($db, Pagini::stare($db, $actiune, $date, $get, $uri, $g['abonat'] ?? null, $g['jeton'] ?? '', $g['coada'] ?? null), $uri);
                return;
        }
        $g = self::verificat($db, $date, $bugetPagina);
        self::pagina($db, Pagini::stare($db, $actiune, $date, $get, $uri, $g['abonat'] ?? null, $g['jeton'] ?? '', $g['coada'] ?? null), $uri);
    }

    /**
     * Originea unui formular al paginilor abonamentului (sondajul, oprirea urmăririi, gestionarea): regula GardaOrigine::aceeasiOrigine
     * (`Sec-Fetch-Site` întâi: `cross-site` și `same-site` refuzate; altfel `Origin` egal cu originea site-ului), cu o singură
     * excepție: `Origin: null` fără `Sec-Fetch-Site`. Paginile pleacă cu `Referrer-Policy: no-referrer`, iar un browser fără
     * `Sec-Fetch-Site` (Safari mai vechi de 16.4) trimite atunci, la propriul formular, `Origin: null`; o astfel de cerere trece mai
     * departe la nonce-ul formularului (HMAC pe zi, scop și abonat), care rămâne obligatoriu (E-PAR-01).
     *
     * @param array<string, mixed> $server
     */
    private static function origineFormular(array $server): bool
    {
        if (trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')) === '' && strtolower(trim((string) ($server['HTTP_ORIGIN'] ?? ''))) === 'null') {
            return true;
        }
        return GardaOrigine::aceeasiOrigine($server, (string) Env::get('APP_URL', '')) === null;
    }

    /**
     * Abonatul din `data` cu jetonul verificat (null: necunoscut, jeton greșit sau peste buget), cu forma canonică a jetonului și coada
     * verificată. `$confirmare`: primește și varianta de confirmare (cu `t`), marcată `proaspat`. `$retragere`: verificarea un-clicului
     * (Jetoane::verifica: jetonul întreg trece și peste limita nepotrivirilor unui jeton scurt).
     *
     * @param array<mixed> $date
     * @return array{abonat: array<string, mixed>, jeton: string, coada: ?array<string, mixed>, proaspat: bool}|null
     */
    public static function verificat(PDO $db, array $date, bool $peste, bool $confirmare = false, bool $retragere = false): ?array
    {
        if ($peste) {
            return null;
        }
        $abonat = Abonati::dupaAdresa($db, $date);
        if ($abonat === null) {
            return null;
        }
        $token = $date['token'] ?? null;
        $proaspat = $confirmare && array_key_exists('t', $date) && Jetoane::verificaConfirmare($abonat, $token, $date['t']);
        $jeton = $proaspat ? Jetoane::ax1((int) $abonat['id'], (string) $abonat['email']) : Jetoane::verifica($db, $abonat, $token, $retragere);
        if ($jeton === null || $jeton === '') {
            return null;
        }
        return ['abonat' => $abonat, 'jeton' => $jeton, 'coada' => Abonati::coada($db, $date, $abonat), 'proaspat' => $proaspat];
    }

    /**
     * Confirmarea (`action=confirm`): doar un abonat `pending` (VC-MP-16: un link vechi nu reabonează pe cine s-a dezabonat sau a
     * devenit inactiv); cu varianta de confirmare a unui email trimis de CMS (o cerere nouă, cu `pending_at`), și unul dezabonat,
     * inactiv sau „sărit” (ca linkul de confirmare al CMS-ului: VC-MP-15).
     *
     * @param array{abonat: array<string, mixed>, jeton: string, coada: ?array<string, mixed>, proaspat: bool} $g
     */
    private static function confirma(PDO $db, array $g): void
    {
        $stare = (string) $g['abonat']['status'];
        $stari = ['pending'];
        if ($g['proaspat'] && ($g['abonat']['pending_at'] ?? null) !== null) {
            $stari = ['pending', 'unsubscribed', 'inactive', 'bounced'];
        }
        if (in_array($stare, $stari, true)
            && NewsletterService::confirmaAbonat($db, (int) $g['abonat']['id'], $stari, \App\Support\IpClient::adresa(), 'mailpoet')) {
            // Un acord nou, al lui: adresa iese de pe lista „nu trimite”, ca la linkul de confirmare al CMS-ului (confirmaAbonare; E-LEG-11).
            $db->prepare('DELETE FROM newsletter_suprimate WHERE email_hash = ?')
                ->execute([NewsletterService::amprentaAdresa((string) $g['abonat']['email'])]);
        }
    }

    /**
     * Dezabonarea „cu un clic” (RFC 8058: POST la adresa din `List-Unsubscribe`, sau POST fără `type=confirmation`): doar jetonul
     * abonatului, fără CSRF și fără bugetul cererilor; mereu 200 cu corpul gol și antetele paginilor abonamentului (ca MailPoet, care
     * le trimite la inițializarea paginii), oricare ar fi rezultatul (nimic aflat). Fiindcă răspunsul nu spune nimic, jetonul întreg al
     * abonatului dezabonează și peste limita nepotrivirilor unui jeton scurt, pe care oricine o poate atinge cu jetoane greșite
     * (SEC-E-02-R1; MailPoet primește mereu jetonul întreg).
     *
     * @param array<mixed> $date
     */
    private static function unClic(PDO $db, array $date, bool $previzualizare): void
    {
        $g = self::verificat($db, $date, false, false, true);
        if ($g !== null && !$previzualizare && (string) $g['abonat']['status'] !== 'unsubscribed') {
            Abonati::dezaboneaza($db, $g['abonat'], 'newsletter', 'one_click', $g['coada']);
            if ($g['coada'] !== null && (int) ($g['abonat']['urmarire_refuzata'] ?? 0) === 0) {
                Urmarire::noteazaClicDezabonare($db, $g['coada'], (int) $g['abonat']['id'], (string) $g['abonat']['email']);
            }
        }
        self::gol(200, true);
    }

    /**
     * Formularul de gestionare trimis (VC-MP-03): la adresa paginii, cu nonce-ul `_ax` și verificarea originii; răspunsul e 303 la
     * pagina de gestionare cu `success=1` sau `error=1` (adresa refăcută din abonatul verificat, nu din câmpul `mailpoet_redirect`).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    private static function gestionare(PDO $db, array $post, bool $peste, string $uri, array $server): void
    {
        $inapoi = NewsletterService::appBaseUrl() . (str_starts_with($uri, '/?') ? $uri : '/');
        $date = is_array($post['data'] ?? null) ? $post['data'] : [];
        $g = ($post['action'] ?? null) === 'mailpoet_subscription_update' && $date !== []
            ? self::verificat($db, ['email' => $date['email'] ?? null, 'token' => $post['token'] ?? null], $peste) : null;
        if ($g === null || !self::origineFormular($server) || !Jetoane::nonceBun('gestionare', (int) $g['abonat']['id'], $post['_ax'] ?? null)) {
            self::redirect(303, Url::cuParametru(self::faraParametri($inapoi), 'error', '1'));
            return;
        }
        $rez = Abonati::actualizeaza($db, $g['abonat'], $date, Pagini::campuri($db));
        $baza = Url::abonare($db, 'manage', $g['abonat'], $g['jeton']);
        self::redirect(303, Url::cuParametru($baza, $rez === 'eroare' ? 'error' : 'success', '1'));
    }

    // ───────────────────────────────────────────────────────────
    // Răspunsurile
    // ───────────────────────────────────────────────────────────

    /**
     * O pagină a abonamentului: documentul capturat al cadrului R, cu starea în regiunea `mailpoet:pagina`, statusul stării și antetele
     * routerului, fără trackerele site-ului (adresa paginii poartă adresa de email și jetonul abonatului; ca previzualizarea din admin,
     * VC-MP-23, E-LEG-07). Fără cadru (un import fără captura paginii), aceeași pagină într-un document simplu: dezabonarea merge oricum.
     */
    private static function pagina(PDO $db, StarePagina $stare, string $uri): void
    {
        StarePagina::seteaza($stare);
        $doc = CadruCapturat::document($db, cale: Url::pagina($db) . '&mailpoet_router&endpoint=subscription&action=manage&data=' . Date::codifica(['preview' => 1]));
        if ($doc !== null) {
            CadruCapturat::raspunde($db, $doc, ['cale' => '/', 'nonce' => CadruCapturat::nonceZi(), 'an' => date('Y'), 'seo' => [], 'urmarire' => []],
                $stare->status, null, true);
        } else {
            http_response_code($stare->status);
            echo "<!DOCTYPE html>\n<html lang=\"ro-RO\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
                . '<meta name="robots" content="noindex,nofollow"><title>' . htmlspecialchars($stare->titlu . (string) Pagini::setare($db, RandarePagina::CHEIE_SUFIX),
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') . '</title></head><body><h1 class="entry-title">'
                . htmlspecialchars($stare->titlu, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8') . '</h1><div class="entry-content">' . $stare->interiorContinut()
                . "</div></body></html>\n";
        }
        self::antete('text/html; charset=UTF-8', true);
    }

    /** Antetele lui MailPoet pe orice răspuns al routerului (și, pe paginile abonamentului, `noindex` și `no-referrer`). */
    public static function antete(string $tip = 'text/html; charset=UTF-8', bool $paginaAbonare = false): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: ' . self::CACHE_CONTROL, true);
        header('Expires: ' . self::EXPIRES, true);
        header('Pragma: no-cache', true);
        header('Content-Type: ' . $tip, true);
        if ($paginaAbonare) {
            header_remove('X-Robots-Tag');
            header('X-Robots-Tag: noindex, nofollow', true);
            header('Referrer-Policy: no-referrer', true);
        }
    }

    /**
     * Un răspuns cu corpul gol (404 al unui capăt necunoscut, 200 al unei dezabonări „cu un clic”: acesta, cu `$paginaAbonare`, și cu
     * `noindex` și `no-referrer`).
     */
    public static function gol(int $cod, bool $paginaAbonare = false): void
    {
        http_response_code($cod);
        self::antete('text/html; charset=UTF-8', $paginaAbonare);
    }

    public static function redirect(int $cod, string $locatie): void
    {
        http_response_code($cod);
        self::antete();
        header('Location: ' . str_replace(["\r", "\n"], '', $locatie), true, $cod);
    }

    /** Verificarea de securitate a eșuat (un nonce sau o origine greșită): 403, cu textul lui MailPoet și antetele paginilor abonamentului. */
    private static function interzis(): void
    {
        http_response_code(403);
        self::antete('text/html; charset=UTF-8', true);
        echo "<!DOCTYPE html>\n<html lang=\"ro-RO\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . '<meta name="robots" content="noindex,nofollow"><title>Eroare</title></head><body><p>' . Texte::VERIFICARE_ESUATA . "</p></body></html>\n";
    }

    /** Adresa fără `success` și `error` (formularul se trimite la adresa paginii, care le poate avea). */
    private static function faraParametri(string $url): string
    {
        $q = strpos($url, '?');
        if ($q === false) {
            return $url;
        }
        $parti = array_filter(explode('&', substr($url, $q + 1)), static fn (string $x): bool => !preg_match('/^(success|error)(=|$)/', $x));
        return substr($url, 0, $q) . '?' . implode('&', $parti);
    }

    private static function db(): ?PDO
    {
        try {
            $config = require __DIR__ . '/../../../config/app.php';
            $db = Database::connection((array) ($config['db'] ?? []));
        } catch (Throwable) {
            return null;
        }
        if ($db instanceof PDO) {
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return $db;
    }
}
