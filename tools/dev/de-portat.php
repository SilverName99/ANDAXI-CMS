<?php

declare(strict_types=1);

/**
 * Commit-urile noi din cele patru coduri vechi, de după „ultimul commit revizuit”
 * scris în docs/de-portat.md (verificarea săptămânală; fiecare commit nou primește
 * un rând acolo și se portează în aceeași săptămână).
 *
 *   tools/dev/php.sh tools/dev/de-portat.php                 # toate cele patru site-uri
 *   tools/dev/php.sh tools/dev/de-portat.php bioscem vertij  # doar acestea
 *   php tools/dev/de-portat.php --revizuit=bioscem           # doar commit-ul revizuit (îl citește compara-cu-baza.sh)
 *
 * Clonele: CMS_DEV_REPO_BS, CMS_DEV_REPO_BV, CMS_DEV_REPO_VT, CMS_DEV_REPO_GR
 * (implicit alături de acest repo, ca în tools/dev/comun.sh). Scriptul doar citește:
 * nu face `git fetch` și nu schimbă nimic în clone. Ce e doar pe GitHub apare după
 * un `git -C <clonă> fetch` făcut de tine. Se uită la ramura din tabel, locală și
 * `origin/<ramura>`.
 *
 * Cod de ieșire: 0 = nimic nou; 2 = sunt commit-uri noi (de trecut în docs/de-portat.md);
 * 1 = eroare (clonă lipsă, commit revizuit necunoscut, tabel stricat).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$fisierDoc = $radacina . '/docs/de-portat.md';

/** Site-ul din tabel → variabila de mediu a clonei. */
$variabile = [
    'bioscem' => 'CMS_DEV_REPO_BS',
    'biovitality' => 'CMS_DEV_REPO_BV',
    'vertij' => 'CMS_DEV_REPO_VT',
    'grafoanaytis' => 'CMS_DEV_REPO_GR',
];

$eroare = static function (string $mesaj): void {
    fwrite(STDERR, 'EROARE: ' . $mesaj . "\n");
    exit(1);
};

/**
 * Tabelul de sus din docs/de-portat.md: site => [repo, ramura, revizuit].
 *
 * @return array<string, array{repo: string, ramura: string, revizuit: string}>
 */
$citesteTabelul = static function (string $fisier) use ($eroare): array {
    $text = @file_get_contents($fisier);
    if (!is_string($text)) {
        $eroare('nu pot citi ' . $fisier);
    }
    $site = [];
    foreach (explode("\n", $text) as $rand) {
        // | Bioscem | `Nou-site-bioscem`, `claude/…` | `f5eafa3` (19.10.2026) | … |
        if (preg_match('/^\|\s*([^|`]+?)\s*\|\s*`([^`]+)`,\s*`([^`]+)`\s*\|\s*`([0-9a-f]{7,40})`/u', $rand, $m) === 1) {
            $site[mb_strtolower($m[1])] = ['repo' => $m[2], 'ramura' => $m[3], 'revizuit' => $m[4]];
        }
    }
    return $site;
};

/** @return array{0: int, 1: list<string>} */
$git = static function (string $clona, array $argumente): array {
    $comanda = 'git -C ' . escapeshellarg($clona);
    foreach ($argumente as $argument) {
        $comanda .= ' ' . escapeshellarg((string) $argument);
    }
    exec($comanda . ' 2>/dev/null', $iesire, $cod);
    return [$cod, $iesire];
};

$tabel = $citesteTabelul($fisierDoc);
foreach (array_keys($variabile) as $cheie) {
    if (!isset($tabel[$cheie])) {
        $eroare('docs/de-portat.md nu are rândul „' . $cheie . '” în tabelul „Ultimul commit revizuit”.');
    }
}
$clona = static function (string $cheie) use ($variabile, $tabel, $radacina): string {
    $cale = (string) getenv($variabile[$cheie]);
    return $cale !== '' ? rtrim($cale, '/') : dirname($radacina) . '/' . $tabel[$cheie]['repo'];
};

$argumente = array_slice($argv, 1);
foreach ($argumente as $argument) {
    if (str_starts_with($argument, '--revizuit=')) {
        $cheie = mb_strtolower(substr($argument, strlen('--revizuit=')));
        if (!isset($tabel[$cheie])) {
            $eroare('site necunoscut „' . $cheie . '” (bioscem, biovitality, vertij, grafoanaytis).');
        }
        echo $tabel[$cheie]['revizuit'], "\n";
        exit(0);
    }
}
$alese = $argumente === [] ? array_keys($variabile) : array_map('mb_strtolower', $argumente);

$noi = 0;
foreach ($alese as $cheie) {
    if (!isset($variabile[$cheie])) {
        $eroare('site necunoscut „' . $cheie . '” (bioscem, biovitality, vertij, grafoanaytis).');
    }
    $rand = $tabel[$cheie];
    $dosar = $clona($cheie);
    echo ucfirst($cheie), ' (', $rand['repo'], ', ', $rand['ramura'], '), ultimul commit revizuit ', $rand['revizuit'], ":\n";
    if (!is_dir($dosar)) {
        $eroare('clona ' . $dosar . ' lipsește (' . $variabile[$cheie] . ').');
    }
    [$cod] = $git($dosar, ['rev-parse', '--verify', '--quiet', $rand['revizuit'] . '^{commit}']);
    if ($cod !== 0) {
        $eroare('commit-ul revizuit ' . $rand['revizuit'] . ' nu e în ' . $dosar . ' (clonă prea scurtă sau alt repo?).');
    }
    $referinte = [];
    foreach (['refs/heads/' . $rand['ramura'] => 'local', 'refs/remotes/origin/' . $rand['ramura'] => 'origin'] as $ref => $unde) {
        if ($git($dosar, ['rev-parse', '--verify', '--quiet', $ref])[0] === 0) {
            $referinte[$ref] = $unde;
        }
    }
    if ($referinte === []) {
        $eroare('ramura ' . $rand['ramura'] . ' nu există în ' . $dosar . '.');
    }
    $commituri = [];
    foreach ($referinte as $ref => $unde) {
        // Commit-ul revizuit trebuie să fie în istoria ramurii (altfel ramura a fost rescrisă).
        if ($git($dosar, ['merge-base', '--is-ancestor', $rand['revizuit'], $ref])[0] !== 0) {
            echo '  ATENȚIE: ', $rand['revizuit'], ' nu e în istoria lui ', $ref, " (ramura a fost rescrisă?)\n";
            $noi++;
            continue;
        }
        [, $linii] = $git($dosar, ['log', '--reverse', '--format=%h%x09%cs%x09%s', $rand['revizuit'] . '..' . $ref]);
        foreach ($linii as $linie) {
            [$hash, $data, $subiect] = array_pad(explode("\t", $linie, 3), 3, '');
            $commituri[$hash] ??= ['data' => $data, 'subiect' => $subiect, 'unde' => []];
            $commituri[$hash]['unde'][] = $unde;
        }
    }
    if ($commituri === []) {
        echo "  nimic nou\n";
        continue;
    }
    foreach ($commituri as $hash => $c) {
        $noi++;
        $doarUnul = count($c['unde']) < count($referinte) ? '  [doar ' . implode(', ', $c['unde']) . ']' : '';
        echo '  ', $hash, '  ', $c['data'], '  ', $c['subiect'], $doarUnul, "\n";
    }
}
echo "\n", $noi === 0
    ? "Nimic nou de portat (fără git fetch: ce e doar pe GitHub nu se vede aici).\n"
    : $noi . " commit-uri noi: fiecare primește un rând în docs/de-portat.md și se portează în aceeași săptămână.\n";
exit($noi === 0 ? 0 : 2);
