<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;

/**
 * Resursele cerute de o pagină capturată, din analiza statică (pasul 4a; DECIZIE §2.3 #3): `<link href>`, `<script src>`,
 * `<img src|srcset>`, `<source src|srcset>`, `poster`, `url()` din `style` și din `<style>` (și, prin dinCss, din foi, cu
 * `@import`), adresele din JSON-ul atributelor `data-*` (`data-props`, `data-et-multi-view`, escapate și cu entități), atributele
 * de date cu o adresă (`data-src`, `data-mfp-src`, `data-placeholder-image`) și vectorul CSS-ului „late” (`var file = […]`).
 * Adresele se întorc absolute, rezolvate față de pagină (sau de foaie), cu entitățile decodate; fiecare cu felul ei (foaie,
 * script, imagine, font, media, document) și locul unde a fost găsită. Analiza dinamică (jurnalul de rețea al referinței,
 * `--jurnal=`) vine la pasul 4b.
 */
final class Resurse
{
    private const ATRIBUTE_ADRESA = ['data-src' => true, 'data-mfp-src' => true, 'data-placeholder-image' => true];

    /** @return list<array{url: string, fel: string, unde: string}> */
    public static function dinHtml(string $html, string $urlPagina): array
    {
        $rez = [];
        $adauga = static function (string $ref, string $unde, string $context = '') use (&$rez, $urlPagina): void {
            $url = self::rezolva($urlPagina, self::decodeaza($ref));
            if ($url !== null) {
                $rez[$url . "\0" . $unde] = ['url' => $url, 'fel' => self::fel($url, $context), 'unde' => $unde];
            }
        };
        $tokeni = Tokenizer::tokenizeaza($html);
        foreach ($tokeni as $k => $t) {
            if ($t->tip === Token::TEXT && $t->brut === 'style') {
                foreach (self::adreseCss($t->octeti($html)) as $u) {
                    $adauga($u, '<style>', 'css');
                }
                continue;
            }
            if ($t->tip === Token::TEXT && $t->brut === 'script') {
                // Vectorul CSS-ului „late” (DECIZIE §2.3 #3): `var file = ["…-late.css"]`.
                if (preg_match_all('/\bvar\s+file\s*=\s*(\[[^\]]*\])/', $t->octeti($html), $m) > 0) {
                    foreach ($m[1] as $lit) {
                        foreach ((array) json_decode($lit, true) as $u) {
                            if (is_string($u)) {
                                $adauga($u, 'script: var file', 'foaie');
                            }
                        }
                    }
                }
                continue;
            }
            if ($t->tip !== Token::DESCHIDERE) {
                continue;
            }
            $n = $t->nume;
            $v = static fn (string $a): ?string => $t->valoare($html, $a);
            if ($n === 'link' && ($h = $v('href')) !== null) {
                $rel = strtolower(trim((string) $v('rel')));
                if (preg_match('/\b(stylesheet|preload|icon|apple-touch-icon|modulepreload|prefetch)\b/', $rel) === 1) {
                    $as = strtolower((string) $v('as'));
                    $adauga($h, '<link rel=' . $rel . '>', str_contains($rel, 'stylesheet') || $as === 'style' ? 'foaie' : ($as === 'script' ? 'script' : ($as === 'font' ? 'font' : '')));
                }
            }
            if ($n === 'script' && ($s = $v('src')) !== null) {
                $adauga($s, '<script src>', 'script');
            }
            if (in_array($n, ['img', 'source', 'video', 'audio', 'track', 'input'], true)) {
                foreach (['src', 'poster'] as $a) {
                    if (($x = $v($a)) !== null && trim($x) !== '') {
                        $adauga($x, '<' . $n . ' ' . $a . '>', $n === 'img' || $a === 'poster' ? 'imagine' : ($n === 'source' ? '' : 'media'));
                    }
                }
                foreach (['srcset', 'imagesrcset'] as $a) {
                    if (($x = $v($a)) !== null) {
                        foreach (self::srcset(self::decodeaza($x)) as $u) {
                            $adauga($u, '<' . $n . ' ' . $a . '>', 'imagine');
                        }
                    }
                }
            }
            foreach ($t->atribute as $a) {
                if ($a->duplicat || !$a->areValoare()) {
                    continue;
                }
                $val = $a->valoare($html);
                if ($a->nume === 'style') {
                    foreach (self::adreseCss(self::decodeaza($val)) as $u) {
                        $adauga($u, '<' . $n . ' style>', 'imagine');
                    }
                } elseif (isset(self::ATRIBUTE_ADRESA[$a->nume]) && trim($val) !== '' && !str_starts_with(trim($val), '#')) {
                    $adauga($val, '<' . $n . ' ' . $a->nume . '>', 'imagine');
                } elseif (in_array($a->nume, ['data-props', 'data-et-multi-view'], true)) {
                    foreach (self::adreseJson(self::decodeaza($val)) as $u) {
                        $adauga($u, '<' . $n . ' ' . $a->nume . '>', 'imagine');
                    }
                }
            }
        }
        $lista = array_values($rez);
        usort($lista, static fn (array $a, array $b): int => [$a['url'], $a['unde']] <=> [$b['url'], $b['unde']]);
        return $lista;
    }

    /** @return list<array{url: string, fel: string, unde: string}> adresele dintr-o foaie: `url()` și `@import` */
    public static function dinCss(string $css, string $urlFoaie): array
    {
        $rez = [];
        foreach (self::adreseCss($css) as $u) {
            $url = self::rezolva($urlFoaie, $u);
            if ($url !== null) {
                $rez[$url] = ['url' => $url, 'fel' => self::fel($url, ''), 'unde' => 'css'];
            }
        }
        ksort($rez);
        return array_values($rez);
    }

    /**
     * @return list<string> adresele din CSS: `url(…)` (cu sau fără ghilimele; și în `src:` al unui `@font-face`), `@import "…"` și
     * șirurile din `image-set(…)` / `-webkit-image-set(…)`; comentariile CSS se scot întâi
     */
    public static function adreseCss(string $css): array
    {
        $rez = [];
        $css = (string) preg_replace('#/\*.*?(?:\*/|$)#s', '', $css);
        if (preg_match_all('/(?:-webkit-)?image-set\(((?:[^()]|\([^()]*\))*)\)/i', $css, $is) > 0) {
            foreach ($is[1] as $interior) {
                if (preg_match_all('/(?<![a-z(])\s*(?:"([^"]+)"|\'([^\']+)\')/i', $interior, $sir, PREG_SET_ORDER) > 0) {
                    foreach ($sir as $x) {
                        $u = trim(($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? ''));
                        if ($u !== '' && !str_starts_with(strtolower($u), 'data:')) {
                            $rez[] = $u;
                        }
                    }
                }
            }
        }
        if (preg_match_all('/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\'"\s]*))\s*\)/i', $css, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $s) {
                $u = trim($s[1] !== '' ? $s[1] : ($s[2] !== '' ? $s[2] : ($s[3] ?? '')));
                if ($u !== '' && !str_starts_with(strtolower($u), 'data:') && !str_starts_with($u, '#')) {
                    $rez[] = $u;
                }
            }
        }
        if (preg_match_all('/@import\s+(?:"([^"]+)"|\'([^\']+)\')/i', $css, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $s) {
                $rez[] = $s[1] !== '' ? $s[1] : $s[2];
            }
        }
        return array_values(array_unique($rez));
    }

    /** @return list<string> */
    public static function srcset(string $srcset): array
    {
        $rez = [];
        foreach (explode(',', $srcset) as $cand) {
            $u = preg_split('/\s+/', trim($cand))[0] ?? '';
            if ($u !== '' && !str_starts_with(strtolower($u), 'data:')) {
                $rez[] = $u;
            }
        }
        return $rez;
    }

    /** @return list<string> șirurile din JSON care arată ca adresele unor fișiere (au o extensie) sau ca un srcset */
    private static function adreseJson(string $json): array
    {
        $date = json_decode($json, true);
        $rez = [];
        $umbla = static function (mixed $x, string $cheie) use (&$umbla, &$rez): void {
            if (is_array($x)) {
                foreach ($x as $k => $y) {
                    $umbla($y, (string) $k);
                }
                return;
            }
            if (!is_string($x) || $x === '') {
                return;
            }
            if ($cheie === 'srcset') {
                foreach (self::srcset($x) as $u) {
                    $rez[] = $u;
                }
            } elseif (preg_match('#^(https?:)?//|^/#', $x) === 1 && preg_match('#\.[a-z0-9]{2,5}(\?|$)#i', $x) === 1) {
                $rez[] = $x;
            }
        };
        $umbla($date, '');
        return $rez;
    }

    /** Entitățile HTML și escapările JSON ale slash-ului, decodate (`&#038;` → `&`, `\/` → `/`). */
    public static function decodeaza(string $v): string
    {
        return str_replace('\\/', '/', html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Adresa absolută a lui `$ref` față de `$baza` (RFC 3986, fără normalizarea literelor), sau null (data:, javascript:, #). */
    public static function rezolva(string $baza, string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || str_starts_with($ref, '#')) {
            return null;
        }
        // Fragmentul nu face parte din resursă (`font.eot?#iefix`, `font.svg#nume`): fișierul e același.
        $ref = (string) preg_replace('/#.*$/s', '', $ref);
        if (str_ends_with($ref, '?')) {
            $ref = substr($ref, 0, -1);
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $ref, $m) === 1) {
            return in_array(strtolower($m[1]), ['http', 'https'], true) ? $ref : null;
        }
        $b = parse_url($baza);
        $schema = (string) ($b['scheme'] ?? 'https');
        $gazda = (string) ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) {
            return $schema . ':' . $ref;
        }
        $cale = (string) ($b['path'] ?? '/');
        if (str_starts_with($ref, '/')) {
            $nou = $ref;
        } elseif (str_starts_with($ref, '?')) {
            $nou = $cale . $ref;
        } else {
            $dir = substr($cale, 0, (int) strrpos($cale, '/') + 1);
            $nou = $dir . $ref;
        }
        // Segmentele „.” și „..” (doar în cale, nu în query).
        $q = '';
        if (($p = strpos($nou, '?')) !== false) {
            $q = substr($nou, $p);
            $nou = substr($nou, 0, $p);
        }
        $iesire = [];
        foreach (explode('/', $nou) as $i => $seg) {
            if ($seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if (count($iesire) > 1) {
                    array_pop($iesire);
                }
                continue;
            }
            $iesire[] = $seg;
        }
        $nou = implode('/', $iesire);
        if (str_ends_with($ref, '/.') || str_ends_with($ref, '/..')) {
            $nou .= '/';
        }
        return $schema . '://' . $gazda . ($nou === '' ? '/' : $nou) . $q;
    }

    /** Felul unei resurse, după context sau după extensie. */
    public static function fel(string $url, string $context): string
    {
        if ($context !== '') {
            return $context === 'css' ? self::fel($url, '') : $context;
        }
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return match (true) {
            $ext === 'css' => 'foaie',
            in_array($ext, ['js', 'mjs'], true) => 'script',
            in_array($ext, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true) => 'font',
            in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'cur', 'bmp'], true) => 'imagine',
            in_array($ext, ['mp4', 'webm', 'ogg', 'mp3', 'm4a', 'vtt'], true) => 'media',
            default => 'document',
        };
    }
}
