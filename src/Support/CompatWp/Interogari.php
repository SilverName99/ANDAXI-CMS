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
 *    `/index.php`, o mută stratul de adrese WordPress înainte);
 *  - cu pluginul proprietar oprit, cererea primește 404-ul site-ului (pagina, sau JSON-ul gărzii la `wc-ajax`), în afara cheilor
 *    mereu deschise (MEREU: notificarea Stripe veche și adresele MailPoet din emailurile trimise, dezabonarea înainte de toate,
 *    RFC 8058), care ajung la proprietar în orice stare a pluginului, ca adresele mereu deschise ale rutelor;
 *  - mai multe chei în aceeași cerere: câștigă prima din CHEI (ordinea de mai jos);
 *  - `wc-ajax`: 404 în JSON (endpointurile AJAX ale WooCommerce nu se portează; scripturile noastre au `/api/wc/*`).
 *
 * Proprietarii sunt controllerele pistelor (MagazinWcController, DescarcariController: o metodă a unei instanțe, fără argumente)
 * și routerul MailPoet al pistei E (`App\Support\MailPoetCompat\Router::raspunde(string $cheie)`, statică; clasa o scrie pista E);
 * până la pista lor răspund 404.
 */
final class Interogari
{
    /**
     * Cheile, în ordinea în care se încearcă: cheia → [grupul din wp-compat.json, pluginul proprietar (null = niciunul),
     * mereu deschisă, proprietarul ([clasă, metodă, „instanta” | „static”]) sau null pentru 404 în JSON].
     *
     * @var array<string, array{0: string, 1: ?string, 2: bool, 3: ?array{0: string, 1: string, 2: string}}>
     */
    public const CHEI = [
        'wc-api' => ['magazin_wc', 'plata_stripe', true, [MagazinWcController::class, 'wcApi', 'instanta']],
        'wc-ajax' => ['magazin_wc', null, false, null],
        'download_file' => ['magazin_wc', 'descarcari', false, [DescarcariController::class, 'descarca', 'instanta']],
        'mailpoet_router' => ['mailpoet', 'newsletter', true, ['App\\Support\\MailPoetCompat\\Router', 'raspunde', 'static']],
        'mailpoet_page' => ['mailpoet', 'newsletter', true, ['App\\Support\\MailPoetCompat\\Router', 'raspunde', 'static']],
        'add-to-cart' => ['magazin_wc', 'magazin', false, [MagazinWcController::class, 'adaugaDinAdresa', 'instanta']],
    ];

    /**
     * Cheia cererii pe care o servește dispecerul, sau null (trece mai departe): calea `/`, o cheie din CHEI în query, cu grupul ei
     * pornit în `wp-compat.json`. Fără efecte: doar citește.
     *
     * @param array<string, mixed> $get
     */
    public static function cheie(string $uri, array $get): ?string
    {
        if (!WpCompat::magazinWc() && !WpCompat::mailpoet()) {
            return null;
        }
        $cale = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        if ($cale !== '/') {
            return null;
        }
        foreach (self::CHEI as $cheie => [$grup]) {
            if (!array_key_exists($cheie, $get)) {
                continue;
            }
            if (($grup === 'magazin_wc' && WpCompat::magazinWc()) || ($grup === 'mailpoet' && WpCompat::mailpoet())) {
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
            // Pista proprietarului nu e încă construită: ca o adresă care nu există.
            if ($cheie === 'wc-api') {
                self::json404();
            } else {
                (new SiteController())->paginaNegasita();
            }
            return true;
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
