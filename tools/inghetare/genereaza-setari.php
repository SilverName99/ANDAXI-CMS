<?php

declare(strict_types=1);

/**
 * Generatorul înghețării setărilor unui site vechi (docs/MIGRARI.md, „Înghețarea setărilor”; WP-06,
 * MIG-09, DB-02): scrie `site-uri/<site>/setari-inghetate.json` din ce folosește codul vechi al site-ului
 * față de codul comun când o setare lipsește din bază (Settings::DEFAULTS și rezervele din cod, fiecare
 * cod citit într-un proces separat; tools/inghetare/lib/Generator.php spune regula). Determinist: același
 * cod vechi, același cod comun și aceleași decizii dau același fișier, octet cu octet.
 *
 *   tools/dev/php.sh tools/inghetare/genereaza-setari.php --toate
 *   tools/dev/php.sh tools/inghetare/genereaza-setari.php --site=bioscem
 *   tools/dev/php.sh tools/inghetare/genereaza-setari.php --site=biovitality --provizoriu="<motiv>"
 *   tools/dev/php.sh tools/inghetare/genereaza-setari.php --site=biovitality --confirmat     # scoate „provizoriu”
 *   tools/dev/php.sh tools/inghetare/genereaza-setari.php --toate --verifica                # nu scrie: e la zi?
 *
 * Codul vechi: clona site-ului (CMS_DEV_REPO_BS, _BV, _VT, _GR din tools/dev/comun.sh) la „ultimul commit
 * revizuit” din docs/de-portat.md, sau `--repo=<clonă>@<commit>` (doar cu --site; apoi verificarea cere
 * tot commit-ul din de-portat.md, deci un commit nou se trece întâi acolo). Pentru BV, VT și GR sursa
 * rămâne „provizoriu” până confirmă raportul „live față de repo” din Etapa 0 ce commit rulează pe site.
 *
 * Deciziile (setările „din”: „manual”/„extras” și fara_inghetare) se scriu de mână în fișier; o decizie
 * lipsă sau una luată pe alte valori decât cele de acum = eroare, iar fișierul rămâne cum era. Valorile
 * private (OD-49) merg în `site-uri/<site>/privat/setari-inghetate.json`, pe care git îl ignoră (refuză
 * să-l scrie altfel); în fișierul din git rămân înlocuitori @exemplu.ro.
 *
 * Doar pentru teste: --pachete=<dosar> (în loc de site-uri/), --nucleu=<dosar> (în loc de acest repo),
 * --lucru=<dosar> (copiile codurilor vechi; implicit $CMS_DEV_DIR/inghetare).
 *
 * Un fișier „inchis” (aplicat pe site-ul live) nu se mai generează: --toate îl sare (SĂRIT, cu motivul), --site=<el> e
 * o eroare.
 *
 * Cod de ieșire: 0 = scris (sau, cu --verifica, la zi); 1 = o eroare, o decizie lipsă, sau (--verifica) diferit.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = (string) realpath(dirname(__DIR__, 2));
foreach (['Eroare', 'ParametruLipsa', 'Inghetare'] as $clasa) {
    require_once $radacina . '/src/Support/Migrari/' . $clasa . '.php';
}
require_once __DIR__ . '/lib/Generator.php';
require_once $radacina . '/src/Support/Tema.php';
require_once $radacina . '/src/Support/PachetSite.php';

use App\Support\Migrari\Inghetare;
use App\Support\PachetSite;
use Unelte\Inghetare\Generator;

$opreste = static function (string $mesaj): never {
    fwrite(STDERR, 'EROARE: ' . $mesaj . "\n");
    exit(1);
};

$alese = [];
$toate = false;
$repo = null;
$provizoriu = null;
$verifica = false;
$pachete = $radacina . '/site-uri';
$nucleu = $radacina;
$lucru = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/') . '/inghetare';
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--site=([a-z0-9][a-z0-9-]*)$/', $argument, $m) === 1) {
        $alese[] = $m[1];
    } elseif ($argument === '--toate') {
        $toate = true;
    } elseif (str_starts_with($argument, '--repo=')) {
        $repo = substr($argument, strlen('--repo='));
    } elseif (str_starts_with($argument, '--provizoriu=')) {
        $provizoriu = trim(substr($argument, strlen('--provizoriu=')));
        if ($provizoriu === '') {
            $opreste('--provizoriu=<motiv>: motivul lipsește');
        }
    } elseif ($argument === '--confirmat') {
        $provizoriu = false;
    } elseif ($argument === '--verifica') {
        $verifica = true;
    } elseif (str_starts_with($argument, '--pachete=')) {
        $pachete = rtrim(substr($argument, strlen('--pachete=')), '/');
    } elseif (str_starts_with($argument, '--nucleu=')) {
        $nucleu = rtrim(substr($argument, strlen('--nucleu=')), '/');
    } elseif (str_starts_with($argument, '--lucru=')) {
        $lucru = rtrim(substr($argument, strlen('--lucru=')), '/');
    } else {
        $opreste('argument necunoscut: ' . $argument . ' (vezi capul fișierului)');
    }
}
if ($toate) {
    $alese = array_keys(Generator::SITURI);
}
if ($alese === [] || ($toate && count(array_unique($alese)) !== count(Generator::SITURI))) {
    $opreste('--site=<site> sau --toate (bioscem, biovitality, vertij, grafoanaytis)');
}
if ($repo !== null && count($alese) !== 1) {
    $opreste('--repo merge doar cu un singur --site');
}
if ($provizoriu !== null && $verifica) {
    $opreste('--provizoriu/--confirmat schimbă fișierul: nu merg cu --verifica');
}

$generator = new Generator($radacina, $lucru);
try {
    $citireNucleu = $generator->citesteCod($nucleu);
} catch (Throwable $e) {
    $opreste('codul comun (' . $nucleu . '): ' . $e->getMessage());
}

$esuate = 0;
foreach ($alese as $site) {
    $fisier = $pachete . '/' . $site . '/' . Inghetare::FISIER;
    $privat = Inghetare::caleaPrivata($fisier);
    try {
        if ($repo !== null) {
            if (preg_match('/^(.*)@([0-9A-Za-z._\/-]+)$/', $repo, $m) !== 1) {
                throw new RuntimeException('--repo=<clonă>@<commit>');
            }
            $clona = (string) realpath($m[1]);
            $commit = $m[2];
            if ($clona === '') {
                throw new RuntimeException('--repo: ' . $m[1] . ' nu există');
            }
            $numeRepo = basename($clona);
        } elseif (isset(Generator::SITURI[$site])) {
            $rand = $generator->tabelDePortat()[$site];
            $clona = $generator->clona($site);
            $commit = $rand['revizuit'];
            $numeRepo = $rand['repo'];
        } else {
            throw new RuntimeException('site necunoscut „' . $site . '”: dă codul vechi cu --repo=<clonă>@<commit>');
        }
        $existent = null;
        if (is_file($fisier)) {
            Inghetare::dinFisier($fisier); // forma, înainte de orice
            $existent = json_decode((string) file_get_contents($fisier), true);
        }
        if ($toate && isset($existent['inchis'])) {
            // Aplicat pe site: nu se mai generează (cerut anume cu --site, e o eroare a generatorului, cu motivul).
            echo 'SĂRIT ', $site, ': fișierul e închis (', $existent['inchis'], '); o corectură merge în setari-inghetate-2.json', "\n";
            continue;
        }
        [$dosarCod, $scurt] = $generator->copie($clona, $commit);
        $citireVeche = $generator->citesteCod($dosarCod, Generator::cereriExtrase($existent));
        $rezultat = $generator->genereaza($site, $numeRepo . '@' . $scurt, $existent, $citireVeche, $citireNucleu, $provizoriu);
    } catch (Throwable $e) {
        echo 'EȘEC  ', $site, ': ', $e->getMessage(), "\n";
        $esuate++;
        continue;
    }
    foreach ($rezultat['scoase'] as $text) {
        echo '  scos   ', $text, "\n";
    }
    foreach ($rezultat['erori'] as $text) {
        echo '  EROARE ', $text, "\n";
    }
    if ($rezultat['erori'] !== []) {
        echo 'EȘEC  ', $rezultat['rezumat'], ': ', count($rezultat['erori']), ' erori, fișierul rămâne cum era', "\n";
        $esuate++;
        continue;
    }
    if ($verifica) {
        $laZi = is_file($fisier) && (string) file_get_contents($fisier) === $rezultat['text'];
        $privatLaZi = $rezultat['privat'] === null ? !is_file($privat) : (!is_file($privat) || (string) file_get_contents($privat) === $rezultat['privat']);
        if ($laZi && $privatLaZi) {
            echo 'OK    ', $rezultat['rezumat'], ' — la zi', ($rezultat['privat'] !== null && !is_file($privat) ? ' (privat/ lipsește local)' : ''), "\n";
        } else {
            echo 'EȘEC  ', $rezultat['rezumat'], ' — ', !$laZi ? 'fișierul din pachet e altul decât cel generat acum' : 'privat/ e altul decât cel generat acum',
                ' (rulează fără --verifica și citește diff-ul)', "\n";
            $esuate++;
        }
        continue;
    }
    @mkdir(dirname($fisier), 0777, true);
    file_put_contents($fisier, $rezultat['text']);
    if ($rezultat['privat'] !== null) {
        // Fișierul privat nu are voie să ajungă în git (OD-49): în acest repo, doar într-o cale ignorată.
        if (str_starts_with($privat, $radacina . '/')) {
            exec('git -C ' . escapeshellarg($radacina) . ' check-ignore -q ' . escapeshellarg(substr($privat, strlen($radacina) + 1)), $_, $codIgnorat);
            if ($codIgnorat !== 0) {
                echo 'EȘEC  ', $site, ': ', $privat, ' nu e ignorat de git (.gitignore: /site-uri/*/privat/); nu scriu valorile private', "\n";
                $esuate++;
                continue;
            }
        }
        @mkdir(dirname($privat), 0700, true);
        file_put_contents($privat, $rezultat['privat']);
        @chmod($privat, 0600);
    } elseif (is_file($privat)) {
        unlink($privat);
    }
    // Manifestul pachetului (pachet.json, docs/PACHETE.md) ține sha256-ul fiecărui fișier: îl rescrie odată cu înghețarea,
    // ca pachetul să rămână verificabil (tools/dev/verifica-pachet.php, în testul de fum). privat/ nu intră în el.
    $dosarPachet = dirname($fisier);
    $manifestNou = '';
    if (is_file($dosarPachet . '/' . PachetSite::MANIFEST)) {
        $existent = PachetSite::manifest($dosarPachet);
        $manifestNou = PachetSite::text(PachetSite::calculeaza($dosarPachet, is_string($existent['site'] ?? null) ? (string) $existent['site'] : $site));
        file_put_contents($dosarPachet . '/' . PachetSite::MANIFEST, $manifestNou);
    }
    echo 'OK    ', $rezultat['rezumat'], ' — scris ', substr($fisier, strlen($radacina) + 1) ?: $fisier,
        $rezultat['privat'] !== null ? ' (+ privat/, în afara git-ului)' : '', $manifestNou !== '' ? ' (+ pachet.json)' : '', "\n";
}
exit($esuate === 0 ? 0 : 1);
