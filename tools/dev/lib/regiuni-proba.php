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
 *    dovadă, sesiunea la o cerere), cu un câmp volatil (`proba_cheie`, 10 hexa aleatori la fiecare randare) pe care îl declară
 *    normalizarea lui din lista închisă (`nonce:proba_cheie`); clasa elementului e cea capturată (ContextRegiune::capturat), cu
 *    `proba-cos-gol` la coșul gol (ca modulele coșului WooCommerce, `et_pb_wc_cart_empty`);
 *  - proba:evenimente (cache „pagina”, atributul data-events): evenimentele, ca JSON într-un atribut; `?rau=1` dă o valoare cu
 *    ghilimele (cadrul trebuie s-o refuze);
 *  - proba:ecou (cache „sesiune”, interior): parametrul `t` al cererii, scăpat doar cu htmlspecialchars (un `{{ax:` din el rămâne
 *    text: ieșirea nu mai trece prin jetoane);
 *  - proba:cont (cache „sesiune”, interior): contul; cu `?endpoint=orders` adaugă clasele corpului `logged-in` și
 *    `woocommerce-orders` și titlul „Comenzi <&>” sau cel din `?titlu=` (scăpat; un `{{ax:` din el rămâne text);
 *  - proba:indiscret (cache „pagina”, interior): la dovadă dă octeții capturați, la o cerere citește sesiunea (interzis pentru o
 *    regiune „pagina”: cadrul răspunde 500 și nimic nu intră în cache);
 *  - proba:seif (cache „sesiune”, interior): cu `?pune=1` scrie în `$_SESSION` un secret inventat (SECRET; plasa SesiuneLaNevoie
 *    pornește sesiunea și trimite cookie-ul ei), ca un vizitator cu ceva al lui în sesiune; altfel arată secretul din sesiune
 *    (ContextRegiune::sesiune), dovada că sesiunea vizitatorului chiar îl are;
 *  - proba:direct (cache „pagina”, interior): încalcă contractul, ca un randator scris greșit: citește secretul direct din
 *    `$_SESSION` (nu prin ContextRegiune::sesiune). Cadrul îi dă un `$_SESSION` gol, iar documentul cu regiuni randat lângă o
 *    sesiune pornită pleacă no-store, oricare ar fi Cache-Control-ul capturat (SEC-A3);
 *  - proba:scrie (cache „pagina”, interior): la o cerere scrie în `$_SESSION` (interzis: 500, nimic în cache, nicio sesiune);
 *  - proba:jsonld (cache „pagina”, interior, într-un `<script type="application/ld+json">`): produsul ca JSON prin
 *    Regiuni::jsonScript (un `</script>` din nume rămâne `\u003C/script\u003E`); `?rau=1` dă un JSON cu „<” (cadrul: 500);
 *  - proba:corp (cache „pagina”, interior): cere, după starea capturii (`adauga`, `scoate`, `titlu`), clase ale corpului sau un
 *    titlu (dovada documentului le compară cu cele capturate);
 *  - proba:normalizare (cache „pagina”, interior): declară o normalizare din afara listei închise (dovada: NU).
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

    public function normalizari(): array
    {
        return [];
    }
}

final class RegiuneProbaCos implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        $numar = $ctx->laDovada() ? (int) ($ctx->stare()['numar'] ?? 0) : count((array) ($ctx->sesiune()['proba_cos'] ?? []));
        if ($ctx->mod() === 'atribut') {
            // Clasele capturate ale elementului (ContextRegiune::capturat, SPEC-A-09), cu `proba-cos-gol` doar la coșul gol.
            $clase = array_values(array_filter(preg_split('/[\t\n\f\r ]+/', $ctx->capturat()) ?: [], static fn (string $c): bool => $c !== '' && $c !== 'proba-cos-gol'));
            if ($numar === 0) {
                $clase[] = 'proba-cos-gol';
            }
            return implode(' ', $clase);
        }
        $text = $numar === 0 ? 'Coșul este gol.' : 'Coșul are ' . $numar . ($numar === 1 ? ' produs.' : ' produse.');
        return '<p class="proba-cos">' . Regiuni::html($text) . '</p><input type="hidden" name="proba_cheie" value="' . bin2hex(random_bytes(5)) . '">';
    }

    /** Câmpul volatil e un „nonce” de 10 hexa, normalizat după numele lui (lista închisă Regiuni::NORMALIZARI). */
    public function normalizari(): array
    {
        return ['nonce:proba_cheie'];
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

    public function normalizari(): array
    {
        return [];
    }
}

final class RegiuneProbaEcou implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        return '<p class="proba-ecou">' . htmlspecialchars((string) ($ctx->get['t'] ?? 'nimic')) . '</p>';
    }

    public function normalizari(): array
    {
        return [];
    }
}

final class RegiuneProbaCont implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        if (!$ctx->laDovada() && (string) ($ctx->get['endpoint'] ?? '') === 'orders') {
            $ctx->adaugaClasaBody('logged-in');
            $ctx->adaugaClasaBody('woocommerce-orders');
            $ctx->titlu((string) ($ctx->get['titlu'] ?? 'Comenzi <&>'));
            return '<div class="proba-cont">Comenzi</div>';
        }
        return '<div class="proba-cont">Panou</div>';
    }

    public function normalizari(): array
    {
        return [];
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

    public function normalizari(): array
    {
        return [];
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

    public function normalizari(): array
    {
        return [];
    }
}

final class RegiuneProbaDirect implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        return '<p class="proba-direct">' . Regiuni::html((string) ($_SESSION['proba_seif'] ?? 'Nimic.')) . '</p>';
    }

    public function normalizari(): array
    {
        return [];
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

    public function normalizari(): array
    {
        return [];
    }
}

final class RegiuneProbaJsonLd implements Randator
{
    public const PRODUS = ['@context' => 'https://schema.org/', '@type' => 'Product', 'name' => 'Ceai de probă </script><!-- & miere', 'sku' => 'P-7'];

    public function randeaza(ContextRegiune $ctx): string
    {
        if (!$ctx->laDovada() && (string) ($ctx->get['rau'] ?? '') === '1') {
            return '{"name":"</script><p>ieșit din script</p>"}';
        }
        return Regiuni::jsonScript($ctx->laDovada() ? (array) ($ctx->stare()['produs'] ?? []) : self::PRODUS);
    }

    public function normalizari(): array
    {
        return [];
    }
}

final class RegiuneProbaCorp implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        $stare = (array) ($ctx->stare() ?? []);
        if (is_string($stare['adauga'] ?? null)) {
            $ctx->adaugaClasaBody($stare['adauga']);
        }
        if (is_string($stare['scoate'] ?? null)) {
            $ctx->scoateClasaBody($stare['scoate']);
        }
        if (is_string($stare['titlu'] ?? null)) {
            $ctx->titlu($stare['titlu']);
        }
        return '<p class="proba-corp">Corp.</p>';
    }

    public function normalizari(): array
    {
        return [];
    }
}

final class RegiuneProbaNormalizare implements Randator
{
    public function randeaza(ContextRegiune $ctx): string
    {
        return '<p class="proba-normalizare">Altceva.</p>';
    }

    /** O normalizare din afara listei închise (ca un randator care ar „normaliza” totul la ""): dovada o refuză. */
    public function normalizari(): array
    {
        return ['totul'];
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
Regiuni::inregistreazaProba('proba:jsonld', 'pagina', ['interior'], [], new RegiuneProbaJsonLd());
Regiuni::inregistreazaProba('proba:corp', 'pagina', ['interior'], [], new RegiuneProbaCorp());
Regiuni::inregistreazaProba('proba:normalizare', 'pagina', ['interior'], [], new RegiuneProbaNormalizare());
