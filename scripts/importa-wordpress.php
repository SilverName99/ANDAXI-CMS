<?php

declare(strict_types=1);

/**
 * Importul unui site WordPress pe codul comun (docs/DEPLOY.md, „Adoptarea unui site WordPress”; App\Support\ImportWordPress):
 * citește baza WordPress DOAR pentru citire, cu datele din wp-config.php (fără să-l ruleze), și scrie în baza acestui
 * site paginile, SEO-ul lor, setările (numele, logo-ul, iconița, ID-urile de analiză), meniul (pe drumul necapturat),
 * mediile (copiate din wp-content/uploads în <docroot>/wp-content/uploads/, la aceeași cale, puse și în Galerie) și
 * redirecționările adreselor vechi.
 *
 *   php scripts/importa-wordpress.php --wp=<dosarul WordPress>              planul: ce s-ar importa (nu scrie nimic)
 *   php scripts/importa-wordpress.php --wp=<dosar> --aplica                 importul
 *
 * Opțiuni: --url=<adresa site-ului WordPress> (când e încă pornit), --captura=<dosar> (captura de la pasul 4; implicit
 * capturat/ din dosarul site-ului), --raport=<fișier.json> (ce s-a planificat și ce s-a făcut), --suprascrie (scrie și
 * peste rândurile editate de om după importul de dinainte: amprentele, corectura 1.9), --fara-media (fără copierea
 * mediilor; doar testele), --fara-magazin, --fara-newsletter, --fara-articole, --fara-automatizari (părțile pe care proprietarul a ales
 * să nu le importe), --export-mailpoet=<fișier.json> (exportul MailPoet al exportatorului, PLAN §7.1: randările emailurilor fără corp
 * păstrat și emailul de confirmare; implicit privat/mailpoet-export.json din dosarul site-ului, dacă există).
 *
 * Un site cu MailPoet (pista E): partea `newsletter` o planifică și o scrie ImportWordPress\MailPoet (abonații cu starea și dovada
 * consimțământului, listele, arhiva campaniilor, cozile și linkurile lor, dezabonările, setările), înaintea documentelor capturate, în
 * aceeași tranzacție; ieșirea are doar numere.
 *
 * Drumul capturat (`import-wordpress.json` → `"continut": "capturat"`, `"dialect": "divi4"`): --url și captura sunt
 * obligatorii, iar paginile le transformă dialectul (Continut\Divi4, pasul 5a) din captura de la pasul 4; HTML-ul transformat
 * trece prin politica HtmlCapturat, deci ce rămâne nepermis e NU pe pagină. Fiecare document se taie apoi în cele cinci
 * bucăți (Continut\Divi4Taiere): `pages.html_content` primește conținutul, restul (cu 404-ul, cardurile embed, arhivele,
 * răspunsurile înghețate, fragmentele, formularele și galeriile) tabelele capturii (ScriereCapturata, migrarea nucleu/0070),
 * cu P1 la import: o randare din bază diferită de documentul servit anulează tot.
 *
 * Pe server îl rulează deploy/adopta-wp.sh, prin bin/platforma cu --public=public_html.nou: mediile ajung în docroot-ul
 * nou (App\Support\Paths::public) înainte de comutare.
 *
 * Coduri de ieșire: 0 planul fără probleme / importul făcut; 1 o problemă (NU în plan, o eroare la import: nimic scris în
 * bază); 2 folosire greșită.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\ImportWordPress\Amprente;
use App\Support\ImportWordPress\Captura\Captura;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\EroareCaptura;
use App\Support\ImportWordPress\Continut\Divi4;
use App\Support\ImportWordPress\EroareImport;
use App\Support\ImportWordPress\ImportWordPress;
use App\Support\ImportWordPress\Importator;
use App\Support\ImportWordPress\Media;
use App\Support\ImportWordPress\ScriereCapturata;
use App\Support\Paths;
use App\Support\ResponseCache;

$optiuni = ['wp' => null, 'url' => '', 'raport' => null, 'captura' => null, 'sha-raport' => null, 'export-mailpoet' => null, 'aplica' => false, 'plan' => false,
    'fara-media' => false, 'suprascrie' => false, 'fara' => []];
$folosire = "Folosire: php scripts/importa-wordpress.php --wp=<dosarul WordPress> [--plan|--aplica] [--url=…] [--captura=…] [--raport=…] [--suprascrie]\n";
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(wp|url|raport|captura|sha-raport|export-mailpoet)=(.+)$/', $arg, $m) === 1) {
        $optiuni[$m[1]] = $m[2];
    } elseif (in_array($arg, ['--aplica', '--plan', '--fara-media', '--suprascrie'], true)) {
        $optiuni[substr($arg, 2)] = true;
    } elseif (preg_match('/^--fara-(magazin|newsletter|articole|automatizari)$/', $arg, $m) === 1) {
        $optiuni['fara'][] = $m[1];
    } else {
        fwrite(STDERR, "Argument necunoscut: {$arg}\n" . $folosire);
        exit(2);
    }
}
if ($optiuni['wp'] === null || ($optiuni['aplica'] && $optiuni['plan'])) {
    fwrite(STDERR, $folosire);
    exit(2);
}

$scrie = static function (string $fel, string $text): void {
    echo mb_str_pad($fel, 11) . $text, "\n";
};
/** Baza acestui site, sau null (planul merge și fără ea: o bază încă neinstalată nu are ce rânduri editate). */
$bazaSite = static function (): ?PDO {
    $config = require __DIR__ . '/../config/app.php';
    try {
        $db = Database::connection((array) ($config['db'] ?? []));
    } catch (Throwable) {
        return null;
    }
    if (!$db instanceof PDO) {
        return null;
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
};

try {
    $reguli = ImportWordPress::reguli();
    foreach (ImportWordPress::valideazaReguli($reguli) as $problema) {
        throw new EroareImport('import-wordpress.json din pachet: ' . $problema);
    }
    // Pe drumul capturat (pasul 5a), convertorul paginilor e dialectul capturii, pe captura de la pasul 4 (index.json); fără
    // captură, dialectul spune NU pe fiecare pagină. Planul trece apoi HTML-ul transformat prin politica HtmlCapturat.
    $captura = rtrim((string) ($optiuni['captura'] ?? (dirname(Paths::env()) . '/capturat')), '/');
    $convertoare = [];
    if (($reguli['continut'] ?? null) === ImportWordPress::CONTINUT_CAPTURAT && ($reguli['dialect'] ?? null) === 'divi4') {
        // Captura: niciodată în repo (ca unealta capturii, recenzia 2, R3-5) și deschisă doar verificată, cu lanțul sha256 întreg;
        // una care a ieșit cu NU (probleme, oprită, o adresă neidentică) e NU (R1-2). Ancora: --sha-raport=, altfel raportul ei.
        try {
            $captura = Captura::verificaIesirea($captura);
            $dosarCaptura = null;
            if (is_file($captura . '/index.json')) {
                $sha = (string) ($optiuni['sha-raport'] ?? (is_file($captura . '/raport-captura.json') ? hash_file('sha256', $captura . '/raport-captura.json') : ''));
                $dosarCaptura = DosarCapturi::deschideVerificat($captura, $sha, (string) $optiuni['url']);
            }
        } catch (EroareCaptura $e) {
            throw new EroareImport('captura ' . $captura . ': ' . $e->getMessage());
        }
        $convertoare[] = new Divi4($dosarCaptura);
    }
    // Exportul MailPoet (PLAN §7.1): cel dat, altfel cel din privat/ al dosarului site-ului, dacă există (fără el, planul spune ce lipsește).
    $exportMailPoet = $optiuni['export-mailpoet'] ?? (is_file(dirname(Paths::env()) . '/privat/mailpoet-export.json') ? dirname(Paths::env()) . '/privat/mailpoet-export.json' : null);
    $r = ImportWordPress::planifica((string) $optiuni['wp'], (string) $optiuni['url'], $convertoare, ['fara' => $optiuni['fara'],
        'export_mailpoet' => $exportMailPoet], $reguli);
    $plan = $r['plan'];
    $inventar = $r['inventar'];
    $ctx = $r['context'];
    // Corectura 1.15: pe drumul capturat adresa site-ului live și captura (pasul 4) sunt obligatorii.
    if ($ctx->capturat()) {
        if ((string) $optiuni['url'] === '') {
            $plan->probleme[] = 'drumul capturat cere --url=<adresa site-ului WordPress live> (captura și verificările o folosesc)';
        }
        if (!is_file($captura . '/manifest.json')) {
            $plan->probleme[] = 'drumul capturat cere captura site-ului live (pasul 4: scripts/captureaza-wordpress.php): lipsește ' . $captura . '/manifest.json';
        }
    }
    // Corectura 1.9: rândurile editate de om de la importul de dinainte (din baza acestui site, dacă e instalată), și adresele
    // pe care stau alte pagini (și cele din coș: unicitatea slug-ului le cuprinde), ca NU în plan, nu ca eroare la scriere.
    $db = $bazaSite();
    if ($db !== null) {
        try {
            foreach (Importator::conflicteAdrese($db, $plan, Importator::harta()) as $c) {
                $plan->probleme[] = 'pe site există deja pagina de la adresa ' . $c . '; nu e din import, deci nu o scriu peste';
            }
            // SPEC-R3-05: slug-uri ale planului care se bat cap în cap pe cheia unică a bazei (colația utf8mb4_unicode_ci), nu
            // doar pe cheia exactă PHP (două slug-uri care diferă printr-un accent).
            foreach (Importator::coliziuniSlug($db, $plan) as $c) {
                $plan->probleme[] = 'două pagini s-ar bate cap în cap pe cheia unică a bazei (accent): ' . $c;
            }
        } catch (Throwable) {
            // O bază încă neinstalată (fără tabelul pages) nu are pagini care să se bată cap în cap cu planul.
        }
    }
    // Pista E: verificările newsletterului MailPoet care cer baza site-ului (expeditorul, adresele deja ale altui abonat, abonații
    // păstrați la reaplicare), doar citite; o bază încă neinstalată nu are ce verifica.
    if ($db !== null && $plan->newsletter instanceof \App\Support\ImportWordPress\MailPoet\PlanMailPoet) {
        try {
            \App\Support\ImportWordPress\MailPoet\PlanMailPoet::verificaSite($plan, $db);
        } catch (\PDOException) {
            // Fără tabelele newsletterului (o bază neinstalată): nimic de comparat.
        }
    }
    if ($db !== null && Amprente::disponibil($db)) {
        $plan->conflicte = array_merge(Amprente::conflicte($db, $plan, Importator::harta(), $plan->capturat),
            $plan->capturat ? ScriereCapturata::conflicte($db) : []);
        foreach ($plan->conflicte as $c) {
            if ($optiuni['suprascrie']) {
                $plan->avertismente[] = 'scris peste (--suprascrie): ' . $c['motiv'];
            } else {
                $plan->probleme[] = 'editat după importul de dinainte: ' . $c['motiv'] . ' (rămâne cum e; --suprascrie îl scrie peste)';
            }
        }
    }
    echo "== Importul WordPress " . ($optiuni['aplica'] ? '' : '— PLAN') . "\n";
    $scrie('  ..', 'baza WordPress: ' . $r['config']->descriere() . ', doar citită');
    $scrie('  ..', 'drumul: ' . ($ctx->capturat() ? 'capturat (dialectul ' . $ctx->dialect() . ')' : 'necapturat (convertoarele conținutului)'));
    foreach ($plan->info as $t) {
        $scrie('  ..', $t);
    }
    foreach ($plan->pagini as $p) {
        $scrie('  pagina', $p['cale_veche'] . ' → ' . $p['cale'] . '  „' . $p['titlu'] . '”' . ($p['publicata'] ? '' : ' (nepublicată)')
            . ' [' . ($p['convertor'] ?? 'fără convertor') . ']' . (($p['seo']['titlu'] ?? '') !== '' ? '  titlu: ' . $p['seo']['titlu'] : ''));
    }
    foreach ($plan->neimportate as $n) {
        $scrie('  sarit', $n['cale'] . '  „' . $n['titlu'] . '”: ' . $n['motiv'] . ($n['spre'] !== null ? ' (adresa duce la ' . $n['spre'] . ')' : ''));
    }
    foreach ($plan->redirecturi as $x) {
        $scrie('  redir', ($x['prefix'] ? $x['din'] . '…' : $x['din']) . ' → ' . ($x['cod'] === 410 ? '410' : (string) $x['spre']) . '  (' . $x['nota'] . ')');
    }
    $scrie('  ..', 'setări: ' . implode(', ', array_keys($plan->setari)) . (!$plan->capturat && $plan->meniu !== [] ? ', design_menu_html' : '')
        . (!$plan->capturat && $plan->design !== [] ? ', ' . implode(', ', array_keys($plan->design)) : ''));
    $scrie('  ..', 'harta WordPress (id → adresă): ' . count($plan->hartaWp) . ' rânduri');
    if ($plan->capturata !== null) {
        // Pasul 5a: ce scrie importul în tabelele capturii.
        $rc = $plan->capturata->raport();
        $scrie('  ..', 'captura: documente ' . (implode(', ', array_map(static fn (string $t, int $n): string => $t . ' ' . $n, array_keys($rc['documente']),
            $rc['documente'])) ?: '0') . '; fragmente ' . (implode(', ', array_map(static fn (string $f, int $n): string => $f . ' ' . $n, array_keys($rc['fragmente']),
            $rc['fragmente'])) ?: '0') . '; răspunsuri înghețate ' . count($rc['raspunsuri']) . '; formulare ' . count($rc['formulare']) . '; galerii ' . count($rc['galerii']));
        foreach ($plan->capturata->documente as $cheie => $d) {
            $scrie('  doc', (string) $cheie . '  ' . ($d['cale'] ?? $d['valori_import']['cale'] ?? '') . '  head: ' . ($d['taiere']->seo === 'jetoane' ? 'pe jetoanele SEO'
                : 'înghețat' . ($d['taiere']->seoMotiv !== null ? ' (' . $d['taiere']->seoMotiv . ')' : '')));
        }
        foreach ($rc['raspunsuri'] as $x) {
            $scrie('  inghetat', $x);
        }
    }
    $scrie('  ..', 'medii de copiat în ' . Paths::public(Media::DOSAR_DOCROOT) . '/: ' . count($plan->media['fisiere']) . ' ('
        . number_format($plan->media['octeti'] / 1048576, 1, ',', '') . ' MB)');
    foreach ($plan->avertismente as $t) {
        $scrie('  ATENȚIE', $t);
    }
    foreach ($plan->probleme as $t) {
        $scrie('  NU', $t);
    }
    $raport = ['facut' => date('c'), 'mod' => $optiuni['aplica'] ? 'aplica' : 'plan', 'site' => $inventar->site, 'pluginuri' => $inventar->pluginuri,
        'altele' => $inventar->altele, 'amprenta_wordpress' => $r['sursa']->amprentaContinut(), 'plan' => $plan->raport()];
    if (!$optiuni['aplica']) {
        if ($optiuni['raport'] !== null) {
            file_put_contents((string) $optiuni['raport'], json_encode($raport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        echo $plan->probleme === [] ? "PLAN: nimic scris. Importul: aceeași comandă cu --aplica.\n" : "PLAN: " . count($plan->probleme) . " probleme (NU); importul nu poate porni.\n";
        exit($plan->probleme === [] ? 0 : 1);
    }
    if ($plan->probleme !== []) {
        echo 'NU import: ' . count($plan->probleme) . " probleme (NU, mai sus); nimic scris.\n";
        exit(1);
    }
    if ($db === null) {
        throw new EroareImport('baza acestui site nu răspunde (vezi .env)');
    }
    $facut = Importator::aplica($plan, $db, !$optiuni['fara-media'], (bool) $optiuni['suprascrie']);
    ResponseCache::purgePageCache();
    $raport['facut_import'] = array_diff_key($facut, ['media' => true]) + ['media' => is_array($facut['media'])
        ? array_diff_key($facut['media'], ['manifest' => true]) + ['manifest' => count($facut['media']['manifest']) . ' fișiere'] : null];
    if ($optiuni['raport'] !== null) {
        file_put_contents((string) $optiuni['raport'], json_encode($raport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    if (is_array($facut['media'])) {
        $scrie('  OK', 'mediile: ' . $facut['media']['copiate'] . ' copiate (' . number_format($facut['media']['octeti'] / 1048576, 1, ',', '') . ' MB), '
            . $facut['media']['la_zi'] . ' la zi, manifestul cu sha256: ' . Paths::stocareSite('import-wordpress') . '/media.json'
            . ($facut['media']['sarite'] !== [] ? ', ' . count($facut['media']['sarite']) . ' sărite: ' . implode('; ', array_slice($facut['media']['sarite'], 0, 3)) : ''));
    }
    $scrie('  OK', 'paginile: ' . $facut['pagini'] . ' (SEO scris la ' . $facut['seo'] . '), setările: ' . $facut['setari'] . ', în Galerie: ' . $facut['galerie']
        . ' poze noi, redirecționări: ' . $facut['redirecturi'] . (isset($facut['harta_wp']) ? ', harta WordPress (wp_harta): ' . $facut['harta_wp'] . ' rânduri' : ''));
    if (is_array($facut['capturat'] ?? null)) {
        $c = $facut['capturat'];
        $scrie('  OK', 'captura: ' . $c['documente'] . ' documente, ' . $c['fragmente'] . ' fragmente, ' . $c['raspunsuri'] . ' răspunsuri înghețate, ' . $c['formulare']
            . ' formulare, ' . $c['galerii'] . ' galerii' . ($c['sterse'] > 0 ? ', ' . $c['sterse'] . ' rânduri vechi șterse' : '') . '; P1 la import: fiecare document randat din bază = T');
    }
    if (is_array($facut['newsletter'] ?? null)) {
        $n = $facut['newsletter'];
        $scrie('  OK', 'newsletter (MailPoet): ' . $n['abonati'] . ' abonați (' . $n['abonati_noi'] . ' noi' . ($n['sariti_suprimati'] > 0 ? ', ' . $n['sariti_suprimati']
            . ' pe lista „nu trimite”, săriți' : '') . '), ' . $n['liste'] . ' liste, ' . $n['apartenente'] . ' apartenențe, ' . $n['campanii'] . ' campanii ('
            . $n['trimiteri'] . ' trimiteri, ' . $n['deschideri'] . ' deschideri, ' . $n['clicuri'] . ' clicuri), ' . $n['cozi'] . ' cozi, ' . $n['linkuri'] . ' linkuri, '
            . $n['dezabonari'] . ' dezabonări, ' . $n['setari'] . ' setări');
        if (($n['pastrati'] ?? 0) > 0) {
            $scrie('  ATENȚIE', 'newsletter (MailPoet): ' . $n['pastrati'] . ' abonați și-au păstrat starea, acordul și listele de pe site-ul nou (id MailPoet→site: '
                . implode(', ', (array) $n['pastrati_id']) . ')' . (($n['retrageri_mailpoet'] ?? 0) > 0 ? '; ' . $n['retrageri_mailpoet']
                . ' retrageri din MailPoet mai noi aplicate' : ''));
        }
    }
    foreach ($facut['suprascrise'] as $t) {
        $scrie('  ATENȚIE', 'scris peste (--suprascrie): ' . $t);
    }
    foreach ($facut['redirecturi_refuzate'] as $t) {
        $scrie('  ATENȚIE', 'redirecționare nescrisă: ' . $t);
    }
    echo "GATA: importul WordPress e făcut.\n";
    exit(0);
} catch (EroareImport $e) {
    fwrite(STDERR, 'NU: ' . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    // Orice eroare neașteptată e NU (codul 1, nimic scris: scrierea e într-o tranzacție), nu o eroare fatală (recenzia m1); fără mesajul
    // ei (ImportWordPress::eroareNeasteptata, E-LEG-06).
    fwrite(STDERR, \App\Support\ImportWordPress\ImportWordPress::eroareNeasteptata($e) . "\n");
    exit(1);
}
