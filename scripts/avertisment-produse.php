<?php

declare(strict_types=1);

/**
 * Avertismentele pe produs ale unui site (ex. „ATENȚIE – PRODUS REFRIGERAT”),
 * scrise o dată, din fișierul site-ului: `avertismente-produse.json` din dosarul
 * site-ului (SITE_DATE_DOSAR; pachetul din site-uri/<site>/). La Bioscem
 * (site-uri/bioscem/avertismente-produse.json): cele 3 produse refrigerate,
 * Vivomixx Neo 115 și 460, Actinovo Vitamina C lipozomală, cu textele aprobate de
 * proprietar pe 20 oct 2026. Formatul:
 *
 *   {"titlu": "…", "eticheta": "…",
 *    "paragrafe": ["… {produs} …", "…"],          // „{produs}” devine numele de mai jos
 *    "produse": {"<slug>": "<numele din text>", …}}
 *
 * Portat din Bioscem `0f5f568`: aceeași logică, cu textele și produsele mutate din
 * script în fișierul site-ului.
 *
 * Unde ajung: aceleași trei câmpuri din Admin → Produse → „Avertisment la coș și
 * la finalizarea comenzii” (tabelul product_warnings; vezi
 * App\Support\AvertismentProduse). Nu se schimbă nimic altceva la produse.
 *
 * Rulare, prin SSH, din folderul site-ului:
 *   php scripts/avertisment-produse.php --plan          arată „acum → nou”; nu scrie nimic
 *   php scripts/avertisment-produse.php --aplica        scrie; înainte salvează valorile de acum
 *                                                       în storage/avertisment-produse-copie-<data>.json
 *   php scripts/avertisment-produse.php --restaureaza   pune înapoi valorile din cea mai nouă copie
 *        [--fisier=storage/avertisment-produse-copie-….json] [--forteaza]
 *
 * Siguranță:
 *  - refuză (cod ≠ 0, nimic scris) dacă lipsește un produs din listă sau dacă
 *    un produs are deja alt avertisment completat (doar cu --forteaza îl
 *    înlocuiește; și atunci vechiul text rămâne în copie);
 *  - se poate rula de oricâte ori: a doua oară spune „Nimic de schimbat” și nu
 *    mai face o copie nouă;
 *  - restaurarea nu calcă peste ce s-a modificat de mână între timp (arată
 *    „SĂRIT”; doar cu --forteaza);
 *  - scrie totul într-o singură tranzacție (totul sau nimic), apoi golește
 *    cache-ul de pagini. CDN-ul (hPanel) se golește de mână;
 *  - afișează doar texte de pe site, nicio dată personală.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\AvertismentProduse;
use App\Support\Database;
use App\Support\DateSite;
use App\Support\Paths;
use App\Support\ResponseCache;

// Copiile valorilor de dinainte stau în storage/ al site-ului (Paths).
define('DOSAR_COPII', Paths::stocare());
const PREFIX_COPIE = 'avertisment-produse-copie-';

/**
 * Fișierul site-ului, citit și verificat: titlul, eticheta bifei, paragrafele
 * (texte nevide) și produsele (slug → numele pus în locul lui „{produs}”).
 *
 * @return array{titlu: string, eticheta: string, paragrafe: list<string>, produse: array<string, string>}
 */
function dateSite(): array
{
    $fisier = DateSite::dosar() . '/avertismente-produse.json';
    $date = json_decode((string) @file_get_contents($fisier), true);
    if (!is_array($date)) {
        iesire("Refuz: nu pot citi {$fisier} (avertismente-produse.json din dosarul site-ului, SITE_DATE_DOSAR).", 3);
    }
    $paragrafe = $date['paragrafe'] ?? null;
    $produse = $date['produse'] ?? null;
    if (!is_string($date['titlu'] ?? null) || !is_string($date['eticheta'] ?? null)
        || !is_array($paragrafe) || $paragrafe === [] || !array_is_list($paragrafe)
        || !is_array($produse) || $produse === [] || array_is_list($produse)) {
        iesire("Refuz: {$fisier} trebuie să aibă titlu, eticheta, paragrafe (listă nevidă) și produse (slug → nume).", 3);
    }
    foreach ($paragrafe as $paragraf) {
        if (!is_string($paragraf) || trim($paragraf) === '') {
            iesire("Refuz: un paragraf din {$fisier} e gol sau nu e text.", 3);
        }
    }
    foreach ($produse as $slug => $nume) {
        if (!is_string($slug) || trim($slug) === '' || !is_string($nume) || trim($nume) === '') {
            iesire("Refuz: produsele din {$fisier} trebuie să fie „slug” → „nume”, ambele nevide.", 3);
        }
    }
    return ['titlu' => $date['titlu'], 'eticheta' => $date['eticheta'], 'paragrafe' => $paragrafe, 'produse' => $produse];
}

/** Textul unui produs: paragrafele despărțite de un rând gol (ca în admin). */
function textPentru(array $date, string $produs): string
{
    return str_replace('{produs}', $produs, implode("\n\n", $date['paragrafe']));
}

/** slug → textul lui. */
function produseDinPlan(array $date): array
{
    $texte = [];
    foreach ($date['produse'] as $slug => $nume) {
        $texte[$slug] = textPentru($date, $nume);
    }
    return $texte;
}

function iesire(string $mesaj, int $cod): never
{
    fwrite($cod === 0 ? STDOUT : STDERR, $mesaj . "\n");
    exit($cod);
}

function scrie(string $linie = ''): void
{
    fwrite(STDOUT, $linie . "\n");
}

/** „«text»” sau „(gol)”, pentru afișare; rândurile noi se văd ca „⏎”. */
function arata(?string $text): string
{
    return ($text === null || trim($text) === '') ? '(gol)' : '«' . str_replace("\n", '⏎', $text) . '»';
}

// ─────────────────────────────────────────────────────────────
// Argumente
// ─────────────────────────────────────────────────────────────

$folosire = 'Folosire: php scripts/avertisment-produse.php --plan | --aplica | --restaureaza [--fisier=…] [--forteaza]';
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
foreach (['settings' => ['key', 'value'], 'products' => ['id', 'slug', 'name', 'deleted_at']] as $tabel => $necesare) {
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

/** Ce e salvat acum pentru un produs: ['exista' => bool, 'titlu', 'text', 'eticheta']. */
function valoareaDeAcum(PDO $db, int $productId): array
{
    try {
        $stmt = $db->prepare('SELECT title, body, checkbox_label FROM product_warnings WHERE product_id = ?');
        $stmt->execute([$productId]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        // Tabelul încă nu există: niciun avertisment.
        $rand = null;
    }
    return [
        'exista' => is_array($rand),
        'titlu' => is_array($rand) ? (string) $rand['title'] : '',
        'text' => is_array($rand) ? (string) ($rand['body'] ?? '') : '',
        'eticheta' => is_array($rand) ? (string) $rand['checkbox_label'] : '',
    ];
}

function acelasi(array $a, array $b): bool
{
    return [$a['titlu'], $a['text'], $a['eticheta']] === [$b['titlu'], $b['text'], $b['eticheta']];
}

function golesteCache(): void
{
    try {
        $n = ResponseCache::purgePageCache();
        scrie("Cache-ul de pagini a fost golit ({$n} pagini). Goliți și CDN-ul din hPanel („Purge all”).");
    } catch (Throwable) {
        scrie('Atenție: nu am putut goli cache-ul de pagini. Goliți-l din Admin → Setări magazin → Caching, apoi CDN-ul din hPanel.');
    }
}

/** Scrie valorile unui produs (toate goale = rândul dispare), în tranzacția apelantului. */
function scrieValori(PDO $db, int $productId, array $valori): void
{
    if ($valori['titlu'] === '' && $valori['text'] === '' && $valori['eticheta'] === '') {
        $db->prepare('DELETE FROM product_warnings WHERE product_id = ?')->execute([$productId]);
        return;
    }
    $db->prepare(
        'INSERT INTO product_warnings (product_id, title, body, checkbox_label, updated_at) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body),
             checkbox_label = VALUES(checkbox_label), updated_at = VALUES(updated_at)'
    )->execute([$productId, $valori['titlu'], $valori['text'], $valori['eticheta'], date('Y-m-d H:i:s')]);
}

// ─────────────────────────────────────────────────────────────
// Restaurarea
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
    if (!is_array($copie) || ($copie['facut_de'] ?? '') !== 'scripts/avertisment-produse.php' || !is_array($copie['intrari'] ?? null)) {
        iesire("Refuz: {$fisierCopie} nu e o copie făcută de acest script.", 3);
    }
    $bazaActuala = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    if ((string) ($copie['baza'] ?? '') !== $bazaActuala) {
        iesire("Refuz: copia e din baza „{$copie['baza']}”, iar acum sunt conectat la „{$bazaActuala}”.", 3);
    }
    scrie('Restaurez din ' . basename($fisierCopie) . ' (făcută la ' . ($copie['facut_la'] ?? '?') . ').');
    $operatii = [];
    $sarite = 0;
    foreach ($copie['intrari'] as $intrare) {
        $productId = (int) ($intrare['product_id'] ?? 0);
        $slug = (string) ($intrare['slug'] ?? '?');
        $vechi = (array) ($intrare['vechi'] ?? []);
        $scris = (array) ($intrare['scris'] ?? []);
        $acum = valoareaDeAcum($db, $productId);
        if (acelasi($acum, $vechi) && $acum['exista'] === (bool) ($vechi['exista'] ?? false)) {
            scrie("  {$slug}: deja ca înainte");
            continue;
        }
        if (!acelasi($acum, $scris) && !$forteaza) {
            scrie("  {$slug}: SĂRIT — a fost modificat după aplicare (acum titlu " . arata($acum['titlu']) . '); folosiți --forteaza ca să-l suprascrieți');
            $sarite++;
            continue;
        }
        $operatii[] = [$productId, $vechi];
        scrie("  {$slug}: titlu " . arata($acum['titlu']) . ' → ' . arata($vechi['titlu'] ?? '')
            . '; text ' . (trim($acum['text']) === '' ? '(gol)' : mb_strlen($acum['text']) . ' caractere')
            . ' → ' . (trim((string) ($vechi['text'] ?? '')) === '' ? '(gol)' : mb_strlen((string) $vechi['text']) . ' caractere'));
    }
    if ($operatii === []) {
        iesire($sarite > 0 ? "Nimic restaurat ({$sarite} sărite)." : 'Nimic de restaurat: valorile sunt deja cele dinainte.', 0);
    }
    $db->beginTransaction();
    try {
        foreach ($operatii as [$productId, $vechi]) {
            scrieValori($db, $productId, [
                'titlu' => (string) ($vechi['titlu'] ?? ''),
                'text' => (string) ($vechi['text'] ?? ''),
                'eticheta' => (string) ($vechi['eticheta'] ?? ''),
            ]);
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
// Ce s-ar schimba
// ─────────────────────────────────────────────────────────────

$dateAvertisment = dateSite();
$intrari = [];
$probleme = [];
$conflicte = [];
foreach (produseDinPlan($dateAvertisment) as $slug => $text) {
    $nou = ['titlu' => $dateAvertisment['titlu'], 'text' => $text, 'eticheta' => $dateAvertisment['eticheta']];
    // Textele se scriu exact cum le-ar salva și adminul.
    foreach (['titlu' => AvertismentProduse::MAX_TITLU, 'text' => AvertismentProduse::MAX_TEXT, 'eticheta' => AvertismentProduse::MAX_ETICHETA] as $camp => $maxim) {
        if (AvertismentProduse::curata($nou[$camp], $maxim) !== $nou[$camp]) {
            iesire("Refuz: {$camp} pentru {$slug} nu se poate salva exact cum e scris.", 3);
        }
    }
    if (preg_match('/[\x{015E}\x{015F}\x{0162}\x{0163}]/u', implode('', $nou)) === 1) {
        iesire("Refuz: textul pentru {$slug} are ş/ţ cu sedilă.", 3);
    }
    $stmt = $db->prepare('SELECT id, name FROM products WHERE slug = ? AND deleted_at IS NULL');
    $stmt->execute([$slug]);
    $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($randuri) !== 1) {
        $probleme[] = 'produsul ' . $slug . (count($randuri) === 0 ? ' nu există' : ' apare de mai multe ori');
        continue;
    }
    $productId = (int) $randuri[0]['id'];
    $acum = valoareaDeAcum($db, $productId);
    $completat = trim($acum['titlu']) !== '' || trim($acum['text']) !== '' || trim($acum['eticheta']) !== '';
    if ($completat && !acelasi($acum, $nou)) {
        $conflicte[] = "{$slug} are deja alt avertisment: titlu " . arata($acum['titlu']) . ', text ' . arata(mb_substr($acum['text'], 0, 80) . (mb_strlen($acum['text']) > 80 ? '…' : ''));
    }
    $intrari[] = [
        'slug' => $slug,
        'product_id' => $productId,
        'nume' => (string) $randuri[0]['name'],
        'acum' => $acum,
        'nou' => $nou,
        'schimba' => !acelasi($acum, $nou),
    ];
}
if ($probleme !== []) {
    iesire("Refuz: baza de date nu e cea așteptată:\n  - " . implode("\n  - ", $probleme) . "\nNu am scris nimic.", 3);
}

$deSchimbat = array_values(array_filter($intrari, static fn (array $x): bool => $x['schimba']));
scrie(($mod === 'plan' ? 'PLAN (nu scriu nimic)' : 'APLIC') . ': ' . count($intrari) . ' produse, ' . count($deSchimbat) . ' de schimbat.');
foreach ($intrari as $x) {
    if (!$x['schimba']) {
        scrie("  {$x['slug']} ({$x['nume']}): neschimbat (deja aplicat)");
        continue;
    }
    scrie("  {$x['slug']} ({$x['nume']}):");
    scrie('      titlu:    ' . arata($x['acum']['titlu']) . ' → ' . arata($x['nou']['titlu']));
    scrie('      text:     ' . arata($x['acum']['text']) . ' → ' . arata($x['nou']['text']));
    scrie('      eticheta: ' . arata($x['acum']['eticheta']) . ' → ' . arata($x['nou']['eticheta']));
}
if ($conflicte !== [] && !$forteaza) {
    iesire("Refuz" . ($mod === 'plan' ? ' (la --aplica)' : '') . ": unele produse au deja alt avertisment:\n  - "
        . implode("\n  - ", $conflicte) . "\nNu am scris nimic. Verificați în Admin → Produse; --forteaza le înlocuiește (vechiul text rămâne în copie).", 3);
}
if ($mod === 'plan') {
    exit(0);
}
if ($deSchimbat === []) {
    iesire('Nimic de schimbat: avertismentele sunt deja aplicate. Nu am făcut o copie nouă.', 0);
}

// ─────────────────────────────────────────────────────────────
// Aplicarea: întâi copia, apoi totul într-o tranzacție
// ─────────────────────────────────────────────────────────────

// Tabelul îl face migrarea magazin/0032 (Bioscem îl crea aici, din mers).
if (coloane($db, 'product_warnings') === []) {
    iesire('Lipsește tabelul product_warnings: rulați întâi php scripts/migreaza.php --aplica. Nu am schimbat nimic.', 1);
}

$copie = [
    'facut_de' => 'scripts/avertisment-produse.php',
    'facut_la' => date('Y-m-d H:i:s'),
    'baza' => (string) $db->query('SELECT DATABASE()')->fetchColumn(),
    'intrari' => array_map(static fn (array $x): array => [
        'slug' => $x['slug'],
        'product_id' => $x['product_id'],
        'vechi' => $x['acum'],
        'scris' => $x['nou'],
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

$db->beginTransaction();
try {
    foreach ($deSchimbat as $x) {
        scrieValori($db, $x['product_id'], $x['nou']);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    iesire('Eroare la scriere; nu s-a schimbat nimic (copia rămâne, nu strică): ' . $e->getMessage(), 1);
}
scrie('Aplicat: ' . count($deSchimbat) . ' produse. Revenire: php scripts/avertisment-produse.php --restaureaza');
golesteCache();
