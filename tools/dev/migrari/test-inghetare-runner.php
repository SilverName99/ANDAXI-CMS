<?php

declare(strict_types=1);

/**
 * Secțiunea I din tools/dev/test-migrari.php: pasul de înghețare a setărilor al runner-ului
 * (site/<site>/setari-inghetate; docs/MIGRARI.md, „Înghețarea setărilor”; WP-06), pe fiecare server, cu
 * migrări de jucărie și un pachet de site inventat (setari-inghetate.json + privat/). Nu se rulează singur:
 * îl încarcă test-migrari.php în bucla serverelor (după secțiunea R) și folosește ce a pregătit acela
 * ($sock, $b, $cheiServer, $bazaNoua, $ruleaza, $scrieMigrare, $genereazaAsteptat, $randuri, $stareBaza,
 * $verifica, $scrie, $lucru …). Doar date inventate; adresa privată e pe domeniul rezervat .test.
 *
 *  - fără --site, cu înghețarea în pachet: 2; pachetul altui site: 2; fără fișierul privat: 15 (cheia, fără
 *    valoare), baza neatinsă;
 *  - planul: ce se scrie, ce se completează (goală), ce există; valoarea privată nu apare nicăieri;
 *  - aplicarea: `lipsa` scrie doar ce lipsește, `lipsa_sau_gol` și peste '' sau doar spații (nu peste un tab:
 *    TRIM al serverului), o valoare prezentă nu se schimbă niciodată; rândul pasului cu sha256 al fișierului;
 *    a doua oară nimic (aceleași rânduri); --plan 0;
 *  - fișierul aplicat schimbat: 12, baza neatinsă; o corectură setari-inghetate-2.json se aplică după; fișierul
 *    privat șters după aplicare nu contează; o înghețare aplicată care lipsește din pachet: 12 (doar cu --site);
 *  - provizoriu (fișierul, sau un parametru în migrari.json `_provizoriu`): 15 fără --permite-provizoriu, nimic
 *    scris; cu el, aplicat;
 *  - `pasi_site` în _platforma_stare cât pașii site-ului n-au terminat: după --pana-la înaintea lor și după un
 *    proces omorât între migrări și înghețare; ștearsă după ce s-au aplicat;
 *  - setare() într-o migrare care nu e pas al site-ului: 1.
 */

if (PHP_SAPI !== 'cli' || !isset($verifica, $ruleaza, $bazaNoua, $scrieMigrare, $sock, $b)) {
    fwrite(STDERR, "tools/dev/migrari/test-inghetare-runner.php se rulează din tools/dev/test-migrari.php\n");
    exit(2);
}

$scrie('I. Înghețarea setărilor (pasul site-ului)');

$regIng = $lucru . '/inghetare-reg-' . $cheiServer;
$scrieMigrare($regIng, 'nucleu/0001_setari', "        \$s->tabel('settings', '`key` VARCHAR(120) NOT NULL, `value` LONGTEXT NOT NULL, PRIMARY KEY (`key`)');");
$scrieMigrare($regIng, 'nucleu/0002_alt', "        \$s->tabel('alt_tabel', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');");
$genereazaAsteptat($sock, $regIng);

/** Un pachet de site inventat: setari-inghetate.json (și, cu $private, privat/setari-inghetate.json făcut pentru el). */
$pachetIng = static function (string $dosar, array $setari, array $private = [], array $sus = []): string {
    @mkdir($dosar . '/privat', 0777, true);
    $document = ['_despre' => 'Test', 'format' => 2, 'site' => 'demo', 'sursa' => 'cod-vechi-demo@abc1234'] + $sus
        + ['setari' => $setari === [] ? new stdClass() : $setari, 'fara_inghetare' => new stdClass()];
    $text = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($dosar . '/setari-inghetate.json', $text);
    if ($private !== []) {
        file_put_contents($dosar . '/privat/setari-inghetate.json', json_encode(['site' => 'demo', 'sursa' => 'cod-vechi-demo@abc1234',
            'pentru' => hash('sha256', $text), 'setari' => $private], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    } else {
        @unlink($dosar . '/privat/setari-inghetate.json');
    }
    return hash('sha256', $text);
};
$setariBaza = static fn (PDO $db): array => $db->query('SELECT `key`, `value` FROM settings ORDER BY `key`')->fetchAll(PDO::FETCH_KEY_PAIR);
$adresaPrivata = 'contact.client@client-inventat.test';
$setariPachet = [
    'a_lipsa' => 'nouă',
    'b_gol' => ['valoare' => 'completată', 'mod' => 'lipsa_sau_gol'],
    'c_exista' => ['valoare' => 'a nucleului', 'mod' => 'lipsa_sau_gol'],
    'd_gol_lipsa' => ['valoare' => 'nu se scrie', 'mod' => 'lipsa'],
    'e_spatii' => ['valoare' => 'peste spații', 'mod' => 'lipsa_sau_gol'],
    'f_tab' => ['valoare' => 'nu peste tab', 'mod' => 'lipsa_sau_gol'],
    'g_privat' => ['valoare' => 'adresa1@exemplu.ro', 'mod' => 'lipsa', 'privat' => true],
];
$pachet = $lucru . '/inghetare-pachet-' . $cheiServer;
$shaPachet = $pachetIng($pachet, $setariPachet);
$cuPachet = ['SITE_DATE_DOSAR' => $pachet];

$ib = $bazaNoua($sock, $b('ing'));
$ruleaza($sock, $b('ing'), $regIng, ['--aplica', '--pana-la=nucleu/0001_setari']);
$ib->exec("INSERT INTO settings (`key`, `value`) VALUES ('c_exista', 'a clientului'), ('d_gol_lipsa', ''), ('b_gol', ''), ('e_spatii', '   '),
    ('f_tab', CHAR(9))");
$inainte = $setariBaza($ib);
[$codFara, $outFara] = $ruleaza($sock, $b('ing'), $regIng, ['--plan'], $cuPachet);
[$codAlt, $outAlt] = $ruleaza($sock, $b('ing'), $regIng, ['--plan', '--site=alt'], $cuPachet);
[$codPriv, $outPriv, $errPriv] = $ruleaza($sock, $b('ing'), $regIng, ['--aplica', '--site=demo'], $cuPachet);
$verifica('înghețarea în pachet, rulare fără --site: 2 (înghețarea n-ar rula); pachetul altui site: 2; fără fișierul privat: 15, cu numele '
    . 'cheii și fără valoare; baza neatinsă',
    $codFara === 2 && str_contains($outFara, '--site=demo') && $codAlt === 2 && str_contains($outAlt, 'altui site')
    && $codPriv === 15 && str_contains($outPriv, 'g_privat') && !str_contains($outPriv . $errPriv, 'client-inventat')
    && $setariBaza($ib) === $inainte && !isset($randuri($ib)['site/demo/setari-inghetate']),
    $codFara . '/' . $codAlt . '/' . $codPriv . ' ' . $outFara . $outAlt . $outPriv);

$shaPachet = $pachetIng($pachet, $setariPachet, ['g_privat' => $adresaPrivata]);
[$codPlan, $outPlan, $errPlan] = $ruleaza($sock, $b('ing'), $regIng, ['--plan', '--site=demo'], $cuPachet);
[, $jsonPlan] = $ruleaza($sock, $b('ing'), $regIng, ['--plan', '--site=demo', '--json'], $cuPachet);
$verifica('planul pasului: ce se scrie (a_lipsa, g_privat ca „valoare privată”), ce se completează (b_gol, e_spatii), ce există (3); '
    . 'valoarea privată nicăieri (nici în --json)',
    $codPlan === 10 && str_contains($outPlan, 'site/demo/setari-inghetate') && str_contains($outPlan, 'settings.a_lipsa (lipsa) (se scrie: „nouă”)')
    && str_contains($outPlan, 'settings.g_privat (lipsa) (se scrie: valoare privată)')
    && str_contains($outPlan, 'settings.b_gol (lipsa_sau_gol) (se completează (e goală)')
    && str_contains($outPlan, 'settings.e_spatii (lipsa_sau_gol) (se completează (e goală)') && str_contains($outPlan, '(3 există deja)')
    && !str_contains($outPlan . $errPlan . $jsonPlan, 'client-inventat'), $outPlan);

[$codAp, $outAp, $errAp] = $ruleaza($sock, $b('ing'), $regIng, ['--aplica', '--site=demo'], $cuPachet);
$dupa = $setariBaza($ib);
$rand = $randuri($ib)['site/demo/setari-inghetate'] ?? [];
$asteptat = ['a_lipsa' => 'nouă', 'b_gol' => 'completată', 'c_exista' => 'a clientului', 'd_gol_lipsa' => '', 'e_spatii' => 'peste spații',
    'f_tab' => "\t", 'g_privat' => $adresaPrivata];
$sha = (string) ($ib->query("SELECT sha256 FROM _platforma_migrari WHERE id = 'site/demo/setari-inghetate'")->fetchColumn());
$verifica('aplicarea: lipsa scrie doar ce lipsește, lipsa_sau_gol și peste gol sau spații (nu peste un tab), o valoare prezentă rămâne a '
    . 'clientului; rândul pasului „aplicata”, 4 operații, sha256 al fișierului; nicio valoare privată în ieșire',
    $codAp === 0 && $dupa === $asteptat && ($rand['rezultat'] ?? '') === 'aplicata' && (int) ($rand['operatii'] ?? 0) === 4
    && (int) ($rand['ord'] ?? -1) === 0 && $sha === $shaPachet && !str_contains($outAp . $errAp, 'client-inventat')
    && !isset($stareBaza($ib)['pasi_site']),
    $outAp . json_encode([$dupa, $rand], JSON_UNESCAPED_UNICODE));

$sumaSetari = static fn (PDO $db): string => (string) ($db->query('CHECKSUM TABLE settings')->fetch(PDO::FETCH_NUM)[1] ?? '');
$sumaInainte = $sumaSetari($ib);
$ib->exec("UPDATE settings SET `value` = 'schimbată de client' WHERE `key` = 'a_lipsa'");
$sumaClient = $sumaSetari($ib);
[$cod2, $out2] = $ruleaza($sock, $b('ing'), $regIng, ['--aplica', '--site=demo'], $cuPachet);
[$codPlan2] = $ruleaza($sock, $b('ing'), $regIng, ['--plan', '--site=demo'], $cuPachet);
$verifica('a doua aplicare: nimic (o valoare schimbată de client după înghețare rămâne a lui), --plan 0',
    $cod2 === 0 && str_contains($out2, 'Nimic de făcut') && $sumaSetari($ib) === $sumaClient && $sumaClient !== $sumaInainte && $codPlan2 === 0,
    $out2);

// Fișierul aplicat nu se mai schimbă; o corectură e un fișier nou.
$textAplicat = (string) file_get_contents($pachet . '/setari-inghetate.json');
file_put_contents($pachet . '/setari-inghetate.json', str_replace('"nouă"', '"altă valoare"', $textAplicat));
[$c12p, $o12p] = $ruleaza($sock, $b('ing'), $regIng, ['--plan', '--site=demo'], $cuPachet);
[$c12a, $o12a] = $ruleaza($sock, $b('ing'), $regIng, ['--aplica', '--site=demo'], $cuPachet);
$verifica('fișierul de înghețare aplicat, schimbat: --plan și --aplica 12 (o corectură e setari-inghetate-2.json), baza neatinsă',
    $c12p === 12 && $c12a === 12 && str_contains($o12a, 'setari-inghetate-2.json') && $sumaSetari($ib) === $sumaClient, $o12p . $o12a);
file_put_contents($pachet . '/setari-inghetate.json', $textAplicat);
file_put_contents($pachet . '/setari-inghetate-2.json', json_encode(['format' => 2, 'site' => 'demo', 'sursa' => 'cod-vechi-demo@abc1234',
    'setari' => ['h_corectura' => 'din corectură', 'a_lipsa' => 'tot nu']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
@unlink($pachet . '/privat/setari-inghetate.json');
[$codCor, $outCor] = $ruleaza($sock, $b('ing'), $regIng, ['--aplica', '--site=demo'], $cuPachet);
$dupaCor = $setariBaza($ib);
$verifica('corectura setari-inghetate-2.json: aplicată după (site/demo/setari-inghetate-2), doar unde lipsește; fișierul privat al celui '
    . 'aplicat poate lipsi (nu intră în sha)',
    $codCor === 0 && ($randuri($ib)['site/demo/setari-inghetate-2']['rezultat'] ?? '') === 'aplicata'
    && ($dupaCor['h_corectura'] ?? null) === 'din corectură' && ($dupaCor['a_lipsa'] ?? null) === 'schimbată de client', $outCor);
rename($pachet . '/setari-inghetate-2.json', $lucru . '/inghetare-2-mutat-' . $cheiServer . '.json');
[$codLipsa, $outLipsa] = $ruleaza($sock, $b('ing'), $regIng, ['--plan', '--site=demo'], $cuPachet);
[$codFaraSite] = $ruleaza($sock, $b('ing'), $regIng, ['--plan']);
$verifica('o înghețare aplicată care lipsește din pachet: 12 cu --site=demo; fără site (și fără pachet) nu se poate verifica, 0',
    $codLipsa === 12 && str_contains($outLipsa, 'lipsește din pachetul site-ului') && $codFaraSite === 0, $outLipsa);
rename($lucru . '/inghetare-2-mutat-' . $cheiServer . '.json', $pachet . '/setari-inghetate-2.json');

// Provizoriu: fișierul și un parametru din migrari.json.
$pachetProv = $lucru . '/inghetare-provizoriu-' . $cheiServer;
$pachetIng($pachetProv, ['a_lipsa' => 'nouă'], [], ['provizoriu' => 'sursa neconfirmată (test)']);
$regParam = $lucru . '/inghetare-reg-param-' . $cheiServer;
$scrieMigrare($regParam, 'nucleu/0001_setari', "        \$s->tabel('settings', '`key` VARCHAR(120) NOT NULL, `value` LONGTEXT NOT NULL, PRIMARY KEY (`key`)');");
$scrieMigrare($regParam, 'nucleu/0002_param', "        \$s->tabel('param_' . \$s->parametruSite('test.proc'), 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');");
file_put_contents($pachetProv . '/migrari.json', json_encode(['test.proc' => 'stripe', '_provizoriu' => ['test.proc' => 'OD de test']]));
$genereazaAsteptat($sock, $regParam, ['--site=demo', '--permite-provizoriu'], ['SITE_DATE_DOSAR' => $pachetProv]);
$pb = $bazaNoua($sock, $b('ingprov'));
// 1. doar parametrul provizoriu; 2. doar înghețarea provizorie; 3. amândouă, cu --permite-provizoriu.
$pachetIng($pachetProv, ['a_lipsa' => 'nouă']);
[$cP1, $oP1] = $ruleaza($sock, $b('ingprov'), $regParam, ['--aplica', '--site=demo'], ['SITE_DATE_DOSAR' => $pachetProv]);
$tabeleP1 = $tabele($pb);
$pachetIng($pachetProv, ['a_lipsa' => 'nouă'], [], ['provizoriu' => 'sursa neconfirmată (test)']);
file_put_contents($pachetProv . '/migrari.json', json_encode(['test.proc' => 'stripe']));
[$cP2, $oP2] = $ruleaza($sock, $b('ingprov'), $regParam, ['--aplica', '--site=demo'], ['SITE_DATE_DOSAR' => $pachetProv]);
$tabeleP2 = $tabele($pb);
file_put_contents($pachetProv . '/migrari.json', json_encode(['test.proc' => 'stripe', '_provizoriu' => ['test.proc' => 'OD de test']]));
[$cP3, $oP3] = $ruleaza($sock, $b('ingprov'), $regParam, ['--aplica', '--site=demo', '--permite-provizoriu'], ['SITE_DATE_DOSAR' => $pachetProv]);
$verifica('provizoriu: un parametru din migrari.json „_provizoriu” și, separat, o înghețare „provizoriu” dau fiecare 15 fără '
    . '--permite-provizoriu, baza neatinsă (planul se oprește înaintea oricărei scrieri); cu el se aplică (doar repetiția pe verificare)',
    $cP1 === 15 && str_contains($oP1, 'test.proc') && str_contains($oP1, 'provizoriu') && $tabeleP1 === []
    && $cP2 === 15 && str_contains($oP2, 'înghețarea e provizorie') && $tabeleP2 === [] && $cP3 === 0
    && $pb->query("SELECT `value` FROM settings WHERE `key` = 'a_lipsa'")->fetchColumn() === 'nouă', $oP1 . $oP2 . $oP3);

// pasi_site: pașii site-ului au început și n-au terminat.
$mb = $bazaNoua($sock, $b('ingmarcaj'));
$pachetMarcaj = $lucru . '/inghetare-marcaj-' . $cheiServer;
$pachetIng($pachetMarcaj, ['a_lipsa' => 'nouă']);
[$cM1] = $ruleaza($sock, $b('ingmarcaj'), $regIng, ['--aplica', '--site=demo', '--pana-la=nucleu/0002_alt'], ['SITE_DATE_DOSAR' => $pachetMarcaj]);
$stareM1 = $stareBaza($mb);
[$cS, $oS] = $ruleaza($sock, $b('ingmarcaj'), $regIng, ['--stare', '--site=demo'], ['SITE_DATE_DOSAR' => $pachetMarcaj]);
[$cM2] = $ruleaza($sock, $b('ingmarcaj'), $regIng, ['--aplica', '--site=demo'], ['SITE_DATE_DOSAR' => $pachetMarcaj]);
$stareM2 = $stareBaza($mb);
$verifica('pasi_site: după --pana-la înaintea pașilor site-ului cheia stă în _platforma_stare (ultima_ord 2 = toate migrările; --stare o '
    . 'spune), iar după aplicarea lor e ștearsă',
    $cM1 === 0 && ($stareM1['pasi_site'] ?? '') === 'demo' && ($stareM1['ultima_ord'] ?? '') === '2' && $cS === 0
    && str_contains($oS, 'pașii site-ului „demo”') && $cM2 === 0 && !isset($stareM2['pasi_site']),
    json_encode([$stareM1, $stareM2]) . $oS);
$kb = $bazaNoua($sock, $b('ingomorat'));
[$cK1, , $eK1] = $ruleaza($sock, $b('ingomorat'), $regIng, ['--aplica', '--site=demo'], ['SITE_DATE_DOSAR' => $pachetMarcaj, 'MIGRARI_TEST_OPRESTE_DUPA' => '2']);
$stareK1 = $stareBaza($kb);
$randK1 = $randuri($kb);
[$cK2] = $ruleaza($sock, $b('ingomorat'), $regIng, ['--aplica', '--site=demo'], ['SITE_DATE_DOSAR' => $pachetMarcaj]);
$verifica('proces omorât după ultima migrare, înaintea înghețării: pasi_site rămâne (scrisă înaintea primei migrări, fără ultima_ord: '
    . 'garda spune in_urma); rulat din nou: înghețarea aplicată, cheia ștearsă',
    $cK1 === 1 && str_contains($eK1, 'MIGRARI_TEST_OPRESTE_DUPA') && ($stareK1['pasi_site'] ?? '') === 'demo' && !isset($stareK1['ultima_ord'])
    && !isset($randK1['site/demo/setari-inghetate']) && $cK2 === 0 && !isset($stareBaza($kb)['pasi_site'])
    && ($randuri($kb)['site/demo/setari-inghetate']['rezultat'] ?? '') === 'aplicata', json_encode([$stareK1, array_keys($randK1)]));

// setare() doar în pașii site-ului.
$regGresit = $lucru . '/inghetare-reg-gresit-' . $cheiServer;
$scrieMigrare($regGresit, 'nucleu/0001_setari', "        \$s->tabel('settings', '`key` VARCHAR(120) NOT NULL, `value` LONGTEXT NOT NULL, PRIMARY KEY (`key`)');\n"
    . "        \$s->setare('x', 'y', 'lipsa', 'y');");
$bazaNoua($sock, $b('inggresit'));
[$cG, $oG] = $ruleaza($sock, $b('inggresit'), $regGresit, ['--aplica']);
$verifica('setare() într-o migrare care nu e un pas al site-ului: 1', $cG === 1 && str_contains($oG, 'doar într-un pas al site-ului'), $oG);
