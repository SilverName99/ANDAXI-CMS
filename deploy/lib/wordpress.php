<?php

declare(strict_types=1);

/**
 * Ce știu scripturile de deploy despre un site WordPress (deploy/adopta-wp.sh, deploy/revino.sh): dacă un dosar e o
 * instalare WordPress, versiunea ei și datele bazei din wp-config.php, citite FĂRĂ să-l ruleze (jetoanele PHP, ca
 * App\Support\ImportWordPress\ConfigWordPress din cod: scripturile de deploy nu încarcă aplicația), ca `Baza` (copia de
 * siguranță a bazei WordPress, amprenta conținutului ei); plus `.htaccess`-urile arborilor statici copiați (din șabloanele
 * din git), spațiul cerut, adresa primului administrator și drumul capturat. Baza WordPress nu se scrie niciodată.
 */

namespace Platforma\Deploy;

final class WordPress
{
    /** Fișierele după care se recunoaște o instalare WordPress. */
    public const SEMNE = ['wp-config.php', 'wp-settings.php', 'wp-includes/version.php'];

    /** Fișierele din rădăcina WordPress aduse în docroot-ul nou (verificările domeniului), dacă există. */
    public const FISIERE_SITE = ['/^google[0-9a-f]{8,32}\.html$/', '/^BingSiteAuth\.xml$/', '/^yandex_[0-9a-f]+\.html$/', '/^pinterest-[0-9a-f]+\.html$/',
        '/^ads\.txt$/', '/^app-ads\.txt$/', '/^[0-9a-f]{32}\.txt$/'];

    /**
     * Ce se aduce din `.well-known/` al WordPress-ului (recenzia 2, R1-1): o listă albă. Fiecare segment al căii fără punct în
     * față (niciun `.env`, `.htaccess`, `.user.ini`), numele fie fără extensie (`security.txt`-ul vechi, `change-password`),
     * fie terminat în `.txt`, `.json` sau `.xml`; iar nicio bucată a numelui (`a.php.txt`, `x.cgi.json`) nu e una pe care
     * șabloanele `.htaccess` din deploy/stub/ o refuză (EXTENSII_EXECUTABILE), oricum ar fi scrise literele.
     */
    public const EXTENSII_WELL_KNOWN = ['txt', 'json', 'xml'];

    /** Extensiile refuzate de șabloanele `.htaccess` (deploy/stub/*.htaccess, FilesMatch): server-side, oricum scrise. */
    public const RE_EXTENSIE_EXECUTABILA = '/^(php[0-9]?|pht|phtm|phtml|phps|phar|shtm|shtml|pl|py|cgi|sh)$/i';

    /** O legătură simbolică în `.well-known/` (dosarul însuși sau orice din el), descrisă, sau null. */
    public static function legaturaWellKnown(string $wk): ?string
    {
        if (is_link($wk)) {
            return '.well-known/ al WordPress-ului e o legătură simbolică';
        }
        if (!is_dir($wk)) {
            return null;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wk, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            if ($f->isLink()) {
                return '.well-known/' . substr($f->getPathname(), strlen($wk) + 1) . ' e o legătură simbolică';
            }
        }
        return null;
    }

    /** Un fișier din `.well-known/` (calea relativă) care se poate aduce? */
    public static function permisWellKnown(string $rel): bool
    {
        if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '\\')) {
            return false;
        }
        $segmente = explode('/', $rel);
        foreach ($segmente as $s) {
            if ($s === '' || $s[0] === '.' || preg_match('/^[A-Za-z0-9._-]+$/D', $s) !== 1) {
                return false;
            }
        }
        $bucati = explode('.', (string) end($segmente));
        foreach (array_slice($bucati, 1) as $b) {
            if (preg_match(self::RE_EXTENSIE_EXECUTABILA, $b) === 1) {
                return false;
            }
        }
        return count($bucati) === 1 || in_array(strtolower((string) end($bucati)), self::EXTENSII_WELL_KNOWN, true);
    }

    /**
     * `.htaccess`-ul pus în `.well-known/` copiat (recenzia 3, R1-N4): ce ține și fără mod_headers vine din nucleul Apache
     * (`ForceType`, `SetHandler none`, `Options`): niciun handler (nimic executat), nicio listă, fiecare fișier servit ca text
     * simplu (`.json` și `apple-app-site-association`, JSON fără extensie, ca application/json — recenzia 4, N4; celelalte fișiere
 * fără extensie rămân text/plain), deci un browser nu randează nimic din el (nici HTML, nici SVG, nici XML cu stil).
     * Cu mod_mime se scot și handlerele pe extensie; cu mod_headers se adaugă `nosniff` și `CSP: sandbox` (doar în plus).
     */
    public const HTACCESS_WELL_KNOWN = "# .well-known/ adus din WordPress (deploy/adopta-wp.php): doar text, nimic executat (recenzia 2, R1-1; recenzia 3, R1-N4).\n"
        . "Options -ExecCGI -Indexes\n"
        . "SetHandler none\n"
        . "ForceType text/plain\n"
        . "<FilesMatch \"(^apple-app-site-association|\\.[jJ][sS][oO][nN])$\">\n    ForceType application/json\n</FilesMatch>\n"
        . "<IfModule mod_mime.c>\n    RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .pht .phps .phar .shtml .pl .py .cgi .sh\n"
        . "    RemoveType .php .php3 .php4 .php5 .php7 .php8 .phtml .pht .phps .phar .shtml .pl .py .cgi .sh\n</IfModule>\n"
        . "<IfModule mod_headers.c>\n    Header always set X-Content-Type-Options \"nosniff\"\n    Header always set Content-Security-Policy \"sandbox\"\n</IfModule>\n";

    /** Tabelele cu conținutul site-ului (amprenta: „s-a schimbat ceva în WordPress de la import?”). */
    public const TABELE_CONTINUT = ['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'aioseo_posts'];

    public static function este(string $dosar): bool
    {
        foreach (self::SEMNE as $f) {
            if (!is_file($dosar . '/' . $f)) {
                return false;
            }
        }
        return true;
    }

    public static function versiune(string $dosar): string
    {
        $text = (string) @file_get_contents($dosar . '/wp-includes/version.php');
        return preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $text, $m) === 1 ? $m[1] : '?';
    }

    /**
     * Baza WordPress ca .env-ul unui site (pentru `Baza`): DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, plus
     * prefixul tabelelor. Oprire dacă wp-config.php n-are valorile scrise simplu.
     *
     * @return array{env: array<string, string>, prefix: string}
     */
    public static function config(string $dosar): array
    {
        $text = (string) @file_get_contents($dosar . '/wp-config.php');
        if ($text === '') {
            throw new Oprire('nu pot citi ' . $dosar . '/wp-config.php');
        }
        $jetoane = array_values(array_filter(token_get_all($text), static fn ($j): bool => !is_array($j) || !in_array($j[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $c = [];
        $prefix = null;
        $literal = static function (mixed $j): ?string {
            if (!is_array($j) || $j[0] !== T_CONSTANT_ENCAPSED_STRING) {
                return is_array($j) && in_array($j[0], [T_LNUMBER], true) ? $j[1] : null;
            }
            $interior = substr($j[1], 1, -1);
            if ($j[1][0] === "'") {
                return strtr($interior, ['\\\\' => '\\', "\\'" => "'"]);
            }
            return preg_match('/(?<!\\\\)\$/', $interior) === 1 ? null : stripcslashes(str_replace('\\$', '$', $interior));
        };
        for ($i = 0, $n = count($jetoane); $i < $n; $i++) {
            $j = $jetoane[$i];
            if (is_array($j) && $j[0] === T_STRING && strtolower($j[1]) === 'define' && ($jetoane[$i + 1] ?? null) === '(') {
                $nume = $literal($jetoane[$i + 2] ?? null);
                $valoare = $literal($jetoane[$i + 4] ?? null);
                if ($nume !== null && $valoare !== null && ($jetoane[$i + 3] ?? null) === ',' && ($jetoane[$i + 5] ?? null) === ')' && !isset($c[$nume])) {
                    $c[$nume] = $valoare;
                }
            } elseif (is_array($j) && $j[0] === T_VARIABLE && $j[1] === '$table_prefix' && ($jetoane[$i + 1] ?? null) === '=') {
                $prefix = $literal($jetoane[$i + 2] ?? null) ?? $prefix;
            }
        }
        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $k) {
            if (!isset($c[$k])) {
                throw new Oprire($dosar . '/wp-config.php n-are ' . $k . ' scris ca text simplu (define(\'' . $k . '\', \'…\')); nu-l pot citi fără să-l rulez');
            }
        }
        if ($prefix === null || preg_match('/^[A-Za-z0-9_]{1,40}$/', $prefix) !== 1) {
            throw new Oprire($dosar . '/wp-config.php n-are $table_prefix scris simplu (litere, cifre, „_”)');
        }
        $gazda = $c['DB_HOST'];
        $port = '3306';
        if (substr_count($gazda, ':') === 1) {
            [$gazda, $rest] = explode(':', $gazda, 2);
            if (!ctype_digit($rest)) {
                throw new Oprire('DB_HOST din wp-config.php are un socket (' . $c['DB_HOST'] . '): scripturile de deploy știu doar gazdă și port');
            }
            $port = $rest;
        }
        return ['env' => ['DB_HOST' => $gazda !== '' ? $gazda : 'localhost', 'DB_PORT' => $port, 'DB_DATABASE' => $c['DB_NAME'],
            'DB_USERNAME' => $c['DB_USER'], 'DB_PASSWORD' => $c['DB_PASSWORD']], 'prefix' => $prefix];
    }

    /** CHECKSUM TABLE pe tabelele de conținut ale WordPress (doar citire). @return array<string, string> */
    public static function amprentaContinut(Baza $b, string $prefix): array
    {
        $rez = [];
        $existente = $b->tabele();
        foreach (self::TABELE_CONTINUT as $t) {
            if (in_array($prefix . $t, $existente, true)) {
                $rez[$t] = (string) ($b->pdo()->query('CHECKSUM TABLE `' . str_replace('`', '``', $prefix . $t) . '`')->fetch(\PDO::FETCH_NUM)[1] ?? '');
            }
        }
        return $rez;
    }

    /** Răspunsul unei pagini arată a WordPress (generatorul, wp-content, wp-json)? */
    public static function pagina(string $html): bool
    {
        return preg_match('#<meta[^>]+generator[^>]+WordPress|/wp-content/|/wp-json/|/wp-includes/#i', $html) === 1;
    }

    /** Arborii statici copiați în docroot-ul nou, fiecare cu `.htaccess`-ul lui din git (`deploy/stub/<arbore>.htaccess`). */
    public const ARBORI = ['wp-content', 'wp-includes', 'capturat'];

    /**
     * `.htaccess`-ul unui arbore copiat (corectura 1.12, F1, R9): din șablonul ținut în git, fără execuție (`Require all
     * denied` pe PHP și scripturi, `Options -ExecCGI -Indexes`), cu antetele de cache ca pe site-ul live (7 zile, cu
     * Expires, VC-SERVER-002) și CORS doar pe fonturi. Mediile rămân la adresele lor: nicio redirecționare.
     */
    public static function htaccess(string $cod, string $arbore): string
    {
        if (!in_array($arbore, self::ARBORI, true)) {
            throw new Oprire('arbore necunoscut: ' . $arbore);
        }
        $text = (string) @file_get_contents($cod . '/deploy/stub/' . $arbore . '.htaccess');
        if ($text === '' || !str_contains($text, 'Require all denied') || !str_contains($text, '-ExecCGI')) {
            throw new Oprire('lipsește șablonul ' . $cod . '/deploy/stub/' . $arbore . '.htaccess (sau nu oprește execuția)');
        }
        return $text;
    }

    /**
     * Scrie `.htaccess`-urile arborilor copiați în docroot-ul dat: `wp-content/` mereu (mediile stau acolo), ceilalți doar
     * dacă există. Întoarce arborii scriși.
     *
     * @return list<string>
     */
    public static function scrieHtaccess(string $cod, string $docroot): array
    {
        $scrise = [];
        foreach (self::ARBORI as $arbore) {
            $dosar = $docroot . '/' . $arbore;
            if ($arbore !== 'wp-content' && !is_dir($dosar)) {
                continue;
            }
            Fisiere::dosar($dosar, 0755);
            Fisiere::scrie($dosar . '/.htaccess', self::htaccess($cod, $arbore), 0644);
            $scrise[] = $arbore;
        }
        return $scrise;
    }

    /**
     * Cât spațiu cere adoptarea (corectura 1.14, O3, R14): mediile și arborii statici (wp-content fără cache, wp-includes)
     * stau de două ori pe disc (în WordPress, neatins pentru revenire, și în docroot-ul nou), plus copia codului
     * WordPress și a bazei. `df` nu arată cota Hostinger: limita contului se verifică în hPanel → Disk usage.
     *
     * @return array{media: int, statice: int, cod: int, baza: int, doua_copii: int, nevoie: int}
     */
    public static function spatiu(string $docrootWp, int $octetiBaza): array
    {
        $media = Fisiere::marime($docrootWp . '/wp-content/uploads');
        $statice = Fisiere::marime($docrootWp . '/wp-content', ['*/wp-content/uploads', '*/wp-content/cache', '*/wp-content/et-cache',
            '*/wp-content/upgrade', '*/wp-content/backup*', '*/wp-content/updraft']) + Fisiere::marime($docrootWp . '/wp-includes');
        $cod = Fisiere::marime($docrootWp, ['*/wp-content/uploads', '*/wp-content/cache', '*/wp-content/et-cache']);
        $douaCopii = 2 * ($media + $statice);
        return ['media' => $media, 'statice' => $statice, 'cod' => $cod, 'baza' => $octetiBaza, 'doua_copii' => $douaCopii,
            'nevoie' => $douaCopii + $cod + $octetiBaza + 100 * 1024 * 1024];
    }

    /**
     * Adresa primului administrator al site-ului nou (corectura 1.13, R16): doar cea dată explicit cu --admin-email (sau
     * ținută minte de adoptarea începută cu ea), niciodată `admin_email` din WordPress (poate fi a agenției). '' = lipsă.
     */
    public static function adminEmail(?string $optiune, string $tinutMinte = ''): string
    {
        $e = trim((string) ($optiune ?? $tinutMinte));
        return filter_var($e, FILTER_VALIDATE_EMAIL) !== false ? strtolower($e) : '';
    }

    /**
     * Pachetul site-ului cere drumul capturat (`import-wordpress.json` → `"continut": "capturat"`)? Pe el paginile folosesc
     * variantele de poze ale WordPress, nu pe cele WebP ale nucleului (corectura 1.11).
     */
    public static function capturat(string $dosarPachet): bool
    {
        $d = json_decode((string) @file_get_contents($dosarPachet . '/import-wordpress.json'), true);
        return is_array($d) && ($d['continut'] ?? null) === 'capturat';
    }
}
