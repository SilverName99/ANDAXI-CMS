<?php

declare(strict_types=1);

/**
 * P1 după import (adoptarea WordPress, pasul 7; INSTRUCTIUNI-BUILDER-C2 §7): fiecare document capturat (`pagini_capturate`) se
 * randează din linia de comandă, pe același drum ca cererea web (App\Support\CadruCapturat, cu scripturile runtime-ului din
 * `tema.json` → `runtime`), cu valorile de la import (calea și anul capturii, SEO-ul de atunci, un nonce fix), și se compară cu
 * amprenta aprobată (`sha_randare`, scrisă de P1 la import). Plus: nicio ieșire n-are un script nepermis (doar datele inerte și
 * scripturile runtime-ului de pe sloturi), iar politica CSP a site-ului n-are `'unsafe-inline'`/`'unsafe-eval'` în `script-src`.
 *
 *   php scripts/verifica-randare.php --capturat                    P1: cod 0 doar fără nicio diferență
 *   php scripts/verifica-randare.php --capturat --aproba=<page_id> reînnoiește amprenta paginii (după o editare văzută de om)
 *   php scripts/verifica-randare.php --capturat --aproba-toate     reînnoiește toate amprentele (după o versiune nouă a runtime-ului)
 *
 * Nu scrie nimic fără `--aproba…`. Coduri: 0 totul la fel, 1 o diferență (sau un document care nu se randează), 2 folosire greșită.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\CadruCapturat;
use App\Support\CspSite;
use App\Support\Database;
use App\Support\ImportWordPress\ScriereCapturata;
use App\Support\Tema;

$capturat = false;
$aproba = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--capturat') {
        $capturat = true;
    } elseif ($arg === '--aproba-toate') {
        $aproba = 'toate';
    } elseif (preg_match('/^--aproba=([1-9][0-9]{0,9})$/', $arg, $m) === 1) {
        $aproba = (int) $m[1];
    } else {
        fwrite(STDERR, "Argument necunoscut: {$arg}\nFolosire: php scripts/verifica-randare.php --capturat [--aproba=<page_id>|--aproba-toate]\n");
        exit(2);
    }
}
if (!$capturat) {
    fwrite(STDERR, "Folosire: php scripts/verifica-randare.php --capturat [--aproba=<page_id>|--aproba-toate]\n");
    exit(2);
}
try {
    $config = require __DIR__ . '/../config/app.php';
    $db = Database::connection((array) ($config['db'] ?? []));
    if (!$db instanceof PDO || !ScriereCapturata::disponibil($db)) {
        fwrite(STDERR, "NU: baza site-ului nu răspunde sau n-are tabelele capturii (migrarea nucleu/0070)\n");
        exit(1);
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $runtime = CadruCapturat::scripturiRuntime(Tema::runtime());
    $politica = CspSite::politica(false, Tema::csp());
    $probleme = [];
    if (preg_match("/script-src[^;]*'unsafe-(?:inline|eval)'/", $politica) === 1) {
        $probleme[] = 'politica CSP a site-ului are unsafe-inline sau unsafe-eval în script-src';
    }
    $documente = $db->query('SELECT cheie, page_id, sha_randare FROM pagini_capturate ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $aprobate = 0;
    foreach ($documente as $d) {
        $cheie = (string) $d['cheie'];
        try {
            $html = ScriereCapturata::randeazaDinBaza($db, $cheie, $runtime, null);
        } catch (Throwable $e) {
            $probleme[] = $cheie . ': nu se randează (' . $e->getMessage() . ')';
            continue;
        }
        foreach (CadruCapturat::scripturiNepermise($html, $runtime) as $s) {
            $probleme[] = $cheie . ': script nepermis în ieșire: ' . $s;
        }
        $sha = hash('sha256', $html);
        if ($aproba === 'toate' || ($aproba !== null && (int) $d['page_id'] === $aproba)) {
            $db->prepare('UPDATE pagini_capturate SET sha_randare = ? WHERE cheie = ?')->execute([$sha, $cheie]);
            $aprobate++;
            echo '  aprobat   ' . $cheie . '  ' . substr($sha, 0, 12) . "\n";
            continue;
        }
        if ($sha !== (string) $d['sha_randare']) {
            $probleme[] = $cheie . ': randarea (' . substr($sha, 0, 12) . ') diferă de amprenta aprobată (' . substr((string) $d['sha_randare'], 0, 12)
                . '): o editare, o versiune nouă a runtime-ului? Vezi pagina, apoi --aproba=' . (int) $d['page_id'];
            continue;
        }
        echo '  la fel    ' . $cheie . '  ' . substr($sha, 0, 12) . "\n";
    }
    if (is_int($aproba) && $aprobate === 0) {
        $probleme[] = 'pagina ' . $aproba . ' n-are document capturat';
    }
    foreach ($probleme as $p) {
        echo '  NU        ' . $p . "\n";
    }
    echo 'P1: ' . count($documente) . ' documente, ' . count($probleme) . ' probleme' . ($aprobate > 0 ? ', ' . $aprobate . ' aprobate' : '') . ".\n";
    exit($probleme === [] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'NU: eroare neașteptată (' . get_class($e) . ': ' . $e->getMessage() . ")\n");
    exit(1);
}
