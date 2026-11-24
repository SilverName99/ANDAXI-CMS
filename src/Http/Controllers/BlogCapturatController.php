<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Listele de articole ale blogului unui site mutat de pe WordPress, dincolo de prima pagină (mutarea unui site WooCommerce, pista B;
 * docs/MAGAZIN-WC.md): `/blog/page/<n>/` și `/category/<slug>/page/<n>/`. Prima pagină a fiecărei liste e documentul ei capturat;
 * paginile următoare le randează pista B în același markup (regiunea `blog:lista`).
 *
 * Rutele (src/Http/rute.php) au condiția `magazin_wc` (Router::CONDITII): pe orice alt site nu se potrivesc deloc. Pista A le-a
 * înregistrat o dată; fiecare metodă răspunde 404 până când pista B e construită (PistaNeconstruita).
 */
final class BlogCapturatController
{
    use PistaNeconstruita;

    /** `GET /blog/page/{n}`: pagina n a listei articolelor (pista B). */
    public function paginaBlog(array $params): void
    {
        $this->paginaNeconstruita();
    }

    /** `GET /category/{slug*}/page/{n}`: pagina n a articolelor unei categorii (și a unei subcategorii; pista B). */
    public function paginaCategorie(array $params): void
    {
        $this->paginaNeconstruita();
    }
}
