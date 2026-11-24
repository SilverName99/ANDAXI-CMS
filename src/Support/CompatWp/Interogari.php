<?php

declare(strict_types=1);

namespace App\Support\CompatWp;

use App\Http\Controllers\DescarcariController;
use App\Http\Controllers\MagazinWcController;
use App\Http\Controllers\SiteController;
use App\Support\Plugins;
use App\Support\WpCompat;

/**
 * Cheile WordPress de pe prima pagină (`/?add-to-cart=…`, `/?download_file=…`, `/?wc-api=…`, `/?wc-ajax=…`, `/?mailpoet_router…`,
 * `/?mailpoet_page=…`) ale unui site mutat de pe WordPress (pista A a mutării unui site WooCommerce; docs/MAGAZIN-WC.md): un
 * singur dispecer, chemat o dată din `public/index.php`, după mentenanță și înaintea cache-ului de pagini, care dă cererea
 * proprietarului cheii. Lista e închisă (CHEI); o cheie care nu e aici trece mai departe, neatinsă.
 *
 *  - fiecare cheie e a unui grup din `wp-compat.json`: `magazin_wc` (cheile WooCommerce) sau `mailpoet` (cheile MailPoet). Fără
 *    grupul ei în pachetul site-ului, cheia trece mai departe (toate site-urile de azi: nicio cerere nu se schimbă);
 *  - doar pe calea `/` (WordPress le citește oriunde, dar linkurile lor sunt pe prima pagină; o cale cu „/” în plus, ca
 *    `/index.php`, o mută stratul de adrese WordPress înainte), în afara lui `add-to-cart` (ORIUNDE): WooCommerce îl citește pe
 *    orice pagină, deci și dispecerul, pe orice cale, doar cu un id numeric (altfel WooCommerce nu adaugă nimic și cererea trece
 *    mai departe neatinsă). Proprietarul lui primește calea cererii și spune dacă a răspuns: după o adăugare reușită 302 la
 *    `/cos/`; refuzată, false, iar pagina adresei cerute se randează (200), cu notificarea pusă în coadă, ca pe live;
 *  - cu pluginul proprietar oprit, cererea primește 404-ul site-ului (pagina, sau JSON-ul gărzii la `wc-ajax`), în afara cheilor
 *    mereu deschise (MEREU: notificarea Stripe veche și adresele MailPoet din emailurile trimise, dezabonarea înainte de toate,
 *    RFC 8058), care ajung la proprietar în orice stare a pluginului, ca adresele mereu deschise ale rutelor;
 *  - mai multe chei în aceeași cerere: câștigă prima din CHEI (ordinea de mai jos);
 *  - `wc-ajax`: 404 în JSON (endpointurile AJAX ale WooCommerce nu se portează; scripturile noastre au `/api/wc/*`).
 *
 * Proprietarii sunt controllerele pistelor (MagazinWcController, DescarcariController: o metodă a unei instanțe, fără argumente,
 * sau, la `add-to-cart`, cu calea cererii și răspunsul bool) și routerul MailPoet al pistei E
 * (`App\Support\MailPoetCompat\Router::raspunde(string $cheie)`, statică; clasa o scrie pista E); până la pista lor răspund 404
 * (`add-to-cart`: false, deci pagina cerută, fără nimic adăugat).
 */
final class Interogari
{
    /**
     * Cheile, în ordinea în care se încearcă: cheia → [grupul din wp-compat.json, pluginul proprietar (null = niciunul),
     * mereu deschisă, proprietarul ([clasă, metodă, „instanta” | „static” | „cale”]) sau null pentru 404 în JSON]. „cale”: o metodă
     * a unei instanțe care primește calea cererii și întoarce true dacă a răspuns (false: cererea merge mai departe).
     *
     * @var array<string, array{0: string, 1: ?string, 2: bool, 3: ?array{0: string, 1: string, 2: string}}>
     */
    public const CHEI = [
        'wc-api' => ['magazin_wc', 'plata_stripe', true, [MagazinWcController::class, 'wcApi', 'instanta']],
        'wc-ajax' => ['magazin_wc', null, false, null],
        'download_file' => ['magazin_wc', 'descarcari', false, [DescarcariController::class, 'descarca', 'instanta']],
        'mailpoet_router' => ['mailpoet', 'newsletter', true, ['App\\Support\\MailPoetCompat\\Router', 'raspunde', 'static']],
        'mailpoet_page' => ['mailpoet', 'newsletter', true, ['App\\Support\\MailPoetCompat\\Router', 'raspunde', 'static']],
        'add-to-cart' => ['magazin_wc', 'magazin', false, [MagazinWcController::class, 'adaugaDinAdresa', 'cale']],
    ];

    /** Cheile citite pe orice cale, nu doar pe `/` (cu un id numeric: 1–20 de cifre, nu doar zerouri). */
    public const ORIUNDE = ['add-to-cart'];

    /**
     * Cheia cererii pe care o servește dispecerul, sau null (trece mai departe): o cheie din CHEI în query, cu grupul ei pornit în
     * `wp-compat.json`, pe calea `/` (ORIUNDE: pe orice cale, cu un id numeric). O adresă din care parse_url nu scoate o cale (N6,
     * docs/SECURITATE.md) nu are nicio cheie. Fără efecte: doar citește.
     *
     * @param array<string, mixed> $get
     */
    public static function cheie(string $uri, array $get): ?string
    {
        if (!WpCompat::magazinWc() && !WpCompat::mailpoet()) {
            return null;
        }
        $cale = \App\Http\Router::caleCerere($uri);
        if ($cale === null) {
            return null;
        }
        foreach (self::CHEI as $cheie => [$grup]) {
            if (!array_key_exists($cheie, $get)) {
                continue;
            }
            if (!(($grup === 'magazin_wc' && WpCompat::magazinWc()) || ($grup === 'mailpoet' && WpCompat::mailpoet()))) {
                continue;
            }
            if (in_array($cheie, self::ORIUNDE, true)) {
                if (is_string($get[$cheie]) && preg_match('/^[0-9]{1,20}$/', $get[$cheie]) === 1 && ltrim($get[$cheie], '0') !== '') {
                    return $cheie;
                }
                continue;
            }
            if ($cale === '/') {
                return $cheie;
            }
        }
        return null;
    }

    /**
     * Dispecerul (public/index.php): true dacă a răspuns el. Fără grupurile din `wp-compat.json` sau fără o cheie a lor pe `/`,
     * false și nimic trimis.
     */
    public static function intercepteaza(string $uri): bool
    {
        $cheie = self::cheie($uri, $_GET);
        if ($cheie === null) {
            return false;
        }
        [, $plugin, $mereu, $proprietar] = self::CHEI[$cheie];
        if ($proprietar === null) {
            self::json404();
            return true;
        }
        if ($plugin !== null && !$mereu && !Plugins::vizibil($plugin)) {
            (new SiteController())->paginaNegasita();
            return true;
        }
        if ($plugin !== null && $mereu && !Plugins::vizibil($plugin)) {
            Plugins::jurnalOdataPeZi('mereu-deschisa|' . $plugin . '|?' . $cheie, 'Cerere ?' . $cheie . ' primită și procesată, deși pluginul '
                . $plugin . ' e oprit (azi nu se mai notează altele la aceeași cheie).');
        }
        [$clasa, $metoda, $fel] = $proprietar;
        if (!class_exists($clasa) || !method_exists($clasa, $metoda)) {
            // Pista proprietarului nu e încă construită: ca o adresă care nu există (add-to-cart: pagina cerută, nimic adăugat).
            if ($fel === 'cale') {
                return false;
            }
            if ($cheie === 'wc-api') {
                self::json404();
            } else {
                (new SiteController())->paginaNegasita();
            }
            return true;
        }
        if ($fel === 'cale') {
            // add-to-cart: proprietarul spune dacă a răspuns; altfel pagina adresei cerute, ca pe live (adăugarea refuzată).
            return (new $clasa())->{$metoda}((string) \App\Http\Router::caleCerere($uri)) === true;
        }
        if ($fel === 'static') {
            $clasa::$metoda($cheie);
        } else {
            (new $clasa())->{$metoda}();
        }
        return true;
    }

    /** 404-ul scurt în JSON (ca garda pluginurilor pe /api/). */
    private static function json404(): void
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(['ok' => false, 'message' => 'Pagina nu a fost găsită.', 'error' => 'Pagina nu a fost găsită.'], JSON_UNESCAPED_UNICODE);
    }
}
