<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

use App\Http\Router;
use App\Support\Paths;
use App\Support\SvgSigur;

/**
 * Copierea fișierelor statice ale site-ului WordPress în docroot-ul nou (pasul 4a; DECIZIE §2.3 #3, §2.9), de pe disc (din
 * instalarea WordPress, pe server), DOAR în arborii copiați (ARBORI, fiecare cu `.htaccess`-ul lui): `wp-content/**` și
 * `wp-includes/**` la aceeași cale, orice alt fișier propriu (`/favicon.ico`, `/logo.svg`, `/wp-admin/images/…`) sub
 * `capturat/radacina/<cale>` (pasul 9a îl servește la adresa lui, ca răspuns înghețat; o adresă care se ciocnește cu un fișier
 * sau o rută a CMS-ului e NU: coliziune()). Doar tipurile din TIPURI (fonturi, foi, poze, video): **niciun `.js` și niciun
 * `.php`**. Refuzate, fiecare cu motivul: o cale ieșită din rădăcina sursei (`realpath`), o legătură simbolică oriunde pe drum,
 * o legătură tare (nlink > 1), un fișier sau un dosar ascuns, unul peste 200 MB, unul cu cod PHP înăuntru, un SVG care nu
 * trece de SvgSigur::problemaFisierNesigur(). Codul PHP: într-o poză (RASTER) doar `<?php` (orice litere: RE_PHP_IMAGINE),
 * după ce semnătura ei e a extensiei și antetul îl acceptă getimagesize() (altfel refuzată). Octeții comprimați ai unei poze
 * reale conțin uneori „<?=” sau „<?” urmat de spațiu; pozele stau doar în arborii copiați, unde .htaccess-ul scoate
 * handlerele PHP și refuză extensiile PHP, deci acolo `<?php` e semnalul care contează. În orice alt fișier: RE_PHP.
 *
 * Copierea deschide sursa O SINGURĂ DATĂ: inode-ul deschis trebuie să fie al căii verificate, iar verificările, sha256-ul și
 * scrierea folosesc aceiași octeți, citiți din acel fișier deschis (nicio fereastră între verificare și copiere). Foile de stil
 * își fac adresele proprii relative (Normalizare::css, §6.7 #2). Scrierea e atomică (temporar + redenumire), cu drepturile 0644;
 * copiaza() întoarce calea publicată și ambele sha256-uri (sursa și fișierul publicat), pentru manifest.
 */
final class CopiereStatica
{
    public const TIPURI = ['css', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'mp4', 'webm'];

    public const MARIME_MAXIMA = 200 * 1024 * 1024;

    /** Arborii copiați în docroot-ul nou (fiecare cu `.htaccess`-ul lui din deploy/stub/). */
    public const ARBORI = ['wp-content', 'wp-includes', 'capturat'];

    /** Dosarul fișierelor proprii din afara wp-content/ și wp-includes/ (servite de pasul 9a la adresa lor). */
    public const RADACINA = '/capturat/radacina';

    /** Codul PHP în textul copiat (CSS, SVG) și în fonturi și video: `<?php`, `<?=` sau `<?` urmat de un spațiu (`<?xml` nu). */
    public const RE_PHP = '/<\?(?:php|=|[\t\n\x0B\f\r ])/i';

    /** Codul PHP într-o poză verificată (semnătura și antetul): doar `<?php` (orice litere). */
    public const RE_PHP_IMAGINE = '/<\?php/i';

    /** Pozele raster: extensia → tipul pe care trebuie să-l dea getimagesize(). */
    public const RASTER = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF,
        'webp' => IMAGETYPE_WEBP, 'avif' => IMAGETYPE_AVIF, 'ico' => IMAGETYPE_ICO];

    /** Tipurile citite întregi (verificate și, la CSS, rescrise ca text); restul se copiază pe bucăți. */
    private const TEXT = ['css', 'svg'];

    private const MARIME_TEXT = 32 * 1024 * 1024;

    /** @var array<string, Router> */
    private static array $rute = [];

    /**
     * Docroot-ul nou (`--public`): calea lui reală (Captura::caleReala). Dacă e într-un arbore de lucru git, arborii copiați
     * (ARBORI) trebuie să fie ignorați de git acolo (`git check-ignore`), altfel fișierele clientului ar apărea de comis: refuzat.
     */
    public static function verificaTinta(string $docroot): string
    {
        $tinta = Captura::caleReala($docroot);
        $sus = $tinta;
        while (!is_dir($sus) && $sus !== '/') {
            $sus = dirname($sus);
        }
        $git = null;
        for ($d = $sus; ; $d = dirname($d)) {
            if (file_exists($d . '/.git')) {
                $git = $d;
                break;
            }
            if ($d === '/' || $d === dirname($d)) {
                break;
            }
        }
        if ($git === null) {
            return $tinta;
        }
        $rel = ltrim(substr($tinta, strlen(rtrim($git, '/'))), '/');
        foreach (self::ARBORI as $arbore) {
            $cale = ($rel !== '' ? $rel . '/' : '') . $arbore . '/proba-ignorare';
            $p = @proc_open(['git', '-C', $git, 'check-ignore', '-q', '--no-index', '--', $cale], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipe);
            $cod = is_resource($p) ? proc_close($p) : -1;
            if ($cod !== 0) {
                throw new EroareCaptura('folosire', '--public=' . $tinta . ' e în arborele git ' . $git . ', iar ' . $cale . ' nu e ignorat (.gitignore):'
                    . ' fișierele clientului ar ajunge de comis; ignoră ' . implode(', ', self::ARBORI) . ' acolo sau alege alt docroot');
            }
        }
        return $tinta;
    }

    /** Calea publicată (relativă la docroot) a unui fișier propriu: wp-content/ și wp-includes/ la aceeași cale, restul sub RADACINA. */
    public static function tinta(string $cale): string
    {
        $cale = '/' . ltrim($cale, '/');
        $prim = explode('/', ltrim($cale, '/'))[0];
        return in_array($prim, ['wp-content', 'wp-includes'], true) ? $cale : self::RADACINA . $cale;
    }

    /**
     * Pentru un fișier propriu care merge sub RADACINA (pasul 9a îl servește la adresa lui): se ciocnește cu un fișier sau un dosar
     * al CMS-ului (`public/` din cod sau docroot-ul nou) ori cu o rută a lui (alta decât pagina generală `/{slug*}`)? Motivul
     * (NU), sau null.
     */
    public static function coliziune(string $cale, ?string $docroot = null): ?string
    {
        $cale = '/' . ltrim($cale, '/');
        if (self::tinta($cale) === $cale) {
            return null;
        }
        return self::coliziuneCms($cale, $docroot);
    }

    /** O cale oarecare se ciocnește cu un fișier, un dosar sau o rută (alta decât `/{slug*}`) a CMS-ului? Motivul, sau null. */
    public static function coliziuneCms(string $cale, ?string $docroot = null): ?string
    {
        $cale = '/' . ltrim($cale, '/');
        $radacinaCms = rtrim(Paths::radacina(), '/');
        $prim = explode('/', ltrim($cale, '/'))[0];
        // Docroot-ul CMS-ului prin Paths (azi public/ din cod, în Etapa 2 public_html/ al site-ului) și docroot-ul nou.
        foreach (array_filter([rtrim(Paths::public(), '/'), $docroot !== null ? rtrim($docroot, '/') : null]) as $dir) {
            if ($prim !== '' && !in_array($prim, self::ARBORI, true) && (file_exists($dir . '/' . $prim) || is_link($dir . '/' . $prim))) {
                return 'adresa ' . $cale . ' se ciocnește cu ' . $dir . '/' . $prim . ' (un fișier sau un dosar al CMS-ului)';
            }
        }
        $g = self::rute($radacinaCms)->gaseste('GET', $cale);
        if ($g !== null && $g['tipar'] !== '/{slug*}') {
            return 'adresa ' . $cale . ' se ciocnește cu ruta CMS-ului ' . $g['tipar'];
        }
        return null;
    }

    /**
     * Fonturile Google ale unei capturi urcate (aduse cu `--fonturi --lista` în `fonturi/` al capturii), publicate în
     * `<docroot>/capturat/fonturi/`: doar numele scrise de FonturiGoogle (`<16 hexa>.css`, `<16 hexa>.<woff2|woff|ttf|otf>`), fiecare
     * fișier din dosarul capturii (fără legături, fără „..”) cu sha256-ul din manifestul verificat; un fișier deja publicat cu alt
     * conținut e NU. Întoarce câte fișiere au fost publicate.
     *
     * @param list<mixed> $foi manifestul verificat → `fonturi`
     */
    public static function publicaFonturi(string $dosarCaptura, array $foi, string $docroot): int
    {
        $fisiere = [];
        foreach ($foi as $f) {
            if (!is_array($f) || !is_string($f['fisier_css'] ?? null) || !is_string($f['sha256_css'] ?? null) || !is_array($f['fonturi'] ?? null)) {
                throw new EroareCaptura('lant', 'manifestul are o foaie de fonturi greșită');
            }
            $fisiere[$f['fisier_css']] = $f['sha256_css'];
            foreach ($f['fonturi'] as $x) {
                if (!is_array($x) || !is_string($x['fisier'] ?? null) || !is_string($x['sha256'] ?? null)) {
                    throw new EroareCaptura('lant', 'manifestul are un font greșit');
                }
                $fisiere[$x['fisier']] = $x['sha256'];
            }
        }
        $tinta = rtrim($docroot, '/') . FonturiGoogle::DOSAR;
        // Întâi totul verificat (sursele, sumele, coliziunile), apoi copiat: o problemă nu lasă o publicare pe jumătate.
        $deCopiat = [];
        foreach ($fisiere as $nume => $sha) {
            if (preg_match('/^[0-9a-f]{16}\\.(?:css|woff2|woff|ttf|otf)$/D', (string) $nume) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $sha) !== 1) {
                throw new EroareCaptura('lant', 'manifestul are un nume de font nepermis: ' . $nume);
            }
            $sursa = DosarCapturi::caleInDosar($dosarCaptura, FonturiGoogle::DOSAR_CAPTURA . '/' . $nume, false);
            if ($sursa === null || !hash_equals($sha, (string) hash_file('sha256', $sursa))) {
                throw new EroareCaptura('lant', 'fontul ' . $nume . ' lipsește din captură sau are alt sha256 decât în manifest');
            }
            $dest = $tinta . '/' . $nume;
            if (file_exists($dest) || is_link($dest)) {
                if (is_link($dest) || !hash_equals($sha, (string) hash_file('sha256', $dest))) {
                    throw new EroareCaptura('scriere', 'coliziune: ' . FonturiGoogle::DOSAR . '/' . $nume . ' există deja cu alt conținut');
                }
                continue;
            }
            $deCopiat[$dest] = [$sursa, $sha];
        }
        if ($deCopiat !== [] && !is_dir($tinta) && !@mkdir($tinta, 0755, true) && !is_dir($tinta)) {
            throw new EroareCaptura('scriere', 'nu pot face ' . $tinta);
        }
        $n = 0;
        foreach ($deCopiat as $dest => [$sursa, $sha]) {
            // Prin un fișier temporar și o redenumire (recenzia 3, R1-N5): o copie întreruptă nu lasă un font pe jumătate cu
            // numele final (care ar bloca rulările următoare ca o coliziune); temporarul rămas nu are un nume de font.
            $tmp = $dest . '.copiere-' . getmypid();
            if (!@copy($sursa, $tmp) || !hash_equals($sha, (string) hash_file('sha256', $tmp)) || !@rename($tmp, $dest)) {
                @unlink($tmp);
                throw new EroareCaptura('scriere', 'nu pot publica ' . $dest);
            }
            $n++;
        }
        return $n;
    }

    /**
     * Fișierele de pe disc care nu sunt cele din manifestul verificat al unei capturi urcate (recenzia 2, R1-6): fiecare fișier
     * static (sursa „disc”) trebuie să aibă aceeași adresă și același sha256 al sursei. Problemele (NU); goală = se poate copia.
     *
     * @param list<array<string, mixed>> $fisiere manifestul de acum → fisiere
     * @param list<mixed> $verificate manifestul verificat al capturii urcate → fisiere
     * @return list<string>
     */
    public static function nepotriviriManifest(array $fisiere, array $verificate): array
    {
        $sume = [];
        foreach ($verificate as $fv) {
            if (is_array($fv) && is_string($fv['url'] ?? null)) {
                $sume[$fv['url']] = (string) ($fv['sha256_sursa'] ?? $fv['sha256'] ?? '');
            }
        }
        $rez = [];
        foreach ($fisiere as $f) {
            if (($f['sursa'] ?? '') !== 'disc') {
                continue;
            }
            $asteptat = $sume[(string) ($f['url'] ?? '')] ?? null;
            if ($asteptat === null || $asteptat === '' || !hash_equals($asteptat, (string) ($f['sha256_sursa'] ?? $f['sha256'] ?? ''))) {
                $rez[] = 'fișierul de pe disc nu e cel din captura urcată (' . ($asteptat === null ? 'lipsește din manifestul ei' : 'alt sha256') . '): '
                    . (string) ($f['cale'] ?? '?');
            }
        }
        return $rez;
    }

    /** Tiparul rutei CMS-ului pentru o cale și o metodă (implicit GET; `/{slug*}` pentru pagina generală), sau null (nicio rută: 404). */
    public static function rutaCms(string $cale, string $metoda = 'GET'): ?string
    {
        $g = self::rute(rtrim(Paths::radacina(), '/'))->gaseste($metoda, '/' . ltrim($cale, '/'));
        return $g !== null ? (string) $g['tipar'] : null;
    }

    private static function rute(string $radacinaCms): Router
    {
        if (!isset(self::$rute[$radacinaCms])) {
            $r = new Router();
            (require $radacinaCms . '/src/Http/rute.php')($r);
            self::$rute[$radacinaCms] = $r;
        }
        return self::$rute[$radacinaCms];
    }

    /** Motivul pentru care fișierul de la calea relativă `$cale` (sub `$radacina`) nu se copiază, sau null (aceeași citire ca la copiere, fără scriere). */
    public static function problema(string $radacina, string $cale): ?string
    {
        try {
            self::citeste($radacina, $cale, null, null);
            return null;
        } catch (EroareCaptura $e) {
            return $e->getMessage();
        }
    }

    /**
     * Semnătura (primii octeți) e a extensiei `$ext` (din RASTER)? PNG, JPEG (FF D8 FF), GIF87a/89a, WebP (RIFF…WEBP), AVIF
     * (cutia ftyp cu marca avif/avis, principală sau compatibilă), ICO (00 00 01 00).
     */
    public static function semnaturaImagine(string $ext, string $cap): bool
    {
        switch ($ext) {
            case 'png':
                return str_starts_with($cap, "\x89PNG\r\n\x1a\n");
            case 'jpg':
            case 'jpeg':
                return str_starts_with($cap, "\xFF\xD8\xFF");
            case 'gif':
                return str_starts_with($cap, 'GIF87a') || str_starts_with($cap, 'GIF89a');
            case 'webp':
                return strlen($cap) >= 12 && str_starts_with($cap, 'RIFF') && substr($cap, 8, 4) === 'WEBP';
            case 'ico':
                return str_starts_with($cap, "\x00\x00\x01\x00");
            case 'avif':
                if (strlen($cap) < 16 || substr($cap, 4, 4) !== 'ftyp') {
                    return false;
                }
                $n = min((int) unpack('N', substr($cap, 0, 4))[1], strlen($cap));
                for ($i = 8; $i + 4 <= $n; $i += ($i === 8 ? 8 : 4)) {
                    if (in_array(substr($cap, $i, 4), ['avif', 'avis'], true)) {
                        return true;
                    }
                }
                return false;
        }
        return false;
    }

    /** Cod PHP (RE_PHP) oriunde în fișier, citit pe bucăți cu suprapunere. Un fișier necitibil e tratat ca având. */
    public static function areCodPhp(string $fisier): bool
    {
        $h = @fopen($fisier, 'rb');
        if ($h === false) {
            return true;
        }
        $coada = '';
        try {
            while (!feof($h)) {
                $bucata = (string) fread($h, 1 << 20);
                if (preg_match(self::RE_PHP, $coada . $bucata) === 1) {
                    return true;
                }
                $coada = substr($coada . $bucata, -5);
            }
            return false;
        } finally {
            fclose($h);
        }
    }

    /**
     * CSS-ul cum îl scrie copia: adresele proprii relative (Normalizare::css), iar fonturile Google aduse printr-un @import al unei
     * foi proprii (recenzia 2, R1-8) trec pe copia locală (FonturiGoogle::cale, aceeași cheie ca în pagini); CSP-ul site-ului
     * (style-src 'self') ar bloca adresa Google. Același drum și pentru CSS-ul din `<style>`-urile paginilor, când se compară cu
     * foile (StiluriComune).
     */
    public static function textCss(string $text, ?Normalizare $normalizare): string
    {
        $scris = $normalizare !== null ? $normalizare->css($text) : $text;
        return (string) preg_replace_callback('/@import\s+(?:url\(\s*)?([\'"]?)((?:https?:)?\/\/fonts\.googleapis\.com\/css[^\'")\s;]*)\1\s*\)?/i',
            static fn (array $m): string => '@import url("' . FonturiGoogle::cale($m[2]) . '")', $scris);
    }

    /**
     * Textul pe care copiaza() l-ar scrie pentru `$cale` (CSS-ul rescris de Normalizare), fără să scrie nimic: aceleași verificări,
     * dintr-o singură deschidere. Pentru planul fișierelor statice (Statice, stilurile comune).
     *
     * @throws EroareCaptura dacă fișierul e refuzat
     */
    public static function continut(string $radacinaSursa, string $cale, ?Normalizare $normalizare = null): string
    {
        $out = fopen('php://memory', 'w+b');
        if ($out === false) {
            throw new EroareCaptura('scriere', 'nu pot citi ' . $cale);
        }
        try {
            self::citeste($radacinaSursa, $cale, $out, $normalizare);
            rewind($out);
            return (string) stream_get_contents($out);
        } finally {
            fclose($out);
        }
    }

    /**
     * Copiază `$cale` din `$radacinaSursa` în `$radacinaTinta`, la calea publicată (tinta()), dintr-o singură deschidere a sursei.
     *
     * @return array{publicat: string, sha256_sursa: string, sha256_publicat: string, marime: int}
     * @throws EroareCaptura dacă fișierul e refuzat sau nu se poate scrie
     */
    public static function copiaza(string $radacinaSursa, string $radacinaTinta, string $cale, ?Normalizare $normalizare = null): array
    {
        $publicat = self::tinta($cale);
        $tinta = rtrim($radacinaTinta, '/') . $publicat;
        $dir = dirname($tinta);
        $drum = rtrim($radacinaTinta, '/');
        foreach (explode('/', trim(dirname($publicat), '/')) as $seg) {
            $drum .= '/' . $seg;
            if (is_link($drum)) {
                throw new EroareCaptura('scriere', 'legătură simbolică în docroot-ul nou: ' . $drum);
            }
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new EroareCaptura('scriere', 'nu pot face dosarul ' . $dir);
        }
        if (is_link($tinta)) {
            throw new EroareCaptura('scriere', 'legătură simbolică în docroot-ul nou: ' . $tinta);
        }
        $tmp = $dir . '/.copie-' . bin2hex(random_bytes(8));
        $out = @fopen($tmp, 'xb');
        if ($out === false) {
            throw new EroareCaptura('scriere', 'nu pot scrie ' . $tmp);
        }
        try {
            $r = self::citeste($radacinaSursa, $cale, $out, $normalizare);
            if (!fflush($out) || !fclose($out)) {
                throw new EroareCaptura('scriere', 'nu pot scrie ' . $tinta);
            }
            $out = null;
            if (!@chmod($tmp, 0644) || !@rename($tmp, $tinta)) {
                throw new EroareCaptura('scriere', 'nu pot scrie ' . $tinta);
            }
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);
            throw $e;
        }
        $shaPublicat = (string) hash_file('sha256', $tinta);
        if ($shaPublicat !== $r['sha256_scris']) {
            throw new EroareCaptura('scriere', 'fișierul scris nu are octeții citiți: ' . $tinta);
        }
        return ['publicat' => $publicat, 'sha256_sursa' => $r['sha256'], 'sha256_publicat' => $shaPublicat, 'marime' => $r['marime']];
    }

    /**
     * Verificările și citirea dintr-o singură deschidere: calea (fără „.”/„..”, ascunse, legături simbolice, ieșiri prin
     * realpath), tipul, apoi fișierul deschis (inode-ul căii verificate, nlink 1, mărimea), octeții (RE_PHP; SVG-ul prin SvgSigur)
     * și, dacă `$out` e dat, scrierea acelorași octeți (CSS-ul rescris de Normalizare::css). Aruncă EroareCaptura cu motivul.
     *
     * @param resource|null $out
     * @return array{sha256: string, sha256_scris: string, marime: int}
     */
    private static function citeste(string $radacina, string $cale, $out, ?Normalizare $normalizare): array
    {
        $radacina = rtrim($radacina, '/');
        $cale = '/' . ltrim($cale, '/');
        $nu = static fn (string $motiv): EroareCaptura => new EroareCaptura('scriere', $motiv);
        if (str_contains($cale, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $cale) === 1) {
            throw $nu('cale cu „.” sau „..”: ' . $cale);
        }
        foreach (explode('/', trim($cale, '/')) as $seg) {
            if ($seg === '' || $seg[0] === '.') {
                throw $nu('fișier sau dosar ascuns pe drum: ' . $cale);
            }
        }
        $ext = strtolower(pathinfo($cale, PATHINFO_EXTENSION));
        if (!in_array($ext, self::TIPURI, true)) {
            throw $nu('tip nepermis (.' . $ext . '): se copiază doar ' . implode(', ', self::TIPURI) . ' (niciun .js, niciun .php)');
        }
        // Nicio bucată executabilă oriunde în nume (`poza.php.png`, ca Media și șabloanele .htaccess; recenzia 2, R1-5).
        foreach (array_slice(explode('.', basename($cale)), 1, -1) as $bucata) {
            if (preg_match('/^(php[0-9]?|pht|phtm|phtml|phps|phar|shtm|shtml|pl|py|cgi|sh|js|mjs|cjs)$/i', $bucata) === 1) {
                throw $nu('nume cu o extensie executabilă înăuntru (.' . $bucata . '): refuzat');
            }
        }
        $drum = $radacina;
        foreach (explode('/', trim($cale, '/')) as $seg) {
            $drum .= '/' . $seg;
            if (is_link($drum)) {
                throw $nu('legătură simbolică pe drum: ' . $drum);
            }
        }
        $real = realpath($radacina . $cale);
        $realRad = realpath($radacina);
        if ($real === false || $realRad === false || !str_starts_with($real, rtrim($realRad, '/') . '/') || !is_file($real)) {
            throw $nu('fișierul nu e în rădăcina sursei (realpath): ' . $cale);
        }
        $st = @lstat($radacina . $cale);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
            throw $nu('nu e un fișier obișnuit: ' . $cale);
        }
        if ($st['nlink'] !== 1) {
            throw $nu('legătură tare (nlink ' . $st['nlink'] . '): ' . $cale);
        }
        if ($st['size'] > self::MARIME_MAXIMA) {
            throw $nu('fișier peste 200 MB (' . $st['size'] . ' octeți): ' . $cale);
        }
        $h = @fopen($real, 'rb');
        if ($h === false) {
            throw $nu('fișierul nu se poate citi: ' . $cale);
        }
        try {
            $fs = fstat($h);
            if ($fs === false || $fs['ino'] !== $st['ino'] || $fs['dev'] !== $st['dev'] || $fs['nlink'] !== 1) {
                throw $nu('fișierul deschis nu e cel verificat (schimbat între verificare și deschidere): ' . $cale);
            }
            $ctx = hash_init('sha256');
            $text = in_array($ext, self::TEXT, true) ? '' : null;
            $ctxScris = $text === null ? hash_init('sha256') : null;
            $coada = '';
            $citit = 0;
            $re = self::RE_PHP;
            $prima = true;
            while (!feof($h)) {
                $b = fread($h, 1 << 20);
                if ($b === false) {
                    throw $nu('fișierul nu se poate citi: ' . $cale);
                }
                $citit += strlen($b);
                if ($citit > self::MARIME_MAXIMA || ($text !== null && $citit > self::MARIME_TEXT)) {
                    throw $nu('fișier prea mare pentru tipul lui (' . $citit . ' octeți): ' . $cale);
                }
                if ($prima && isset(self::RASTER[$ext])) {
                    // O poză: semnătura extensiei și antetul acceptat de getimagesize (din primul MB), apoi doar <?php.
                    if (!self::semnaturaImagine($ext, $b)) {
                        throw $nu('semnătura fișierului nu e a unui .' . $ext . ': ' . $cale);
                    }
                    $info = @getimagesizefromstring($b);
                    if ($info === false || $info[2] !== self::RASTER[$ext] || $info[0] < 1 || $info[1] < 1) {
                        throw $nu('antetul nu e al unei poze .' . $ext . ' valide (getimagesize): ' . $cale);
                    }
                    $re = self::RE_PHP_IMAGINE;
                }
                $prima = false;
                if (preg_match($re, $coada . $b) === 1) {
                    throw $nu($re === self::RE_PHP_IMAGINE ? 'poza conține cod PHP („<?php”): ' . $cale
                        : 'fișierul conține cod PHP („<?php”, „<?=” sau „<?” urmat de spațiu): ' . $cale);
                }
                $coada = substr($coada . $b, -5);
                hash_update($ctx, $b);
                if ($text !== null) {
                    $text .= $b;
                } else {
                    hash_update($ctxScris, $b);
                    if ($out !== null && fwrite($out, $b) !== strlen($b)) {
                        throw new EroareCaptura('scriere', 'nu pot scrie copia lui ' . $cale);
                    }
                }
            }
            $fs2 = fstat($h);
            if ($fs2 === false || $fs2['size'] !== $citit || $fs2['mtime'] !== $fs['mtime'] || $fs2['ino'] !== $fs['ino']) {
                throw $nu('fișierul s-a schimbat în timpul citirii: ' . $cale);
            }
        } finally {
            fclose($h);
        }
        $sha = hash_final($ctx);
        if ($text === null) {
            return ['sha256' => $sha, 'sha256_scris' => hash_final($ctxScris), 'marime' => $citit];
        }
        if ($ext === 'svg' && ($p = SvgSigur::problemaFisierNesigur($text)) !== null) {
            throw $nu('SVG refuzat de SvgSigur (' . $p . '): ' . $cale);
        }
        $scris = $ext === 'css' ? self::textCss($text, $normalizare) : $text;
        if ($out !== null && fwrite($out, $scris) !== strlen($scris)) {
            throw new EroareCaptura('scriere', 'nu pot scrie copia lui ' . $cale);
        }
        return ['sha256' => $sha, 'sha256_scris' => hash('sha256', $scris), 'marime' => $citit];
    }
}
