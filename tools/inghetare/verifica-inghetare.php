<?php

declare(strict_types=1);

/**
 * Verificarea înghețării setărilor (docs/MIGRARI.md, „Înghețarea setărilor”; WP-06, DB-02). Rulează în
 * testul de fum (tools/dev/smoke.sh) și în poarta G-mig din tools/dev/porti.sh: nu există CI, deci aici
 * se oprește o setare nouă sau schimbată în codul comun fără înghețarea ei pe site-urile vechi.
 *
 *   tools/dev/php.sh tools/inghetare/verifica-inghetare.php                 # cele patru site-uri
 *   tools/dev/php.sh tools/inghetare/verifica-inghetare.php bioscem         # doar unele
 *   tools/dev/php.sh tools/inghetare/verifica-inghetare.php --fara-clone    # fără clonele codurilor vechi (spus explicit)
 *
 * Pentru fiecare site (site-uri/<site>/):
 *  1. setari-inghetate.json există și se citește ca runner-ul (App\Support\Migrari\Inghetare), e al
 *     acelui site; la fel corecturile setari-inghetate-<N>.json;
 *  2. nicio dată privată în git (OD-49): nicio adresă de email în afara @exemplu.ro și niciun telefon,
 *     iar fiecare valoare „privat” e un înlocuitor;
 *  3. „sursa” e codul vechi al site-ului la „ultimul commit revizuit” din docs/de-portat.md;
 *  4. completitudinea, fără clone: valorile codului comun (Settings::DEFAULTS și rezervele din cod) sunt
 *     cele de la generare (`amprenta_nucleu`); o setare nouă sau schimbată în codul comun = de generat din
 *     nou, cu deciziile ei (tools/inghetare/genereaza-setari.php --toate);
 *  5. reproducerea, cu clona codului vechi: generatorul dă acum exact același fișier (și același fișier
 *     privat, dacă există local), deci nicio decizie nu lipsește și niciuna nu e veche;
 *  6. migrari.json (dacă există): JSON, doar parametri ceruți de migrări (Schema::parametruSite în
 *     database/migrari), cu valori permise; `_provizoriu` doar pentru parametri care există.
 * Un fișier „inchis” (aplicat pe site) nu se mai generează: 4 și 5 nu se fac pentru el.
 *
 * Cod de ieșire 0 doar dacă totul a trecut. Ultimul rând e rezumatul (îl arată smoke.sh).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = (string) realpath(dirname(__DIR__, 2));
foreach (['Eroare', 'ParametruLipsa', 'Inghetare', 'Registru'] as $clasa) {
    require_once $radacina . '/src/Support/Migrari/' . $clasa . '.php';
}
require_once __DIR__ . '/lib/Generator.php';

use App\Support\Migrari\Inghetare;
use App\Support\Migrari\Registru;
use Unelte\Inghetare\Generator;

$faraClone = false;
$alese = [];
$pachete = $radacina . '/site-uri';
$nucleu = $radacina;
$lucru = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/') . '/inghetare';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--fara-clone') {
        $faraClone = true;
    } elseif (str_starts_with($argument, '--pachete=')) {
        $pachete = rtrim(substr($argument, strlen('--pachete=')), '/');
    } elseif (str_starts_with($argument, '--nucleu=')) {
        $nucleu = rtrim(substr($argument, strlen('--nucleu=')), '/');
    } elseif (preg_match('/^[a-z0-9][a-z0-9-]*$/', $argument) === 1) {
        $alese[] = $argument;
    } else {
        fwrite(STDERR, 'argument necunoscut: ' . $argument . " (vezi capul fișierului)\n");
        exit(2);
    }
}
if ($alese === []) {
    $alese = array_keys(Generator::SITURI);
    // Și orice alt pachet care are o înghețare.
    foreach (glob($pachete . '/*/' . Inghetare::FISIER) ?: [] as $fisier) {
        $alese[] = basename(dirname($fisier));
    }
    $alese = array_values(array_unique($alese));
}

$trecute = 0;
$esuate = 0;
$verifica = static function (string $eticheta, bool $ok, string $detalii = '') use (&$trecute, &$esuate): void {
    if ($ok) {
        $trecute++;
        echo '  OK    ', $eticheta, "\n";
    } else {
        $esuate++;
        echo '  EȘEC  ', $eticheta, ($detalii !== '' ? ': ' . $detalii : ''), "\n";
    }
};
$sarite = [];

$generator = new Generator($radacina, $lucru);
$tabel = [];
$citireNucleu = null;
try {
    $tabel = $generator->tabelDePortat();
    $citireNucleu = $generator->citesteCod($nucleu);
} catch (Throwable $e) {
    $verifica('codul comun și docs/de-portat.md se citesc', false, $e->getMessage());
}
$amprentaAcum = $citireNucleu !== null ? Generator::amprenta($citireNucleu) : null;

// Parametrii pe care îi cer migrările (Schema::parametruSite('…') în database/migrari).
$parametriCeruti = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radacina . '/database/migrari', FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')
        && preg_match_all("/parametruSite\\(\\s*'([^']+)'\\s*\\)/", (string) file_get_contents($f->getPathname()), $m) > 0) {
        foreach ($m[1] as $cheie) {
            $parametriCeruti[$cheie] = true;
        }
    }
}
/** Valorile permise ale fiecărui parametru cunoscut (alt parametru: orice text negol). */
$valoriPermise = ['link_plata.procesator_linkuri_existente' => ['euplatesc', 'btipay', 'stripe']];

foreach ($alese as $site) {
    $dosar = $pachete . '/' . $site;
    $fisier = $dosar . '/' . Inghetare::FISIER;
    echo $site, ":\n";
    try {
        $inghetare = Inghetare::dinFisier($fisier);
    } catch (Throwable $e) {
        $verifica($site . '/' . Inghetare::FISIER . ' se citește ca la runner', false, $e->getMessage());
        continue;
    }
    $verifica($site . '/' . Inghetare::FISIER . ' se citește ca la runner și e al site-ului „' . $site . '” ('
        . count($inghetare->setari()) . ' setări, ' . count($inghetare->faraInghetare()) . ' fără înghețare)', $inghetare->site() === $site,
        'e al site-ului „' . $inghetare->site() . '”');

    // 1 + 2: corecturile și datele private, în fiecare fișier de înghețare al pachetului.
    $registru = new Registru($radacina . '/database/migrari', []);
    try {
        $fisiere = $registru->fisiereInghetare($dosar);
    } catch (Throwable $e) {
        $verifica('numele fișierelor de înghețare', false, $e->getMessage());
        $fisiere = [];
    }
    foreach ($fisiere as $nume => $cale) {
        $text = (string) file_get_contents($cale);
        if ($nume !== 'setari-inghetate') {
            try {
                $corectura = Inghetare::dinFisier($cale);
                $verifica($nume . '.json se citește și e al site-ului', $corectura->site() === $site, 'e al site-ului „' . $corectura->site() . '”');
            } catch (Throwable $e) {
                $verifica($nume . '.json se citește', false, $e->getMessage());
                continue;
            }
        }
        $emailuri = Inghetare::emailuriReale($text);
        $telefoane = Inghetare::telefoane($text);
        $verifica($nume . '.json fără date private în git (emailuri doar @exemplu.ro, niciun telefon; OD-49)', $emailuri === [] && $telefoane === [],
            count($emailuri) . ' adrese și ' . count($telefoane) . ' telefoane (nu le scriu aici); valorile private merg în privat/, cu genereaza-setari.php');
        $deVerificat = $nume === 'setari-inghetate' ? $inghetare : Inghetare::dinFisier($cale);
        $faraInlocuitor = array_keys(array_filter($deVerificat->setari(), static fn (array $i): bool =>
            $i['privat'] && !Inghetare::eInlocuitor((string) $i['valoare'])));
        $verifica($nume . '.json: fiecare valoare „privat” e un înlocuitor (' . count($deVerificat->cheiPrivate()) . ' private)', $faraInlocuitor === [],
            implode(', ', $faraInlocuitor));
    }

    // 3: sursa = ultimul commit revizuit.
    if (isset(Generator::SITURI[$site])) {
        $asteptata = isset($tabel[$site]) ? $tabel[$site]['repo'] . '@' . $tabel[$site]['revizuit'] : '?';
        $verifica('sursa ' . $inghetare->sursa() . ' = „ultimul commit revizuit” din docs/de-portat.md', $inghetare->sursa() === $asteptata,
            'în de-portat.md: ' . $asteptata . ' (când se mută commit-ul revizuit, se generează din nou: genereaza-setari.php --site=' . $site . ')');
    }
    if ($inghetare->provizoriu() !== null) {
        echo '  INFO  înghețare provizorie: ', $inghetare->provizoriu(), "\n";
    }

    if ($inghetare->inchis() !== null) {
        echo '  INFO  fișier închis (', $inghetare->inchis(), '): nu se mai generează; o corectură e setari-inghetate-2.json', "\n";
    } elseif ($amprentaAcum !== null) {
        // 4: completitudinea, fără clone.
        $verifica('valorile codului comun (Settings::DEFAULTS și rezervele din cod) sunt cele de la generare (amprenta_nucleu)',
            $inghetare->amprentaNucleu() === $amprentaAcum,
            'o setare a codului comun s-a schimbat sau e nouă: tools/dev/php.sh tools/inghetare/genereaza-setari.php --toate (diff-ul arată ce '
            . 'trebuie înghețat; o decizie lipsă sau veche oprește generarea)');

        // 5: reproducerea, cu clona codului vechi.
        $clona = null;
        $commit = null;
        if (isset(Generator::SITURI[$site]) && preg_match('/@([0-9a-f]{7,40})$/', $inghetare->sursa(), $m) === 1) {
            $clona = $generator->clona($site);
            $commit = $m[1];
        }
        $areClona = $clona !== null && (is_dir($clona . '/.git') || is_file($clona . '/.git'));
        if (!$areClona) {
            $motiv = $clona === null ? 'site fără clonă cunoscută' : 'clona ' . $clona . ' lipsește (' . (Generator::SITURI[$site] ?? '?') . ')';
            if ($faraClone || $clona === null) {
                echo '  SĂRIT reproducerea: ', $motiv, ($faraClone ? ' (--fara-clone)' : ''), "\n";
                $sarite[] = $site;
            } else {
                $verifica('reproducerea cu codul vechi', false, $motiv . '; fără ea: --fara-clone (spus în rezultat)');
            }
        } else {
            try {
                [$dosarCod, $scurt] = $generator->copie($clona, (string) $commit);
                $existent = json_decode((string) file_get_contents($fisier), true);
                $citireVeche = $generator->citesteCod($dosarCod, Generator::cereriExtrase($existent));
                $sursa = preg_replace('/@[0-9a-f]+$/', '@' . $scurt, $inghetare->sursa());
                $rezultat = $generator->genereaza($site, (string) $sursa, $existent, $citireVeche, (array) $citireNucleu);
                $verifica('generatorul dă acum exact același fișier (nicio decizie lipsă sau veche)',
                    $rezultat['erori'] === [] && $rezultat['text'] === (string) file_get_contents($fisier),
                    $rezultat['erori'] !== [] ? implode(' | ', array_slice($rezultat['erori'], 0, 5)) : 'fișierul e altul: genereaza-setari.php --site=' . $site . ' și citește diff-ul');
                $privat = Inghetare::caleaPrivata($fisier);
                if ($rezultat['privat'] !== null && !is_file($privat)) {
                    echo '  INFO  privat/', Inghetare::FISIER, ' lipsește local (se face cu genereaza-setari.php; pe server îl pune proprietarul)', "\n";
                } elseif ($rezultat['privat'] !== null || is_file($privat)) {
                    $verifica('privat/' . Inghetare::FISIER . ' (local, în afara git-ului) e cel generat acum',
                        is_file($privat) && (string) file_get_contents($privat) === $rezultat['privat'], 'genereaza-setari.php --site=' . $site);
                }
            } catch (Throwable $e) {
                $verifica('reproducerea cu codul vechi', false, $e->getMessage());
            }
        }
    }

    // 6: parametrii site-ului.
    $fisierParametri = $dosar . '/migrari.json';
    if (is_file($fisierParametri)) {
        $parametri = json_decode((string) file_get_contents($fisierParametri), true);
        if (!is_array($parametri)) {
            $verifica('migrari.json e JSON', false, json_last_error_msg());
            continue;
        }
        $gresite = [];
        foreach ($parametri as $cheie => $valoare) {
            $cheie = (string) $cheie;
            if (str_starts_with($cheie, '_')) {
                continue;
            }
            if (!isset($parametriCeruti[$cheie])) {
                $gresite[] = $cheie . ' (nicio migrare nu-l cere)';
            } elseif (!is_string($valoare) || trim($valoare) === '' || (isset($valoriPermise[$cheie]) && !in_array($valoare, $valoriPermise[$cheie], true))) {
                $gresite[] = $cheie . ' (valoare nepermisă' . (isset($valoriPermise[$cheie]) ? ': ' . implode(', ', $valoriPermise[$cheie]) : '') . ')';
            }
        }
        foreach (array_keys(is_array($parametri['_provizoriu'] ?? null) ? $parametri['_provizoriu'] : []) as $cheie) {
            if (!array_key_exists($cheie, $parametri)) {
                $gresite[] = '_provizoriu.' . $cheie . ' (parametrul lipsește)';
            }
        }
        if (array_key_exists('_provizoriu', $parametri) && !is_array($parametri['_provizoriu'])) {
            $gresite[] = '_provizoriu (trebuie să fie un obiect {parametru: motiv})';
        }
        $verifica('migrari.json: doar parametri ceruți de migrări, cu valori permise', $gresite === [], implode('; ', $gresite));
        foreach ((array) ($parametri['_provizoriu'] ?? []) as $cheie => $motiv) {
            echo '  INFO  parametru provizoriu ', $cheie, ': ', is_string($motiv) ? $motiv : '?', "\n";
        }
    }
}

echo 'verifica-inghetare: ', $trecute, ' verificări trecute, ', $esuate, ' eșuate (', implode(', ', $alese), ')',
    $sarite !== [] ? '; reproducerea SĂRITĂ pentru ' . implode(', ', $sarite) : '', "\n";
exit($esuate === 0 ? 0 : 1);
