<?php

declare(strict_types=1);

namespace App\Support\MagazinWc;

use App\Support\WpCompat;

/**
 * Comutatorul magazinului în forma WooCommerce (mutarea unui site WooCommerce pe drumul capturat, pista A): pornit doar de
 * `wp-compat.json` → `"magazin_wc": true` din pachetul site-ului (WpCompat::magazinWc). Fără cheie (toate site-urile de azi)
 * e oprit, și atunci nimic din ce ține de el nu se vede: rutele WooCommerce (`/product/…`, `/my-account/…`,
 * `/checkout/order-received/…`, `/api/wc/…`) nu se potrivesc deloc în router (Router::CONDITII), cheile WooCommerce de pe
 * `/` (CompatWp\Interogari) trec mai departe, iar CSP-ul nu primește sursele Stripe (CspSite::extensie).
 *
 * Doar comutatorul; ce face magazinul vine în pistele lui (docs/MAGAZIN-WC.md).
 */
final class Mod
{
    public static function activ(): bool
    {
        return WpCompat::magazinWc();
    }
}
