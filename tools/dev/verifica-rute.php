<?php

declare(strict_types=1);

/**
 * Verifică harta pluginurilor (config/plugins.php) față de cod:
 *
 *  1. fiecare rută din router (src/Http/rute.php) are un proprietar explicit:
 *     nucleul sau un plugin, fără ambiguitate (două declarații la fel de precise,
 *     cu proprietari diferiți);
 *  2. fiecare declarație de rută din catalog acoperă cel puțin o rută reală;
 *  3. rutele împărțite există și trimit spre chei cunoscute;
 *  4. fiecare intrare din meniu duce la o rută a aceluiași plugin;
 *  5. cronurile din catalog există și au garda; orice script cu gardă e în catalog;
 *  6. dependențele și pachetele folosesc chei cunoscute, fără cerc;
 *  7. cheile pluginurilor sunt exact cele 26 hotărâte + unealta internă;
 *  8. codul întreabă doar de chei care există (Plugins::activ('…') etc.);
 *  9. în șabloane (views/), fiecare bloc `<?php if (…): ?>` … `<?php endif; ?>`
 *     scris pe coloana 0 (așa se ascunde bucata unui plugin) închide tot ce
 *     deschide: un `endif` pus greșit ar lăsa, cu pluginul oprit, jumătate de
 *     rând de tabel sau un `</article>` fără pereche;
 * 10. fiecare adresă „mereu deschisă” din catalog (webhook-uri, întoarceri de
 *     plată, ERP, linkurile din emailuri) e exclusă din redirecționările SEO
 *     (App\Support\SeoRedirects): o confirmare de plată nu primește niciodată 301;
 * 11. nucleul nu pomenește un client (planul de scoatere a numelor de client, §7.4, DB-04;
 *     adusă în WP-16): niciun termen din lista neagră (numele Bioscem, firma și
 *     reprezentantul de pe acordul GDPR, conferința, produsele și categoriile Bioscem,
 *     sloganul), ca un cuvânt întreg, fără deosebire de litere mari, în src/, views/,
 *     config/, scripts/, public/ (fără uploads/, tema/ și assets/vendor/), database/,
 *     bootstrap.php, index.php și .env.example, comentariile incluse. În **mod clichet**:
 *     ce mai e azi stă în tools/dev/nume-client-ramase.txt (fișier → număr de potriviri);
 *     un fișier nou cu potriviri, sau mai multe decât în listă, pică, iar unul cu mai
 *     puține cere lista micșorată (`--actualizeaza-nume-client` o rescrie: lista doar
 *     scade). Lista goală = verificarea strictă din plan.
 *
 * Listează toate rutele cu proprietarul lor. Cod de ieșire 0 doar dacă totul e în regulă.
 *
 *   php tools/dev/verifica-rute.php                              # raport complet
 *   php tools/dev/verifica-rute.php --scurt                      # doar problemele și sumarul
 *   php tools/dev/verifica-rute.php --actualizeaza-nume-client   # rescrie lista clichetului (11) după ce s-au scos nume
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Http\Router;
use App\Support\Plugins;

$scurt = in_array('--scurt', $argv, true);
$probleme = [];
$problema = static function (string $text) use (&$probleme): void {
    $probleme[] = $text;
};

$catalog = Plugins::catalog();
$pluginuri = $catalog['pluginuri'];
$cheiValide = array_merge(['nucleu'], array_keys($pluginuri));

// 7. Cheile hotărâte de proprietar (ANALIZA.md, secțiunea 3), plus unealta internă.
$cheiHotarate = ['catalog', 'magazin', 'livrare_fan', 'plata_stripe', 'plata_euplatesc', 'plata_bt', 'link_plata', 'erp',
    'cupoane', 'fidelitate', 'recenzii', 'precomenzi', 'oferte_bbd', 'produse_promo', 'pachete_produse', 'conturi_clienti',
    'blog', 'newsletter', 'chat_live', 'donatii', 'membri', 'cereri_oferta', 'fisiere_cloud', 'concurenta', 'manechin',
    'acorduri_gdpr', 'migrare_wordpress'];
$lipsa = array_diff($cheiHotarate, array_keys($pluginuri));
$inPlus = array_diff(array_keys($pluginuri), $cheiHotarate);
if ($lipsa !== []) {
    $problema('Catalogul nu are pluginurile: ' . implode(', ', $lipsa));
}
if ($inPlus !== []) {
    $problema('Catalogul are chei nehotărâte: ' . implode(', ', $inPlus));
}
foreach ($pluginuri as $cheie => $definitie) {
    foreach (['nume', 'descriere'] as $camp) {
        if (trim((string) ($definitie[$camp] ?? '')) === '') {
            $problema('Pluginul „' . $cheie . '” nu are „' . $camp . '”.');
        }
    }
    if (($definitie['intern'] ?? false) === true) {
        foreach ($catalog['pachete'] as $numePachet => $pachet) {
            if (in_array($cheie, array_merge($pachet['pluginuri'] ?? [], $pachet['optionale'] ?? [], $pachet['cel_putin_una_din'] ?? []), true)) {
                $problema('Unealta internă „' . $cheie . '” apare în pachetul „' . $numePachet . '”.');
            }
        }
    }
    if (($definitie['portat'] ?? true) === false) {
        foreach (['rute_admin', 'rute_publice', 'rute_mereu_deschise', 'meniu', 'cron', 'pagini'] as $camp) {
            if (($definitie[$camp] ?? []) !== []) {
                $problema('Pluginul neportat „' . $cheie . '” are deja „' . $camp . '”.');
            }
        }
    }
}

// 6. Dependențe și pachete.
foreach ($pluginuri as $cheie => $definitie) {
    foreach ((array) ($definitie['necesita'] ?? []) as $nevoie) {
        foreach ((array) $nevoie as $dep) {
            if (!isset($pluginuri[$dep])) {
                $problema('Pluginul „' . $cheie . '” are nevoie de „' . $dep . '”, care nu există.');
            }
        }
    }
}
$vizitat = [];
$cerc = static function (string $cheie, array $drum) use (&$cerc, &$vizitat, $pluginuri, $problema): void {
    if (in_array($cheie, $drum, true)) {
        $problema('Dependență circulară: ' . implode(' → ', array_merge($drum, [$cheie])));
        return;
    }
    if (isset($vizitat[$cheie])) {
        return;
    }
    foreach ((array) ($pluginuri[$cheie]['necesita'] ?? []) as $nevoie) {
        foreach ((array) $nevoie as $dep) {
            if (isset($pluginuri[$dep])) {
                $cerc((string) $dep, array_merge($drum, [$cheie]));
            }
        }
    }
    $vizitat[$cheie] = true;
};
foreach (array_keys($pluginuri) as $cheie) {
    $cerc((string) $cheie, []);
}
foreach ($catalog['pachete'] as $numePachet => $pachet) {
    foreach (array_merge($pachet['pluginuri'] ?? [], $pachet['optionale'] ?? [], $pachet['cel_putin_una_din'] ?? []) as $cheie) {
        if (!isset($pluginuri[$cheie])) {
            $problema('Pachetul „' . $numePachet . '” are pluginul necunoscut „' . $cheie . '”.');
        }
    }
    // Fiecare plugin din pachet are dependențele tot în pachet.
    $inPachet = array_merge($pachet['pluginuri'] ?? [], $pachet['cel_putin_una_din'] ?? []);
    $rezultat = Plugins::calculeazaStari(array_fill_keys($inPachet, 'pornit'));
    foreach ($rezultat['mesaje'] as $mesaj) {
        $problema('Pachetul „' . $numePachet . '”: ' . $mesaj);
    }
}

// 1–3. Rutele.
$router = new Router();
(require $radacina . '/src/Http/rute.php')($router);
$rute = $router->rute();
$declaratiiFolosite = [];
$linii = [];
foreach ($rute as $ruta) {
    $metoda = $ruta['metoda'];
    $tipar = $ruta['tipar'];
    $eticheta = str_pad($metoda, 5) . ' ' . $tipar;
    $impartita = Plugins::impartitaNormalizata($metoda, $tipar);
    if ($impartita !== null) {
        $descriere = [];
        foreach ($impartita as [$conditii, $tinta]) {
            foreach ((array) $tinta as $t) {
                if (!in_array($t, $cheiValide, true)) {
                    $problema($eticheta . ': regula trimite spre cheia necunoscută „' . $t . '”.');
                }
            }
            $cond = [];
            foreach ((array) $conditii as $k => $v) {
                $cond[] = $k . '=' . implode('|', (array) $v);
            }
            $descriere[] = ($cond === [] ? 'altfel' : implode(' & ', $cond)) . ' → ' . implode('|', (array) $tinta);
        }
        if (end($impartita)[0] !== []) {
            $problema($eticheta . ': regulile împărțite nu se termină cu o regulă fără condiții (cine răspunde altfel?).');
        }
        $linii[] = sprintf('  %-62s %s', $eticheta, 'după parametri: ' . implode('; ', $descriere));
        continue;
    }

    $potriviri = Plugins::declaratiiPentru($metoda, $tipar);
    if ($potriviri === []) {
        $problema($eticheta . ': nicio declarație în config/plugins.php (nici nucleu, nici plugin).');
        $linii[] = sprintf('  %-62s %s', $eticheta, '??? FĂRĂ PROPRIETAR');
        continue;
    }
    $prima = $potriviri[0];
    foreach ($potriviri as $p) {
        $declaratiiFolosite[$p['sursa']] = true;
    }
    if (isset($potriviri[1]) && $potriviri[1]['scor'] === $prima['scor'] && $potriviri[1]['proprietar'] !== $prima['proprietar']) {
        $problema($eticheta . ': ambiguă între „' . $prima['sursa'] . '” și „' . $potriviri[1]['sursa'] . '”.');
    }
    $proprietar = $prima['proprietar'];
    $extra = $prima['mereu_deschis'] ? '  [mereu deschisă]' : '';
    if ($tipar === '/{slug*}') {
        $pagini = [];
        foreach ($pluginuri as $cheie => $definitie) {
            foreach ((array) ($definitie['pagini'] ?? []) as $pagina) {
                $pagini[] = $pagina . '→' . $cheie;
            }
        }
        $extra .= '  (pagini din bază ale pluginurilor: ' . implode(', ', $pagini) . ')';
    }
    $linii[] = sprintf('  %-62s %s', $eticheta, $proprietar . $extra);
}

foreach (Plugins::listaDeclaratii() as $declaratie) {
    if (!isset($declaratiiFolosite[$declaratie['sursa']])) {
        $problema('Declarația „' . $declaratie['sursa'] . '” nu acoperă nicio rută.');
    }
}
foreach (array_keys($catalog['rute_impartite']) as $cheieImpartita) {
    [$m, $t] = array_pad(explode(' ', (string) $cheieImpartita, 2), 2, '');
    $exista = false;
    foreach ($rute as $ruta) {
        if ($ruta['metoda'] === strtoupper($m) && Plugins::normalizeazaTipar($ruta['tipar']) === Plugins::normalizeazaTipar($t)) {
            $exista = true;
        }
    }
    if (!$exista) {
        $problema('Ruta împărțită „' . $cheieImpartita . '” nu există în router.');
    }
}
$pagini = [];
foreach ($pluginuri as $cheie => $definitie) {
    foreach ((array) ($definitie['pagini'] ?? []) as $pagina) {
        if (isset($pagini[$pagina])) {
            $problema('Pagina „' . $pagina . '” e declarată de „' . $pagini[$pagina] . '” și de „' . $cheie . '”.');
        }
        $pagini[$pagina] = $cheie;
    }
}

// 4. Meniul.
$grupuri = array_column($catalog['meniu_admin'], 'cheie');
$intrari = [];
foreach (array_merge(['nucleu' => $catalog['nucleu']], $pluginuri) as $cheie => $definitie) {
    foreach ((array) ($definitie['meniu'] ?? []) as $intrare) {
        $intrari[] = [$cheie, $intrare];
    }
}
foreach ($intrari as [$cheie, $intrare]) {
    $href = (string) ($intrare['href'] ?? '');
    $eticheta = 'Meniu „' . ($intrare['eticheta'] ?? '?') . '” (' . $cheie . ')';
    if (!in_array($intrare['grup'] ?? '', $grupuri, true)) {
        $problema($eticheta . ': grupul „' . ($intrare['grup'] ?? '') . '” nu există în meniu_admin.');
    }
    // Eticheta / iconița care se schimbă fără un plugin: cheia lui trebuie să existe.
    foreach (['eticheta_fara', 'icon_fara'] as $cheieFara) {
        foreach (array_keys((array) ($intrare[$cheieFara] ?? [])) as $pluginLipsa) {
            if (!in_array((string) $pluginLipsa, $cheiValide, true)) {
                $problema($eticheta . ': ' . $cheieFara . ' pomenește pluginul necunoscut „' . $pluginLipsa . '”.');
            }
        }
    }
    $gasita = $router->gaseste('GET', $href);
    if ($gasita === null) {
        $problema($eticheta . ': adresa ' . $href . ' nu e o rută.');
        continue;
    }
    parse_str((string) parse_url($href, PHP_URL_QUERY), $get);
    $proprietar = Plugins::proprietarRuta('GET', $gasita['tipar'], $gasita['parametri'], $get, []);
    $asteptat = isset($intrare['oricare_din']) ? (array) $intrare['oricare_din'] : [$cheie];
    sort($asteptat);
    $real = $proprietar['chei'];
    sort($real);
    if ($real !== $asteptat) {
        $problema($eticheta . ': adresa ' . $href . ' e a lui „' . implode('|', $real) . '”, nu a lui „' . implode('|', $asteptat) . '”.');
    }
}

// 5. Cronurile.
$cronuriCatalog = [];
foreach ($pluginuri as $cheie => $definitie) {
    foreach ((array) ($definitie['cron'] ?? []) as $fisier => $cerinta) {
        $cronuriCatalog[$fisier] = $cheie;
        if (!in_array($cerinta, ['vizibil', 'pornit'], true)) {
            $problema('Cronul ' . $fisier . ' (' . $cheie . ') cere starea necunoscută „' . $cerinta . '”.');
        }
        if (!is_file($radacina . '/' . $fisier)) {
            $problema('Cronul ' . $fisier . ' (' . $cheie . ') nu există.');
        } elseif (!str_contains((string) file_get_contents($radacina . '/' . $fisier), 'Plugins::cronPermis(__FILE__)')) {
            $problema('Cronul ' . $fisier . ' (' . $cheie . ') nu are garda Plugins::cronPermis(__FILE__).');
        }
    }
}
foreach (glob($radacina . '/scripts/*.php') ?: [] as $script) {
    $relativ = 'scripts/' . basename($script);
    if (str_contains((string) file_get_contents($script), 'Plugins::cronPermis(') && !isset($cronuriCatalog[$relativ])) {
        $problema('Scriptul ' . $relativ . ' are garda de cron, dar nu e în catalogul niciunui plugin.');
    }
}

// 8. Codul întreabă doar de chei care există.
$fisiere = [];
foreach (['src', 'views', 'scripts', 'public', 'config'] as $dosar) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radacina . '/' . $dosar, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fisier) {
        if ($fisier->isFile() && str_ends_with($fisier->getFilename(), '.php')) {
            $fisiere[] = $fisier->getPathname();
        }
    }
}
$intrebari = 0;
foreach ($fisiere as $fisier) {
    $text = (string) file_get_contents($fisier);
    preg_match_all("/Plugins::(?:activ|stare|vizibil|permiteInceput|redirectionare)\\(\\s*'([^']*)'/", $text, $m);
    preg_match_all("/Plugins::oricareVizibil\\(\\s*\\[([^\\]]*)\\]/", $text, $liste);
    $chei = $m[1];
    foreach ($liste[1] as $lista) {
        preg_match_all("/'([^']*)'/", $lista, $din);
        $chei = array_merge($chei, $din[1]);
    }
    foreach ($chei as $cheie) {
        $intrebari++;
        if (!in_array($cheie, $cheiValide, true)) {
            $problema(substr($fisier, strlen($radacina) + 1) . ': întreabă de pluginul necunoscut „' . $cheie . '”.');
        }
    }
}

// 9. Blocurile de pe coloana 0 din șabloane închid HTML-ul pe care îl deschid.
// Excepțiile vin din codul Bioscem așa cum era (eticheta tolerată, pe bloc):
// două blocuri vechi care nu țin de pluginuri și caseta „Facturare” din fișa
// clientului, al cărei `<article>` nu s-a închis niciodată explicit (browserul
// îl închide la `</div>`-ul de după; cu magazinul oprit caseta lipsește întreagă).
$dezechilibrateDinBaza = [
    'views/admin/emails.php|$currentSection === \'newsletters\'' => ['div'],
    'views/site/custom-page.php|$registerAntiBot !== []' => ['label'],
    'views/admin/users.php|$areMagazin' => ['article'],
];
$etichete = ['div', 'tr', 'td', 'th', 'table', 'thead', 'tbody', 'article', 'section', 'form', 'ul', 'li', 'p', 'label', 'span',
    'select', 'optgroup', 'nav', 'button', 'details', 'fieldset', 'small', 'strong', 'a', 'h2', 'h3', 'h4'];
$dezechilibru = static function (string $html, array $tolerate) use ($etichete): array {
    $html = (string) preg_replace('/<\?(?:php|=).*?\?>/s', '', $html);
    $diferente = [];
    foreach ($etichete as $eticheta) {
        if (in_array($eticheta, $tolerate, true)) {
            continue;
        }
        $deschise = preg_match_all('/<' . $eticheta . '(?:\s|>)/', $html);
        $inchise = preg_match_all('/<\/' . $eticheta . '\s*>/', $html);
        if ($deschise !== $inchise) {
            $diferente[] = $eticheta . ' ' . $deschise . '/' . $inchise;
        }
    }
    return $diferente;
};
$blocuri = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radacina . '/views', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $fisier) {
    if (!$fisier->isFile() || !str_ends_with($fisier->getFilename(), '.php')) {
        continue;
    }
    $relativ = substr($fisier->getPathname(), strlen($radacina) + 1);
    $randuri = explode("\n", (string) file_get_contents($fisier->getPathname()));
    $stiva = [];
    foreach ($randuri as $i => $rand) {
        if (preg_match('/^<\?php if \((.*)\): \?>\s*$/', $rand, $m) === 1) {
            $stiva[] = ['inceput' => $i, 'else' => null, 'conditie' => $m[1]];
        } elseif (preg_match('/^<\?php else: \?>\s*$/', $rand) === 1 && $stiva !== []) {
            $stiva[count($stiva) - 1]['else'] = $i;
        } elseif (preg_match('/^<\?php endif; \?>\s*$/', $rand) === 1) {
            if ($stiva === []) {
                $problema($relativ . ':' . ($i + 1) . ': `endif` pe coloana 0 fără `if`.');
                continue;
            }
            $bloc = array_pop($stiva);
            $blocuri++;
            $tolerate = $dezechilibrateDinBaza[$relativ . '|' . $bloc['conditie']] ?? [];
            $bucati = $bloc['else'] === null
                ? [[$bloc['inceput'], $i]]
                : [[$bloc['inceput'], $bloc['else']], [$bloc['else'], $i]];
            foreach ($bucati as [$de, $pana]) {
                $diferente = $dezechilibru(implode("\n", array_slice($randuri, $de + 1, $pana - $de - 1)), $tolerate);
                if ($diferente !== []) {
                    $problema($relativ . ':' . ($de + 1) . '-' . ($pana + 1) . ': blocul „if (' . $bloc['conditie']
                        . ')” nu închide ce deschide (deschise/închise: ' . implode(', ', $diferente) . ').');
                }
            }
        }
    }
    if ($stiva !== []) {
        $problema($relativ . ': `if` pe coloana 0 fără `endif`.');
    }
}

// 10. Adresele mereu deschise nu se redirecționează niciodată (stratul SEO).
foreach (array_merge(['nucleu' => $catalog['nucleu']], $pluginuri) as $cheie => $definitie) {
    foreach ((array) ($definitie['rute_mereu_deschise'] ?? []) as $text) {
        $cale = (string) preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\*?\}#', 'x', (string) preg_replace('#^(GET|POST)\s+#', '', (string) $text));
        if (!\App\Support\SeoRedirects::eExclusa($cale)) {
            $problema('Adresa mereu deschisă ' . $text . ' (' . $cheie . ') nu e exclusă din redirecționările SEO (SeoRedirects::caiExcluse).');
        }
    }
}

// 11. Nucleul nu pomenește un client: lista neagră, în mod clichet (tools/dev/nume-client-ramase.txt doar scade).
$listaNeagra = ['bioscem', 'amalbo', 'tătaru', 'tataru', 'neurocare', 'rațiu', 'ratiu', '19166493', 'j2006017724407', '0756.195.323',
    'quinton', 'vivomixx', 'siliciu organic', 'info pacienți', 'info medici', 'vitalitate și protecție', 'jurnalul bioscem'];
$fisierRamase = $radacina . '/tools/dev/nume-client-ramase.txt';
$potriviriClient = [];
$candidati = [];
foreach (['src', 'views', 'config', 'scripts', 'public', 'database'] as $dosarCod) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radacina . '/' . $dosarCod, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $candidati[] = substr($f->getPathname(), strlen($radacina) + 1);
    }
}
foreach (['bootstrap.php', 'index.php', '.env.example'] as $fisierCod) {
    if (is_file($radacina . '/' . $fisierCod)) {
        $candidati[] = $fisierCod;
    }
}
foreach ($candidati as $relativ) {
    if (preg_match('#^public/(uploads|tema|assets/vendor)/#', $relativ) === 1
        || preg_match('/\.(png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf|eot|pdf|zip|gz)$/i', $relativ) === 1) {
        continue;
    }
    $text = (string) file_get_contents($radacina . '/' . $relativ);
    $n = 0;
    foreach ($listaNeagra as $termen) {
        $n += (int) preg_match_all('/(?<![\p{L}\p{N}])' . preg_quote($termen, '/') . '(?![\p{L}\p{N}])/iu', $text);
    }
    if ($n > 0) {
        $potriviriClient[$relativ] = $n;
    }
}
ksort($potriviriClient, SORT_STRING);
if (in_array('--actualizeaza-nume-client', $argv, true)) {
    $text = "# Clichetul verificării 11 din tools/dev/verifica-rute.php: potrivirile din lista neagră (numele unui client) care mai\n"
        . "# sunt azi în nucleu, pe fișiere. Lista doar scade; goală = verificarea strictă. Rescrisă de\n"
        . "# tools/dev/php.sh tools/dev/verifica-rute.php --actualizeaza-nume-client\n";
    foreach ($potriviriClient as $relativ => $n) {
        $text .= $n . "\t" . $relativ . "\n";
    }
    file_put_contents($fisierRamase, $text);
    echo 'Lista clichetului (11) rescrisă: ' . count($potriviriClient) . ' fișiere, ' . array_sum($potriviriClient) . " potriviri.\n";
}
$ramaseClient = [];
foreach (file(is_file($fisierRamase) ? $fisierRamase : '/dev/null', FILE_IGNORE_NEW_LINES) ?: [] as $rand) {
    if (preg_match('/^(\d+)\t(\S+)$/', $rand, $m) === 1) {
        $ramaseClient[$m[2]] = (int) $m[1];
    }
}
foreach ($potriviriClient as $relativ => $n) {
    if ($n > ($ramaseClient[$relativ] ?? 0)) {
        $problema('Nucleul pomenește un client: ' . $relativ . ' are ' . $n . ' potriviri din lista neagră (clichetul permite '
            . ($ramaseClient[$relativ] ?? 0) . '; tools/dev/nume-client-ramase.txt). Scrie neutru (de exemplu exemplu.ro, „Firma Demo”) '
            . 'sau mută valoarea în pachetul site-ului.');
    }
}
foreach ($ramaseClient as $relativ => $n) {
    if (($potriviriClient[$relativ] ?? 0) < $n) {
        $problema('Clichetul numelor de client (11) nu mai e la zi: ' . $relativ . ' are ' . ($potriviriClient[$relativ] ?? 0) . ' potriviri, '
            . 'lista ' . $n . '. Micșorează lista: tools/dev/php.sh tools/dev/verifica-rute.php --actualizeaza-nume-client');
    }
}

// Raportul.
if (!$scurt) {
    echo 'Rutele din router și proprietarul lor (' . count($rute) . "):\n";
    echo implode("\n", $linii), "\n\n";
}
$numarate = [];
foreach ($rute as $ruta) {
    $p = Plugins::proprietarRuta($ruta['metoda'], $ruta['tipar']);
    $numarate[implode('|', $p['chei'])] = ($numarate[implode('|', $p['chei'])] ?? 0) + 1;
}
arsort($numarate);
echo 'Rute: ' . count($rute) . '; declarații în catalog: ' . count(Plugins::listaDeclaratii())
    . '; reguli împărțite: ' . count($catalog['rute_impartite']) . '; intrări de meniu: ' . count($intrari)
    . '; cronuri: ' . count($cronuriCatalog) . '; întrebări Plugins::… în cod: ' . $intrebari
    . '; blocuri pe coloana 0 în șabloane: ' . $blocuri
    . '; nume de client în nucleu (clichetul 11): ' . array_sum($potriviriClient) . ' în ' . count($potriviriClient) . " fișiere\n";
$bucati = [];
foreach ($numarate as $cheie => $n) {
    $bucati[] = $cheie . ' ' . $n;
}
echo 'Rute pe proprietar (fără parametri): ' . implode(', ', $bucati) . "\n";

if ($probleme !== []) {
    echo "\nPROBLEME (" . count($probleme) . "):\n";
    foreach ($probleme as $p) {
        echo '  - ' . $p . "\n";
    }
    exit(1);
}
echo "\nOK: fiecare rută are un proprietar explicit, catalogul corespunde codului.\n";
exit(0);
