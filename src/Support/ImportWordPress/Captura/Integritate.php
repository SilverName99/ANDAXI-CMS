<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;

/**
 * Un WordPress compromis se caută ÎNAINTE de captură (pasul 4a; DECIZIE §2.9), ca pas separat: un NU oprește și captura, și
 * copierea (scripts/captureaza-wordpress.php, cod 2). Se sare doar cu `--fara-integritate=<motiv>` (motivul intră în raport,
 * starea e „în așteptare (server)”: o captură din mediul nostru, fără discul WordPress). verifica():
 *  - local, întâi (fără nicio cerere): versiunea și limba instalației (`$wp_version`, `$wp_local_package` din
 *    wp-includes/version.php); orice fișier PHP din wp-content/uploads/ (NU, în afara cioturilor „Silence is golden”); semne()
 *    în plugins/, themes/, mu-plugins/ și uploads/ (ATENȚIE, de revizuit de om). Un NU aici oprește înaintea rețelei;
 *  - nucleul față de sumele publice (api.wordpress.org, `core/checksums/1.0` cu versiunea și limba instalației): fișierele
 *    diferite și fișierele PHP în plus din wp-admin/ și wp-includes/ sunt NU, cele lipsă și PHP-ul în plus din rădăcină ATENȚIE;
 *  - fiecare plugin găzduit pe wordpress.org față de sumele lui (downloads.wordpress.org, `plugin-checksums/<slug>/<versiune>`):
 *    un fișier diferit sau un PHP în plus e NU; unul fără sume publice (premium sau din afara wordpress.org), temele (Divi) și
 *    mu-plugins sunt „neverificabil”, cu sha256-ul fiecărui fișier (de comparat cu arhiva originală pe care o descarcă
 *    proprietarul);
 *  - cererile trec prin Cereri-ul ei, cu gazdele ei (GAZDE), niciodată prin cel al capturii.
 * ascunse(): linkurile spre alte gazde din elementele ascunse ale unei pagini capturate (spam SEO), pentru revizia omului.
 */
final class Integritate
{
    /** Singurele gazde ale integrității (Cereri-ul ei); captura nu le are. */
    public const GAZDE = ['api.wordpress.org', 'downloads.wordpress.org'];

    public const API_NUCLEU = 'https://api.wordpress.org';
    public const API_PLUGINURI = 'https://downloads.wordpress.org';

    public const TRECUT = 'trecut';
    public const NU = 'NU';
    public const IN_ASTEPTARE = 'în așteptare (server)';

    /** Extensiile executate ca PHP de un server obișnuit (RemoveHandler, FilesMatch din deploy/stub/). */
    private const RE_PHP_EXT = '/\.(?:php[0-9]?|phtml|phar|pht|phps)$/i';

    /** Ciotul pus de WordPress și de pluginuri în dosare („Silence is golden”): singurul PHP tolerat în uploads/. */
    private const RE_LINISTE = '#^\s*<\?php\s*(?://|\#|/\*)?\s*silence is golden\.?\s*(?:\*/)?\s*(?:\?>)?\s*$#i';

    /** Rezultatul pasului sărit: motivul intră în raport. */
    public static function inAsteptare(string $motiv): array
    {
        return ['stare' => self::IN_ASTEPTARE, 'motiv' => $motiv, 'probleme' => [], 'avertismente' => []];
    }

    /** Câte cereri face verifica() pe instalația dată: sumele nucleului și câte una pe plugin cu versiune (pentru plan și buget). */
    public static function cereriPlanificate(string $dosarWp): int
    {
        return 1 + count(array_filter(self::pluginuri($dosarWp), static fn (array $p): bool => $p['versiune'] !== ''));
    }

    /**
     * Pasul integrității pe instalația de pe disc. `$cereri`: Cereri-ul integrității (gazdele GAZDE sau, într-o probă, ale ei).
     *
     * @return array<string, mixed> stare (TRECUT sau NU), versiune, limba, probleme, avertismente și listele fiecărei verificări
     */
    public static function verifica(string $dosarWp, Cereri $cereri, string $apiNucleu = self::API_NUCLEU, string $apiPluginuri = self::API_PLUGINURI): array
    {
        $rad = realpath($dosarWp);
        $rez = ['stare' => self::NU, 'versiune' => '', 'limba' => '', 'probleme' => [], 'avertismente' => [], 'uploads_php' => [], 'uploads_liniste' => [],
            'semne' => [], 'nucleu' => null, 'pluginuri' => [], 'teme' => [], 'mu_pluginuri' => []];
        if ($rad === false || !is_file($rad . '/wp-includes/version.php')) {
            $rez['probleme'][] = 'nu găsesc wp-includes/version.php în ' . $dosarWp;
            return $rez;
        }
        $versiune = (string) @file_get_contents($rad . '/wp-includes/version.php');
        $rez['versiune'] = preg_match('/\$wp_version\s*=\s*[\'"]([0-9][0-9a-z.\-]{0,30})[\'"]/i', $versiune, $m) === 1 ? $m[1] : '';
        $rez['limba'] = preg_match('/\$wp_local_package\s*=\s*[\'"]([a-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2})[\'"]/', $versiune, $m) === 1 ? $m[1] : 'en_US';
        if ($rez['versiune'] === '') {
            $rez['probleme'][] = 'versiunea WordPress nu se poate citi din wp-includes/version.php';
            return $rez;
        }
        // 1. Local, fără rețea: PHP în uploads/, semnele codului ascuns.
        foreach (self::fisiere($rad . '/wp-content/uploads') as $rel => $f) {
            if (preg_match(self::RE_PHP_EXT, $rel) === 1) {
                if (filesize($f) < 256 && preg_match(self::RE_LINISTE, (string) file_get_contents($f)) === 1) {
                    $rez['uploads_liniste'][] = 'wp-content/uploads/' . $rel;
                } else {
                    $rez['uploads_php'][] = 'wp-content/uploads/' . $rel;
                }
            }
        }
        if ($rez['uploads_php'] !== []) {
            $rez['probleme'][] = 'fișiere PHP în wp-content/uploads/: ' . implode(', ', array_slice($rez['uploads_php'], 0, 10)) . (count($rez['uploads_php']) > 10 ? ' …' : '');
        }
        foreach (['plugins', 'themes', 'mu-plugins', 'uploads'] as $sub) {
            foreach (self::fisiere($rad . '/wp-content/' . $sub) as $rel => $f) {
                if ((preg_match(self::RE_PHP_EXT, $rel) === 1 || preg_match('/\.m?js$/i', $rel) === 1) && filesize($f) < 8 << 20) {
                    $semne = self::semne((string) file_get_contents($f));
                    if ($semne !== []) {
                        $rez['semne'][] = ['fisier' => 'wp-content/' . $sub . '/' . $rel, 'semne' => $semne];
                        $rez['avertismente'][] = 'cod ascuns posibil în wp-content/' . $sub . '/' . $rel . ': ' . implode(', ', $semne) . ' (de revizuit de om)';
                    }
                }
            }
        }
        if ($rez['probleme'] !== []) {
            return $rez; // NU local: nicio cerere
        }
        // 2. Nucleul, cu limba instalației.
        $rez['nucleu'] = self::nucleu($cereri, $rad, $rez['versiune'], $rez['limba'], $apiNucleu);
        foreach ($rez['nucleu']['probleme'] as $p) {
            $rez['probleme'][] = $p;
        }
        foreach ($rez['nucleu']['avertismente'] as $a) {
            $rez['avertismente'][] = $a;
        }
        // 3. Pluginurile: cu sumele de pe wordpress.org, sau neverificabile (cu sha256-ul fiecărui fișier).
        foreach (self::pluginuri($rad) as $pl) {
            $v = self::plugin($cereri, $rad, $pl, $apiPluginuri);
            $rez['pluginuri'][] = $v;
            foreach ($v['probleme'] as $p) {
                $rez['probleme'][] = $p;
            }
        }
        foreach (['themes' => 'teme', 'mu-plugins' => 'mu_pluginuri'] as $sub => $cheie) {
            $dir = $rad . '/wp-content/' . $sub;
            foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $nume) {
                if ($nume === '.' || $nume === '..' || is_link($dir . '/' . $nume)) {
                    continue;
                }
                $rez[$cheie][] = ['nume' => $nume, 'stare' => 'neverificabil', 'fisiere' => self::sume($dir . '/' . $nume)];
            }
        }
        $rez['stare'] = $rez['probleme'] === [] ? self::TRECUT : self::NU;
        return $rez;
    }

    /** @return array{verificate: int, diferite: list<string>, lipsa: list<string>, in_plus_php: list<string>, radacina_php: list<string>, probleme: list<string>, avertismente: list<string>} */
    private static function nucleu(Cereri $cereri, string $rad, string $versiune, string $limba, string $api): array
    {
        $rez = ['verificate' => 0, 'diferite' => [], 'lipsa' => [], 'in_plus_php' => [], 'radacina_php' => [], 'probleme' => [], 'avertismente' => []];
        $url = rtrim($api, '/') . '/core/checksums/1.0/?version=' . rawurlencode($versiune) . '&locale=' . rawurlencode($limba);
        try {
            $r = $cereri->get($url, 'chrome-win', 'application/json');
        } catch (EroareCaptura $e) {
            $rez['probleme'][] = 'sumele nucleului WordPress ' . $versiune . ' (' . $limba . ') nu s-au putut citi: ' . $e->getMessage();
            return $rez;
        }
        $date = json_decode($r->corp, true);
        $sume = is_array($date['checksums'] ?? null) ? ($date['checksums'][$versiune] ?? $date['checksums']) : null;
        if ($r->status !== 200 || !is_array($sume) || $sume === []) {
            $rez['probleme'][] = 'sumele nucleului WordPress ' . $versiune . ' (' . $limba . ') nu s-au putut citi (status ' . $r->status . ')';
            return $rez;
        }
        $nucleu = [];
        foreach ($sume as $f => $suma) {
            $f = (string) $f;
            if (str_starts_with($f, 'wp-content/') || !is_string($suma)) {
                continue;
            }
            $nucleu[$f] = true;
            $cale = $rad . '/' . ltrim($f, '/');
            if (str_contains($f, '..') || !is_file($cale) || is_link($cale)) {
                $rez['lipsa'][] = $f;
                continue;
            }
            $rez['verificate']++;
            if (!hash_equals(strtolower($suma), (string) md5_file($cale))) {
                $rez['diferite'][] = $f;
            }
        }
        foreach (['wp-admin', 'wp-includes'] as $sub) {
            foreach (self::fisiere($rad . '/' . $sub) as $rel => $f) {
                if (preg_match(self::RE_PHP_EXT, $rel) === 1 && !isset($nucleu[$sub . '/' . $rel])) {
                    $rez['in_plus_php'][] = $sub . '/' . $rel;
                }
            }
        }
        foreach (scandir($rad) ?: [] as $nume) {
            if (preg_match(self::RE_PHP_EXT, $nume) === 1 && is_file($rad . '/' . $nume) && !isset($nucleu[$nume]) && $nume !== 'wp-config.php') {
                $rez['radacina_php'][] = $nume;
            }
        }
        if ($rez['diferite'] !== []) {
            $rez['probleme'][] = 'nucleul WordPress diferă de sumele publice (' . $limba . '): ' . implode(', ', array_slice($rez['diferite'], 0, 10));
        }
        if ($rez['in_plus_php'] !== []) {
            $rez['probleme'][] = 'fișiere PHP în plus în nucleu (wp-admin/, wp-includes/): ' . implode(', ', array_slice($rez['in_plus_php'], 0, 10));
        }
        if ($rez['lipsa'] !== []) {
            $rez['avertismente'][] = count($rez['lipsa']) . ' fișiere ale nucleului lipsesc: ' . implode(', ', array_slice($rez['lipsa'], 0, 10));
        }
        if ($rez['radacina_php'] !== []) {
            $rez['avertismente'][] = 'fișiere PHP în rădăcina WordPress care nu sunt ale nucleului (de revizuit de om): ' . implode(', ', $rez['radacina_php']);
        }
        return $rez;
    }

    /**
     * Pluginurile de pe disc: dosarul (slug-ul), fișierul principal (antetul „Plugin Name:”) și versiunea lui („Version:”);
     * plus pluginurile dintr-un singur fișier din plugins/.
     *
     * @return list<array{slug: string, dosar: string, versiune: string}>
     */
    private static function pluginuri(string $dosarWp): array
    {
        $dir = rtrim($dosarWp, '/') . '/wp-content/plugins';
        $rez = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $nume) {
            $cale = $dir . '/' . $nume;
            if ($nume === '.' || $nume === '..' || $nume === 'index.php' || is_link($cale)) {
                continue;
            }
            $candidati = is_dir($cale) ? (glob($cale . '/*.php') ?: []) : (preg_match('/\.php$/i', $nume) === 1 ? [$cale] : []);
            $versiune = '';
            foreach ($candidati as $f) {
                $cap = (string) @file_get_contents($f, false, null, 0, 8192);
                if (preg_match('/^[ \t\/*#@]*Plugin Name\s*:/mi', $cap) === 1) {
                    $versiune = preg_match('/^[ \t\/*#@]*Version\s*:\s*([0-9][0-9A-Za-z.\-]{0,30})/mi', $cap, $m) === 1 ? $m[1] : '';
                    break;
                }
            }
            $rez[] = ['slug' => preg_replace('/\.php$/i', '', $nume), 'dosar' => $cale, 'versiune' => $versiune];
        }
        return $rez;
    }

    /** @param array{slug: string, dosar: string, versiune: string} $pl */
    private static function plugin(Cereri $cereri, string $rad, array $pl, string $api): array
    {
        $rez = ['slug' => $pl['slug'], 'versiune' => $pl['versiune'], 'stare' => 'neverificabil', 'probleme' => []];
        if ($pl['versiune'] === '' || !is_dir($pl['dosar'])) {
            return $rez + ['motiv' => 'fără versiune sau dintr-un singur fișier', 'fisiere' => self::sume($pl['dosar'])];
        }
        $url = rtrim($api, '/') . '/plugin-checksums/' . rawurlencode($pl['slug']) . '/' . rawurlencode($pl['versiune']) . '.json';
        try {
            $r = $cereri->get($url, 'chrome-win', 'application/json');
        } catch (EroareCaptura $e) {
            $rez['stare'] = 'NU';
            $rez['probleme'][] = 'sumele pluginului ' . $pl['slug'] . ' ' . $pl['versiune'] . ' nu s-au putut citi: ' . $e->getMessage();
            return $rez;
        }
        if ($r->status === 404) {
            return $rez + ['motiv' => 'fără sume publice pe wordpress.org (premium sau din altă parte)', 'fisiere' => self::sume($pl['dosar'])];
        }
        $date = json_decode($r->corp, true);
        if ($r->status !== 200 || !is_array($date['files'] ?? null)) {
            $rez['stare'] = 'NU';
            $rez['probleme'][] = 'sumele pluginului ' . $pl['slug'] . ' ' . $pl['versiune'] . ' nu s-au putut citi (status ' . $r->status . ')';
            return $rez;
        }
        $diferite = [];
        $lipsa = [];
        $cunoscute = [];
        foreach ($date['files'] as $f => $s) {
            $f = (string) $f;
            $cunoscute[$f] = true;
            $acceptate = array_map('strtolower', array_map('strval', (array) (is_array($s) ? ($s['sha256'] ?? []) : $s)));
            $cale = $pl['dosar'] . '/' . ltrim($f, '/');
            if (str_contains($f, '..') || !is_file($cale) || is_link($cale)) {
                $lipsa[] = $f;
                continue;
            }
            if (!in_array((string) hash_file('sha256', $cale), $acceptate, true)) {
                $diferite[] = $f;
            }
        }
        $inPlus = [];
        foreach (self::fisiere($pl['dosar']) as $rel => $f) {
            if (!isset($cunoscute[$rel]) && preg_match(self::RE_PHP_EXT, $rel) === 1) {
                $inPlus[] = $rel;
            }
        }
        $rez = ['slug' => $pl['slug'], 'versiune' => $pl['versiune'], 'stare' => $diferite === [] && $inPlus === [] ? 'verificat' : 'NU',
            'diferite' => $diferite, 'lipsa' => $lipsa, 'in_plus_php' => $inPlus, 'probleme' => []];
        if ($diferite !== []) {
            $rez['probleme'][] = 'pluginul ' . $pl['slug'] . ' ' . $pl['versiune'] . ' diferă de sumele publice: ' . implode(', ', array_slice($diferite, 0, 10));
        }
        if ($inPlus !== []) {
            $rez['probleme'][] = 'fișiere PHP în plus în pluginul ' . $pl['slug'] . ': ' . implode(', ', array_slice($inPlus, 0, 10));
        }
        return $rez;
    }

    /**
     * Fișierele obișnuite de sub un dosar (relativ → cale), fără să urmeze legăturile simbolice.
     *
     * @return array<string, string>
     */
    private static function fisiere(string $dir): array
    {
        if (!is_dir($dir) || is_link($dir)) {
            return [];
        }
        $rez = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && !$f->isLink()) {
                $rez[substr($f->getPathname(), strlen(rtrim($dir, '/')) + 1)] = $f->getPathname();
            }
        }
        ksort($rez);
        return $rez;
    }

    /** @return list<array{fisier: string, sha256: string}> sha256-ul fiecărui fișier (neverificabil: de comparat cu arhiva originală) */
    private static function sume(string $cale): array
    {
        if (is_file($cale)) {
            return [['fisier' => basename($cale), 'sha256' => (string) hash_file('sha256', $cale)]];
        }
        $rez = [];
        foreach (self::fisiere($cale) as $rel => $f) {
            $rez[] = ['fisier' => $rel, 'sha256' => (string) hash_file('sha256', $f)];
        }
        return $rez;
    }

    /** @return list<string> semnele de cod ascuns găsite (gol = niciunul) */
    public static function semne(string $continut): array
    {
        $rez = [];
        foreach ([
            '/\beval\s*\(\s*atob\s*\(/i' => 'eval(atob(…))',
            '/\beval\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i' => 'eval(base64_decode/gzinflate(…))',
            '/\bgzinflate\s*\(\s*base64_decode\s*\(/i' => 'gzinflate(base64_decode(…))',
            '/\bassert\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/i' => 'assert($_…)',
            '/\bpreg_replace\s*\(\s*([\'"]).*?\/e[a-z]*\1/i' => 'preg_replace cu /e',
            '/String\.fromCharCode\s*\((?:\s*\d+\s*,){40,}/' => 'String.fromCharCode lung',
            '/(?:\\\\x[0-9a-f]{2}){100,}/i' => 'șir \\x.. lung',
            '/document\.write\s*\(\s*unescape\s*\(/i' => 'document.write(unescape(…))',
        ] as $re => $semn) {
            if (preg_match($re, $continut) === 1) {
                $rez[] = $semn;
            }
        }
        return $rez;
    }

    /**
     * Linkurile spre alte gazde aflate în elemente ascunse (`hidden`, `display:none`, `visibility:hidden`, `aria-hidden` cu
     * poziționare în afara ecranului) — spamul SEO tipic al unui site compromis.
     *
     * @param list<string> $gazdeProprii
     * @return list<string>
     */
    public static function ascunse(string $html, array $gazdeProprii): array
    {
        $tokeni = Tokenizer::tokenizeaza($html);
        $rez = [];
        $ascunse = []; // indecșii de închidere ai elementelor ascunse deschise
        foreach ($tokeni as $k => $t) {
            if ($t->tip !== Token::DESCHIDERE) {
                continue;
            }
            $ascunse = array_values(array_filter($ascunse, static fn (int $i): bool => $i > $k));
            $stil = strtolower((string) $t->valoare($html, 'style'));
            if ($t->atribut('hidden') !== null || preg_match('/display\s*:\s*none|visibility\s*:\s*hidden|(?:left|top)\s*:\s*-\d{3,}/', $stil) === 1) {
                if ($t->inchidere > $k) {
                    $ascunse[] = $t->inchidere;
                }
            }
            if ($ascunse !== [] && $t->nume === 'a') {
                $href = html_entity_decode((string) $t->valoare($html, 'href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $g = strtolower((string) parse_url($href, PHP_URL_HOST));
                if ($g !== '' && !in_array($g, $gazdeProprii, true)) {
                    $rez[] = $href;
                }
            }
        }
        return array_values(array_unique($rez));
    }
}
