<?php

declare(strict_types=1);

/**
 * Conținutul din baza de date al unui site, schimbat o singură dată, din fișierul
 * site-ului: `seo-continut.json` din dosarul site-ului (SITE_DATE_DOSAR; pachetul din
 * site-uri/<site>/). Două feluri de rânduri:
 *
 *  - „aircall” (produse care își schimbă numele, adresa și textele Google, după
 *    cod): codul (sku) nu se schimbă niciodată (stocul și comenzile din ERP merg
 *    după cod); se schimbă numele, adresa trece la litere mici (forma veche, cu
 *    majusculă, face 301 la cea nouă din pagina produsului), titlul și descrierea
 *    pentru Google și primul paragraf din descriere;
 *  - „inlocuiri”: un text exact, înlocuit în textul unei pagini, în descrierea unui
 *    produs sau într-un tab al lui, de exact câte ori scrie în listă.
 *
 * La Bioscem (site-uri/bioscem/seo-continut.json, livrarea 4 SEO): D5, căștile
 * AIRCALL („… – alb” / „… – negru”, …-sp21a / …-sp21n, cu linkul spre cealaltă
 * culoare), și A9, 30 de linkuri vechi sau stricate din texte (/retur-garantie/,
 * poza WordPress de pe /program-sarbatori, articolele vechi, linkurile interne pe
 * http sau cu „/” la final, site-urile surori pe https, rândul „```html” de pe
 * /termeni-conditii). Formatul (pe lângă rândurile de mai sus, neschimbate față de
 * Bioscem):
 *
 *   {"versiune": 1,
 *    "nume_magazin": "Bioscem",                    // opțional: titlurile noi trebuie să rămână la fel și cu el
 *    "asteptat": {"aircall": 2, "inlocuiri": 30},  // opțional: câte rânduri trebuie să fie
 *    "aircall": […], "inlocuiri": […]}
 *
 * Portat din Bioscem `c3ad6ab` și `b51067a`: aceeași logică, cu lista, numele
 * magazinului și numărătoarea așteptată mutate din script în fișierul site-ului,
 * iar copiile în storage/ al site-ului (Paths).
 *
 * Coșul, comenzile și ERP-ul nu se schimbă: coșul ține produsele după id,
 * comenzile după id și numele de atunci (order_items), ERP-ul după cod (sku).
 *
 * Rulare, prin SSH, din folderul site-ului:
 *   php scripts/seo-continut.php --plan          arată „acum → nou”; nu scrie nimic
 *   php scripts/seo-continut.php --aplica        scrie; înainte salvează valorile de acum
 *                                                în storage/seo-continut-copie-<data>.json
 *   php scripts/seo-continut.php --restaureaza   pune înapoi valorile din cea mai nouă copie
 *        [--fisier=storage/seo-continut-copie-….json] [--forteaza]
 *
 * Siguranță:
 *  - refuză să scrie ceva dacă baza nu e cea așteptată: lipsește un tabel, o
 *    pagină, un produs sau un câmp, un text vechi nu apare exact de câte ori
 *    scrie în listă, codul unui produs AIRCALL nu e cel din listă (sau adresa
 *    nouă e deja a altui produs), numele, titlul sau descrierea AIRCALL au fost
 *    schimbate de mână între timp, ori un titlu nou n-ar apărea în Google exact
 *    cum e scris;
 *  - nu schimbă niciodată codul (sku) unui produs: îl verifică și după scriere,
 *    în aceeași tranzacție;
 *  - se poate rula de oricâte ori: a doua oară nu mai are ce schimba și nu mai
 *    face o copie nouă;
 *  - restaurarea nu calcă peste ce s-a modificat de mână între timp (doar cu
 *    --forteaza);
 *  - scrie totul într-o singură tranzacție (totul sau nimic), apoi golește
 *    cache-ul de pagini și fluxul Merchant Center. CDN-ul (hPanel) se golește de mână.
 *  - afișează doar texte de pe site, nicio dată personală.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\DateSite;
use App\Support\FluxGoogleMerchant;
use App\Support\Paths;
use App\Support\ResponseCache;
use App\Support\SeoMeta;
use App\Support\Settings;

// Fișierul site-ului și copiile valorilor de dinainte (în storage/ al site-ului, Paths).
define('FISIER_DATE', DateSite::dosar() . '/seo-continut.json');
define('DOSAR_COPII', Paths::stocare());
const PREFIX_COPIE = 'seo-continut-copie-';

function iesire(string $mesaj, int $cod): never
{
    fwrite($cod === 0 ? STDOUT : STDERR, $mesaj . "\n");
    exit($cod);
}

function scrie(string $linie = ''): void
{
    fwrite(STDOUT, $linie . "\n");
}

/** „«text»” sau „(gol)”, pe un singur rând, pentru afișare. */
function arata(?string $text, int $maxim = 220): string
{
    if ($text === null || $text === '') {
        return '(gol)';
    }
    $text = str_replace(["\r", "\n"], ['', '⏎'], $text);
    if (mb_strlen($text) > $maxim) {
        $text = mb_substr($text, 0, $maxim) . '…';
    }
    return '«' . $text . '»';
}

/** Bucata din jurul primei apariții, ca să se vadă unde e schimbarea. */
function context(string $text, string $cauta, int $latime = 40): string
{
    $poz = mb_strpos($text, $cauta);
    if ($poz === false) {
        return arata($cauta);
    }
    $inceput = max(0, $poz - $latime);
    $bucata = mb_substr($text, $inceput, ($poz - $inceput) + mb_strlen($cauta) + $latime);
    return ($inceput > 0 ? '…' : '') . arata($bucata, 400) . (($poz + mb_strlen($cauta) + $latime) < mb_strlen($text) ? '…' : '');
}

// ─────────────────────────────────────────────────────────────
// Argumente
// ─────────────────────────────────────────────────────────────

$folosire = 'Folosire: php scripts/seo-continut.php --plan | --aplica | --restaureaza [--fisier=…] [--forteaza]';
$mod = null;
$fisierCopie = null;
$forteaza = false;
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['--plan', '--aplica', '--restaureaza'], true)) {
        if ($mod !== null) {
            iesire('Alege un singur mod: --plan, --aplica sau --restaureaza.', 2);
        }
        $mod = substr($arg, 2);
    } elseif (str_starts_with($arg, '--fisier=')) {
        $fisierCopie = substr($arg, strlen('--fisier='));
    } elseif ($arg === '--forteaza') {
        $forteaza = true;
    } else {
        iesire("Argument necunoscut: {$arg}\n{$folosire}", 2);
    }
}
if ($mod === null) {
    iesire("{$folosire}\n--plan doar arată ce s-ar schimba.", 2);
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    iesire('Nu am putut deschide conexiunea la baza de date (vezi .env).', 1);
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ─────────────────────────────────────────────────────────────
// Baza e cea așteptată?
// ─────────────────────────────────────────────────────────────

/** Coloanele unui tabel (litere mici), sau [] dacă tabelul lipsește. */
function coloane(PDO $db, string $tabel): array
{
    try {
        $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tabel) . '`');
        return array_map(static fn (array $r): string => strtolower((string) $r['Field']), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable) {
        return [];
    }
}

$probleme = [];
foreach ([
    'settings' => ['key', 'value'],
    'pages' => ['id', 'slug', 'html_content', 'deleted_at'],
    'products' => ['id', 'sku', 'slug', 'name', 'brand', 'description', 'deleted_at'],
    'product_extra_fields' => ['id', 'field_key'],
    'product_extra_field_values' => ['product_id', 'field_id', 'value'],
    'seo_pages' => ['page_type', 'page_ref', 'title', 'description'],
] as $tabel => $necesare) {
    $are = coloane($db, $tabel);
    if ($are === []) {
        $probleme[] = "lipsește tabelul {$tabel}";
        continue;
    }
    foreach ($necesare as $coloana) {
        if (!in_array($coloana, $are, true)) {
            $probleme[] = "tabelul {$tabel} n-are coloana {$coloana}";
        }
    }
}
if ($probleme !== []) {
    iesire('Refuz: baza de date nu e cea așteptată (' . implode('; ', $probleme) . '). Nu am scris nimic.', 3);
}

/** Câmpurile pe care le poate atinge scriptul: [tabel, coloană] după felul cheii. */
const CAMPURI = [
    'pages.html_content' => ['pages', 'html_content'],
    'products.description' => ['products', 'description'],
    'products.name' => ['products', 'name'],
    'products.slug' => ['products', 'slug'],
    'product_extra_field_values.value' => ['product_extra_field_values', 'value'],
    'seo_pages.title' => ['seo_pages', 'title'],
    'seo_pages.description' => ['seo_pages', 'description'],
];

/**
 * Valoarea de acum a unui câmp, după cheia lui din copie:
 * ['camp' => 'products.slug', 'id' => 12] sau, pentru valorile din tab-uri,
 * ['camp' => 'product_extra_field_values.value', 'product_id' => 12, 'field_id' => 3],
 * pentru seo_pages ['camp' => 'seo_pages.title', 'page_type' => 'product', 'page_ref' => '12'].
 * ['exista' => bool, 'valoare' => ?string].
 */
function valoareCamp(PDO $db, array $cheie): array
{
    $camp = (string) ($cheie['camp'] ?? '');
    if (!isset(CAMPURI[$camp])) {
        throw new RuntimeException("câmp necunoscut: {$camp}");
    }
    [$tabel, $coloana] = CAMPURI[$camp];
    if ($tabel === 'product_extra_field_values') {
        $stmt = $db->prepare('SELECT `value` AS v FROM product_extra_field_values WHERE product_id = ? AND field_id = ?');
        $stmt->execute([(int) $cheie['product_id'], (int) $cheie['field_id']]);
    } elseif ($tabel === 'seo_pages') {
        $stmt = $db->prepare("SELECT `{$coloana}` AS v FROM seo_pages WHERE page_type = ? AND page_ref = ?");
        $stmt->execute([(string) $cheie['page_type'], (string) $cheie['page_ref']]);
    } else {
        $stmt = $db->prepare("SELECT `{$coloana}` AS v FROM `{$tabel}` WHERE id = ?");
        $stmt->execute([(int) $cheie['id']]);
    }
    $randuri = $stmt->fetchAll() ?: [];
    if (count($randuri) !== 1) {
        return ['exista' => false, 'valoare' => null];
    }
    return ['exista' => true, 'valoare' => $randuri[0]['v'] === null ? null : (string) $randuri[0]['v']];
}

function scrieCamp(PDO $db, array $cheie, ?string $valoare): void
{
    [$tabel, $coloana] = CAMPURI[(string) $cheie['camp']];
    if ($tabel === 'product_extra_field_values') {
        $stmt = $db->prepare('UPDATE product_extra_field_values SET `value` = ? WHERE product_id = ? AND field_id = ?');
        $stmt->execute([$valoare, (int) $cheie['product_id'], (int) $cheie['field_id']]);
    } elseif ($tabel === 'seo_pages') {
        $stmt = $db->prepare("UPDATE seo_pages SET `{$coloana}` = ? WHERE page_type = ? AND page_ref = ?");
        $stmt->execute([$valoare, (string) $cheie['page_type'], (string) $cheie['page_ref']]);
    } else {
        $stmt = $db->prepare("UPDATE `{$tabel}` SET `{$coloana}` = ? WHERE id = ?");
        $stmt->execute([$valoare, (int) $cheie['id']]);
    }
    if ($stmt->rowCount() > 1) {
        throw new RuntimeException('s-ar fi schimbat mai multe rânduri pentru ' . json_encode($cheie));
    }
}

/** Codurile (sku) produselor date, ca să verificăm că nu se schimbă. */
function coduri(PDO $db, array $iduri): array
{
    $iduri = array_values(array_unique(array_map('intval', $iduri)));
    if ($iduri === []) {
        return [];
    }
    $stmt = $db->prepare('SELECT id, sku FROM products WHERE id IN (' . implode(',', array_fill(0, count($iduri), '?')) . ') ORDER BY id');
    $stmt->execute($iduri);
    $rez = [];
    foreach ($stmt->fetchAll() ?: [] as $r) {
        $rez[(int) $r['id']] = $r['sku'] === null ? null : (string) $r['sku'];
    }
    return $rez;
}

function golesteCache(): void
{
    try {
        $n = ResponseCache::purgePageCache();
        scrie("Cache-ul de pagini a fost golit ({$n} pagini). Goliți și CDN-ul din hPanel („Purge all”).");
    } catch (Throwable) {
        scrie('Atenție: nu am putut goli cache-ul de pagini. Goliți-l din Admin → Setări magazin → Caching, apoi CDN-ul din hPanel.');
    }
    // Fluxul Merchant Center are adresele produselor: se reface la următoarea cerere.
    try {
        $flux = FluxGoogleMerchant::fisierCache();
        if (is_file($flux)) {
            @unlink($flux);
        }
    } catch (Throwable) {
    }
}

// ─────────────────────────────────────────────────────────────
// Restaurarea (nu are nevoie de fișierul cu date)
// ─────────────────────────────────────────────────────────────

if ($mod === 'restaureaza') {
    if ($fisierCopie === null) {
        $copii = glob(DOSAR_COPII . '/' . PREFIX_COPIE . '*.json') ?: [];
        sort($copii);
        $fisierCopie = $copii === [] ? null : end($copii);
        if ($fisierCopie === null) {
            iesire('Nu există nicio copie ' . PREFIX_COPIE . '*.json în storage/. Nu am ce restaura.', 1);
        }
    }
    $copie = json_decode((string) @file_get_contents($fisierCopie), true);
    if (!is_array($copie) || ($copie['facut_de'] ?? '') !== 'scripts/seo-continut.php' || !is_array($copie['campuri'] ?? null)) {
        iesire("Refuz: {$fisierCopie} nu e o copie făcută de acest script.", 3);
    }
    $bazaActuala = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    if ((string) ($copie['baza'] ?? '') !== $bazaActuala) {
        iesire("Refuz: copia e din baza „{$copie['baza']}”, iar acum sunt conectat la „{$bazaActuala}”.", 3);
    }
    scrie('Restaurez din ' . basename($fisierCopie) . ' (făcută la ' . ($copie['facut_la'] ?? '?') . ').');
    $operatii = [];
    $sarite = 0;
    $produse = [];
    foreach ($copie['campuri'] as $intrare) {
        $cheie = (array) ($intrare['cheie'] ?? []);
        $eticheta = (string) ($intrare['eticheta'] ?? '?');
        $acum = valoareCamp($db, $cheie);
        $vechi = $intrare['vechi'] ?? null;
        $scris = $intrare['scris'] ?? null;
        if (!$acum['exista']) {
            scrie("  {$eticheta}: SĂRIT — rândul nu mai există");
            $sarite++;
            continue;
        }
        if ($acum['valoare'] === $vechi) {
            scrie("  {$eticheta}: deja ca înainte");
            continue;
        }
        if ($acum['valoare'] !== $scris && !$forteaza) {
            scrie("  {$eticheta}: SĂRIT — a fost modificat după aplicare; folosiți --forteaza ca să-l suprascrieți");
            $sarite++;
            continue;
        }
        if (($cheie['camp'] ?? '') === 'products.slug') {
            // Adresa veche nu trebuie să fie între timp a altui produs.
            $stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE LOWER(slug) = LOWER(?) AND id <> ?');
            $stmt->execute([(string) $vechi, (int) $cheie['id']]);
            if ((int) $stmt->fetchColumn() > 0) {
                scrie("  {$eticheta}: SĂRIT — adresa veche e acum a altui produs");
                $sarite++;
                continue;
            }
        }
        if (isset($cheie['id']) && str_starts_with((string) $cheie['camp'], 'products.')) {
            $produse[] = (int) $cheie['id'];
        }
        $operatii[] = [$cheie, $vechi];
        scrie("  {$eticheta}: " . arata($acum['valoare'], 160) . ' → ' . arata($vechi, 160));
    }
    if ($operatii === []) {
        iesire($sarite > 0 ? "Nimic restaurat ({$sarite} sărite)." : 'Nimic de restaurat: valorile sunt deja cele dinainte.', 0);
    }
    $coduriInainte = coduri($db, $produse);
    $db->beginTransaction();
    try {
        foreach ($operatii as [$cheie, $vechi]) {
            scrieCamp($db, $cheie, $vechi);
        }
        if (coduri($db, $produse) !== $coduriInainte) {
            throw new RuntimeException('codul (sku) unui produs s-ar fi schimbat');
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        iesire('Eroare la restaurare; nu s-a schimbat nimic: ' . $e->getMessage(), 1);
    }
    scrie('Restaurat: ' . count($operatii) . ($sarite > 0 ? ", sărite: {$sarite}" : '') . '.');
    golesteCache();
    exit(0);
}

// ─────────────────────────────────────────────────────────────
// Fișierul cu date
// ─────────────────────────────────────────────────────────────

$brut = @file_get_contents(FISIER_DATE);
$date = is_string($brut) ? json_decode($brut, true) : null;
if (!is_array($date) || (int) ($date['versiune'] ?? 0) !== 1 || !is_array($date['aircall'] ?? null) || !is_array($date['inlocuiri'] ?? null)
    || !array_is_list($date['aircall']) || !array_is_list($date['inlocuiri'])) {
    iesire('Refuz: nu pot citi ' . FISIER_DATE . ' (seo-continut.json din dosarul site-ului, SITE_DATE_DOSAR; versiunea 1, cu listele „aircall” și „inlocuiri”).', 3);
}
$asteptat = $date['asteptat'] ?? null;
if ($asteptat !== null && (!is_array($asteptat) || array_diff_key($asteptat, ['aircall' => 0, 'inlocuiri' => 0]) !== [])) {
    iesire('Refuz: „asteptat” din seo-continut.json are alte chei decât aircall, inlocuiri.', 3);
}
foreach ((array) $asteptat as $lista => $cate) {
    if ((int) $cate !== count($date[$lista])) {
        iesire('Refuz: seo-continut.json trebuie să aibă ' . (int) ($asteptat['aircall'] ?? count($date['aircall'])) . ' produse AIRCALL și '
            . (int) ($asteptat['inlocuiri'] ?? count($date['inlocuiri'])) . ' înlocuiri (are ' . count($date['aircall']) . ' și '
            . count($date['inlocuiri']) . ').', 3);
    }
}
// Numele magazinului din titluri ales de site (la Bioscem „Bioscem”, livrarea 2): un titlu scris trebuie să rămână la fel cu oricare.
$numeMagazinSite = trim((string) ($date['nume_magazin'] ?? ''));
$iduriVazute = [];
foreach (array_merge($date['aircall'], $date['inlocuiri']) as $i => $rand) {
    $id = (string) ($rand['id'] ?? '');
    if ($id === '' || isset($iduriVazute[$id])) {
        iesire("Refuz: rândul {$i} din seo-continut.json n-are id sau îl repetă.", 3);
    }
    $iduriVazute[$id] = true;
}
// Diacriticele corecte (ș, ț cu virgulă), nu cele cu sedilă, în tot ce scriem.
$texteNoi = [];
foreach ($date['aircall'] as $a) {
    foreach (['slug_vechi', 'slug_nou', 'nume_vechi', 'nume_nou', 'sku', 'seo_titlu_vechi', 'seo_titlu_nou', 'seo_descriere_veche', 'seo_descriere_noua'] as $k) {
        if (!is_string($a[$k] ?? null) || trim((string) $a[$k]) === '') {
            iesire("Refuz: {$a['id']} n-are {$k}.", 3);
        }
    }
    if (preg_match('/^[a-z0-9-]+$/', (string) $a['slug_nou']) !== 1 || strtolower((string) $a['slug_vechi']) !== (string) $a['slug_nou']) {
        iesire("Refuz: {$a['id']}: adresa nouă trebuie să fie adresa veche cu litere mici.", 3);
    }
    if (mb_strlen((string) $a['seo_titlu_nou']) > SeoMeta::LUNGIME_TITLU || mb_strlen((string) $a['seo_descriere_noua']) > 160) {
        iesire("Refuz: {$a['id']}: titlul (≤ 60) sau descrierea (≤ 160) e prea lungă.", 3);
    }
    $texteNoi[] = $a['nume_nou'] . $a['seo_titlu_nou'] . $a['seo_descriere_noua'] . ($a['descriere']['nou'] ?? '');
}
foreach ($date['inlocuiri'] as $r) {
    if (!is_string($r['vechi'] ?? null) || (string) $r['vechi'] === '' || !is_string($r['nou'] ?? null) || (int) ($r['aparitii'] ?? 0) < 1) {
        iesire("Refuz: rândul {$r['id']} din seo-continut.json e incomplet.", 3);
    }
    $texteNoi[] = (string) $r['nou'];
}
if (preg_match('/[\x{015E}\x{015F}\x{0162}\x{0163}]/u', implode('', $texteNoi)) === 1) {
    iesire('Refuz: un text nou are ş/ţ cu sedilă.', 3);
}

// ─────────────────────────────────────────────────────────────
// Ce s-ar schimba
// ─────────────────────────────────────────────────────────────

$setari = Settings::all($db);
$magazinAcum = SeoMeta::numeMagazin($setari);
$numeVerificate = array_values(array_unique(array_filter([$magazinAcum, $numeMagazinSite, SeoMeta::numeMagazinImplicit()],
    static fn (string $n): bool => $n !== '')));
$probleme = [];

/**
 * Câmpurile atinse, în ordinea în care apar: cheie (json) => ['cheie', 'eticheta', 'vechi' (valoarea
 * de acum), 'nou' (după toate rândurile), 'randuri' (ce rânduri îl schimbă)].
 */
$campuri = [];
$adaugaCamp = static function (array $cheie, string $eticheta, ?string $valoare) use (&$campuri): string {
    $k = json_encode($cheie);
    if (!isset($campuri[$k])) {
        $campuri[$k] = ['cheie' => $cheie, 'eticheta' => $eticheta, 'vechi' => $valoare, 'nou' => $valoare, 'randuri' => []];
    }
    return (string) $k;
};
/** Ce face un rând (înlocuire în text) pe valoarea de acum: [stare, valoare nouă]. stare: aplica / deja / problema. */
$aplicaRand = static function (?string $valoare, string $vechi, string $nou, int $aparitii): array {
    $text = (string) $valoare;
    $nVechi = substr_count($text, $vechi);
    if ($nou !== '') {
        if (substr_count($text, $nou) >= 1 && ($nVechi === 0 || str_contains($nou, $vechi))) {
            return ['deja', $text];
        }
    } elseif ($nVechi === 0) {
        return ['deja', $text];
    }
    if ($nVechi !== $aparitii) {
        return ['problema', "textul vechi apare de {$nVechi} ori, nu de {$aparitii}"];
    }
    return ['aplica', str_replace($vechi, $nou, $text)];
};
$iduriProduse = [];
$rezumat = [];

// D5: căștile AIRCALL (întâi adresa și numele, apoi restul, ca în listă).
foreach ($date['aircall'] as $a) {
    $stmt = $db->prepare('SELECT id, sku, slug, name, brand, description FROM products WHERE sku = ? AND deleted_at IS NULL');
    $stmt->execute([(string) $a['sku']]);
    $randuri = $stmt->fetchAll() ?: [];
    if (count($randuri) !== 1) {
        $probleme[] = "{$a['id']}: produsul cu codul {$a['sku']} " . (count($randuri) === 0 ? 'nu există' : 'apare de mai multe ori');
        continue;
    }
    $p = $randuri[0];
    $pid = (int) $p['id'];
    $iduriProduse[] = $pid;
    if ((string) $p['sku'] !== (string) $a['sku']) {
        $probleme[] = "{$a['id']}: codul produsului e „{$p['sku']}”, nu „{$a['sku']}”";
        continue;
    }
    if (!in_array((string) $p['slug'], [(string) $a['slug_vechi'], (string) $a['slug_nou']], true)) {
        $probleme[] = "{$a['id']}: adresa produsului {$a['sku']} e „{$p['slug']}”, nu „{$a['slug_vechi']}” (sau deja „{$a['slug_nou']}”)";
        continue;
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE LOWER(slug) = LOWER(?) AND id <> ?');
    $stmt->execute([(string) $a['slug_nou'], $pid]);
    if ((int) $stmt->fetchColumn() > 0) {
        $probleme[] = "{$a['id']}: adresa nouă „{$a['slug_nou']}” e deja a altui produs";
        continue;
    }
    if (!in_array((string) $p['name'], [(string) $a['nume_vechi'], (string) $a['nume_nou']], true)) {
        $probleme[] = "{$a['id']}: numele produsului e „{$p['name']}”, nu „{$a['nume_vechi']}” (schimbat de mână?)";
        continue;
    }
    $stmt = $db->prepare("SELECT title, description FROM seo_pages WHERE page_type = 'product' AND page_ref = ?");
    $stmt->execute([(string) $pid]);
    $seo = $stmt->fetchAll() ?: [];
    if (count($seo) !== 1) {
        $probleme[] = "{$a['id']}: produsul n-are rândul SEO așteptat (titlul de azi „{$a['seo_titlu_vechi']}”)";
        continue;
    }
    $titluSalvat = $seo[0]['title'] === null ? null : (string) $seo[0]['title'];
    $descriereSalvata = $seo[0]['description'] === null ? null : (string) $seo[0]['description'];
    $titluAfisat = SeoMeta::titluProdusDetaliat((string) $p['name'], (string) ($p['brand'] ?? ''), (string) $titluSalvat, $magazinAcum);
    if ($titluSalvat !== (string) $a['seo_titlu_nou']
        && SeoMeta::curataTitlu(SeoMeta::text((string) $titluSalvat)) !== (string) $a['seo_titlu_vechi']
        && $titluAfisat['titlu'] !== (string) $a['seo_titlu_vechi']) {
        $probleme[] = "{$a['id']}: titlul Google salvat e " . arata($titluSalvat) . ", nu „{$a['seo_titlu_vechi']}” (schimbat de mână?)";
        continue;
    }
    // Descrierea de azi („culoare: alb”) poate fi și cea făcută din descrierea scurtă, fără una salvată.
    if (!in_array($descriereSalvata, [(string) $a['seo_descriere_veche'], (string) $a['seo_descriere_noua'], null, ''], true)) {
        $probleme[] = "{$a['id']}: descrierea Google salvată e " . arata($descriereSalvata) . ", nu „{$a['seo_descriere_veche']}” (schimbată de mână?)";
        continue;
    }
    // Titlul nou trebuie să apară în Google exact cum e scris, cu oricare nume al magazinului.
    foreach ($numeVerificate as $magazin) {
        $afisat = SeoMeta::titluProdusDetaliat((string) $a['nume_nou'], (string) ($p['brand'] ?? ''), (string) $a['seo_titlu_nou'], $magazin);
        if ($afisat['sursa'] !== 'scris' || $afisat['titlu'] !== (string) $a['seo_titlu_nou']) {
            $probleme[] = "{$a['id']}: titlul nou s-ar afișa „{$afisat['titlu']}”, nu cum e scris (magazin „{$magazin}”)";
        }
    }
    $eticheta = "produsul {$a['sku']}";
    foreach ([
        ['products.slug', ['camp' => 'products.slug', 'id' => $pid], (string) $p['slug'], (string) $a['slug_nou'], 'adresa'],
        ['products.name', ['camp' => 'products.name', 'id' => $pid], (string) $p['name'], (string) $a['nume_nou'], 'numele'],
        ['seo_pages.title', ['camp' => 'seo_pages.title', 'page_type' => 'product', 'page_ref' => (string) $pid], $titluSalvat, (string) $a['seo_titlu_nou'], 'titlul Google'],
        ['seo_pages.description', ['camp' => 'seo_pages.description', 'page_type' => 'product', 'page_ref' => (string) $pid], $descriereSalvata, (string) $a['seo_descriere_noua'], 'descrierea Google'],
    ] as [, $cheie, $acum, $nou, $ce]) {
        $k = $adaugaCamp($cheie, "{$eticheta}, {$ce}", $acum);
        $campuri[$k]['nou'] = $nou;
        $campuri[$k]['randuri'][] = [$a['id'], $acum === $nou ? 'deja' : 'aplica'];
    }
    $d = (array) ($a['descriere'] ?? []);
    if (($d['camp'] ?? '') !== 'description') {
        $probleme[] = "{$a['id']}: descrierea trebuie să fie în products.description";
        continue;
    }
    $k = $adaugaCamp(['camp' => 'products.description', 'id' => $pid], "{$eticheta}, descrierea", $p['description'] === null ? null : (string) $p['description']);
    [$stare, $rezultat] = $aplicaRand($campuri[$k]['nou'], (string) $d['vechi'], (string) $d['nou'], (int) ($d['aparitii'] ?? 1));
    if ($stare === 'problema') {
        $probleme[] = "{$a['id']}: descrierea produsului: {$rezultat}";
        continue;
    }
    $campuri[$k]['nou'] = $rezultat;
    $campuri[$k]['randuri'][] = [$a['id'], $stare];
    $rezumat[$a['id']] = $stare;
}

// A9: înlocuirile din texte.
foreach ($date['inlocuiri'] as $r) {
    $idR = (string) $r['id'];
    $tabel = (string) ($r['tabel'] ?? '');
    $cheieDate = (array) ($r['cheie'] ?? []);
    if ($tabel === 'pages' && ($r['camp'] ?? '') === 'html_content') {
        $stmt = $db->prepare('SELECT id, html_content AS v FROM pages WHERE LOWER(slug) = LOWER(?) AND deleted_at IS NULL');
        $stmt->execute([(string) ($cheieDate['slug'] ?? '')]);
        $randuri = $stmt->fetchAll() ?: [];
        if (count($randuri) !== 1) {
            $probleme[] = "{$idR}: pagina /{$cheieDate['slug']} " . (count($randuri) === 0 ? 'nu există' : 'apare de mai multe ori');
            continue;
        }
        $cheie = ['camp' => 'pages.html_content', 'id' => (int) $randuri[0]['id']];
        $eticheta = "pagina /{$cheieDate['slug']}";
    } elseif ($tabel === 'products' && ($r['camp'] ?? '') === 'description') {
        $stmt = $db->prepare('SELECT id, description AS v FROM products WHERE LOWER(slug) = LOWER(?) AND deleted_at IS NULL');
        $stmt->execute([(string) ($cheieDate['slug'] ?? '')]);
        $randuri = $stmt->fetchAll() ?: [];
        if (count($randuri) !== 1) {
            $probleme[] = "{$idR}: produsul {$cheieDate['slug']} " . (count($randuri) === 0 ? 'nu există' : 'apare de mai multe ori');
            continue;
        }
        $cheie = ['camp' => 'products.description', 'id' => (int) $randuri[0]['id']];
        $eticheta = "produsul {$cheieDate['slug']}, descrierea";
        $iduriProduse[] = (int) $randuri[0]['id'];
    } elseif ($tabel === 'product_extra_field_values' && ($r['camp'] ?? '') === 'value') {
        $stmt = $db->prepare('SELECT id FROM products WHERE LOWER(slug) = LOWER(?) AND deleted_at IS NULL');
        $stmt->execute([(string) ($cheieDate['produs_slug'] ?? '')]);
        $prod = $stmt->fetchAll() ?: [];
        $stmt = $db->prepare('SELECT id FROM product_extra_fields WHERE LOWER(field_key) = LOWER(?)');
        $stmt->execute([(string) ($cheieDate['field_key'] ?? '')]);
        $campDb = $stmt->fetchAll() ?: [];
        if (count($prod) !== 1 || count($campDb) !== 1) {
            $probleme[] = "{$idR}: produsul {$cheieDate['produs_slug']} sau câmpul {$cheieDate['field_key']} nu există (sau nu e unic)";
            continue;
        }
        $cheie = ['camp' => 'product_extra_field_values.value', 'product_id' => (int) $prod[0]['id'], 'field_id' => (int) $campDb[0]['id']];
        $eticheta = "produsul {$cheieDate['produs_slug']}, tab-ul {$cheieDate['field_key']}";
    } else {
        $probleme[] = "{$idR}: tabel/câmp neașteptat ({$tabel}.{$r['camp']})";
        continue;
    }
    $acum = valoareCamp($db, $cheie);
    if (!$acum['exista']) {
        $probleme[] = "{$idR}: {$eticheta} nu are valoare salvată";
        continue;
    }
    $k = $adaugaCamp($cheie, $eticheta, $acum['valoare']);
    [$stare, $rezultat] = $aplicaRand($campuri[$k]['nou'], (string) $r['vechi'], (string) $r['nou'], (int) $r['aparitii']);
    if ($stare === 'problema') {
        $probleme[] = "{$idR}: {$eticheta}: {$rezultat}";
        continue;
    }
    $campuri[$k]['nou'] = $rezultat;
    $campuri[$k]['randuri'][] = [$idR, $stare];
    $rezumat[$idR] = $stare;
}

if ($probleme !== []) {
    iesire("Refuz: baza de date nu e cea așteptată sau un text nu se potrivește:\n  - " . implode("\n  - ", $probleme) . "\nNu am scris nimic.", 3);
}

// ─────────────────────────────────────────────────────────────
// Afișarea
// ─────────────────────────────────────────────────────────────

$deSchimbat = array_values(array_filter($campuri, static fn (array $c): bool => $c['nou'] !== $c['vechi']));
$randuriAplicate = 0;
foreach ($campuri as $c) {
    foreach ($c['randuri'] as [, $stare]) {
        $randuriAplicate += $stare === 'aplica' ? 1 : 0;
    }
}
scrie(($mod === 'plan' ? 'PLAN (nu scriu nimic)' : 'APLIC') . ': ' . count($campuri) . ' câmpuri din ' . (count($date['aircall']) . ' produse AIRCALL și ' . count($date['inlocuiri']) . ' înlocuiri')
    . ', ' . count($deSchimbat) . ' de schimbat (' . $randuriAplicate . ' rânduri).');
$randDupaId = [];
foreach (array_merge($date['aircall'], $date['inlocuiri']) as $r) {
    $randDupaId[(string) $r['id']] = $r;
}
foreach ($campuri as $c) {
    $starea = implode(', ', array_map(static fn (array $x): string => $x[0] . ($x[1] === 'deja' ? ' (deja aplicat)' : ''), $c['randuri']));
    if ($c['nou'] === $c['vechi']) {
        scrie("  {$c['eticheta']}: neschimbat — {$starea}");
        continue;
    }
    scrie("  {$c['eticheta']} — {$starea}:");
    $camp = (string) $c['cheie']['camp'];
    if (in_array($camp, ['products.slug', 'products.name', 'seo_pages.title', 'seo_pages.description'], true)) {
        scrie('      ' . arata($c['vechi']) . ' → ' . arata($c['nou']));
        continue;
    }
    foreach ($c['randuri'] as [$idR, $stare]) {
        if ($stare !== 'aplica') {
            continue;
        }
        $r = $randDupaId[$idR];
        $vechi = (string) (isset($r['descriere']) ? $r['descriere']['vechi'] : $r['vechi']);
        $nou = (string) (isset($r['descriere']) ? $r['descriere']['nou'] : $r['nou']);
        scrie("      {$idR}: " . context((string) $c['vechi'], $vechi));
        scrie('         → ' . ($nou === '' ? '(scos)' : arata($nou, 400)));
    }
}
if ($mod === 'plan') {
    exit(0);
}
if ($deSchimbat === []) {
    iesire('Nimic de schimbat: conținutul e deja aplicat. Nu am făcut o copie nouă.', 0);
}

// ─────────────────────────────────────────────────────────────
// Aplicarea: întâi copia, apoi totul într-o tranzacție
// ─────────────────────────────────────────────────────────────

$copie = [
    'facut_de' => 'scripts/seo-continut.php',
    'facut_la' => date('Y-m-d H:i:s'),
    'baza' => (string) $db->query('SELECT DATABASE()')->fetchColumn(),
    'fisier_date_sha1' => sha1((string) $brut),
    'campuri' => array_map(static fn (array $c): array => [
        'eticheta' => $c['eticheta'],
        'cheie' => $c['cheie'],
        'randuri' => array_map(static fn (array $x): string => (string) $x[0], $c['randuri']),
        'vechi' => $c['vechi'],
        'scris' => $c['nou'],
    ], $deSchimbat),
];
if (!is_dir(DOSAR_COPII)) {
    @mkdir(DOSAR_COPII, 0775, true);
}
$fisier = DOSAR_COPII . '/' . PREFIX_COPIE . date('Ymd-His') . '.json';
for ($n = 2; is_file($fisier); $n++) {
    $fisier = DOSAR_COPII . '/' . PREFIX_COPIE . date('Ymd-His') . '-' . $n . '.json';
}
$json = json_encode($copie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || @file_put_contents($fisier, $json . "\n") !== strlen($json) + 1) {
    iesire("Nu am putut scrie copia {$fisier}. Nu am schimbat nimic.", 1);
}
scrie('Copia valorilor de acum: storage/' . basename($fisier));

$coduriInainte = coduri($db, $iduriProduse);
$db->beginTransaction();
try {
    foreach ($deSchimbat as $c) {
        // Nimic schimbat între --plan și aici (altă sesiune, adminul).
        $acum = valoareCamp($db, $c['cheie']);
        if (!$acum['exista'] || $acum['valoare'] !== $c['vechi']) {
            throw new RuntimeException("{$c['eticheta']} s-a schimbat chiar acum; rulați din nou");
        }
        scrieCamp($db, $c['cheie'], $c['nou']);
    }
    if (coduri($db, $iduriProduse) !== $coduriInainte) {
        throw new RuntimeException('codul (sku) unui produs s-ar fi schimbat');
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    iesire('Eroare la scriere; nu s-a schimbat nimic (copia rămâne, nu strică): ' . $e->getMessage(), 1);
}
scrie('Aplicat: ' . count($deSchimbat) . ' câmpuri. Revenire: php scripts/seo-continut.php --restaureaza');
golesteCache();
