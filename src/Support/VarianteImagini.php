<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Pozele încărcate, în mărimile ecranului (WP-40, SEO partea B, B5): la încărcare (AdminController::handleMediaUpload)
 * și o dată pentru pozele existente (scripts/optimizeaza-imagini.php), fiecare poză JPEG, PNG, WebP sau GIF primește
 * variante WebP de 160, 400, 800 și 1200 px lățime (doar cele mai mici decât poza), iar lățimea și înălțimea ei intră în
 * cache-ul dimensiunilor (ImageDimensions). Paginile pun atunci pe <img> și `srcset` (variantele care există pe disc,
 * plus poza întreagă) cu `sizes`, deci un telefon descarcă poza de 400 px, nu pe cea de 2.000.
 *
 * Variantele stau lângă încărcări, într-un dosar separat, ca galeria să nu le arate: `/uploads/_variante/<dosar>/<nume.ext>-<lățime>w.webp`
 * (de exemplu /uploads/products/ceai.png → /uploads/_variante/products/ceai.png-400w.webp). Poza încărcată nu se schimbă
 * niciodată (datele structurate, fluxul Merchant și linkurile vechi o folosesc). O variantă se reface doar dacă poza e
 * mai nouă decât ea; atunci toate variantele ei vechi pleacă întâi (și la ștergerea pozei din admin, `sterge`). Fără GD cu WebP (`imagewebp`, de verificat pe Hostinger cu `php -m`), nu se face nimic și paginile
 * rămân ca înainte.
 *
 * Nu se ating: pozele altor site-uri, SVG-urile, dosarul pozelor de profil ale membrilor (`uploads/gallery/profile/`,
 * date personale) și variantele însele.
 *
 * Comutatorul: setarea `imagini_srcset` (implicit pornită; înghețată la „0” pe site-ul înghețat: paginile lui rămân identice până o
 * vede pe site-ul de verificare). Oprită, paginile nu primesc `srcset` (variantele se fac oricum la încărcare).
 */
final class VarianteImagini
{
    public const MARIMI = [160, 400, 800, 1200];
    public const DOSAR = '_variante';
    public const EXCLUSE = ['gallery/profile/', '_variante/'];
    private const CALITATE = 80;
    /** Peste 25 MP (de exemplu 6000 × 4000) poza nu primește variante: memoria ar trece de ce dă o găzduire obișnuită. */
    private const PIXELI_MAXIM = 25000000;

    private static ?bool $activ = null;

    /** Comutatorul paginii de acum (public/index.php, din setări). */
    public static function configureaza(array $setari): void
    {
        self::$activ = (string) ($setari['imagini_srcset'] ?? '1') === '1';
    }

    public static function activ(): bool
    {
        return self::$activ === true;
    }

    public static function suportat(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
    }

    /** Calea relativă la /uploads/ a unei adrese de poză din site, sau null (alt site, SVG, alt dosar, exclusă). */
    public static function caleUploads(string $adresa): ?string
    {
        $adresa = trim(explode('#', explode('?', $adresa, 2)[0], 2)[0]);
        if (preg_match('#^https?://[^/]+(/.*)$#i', $adresa, $m) === 1) {
            $gazda = strtolower((string) parse_url($adresa, PHP_URL_HOST));
            if ($gazda !== strtolower((string) parse_url(AppUrl::base(), PHP_URL_HOST))) {
                return null;
            }
            $adresa = $m[1];
        }
        if (!str_starts_with($adresa, '/uploads/')) {
            return null;
        }
        $rel = rawurldecode(substr($adresa, strlen('/uploads/')));
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0") || preg_match('/\.(jpe?g|png|webp|gif)$/i', $rel) !== 1) {
            return null;
        }
        foreach (self::EXCLUSE as $exclus) {
            if (str_starts_with($rel, $exclus)) {
                return null;
            }
        }
        return $rel;
    }

    /**
     * Calea relativă (la /uploads/) a variantei de $latime px a unei poze. Numele păstrează extensia pozei (ceai.png →
     * ceai.png-400w.webp), ca ceai.png și ceai.jpg din același dosar să nu aibă aceleași variante.
     */
    public static function caleVarianta(string $rel, int $latime): string
    {
        $dosar = dirname($rel);
        return self::DOSAR . '/' . ($dosar === '.' ? '' : $dosar . '/') . basename($rel) . '-' . $latime . 'w.webp';
    }

    /** Șterge variantele unei poze (la ștergerea ei și înainte să fie refăcute). Întoarce câte au plecat. */
    public static function sterge(string $rel): int
    {
        $sterse = 0;
        foreach (self::MARIMI as $marime) {
            $cale = Paths::uploads() . '/' . self::caleVarianta($rel, $marime);
            if (is_file($cale) && @unlink($cale)) {
                $sterse++;
            }
        }
        return $sterse;
    }

    /**
     * Memoria pentru o poză de $latime × $inaltime: o cere (ridică memory_limit, ca ImagineDistribuire) dacă e nevoie;
     * false = nu se poate (atunci poza rămâne fără variante, fără eroare fatală la încărcare).
     */
    private static function memoriePentru(int $latime, int $inaltime): bool
    {
        // Poza decodată (~5 octeți pe pixel, cu GD) plus cea mai mare variantă și o rezervă.
        $nevoie = memory_get_usage() + $latime * $inaltime * 5 + 1200 * 1200 * 5 + 16 * 1024 * 1024;
        $limita = self::octeti((string) ini_get('memory_limit'));
        if ($limita < 0 || $limita >= $nevoie) {
            return true;
        }
        @ini_set('memory_limit', (string) (int) ceil($nevoie / 1048576) . 'M');
        $dupa = self::octeti((string) ini_get('memory_limit'));
        return $dupa < 0 || $dupa >= $nevoie;
    }

    private static function octeti(string $valoare): int
    {
        $valoare = trim($valoare);
        if ($valoare === '' || $valoare === '-1') {
            return -1;
        }
        $numar = (int) $valoare;
        return match (strtolower(substr($valoare, -1))) {
            'g' => $numar * 1073741824,
            'm' => $numar * 1048576,
            'k' => $numar * 1024,
            default => $numar,
        };
    }

    /**
     * Face variantele unei poze din /uploads/ (cale relativă). Întoarce câte variante au fost scrise (0 = toate erau la zi
     * sau nu e nimic de făcut); null = poza nu se poate citi (sau nu e GD cu WebP).
     */
    public static function genereaza(string $rel): ?int
    {
        if (!self::suportat()) {
            return null;
        }
        $sursa = Paths::uploads() . '/' . $rel;
        $info = @getimagesize($sursa);
        if (!is_file($sursa) || $info === false || (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > self::PIXELI_MAXIM) {
            return null;
        }
        [$latime, $inaltime] = [(int) $info[0], (int) $info[1]];
        // Dimensiunile intră în cache-ul lor (width/height pe <img>), citite acum, la încărcare, nu la prima afișare.
        ImageDimensions::pentru('/uploads/' . $rel);
        $deFacut = [];
        $laZi = true;
        foreach (self::MARIMI as $marime) {
            $tinta = Paths::uploads() . '/' . self::caleVarianta($rel, $marime);
            if ($marime >= $latime) {
                // O variantă mai mare decât poza de acum (rămasă de la o poză mai mare, înlocuită) nu mai e bună.
                $laZi = $laZi && !is_file($tinta);
                continue;
            }
            $deFacut[$marime] = $tinta;
            $laZi = $laZi && is_file($tinta) && (int) @filemtime($tinta) >= (int) @filemtime($sursa);
        }
        if ($laZi) {
            return 0;
        }
        // Poza s-a schimbat (sau e nouă): variantele vechi pleacă toate, apoi se fac din nou.
        self::sterge($rel);
        if ($deFacut === []) {
            return 0;
        }
        if (!self::memoriePentru($latime, $inaltime)) {
            return null;
        }
        $imagine = match ((int) $info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sursa),
            IMAGETYPE_PNG => @imagecreatefrompng($sursa),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sursa) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($sursa),
            default => false,
        };
        if ($imagine === false) {
            return null;
        }
        $scrise = 0;
        try {
            foreach ($deFacut as $marime => $tinta) {
                $inaltimeNoua = max(1, (int) round($inaltime * $marime / $latime));
                $mica = imagecreatetruecolor($marime, $inaltimeNoua);
                if ($mica === false) {
                    continue;
                }
                imagealphablending($mica, false);
                imagesavealpha($mica, true);
                imagefill($mica, 0, 0, (int) imagecolorallocatealpha($mica, 0, 0, 0, 127));
                imagecopyresampled($mica, $imagine, 0, 0, 0, 0, $marime, $inaltimeNoua, $latime, $inaltime);
                if (!is_dir(dirname($tinta))) {
                    @mkdir(dirname($tinta), 0775, true);
                }
                $temporar = $tinta . '.' . getmypid() . '.tmp';
                if (@imagewebp($mica, $temporar, self::CALITATE) && @rename($temporar, $tinta)) {
                    $scrise++;
                } else {
                    @unlink($temporar);
                }
                imagedestroy($mica);
            }
        } catch (Throwable) {
            // O poză pe care GD n-o poate scala rămâne fără variante; pagina o arată ca înainte.
        } finally {
            imagedestroy($imagine);
        }
        return $scrise;
    }

    /**
     * Atributele `srcset` și `sizes` pentru o poză din /uploads/ cu variante pe disc (cu comutatorul pornit); altfel ''.
     * Poza întreagă intră în listă cu lățimea ei, deci un ecran mare o primește tot pe ea.
     */
    public static function atribute(string $adresa, string $sizes = '(max-width: 600px) 50vw, 300px'): string
    {
        if (!self::activ() || !PozeOptimizate::activ()) {
            return '';
        }
        $rel = self::caleUploads($adresa);
        if ($rel === null) {
            return '';
        }
        $dim = ImageDimensions::pentru('/uploads/' . $rel);
        if ($dim === null) {
            return '';
        }
        $lista = [];
        foreach (self::MARIMI as $marime) {
            if ($marime < $dim[0] && is_file(Paths::uploads() . '/' . self::caleVarianta($rel, $marime))) {
                $lista[] = '/uploads/' . implode('/', array_map('rawurlencode', explode('/', self::caleVarianta($rel, $marime)))) . ' ' . $marime . 'w';
            }
        }
        if ($lista === []) {
            return '';
        }
        $lista[] = '/uploads/' . implode('/', array_map('rawurlencode', explode('/', $rel))) . ' ' . (int) $dim[0] . 'w';
        return ' srcset="' . htmlspecialchars(implode(', ', $lista), ENT_QUOTES) . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '"';
    }

    /**
     * O etichetă <img> întreagă: src, alt, width/height (ImageDimensions), srcset/sizes (variantele), loading, plus
     * atributele date (`class`, `decoding`…).
     *
     * @param array<string, string> $extra
     */
    public static function img(string $src, string $alt, string $sizes = '(max-width: 600px) 50vw, 300px', array $extra = []): string
    {
        $html = '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"';
        $dim = ImageDimensions::pentru($src);
        if ($dim !== null) {
            $html .= ' width="' . (int) $dim[0] . '" height="' . (int) $dim[1] . '"';
        }
        $html .= self::atribute($src, $sizes);
        $extra += ['loading' => 'lazy', 'decoding' => 'async'];
        foreach ($extra as $nume => $valoare) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', (string) $nume) === 1) {
                $html .= ' ' . $nume . '="' . htmlspecialchars((string) $valoare, ENT_QUOTES) . '"';
            }
        }
        return $html . '>';
    }

    /**
     * Toate pozele din dosarele date ale /uploads/ (implicit products, gallery, blog, demo), fără cele excluse, ca adrese
     * relative la /uploads/.
     *
     * @param list<string> $dosare
     * @return list<string>
     */
    public static function pozeDin(array $dosare = ['products', 'gallery', 'blog', 'demo']): array
    {
        $rez = [];
        foreach ($dosare as $dosar) {
            $dosar = trim($dosar, '/');
            $baza = Paths::uploads() . '/' . $dosar;
            if ($dosar === '' || str_contains($dosar, '..') || !is_dir($baza)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($baza, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fisier) {
                if (!$fisier->isFile() || $fisier->isLink()) {
                    continue;
                }
                $rel = $dosar . '/' . ltrim(substr($fisier->getPathname(), strlen($baza)), '/');
                if (self::caleUploads('/uploads/' . implode('/', array_map('rawurlencode', explode('/', $rel)))) !== null) {
                    $rez[] = $rel;
                }
            }
        }
        sort($rez);
        return $rez;
    }
}
