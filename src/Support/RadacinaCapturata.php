<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\ImportWordPress\Captura\CopiereStatica;

/**
 * Fișierele de la rădăcina unui site mutat de pe WordPress (adoptarea WordPress, pasul 9a; INSTRUCTIUNI-BUILDER-C2 §9a): cele
 * copiate de captură sub `capturat/radacina/<cale>` (CopiereStatica::RADACINA: `/favicon.ico`, `/logo.svg`,
 * `/wp-admin/images/…`) se servesc, pe cadrul capturat, la adresa lor, ca răspunsuri înghețate: doar GET și HEAD, doar tipurile
 * copiate (CopiereStatica::TIPURI, cu tipul fix al extensiei), `nosniff`, cache-ul arborilor copiați, iar un SVG cu
 * `Content-Security-Policy: sandbox` în locul politicii site-ului. O adresă care se ciocnește cu un fișier, un dosar sau o rută
 * a CMS-ului (CopiereStatica::coliziune) nu se servește de aici (o arată `diagnostic.php --capturat`, ca NU); nici o cale cu un
 * segment ascuns, cu „..”, cu „\” sau cu octetul 0, nici o legătură simbolică, nici un fișier din afara dosarului.
 *
 * Fără cadrul capturat sau fără fișier nu face nimic.
 */
final class RadacinaCapturata
{
    /** Tipul fix al fiecărei extensii copiate (nimic ghicit din conținut). */
    public const TIPURI = [
        'css' => 'text/css; charset=utf-8', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
    ];

    /** Fișierul de servit pentru o cale cerută (calea pe disc), sau null. */
    public static function fisier(string $cale): ?string
    {
        $cale = rawurldecode($cale);
        if ($cale === '' || $cale[0] !== '/' || str_contains($cale, "\0") || str_contains($cale, '\\') || str_ends_with($cale, '/')) {
            return null;
        }
        $segmente = explode('/', substr($cale, 1));
        foreach ($segmente as $s) {
            if ($s === '' || $s[0] === '.') {
                return null;
            }
        }
        if (in_array($segmente[0], CopiereStatica::ARBORI, true)) {
            return null; // arborii copiați îi servește serverul direct, cu .htaccess-ul lor
        }
        $ext = strtolower(pathinfo($cale, PATHINFO_EXTENSION));
        if (!isset(self::TIPURI[$ext]) || !in_array($ext, CopiereStatica::TIPURI, true)) {
            return null;
        }
        $baza = realpath(Paths::public(ltrim(CopiereStatica::RADACINA, '/')));
        $f = Paths::public(ltrim(CopiereStatica::RADACINA, '/') . $cale);
        if ($baza === false || !is_file($f)) {
            return null;
        }
        // Nicio legătură simbolică pe drum, iar calea reală rămâne în dosar.
        $drum = $baza;
        foreach ($segmente as $s) {
            $drum .= '/' . $s;
            if (is_link($drum)) {
                return null;
            }
        }
        $real = realpath($f);
        if ($real === false || !str_starts_with($real, $baza . '/')) {
            return null;
        }
        return CopiereStatica::coliziune($cale) === null ? $real : null;
    }

    /** Răspunde cu fișierul, dacă cererea e una. Întoarce dacă a răspuns. */
    public static function intercepteaza(string $metoda, string $uri): bool
    {
        $metoda = strtoupper($metoda);
        if (Tema::cadru() !== 'capturat' || !in_array($metoda, ['GET', 'HEAD'], true)) {
            return false;
        }
        $f = self::fisier((string) (parse_url($uri, PHP_URL_PATH) ?? ''));
        if ($f === null) {
            return false;
        }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!headers_sent()) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort(); // nicio sesiune și niciun cookie pe un fișier înghețat (recenzia 2, R2-5)
            }
            header_remove('Set-Cookie');
            CspSite::trimite($uri, true);
            http_response_code(200);
            header('Content-Type: ' . self::TIPURI[$ext]);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=604800');
            if ($ext === 'svg') {
                header('Content-Security-Policy: sandbox');
            }
        }
        if ($metoda === 'GET') {
            readfile($f);
        }
        return true;
    }

    /**
     * Fișierele de sub `capturat/radacina/` care se ciocnesc cu CMS-ul (pentru diagnostic: fiecare e NU).
     *
     * @return list<string>
     */
    public static function coliziuni(): array
    {
        $baza = Paths::public(ltrim(CopiereStatica::RADACINA, '/'));
        if (!is_dir($baza)) {
            return [];
        }
        $rez = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($baza, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $x) {
            if ($x->isFile()) {
                $cale = substr($x->getPathname(), strlen($baza));
                if (($c = CopiereStatica::coliziune($cale)) !== null) {
                    $rez[] = $c;
                }
            }
        }
        return $rez;
    }
}
