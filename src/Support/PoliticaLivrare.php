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
 *     "termen_livrare": [0, 1, 1, 2],     // implicit: [pregătire min, max, transport min, max], zile lucrătoare; null = nehotărât
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
 * Transportul gratuit (pragul din setări), exact ca în coș
 * (CheckoutCalculator::transportGratuit; Bioscem, decizia D din 20 oct 2026):
 * comanda e gratuită când valoarea ei atinge pragul județului (București sau
 * provincie), ORICE preț ar avea produsele, deci și la produsele cu reducere.
 * Pentru un produs comandat singur, fără cupon, valoarea comenzii e prețul afișat
 * (cu reducerea, dacă e). Un prag 0 înseamnă „niciodată gratuit” acolo.
 *  - prețul atinge ambele praguri → 0 lei;
 *  - nu atinge niciunul → tariful livrării la adresă;
 *  - atinge doar unul (praguri diferite pe județe) → depinde de județ, iar Google
 *    nu primește regiuni pentru România → nu declarăm nimic.
 * Cuponul și punctele de fidelitate le alege clientul în coș; cu setarea
 * „include cupoanele” bifată, un cupon poate coborî comanda sub prag. Google
 * cere costul pentru oferta afișată (fără cupon), deci declarăm costul fără cupon.
 *
 * Taxa pentru kilometri suplimentari (localitățile din afara ariei curierului,
 * plătită și la comenzile cu transport gratuit) NU intră. Regula Merchant Center
 * e „costul declarat egal sau mai mare decât cel plătit”, deci pentru acele
 * localități suntem sub cost: e o decizie a proprietarului site-ului (fie cazul cel
 * mai scump, fie așa, fie tarife pe coduri poștale în Merchant Center).
 *
 * Termenul de livrare (deliveryTime / handling_time / transit_time; câmp
 * obligatoriu pentru Google din 8 sep 2026): pregătirea (de la comandă până la
 * predarea la curier) și transportul (de la curier până la client), fiecare
 * [minim, maxim] în zile lucrătoare luni–vineri. Valorile implicite ale site-ului
 * sunt în termen_livrare (la Bioscem 0–1 + 1–2, decizia din 20 oct 2026); cele
 * patru numere se pot schimba fără cod, din setări (tabelul settings, fără ecran
 * în admin): seo_livrare_pregatire_min/_max și seo_livrare_transport_min/_max.
 * O valoare lipsă sau greșită (nu e număr întreg 0–30) ia valoarea implicită;
 * dacă minimul iese peste maxim, perechea întreagă revine la cea implicită. Un
 * site fără termen_livrare (null) îl declară doar dacă setările dau toate patru
 * numerele valide; altfel fără termen.
 * Apar în datele structurate (Offer.shippingDetails.deliveryTime) și în flux
 * (g:min_handling_time…, g:shipping_handling_business_days…). Pagina de livrare
 * a site-ului nu se atinge.
 *
 * Returul: din pagina de condiții a site-ului (la Bioscem /termeni-conditii: 14
 * zile de la primire, fără motiv, clientul plătind doar transportul înapoi →
 * ReturnFeesCustomerResponsibility).
 *
 * Oprire de urgență: SEO_LIVRARE_RETUR=0 în .env scoate livrarea (cu termenul
 * ei) și returul din datele structurate și livrarea din flux (restul rămâne ca în
 * livrarea 1).
 *
 * Din Bioscem `dfcf6bd` și `1e46f9f` (livrarea 2 SEO, A5), `5359ec6` (termenul
 * din setări) și `196fa46` (regula D), cu condițiile mutate din constante în
 * datele site-ului.
 */
final class PoliticaLivrare
{
    /** Setările care pot schimba termenul (cheile din tabelul settings). */
    public const SETARI_TERMEN = [
        'pregatire' => ['seo_livrare_pregatire_min', 'seo_livrare_pregatire_max'],
        'transport' => ['seo_livrare_transport_min', 'seo_livrare_transport_max'],
    ];

    /** Cel mai mare număr de zile acceptat într-o setare de termen. */
    public const ZILE_MAXIM = 30;

    /** Zilele lucrătoare (pregătire și transport): luni–vineri, ca nume schema.org. */
    public const ZILE_LUCRATOARE = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

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
     * Termenul de livrare implicit al site-ului (seo.json → termen_livrare), în
     * zile lucrătoare: [pregătire min, max, transport min, max], sau null până îl
     * hotărăște proprietarul site-ului.
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
     * opțiune, livrarea la adresă (vezi sus de ce nu și punctele de ridicare), cu
     * termenul de livrare când site-ul îl are. Listă goală când nu putem spune
     * sigur cât costă transportul.
     *
     * @param float $pretAfisat prețul din pagină (cu reducerea, dacă e)
     * @return list<array{serviciu: string, pret: float, termen?: array{pregatire: array{0: int, 1: int}, transport: array{0: int, 1: int}}}>
     */
    public static function optiuni(array $setari, float $pretAfisat): array
    {
        if (!self::activ() || !ShippingPricing::esteActiv($setari)) {
            return [];
        }
        $adresa = ShippingPricing::pretDeBaza($setari, false);
        if ($adresa === null || $adresa <= 0.0) {
            // Un preț fix de 0 lei e mai degrabă o setare necompletată decât o promisiune.
            return [];
        }

        // Pragul de gratuitate e pe județ (București / provincie), ca în coș.
        $gratuitBucuresti = self::gratuit($setari, true, $pretAfisat);
        $gratuitProvincie = self::gratuit($setari, false, $pretAfisat);
        if ($gratuitBucuresti !== $gratuitProvincie) {
            // Gratuit doar în unele județe: Google nu primește regiuni pentru România. Tăcem.
            return [];
        }

        $optiune = [
            'serviciu' => self::serviciuAdresa(),
            'pret' => $gratuitBucuresti ? 0.0 : round($adresa, 2),
        ];
        $termen = self::termen($setari);
        if ($termen !== null) {
            $optiune['termen'] = $termen;
        }
        return [$optiune];
    }

    /**
     * Comanda de $valoare lei (fără cupon) are transport gratuit în București /
     * în provincie? Aceeași regulă ca CheckoutCalculator::transportGratuit: pragul
     * județului (lipsă din setări = 200, ca în coș), 0 = niciodată gratuit, iar
     * comparația e „valoarea ≥ prag”, fără să conteze dacă produsele sunt la reducere.
     */
    public static function gratuit(array $setari, bool $bucuresti, float $valoare): bool
    {
        $prag = $bucuresti
            ? (float) ($setari['shipping_free_bucharest'] ?? 200)
            : (float) ($setari['shipping_free_province'] ?? 200);
        if ($prag <= 0) {
            return false;
        }
        return $valoare >= $prag;
    }

    /**
     * Termenul de livrare: valorile implicite ale site-ului (termenLivrare), cu
     * setările peste ele (vezi sus). O valoare lipsă sau greșită ia valoarea
     * implicită; un minim peste maxim readuce perechea la cea implicită. Null când
     * nu e niciun termen (site fără termen_livrare și setări incomplete) sau când
     * livrarea e oprită.
     *
     * @return array{pregatire: array{0: int, 1: int}, transport: array{0: int, 1: int}}|null
     */
    public static function termen(array $setari): ?array
    {
        if (!self::activ()) {
            return null;
        }
        $implicit = self::termenLivrare();
        $termen = $implicit === null
            ? null
            : ['pregatire' => [$implicit[0], $implicit[1]], 'transport' => [$implicit[2], $implicit[3]]];
        $rezultat = [];
        foreach (self::SETARI_TERMEN as $parte => [$cheieMin, $cheieMax]) {
            $min = self::zile($setari[$cheieMin] ?? null);
            $max = self::zile($setari[$cheieMax] ?? null);
            $baza = $termen[$parte] ?? null;
            if ($baza === null) {
                // Fără valori implicite: perechea doar din setări, întreagă și în ordine.
                if ($min === null || $max === null || $min > $max) {
                    return null;
                }
                $rezultat[$parte] = [$min, $max];
                continue;
            }
            $min ??= $baza[0];
            $max ??= $baza[1];
            $rezultat[$parte] = $min <= $max ? [$min, $max] : $baza;
        }
        return $rezultat;
    }

    /** O setare de zile: număr întreg 0–30 (fără semn, fără zecimale); altfel null. */
    private static function zile(mixed $valoare): ?int
    {
        $text = trim((string) ($valoare ?? ''));
        if (preg_match('/^\d{1,2}$/', $text) !== 1) {
            return null;
        }
        $zile = (int) $text;
        return $zile <= self::ZILE_MAXIM ? $zile : null;
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
