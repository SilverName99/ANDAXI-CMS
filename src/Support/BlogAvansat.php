<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Comutatorul blogului avansat și textele listei de articole (WP-22: B1, B2, B3, DB-13; decizia OD-13 luată ca
 * recomandarea, un singur comutator).
 *
 * `blog_avansat` (implicit 1; site-ul de la care a pornit codul comun îl are înghețat 0): cu el pornit, pe /blog apar căutarea (`?q=`) și categoriile
 * (chips-uri cu numărul de articole, `?categorie=`, `?eticheta=`), iar în articol cuprinsul automat (ancore pe titlurile
 * H2/H3), plus bibliografia, etichetele și articolele similare în șablonul implicit; secțiunea veche „Articole
 * interesante” a unui șablon (`data-blog-related-list`, umplută din JavaScript cu articole luate la întâmplare) se umple
 * de pe server cu articole înrudite. Cu el oprit, /blog și articolele arată ca înainte. Ce nu depinde de comutator
 * (nu schimbă nimic cât timp datele lipsesc): bibliografia și etichetele unui articol care le are, lățimea copertei
 * sub 100%, tokenurile noi scrise într-un șablon, adresele /blog/categorie/{slug} și /blog/eticheta/{slug}.
 *
 * Textele (planul de-brandizării, D1–D6): eticheta de deasupra titlului listei (gol = fără), titlul listei, descrierea de sub el
 * (gol = fără), autorul și categoria arătate când articolul n-are (autorul gol = numele site-ului, categoria goală =
 * nimic). Fiecare site vechi le păstrează pe ale lui prin înghețare.
 */
final class BlogAvansat
{
    /** @param array<string, mixed> $setari */
    public static function pornit(array $setari): bool
    {
        return trim((string) ($setari['blog_avansat'] ?? '1')) === '1';
    }

    /** @param array<string, mixed> $setari */
    public static function etichetaLista(array $setari): string
    {
        return trim((string) ($setari['blog_eticheta_lista'] ?? ''));
    }

    /** @param array<string, mixed> $setari */
    public static function titluLista(array $setari): string
    {
        $titlu = trim((string) ($setari['blog_titlu_lista'] ?? ''));
        return $titlu !== '' ? $titlu : 'Blog & Resurse';
    }

    /** @param array<string, mixed> $setari */
    public static function descriereLista(array $setari): string
    {
        return trim((string) ($setari['blog_descriere_lista'] ?? ''));
    }

    /**
     * Autorul arătat când articolul nu are câmpul (de exemplu în previzualizarea din admin): setarea, altfel numele
     * site-ului (App\Support\Brand).
     *
     * @param array<string, mixed> $setari
     */
    public static function autorImplicit(array $setari): string
    {
        $autor = trim((string) ($setari['blog_autor_implicit'] ?? ''));
        return $autor !== '' ? $autor : Brand::numeSite($setari);
    }

    /**
     * Inițiala din cercul autorului, când articolul n-are autor: prima literă a autorului implicit (setarea, altfel
     * numele site-ului), altfel „•”. Codul vechi scria „B” pe toate site-urile (inițiala
     * primului site).
     *
     * @param array<string, mixed> $setari
     */
    public static function initialaImplicita(array $setari): string
    {
        $autor = self::autorImplicit($setari);
        return $autor !== '' ? mb_substr($autor, 0, 1) : '•';
    }

    /** @param array<string, mixed> $setari */
    public static function categorieImplicita(array $setari): string
    {
        return trim((string) ($setari['blog_categorie_implicita'] ?? ''));
    }

    /**
     * Fișierele blogului avansat (stilurile cuprinsului, citărilor, bibliografiei, etichetelor și articolelor similare;
     * derularea lină la ancore), puse la sfârșitul unui articol. Controller-ul le cere doar pentru un articol care chiar
     * folosește una dintre ele, ca un articol fără ele (și orice articol cu comutatorul oprit și fără date noi) să rămână
     * octet cu octet cel de dinainte.
     */
    public static function fisiere(): string
    {
        $versiune = static fn (string $cale): string => (string) (@filemtime(Paths::public($cale)) ?: time());
        return '<link rel="stylesheet" href="/assets/css/blog-avansat.css?v=' . $versiune('assets/css/blog-avansat.css') . '">'
            . "\n" . '<script src="/assets/js/blog-anchors.js?v=' . $versiune('assets/js/blog-anchors.js') . '" defer></script>';
    }
}
