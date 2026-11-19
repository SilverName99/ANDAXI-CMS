<?php

declare(strict_types=1);

/**
 * Captura unui site WordPress live, pentru adoptarea pe drumul capturat (docs/DEPLOY.md, „Adoptarea unui site WordPress”;
 * App\Support\ImportWordPress\Captura): paginile, postările, arhivele, căutarea, 404-ul, cardurile embed, oEmbed și fișierele
 * speciale, cerute prin HTTP fără browser (doar GET, șase UA × 2, ritm și buget, reluare la 429 și la 503 cu Retry-After, fără
 * cookie-uri, fără trackere), comparate după normalizarea declarată; apoi manifestul fișierelor cerute de pagini și de foile
 * lor. Întâi, pe server, integritatea WordPress-ului de pe disc (un NU oprește tot). Scrie DOAR în dosarul capturii (implicit
 * `site/capturat/`, niciodată în repo) și, cu --public, în arborii copiați ai docroot-ului nou.
 *
 *   php scripts/captureaza-wordpress.php --url=https://<site> --wp=<dosarul WordPress>            planul (nicio cerere, nimic scris)
 *   php scripts/captureaza-wordpress.php --url=https://<site> --wp=<dosar> --aplica                integritatea, apoi captura
 *   php scripts/captureaza-wordpress.php --url=https://<site> --lista=<adrese.json> --fara-integritate=<motiv> --aplica
 *                                                                                    captura din mediul nostru, fără discul WP
 *   php scripts/captureaza-wordpress.php --url=https://<site> (--wp=<dosar> | --lista=<…> --fara-integritate=<motiv>)
 *       --din-dosar=<captura urcată> --sha-raport=<sha256 al raport-captura.json> --aplica      captura urcată, verificată, fără rețea
 *
 * Opțiuni: --iesire=<dosar> (unde se scrie captura), --buget=<cereri> (implicit 300, cel mult 2000; fiecare încercare se numără),
 * --ritm=<secunde> (implicit 1, cel puțin 1: cel mult o cerere pe secundă), --raspunsuri-ua=1|2|6 (răspunsurile înghețate pe
 * 1 sau 2 UA × 2 în loc de 6 × 2, când bugetul e mic), --doar=<felurile, din ListaAdrese::FELURI>, --fara-descoperire (fără
 * sub-sitemap-urile găsite în sitemap.xml), --din-oglinda=<dosar> [--harta=<fișier.json>] (o oglindă locală, neverificată:
 * cache-ul inventarului, doar în mediul nostru), --resurse-din=<dosar> [--harta-resurse=<…>] (sha256-ul fișierelor dintr-o
 * oglindă, când discul WordPress nu e la îndemână), --public=<docroot nou> (cu --wp și --aplica: copiază fișierele statice în
 * arborii copiați), --fonturi (cu --public: fonturile Google, în <docroot nou>/capturat/fonturi/), --integritate (implicită cu
 * --wp), --fara-integritate=<motiv> (fără discul WordPress; starea ei rămâne „în așteptare (server)”), --nonce=<nume,…>.
 *
 * Coduri de ieșire: 0 fără NU; 1 măcar un NU al capturii (o diferență nedeclarată, o adresă necapturată, o coliziune); 2 folosire
 * greșită sau un refuz înaintea capturii, deci înaintea oricărei cereri spre site (o ieșire în repo, un --public refuzat, un dosar
 * lipsă, o captură urcată care nu trece verificarea sha256, integritatea NU). La sfârșit se tipărește sha256-ul lui
 * raport-captura.json (pentru --sha-raport=).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\ImportWordPress\Captura\Captura;
use App\Support\ImportWordPress\Captura\Cereri;
use App\Support\ImportWordPress\Captura\CopiereStatica;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\EroareCaptura;
use App\Support\ImportWordPress\Captura\FonturiGoogle;
use App\Support\ImportWordPress\Captura\Integritate;
use App\Support\ImportWordPress\Captura\ListaAdrese;
use App\Support\ImportWordPress\Captura\Normalizare;
use App\Support\ImportWordPress\ConfigWordPress;
use App\Support\ImportWordPress\EroareImport;
use App\Support\ImportWordPress\ImportWordPress;
use App\Support\ImportWordPress\Inventar;
use App\Support\ImportWordPress\SursaWordPress;
use App\Support\Paths;

const BUGET_MAXIM = 2000;
const RITM_MINIM = 1.0;
/** Rezerva planului pentru ce se află abia la captură: sub-sitemap-urile și XSL-ul din sitemap.xml. Fonturile Google au bugetul lor (FonturiGoogle::BUGET). */
const REZERVA_SITEMAP_DOCUMENTE = 6;

$valori = ['url', 'wp', 'lista', 'din-dosar', 'sha-raport', 'din-oglinda', 'harta', 'iesire', 'buget', 'ritm', 'raspunsuri-ua', 'doar', 'resurse-din',
    'harta-resurse', 'public', 'nonce', 'fara-integritate'];
$fanioane = ['aplica', 'plan', 'fonturi', 'integritate', 'fara-descoperire'];
$o = array_fill_keys($valori, null) + array_fill_keys($fanioane, false);
$o['buget'] = '300';
$o['ritm'] = '1';
$o['raspunsuri-ua'] = '6';
$folosire = "Folosire: php scripts/captureaza-wordpress.php --url=https://<site> (--wp=<dosar> | --lista=<adrese.json> --fara-integritate=<motiv>) [--plan|--aplica] [opțiuni]\n";
$gresit = static function (string $motiv) use ($folosire): never {
    fwrite(STDERR, 'NU (folosire): ' . $motiv . "\n" . $folosire);
    exit(2);
};
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(' . implode('|', array_map('preg_quote', $valori)) . ')=(.*)$/s', $arg, $m) === 1) {
        if ($m[2] === '' || $o[$m[1]] !== null && !in_array($m[1], ['buget', 'ritm', 'raspunsuri-ua'], true)) {
            $gresit($m[2] === '' ? '--' . $m[1] . '= fără valoare' : '--' . $m[1] . ' dat de două ori');
        }
        $o[$m[1]] = $m[2];
    } elseif (str_starts_with($arg, '--') && in_array(substr($arg, 2), $fanioane, true)) {
        $o[substr($arg, 2)] = true;
    } else {
        $gresit('argument necunoscut: ' . $arg);
    }
}

// --- Validarea (cod 2, la fel la --plan și la --aplica) ---------------------------------------------------------------------
$url = (static function (string $dat) use ($gresit): string {
    $p = parse_url($dat);
    $schema = strtolower((string) ($p['scheme'] ?? ''));
    if (!is_array($p) || !in_array($schema, ['http', 'https'], true) || !isset($p['host']) || isset($p['user']) || isset($p['pass'])
        || isset($p['query']) || isset($p['fragment']) || !in_array((string) ($p['path'] ?? ''), ['', '/'], true)) {
        $gresit('--url trebuie să fie de forma https://<gazdă>[:port] (fără cale, query, fragment sau utilizator): ' . $dat);
    }
    $gazda = (string) $p['host'];
    if (preg_match('/[^\x00-\x7f]/', $gazda) === 1) {
        $puny = function_exists('idn_to_ascii') ? idn_to_ascii($gazda, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46) : false;
        if (!is_string($puny) || $puny === '') {
            $gresit('gazda IDN nu se poate scrie în punycode: ' . $gazda);
        }
        $gazda = $puny;
    }
    $gazda = strtolower($gazda);
    $eticheta = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    if (filter_var($gazda, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false && preg_match('/^' . $eticheta . '(?:\.' . $eticheta . ')*$/', $gazda) !== 1) {
        $gresit('gazda nu e un nume DNS valid (litere, cifre, „-”, „.”; fără „_”): ' . $gazda);
    }
    return $schema . '://' . $gazda . (isset($p['port']) ? ':' . (int) $p['port'] : '');
})((string) ($o['url'] ?? ''));
if (($o['wp'] === null) === ($o['lista'] === null)) {
    $gresit('dă exact una dintre --wp și --lista');
}
if ($o['aplica'] && $o['plan']) {
    $gresit('--plan și --aplica împreună');
}
if (!ctype_digit((string) $o['buget']) || (int) $o['buget'] < 1 || (int) $o['buget'] > BUGET_MAXIM) {
    $gresit('--buget trebuie să fie un număr întreg între 1 și ' . BUGET_MAXIM);
}
if (!is_numeric($o['ritm']) || !is_finite((float) $o['ritm']) || (float) $o['ritm'] < RITM_MINIM || (float) $o['ritm'] > 3600) {
    $gresit('--ritm trebuie să fie cel puțin ' . RITM_MINIM . ' secunde (cel mult o cerere pe secundă)');
}
if (!in_array((string) $o['raspunsuri-ua'], ['1', '2', '6'], true)) {
    $gresit('--raspunsuri-ua e 1, 2 sau 6');
}
$feluri = $o['doar'] !== null ? array_values(array_filter(array_map('trim', explode(',', (string) $o['doar'])), static fn (string $f): bool => $f !== '')) : ListaAdrese::FELURI;
if ($feluri === [] || array_diff($feluri, ListaAdrese::FELURI) !== []) {
    $gresit('--doar cere felurile capturii, din: ' . implode(', ', ListaAdrese::FELURI));
}
if (($o['din-dosar'] === null) !== ($o['sha-raport'] === null)) {
    $gresit('--din-dosar cere --sha-raport=<sha256 al raport-captura.json> (tipărit la finalul capturii), și invers');
}
if ($o['sha-raport'] !== null && preg_match('/^[0-9a-f]{64}$/', (string) $o['sha-raport']) !== 1) {
    $gresit('--sha-raport trebuie să fie un sha256 (64 de cifre hexazecimale, litere mici)');
}
if ($o['din-dosar'] !== null && $o['din-oglinda'] !== null) {
    $gresit('--din-dosar și --din-oglinda împreună');
}
if ($o['harta'] !== null && $o['din-oglinda'] === null) {
    $gresit('--harta merge doar cu --din-oglinda');
}
if ($o['harta-resurse'] !== null && $o['resurse-din'] === null) {
    $gresit('--harta-resurse merge doar cu --resurse-din');
}
if ($o['public'] !== null && $o['wp'] === null) {
    $gresit('--public copiază doar de pe discul WordPress: cere --wp');
}
if ($o['fonturi'] && $o['din-dosar'] === null && $o['din-oglinda'] !== null) {
    $gresit('--fonturi se cer prin rețea: nu merg cu --din-oglinda');
}
if ($o['fara-integritate'] !== null && $o['public'] !== null) {
    $gresit('--fara-integritate nu merge cu --public: nu se copiază fișiere de pe un disc WordPress neverificat');
}
if ($o['fara-integritate'] !== null && $o['integritate']) {
    $gresit('--integritate și --fara-integritate împreună');
}
if ($o['wp'] === null && $o['fara-integritate'] === null) {
    $gresit('fără --wp integritatea nu se poate face: dă --fara-integritate=<motiv> (starea ei rămâne „în așteptare (server)”)');
}
$scrie = static function (string $fel, string $text): void {
    echo mb_str_pad($fel, 11) . $text, "\n";
};
$ritm = (float) $o['ritm'];
$buget = (int) $o['buget'];
$uaR = (int) $o['raspunsuri-ua'];
$captura = null;
$cereri = null;
$cereriFonturi = null;
$antet = [];
$raport = null;
$oprit = null;
$cod = 0;
// Până începe captura (după integritate), orice refuz e cod 2: --iesire sau --public greșite, un dosar lipsă, o captură urcată
// care nu trece lanțul, integritatea care nu se poate face. După, un NU al capturii e cod 1.
$inainteDeCaptura = true;
$scrieRaportul = static function () use (&$captura, &$cereri, &$antet, &$raport, &$oprit): void {
    if ($captura === null || $raport !== null) {
        return;
    }
    $raport = $captura->raport($cereri, $antet, $oprit);
    echo 'raport-captura.json: sha256 ' . $raport['sha256_raport'] . "\n";
};
// Orice oprire după primele scrieri (o eroare fatală, un exit) lasă raportul scris: jurnalul e deja pe disc, cerere cu cerere.
register_shutdown_function(static function () use (&$oprit, $scrieRaportul): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $oprit ??= 'eroare fatală: ' . $e['message'];
    }
    try {
        $scrieRaportul();
    } catch (Throwable) {
    }
});

try {
    $iesire = Captura::verificaIesirea(rtrim((string) ($o['iesire'] ?? (dirname(Paths::env()) . '/capturat')), '/'));
    $public = $o['public'] !== null ? CopiereStatica::verificaTinta((string) $o['public']) : null;
    // Dosarele locale se deschid întâi (fără rețea), ca o greșeală să iasă înaintea oricărei cereri.
    $oglinda = $o['resurse-din'] !== null ? DosarCapturi::deschide((string) $o['resurse-din'], $o['harta-resurse'] !== null ? (string) $o['harta-resurse'] : null) : null;
    $reguli = ImportWordPress::reguli();
    // Regulile îmbinate (pachetul și privat/) se validează și aici, ca la import (recenzia 2, R3-8): un NU înaintea oricărei cereri.
    foreach (ImportWordPress::valideazaReguli($reguli) as $problemaReguli) {
        throw new EroareCaptura('folosire', 'import-wordpress.json din pachet (cu privat/): ' . $problemaReguli);
    }
    $nonce = array_values(array_filter(array_merge(explode(',', (string) $o['nonce']), (array) ($reguli['captura']['nonce'] ?? []))));
    $normalizare = new Normalizare($url, array_map('strval', $nonce));
    $config = $o['wp'] !== null ? ConfigWordPress::citeste((string) $o['wp']) : null;
    $dosarWp = $config?->dosar;
    $mod = $o['din-dosar'] !== null ? 'din-dosar' : ($o['din-oglinda'] !== null ? 'din-oglinda' : 'http');
    echo '== Captura WordPress ' . ($o['aplica'] ? '' : '— PLAN') . "\n";
    $scrie('  ..', 'site-ul: ' . $url . ' (doar GET, fără cookie-uri; trackerele refuzate: ' . implode(', ', array_slice(Cereri::TRACKERE, 0, 4)) . '…)');
    $scrie('  ..', 'ieșirea: ' . $iesire . ($mod !== 'http' ? ' (' . $mod . ': ' . ($o['din-dosar'] ?? $o['din-oglinda']) . ', fără rețea)' : ''));
    $integritatePlan = $dosarWp !== null && $o['fara-integritate'] === null ? Integritate::cereriPlanificate($dosarWp) : 0;

    if (!$o['aplica']) {
        // --- Planul: nicio cerere, nimic scris --------------------------------------------------------------------------
        [$adrese, $avertismente] = (static function () use ($config, $reguli, $url, $o): array {
            if ($config !== null) {
                $inventar = Inventar::citeste(SursaWordPress::deschide($config));
                return [ListaAdrese::dinInventar($inventar, $reguli, $url), ListaAdrese::avertismenteInventar($inventar)];
            }
            return [ListaAdrese::dinFisier((string) $o['lista'], $url), []];
        })();
        $adrese = ListaAdrese::filtreaza($adrese, $feluri);
        foreach ($adrese as $a) {
            $scrie('  adresa', sprintf('%-14s %s', $a['fel'], $a['cale']) . ($mod === 'http' ? '  (' . count(Captura::ua($a['fel'], $uaR)) . ' UA × ' . Captura::REPETARI . ')' : ''));
        }
        foreach ($avertismente as $t) {
            $scrie('  ATENȚIE', $t);
        }
        $probleme = [];
        if ($mod === 'http') {
            $plan = Captura::plan($adrese, $uaR);
            $sitemap = !$o['fara-descoperire'] && array_filter($adrese, static fn (array $a): bool => $a['fel'] === 'raspuns'
                && preg_match('#/sitemap(_index)?\.xml$#', $a['cale']) === 1) !== [];
            $rezervaSitemap = $sitemap ? REZERVA_SITEMAP_DOCUMENTE * count(Captura::ua('raspuns', $uaR)) * Captura::REPETARI : 0;
            $total = $plan['cereri'] + $rezervaSitemap;
            $scrie('  ..', 'cereri planificate: ' . $plan['cereri'] . ' (' . implode(', ', array_map(static fn (string $f, int $n): string => $f . ' ' . $n,
                array_keys($plan['pe_fel']), $plan['pe_fel'])) . ')' . ($rezervaSitemap > 0 ? ', plus rezerva sitemap-urilor găsite în index: ' . $rezervaSitemap
                . ' (' . REZERVA_SITEMAP_DOCUMENTE . ' documente)' : '') . ' = ' . $total . ' din bugetul ' . $buget . ', cel mult o cerere la ' . $ritm . ' s');
            if ($o['fonturi']) {
                $scrie('  ..', 'fonturile Google: un buget al lor, separat, de cel mult ' . FonturiGoogle::BUGET . ' cereri spre ' . FonturiGoogle::GAZDA_CSS . ' și '
                    . FonturiGoogle::GAZDA_FONTURI . ' (fiecare adresă o dată), ' . ($public !== null ? 'în ' . $public . FonturiGoogle::DOSAR : 'în dosarul capturii (fonturi/), publicate apoi de --din-dosar'));
            }
            if ($total > $buget) {
                $probleme[] = 'cererile planificate cu rezervele (' . $total . ') depășesc bugetul (' . $buget . '): restrânge cu --doar= sau --raspunsuri-ua=2';
            }
        }
        $scrie('  ..', $integritatePlan > 0 ? 'integritatea, înaintea capturii: ' . $integritatePlan . ' cereri (nucleul și pluginurile cu versiune), cu gazdele ei ('
            . implode(', ', Integritate::GAZDE) . ') și bugetul ei' : 'integritatea: sărită (' . (string) $o['fara-integritate'] . '); starea ei rămâne „'
            . Integritate::IN_ASTEPTARE . '”');
        foreach ($probleme as $p) {
            $scrie('  NU', $p);
        }
        echo $probleme === [] ? "PLAN: nicio cerere făcută, nimic scris. Captura: aceeași comandă cu --aplica.\n" : 'PLAN: ' . count($probleme) . " probleme (NU).\n";
        exit($probleme === [] ? 0 : 1);
    }

    // --- Captura ----------------------------------------------------------------------------------------------------------
    // Un dosar cu o captură e refuzat înaintea oricărei scrieri (recenzia 2, R1-3): nici raportul, nici jurnalul ei nu se ating.
    // Un dosar cu raportul unei rulări refuzate înaintea capturii (recenzia 3, R1-N5) se poate refolosi: fișierele ei se scot.
    // Nu și dosarul unei integrități (recenzia 4, R1-N6): integritate.json, jurnalul ei și raportul sunt dovada unui NU, deci
    // dosarul e refuzat ca o captură, înaintea oricărei scrieri.
    $rVechi = json_decode((string) @file_get_contents($iesire . '/raport-captura.json'), true);
    $cuIntegritate = file_exists($iesire . '/integritate.json') || is_link($iesire . '/integritate.json')
        || file_exists($iesire . '/' . Captura::JURNAL_INTEGRITATE) || is_link($iesire . '/' . Captura::JURNAL_INTEGRITATE)
        || (is_array($rVechi) && isset($rVechi['antet']['integritate']['stare']) && $rVechi['antet']['integritate']['stare'] !== Integritate::IN_ASTEPTARE);
    if ($cuIntegritate) {
        throw new EroareCaptura('folosire', 'dosarul de ieșire are deja o verificare a integrității (integritate.json, jurnalul ei sau raportul ei): '
            . 'rămâne neatins; dă un --iesire= gol');
    }
    if (is_array($rVechi) && (($rVechi['antet']['refuzat'] ?? false) === true) && !is_dir($iesire . '/brut')) {
        foreach (array_merge(Captura::FISIERE_LANT, ['raport-captura.json']) as $vechi) {
            if (is_file($iesire . '/' . $vechi) && !is_link($iesire . '/' . $vechi)) {
                @unlink($iesire . '/' . $vechi);
            }
        }
    }
    foreach (array_merge(Captura::FISIERE_LANT, ['raport-captura.json', 'brut', FonturiGoogle::DOSAR_CAPTURA]) as $vechi) {
        if (file_exists($iesire . '/' . $vechi) || is_link($iesire . '/' . $vechi)) {
            throw new EroareCaptura('folosire', 'dosarul de ieșire are deja o captură (' . $vechi . '): dă un --iesire= gol');
        }
    }
    $captura = new Captura($url, $iesire, $normalizare);
    $captura->pregatesteIesirea();
    $antet = ['mod' => $mod, 'url' => $url, 'raspunsuri_ua' => $uaR, 'feluri' => $feluri, 'ritm_s' => $ritm, 'buget' => $buget];
    // 1. Lista adreselor și bugetul (fără nicio cerere: inventarul e citit din baza WordPress, doar citită).
    $inventar = $config !== null ? Inventar::citeste(SursaWordPress::deschide($config)) : null;
    $adrese = ListaAdrese::filtreaza($inventar !== null ? ListaAdrese::dinInventar($inventar, $reguli, $url) : ListaAdrese::dinFisier((string) $o['lista'], $url), $feluri);
    foreach ($inventar !== null ? ListaAdrese::avertismenteInventar($inventar) : [] as $t) {
        $captura->avertismente[] = $t;
    }
    // Aceeași socoteală ca planul, cu rezerva sitemap-urilor (recenzia 2, R1-9): un plan NU e și o captură refuzată.
    if ($mod === 'http') {
        $cuSitemap = !$o['fara-descoperire'] && array_filter($adrese, static fn (array $a): bool => $a['fel'] === 'raspuns'
            && preg_match('#/sitemap(_index)?\.xml$#', $a['cale']) === 1) !== [];
        $totalAplica = Captura::plan($adrese, $uaR)['cereri'] + ($cuSitemap ? REZERVA_SITEMAP_DOCUMENTE * count(Captura::ua('raspuns', $uaR)) * Captura::REPETARI : 0);
        if ($totalAplica > $buget) {
            throw new EroareCaptura('folosire', 'cererile planificate cu rezervele (' . $totalAplica . ') depășesc bugetul (' . $buget . '): vezi --plan');
        }
    }
    // Captura urcată (lanțul sha256 întreg) sau oglinda, verificate înaintea oricărei cereri (și a celor ale integrității).
    $sursa = match ($mod) {
        'din-dosar' => DosarCapturi::deschideVerificat((string) $o['din-dosar'], (string) $o['sha-raport'], $url),
        'din-oglinda' => DosarCapturi::deschide((string) $o['din-oglinda'], $o['harta'] !== null ? (string) $o['harta'] : null),
        default => null,
    };
    // 2. Integritatea, înaintea oricărei cereri a capturii (DECIZIE §2.9): cu gazdele ei; un NU oprește captura și copierea (cod 2).
    if ($dosarWp !== null && $o['fara-integritate'] === null) {
        $ci = new Cereri(Integritate::GAZDE, $integritatePlan + 2, $ritm, 3, 6, 600, $captura->iesire . '/' . Captura::JURNAL_INTEGRITATE);
        $iv = Integritate::verifica($dosarWp, $ci);
        file_put_contents($captura->iesire . '/integritate.json', Captura::json($iv));
        $antet['integritate'] = ['stare' => $iv['stare'], 'versiune' => $iv['versiune'], 'limba' => $iv['limba'], 'cereri' => $ci->facute(),
            'sha256' => hash_file('sha256', $captura->iesire . '/integritate.json')];
        foreach ($iv['avertismente'] as $t) {
            $scrie('  ATENȚIE', $t);
        }
        if ($iv['stare'] !== Integritate::TRECUT) {
            foreach ($iv['probleme'] as $t) {
                $scrie('  NU', $t);
                $captura->probleme[] = 'integritatea: ' . $t;
            }
            $oprit = 'integritatea WordPress nu trece: nimic capturat, nimic copiat';
            echo "NU: integritatea WordPress nu trece; nimic capturat, nimic copiat.\n";
            $scrieRaportul();
            exit(2);
        }
        $scrie('  ..', 'integritatea WordPress: trecută (' . $iv['versiune'] . ', ' . $iv['limba'] . '; ' . $ci->facute() . ' cereri)');
    } else {
        $antet['integritate'] = Integritate::inAsteptare((string) ($o['fara-integritate'] ?? ''));
        $scrie('  ATENȚIE', 'integritatea: sărită (' . $antet['integritate']['motiv'] . '), „' . Integritate::IN_ASTEPTARE . '”');
    }
    // 3. Captura: prin HTTP, dintr-o captură urcată (verificată) sau dintr-o oglindă locală (neverificată).
    $inainteDeCaptura = false;
    if ($sursa !== null) {
        if ($mod === 'din-oglinda') {
            $captura->avertismente[] = 'din oglindă: răspunsurile nu au lanțul sha256 al unei capturi (doar pentru iterațiile locale)';
        }
        $captura->dinDosar($adrese, $sursa, $scrie);
    } else {
        $gazde = [strtolower((string) parse_url($url, PHP_URL_HOST)) . (parse_url($url, PHP_URL_PORT) !== null ? ':' . parse_url($url, PHP_URL_PORT) : '')];
        $cereri = new Cereri($gazde, $buget, $ritm, 3, 6, 600, $captura->iesire . '/' . Captura::JURNAL);
        // Fonturile Google: alte gazde, alt buget (plafonul tare), același jurnal pe disc.
        $cereriFonturi = $o['fonturi'] ? new Cereri([FonturiGoogle::GAZDA_CSS, FonturiGoogle::GAZDA_FONTURI], FonturiGoogle::BUGET, $ritm, 3, 6, 600,
            $captura->iesire . '/' . Captura::JURNAL) : null;
        $captura->prinHttp($adrese, $cereri, $uaR, $scrie, !$o['fara-descoperire']);
    }
    // 4. Manifestul (cu foile citite recursiv), apoi copierea în arborii copiați și fonturile.
    $manifest = $captura->manifest($dosarWp, $oglinda);
    $scrie('  ..', 'manifestul: ' . count($manifest['pagini']) . ' adrese, ' . count($manifest['fisiere']) . ' fișiere cu sha256, ' . count($manifest['lipsa'])
        . ' negăsite, ' . count($manifest['externe']) . ' gazde externe');
    // Fonturile întâi (recenzia 3, R1-N2): aduse și verificate înaintea copierii statice, ca niciun @import rescris să nu trimită la
    // o foaie nepublicată; o eroare a lor oprește rularea înaintea oricărei copii.
    // Foi Google cerute de pagini sau de foile proprii (un @import rescris spre copia locală): fără --fonturi, copia locală n-ar exista.
    if ($public !== null && !$o['fonturi'] && array_filter($captura->resurse, static fn (array $r): bool => FonturiGoogle::esteFoaie($r['url'])) !== []) {
        $captura->probleme[] = 'paginile sau foile lor cer fonturi Google: copia lor locală se face doar cu --fonturi';
    }
    // Fonturile Google: prin rețea (cu bugetul lor), în docroot-ul nou (--public) sau în dosarul capturii, cu sha256-ul în manifest;
    // dintr-o captură urcată (--din-dosar, verificată), doar publicate pe copie, fiecare fișier cu sha256-ul din manifest.
    if ($o['fonturi'] && $cereriFonturi !== null) {
        $fg = new FonturiGoogle($cereriFonturi);
        $foi = [];
        // Întâi în dosarul capturii (cu sha256-ul în manifest), apoi, cu --public, publicate toate odată, verificate (recenzia 2,
        // R1-6): o foaie sau un font care pică nu lasă o publicare pe jumătate.
        $dosarFonturi = $captura->iesire . '/' . FonturiGoogle::DOSAR_CAPTURA;
        foreach ($captura->resurse as $r) {
            if (FonturiGoogle::esteFoaie($r['url']) && !isset($foi[$r['url']])) {
                $foi[$r['url']] = $fg->aduce($r['url'], $dosarFonturi);
                $scrie('  ..', 'fonturile Google: ' . $foi[$r['url']]['css'] . ' (' . count($foi[$r['url']]['fonturi']) . ' fișiere)');
            }
        }
        $captura->inregistreazaFonturi($foi, true);
        if ($public !== null) {
            $lista = [];
            foreach ($foi as $u => $f) {
                $lista[] = ['url' => $u] + $f;
            }
            $scrie('  ..', 'fonturile Google publicate în ' . $public . FonturiGoogle::DOSAR . ': ' . CopiereStatica::publicaFonturi($captura->iesire, $lista, $public) . ' fișiere');
        }
        $scrie('  ..', 'fonturile Google: ' . count($foi) . ' foi, ' . $fg->fonturiAduse() . ' fonturi distincte, ' . $cereriFonturi->facute() . ' cereri din ' . FonturiGoogle::BUGET);
        $antet['cereri_fonturi'] = $cereriFonturi->facute();
    }
    // Recenzia 2, R1-6: dintr-o captură urcată, fiecare fișier static de pe disc trebuie să fie cel din manifestul ei verificat
    // (același sha256 al sursei), verificat înaintea primei copii; o nepotrivire e NU și nu se publică nimic.
    $deCopiatBlocat = false;
    if ($public !== null && $dosarWp !== null && $mod === 'din-dosar' && $sursa !== null) {
        foreach (CopiereStatica::nepotriviriManifest($manifest['fisiere'], (array) ($sursa->manifest()['fisiere'] ?? [])) as $pb) {
            $captura->probleme[] = $pb;
            $deCopiatBlocat = true;
        }
        if ($deCopiatBlocat) {
            $scrie('  NU', 'fișierele statice nu se copiază: discul nu e cel din captura urcată');
        }
    }
    // Fonturile unei capturi urcate: verificate și publicate înaintea copierii statice (recenzia 2, R1-6).
    if ($o['fonturi'] && $sursa !== null && $mod === 'din-dosar' && $public !== null && !$deCopiatBlocat) {
        // Recenzia 3, R1-N1: fiecare foaie Google cerută de pagini trebuie să fie în manifestul capturii urcate.
        $urcate = array_map(static fn ($x): string => is_array($x) ? FonturiGoogle::cheie((string) ($x['url'] ?? '')) : '', (array) ($sursa->manifest()['fonturi'] ?? []));
        foreach ($captura->resurse as $r) {
            if (FonturiGoogle::esteFoaie($r['url']) && !in_array(FonturiGoogle::cheie($r['url']), $urcate, true)) {
                $captura->probleme[] = 'foaia de fonturi Google ' . $r['url'] . ' lipsește din captura urcată (fără ea, legătura paginii ar da 404)';
                $deCopiatBlocat = true;
            }
        }
    }
    if ($o['fonturi'] && $sursa !== null && $mod === 'din-dosar' && $public !== null && !$deCopiatBlocat) {
        $n = CopiereStatica::publicaFonturi((string) $o['din-dosar'], (array) ($sursa->manifest()['fonturi'] ?? []), $public);
        $scrie('  ..', 'fonturile Google din captura urcată, publicate în ' . $public . FonturiGoogle::DOSAR . ': ' . $n . ' fișiere');
    }
    if ($public !== null && $dosarWp !== null && !$deCopiatBlocat) {
        $copiate = 0;
        foreach ($manifest['fisiere'] as $f) {
            if ($f['sursa'] !== 'disc') {
                continue;
            }
            if (($col = CopiereStatica::coliziune($f['cale'], $public)) !== null) {
                $captura->probleme[] = 'necopiat (coliziune): ' . $col;
                $captura->inregistreazaCopia($f['url'], null, $col);
                continue;
            }
            try {
                $copie = CopiereStatica::copiaza($dosarWp, $public, $f['cale'], $normalizare);
                // Un fișier schimbat între manifest și copiere (recenzia 2, R1-4): copia se scoate din docroot și e NU.
                if (!hash_equals((string) $f['sha256_sursa'], (string) $copie['sha256_sursa'])) {
                    @unlink(rtrim($public, '/') . $copie['publicat']);
                    $captura->probleme[] = 'fișierul s-a schimbat între manifest și copiere (copia scoasă): ' . $f['cale'];
                    $captura->inregistreazaCopia($f['url'], null, 'schimbat între manifest și copiere');
                    continue;
                }
                $captura->inregistreazaCopia($f['url'], $copie);
                $copiate++;
            } catch (EroareCaptura $e) {
                $captura->inregistreazaCopia($f['url'], null, $e->getMessage());
                if ($f['fel'] === 'script') {
                    $scrie('  ..', 'necopiat (script, aruncat): ' . $f['cale']);
                } else {
                    $captura->avertismente[] = 'necopiat: ' . $e->getMessage();
                }
            }
        }
        $scrie('  ..', 'copiate în ' . $public . ': ' . $copiate . ' fișiere statice (doar în ' . implode('/, ', CopiereStatica::ARBORI) . '/)');
    }
    foreach ($captura->adrese as $a) {
        if (in_array($a['fel'], ListaAdrese::HTML, true) && $a['status'] === 200) {
            foreach (Integritate::ascunse((string) file_get_contents($captura->iesire . '/' . $a['referinta']), $normalizare->gazde()) as $h) {
                $captura->avertismente[] = 'link extern într-un element ascuns pe ' . $a['cale'] . ': ' . $h . ' (spam SEO? de revizuit de om)';
            }
        }
    }
    $cod = $captura->probleme === [] ? 0 : 1;
} catch (EroareCaptura | EroareImport $e) {
    $oprit = $e->getMessage();
    fwrite(STDERR, 'NU: ' . $e->getMessage() . "\n");
    $cod = $inainteDeCaptura || $e instanceof EroareCaptura && in_array($e->fel, ['folosire', 'lant', 'integritate'], true) ? 2 : 1;
    if ($cod === 2) {
        $antet['refuzat'] = true; // raportul unei rulări refuzate nu blochează refolosirea dosarului
    }
    if ($captura !== null) {
        $captura->probleme[] = 'oprit: ' . $e->getMessage();
    }
}
if ($captura !== null) {
    foreach ($captura->info as $t) {
        $scrie('  ..', $t);
    }
    foreach ($captura->avertismente as $t) {
        $scrie('  ATENȚIE', $t);
    }
    foreach ($captura->probleme as $t) {
        $scrie('  NU', $t);
    }
    $scrie('  ..', 'cereri făcute: ' . ($cereri?->facute() ?? 0) . ' din ' . $buget);
    $scrieRaportul();
    echo $cod === 0 ? "GATA: captura e scrisă în {$captura->iesire}.\n" : 'NU: ' . count($captura->probleme) . " probleme (mai sus); captura scrisă în {$captura->iesire}.\n";
}
exit($cod);
