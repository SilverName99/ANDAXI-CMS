<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Pachetele de produse (pluginul `pachete_produse`, din Biovitality 90afd46, 83067d3, afc6fd7, 7391226; docs/PLUGINURI.md,
 * „Pachete de produse”). Un pachet e un produs ca oricare altul: prețul, reducerea, TVA-ul, stocul, transportul gratuit, linia
 * comenzii și ce pleacă în ERP sunt ale lui, deci coșul, checkout-ul și comanda îl socotesc exact ca pe orice produs. În plus
 * știe din ce produse ale magazinului e făcut: `products.bundle_items_json` (migrarea pachete_produse/0055), lista
 * `[{"id", "qty", "desc"}]` în ordinea din editor. Pluginul folosește lista în trei locuri:
 *
 *  - pagina pachetului: sloturile {{bundle_item_N_name|image|image_url|desc|qty|url}} (N = 1…12) și {{bundle_items_count}} din
 *    șablonul produsului (SiteController::buildProductTemplateRender); numele, poza și descrierea scurtă se citesc din produsul
 *    ales la fiecare afișare, iar sloturile goale ies goale (un șablon cu patru carduri nu rămâne cu „{{bundle_item_4_name}}”);
 *  - coșul: pe linia unui produs care intră în pachete, toate pachetele lui active și cu stoc, de la cel mai ieftin, fără cele
 *    aflate deja în coș (`bundle_hints` în liniile lui CheckoutCalculator::buildSummary; pagina coșului și coșul flotant), cu
 *    „Schimbă în pachet” (PacheteProduseController);
 *  - editorul produsului: conținutul pachetului, arătat când șablonul ales folosește un slot.
 *
 * Fără plugin pe site nimic din toate acestea (coloana rămâne, neatinsă); sloturile unui șablon ies goale. Regulile sunt cele ale
 * codului Biovitality (`ProductBundles`, `loadProductBundleItemsForSite`, `normalizeProductBundleItems`), una câte una.
 */
final class PacheteProduse
{
    /** Câte produse încap într-un pachet (sloturile {{bundle_item_N_*}}). */
    public const MAX_ELEMENTE = 12;

    /** Pachetele din care face parte fiecare produs, pe cerere (buildSummary e chemat de mai multe ori pe pagină). */
    private static ?array $memo = null;

    /** Pluginul e pe site (pornit sau „se oprește”: ca pornit, nu începe nimic cu bani). */
    public static function activ(): bool
    {
        return Plugins::vizibil('pachete_produse');
    }

    /** Uită ce a citit (testele; o cerere nouă pornește oricum curat). */
    public static function uita(): void
    {
        self::$memo = null;
    }

    /**
     * Pachetele propuse pe fiecare linie a coșului (`bundle_hints`): toate pachetele din care face parte produsul liniei, de la
     * cel mai ieftin, fără pachetul însuși și fără cele aflate deja în coș. O linie fără propuneri rămâne neatinsă (fără cheie).
     *
     * @param list<array<string, mixed>> $linii liniile lui CheckoutCalculator::buildSummary (cu `id` = produsul)
     * @return list<array<string, mixed>>
     */
    public static function cuPropuneri(?PDO $db, array $linii): array
    {
        if (!self::activ() || $linii === []) {
            return $linii;
        }
        $pachete = self::pentruProduse($db, array_column($linii, 'id'));
        if ($pachete === []) {
            return $linii;
        }
        $inCos = array_fill_keys(array_map('intval', array_column($linii, 'id')), true);
        foreach ($linii as &$linie) {
            $idLinie = (int) ($linie['id'] ?? 0);
            // Pachetele deja aflate în coș nu se propun: omul le-a pus deja.
            $propuneri = array_values(array_filter(
                $pachete[$idLinie] ?? [],
                static fn (array $p): bool => (int) $p['id'] !== $idLinie && !isset($inCos[(int) $p['id']])
            ));
            if ($propuneri !== []) {
                $linie['bundle_hints'] = $propuneri;
            }
        }
        unset($linie);

        return $linii;
    }

    /**
     * Adresa foii de stil a casetei din coș (`public/assets/css/pachete-produse.css`, cu versiunea fișierului), doar dacă vreo
     * linie are pachete propuse; altfel ''. Coșul flotant o primește în răspuns (`pachete_css`) și o încarcă atunci.
     *
     * @param list<array<string, mixed>> $linii
     */
    public static function adresaStiluri(array $linii): string
    {
        foreach ($linii as $linie) {
            if (is_array($linie) && is_array($linie['bundle_hints'] ?? null) && $linie['bundle_hints'] !== []) {
                return '/assets/css/pachete-produse.css?v=' . (int) (@filemtime(Paths::public('assets/css/pachete-produse.css')) ?: 1);
            }
        }

        return '';
    }

    /**
     * Legătura spre foaia de stil a casetei, pentru pagina coșului (cu newline), sau '' fără pachete propuse: pagina rămâne
     * octet cu octet cea de dinainte.
     *
     * @param list<array<string, mixed>> $linii
     */
    public static function linkStiluri(array $linii): string
    {
        $adresa = self::adresaStiluri($linii);

        return $adresa === '' ? '' : '<link rel="stylesheet" href="' . htmlspecialchars($adresa, ENT_QUOTES) . '" data-pachete-css="1">' . "\n";
    }

    /**
     * Pachetele din care face parte fiecare produs cerut, de la cel mai ieftin: produsul => listă de pachete. Legătura e ținută
     * invers în bază (pachetul știe ce conține), deci pachetele (puține) se citesc o dată pe cerere și legătura se întoarce.
     *
     * @param list<int|string> $idProduse
     * @return array<int, list<array{id:int,name:string,slug:string,url:string,price:float,image_url:string}>>
     */
    public static function pentruProduse(?PDO $db, array $idProduse): array
    {
        $idProduse = array_values(array_unique(array_filter(
            array_map('intval', $idProduse),
            static fn (int $id): bool => $id > 0
        )));
        if ($idProduse === [] || !$db instanceof PDO) {
            return [];
        }
        $harta = self::hartaComponentaCatrePachet($db);
        $rezultat = [];
        foreach ($idProduse as $id) {
            if (isset($harta[$id])) {
                $rezultat[$id] = $harta[$id];
            }
        }

        return $rezultat;
    }

    /**
     * Produsul chiar face parte din pachetul ăsta (activ, neșters)? Verificarea dinaintea schimbării produsului cu pachetul în
     * coș: altfel oricine ar putea trimite ce pereche vrea. Citește pachetul cerut, nu harta propunerilor (aceea sare peste
     * pachetele epuizate, iar un pachet fără stoc trebuie refuzat cu motivul lui).
     */
    public static function esteComponenta(?PDO $db, int $idProdus, int $idPachet): bool
    {
        if ($idProdus <= 0 || $idPachet <= 0 || !$db instanceof PDO) {
            return false;
        }
        try {
            $stmt = $db->prepare('SELECT bundle_items_json FROM products WHERE id = :id AND deleted_at IS NULL AND is_active = 1 LIMIT 1');
            $stmt->execute(['id' => $idPachet]);
            $brut = $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
        if (!is_string($brut) || trim($brut) === '') {
            return false;
        }
        $elemente = json_decode($brut, true);
        if (!is_array($elemente)) {
            return false;
        }
        foreach ($elemente as $element) {
            if (is_array($element) && (int) ($element['id'] ?? 0) === $idProdus) {
                return true;
            }
        }

        return false;
    }

    /**
     * Produsele pachetului, pentru pagina lui: în ordinea din editor, cel mult MAX_ELEMENTE, fiecare cu numele, slug-ul, poza și
     * descrierea scurtă ale produsului de acum (descrierea proprie din pachet, dacă e scrisă, o înlocuiește); un produs șters
     * între timp e sărit. Fără plugin pe site: nimic.
     *
     * @return list<array{qty:string,name:string,desc:string,image_url:string,url:string}>
     */
    public static function pentruPagina(?PDO $db, mixed $brut): array
    {
        if (!self::activ()) {
            return [];
        }
        if (is_string($brut)) {
            $decodat = json_decode($brut, true);
            $brut = is_array($decodat) ? $decodat : [];
        }
        if (!is_array($brut) || $brut === [] || !$db instanceof PDO) {
            return [];
        }
        $intrari = [];
        foreach ($brut as $intrare) {
            if (!is_array($intrare)) {
                continue;
            }
            $id = (int) ($intrare['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $intrari[] = [
                'id' => $id,
                'qty' => trim((string) ($intrare['qty'] ?? '')),
                'desc' => trim((string) ($intrare['desc'] ?? '')),
            ];
            if (count($intrari) >= self::MAX_ELEMENTE) {
                break;
            }
        }
        if ($intrari === []) {
            return [];
        }
        $ids = array_column($intrari, 'id');
        try {
            $stmt = $db->prepare('SELECT id, name, slug, short_description, image_url FROM products
                WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND deleted_at IS NULL');
            $stmt->execute($ids);
            $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
        $dupaId = [];
        foreach ($randuri as $rand) {
            if (is_array($rand)) {
                $dupaId[(int) ($rand['id'] ?? 0)] = $rand;
            }
        }
        $rezultat = [];
        foreach ($intrari as $intrare) {
            $produs = $dupaId[$intrare['id']] ?? null;
            if (!is_array($produs)) {
                continue;
            }
            $slug = trim((string) ($produs['slug'] ?? ''));
            $rezultat[] = [
                'qty' => $intrare['qty'],
                'name' => (string) ($produs['name'] ?? ''),
                'desc' => $intrare['desc'] !== '' ? $intrare['desc'] : trim((string) ($produs['short_description'] ?? '')),
                'image_url' => trim((string) ($produs['image_url'] ?? '')),
                'url' => $slug !== '' ? '/produs/' . rawurlencode($slug) : '',
            ];
        }

        return $rezultat;
    }

    /**
     * Sloturile șablonului de produs: {{bundle_item_N_name|desc|qty|url|image_url|image}} pentru N = 1…MAX_ELEMENTE (și cele
     * necompletate, goale) și {{bundle_items_count}}. `image` e un `<img>` gata făcut (se pune ca markup, în $brute), restul se
     * escapează ca orice text al șablonului.
     *
     * @param list<array<string, string>> $elemente din pentruPagina()
     * @param array<string, string> $harta valorile șablonului (se adaugă sloturile)
     * @param array<string, bool> $brute codurile puse ca markup (se adaugă imaginile)
     */
    public static function sloturi(array $elemente, array &$harta, array &$brute): void
    {
        for ($i = 1; $i <= self::MAX_ELEMENTE; $i++) {
            $element = $elemente[$i - 1] ?? null;
            $urlImagine = is_array($element) ? trim((string) ($element['image_url'] ?? '')) : '';
            $nume = is_array($element) ? (string) ($element['name'] ?? '') : '';
            $harta['bundle_item_' . $i . '_name'] = $nume;
            $harta['bundle_item_' . $i . '_desc'] = is_array($element) ? (string) ($element['desc'] ?? '') : '';
            $harta['bundle_item_' . $i . '_qty'] = is_array($element) ? (string) ($element['qty'] ?? '') : '';
            $harta['bundle_item_' . $i . '_url'] = is_array($element) ? (string) ($element['url'] ?? '') : '';
            $harta['bundle_item_' . $i . '_image_url'] = $urlImagine;
            $harta['bundle_item_' . $i . '_image'] = $urlImagine !== ''
                ? '<img src="' . htmlspecialchars($urlImagine, ENT_QUOTES) . '" alt="' . htmlspecialchars($nume, ENT_QUOTES) . '" loading="lazy">'
                : '';
            $brute['bundle_item_' . $i . '_image'] = true;
        }
        $harta['bundle_items_count'] = (string) count($elemente);
    }

    /**
     * Conținutul pachetului din formularul produsului, curățat: doar produse (id > 0), fiecare o dată, cel mult MAX_ELEMENTE,
     * cantitatea (text, cel mult 24 de caractere) și descrierea proprie (cel mult 400).
     *
     * @return list<array{id:int,qty:string,desc:string}>
     */
    public static function normalizeaza(mixed $brut): array
    {
        if (is_string($brut)) {
            $decodat = json_decode($brut, true);
            $brut = is_array($decodat) ? $decodat : [];
        }
        if (!is_array($brut)) {
            return [];
        }
        $elemente = [];
        $vazute = [];
        foreach ($brut as $intrare) {
            if (!is_array($intrare)) {
                continue;
            }
            $id = (int) ($intrare['id'] ?? 0);
            // Același produs de două ori într-un pachet n-are sens.
            if ($id <= 0 || isset($vazute[$id])) {
                continue;
            }
            $vazute[$id] = true;
            $elemente[] = [
                'id' => $id,
                'qty' => mb_substr(trim((string) ($intrare['qty'] ?? '')), 0, 24),
                'desc' => mb_substr(trim((string) ($intrare['desc'] ?? '')), 0, 400),
            ];
            if (count($elemente) >= self::MAX_ELEMENTE) {
                break;
            }
        }

        return $elemente;
    }

    /**
     * Salvarea din editorul produsului, într-o instrucțiune separată: doar cu pluginul pe site și doar când formularul a trimis
     * câmpul (`bundle_items_json`); altfel conținutul din bază rămâne (alt formular, pluginul oprit). Fără migrare: nimic.
     */
    public static function salveazaDinFormular(PDO $db, int $idProdus): void
    {
        if ($idProdus <= 0 || !self::activ() || !array_key_exists('bundle_items_json', $_POST)) {
            return;
        }
        $elemente = self::normalizeaza($_POST['bundle_items_json']);
        $json = json_encode($elemente, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        try {
            // Un produs care n-a fost niciodată pachet (NULL) și rămâne fără conținut nu se atinge: o salvare neatinsă lasă rândul
            // exact cum era (ca editorul de dinainte, tools/dev/test-admin-faza-d.php).
            $db->prepare('UPDATE products SET bundle_items_json = :pachet WHERE id = :id' . ($elemente === [] ? ' AND bundle_items_json IS NOT NULL' : ''))
                ->execute(['pachet' => $json, 'id' => $idProdus]);
        } catch (Throwable) {
            // Fără pachete_produse/0055 încă: produsul e salvat, conținutul pachetului vine după migrare.
        }
        self::uita();
    }

    /**
     * Datele editorului de produs: conținutul fiecărui pachet (id => elemente), produsele care se pot alege (id, nume) și ce
     * șabloane folosesc un slot (id șablon => true). Fără plugin pe site: null (editorul nu arată nimic).
     *
     * @param list<array<string, mixed>> $produse lista produselor din admin
     * @param list<array<string, mixed>> $sabloane șabloanele de produs
     * @return array{continut: array<string, list<array{id:int,qty:string,desc:string}>>, produse: list<array{id:int,nume:string}>, sabloane: array<string, bool>}|null
     */
    public static function pentruEditor(?PDO $db, array $produse, array $sabloane): ?array
    {
        if (!self::activ()) {
            return null;
        }
        $continut = [];
        if ($db instanceof PDO) {
            try {
                $randuri = $db->query("SELECT id, bundle_items_json FROM products WHERE deleted_at IS NULL AND bundle_items_json IS NOT NULL
                    AND bundle_items_json <> '' AND bundle_items_json <> '[]'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                $randuri = [];
            }
            foreach ($randuri as $rand) {
                $elemente = self::normalizeaza($rand['bundle_items_json'] ?? '[]');
                if ($elemente !== []) {
                    $continut[(string) (int) $rand['id']] = $elemente;
                }
            }
        }
        $candidate = [];
        foreach ($produse as $produs) {
            if (is_array($produs) && (int) ($produs['id'] ?? 0) > 0) {
                $candidate[] = ['id' => (int) $produs['id'], 'nume' => (string) ($produs['name'] ?? '')];
            }
        }
        $cuSloturi = [];
        foreach ($sabloane as $sablon) {
            if (!is_array($sablon)) {
                continue;
            }
            $text = (string) ($sablon['html_content'] ?? '') . "\n" . (string) ($sablon['css_content'] ?? '') . "\n"
                . (string) ($sablon['js_content'] ?? '');
            // Șablonul cere conținutul pachetului dacă folosește măcar un slot.
            $cuSloturi[(string) (int) ($sablon['id'] ?? 0)] = str_contains($text, '{{bundle_item_');
        }

        return ['continut' => $continut, 'produse' => $candidate, 'sabloane' => $cuSloturi];
    }

    /**
     * Codurile pachetului în lista codurilor șablonului de produs (AdminController, `availablePlaceholders`), ca în codul
     * Biovitality.
     *
     * @return list<array{code:string,label:string}>
     */
    public static function coduriSablon(): array
    {
        return [
            ['code' => '{{bundle_items_count}}', 'label' => 'Pachet: câte produse conține'],
            ['code' => '{{bundle_item_1_name}}', 'label' => 'Pachet, produsul 1: denumire (schimbă 1 cu 2…' . self::MAX_ELEMENTE . ')'],
            ['code' => '{{bundle_item_1_image}}', 'label' => 'Pachet, produsul 1: imaginea gata pusă în <img>'],
            ['code' => '{{bundle_item_1_image_url}}', 'label' => 'Pachet, produsul 1: doar adresa imaginii'],
            ['code' => '{{bundle_item_1_desc}}', 'label' => 'Pachet, produsul 1: descriere'],
            ['code' => '{{bundle_item_1_qty}}', 'label' => 'Pachet, produsul 1: cantitate'],
            ['code' => '{{bundle_item_1_url}}', 'label' => 'Pachet, produsul 1: link către pagina lui'],
        ];
    }

    /**
     * @return array<int, list<array{id:int,name:string,slug:string,url:string,price:float,image_url:string}>>
     */
    private static function hartaComponentaCatrePachet(PDO $db): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        try {
            $randuri = $db->query("SELECT id, name, slug, price, sale_price, image_url, bundle_items_json FROM products
                WHERE deleted_at IS NULL AND is_active = 1 AND out_of_stock = 0 AND bundle_items_json IS NOT NULL
                AND bundle_items_json <> '' AND bundle_items_json <> '[]'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return self::$memo = [];
        }
        $harta = [];
        foreach ($randuri as $rand) {
            if (!is_array($rand)) {
                continue;
            }
            $idPachet = (int) ($rand['id'] ?? 0);
            $slug = trim((string) ($rand['slug'] ?? ''));
            if ($idPachet <= 0 || $slug === '') {
                continue;
            }
            $elemente = json_decode((string) ($rand['bundle_items_json'] ?? ''), true);
            if (!is_array($elemente)) {
                continue;
            }
            $redus = (float) ($rand['sale_price'] ?? 0);
            $imagine = trim((string) ($rand['image_url'] ?? ''));
            $pachet = [
                'id' => $idPachet,
                'name' => (string) ($rand['name'] ?? ''),
                'slug' => $slug,
                'url' => '/produs/' . rawurlencode($slug),
                'price' => $redus > 0 ? $redus : (float) ($rand['price'] ?? 0),
                'image_url' => $imagine !== '' ? $imagine : '/assets/img/product-placeholder.svg',
            ];
            $vazuteAici = [];
            foreach ($elemente as $element) {
                if (!is_array($element)) {
                    continue;
                }
                $idComponenta = (int) ($element['id'] ?? 0);
                // Același produs de două ori în același pachet n-are voie să ducă la două propuneri identice.
                if ($idComponenta <= 0 || $idComponenta === $idPachet || isset($vazuteAici[$idComponenta])) {
                    continue;
                }
                $vazuteAici[$idComponenta] = true;
                $harta[$idComponenta][] = $pachet;
            }
        }
        foreach ($harta as &$listaPachete) {
            usort($listaPachete, static fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        }
        unset($listaPachete);

        return self::$memo = $harta;
    }
}
