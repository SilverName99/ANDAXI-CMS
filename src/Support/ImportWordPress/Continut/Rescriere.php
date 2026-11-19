<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

use App\Support\ImportWordPress\Context;

/**
 * Rescrierea adreselor dintr-un HTML sau CSS venit din WordPress, fără să-l reformateze (expresii pe atribute, nu un
 * DOM, ca markup-ul să rămână octet cu octet altfel): `href`, `src`, `srcset`, `poster`, `action`, `data-*` cu adrese și
 * `url(…)` din `style` și din foi. Fiecare fișier din wp-content/uploads pomenit se spune contextului (se copiază), iar
 * adresa lui rămâne neatinsă (aceeași cale pe site-ul nou, corectura 1.1); paginile site-ului devin căile lor noi
 * (Adrese::rescrie).
 */
final class Rescriere
{
    /** Atributele cu o singură adresă. */
    private const ATRIBUTE = ['href', 'src', 'poster', 'action', 'data-src', 'data-href', 'data-url', 'data-image', 'data-bg', 'data-background',
        'data-lazy-src', 'data-full', 'data-large_image', 'data-thumb', 'data-mobile-src', 'data-src-cmplz'];
    /** Atributele cu liste de adrese (srcset). */
    private const LISTE = ['srcset', 'data-srcset', 'data-lazy-srcset'];

    public static function html(string $html, Context $ctx): string
    {
        $atribute = implode('|', array_map(static fn (string $a): string => preg_quote($a, '/'), self::ATRIBUTE));
        $html = (string) preg_replace_callback('/(\s(?:' . $atribute . ')\s*=\s*)(["\'])(.*?)\2/is', static function (array $m) use ($ctx): string {
            return $m[1] . $m[2] . self::adresa($m[3], $ctx, $m[2]) . $m[2];
        }, $html);
        $liste = implode('|', array_map(static fn (string $a): string => preg_quote($a, '/'), self::LISTE));
        $html = (string) preg_replace_callback('/(\s(?:' . $liste . ')\s*=\s*)(["\'])(.*?)\2/is', static function (array $m) use ($ctx): string {
            $parti = [];
            foreach (explode(',', $m[3]) as $parte) {
                $parte = trim($parte);
                if ($parte === '') {
                    continue;
                }
                $bucati = preg_split('/\s+/', $parte, 2) ?: [$parte];
                $parti[] = self::adresa($bucati[0], $ctx, $m[2]) . (isset($bucati[1]) ? ' ' . $bucati[1] : '');
            }
            return $m[1] . $m[2] . implode(', ', $parti) . $m[2];
        }, $html);
        // style="… url(…) …"
        $html = (string) preg_replace_callback('/(\sstyle\s*=\s*)(["\'])(.*?)\2/is', static function (array $m) use ($ctx): string {
            return $m[1] . $m[2] . self::css($m[3], $ctx, $m[2]) . $m[2];
        }, $html);
        // <style>…</style> din conținut
        return (string) preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', static function (array $m) use ($ctx): string {
            return $m[1] . self::css($m[2], $ctx) . $m[3];
        }, $html);
    }

    /** Adresele din url(…) ale unei foi de stil (sau ale unui atribut style). */
    public static function css(string $css, Context $ctx, string $ghilimea = ''): string
    {
        return (string) preg_replace_callback('/url\(\s*(&quot;|["\']?)(.*?)\1\s*\)/i', static function (array $m) use ($ctx, $ghilimea): string {
            $adresa = self::adresa($m[2], $ctx, $ghilimea);
            return 'url(' . $m[1] . $adresa . $m[1] . ')';
        }, $css);
    }

    /** O adresă: fișierul din uploads se ține minte, apoi adresa nouă (cu entitățile HTML păstrate în atribut). */
    public static function adresa(string $adresa, Context $ctx, string $ghilimea = '"'): string
    {
        $decodata = html_entity_decode($adresa, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$ctx->adrese->eASite($decodata)) {
            return $adresa;
        }
        $rel = $ctx->adrese->fisierUploads($decodata);
        if ($rel !== null) {
            $ctx->fisier($rel);
        }
        $noua = $ctx->adrese->rescrie($decodata);
        if ($noua === $decodata) {
            return $adresa;
        }
        if ($ghilimea === '') {
            return $noua; // într-o foaie de stil: textul simplu
        }
        // Înapoi în atribut: & ca &amp; (cum era în HTML), ghilimelele atributului protejate.
        $noua = str_replace('&', '&amp;', $noua);
        return $ghilimea === "'" ? str_replace("'", '&#039;', $noua) : str_replace('"', '&quot;', $noua);
    }
}
