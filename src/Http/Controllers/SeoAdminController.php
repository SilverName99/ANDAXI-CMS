<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Auth;
use App\Support\Seo;

/**
 * Verificările panoului SEO din admin (WP-37, B1): `GET …/seo-verifica?tip=&ref=&titlu=&descriere=` → JSON cu
 * avertismentele (Seo::verificari: lipsă, prea lung, același titlu sau aceeași descriere pe altă pagină). Doar citire.
 *
 * Aceeași metodă stă la trei adrese, ca fiecare rol să o ceară sub paginile pe care le are deja (Auth::canAccessPath,
 * fără drepturi noi): `/admin/products/seo-verifica` (produse, categorii, mărci, etichete, lista produselor; rolul
 * magazinului), `/admin/blog/posts/seo-verifica` (articolele; rolurile blogului și emailurilor) și `/admin/seo/verifica`
 * (restul; administratorul general). Fiecare răspunde doar pentru tipurile paginilor ei.
 */
final class SeoAdminController
{
    use Comun;

    /** Tipurile la care răspunde fiecare adresă. */
    private const TIPURI_PE_ADRESA = [
        '/admin/products/seo-verifica' => ['product', 'category', 'brand', 'tag', 'listing_default'],
        '/admin/blog/posts/seo-verifica' => ['blog_post'],
        '/admin/seo/verifica' => ['custom_page', 'blog_category', 'blog_tag', 'product', 'category', 'brand', 'tag', 'listing_default', 'blog_post'],
    ];

    public function verifica(): void
    {
        header('Cache-Control: no-store');
        if (!Auth::check()) {
            $this->jsonResponse(['ok' => false, 'eroare' => 'neautentificat'], 401);
            return;
        }
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        if (!Auth::canAccessPath($cale)) {
            $this->jsonResponse(['ok' => false, 'eroare' => 'fara_drept'], 403);
            return;
        }
        $tip = Seo::tipValid(is_scalar($_GET['tip'] ?? null) ? (string) $_GET['tip'] : '');
        if ($tip === '' || !in_array($tip, self::TIPURI_PE_ADRESA[$cale] ?? [], true)) {
            $this->jsonResponse(['ok' => false, 'eroare' => 'tip'], 400);
            return;
        }
        $text = static fn (string $cheie, int $max): string => mb_substr(is_scalar($_GET[$cheie] ?? null) ? (string) $_GET[$cheie] : '', 0, $max);
        $this->jsonResponse([
            'ok' => true,
            // Duplicatele doar dintre paginile pe care adminul de acum le poate edita (Auth, după adresa lor din admin).
            'verificari' => Seo::verificari($this->db(), $tip, $text('ref', 190), $text('titlu', 500), $text('descriere', 2000),
                static fn (string $link): bool => Auth::canAccessPath($link)),
        ]);
    }
}
