<?php

declare(strict_types=1);

/**
 * Randatorii de PROBĂ ai regiunilor dinamice (poarta G-regiuni, tools/dev/test-regiuni.php; pista A, docs/MAGAZIN-WC.md): câțiva
 * randatori `proba:*` inventați, cu datele lor scrise aici ("Ceai de probă", "Atelier de probă"), fără nimic de la un furnizor și
 * fără date ale unui client. Îi încarcă App\Support\CadruCapturat\Regiuni doar cu constanta ANDAXI_DEV_REGIUNI_PROBA (definită de
 * test și de tools/dev/lib/router-regiuni.php, routerul serverului lui de probă); pe un site ea nu există.
 *
 *  - proba:lista (cache „pagina”, interior): lista produselor din starea capturii (la dovadă) sau din LISTA (la o cerere);
 *    `?lista=1` arată doar primul produs (conținutul viu);
 *  - proba:cos (cache „sesiune”, interior și atributul class al aceluiași element): numărul produselor din coș (starea capturii la
 *    dovadă, sesiunea la o cerere), cu un câmp volatil (`proba_cheie`, aleator la fiecare randare) pe care normalizarea lui îl
 *    declară; clasa elementului primește `proba-cos-gol` la coșul gol (ca modulele coșului WooCommerce, `et_pb_wc_cart_empty`);
 *  - proba:evenimente (cache „pagina”, atributul data-events): evenimentele, ca JSON într-un atribut; `?rau=1` dă o valoare cu
 *    ghilimele (cadrul trebuie s-o refuze);
 *  - proba:ecou (cache „sesiune”, interior): parametrul `t` al cererii, scăpat doar cu htmlspecialchars (un `{{ax:` din el rămâne
 *    text: ieșirea nu mai trece prin jetoane);
 *  - proba:cont (cache „sesiune”, interior): contul; cu `?endpoint=orders` adaugă clasele corpului `logged-in` și
 *    `woocommerce-orders` și titlul „Comenzi <&>” (scăpat);
 *  - proba:indiscret (cache „pagina”, interior): la dovadă dă octeții capturați, la o cerere citește sesiunea (interzis pentru o
 *    regiune „pagina”: cadrul răspunde 500 și nimic nu intră în cache);
 *  - proba:seif (cache „sesiune”, interior): cu `?pune=1` scrie în `$_SESSION` un secret inventat (SECRET; plasa SesiuneLaNevoie
 *    pornește sesiunea și trimite cookie-ul ei), ca un vizitator cu ceva al lui în sesiune; altfel arată secretul din sesiune
 *    (ContextRegiune::sesiune), dovada că sesiunea vizitatorului chiar îl are;
 *  - proba:direct (cache „pagina”, interior): încalcă contractul, ca un randator scris greșit: citește secretul direct din
 *    `$_SESSION` (nu prin ContextRegiune::sesiune). Cadrul îi dă un `$_SESSION` gol, iar documentul cu regiuni randat lângă o
 *    sesiune pornită pleacă no-store, oricare ar fi Cache-Control-ul capturat (SEC-A3);
 *  - proba:scrie (cache „pagina”, interior): la o cerere scrie în `$_SESSION` (interzis: 500, nimic în cache, nicio sesiune).
 */

use App\Support\CadruCapturat\ContextRegiune;
use App\Support\CadruCapturat\Randator;
use App\Support\CadruCapturat\Regiuni;

if (!defined('ANDAXI_DEV_REGIUNI_PROBA')) {
    return;
}

final class RegiuneProbaLista implements Randator
{
    public const LISTA = [['id' => 7, 'nume' => 'Ceai de probă & miere', 'pret' => '12,50'], ['id' => 8, 'nume' => 'Cană de probă', 'pret' => '30,00']];

    public function randeaza(ContextRegiune $ctx): string
    {
        $produse = $ctx->laDovada() ? (array) ($ctx->stare()['produse'] ?? []) : self::LISTA;
        if (!$ctx->laDovada() && (string) ($ctx->get['lista'] ?? '') === '1') {
            $produse = array_slice($produse, 0, 1);
        }
        $rez = "\n<ul class=\"proba-lista\">";
        foreach ($produse as $p) {
            $rez .= '<li data-product_id="' . Regiuni::attr((string) $p['id']) . '">' . Regiuni::html((string) $p['nume']) . ' — ' . Regiuni::html((string) $p['pret'])
                . ' lei</li>';
        }
        return $rez . "</ul>\n";
    }

    public function normalizeaza(string $html): string
    {
        return $html;
    }
}

final class RegiuneProbaCos implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        $numar = $ctx->laDovada() ? (int) ($ctx->stare()['numar'] ?? 0) : count((array) ($ctx->sesiune()['proba_cos'] ?? []));
        if ($ctx->mod() === 'atribut') {
            return 'proba-regiune-cos' . ($numar === 0 ? ' proba-cos-gol' : '');
        }
        $text = $numar === 0 ? 'Coșul este gol.' : 'Coșul are ' . $numar . ($numar === 1 ? ' produs.' : ' produse.');
        return '<p class="proba-cos">' . Regiuni::html($text) . '</p><input type="hidden" name="proba_cheie" value="' . bin2hex(random_bytes(3)) . '">';
    }

    public function normalizeaza(string $html): string
    {
        return (string) preg_replace('/(name="proba_cheie" value=")[0-9a-f]{6}"/', '${1}{{volatil}}"', $html);
    }
}

final class RegiuneProbaEvenimente implements Randator
{
    public const EVENIMENTE = [['title' => 'Atelier de proba', 'start' => '2026-12-05']];

    public function randeaza(ContextRegiune $ctx): string
    {
        if (!$ctx->laDovada() && (string) ($ctx->get['rau'] ?? '') === '1') {
            return '[{"title":"rupe ghilimelele"}]';
        }
        return Regiuni::jsonAttr($ctx->laDovada() ? (array) ($ctx->stare()['evenimente'] ?? []) : self::EVENIMENTE);
    }

    public function normalizeaza(string $html): string
    {
        return $html;
    }
}

final class RegiuneProbaEcou implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        return '<p class="proba-ecou">' . htmlspecialchars((string) ($ctx->get['t'] ?? 'nimic')) . '</p>';
    }

    public function normalizeaza(string $html): string
    {
        return $html;
    }
}

final class RegiuneProbaCont implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        if (!$ctx->laDovada() && (string) ($ctx->get['endpoint'] ?? '') === 'orders') {
            $ctx->adaugaClasaBody('logged-in');
            $ctx->adaugaClasaBody('woocommerce-orders');
            $ctx->titlu('Comenzi <&>');
            return '<div class="proba-cont">Comenzi</div>';
        }
        return '<div class="proba-cont">Panou</div>';
    }

    public function normalizeaza(string $html): string
    {
        return $html;
    }
}

final class RegiuneProbaIndiscret implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        if ($ctx->laDovada()) {
            return '<p class="proba-indiscret">Nimic din sesiune.</p>';
        }
        return '<p class="proba-indiscret">' . Regiuni::html((string) count($ctx->sesiune())) . '</p>';
    }

    public function normalizeaza(string $html): string
    {
        return $html;
    }
}

final class RegiuneProbaSeif implements Randator
{
    public const SECRET = 'secret-de-proba-al-vizitatorului';

    public function randeaza(ContextRegiune $ctx): string
    {
        if (!$ctx->laDovada() && (string) ($ctx->get['pune'] ?? '') === '1') {
            $_SESSION['proba_seif'] = self::SECRET;
            return '<p class="proba-seif">Pus.</p>';
        }
        return '<p class="proba-seif">' . Regiuni::html((string) ($ctx->sesiune()['proba_seif'] ?? 'Nimic.')) . '</p>';
    }

    public function normalizeaza(string $html): string
    {
        return $html;
    }
}

final class RegiuneProbaDirect implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        return '<p class="proba-direct">' . Regiuni::html((string) ($_SESSION['proba_seif'] ?? 'Nimic.')) . '</p>';
    }

    public function normalizeaza(string $html): string
    {
        return $html;
    }
}

final class RegiuneProbaScrie implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        if (!$ctx->laDovada()) {
            $_SESSION['proba_scris'] = 1;
        }
        return '<p class="proba-scrie">Nimic.</p>';
    }

    public function normalizeaza(string $html): string
    {
        return $html;
    }
}

Regiuni::inregistreazaProba('proba:lista', 'pagina', ['interior'], [], new RegiuneProbaLista());
Regiuni::inregistreazaProba('proba:cos', 'sesiune', ['interior', 'atribut'], ['class'], new RegiuneProbaCos());
Regiuni::inregistreazaProba('proba:evenimente', 'pagina', ['atribut'], ['data-events'], new RegiuneProbaEvenimente());
Regiuni::inregistreazaProba('proba:ecou', 'sesiune', ['interior'], [], new RegiuneProbaEcou());
Regiuni::inregistreazaProba('proba:cont', 'sesiune', ['interior'], [], new RegiuneProbaCont());
Regiuni::inregistreazaProba('proba:indiscret', 'pagina', ['interior'], [], new RegiuneProbaIndiscret());
Regiuni::inregistreazaProba('proba:seif', 'sesiune', ['interior'], [], new RegiuneProbaSeif());
Regiuni::inregistreazaProba('proba:direct', 'pagina', ['interior'], [], new RegiuneProbaDirect());
Regiuni::inregistreazaProba('proba:scrie', 'pagina', ['interior'], [], new RegiuneProbaScrie());
