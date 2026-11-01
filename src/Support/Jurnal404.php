<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Jurnalul paginilor negăsite (WP-38, SEO partea B, B2; tabelul `seo_404_log`, migrarea nucleu/0047): fiecare adresă
 * publică la care site-ul a răspuns 404, câte ori și când (prima și ultima dată), ca adminul să vadă ce link vechi sau
 * greșit caută oamenii și Google și să-l redirecționeze dintr-un clic (Setări → Redirecționări și 404).
 *
 * **Fără date personale în plus:** doar calea (decodată, cu litere mici, fără „/” final — cheia hărții), fără parametri
 * (acolo pot sta tokenuri, emailuri, coduri de reducere), fără IP, fără browser; de unde a venit omul, doar gazda altui
 * site (fără calea paginii de pe care a venit). Nu intră: altceva decât GET, căile excluse (coș, plată, cont, admin, API,
 * fișiere, confirmări), o cale cu „@” sau mai lungă de 500 de caractere, încercările roboților de scanare (fișiere
 * `.php`, `.env`, `.git`, `wp-admin`… — dau 404 pe orice site și n-au ce redirecționa).
 *
 * **Cât se ține:** 90 de zile de la ultima accesare (`ZILE_PASTRARE`) și cel mult `MAX_RANDURI` adrese (cele mai vechi
 * pleacă primele); curățarea se face la întâmplare, o dată la ~50 de 404-uri, și la fiecare deschidere a jurnalului în admin.
 * Oprire: `SEO_JURNAL_404=0` în .env.
 */
final class Jurnal404
{
    public const ZILE_PASTRARE = 90;
    public const MAX_RANDURI = 5000;
    private const MAX_CALE = 500;

    /** Încercările de scanare (nu link-uri vechi): extensii de cod și fișiere de configurare. */
    private const SCANARE = '~(\.(php\d?|asp|aspx|jsp|cgi|env|ini|sql|bak|old|swp|zip|gz|tar|rar|7z|log|yml|yaml|config)$)|(^|/)\.|/(wp-admin|wp-includes|wp-content/plugins|cgi-bin|phpmyadmin|vendor/phpunit)(/|$)~i';

    /** Se înregistrează la oprirea scriptului, după SeoRedirects::termina (deci vede codul final al răspunsului). */
    public static function laFinal(): void
    {
        try {
            self::laFinalIntern();
        } catch (Throwable) {
            // Jurnalul nu are voie să strice vreo cerere.
        }
    }

    private static function laFinalIntern(): void
    {
        if ((int) http_response_code() !== 404 || self::oprit()) {
            return;
        }
        $metoda = strtoupper((string) ($_SERVER['SEO_REQUEST_METHOD_ORIGINAL'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($metoda !== 'GET') {
            return;
        }
        $uri = (string) ($_SERVER['SEO_REQUEST_URI_ORIGINAL'] ?? $_SERVER['REQUEST_URI'] ?? '/');
        $cale = self::caleDeNotat($uri);
        if ($cale === null) {
            return;
        }
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $db = Database::connection((array) ($config['db'] ?? []));
        if ($db instanceof PDO) {
            self::noteaza($db, $cale, self::gazdaReferer((string) ($_SERVER['HTTP_REFERER'] ?? '')));
        }
    }

    /** Calea de notat pentru o cerere (cheia hărții), sau null dacă nu se notează. */
    public static function caleDeNotat(string $uri): ?string
    {
        $caleBruta = (string) explode('?', explode('#', $uri, 2)[0], 2)[0];
        if (preg_match('#^https?://[^/]+(/.*)?$#i', $caleBruta, $m) === 1) {
            $caleBruta = $m[1] ?? '/';
        }
        if ($caleBruta === '' || $caleBruta[0] !== '/' || str_contains($caleBruta, '@') || str_contains(rawurldecode($caleBruta), '@')
            || strlen($caleBruta) > self::MAX_CALE * 3) {
            return null;
        }
        $cale = SeoRedirects::cheie($caleBruta);
        if ($cale === '/' || mb_strlen($cale) > self::MAX_CALE || preg_match('/[\x00-\x1f\x7f]/', $cale) === 1
            || SeoRedirects::eExclusa($caleBruta) || SeoRedirects::eExclusa($cale) || preg_match(self::SCANARE, $cale) === 1) {
            return null;
        }
        return $cale;
    }

    /** Doar gazda altui site de pe care a venit omul (fără cale): „google.com”, „facebook.com”. */
    public static function gazdaReferer(string $referer): ?string
    {
        $gazda = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($gazda === '' || strlen($gazda) > 190 || preg_match('/^[a-z0-9.-]+$/', $gazda) !== 1) {
            return null;
        }
        $aSite = strtolower((string) parse_url(AppUrl::base(), PHP_URL_HOST));
        $faraWww = static fn (string $g): string => str_starts_with($g, 'www.') ? substr($g, 4) : $g;
        return $aSite !== '' && $faraWww($gazda) === $faraWww($aSite) ? null : $gazda;
    }

    public static function noteaza(PDO $db, string $cale, ?string $gazdaReferer = null, bool $curata = true): void
    {
        try {
            $acum = date('Y-m-d H:i:s');
            $db->prepare('INSERT INTO seo_404_log (path_hash, path, hits, first_seen_at, last_seen_at, referrer_host) VALUES (?, ?, 1, ?, ?, ?)
                ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen_at = VALUES(last_seen_at),
                    referrer_host = COALESCE(VALUES(referrer_host), referrer_host)')
                ->execute([sha1($cale), $cale, $acum, $acum, $gazdaReferer]);
            if ($curata && random_int(1, 50) === 1) {
                self::curata($db);
            }
        } catch (Throwable) {
            // Fără tabel (migrarea nucleu/0047 n-a rulat): nimic.
        }
    }

    /** Șterge rândurile mai vechi de ZILE_PASTRARE zile și, peste MAX_RANDURI, pe cele văzute cel mai demult. */
    public static function curata(PDO $db): int
    {
        try {
            $sterse = (int) $db->exec('DELETE FROM seo_404_log WHERE last_seen_at < (NOW() - INTERVAL ' . self::ZILE_PASTRARE . ' DAY)');
            $numar = (int) $db->query('SELECT COUNT(*) FROM seo_404_log')->fetchColumn();
            if ($numar > self::MAX_RANDURI) {
                $sterse += (int) $db->exec('DELETE FROM seo_404_log ORDER BY last_seen_at ASC, id ASC LIMIT ' . ($numar - self::MAX_RANDURI));
            }
            return $sterse;
        } catch (Throwable) {
            return 0;
        }
    }

    public static function disponibil(?PDO $db): bool
    {
        if (!$db instanceof PDO) {
            return false;
        }
        try {
            $db->query('SELECT 1 FROM seo_404_log LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function oprit(): bool
    {
        return in_array(strtolower(trim((string) Env::get('SEO_JURNAL_404', '1'))), ['0', 'false', 'off', 'nu'], true);
    }
}
