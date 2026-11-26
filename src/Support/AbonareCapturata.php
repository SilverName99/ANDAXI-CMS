<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\MailPoetCompat\Date;
use App\Support\MailPoetCompat\Url;
use PDO;
use Throwable;

/**
 * Serverul formularelor de abonare Divi (`et_pb_signup`, furnizorul MailPoet) ale unui site capturat mutat de pe WordPress (pista E a
 * mutării unui site WooCommerce; docs/PLUGINURI.md, „Newsletter”). Clientul e runtime-ul compat `divi4/v1/abonare.js`: validarea lui
 * Divi în browser, apoi `POST /_andaxi/abonare/<n>` (n = al câtelea formular de abonare din pagină, în ordinea documentului), cu calea
 * paginii (`cale`, cu query-ul ei), adresa (`et_pb_signup_email`), numele (doar dacă formularul are câmpurile lor) și nonce-ul zilei al
 * paginii (`et_frontend_nonce` din configurația `et_pb_custom`, ca Divi).
 *
 * Doar pe cadrul capturat, doar cu `wp-compat.json` → `mailpoet`; altfel nu face nimic (cererea merge mai departe, ca înainte).
 * Ordinea, fiecare pas fail-closed:
 *  1. mărimea (MARIME_CERERE octeți, MAX_CAMPURI câmpuri: 413) și originea (GardaOrigine::aceeasiOrigine: 403);
 *  2. bugetul vizitatorului (LimitaCereri, pe rețeaua lui: INCERCARI_15_MIN în 15 minute, INCERCARI_ZI pe zi);
 *  3. nonce-ul zilei (de azi sau de ieri, hash_equals);
 *  4. lista vine din DOCUMENTUL CAPTURAT al căii, niciodată din cerere: formularul n al paginii, cu `et_pb_signup_provider` = `mailpoet` și
 *     `et_pb_signup_list_id` = id-ul listei MailPoet → lista CMS cu acel `mailpoet_id` (fiecare formular cu lista scrisă în el, în
 *     documentul capturat);
 *  5. adresa (cel mult 190 de caractere, FILTER_VALIDATE_EMAIL) și numele (fără taguri, cel mult 255);
 *  6. NewsletterService::cerereAbonare: dubla confirmare, cu limitele ei pe adresă, pe IP și pe site; sursa `api`, IP-ul cererii.
 *
 * Răspunsul (JSON, `no-store`), în forma pe care o așteaptă scriptul Divi: `{"success": true}` (abonat nou, cerere trimisă, adresă
 * deja abonată sau o adresă care a primit deja emailurile de confirmare îngăduite: același răspuns, ca pagina să nu spună cine e abonat
 * și ca MailPoet, care nu mai trimite confirmarea dar arată succesul) sau `false` (orice altă problemă: clientul arată textul Divi
 * `subscription_failed` din pagină). Nicio adresă în jurnale.
 */
final class AbonareCapturata
{
    public const MARIME_CERERE = 16384;
    public const MAX_CAMPURI = 20;
    public const INCERCARI_15_MIN = 20;
    public const INCERCARI_ZI = 60;
    public const PREFIX = '/_andaxi/abonare/';

    /**
     * Pentru public/index.php (înaintea cache-ului de pagini, ca formularele de contact; după decizia sesiunii, care pentru această cale
     * nu o pornește: SesiuneLaNevoie::postFaraSesiune, deci răspunsul nu pune cookie-ul sesiunii): o trimitere a unui formular de
     * abonare capturat primește aici răspunsul (true); altfel false. Ruta `POST /_andaxi/abonare/{n}` a pistei A
     * (App\Http\Controllers\AbonareCapturataController, condiția `mailpoet`) duce la același răspuns (raspunde()).
     */
    public static function intercepteaza(?PDO $db, string $metoda, string $uri): bool
    {
        $cale = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        if (!str_starts_with($cale, self::PREFIX) || Tema::cadru() !== 'capturat' || !WpCompat::mailpoet()) {
            return false;
        }
        if (preg_match('#^/_andaxi/abonare/(\d{1,3})$#D', $cale, $m) !== 1 || strtoupper($metoda) !== 'POST' || !$db instanceof PDO) {
            self::raspuns(404, false);
            return true;
        }
        self::raspunde($db, (int) $m[1]);
        return true;
    }

    /** Răspunsul unei trimiteri a formularului n (corpul cererii din `$_POST`, antetele din `$_SERVER`). */
    public static function raspunde(PDO $db, int $n): void
    {
        $rez = self::proceseaza($db, $n, $_POST, $_SERVER);
        self::raspuns($rez['cod'], $rez['ok'] ? ['success' => true] : false);
    }

    /**
     * Trimiterea, de la mărime până la cererea de abonare (pașii din capul clasei).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @return array{ok: bool, cod: int, motiv: string}
     */
    public static function proceseaza(PDO $db, int $n, array $post, array $server): array
    {
        $nu = static fn (string $motiv, int $cod = 200): array => ['ok' => false, 'cod' => $cod, 'motiv' => $motiv];
        if ((int) ($server['CONTENT_LENGTH'] ?? 0) > self::MARIME_CERERE || count($post, COUNT_RECURSIVE) > self::MAX_CAMPURI) {
            return $nu('prea_mare', 413);
        }
        if (GardaOrigine::aceeasiOrigine($server, (string) Env::get('APP_URL', '')) !== null) {
            return $nu('origine', 403);
        }
        if (!LimitaCereri::incearca($db, 'abonare_wp', self::INCERCARI_15_MIN, 15) || !LimitaCereri::incearca($db, 'abonare_wp_zi', self::INCERCARI_ZI, 1440)) {
            return $nu('buget');
        }
        $nonce = $post['et_frontend_nonce'] ?? '';
        $bun = false;
        foreach ([date('Y-m-d'), date('Y-m-d', time() - 86400)] as $zi) {
            $asteptat = CadruCapturat::nonceZi($zi);
            $bun = $bun || ($asteptat !== '' && is_string($nonce) && hash_equals($asteptat, $nonce));
        }
        if (!$bun) {
            return $nu('nonce');
        }
        if (!Plugins::permiteInceput('newsletter')) {
            return $nu('plugin');
        }
        $cale = is_string($post['cale'] ?? null) ? (string) $post['cale'] : '';
        $formular = $cale !== '' ? self::formular($db, $cale, $n) : null;
        if ($formular === null || $formular['furnizor'] !== 'mailpoet') {
            return $nu('formular');
        }
        $lista = self::listaMailPoet($db, $formular['lista']);
        if ($lista === null) {
            return $nu('lista');
        }
        $email = $post['et_pb_signup_email'] ?? '';
        if (!is_string($email) || strlen($email) > 190 || !mb_check_encoding($email, 'UTF-8')) {
            return $nu('adresa');
        }
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $nu('adresa');
        }
        $prenume = $formular['prenume'] ? self::nume($post['et_pb_signup_firstname'] ?? '') : '';
        $nume = $formular['nume'] ? self::nume($post['et_pb_signup_lastname'] ?? '') : '';
        try {
            $rez = NewsletterService::cerereAbonare($db, $lista, $email, trim($prenume . ' ' . $nume), IpClient::adresa(), Settings::all($db),
                ['sursa' => 'api', 'prenume' => $prenume, 'nume' => $nume]);
        } catch (Throwable $e) {
            error_log('ANDAXI-CMS: abonarea capturată: cererea nu s-a putut face (' . get_class($e) . ')');
            return $nu('eroare');
        }
        return in_array($rez['stare'], ['trimis', 'deja_activ', 'limita_adresa'], true) ? ['ok' => true, 'cod' => 200, 'motiv' => $rez['stare']]
            : $nu($rez['stare']);
    }

    /**
     * Formularul de abonare n al documentului servit la calea dată (cu query-ul ei: paginile MailPoet `/?mailpoet_page=…` au documentele
     * lor), în ordinea documentului randat (conținutul, apoi subsolul): furnizorul, lista și ce câmpuri de nume are.
     *
     * @return array{furnizor: string, lista: string, prenume: bool, nume: bool}|null
     */
    public static function formular(PDO $db, string $cale, int $n): ?array
    {
        $doc = self::document($db, $cale);
        if ($doc === null) {
            return null;
        }
        try {
            $html = CadruCapturat::randeazaRand($db, $doc, ['cale' => (string) (parse_url($cale, PHP_URL_PATH) ?: '/'), 'nonce' => '', 'an' => date('Y'),
                'sablon_cmplz' => ''], ['cap' => '', 'final' => '']);
        } catch (Throwable) {
            return null;
        }
        $formulare = self::formulare($html);
        return $formulare[$n] ?? null;
    }

    /**
     * Formularele de abonare Divi dintr-un document (cele cu câmpul `et_pb_signup_provider`), în ordine.
     *
     * @return list<array{furnizor: string, lista: string, prenume: bool, nume: bool}>
     */
    public static function formulare(string $html): array
    {
        $rez = [];
        if (!preg_match_all('#<form\b[^>]*>(.*?)</form>#is', $html, $m)) {
            return [];
        }
        foreach ($m[1] as $corp) {
            $campuri = [];
            if (preg_match_all('/<input\b[^>]*>/i', $corp, $intrari)) {
                foreach ($intrari[0] as $tag) {
                    $nume = self::atribut($tag, 'name');
                    if ($nume !== null) {
                        $campuri[$nume] = (string) self::atribut($tag, 'value');
                    }
                }
            }
            if (!array_key_exists('et_pb_signup_provider', $campuri)) {
                continue;
            }
            $rez[] = ['furnizor' => html_entity_decode($campuri['et_pb_signup_provider'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'lista' => html_entity_decode((string) ($campuri['et_pb_signup_list_id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'prenume' => array_key_exists('et_pb_signup_firstname', $campuri), 'nume' => array_key_exists('et_pb_signup_lastname', $campuri)];
        }
        return $rez;
    }

    /** Documentul capturat al căii (cu query): paginile MailPoet pe `/`, altfel cum le găsesc rutele (FormularCapturat::documentPentru). @return array<string, mixed>|null */
    private static function document(PDO $db, string $cale): ?array
    {
        if (preg_match('#^/[^\s]*$#D', $cale) !== 1 || str_starts_with($cale, '//')) {
            return null;
        }
        $drum = (string) (parse_url($cale, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($cale, PHP_URL_QUERY) ?? '');
        parse_str($query, $get);
        if ($drum === '/' && array_key_exists('mailpoet_page', $get)) {
            $cadru = array_key_exists('mailpoet_router', $get)
                ? Url::pagina($db) . '&mailpoet_router&endpoint=subscription&action=manage&data=' . Date::codifica(['preview' => 1])
                : Url::pagina($db);
            return CadruCapturat::document($db, cale: $cadru);
        }
        $gasit = FormularCapturat::documentPentru($db, $drum === '' ? '/' : $drum);
        return $gasit['doc'] ?? null;
    }

    /** Lista CMS a listei MailPoet cu id-ul dat (importată), sau null. */
    private static function listaMailPoet(PDO $db, string $idMailPoet): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $idMailPoet) !== 1) {
            return null;
        }
        try {
            $q = $db->prepare('SELECT id FROM newsletter_lists WHERE mailpoet_id = ? LIMIT 1');
            $q->execute([(int) $idMailPoet]);
            $id = (int) ($q->fetchColumn() ?: 0);
        } catch (Throwable) {
            return null;
        }
        return $id > 0 ? $id : null;
    }

    private static function atribut(string $tag, string $nume): ?string
    {
        if (preg_match('/\s' . preg_quote($nume, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $tag, $m) !== 1) {
            return null;
        }
        return $m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : (string) ($m[3] ?? ''));
    }

    private static function nume(mixed $v): string
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/', '', strip_tags($v)))), 0, 255);
    }

    private static function raspuns(int $cod, mixed $date): void
    {
        http_response_code($cod);
        header('Content-Type: application/json; charset=utf-8');
        // Un 404 are antetele WordPress ale oricărui 404 al site-ului (`antete_404`; interceptarea e înaintea lui WpCompat::intercepteaza).
        header('Cache-Control: ' . ($cod === 404 && WpCompat::antete404() ? WpCompat::CACHE_CONTROL_404 : 'no-store, private'));
        if ($cod === 404 && WpCompat::antete404()) {
            header('Expires: ' . WpCompat::EXPIRES_404);
        }
        header('X-Content-Type-Options: nosniff');
        echo json_encode($date);
    }
}
