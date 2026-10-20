<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Livrarea și returul, așa cum le spunem lui Google: în datele structurate ale
 * produsului (Offer.shippingDetails, Offer.hasMerchantReturnPolicy), la firmă
 * (Organization.hasMerchantReturnPolicy) și în fluxul pentru Merchant Center
 * (/feed/google-merchant.xml). Un singur loc, ca pagina și fluxul să nu se
 * contrazică.
 *
 * Condițiile sunt ale fiecărui site și le confirmă proprietarul lui, deci stau
 * în datele site-ului (seo.json → livrare_retur, vezi docs/SEO.md), nu în nucleu.
 * Un site fără ele nu declară nimic (ca înainte de livrarea 2 SEO a Bioscem):
 *
 *   "livrare_retur": {
 *     "serviciu": "FAN Courier",          // numele livrării la adresă în flux, ASCII; gol = fără g:service
 *     "termen_livrare": null,             // [pregătire min, max, transport min, max], zile lucrătoare; null = nehotărât
 *     "retur": {"zile": 14, "pagina": "/termeni-conditii", "taxe": "client"}   // taxe: client | gratuit
 *   }
 *
 * Livrarea vine din Setări livrare → prețuri fixe, adică exact din ce încasează
 * checkout-ul. Spunem DOAR tariful livrării la adresă. Tariful la punctele de
 * ridicare (FANbox) nu intră: Merchant Center cere „tariful livrării direct la
 * client, nu alte tarife, de exemplu ridicarea din magazin”, iar pentru România
 * punctele de ridicare (lockere ca FANbox) se declară doar în cont, nu pe produs.
 * Cu ambele tarife, Google ar fi afișat la toate produsele tariful cel mai mic.
 * Fără prețuri fixe active nu spunem nimic (tariful curierului se calculează
 * abia la comandă).
 *
 * Transportul gratuit (pragul din setări): Google nu primește o condiție de felul
 * „doar pentru comenzile fără reduceri”, așa că NU declarăm un prag. Îl spunem
 * doar pe produs, unde e sigur: un produs la preț întreg care singur atinge
 * pragul se livrează gratuit. Unde regula nu e sigură, nu declarăm nimic:
 *  - produs la reducere care atinge pragul (la Bioscem termenii spun „plătește
 *    transportul”, iar coșul dă gratuit) → tăcem;
 *  - pragurile pentru București și provincie diferite, iar prețul e între ele
 *    → depinde de județ, iar Google nu primește regiuni pentru România → tăcem.
 *
 * Taxa pentru kilometri suplimentari (localitățile din afara ariei curierului,
 * plătită și la comenzile cu transport gratuit) NU intră. Regula Merchant Center
 * e „costul declarat egal sau mai mare decât cel plătit”, deci pentru acele
 * localități suntem sub cost: e o decizie a proprietarului site-ului (fie cazul cel
 * mai scump, fie așa, fie tarife pe coduri poștale în Merchant Center).
 *
 * Termenul de livrare (deliveryTime / handling_time / transit_time) apare doar
 * când e completat în termen_livrare. Google îl trece la „necesar” pentru
 * afișarea livrării în rezultate (documentația din 8 sep 2026).
 *
 * Returul: din pagina de condiții a site-ului (la Bioscem /termeni-conditii: 14
 * zile de la primire, fără motiv, clientul plătind doar transportul înapoi →
 * ReturnFeesCustomerResponsibility).
 *
 * Oprire de urgență: SEO_LIVRARE_RETUR=0 în .env scoate livrarea și returul din
 * datele structurate și livrarea din flux (restul rămâne ca în livrarea 1).
 *
 * Din Bioscem `dfcf6bd` și `1e46f9f` (livrarea 2 SEO, A5), cu condițiile mutate
 * din constante în datele site-ului.
 */
final class PoliticaLivrare
{
    /** Livrarea și returul apar în datele pentru Google? (oprire: SEO_LIVRARE_RETUR=0; site fără condiții confirmate: nu) */
    public static function activ(): bool
    {
        $valoare = strtolower(trim((string) Env::get('SEO_LIVRARE_RETUR', '1')));
        if (in_array($valoare, ['0', 'false', 'off', 'nu'], true)) {
            return false;
        }
        $conditii = DateSite::seo('livrare_retur', null);
        return is_array($conditii) && $conditii !== [];
    }

    /**
     * Termenul de livrare, în zile lucrătoare: [pregătire min, max, transport
     * min, max], sau null până îl hotărăște proprietarul site-ului.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    public static function termenLivrare(): ?array
    {
        if (!self::activ()) {
            return null;
        }
        $termen = DateSite::seo('livrare_retur.termen_livrare', null);
        if (!is_array($termen) || count($termen) !== 4) {
            return null;
        }
        $zile = [];
        foreach (array_values($termen) as $valoare) {
            if (!is_int($valoare) && !(is_string($valoare) && ctype_digit($valoare))) {
                return null;
            }
            $zile[] = max(0, (int) $valoare);
        }
        if ($zile[0] > $zile[1] || $zile[2] > $zile[3]) {
            return null;
        }
        return [$zile[0], $zile[1], $zile[2], $zile[3]];
    }

    /** Numele livrării la adresă din flux (Merchant Center îl cere ASCII); gol = fără nume. */
    public static function serviciuAdresa(): string
    {
        $serviciu = trim((string) DateSite::seo('livrare_retur.serviciu', ''));
        return preg_match('/^[\x20-\x7e]{1,100}$/', $serviciu) === 1 ? $serviciu : '';
    }

    /**
     * Livrarea pentru un produs comandat singur, la prețul afișat: o singură
     * opțiune, livrarea la adresă (vezi sus de ce nu și punctele de ridicare).
     * Listă goală când nu putem spune sigur cât costă transportul.
     *
     * @param float $pretAfisat prețul din pagină (cu reducerea, dacă e)
     * @param bool  $redus      produsul e la reducere (preț redus, perioadă de reducere)
     * @return list<array{serviciu: string, pret: float}>
     */
    public static function optiuni(array $setari, float $pretAfisat, bool $redus): array
    {
        if (!self::activ() || !ShippingPricing::esteActiv($setari)) {
            return [];
        }
        $adresa = ShippingPricing::pretDeBaza($setari, false);
        if ($adresa === null || $adresa <= 0.0) {
            // Un preț fix de 0 lei e mai degrabă o setare necompletată decât o promisiune.
            return [];
        }

        // Pragul de gratuitate e pe județ (București / provincie), ca în coș; un
        // prag 0 înseamnă „niciodată gratuit” acolo.
        $atinge = static fn (float $prag): bool => $prag > 0.0 && $pretAfisat + 0.00001 >= $prag;
        $pragBucuresti = (float) ($setari['shipping_free_bucharest'] ?? 0);
        $pragProvincie = (float) ($setari['shipping_free_province'] ?? 0);
        $gratuitUndeva = $atinge($pragBucuresti) || $atinge($pragProvincie);
        $gratuitPeste = $atinge($pragBucuresti) && $atinge($pragProvincie);
        if ($gratuitUndeva && ($redus || !$gratuitPeste)) {
            // La reducere: regula gratuității nu e sigură. Doar în unele județe:
            // Google nu primește regiuni pentru România. Tăcem.
            return [];
        }

        return [['serviciu' => self::serviciuAdresa(), 'pret' => $gratuitPeste ? 0.0 : round($adresa, 2)]];
    }

    /**
     * Politica de retur confirmată a site-ului: zilele (de la primire), pagina în
     * care e scrisă (o cale din site) și cine plătește transportul înapoi
     * („client” sau „gratuit”). Gol dacă lipsește sau e incompletă.
     *
     * @return array{}|array{zile: int, pagina: string, taxe: string}
     */
    public static function retur(): array
    {
        if (!self::activ()) {
            return [];
        }
        $retur = DateSite::seo('livrare_retur.retur', null);
        if (!is_array($retur)) {
            return [];
        }
        $zile = $retur['zile'] ?? null;
        $pagina = trim((string) ($retur['pagina'] ?? ''));
        $taxe = trim((string) ($retur['taxe'] ?? 'client'));
        if (!is_int($zile) || $zile < 1 || $zile > 365
            || $pagina === '' || $pagina[0] !== '/' || str_starts_with($pagina, '//')
            || !in_array($taxe, ['client', 'gratuit'], true)) {
            return [];
        }
        return ['zile' => $zile, 'pagina' => $pagina, 'taxe' => $taxe];
    }

    /** Linkul absolut spre condițiile de retur (gol fără politică de retur). */
    public static function linkRetur(string $baseUrl): string
    {
        $retur = self::retur();
        return $retur === [] ? '' : rtrim($baseUrl, '/') . $retur['pagina'];
    }
}
