<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Magazinul în forma WooCommerce (mutarea unui site WooCommerce pe drumul capturat; docs/MAGAZIN-WC.md): pagina produsului și
 * adăugarea în coș fără JavaScript, coșul, checkout-ul, comanda primită și plata unei comenzi, cererile `/api/wc/*` ale
 * scripturilor din pagină și cheile WooCommerce de pe `/` (`?add-to-cart=`, `?wc-api=`; CompatWp\Interogari).
 *
 * Rutele lui (src/Http/rute.php) sunt condiționate de `wp-compat.json` → `magazin_wc` (Router::CONDITII): pe orice alt site nu
 * se potrivesc deloc. Pista A le-a înregistrat o dată, cu proprietarii lor; fiecare metodă e a pistei scrise în dreptul ei și
 * răspunde 404 până când pista e construită (PistaNeconstruita).
 */
final class MagazinWcController
{
    use PistaNeconstruita;

    /** `GET /product/{slug}`: pagina produsului din documentul ei capturat (pista G). */
    public function produs(array $params): void
    {
        $this->paginaNeconstruita();
    }

    /**
     * `POST /product/{slug}`: adăugarea în coș fără JavaScript (pista G). Ca WooCommerce (docs/MAGAZIN-WC.md, „Cheile”): după o
     * adăugare reușită, 302 la `/cos/`; refuzată (stoc, dată, variantă), 200 la adresa cerută, cu notificarea pusă în coadă.
     */
    public function produsAdauga(array $params): void
    {
        $this->paginaNeconstruita();
    }

    /** `GET /cos`: coșul în documentul capturat, cu regiunile lui (pista G). */
    public function cos(): void
    {
        $this->paginaNeconstruita();
    }

    /** `POST /cos`: actualizarea coșului și cuponul fără JavaScript (pista G). */
    public function cosTrimis(): void
    {
        $this->paginaNeconstruita();
    }

    /** `GET /checkout`: checkout-ul în documentul capturat (pista L). */
    public function checkout(): void
    {
        $this->paginaNeconstruita();
    }

    /** `GET /checkout/order-received/{comanda}`: comanda primită, după cheia comenzii (pista L). */
    public function comandaPrimita(array $params): void
    {
        $this->paginaNeconstruita();
    }

    /** `GET /checkout/order-pay/{comanda}`: plata unei comenzi (pista L). */
    public function comandaPlata(array $params): void
    {
        $this->paginaNeconstruita();
    }

    /** `GET /api/wc/jeton`: jetonul CSRF al cererilor scripturilor din paginile capturate (pista G). */
    public function jeton(): void
    {
        $this->jsonNeconstruit();
    }

    /** `GET /api/wc/cos-plutitor`: coșul plutitor (pista G). */
    public function cosPlutitor(): void
    {
        $this->jsonNeconstruit();
    }

    /** `POST /api/wc/cos/{actiune}`: adăugarea, actualizarea, ștergerea, cuponul (pista G). */
    public function cosApi(array $params): void
    {
        $this->jsonNeconstruit();
    }

    /** `POST /api/wc/checkout`: plasarea comenzii (pista L). */
    public function checkoutApi(): void
    {
        $this->jsonNeconstruit();
    }

    /** `POST /api/wc/stripe/{actiune}`: sesiunea Stripe Checkout, metodele de plată salvate (pista H). */
    public function stripeApi(array $params): void
    {
        $this->jsonNeconstruit();
    }

    /**
     * `?add-to-cart=<id>` pe orice adresă (CompatWp\Interogari; WooCommerce o citește pe orice pagină): adăugarea din adresă, pe
     * calea cerută (pista G). Întoarce true dacă a răspuns (după o adăugare reușită: 302 la `/cos/`, cu antetele nocache ale
     * WooCommerce) sau false ca cererea să meargă mai departe (adăugarea refuzată: pagina adresei cerute, 200, cu notificarea pusă în
     * coadă). Până la pista G: false, deci pagina se randează ca înainte, fără nimic adăugat.
     */
    public function adaugaDinAdresa(string $cale): bool
    {
        return false;
    }

    /** `POST /api/wc/stripe/metode/{actiune*}`: metodele de plată salvate: `sterge`, `implicita`, `adauga`, `adauga/confirma` (pista H). */
    public function stripeMetode(array $params): void
    {
        $this->jsonNeconstruit();
    }

    /**
     * `POST /admin/orders/{id}/rambursare-stripe`: rambursarea unei plăți Stripe din fișa comenzii (pista H: adminul logat, garda
     * originii, suma cel mult cât s-a plătit minus ce s-a rambursat).
     */
    public function rambursareStripe(array $params): void
    {
        $this->jsonNeconstruit();
    }

    /**
     * `GET /_dev/stripe-js-fals.js`: Stripe.js-ul fals al testelor (tools/dev/lib/stripe-js-fals.js, scris de pista H), doar cu
     * `APP_ENV=local`. Pe orice alt mediu, sau fără fișier, 404-ul site-ului (un deploy nu are oricum tools/).
     */
    public function stripeJsFals(): void
    {
        $fisier = dirname(__DIR__, 3) . '/tools/dev/lib/stripe-js-fals.js';
        if ((string) \App\Support\Env::get('APP_ENV', '') !== 'local' || !is_file($fisier)) {
            $this->paginaNeconstruita();
            return;
        }
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($fisier);
    }

    /** `/?wc-api=wc_stripe` (CompatWp\Interogari): webhook-ul Stripe vechi, în fereastra comutării (pista H). */
    public function wcApi(): void
    {
        $this->jsonNeconstruit();
    }
}
