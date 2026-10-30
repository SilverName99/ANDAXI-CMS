<?php

declare(strict_types=1);

/**
 * Testele înghețării setărilor fără bază (WP-06; docs/MIGRARI.md, „Înghețarea setărilor”). Pasul runner-ului,
 * pe ambele servere, e în tools/dev/test-migrari.php (secțiunea I); aici restul:
 *
 *  F. Formatul (App\Support\Migrari\Inghetare): forma scurtă = lipsa, fiecare greșeală de formă = codul 2,
 *     sha256 pe fișierul cu LF, fișierul privat (lipsă, alt site, altă sursă, făcut pentru altă versiune, alte
 *     chei, o valoare care nu e text: codul 15, fără nicio valoare în mesaj); datele private (emailuri în afara
 *     @exemplu.ro, telefoane și cu paranteze, bare sau mai mulți separatori la rând, nu și bucăți dintr-un sha256 sau
 *     dintr-un număr lung) și înlocuitorii lor.
 *  R. Registrul: fișierele de înghețare ale pachetului în ordinea lor (-2 înainte de -10), un nume greșit, un pas
 *     PHP cu numele înghețării, pachetul altui site, două site-uri într-un pachet: codul 2; id-urile pașilor.
 *  C. Citirea unui cod (tools/inghetare/lib/citeste-cod.php): DEFAULTS prin Reflection, rezervele `?? <literal>`
 *     (ghilimele simple și duble, numere, null, $this->settings, $designSettings; o expresie; mai multe valori;
 *     nimic din comentarii), valorile extrase (tipar, apel; erorile lor).
 *  G. Generatorul (tools/inghetare/genereaza-setari.php) pe un cod vechi inventat (o clonă git făcută aici) și un
 *     cod comun inventat: intrările automate (DEFAULTS, rezerve, private cu înlocuitor și fișierul privat), lipsa =
 *     gol, deciziile lipsă (nimic scris), deciziile scrise (legate de valorile de atunci), determinismul, --verifica,
 *     o decizie veche după o schimbare a codului comun (și confirmarea ei din nou), o valoare privată scrisă de mână,
 *     un „extrage” greșit, un fara_inghetare fără obiect scos, provizoriu și --confirmat, un fișier închis; pe server
 *     (--cod-vechi=<dosar>): doar privat/, din dosarul codului, dacă dă exact înghețarea din pachet (altfel nimic scris).
 *  V. Verificarea (tools/inghetare/verifica-inghetare.php) pe pachetele din repo (0) și pe o copie a lor cu câte o
 *     greșeală: o adresă reală într-o notă, o valoare privată care nu e înlocuitor, o setare nouă în codul comun
 *     (amprenta și reproducerea), un parametru necunoscut sau o valoare nepermisă în migrari.json, sursa altă decât
 *     commit-ul revizuit, clona lipsă (și --fara-clone, spus explicit); un fișier închis (verificarea nu-l mai
 *     cere reprodus, genereaza-setari --toate îl sare).
 *
 *   tools/dev/php.sh tools/dev/test-inghetare.php
 *
 * Doar date inventate (domeniul rezervat .test pentru adresele „private”). Dosarul de lucru
 * $CMS_DEV_DIR/test-inghetare se face și se șterge aici. Raport: $CMS_DEV_DIR/test-inghetare-raport.txt.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = (string) realpath(dirname(__DIR__, 2));
foreach (['Eroare', 'ParametruLipsa', 'Inghetare', 'Registru'] as $clasa) {
    require_once $radacina . '/src/Support/Migrari/' . $clasa . '.php';
}
require_once $radacina . '/tools/inghetare/lib/Generator.php';

use App\Support\Migrari\Eroare;
use App\Support\Migrari\Inghetare;
use App\Support\Migrari\Registru;
use Unelte\Inghetare\Generator;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$lucru = $dosarDev . '/test-inghetare';
$fisierRaport = $dosarDev . '/test-inghetare-raport.txt';
@mkdir($dosarDev, 0777, true);
@file_put_contents($fisierRaport, '');
$trecute = 0;
$esuate = 0;
$scrie = static function (string $linie) use ($fisierRaport): void {
    echo $linie, "\n";
    file_put_contents($fisierRaport, $linie . "\n", FILE_APPEND);
};
$verifica = static function (string $eticheta, bool $ok, string $detalii = '') use (&$trecute, &$esuate, $scrie): void {
    if ($ok) {
        $trecute++;
        $scrie('  OK    ' . $eticheta);
    } else {
        $esuate++;
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr(trim($detalii), 0, 1500) : ''));
    }
};
$sterge = static function (string $cale) use (&$sterge): void {
    if (is_link($cale) || is_file($cale)) {
        @unlink($cale);
    } elseif (is_dir($cale)) {
        foreach (array_diff(scandir($cale) ?: [], ['.', '..']) as $x) {
            $sterge($cale . '/' . $x);
        }
        @rmdir($cale);
    }
};
$sterge($lucru);
mkdir($lucru, 0777, true);
register_shutdown_function(static fn () => $sterge($lucru));
/** Codul de eroare al unei funcții (0 = n-a aruncat) și mesajul. @return array{0: int, 1: string} */
$aruncat = static function (callable $f): array {
    try {
        $f();
        return [0, ''];
    } catch (Eroare $e) {
        return [$e->getCode(), $e->getMessage()];
    } catch (Throwable $e) {
        return [-1, get_class($e) . ': ' . $e->getMessage()];
    }
};
/** Un script, ca proces separat: [cod, ieșirea]. */
$ruleaza = static function (array $argumente, array $mediu = []) use ($radacina): array {
    $env = array_merge(getenv(), $mediu);
    $p = proc_open(array_merge([PHP_BINARY], $argumente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $radacina, $env);
    $out = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
    fclose($pipe[1]);
    fclose($pipe[2]);
    return [proc_close($p), $out];
};
$json = static fn (array $date): string => json_encode($date, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

$scrie('Testele înghețării setărilor (fără bază) — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// =====================================================================
// F. Formatul
// =====================================================================
$scrie('');
$scrie('F. Formatul fișierului (Inghetare)');
$bun = ['format' => 2, 'site' => 'demo', 'sursa' => 'cod-vechi@abc1234', 'setari' => ['a' => 'scurt', 'b' => ['valoare' => 'x', 'mod' => 'lipsa_sau_gol']],
    'fara_inghetare' => ['c' => 'motivul']];
$ing = Inghetare::dinText($json($bun), 'setari-inghetate.json');
$s = $ing->setari();
$verifica('forma scurtă (doar textul) = mod „lipsa”, „din” manual; câmpurile lipsă cu valorile implicite; fara_inghetare cu motivul',
    $s['a'] === ['valoare' => 'scurt', 'mod' => 'lipsa', 'din' => 'manual', 'privat' => false] && $s['b']['mod'] === 'lipsa_sau_gol'
    && $ing->faraInghetare() === ['c' => ['motiv' => 'motivul']] && $ing->site() === 'demo' && $ing->sursa() === 'cod-vechi@abc1234'
    && $ing->provizoriu() === null && $ing->cheiPrivate() === []);
$verifica('sha256 pe fișierul cu LF: același cu CRLF', Inghetare::dinText(str_replace("\n", "\r\n", $json($bun)), 'x')->sha256() === $ing->sha256()
    && $ing->sha256() === hash('sha256', $json($bun)));
$gresite = [
    'nu e JSON' => '{',
    'listă în loc de obiect' => '[1, 2]',
    'cheie necunoscută sus' => $json($bun + ['altceva' => 1]),
    'format 1' => $json(['format' => 1] + $bun),
    'site cu majuscule' => $json(['site' => 'Demo'] + $bun),
    'sursa fără commit' => $json(['sursa' => 'cod-vechi'] + $bun),
    'provizoriu gol' => $json($bun + ['provizoriu' => ' ']),
    'amprenta_nucleu greșită' => $json($bun + ['amprenta_nucleu' => 'abc']),
    'setari listă' => $json(['setari' => ['a', 'b']] + $bun),
    'cheie cu majuscule' => $json(['setari' => ['Cheie' => 'x']] + $bun),
    'cheie de 121 de caractere' => $json(['setari' => [str_repeat('a', 121) => 'x']] + $bun),
    'mod necunoscut' => $json(['setari' => ['a' => ['valoare' => 'x', 'mod' => 'mereu']]] + $bun),
    'valoare număr' => $json(['setari' => ['a' => ['valoare' => 5]]] + $bun),
    'din necunoscut' => $json(['setari' => ['a' => ['valoare' => 'x', 'din' => 'ghicit']]] + $bun),
    'privat text' => $json(['setari' => ['a' => ['valoare' => 'x', 'privat' => 'da']]] + $bun),
    'câmp necunoscut' => $json(['setari' => ['a' => ['valoare' => 'x', 'culoare' => 'rosu']]] + $bun),
    'extrage fără extras' => $json(['setari' => ['a' => ['valoare' => 'x', 'extrage' => ['fisier' => 'a', 'tipar' => '/x/']]]] + $bun),
    'extras fără extrage' => $json(['setari' => ['a' => ['valoare' => 'x', 'din' => 'extras']]] + $bun),
    'și setare și fără înghețare' => $json(['fara_inghetare' => ['a' => 'motiv']] + $bun),
    'fără înghețare fără motiv' => $json(['fara_inghetare' => ['c' => ' ']] + $bun),
    'fără înghețare cu câmp necunoscut' => $json(['fara_inghetare' => ['c' => ['motiv' => 'm', 'x' => 'y']]] + $bun),
];
$coduri = [];
foreach ($gresite as $nume => $text) {
    $coduri[$nume] = $aruncat(static fn () => Inghetare::dinText($text, 'setari-inghetate.json'))[0];
}
$verifica('fiecare greșeală de formă: codul 2 (' . count($gresite) . ' cazuri)', array_unique(array_values($coduri)) === [2],
    json_encode($coduri, JSON_UNESCAPED_UNICODE));

// Fișierul privat.
$dosarP = $lucru . '/privat-test';
@mkdir($dosarP . '/privat', 0777, true);
$cuPrivat = ['setari' => ['a' => ['valoare' => 'adresa1@exemplu.ro', 'privat' => true], 'b' => 'x']] + $bun;
file_put_contents($dosarP . '/setari-inghetate.json', $json($cuPrivat));
$ip = Inghetare::dinFisier($dosarP . '/setari-inghetate.json');
$real = 'birou.client@client-inventat.test';
$scriePrivat = static fn (array $date) => file_put_contents($dosarP . '/privat/setari-inghetate.json', $json($date));
$privatBun = ['site' => 'demo', 'sursa' => 'cod-vechi@abc1234', 'pentru' => $ip->sha256(), 'setari' => ['a' => $real]];
$cazuriPrivat = [
    'lipsește' => null,
    'alt site' => ['site' => 'alt'] + $privatBun,
    'altă sursă' => ['sursa' => 'cod-vechi@fff9999'] + $privatBun,
    'pentru altă versiune' => ['pentru' => str_repeat('0', 64)] + $privatBun,
    'o cheie în plus' => ['setari' => ['a' => $real, 'b' => $real]] + $privatBun,
    'fără cheia privată' => ['setari' => []] + $privatBun,
    'valoare care nu e text' => ['setari' => ['a' => 5]] + $privatBun,
    'cheie necunoscută' => $privatBun + ['parola' => 'x'],
];
$rezultatePrivat = [];
foreach ($cazuriPrivat as $nume => $date) {
    if ($date === null) {
        @unlink($dosarP . '/privat/setari-inghetate.json');
    } else {
        $scriePrivat($date);
    }
    $rezultatePrivat[$nume] = $aruncat(static fn () => $ip->valoriPrivate('site/demo/setari-inghetate'));
}
$scriePrivat($privatBun);
$valori = $ip->valoriPrivate('site/demo/setari-inghetate');
$verifica('fișierul privat: lipsă, alt site, altă sursă, făcut pentru altă versiune, alte chei, o valoare care nu e text, o cheie '
    . 'necunoscută: codul 15, fără nicio valoare în mesaj; cel bun dă valorile',
    array_values(array_unique(array_map(static fn (array $r): int => $r[0], $rezultatePrivat))) === [15]
    && array_filter($rezultatePrivat, static fn (array $r): bool => str_contains($r[1], 'client-inventat')) === []
    && $valori === ['a' => $real] && $ip->cheiPrivate() === ['a'],
    json_encode($rezultatePrivat, JSON_UNESCAPED_UNICODE));
$verifica('calea fișierului privat: privat/<nume> lângă fișier', Inghetare::caleaPrivata('/x/y/setari-inghetate-2.json') === '/x/y/privat/setari-inghetate-2.json');

// Datele private și înlocuitorii.
$verifica('emailurile reale: orice adresă în afara @exemplu.ro (și cu majuscule la domeniu: tot demo)',
    Inghetare::emailuriReale('a@exemplu.ro, B@EXEMPLU.RO; contact@client-inventat.test') === ['contact@client-inventat.test']
    && Inghetare::emailuriReale('no-reply@localhost (fără punct în domeniu)') === []);
$telefoane = ['0244 000 111', '0712.345.678', '+40 744 123 456', '0040-721-000-111', '0212345678', '(0244) 000 111',
    '+40 (244) 000 111', '0244 / 000 111', '0721 234 5678', '+40722123456', '(0244)-000-111'];
$shaHex = [];
for ($i = 0; $i < 2000; $i++) {
    $shaHex[] = hash('sha256', 'proba-' . $i);
}
$nuTelefoane = array_merge(['203800', 'J2006017724407', '2026-10-20 10:00:00', 'RO49AAAA1B31007593840000',
    'amprenta ' . hash('sha256', 'x') . ' ' . str_repeat('ab0712345678cd', 3), '0700 000 001', '19166493', '07212345678901',
    '0244 000 11112', 'x0244000111'], $shaHex, array_map('strtoupper', array_slice($shaHex, 0, 200)));
$verifica('telefoanele: fix și mobil, cu spații, puncte, liniuțe, bare, paranteze (și câte trei la rând), +40/0040, cu o cifră în plus '
    . '(nu un număr mai lung, o dată, un cont, 2200 de sha256 în hex sau înlocuitorul)',
    array_filter($telefoane, static fn (string $t): bool => Inghetare::telefoane('tel. ' . $t . '.') === []) === []
    && array_filter($nuTelefoane, static fn (string $t): bool => Inghetare::telefoane($t) !== []) === [],
    json_encode([array_map([Inghetare::class, 'telefoane'], $telefoane), array_map([Inghetare::class, 'telefoane'], $nuTelefoane)]));
$verifica('înlocuitorii: același număr de adrese, un telefon inventat, „Valoare demo”; deterministi; recunoscuți ca înlocuitori',
    Inghetare::inlocuitor('a@client-inventat.test, b@client-inventat.test') === 'adresa1@exemplu.ro, adresa2@exemplu.ro'
    && Inghetare::inlocuitor('0244 000 111') === '0700 000 001' && Inghetare::inlocuitor('Ion Inventat') === 'Valoare demo'
    && Inghetare::eInlocuitor('adresa1@exemplu.ro, adresa2@exemplu.ro') && Inghetare::eInlocuitor('Valoare demo')
    && !Inghetare::eInlocuitor('contact@client-inventat.test') && !Inghetare::arePrivat(Inghetare::inlocuitor('a@client-inventat.test')));
$verifica('valoarea scurtă din plan: cea întreagă între ghilimele, una lungă tăiată, cu lungimea',
    Inghetare::scurt('abc') === '„abc”' && str_ends_with(Inghetare::scurt(str_repeat('ș', 80)), '…”, 80 caractere')
    && Inghetare::scurt("a\nb") === '„a⏎b”');

// =====================================================================
// R. Registrul și fișierele de înghețare
// =====================================================================
$scrie('');
$scrie('R. Registrul: fișierele de înghețare ale pachetului');
$reg = new Registru($lucru . '/migrari-goale', ['nucleu']);
$pachet = $lucru . '/pachet-ordine';
@mkdir($pachet, 0777, true);
foreach (['setari-inghetate.json', 'setari-inghetate-10.json', 'setari-inghetate-2.json'] as $nume) {
    file_put_contents($pachet . '/' . $nume, $json(['setari' => ['k' => 'v']] + $bun));
}
file_put_contents($pachet . '/setari-inghetate.md', 'nu e JSON');
$pasi = $reg->pasiSite('demo', $pachet);
$verifica('pașii de înghețare: setari-inghetate, -2, -10 (după număr), id site/demo/…, ord 0, tip site, sha256 al fișierului; fisiere() îi '
    . 'are doar pentru site-ul dat; pasInghetare() le recunoaște id-ul',
    array_column($pasi, 'id') === ['site/demo/setari-inghetate', 'site/demo/setari-inghetate-2', 'site/demo/setari-inghetate-10']
    && $pasi[0]['ord'] === 0 && $pasi[0]['tip'] === 'site' && $pasi[0]['sha256'] === Registru::sha($pachet . '/setari-inghetate.json')
    && isset($reg->fisiere('demo', $pachet)['site/demo/setari-inghetate-2']) && !isset($reg->fisiere(null, $pachet)['site/demo/setari-inghetate'])
    && $reg->sitePachet($pachet) === 'demo' && $reg->sitePachet($lucru . '/nu-exista') === null
    && Registru::pasInghetare('site/demo/setari-inghetate-2') === 'demo' && Registru::pasInghetare('site/demo/0001_ordine') === null
    && Registru::pasInghetare('nucleu/0001_baza') === null);
$cazuriReg = [
    'nume greșit (setari-inghetate-x.json)' => static function (string $d) use ($json, $bun): void {
        file_put_contents($d . '/setari-inghetate-x.json', $json($bun));
    },
    'pachetul altui site' => static function (string $d) use ($json, $bun): void {
        file_put_contents($d . '/setari-inghetate.json', $json(['site' => 'alt'] + $bun));
    },
    'fișier stricat' => static function (string $d): void {
        file_put_contents($d . '/setari-inghetate.json', '{');
    },
];
$coduriReg = [];
foreach ($cazuriReg as $nume => $pregatire) {
    $d = $lucru . '/pachet-' . substr(md5($nume), 0, 8);
    @mkdir($d, 0777, true);
    $pregatire($d);
    $coduriReg[$nume] = $aruncat(static fn () => (new Registru($lucru . '/migrari-goale', ['nucleu']))->pasiSite('demo', $d))[0];
}
$dDoua = $lucru . '/pachet-doua';
@mkdir($dDoua, 0777, true);
file_put_contents($dDoua . '/setari-inghetate.json', $json($bun));
file_put_contents($dDoua . '/setari-inghetate-2.json', $json(['site' => 'alt'] + $bun));
$coduriReg['două site-uri într-un pachet'] = $aruncat(static fn () => (new Registru($lucru . '/migrari-goale', ['nucleu']))->sitePachet($dDoua))[0];
$migPas = $lucru . '/migrari-pas-gresit/site/demo';
@mkdir($migPas, 0777, true);
file_put_contents($migPas . '/setari-inghetate.php', "<?php return ['descriere' => 'x', 'tip' => 'site', 'blocanta' => false, 'sus' => static function (): void {}];");
$coduriReg['un pas PHP cu numele înghețării'] = $aruncat(static fn () => (new Registru($lucru . '/migrari-pas-gresit', ['nucleu']))->pasiSite('demo'))[0];
$verifica('registrul: fiecare greșeală a pachetului dă 2 (' . implode(', ', array_keys($coduriReg)) . ')',
    array_unique(array_values($coduriReg)) === [2], json_encode($coduriReg, JSON_UNESCAPED_UNICODE));

// =====================================================================
// C. Citirea unui cod
// =====================================================================
$scrie('');
$scrie('C. Citirea unui cod (tools/inghetare/lib/citeste-cod.php)');
$scrieFisier = static function (string $cale, string $text): void {
    @mkdir(dirname($cale), 0777, true);
    file_put_contents($cale, $text);
};
$settingsPhp = static function (array $implicite): string {
    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Support;\n\nfinal class Settings\n{\n    private const DEFAULTS = "
        . var_export($implicite, true) . ";\n\n    public static function all(): array\n    {\n        return self::DEFAULTS;\n    }\n}\n";
};
$vechiImplicite = ['la_fel' => 'x', 'diferit' => 'vechi', 'doar_vechi' => 'v', 'doar_vechi_gol' => '',
    'email_vechi' => 'Birou <birou.client@client-inventat.test>', 'telefon_vechi' => '0800 000 111'];
$layoutVechi = <<<'PHP'
<?php
$a = $settings['rezerva_veche'] ?? 'din cod';
$b = $designSettings["ghilimele"] ?? "a\nb\x41\u{0103}";
$c = $this->settings['obiect'] ?? 7;
$d = $settings['expresie'] ?? 'a' . $x;
$e = ($settings['multiplu'] ?? 'unu');
$f = [$settings['multiplu'] ?? 'doi'];
$g = $settings['nul'] ?? null;
$h = $settings['negativ'] ?? -2.5;
$i = $alteDate['nu_e_setare'] ?? 'nu';
// $settings['comentariu'] ?? 'nu';
/* $settings['comentariu2'] ?? 'nu'; */
$j = $settings['cu_escape'] ?? 'it\'s';
PHP;
$vechiRepo = $lucru . '/repo-vechi';
$scrieFisier($vechiRepo . '/src/Support/Settings.php', $settingsPhp($vechiImplicite));
$scrieFisier($vechiRepo . '/src/Support/Ajutor.php', "<?php\n\nnamespace App\\Support;\n\nfinal class Ajutor\n{\n"
    . "    public static function nume(array \$s): string\n    {\n        return \$s['nume'] ?? 'Numele vechi';\n    }\n\n"
    . "    public static function lista(): array\n    {\n        return ['a' => ['b' => ['x' => 1]]];\n    }\n}\n");
$scrieFisier($vechiRepo . '/views/layout.php', $layoutVechi);
$scrieFisier($vechiRepo . '/tools/nu-se-citeste.php', "<?php \$settings['din_unelte'] ?? 'nu';");
exec('cd ' . escapeshellarg($vechiRepo) . ' && git init -q && git add -A && git -c user.email=test@exemplu.ro -c user.name=Test commit -q -m inceput 2>&1', $_, $codGit);
$commitVechi = trim((string) shell_exec('git -C ' . escapeshellarg($vechiRepo) . ' rev-parse --short=7 HEAD'));
$generator = new Generator($radacina, $lucru . '/lucru-gen');
$citire = $generator->citesteCod($vechiRepo, [
    'tipar' => ['fisier' => 'views/layout.php', 'tipar' => "/'rezerva_veche'\\] \\?\\? '([^']+)'/"],
    'apel' => ['apel' => 'App\\Support\\Ajutor::nume', 'argumente' => [[]]],
    'apel_json' => ['apel' => 'App\\Support\\Ajutor::lista', 'cale' => ['a'], 'json' => true],
    'fara_fisier' => ['fisier' => 'nu/exista.php', 'tipar' => '/(x)/'],
    'doua_valori' => ['fisier' => 'views/layout.php', 'tipar' => "/'multiplu'\\] \\?\\? '([a-z]+)'/"],
    'cale_urcata' => ['fisier' => '../repo-vechi/views/layout.php', 'tipar' => '/(x)/'],
]);
$r = $citire['rezerve'];
$verifica('DEFAULTS prin Reflection (constanta privată), aceleași valori',
    $codGit === 0 && $citire['implicite'] === (static function () use ($vechiImplicite): array {
        ksort($vechiImplicite);
        return $vechiImplicite;
    })());
$verifica('rezervele: ghilimele simple și duble (cu \\n, \\x41, \\u{0103}, \\\'), numere (7, -2.5), null, $this->settings, $designSettings, '
    . 'o expresie („<expresie>”), mai multe valori; nu alte array-uri, comentarii sau tools/',
    ($r['rezerva_veche'] ?? null) === ['din cod'] && ($r['ghilimele'] ?? null) === ["a\nbAă"] && ($r['obiect'] ?? null) === ['7']
    && ($r['expresie'] ?? null) === ['<expresie>'] && ($r['multiplu'] ?? null) === ['doi', 'unu'] && ($r['nul'] ?? null) === ['']
    && ($r['negativ'] ?? null) === ['-2.5'] && ($r['cu_escape'] ?? null) === ["it's"] && !isset($r['nu_e_setare'])
    && !isset($r['comentariu']) && !isset($r['comentariu2']) && !isset($r['din_unelte']), json_encode($r, JSON_UNESCAPED_UNICODE));
$e = $citire['extrase'];
$verifica('valorile extrase: tipar (o singură valoare), apel (static, cu argumente), apel cu cale și JSON; un fișier lipsă, două valori '
    . 'diferite, o cale care urcă: eroare',
    ($e['tipar']['valoare'] ?? null) === 'din cod' && ($e['apel']['valoare'] ?? null) === 'Numele vechi'
    && ($e['apel_json']['valoare'] ?? null) === '{"b":{"x":1}}' && isset($e['fara_fisier']['eroare'], $e['doua_valori']['eroare'], $e['cale_urcata']['eroare']),
    json_encode($e, JSON_UNESCAPED_UNICODE));

// =====================================================================
// G. Generatorul
// =====================================================================
$scrie('');
$scrie('G. Generatorul (tools/inghetare/genereaza-setari.php)');
$nucleu = $lucru . '/nucleu';
$implicitNucleu = ['la_fel' => 'x', 'diferit' => 'nou', 'doar_nucleu' => 'n', 'doar_nucleu_gol' => '', 'rezerva_veche' => 'altceva'];
$scrieFisier($nucleu . '/src/Support/Settings.php', $settingsPhp($implicitNucleu));
$scrieFisier($nucleu . '/views/layout.php', "<?php\n\$z = \$settings['doar_cod_nucleu'] ?? 'z';\n");
$pachete = $lucru . '/pachete';
$fisierProba = $pachete . '/proba/setari-inghetate.json';
$privatProba = $pachete . '/proba/privat/setari-inghetate.json';
$gen = static function (array $extra = []) use ($ruleaza, $radacina, $vechiRepo, $commitVechi, $pachete, $nucleu, $lucru): array {
    return $ruleaza(array_merge([$radacina . '/tools/inghetare/genereaza-setari.php', '--site=proba', '--repo=' . $vechiRepo . '@' . $commitVechi,
        '--pachete=' . $pachete, '--nucleu=' . $nucleu, '--lucru=' . $lucru . '/lucru-gen'], $extra));
};
[$c1, $o1] = $gen();
$verifica('fără decizii: 1, fiecare decizie lipsă numită (doar_nucleu, expresie, multiplu, doar_cod_nucleu), nimic scris',
    $c1 === 1 && substr_count($o1, 'decizie lipsă') === 4 && str_contains($o1, '„doar_nucleu”') && str_contains($o1, '„expresie”')
    && str_contains($o1, '„multiplu”') && str_contains($o1, '„doar_cod_nucleu”') && !is_file($fisierProba), $o1);

$decizii = ['format' => 2, 'site' => 'proba', 'sursa' => 'x@0000000', 'setari' => [
    'doar_nucleu' => ['valoare' => 'v0', 'din' => 'manual', 'sursa' => 'decizia testului'],
    'multiplu' => ['valoare' => '', 'din' => 'extras', 'sursa' => 'layout-ul vechi',
        'extrage' => ['fisier' => 'views/layout.php', 'tipar' => "/'multiplu'\\] \\?\\? '(unu)'/"]],
    'doar_cod_nucleu' => ['valoare' => '', 'din' => 'extras', 'sursa' => 'Ajutor::nume', 'extrage' => ['apel' => 'App\\Support\\Ajutor::nume', 'argumente' => [[]]],
        'nota' => 'nota rămâne'],
    'diferit' => ['valoare' => 'ignorat', 'mod' => 'lipsa_sau_gol', 'din' => 'implicit', 'nota' => 'modul și nota automatelor rămân'],
], 'fara_inghetare' => ['expresie' => 'motivul testului', 'la_fel' => 'fără obiect']];
$scrieFisier($fisierProba, $json($decizii));
[$c2, $o2] = $gen();
$doc = json_decode((string) @file_get_contents($fisierProba), true) ?: [];
$st = $doc['setari'] ?? [];
$privat = json_decode((string) @file_get_contents($privatProba), true) ?: [];
$verifica('cu decizii: 0; automate: diferit (DEFAULTS, modul și nota păstrate), doar_vechi, rezerva_veche și ghilimele, obiect, nul, negativ, '
    . 'cu_escape (din cod); lipsa = gol (doar_vechi_gol, doar_nucleu_gol, nul nu); egale nimic (la_fel); deciziile legate de valorile de acum; '
    . 'fara_inghetare.la_fel scos (fără obiect)',
    $c2 === 0 && ($st['diferit'] ?? null) === ['valoare' => 'vechi', 'mod' => 'lipsa_sau_gol', 'din' => 'implicit', 'sursa' => 'Settings::DEFAULTS',
        'nota' => 'modul și nota automatelor rămân']
    && ($st['doar_vechi']['valoare'] ?? null) === 'v' && ($st['rezerva_veche']['din'] ?? '') === 'cod' && ($st['rezerva_veche']['valoare'] ?? '') === 'din cod'
    && ($st['ghilimele']['valoare'] ?? null) === "a\nbAă" && ($st['obiect']['valoare'] ?? null) === '7' && ($st['negativ']['valoare'] ?? null) === '-2.5'
    && !isset($st['nul'], $st['doar_vechi_gol'], $st['doar_nucleu_gol'], $st['la_fel'])
    && ($st['doar_nucleu'] ?? null) === ['valoare' => 'v0', 'mod' => 'lipsa', 'din' => 'manual', 'sursa' => 'decizia testului',
        'vechi' => 'lipsește', 'nucleu' => 'implicit "n"']
    && ($st['multiplu']['valoare'] ?? null) === 'unu' && ($st['multiplu']['vechi'] ?? null) === 'cod "doi" | "unu"'
    && ($st['doar_cod_nucleu']['valoare'] ?? null) === 'Numele vechi' && ($st['doar_cod_nucleu']['nota'] ?? null) === 'nota rămâne'
    && ($doc['fara_inghetare'] ?? null) === ['expresie' => ['motiv' => 'motivul testului', 'vechi' => 'cod expresie', 'nucleu' => 'lipsește']]
    && str_contains($o2, 'fara_inghetare.la_fel: nu mai e nevoie') && $doc['sursa'] === 'repo-vechi@' . $commitVechi,
    $o2 . json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$verifica('valorile private: în fișier înlocuitori și „privat”, adevăratele în privat/ (același site și sursă, „pentru” = sha256 al fișierului)',
    ($st['email_vechi'] ?? null) === ['valoare' => 'adresa1@exemplu.ro', 'mod' => 'lipsa', 'din' => 'implicit', 'privat' => true, 'sursa' => 'Settings::DEFAULTS']
    && ($st['telefon_vechi']['valoare'] ?? null) === '0700 000 001' && ($st['telefon_vechi']['privat'] ?? false) === true
    && !str_contains((string) file_get_contents($fisierProba), 'client-inventat') && !str_contains((string) file_get_contents($fisierProba), '0800 000 111')
    && ($privat['setari'] ?? null) === ['email_vechi' => 'Birou <birou.client@client-inventat.test>', 'telefon_vechi' => '0800 000 111']
    && ($privat['pentru'] ?? '') === hash('sha256', (string) file_get_contents($fisierProba)) && ($privat['site'] ?? '') === 'proba'
    && Inghetare::dinFisier($fisierProba)->valoriPrivate('x')['telefon_vechi'] === '0800 000 111', json_encode($privat, JSON_UNESCAPED_UNICODE));

$text1 = (string) file_get_contents($fisierProba);
$privat1 = (string) file_get_contents($privatProba);
[$c3] = $gen();
[$c4, $o4] = $gen(['--verifica']);
$verifica('determinist: a doua generare dă aceleași fișiere, octet cu octet; --verifica 0', $c3 === 0 && $c4 === 0
    && (string) file_get_contents($fisierProba) === $text1 && (string) file_get_contents($privatProba) === $privat1 && str_contains($o4, 'la zi'), $o4);
// Pe server (--cod-vechi=<dosar>, docs/VERIFICARE.md pasul 6b): doar privat/, din dosarul codului (nu o clonă), dacă dă
// exact înghețarea din pachet; fișierul din pachet neatins, dosarul codului doar citit.
$live = $lucru . '/live-proba';
@mkdir($live, 0777, true);
exec('git -C ' . escapeshellarg($vechiRepo) . ' archive ' . escapeshellarg($commitVechi) . ' | tar -x -C ' . escapeshellarg($live));
file_put_contents($live . '/.env', "DB_PASSWORD=parola-inventata\n");
@mkdir($live . '/public/uploads', 0777, true);
file_put_contents($live . '/public/uploads/x.php', "<?php\n\$a = \$settings['din_uploads'] ?? 'u';\n");
$genServer = static fn (string $dosar, array $extra = []): array => $ruleaza(array_merge([$radacina . '/tools/inghetare/genereaza-setari.php', '--site=proba',
    '--cod-vechi=' . $dosar, '--pachete=' . $pachete, '--nucleu=' . $nucleu, '--lucru=' . $lucru . '/lucru-server'], $extra));
$sumeLive = (string) shell_exec('cd ' . escapeshellarg($live) . ' && find . -type f -exec sha256sum {} + | sort');
unlink($privatProba);
[$cs1, $os1] = $genServer($live);
$verifica('--cod-vechi (pe server): 0, privat/ scris (0600) din dosarul codului, octet cu octet ca din clonă; fișierul din pachet neatins; dosarul codului '
    . 'neschimbat; pe ecran doar numele cheilor private', $cs1 === 0 && (string) @file_get_contents($privatProba) === $privat1
    && (fileperms($privatProba) & 0777) === 0600 && (string) file_get_contents($fisierProba) === $text1
    && (string) shell_exec('cd ' . escapeshellarg($live) . ' && find . -type f -exec sha256sum {} + | sort') === $sumeLive
    && str_contains($os1, '2 valori private: email_vechi, telefon_vechi') && !str_contains($os1, 'client-inventat') && !str_contains($os1, '0800'), $os1);
unlink($privatProba);
file_put_contents($live . '/views/layout.php', str_replace("'unu'", "'trei'", (string) file_get_contents($live . '/views/layout.php')));
[$cs2, $os2] = $genServer($live);
[$cs3, $os3] = $genServer($lucru . '/nu-exista');
[$cs4, $os4] = $genServer($live, ['--repo=' . $vechiRepo . '@' . $commitVechi]);
$verifica('--cod-vechi refuzat (1, nimic scris): alt cod decât cel din pachet („nu e codul revizuit”), un dosar fără src/, împreună cu --repo',
    $cs2 === 1 && (str_contains($os2, 'nu e codul revizuit') || str_contains($os2, 'erori ale generării')) && $cs3 === 1
    && str_contains($os3, 'nu are src/Support/Settings.php') && $cs4 === 1 && str_contains($os4, 'merge doar cu un singur --site') && !is_file($privatProba)
    && (string) file_get_contents($fisierProba) === $text1,
    $os2 . $os3 . $os4);
file_put_contents($privatProba, $privat1);
chmod($privatProba, 0600);

file_put_contents($fisierProba, str_replace('nota rămâne', 'nota schimbată de mână', $text1));
[$c5, $o5] = $gen(['--verifica']);
file_put_contents($fisierProba, $text1);
$verifica('--verifica după o schimbare de mână a fișierului: 1, fișierul rămâne cum era', $c5 === 1 && str_contains($o5, 'altul decât cel generat'), $o5);

// O decizie veche: codul comun schimbă valoarea pe care s-a luat decizia.
$scrieFisier($nucleu . '/src/Support/Settings.php', $settingsPhp(['doar_nucleu' => 'n2'] + $implicitNucleu));
$amprentaInainte = json_decode($text1, true)['amprenta_nucleu'];
[$c6, $o6] = $gen();
$verifica('o decizie luată pe alt implicit al codului comun: 1, „decizie de refăcut” (cu valorile de atunci și de acum), fișierul neatins',
    $c6 === 1 && str_contains($o6, 'decizie de refăcut: setari.doar_nucleu') && str_contains($o6, 'implicit "n2"')
    && (string) file_get_contents($fisierProba) === $text1, $o6);
$docVechi = json_decode($text1, true);
unset($docVechi['setari']['doar_nucleu']['vechi'], $docVechi['setari']['doar_nucleu']['nucleu']);
file_put_contents($fisierProba, $json($docVechi));
[$c7, $o7] = $gen();
$doc7 = json_decode((string) file_get_contents($fisierProba), true);
$verifica('decizia confirmată din nou (fără „vechi”/„nucleu”): legată de valoarea nouă; amprenta_nucleu alta',
    $c7 === 0 && ($doc7['setari']['doar_nucleu']['nucleu'] ?? '') === 'implicit "n2"' && $doc7['amprenta_nucleu'] !== $amprentaInainte, $o7);

// Valori private scrise de mână, extrageri greșite, provizoriu, închis.
$doc8 = $doc7;
$doc8['setari']['doar_nucleu']['valoare'] = 'contact@client-inventat.test';
unset($doc8['setari']['doar_nucleu']['vechi'], $doc8['setari']['doar_nucleu']['nucleu']);
file_put_contents($fisierProba, $json($doc8));
[$c8, $o8] = $gen();
$doc9 = $doc7;
$doc9['setari']['multiplu']['extrage']['fisier'] = 'views/nu-exista.php';
file_put_contents($fisierProba, $json($doc9));
[$c9, $o9] = $gen();
$doc10 = $doc7;
$doc10['setari']['doar_nucleu']['sursa'] = '';
file_put_contents($fisierProba, $json($doc10));
[$c10, $o10] = $gen();
$verifica('o valoare privată scrisă de mână (ar sta în git), un „extrage” care nu merge, o decizie fără „sursa”: 1 de fiecare dată, cu motivul',
    $c8 === 1 && str_contains($o8, 'nu poate fi privată') && !str_contains($o8, 'client-inventat') && $c9 === 1 && str_contains($o9, 'nu se poate scoate')
    && $c10 === 1 && str_contains($o10, 'de unde vine valoarea'), $o8 . $o9 . $o10);
file_put_contents($fisierProba, $json($doc7));
[$c11] = $gen(['--provizoriu=sursa de test, neconfirmată']);
$prov = Inghetare::dinFisier($fisierProba)->provizoriu();
[$c12] = $gen();
$provPastrat = Inghetare::dinFisier($fisierProba)->provizoriu();
[$c13] = $gen(['--confirmat']);
$verifica('provizoriu: --provizoriu=<motiv> îl scrie, o generare fără opțiune îl păstrează, --confirmat îl scoate',
    $c11 === 0 && $prov === 'sursa de test, neconfirmată' && $c12 === 0 && $provPastrat === $prov && $c13 === 0
    && Inghetare::dinFisier($fisierProba)->provizoriu() === null);
$doc14 = json_decode((string) file_get_contents($fisierProba), true);
$doc14['inchis'] = 'aplicat pe site (test)';
file_put_contents($fisierProba, $json($doc14));
[$c14, $o14] = $gen();
$verifica('un fișier închis (aplicat pe site) nu se mai generează: 1, „o corectură merge în setari-inghetate-2.json”',
    $c14 === 1 && str_contains($o14, 'setari-inghetate-2.json'), $o14);
// Fără valori private: fișierul privat rămas se șterge.
$scrieFisier($vechiRepo . '/src/Support/Settings.php', $settingsPhp(['email_vechi' => '', 'telefon_vechi' => ''] + $vechiImplicite));
exec('cd ' . escapeshellarg($vechiRepo) . ' && git -c user.email=test@exemplu.ro -c user.name=Test commit -qam fara-privat 2>&1');
$commit2 = trim((string) shell_exec('git -C ' . escapeshellarg($vechiRepo) . ' rev-parse --short=7 HEAD'));
file_put_contents($fisierProba, $json($doc7));
[$c15, $o15] = $ruleaza([$radacina . '/tools/inghetare/genereaza-setari.php', '--site=proba', '--repo=' . $vechiRepo . '@' . $commit2,
    '--pachete=' . $pachete, '--nucleu=' . $nucleu, '--lucru=' . $lucru . '/lucru-gen']);
$verifica('alt commit al codului vechi, fără valori private: sursa nouă, nicio intrare privată, fișierul privat rămas șters',
    $c15 === 0 && Inghetare::dinFisier($fisierProba)->sursa() === 'repo-vechi@' . $commit2 && Inghetare::dinFisier($fisierProba)->cheiPrivate() === []
    && !is_file($privatProba), $o15);

// =====================================================================
// V. Verificarea, pe pachetele din repo și pe copii greșite
// =====================================================================
$scrie('');
$scrie('V. Verificarea (tools/inghetare/verifica-inghetare.php)');
$verificaIng = static function (array $extra = [], array $mediu = []) use ($ruleaza, $radacina): array {
    return $ruleaza(array_merge([$radacina . '/tools/inghetare/verifica-inghetare.php'], $extra), $mediu);
};
[$cv, $ov] = $verificaIng();
$verifica('pe pachetele din repo (cele patru site-uri): 0, cu reproducerea din clone', $cv === 0 && !str_contains($ov, 'SĂRIT')
    && substr_count($ov, 'generatorul dă acum exact același fișier') === 4, $ov);
$copie = $lucru . '/site-uri';
exec('cp -r ' . escapeshellarg($radacina . '/site-uri') . ' ' . escapeshellarg($copie));
$cuCopie = ['--pachete=' . $copie];
$strica = static function (string $site, callable $schimba) use ($copie): void {
    $f = $copie . '/' . $site . '/setari-inghetate.json';
    $d = json_decode((string) file_get_contents($f), true);
    file_put_contents($f, json_encode($schimba($d), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
};
$refa = static function () use ($copie, $radacina): void {
    exec('rm -rf ' . escapeshellarg($copie) . ' && cp -r ' . escapeshellarg($radacina . '/site-uri') . ' ' . escapeshellarg($copie));
};
$mutatii = [
    'o adresă reală într-o notă' => [static fn () => $strica('bioscem', static function (array $d): array {
        $d['setari']['erp_trimite_din']['nota'] .= ' (scrie la birou.client@client-inventat.test)';
        return $d;
    }), 'fără date private în git'],
    'un telefon într-un motiv' => [static fn () => $strica('vertij', static function (array $d): array {
        $d['fara_inghetare']['tawk_enabled']['motiv'] .= ' — 0244 000 111';
        return $d;
    }), 'fără date private în git'],
    'o valoare privată care nu e înlocuitor' => [static fn () => $strica('grafoanaytis', static function (array $d): array {
        $d['setari']['gdpr_operator_telefon']['valoare'] = 'Telefon de birou';
        return $d;
    }), 'e un înlocuitor'],
    'sursa alt commit' => [static fn () => $strica('bioscem', static function (array $d): array {
        $d['sursa'] = 'Nou-site-bioscem@f5eafa3';
        return $d;
    }), 'ultimul commit revizuit'],
    'un parametru necunoscut în migrari.json' => [static fn () => file_put_contents($copie . '/biovitality/migrari.json',
        json_encode(['link_plata.procesator' => 'stripe'])), 'doar parametri ceruți'],
    'o valoare nepermisă în migrari.json' => [static fn () => file_put_contents($copie . '/biovitality/migrari.json',
        json_encode(['link_plata.procesator_linkuri_existente' => 'paypal'])), 'valoare nepermisă'],
    'o decizie ștearsă' => [static fn () => $strica('vertij', static function (array $d): array {
        unset($d['setari']['welcome_popup_enabled']);
        return $d;
    }), 'decizie lipsă'],
];
$esecuri = [];
foreach ($mutatii as $nume => [$fa, $asteptat]) {
    $refa();
    $fa();
    [$cm, $om] = $verificaIng($cuCopie);
    if ($cm !== 1 || !str_contains($om, $asteptat) || str_contains($om, 'client-inventat') || str_contains($om, '0244 000 111')) {
        $esecuri[] = $nume . ': cod ' . $cm . "\n" . $om;
    }
}
$verifica('fiecare greșeală pusă într-o copie a pachetelor: 1, cu motivul ei, fără valoarea privată în ieșire ('
    . implode(', ', array_keys($mutatii)) . ')', $esecuri === [], implode("\n---\n", $esecuri));

// Un fișier închis (aplicat pe site-ul live): verificarea nu-l mai cere reprodus, --toate îl sare.
$refa();
$strica('bioscem', static function (array $d): array {
    $d['inchis'] = 'aplicat pe site (test)';
    return $d;
});
[$ci, $oi] = $verificaIng($cuCopie);
[$cg, $og] = $ruleaza([$radacina . '/tools/inghetare/genereaza-setari.php', '--toate', '--verifica', '--pachete=' . $copie,
    '--lucru=' . $lucru . '/lucru-gen']);
$verifica('un fișier închis: verificarea 0 („nu se mai generează”), genereaza-setari --toate îl sare (SĂRIT bioscem) și le face pe celelalte trei',
    $ci === 0 && str_contains($oi, 'fișier închis') && $cg === 0 && str_contains($og, 'SĂRIT bioscem: fișierul e închis')
    && substr_count($og, '— la zi') === 3, $oi . $og);

// O setare nouă în codul comun: amprenta și reproducerea pică pe toate patru, chiar și fără clone.
$refa();
$nucleuCopie = $lucru . '/nucleu-copie';
foreach (['src', 'views', 'scripts', 'config', 'public', 'bootstrap.php'] as $parte) {
    exec('mkdir -p ' . escapeshellarg($nucleuCopie) . ' && cp -r ' . escapeshellarg($radacina . '/' . $parte) . ' ' . escapeshellarg($nucleuCopie . '/'));
}
$fisierSettings = $nucleuCopie . '/src/Support/Settings.php';
file_put_contents($fisierSettings, str_replace("private const DEFAULTS = [\n", "private const DEFAULTS = [\n        'setare_noua_de_test' => 'da',\n",
    (string) file_get_contents($fisierSettings)));
[$cn, $on] = $verificaIng(['--nucleu=' . $nucleuCopie]);
[$cnf, $onf] = $verificaIng(['--nucleu=' . $nucleuCopie, '--fara-clone'], ['CMS_DEV_REPO_BS' => '/nu/exista', 'CMS_DEV_REPO_BV' => '/nu/exista',
    'CMS_DEV_REPO_VT' => '/nu/exista', 'CMS_DEV_REPO_GR' => '/nu/exista']);
$verifica('o setare nouă în codul comun (setare_noua_de_test): amprenta pică pe toate patru, reproducerea cere decizia ei; fără clone '
    . '(--fara-clone) tot pică, din amprentă',
    $cn === 1 && substr_count($on, 'EȘEC  valorile codului comun') === 4 && str_contains($on, '„setare_noua_de_test”')
    && $cnf === 1 && substr_count($onf, 'EȘEC  valorile codului comun') === 4 && str_contains($onf, 'SĂRIT reproducerea'), $on . $onf);
[$cc, $oc] = $verificaIng([], ['CMS_DEV_REPO_BV' => '/nu/exista']);
[$ccf, $ocf] = $verificaIng(['--fara-clone'], ['CMS_DEV_REPO_BV' => '/nu/exista']);
$verifica('clona unui site lipsă: 1 (nu se sare în tăcere); cu --fara-clone 0, „reproducerea SĂRITĂ pentru biovitality” în rezumat',
    $cc === 1 && str_contains($oc, 'clona /nu/exista lipsește') && $ccf === 0 && str_contains($ocf, 'reproducerea SĂRITĂ pentru biovitality'), $oc . $ocf);

$scrie('');
$scrie('test-inghetare: ' . $trecute . ' trecute, ' . $esuate . ' eșuate, PHP ' . PHP_VERSION . '.');
exit($esuate === 0 ? 0 : 1);
