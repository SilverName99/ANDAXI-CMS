<?php

declare(strict_types=1);

/**
 * Testele preluării automate a licenței de la CRM (App\Support\LicentaSincronizare, scripts/licenta-sincronizare.php,
 * „Verifică acum” din Admin → Abonamentul tău; docs/PLUGINURI.md, „Preluarea automată”), cu un CRM simulat (`php -S` pe
 * CMS_DEV_PORT + 56, care servește ce îi scrie testul și notează fiecare cerere) și o pereche de chei Ed25519 a testului:
 *
 *   tools/dev/php.sh tools/dev/test-licenta-sincronizare.php
 *
 * A. Fără rețea: verificarea unei licențe primite (semnătura, alt site, versiune mai veche, aceeași versiune cu alt conținut,
 *    licența fără dată de expirare, ca la mână) și configurarea (doar https://, în afară de 127.0.0.1/localhost; fără cheie; o adresă
 *    cu utilizator și parolă).
 * B. Cu CRM-ul simulat, pe un dosar de licență al testului: o licență mai nouă se primește (fișierul e exact răspunsul,
 *    0660 ca la mână, fără fișiere temporare rămase), pluginul pornit apare în stări și în `stare-aplicata.json`, ca la un fișier urcat
 *    de mână (aceleași stări, aceeași licență bună, comparat cu un al doilea dosar în care fișierul e pus de mână); a doua
 *    oară „la zi”, fără scriere; oprirea unui plugin din CRM; respinse, cu licența neatinsă: semnătură greșită, altă cheie,
 *    alt site, versiune mai veche, aceeași versiune cu alt conținut, HTTP 401/404/500, JSON stricat, un răspuns
 *    prea mare, o redirecționare (nu se urmează), CRM-ul oprit (și cu fișierul de stare și jurnalul: motivul, fără cheie;
 *    același eșec o singură dată în jurnal); cheia trimisă doar în antetul Authorization.
 * C. Scrierea atomică: un proces care citește `licenta.json` în buclă cât testul pune 30 de licențe la rând nu vede niciodată
 *    un fișier pe jumătate sau nesemnat.
 * D. Cronul prin `bin/platforma`, pe o găzduire simulată (`~/platforma/releases/<v>/` = o copie a codului, `site/` cu `.env`,
 *    `RELEASE` și `storage/`): licența ajunge în `site/storage/licenta/`, codurile de ieșire 0/1/2.
 * E. Butonul „Verifică acum”, pe un server al testului (CMS_DEV_PORT + 55, licența într-un dosar al lui): panoul și butonul
 *    doar cu preluarea configurată, POST fără jeton refuzat (nicio cerere la CRM), cu jeton: licența nouă aplicată și
 *    rezultatul în pagină, CRM-ul oprit: mesajul, site-ul merge mai departe; administratorul de magazin nu ajunge la
 *    pagină și nici la buton (nicio cerere la CRM).
 *
 * Raportul: $CMS_DEV_DIR/test-licenta-sincronizare-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Nu atinge licența
 * bazei de dezvoltare (storage/licenta) și nu scrie în bază.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Support\Auth;
use App\Support\Licenta;
use App\Support\LicentaSincronizare;
use App\Support\Plugins;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-licenta-sincronizare-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 1500) : ''));
    }
};

$dosarTest = $dosarDev . '/test-licenta-sincronizare';
exec('rm -rf ' . escapeshellarg($dosarTest));
mkdir($dosarTest . '/licenta', 0770, true);
mkdir($dosarTest . '/manual', 0770, true);

// Perechea de chei a „CRM-ului” testului și o a doua, străină.
$pereche = sodium_crypto_sign_keypair();
$privata = sodium_crypto_sign_secretkey($pereche);
$publica = base64_encode(sodium_crypto_sign_publickey($pereche));
$privataStraina = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
$site = 'biovitality';
$cheieSite = 'clsite0000000000000000001.' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$portCrm = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 56;
$portSite = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 55;
$urlCrm = 'http://127.0.0.1:' . $portCrm . '/api/site/licenta';
$jurnalSinc = $dosarTest . '/licenta-sincronizare.log';

$licentaText = static function (int $versiune, array $pluginuri, array $extra = [], ?string $cheie = null, string $pentru = '')
    use ($privata, $site): string {
    return Licenta::semneaza(array_merge(['site' => $pentru !== '' ? $pentru : $site, 'emisa_la' => gmdate('Y-m-d\TH:i:s\Z'),
        'versiune' => $versiune, 'pluginuri' => $pluginuri], $extra), $cheie ?? $privata);
};
$configureaza = static function (string $dosar, string $pentru = '') use ($publica, $site, $urlCrm, $cheieSite, $jurnalSinc): void {
    Licenta::configureaza(['dosar' => $dosar, 'cheie_publica' => $publica, 'site' => $pentru !== '' ? $pentru : $site]);
    Plugins::reseteaza();
    Plugins::folosesteJurnal(dirname($jurnalSinc) . '/pluginuri.log');
    LicentaSincronizare::configureaza(['url' => $urlCrm, 'cheie' => $cheieSite, 'jurnal' => $jurnalSinc], 3);
};

// ---------------------------------------------------------------- CRM-ul simulat
$fisierRaspuns = $dosarTest . '/crm-raspuns.json';
$fisierCereri = $dosarTest . '/crm-cereri.log';
file_put_contents($dosarTest . '/crm.php', <<<'PHP'
<?php
// CRM-ul simulat: răspunde cu ce scrie testul în crm-raspuns.json și notează fiecare cerere în crm-cereri.log.
$dosar = __DIR__;
$antete = function_exists('getallheaders') ? array_change_key_case((array) getallheaders(), CASE_LOWER) : [];
$auth = (string) ($antete['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
file_put_contents($dosar . '/crm-cereri.log', json_encode(['metoda' => $_SERVER['REQUEST_METHOD'], 'cale' => $_SERVER['REQUEST_URI'],
    'auth' => $auth, 'antete' => $antete]) . "\n", FILE_APPEND | LOCK_EX);
$r = json_decode((string) file_get_contents($dosar . '/crm-raspuns.json'), true);
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/site/licenta') {
    http_response_code(404);
    return;
}
if ($auth !== 'Bearer ' . $r['cheie']) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"error":"Cheie lipsa sau gresita."}';
    return;
}
http_response_code((int) $r['status']);
if (($r['locatie'] ?? '') !== '') {
    header('Location: ' . $r['locatie']);
}
header('Content-Type: application/json; charset=utf-8');
echo base64_decode((string) $r['corp']);
PHP);
$raspunde = static function (string $corp, int $status = 200, string $locatie = '', ?string $cheie = null) use ($fisierRaspuns, $cheieSite): void {
    file_put_contents($fisierRaspuns, json_encode(['status' => $status, 'corp' => base64_encode($corp), 'locatie' => $locatie,
        'cheie' => $cheie ?? $cheieSite]));
};
$cereri = static function () use ($fisierCereri): array {
    $linii = is_file($fisierCereri) ? file($fisierCereri, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    return array_map(static fn (string $l): array => (array) json_decode($l, true), $linii ?: []);
};
$raspunde('{}', 503);
$procese = [];
$pornesteCrm = static function () use ($portCrm, $dosarTest, &$procese) {
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $portCrm, $dosarTest . '/crm.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dosarTest . '/crm-server.log', 'a'], 2 => ['file', $dosarTest . '/crm-server.log', 'a']], $_);
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        $s = @fsockopen('127.0.0.1', $portCrm, $_e, $_m, 0.2);
        if ($s !== false) {
            fclose($s);
            break;
        }
    }
    $procese['crm'] = $proc;
    return $proc;
};
$opreste = static function (string $nume) use (&$procese): void {
    if (!isset($procese[$nume])) {
        return;
    }
    $stare = proc_get_status($procese[$nume]);
    exec('pkill -TERM -P ' . (int) $stare['pid'] . ' 2>/dev/null');
    proc_terminate($procese[$nume]);
    proc_close($procese[$nume]);
    unset($procese[$nume]);
    usleep(200000);
};
register_shutdown_function(static function () use (&$procese, $opreste): void {
    foreach (array_keys($procese) as $nume) {
        $opreste($nume);
    }
});

$toate = array_keys(Plugins::catalog()['pluginuri']);
$pornite = static fn (array $chei): array => array_fill_keys($chei, 'pornit');
$dosar = $dosarTest . '/licenta';
$fisier = $dosar . '/' . Licenta::FISIER;
$temporare = static fn (string $d): array => glob($d . '/*.tmp') ?: [];
$mod = static function (string $f): string {
    clearstatcache(true, $f);
    return is_file($f) ? sprintf('%04o', fileperms($f) & 0777) : 'lipsă';
};

// =====================================================================
// A. Fără rețea
// =====================================================================
$scrie('== A. Verificarea unei licențe primite și configurarea (fără rețea)');
$configureaza($dosar);
$v5 = $licentaText(5, $pornite(['catalog', 'magazin']));
$verifica('licența de pornire (v5) se pune cu Licenta::instaleaza', Licenta::instaleaza($v5)['ok']);
Plugins::reseteaza();
$verifica('fără blog la început', Plugins::stare('blog') === Plugins::OPRIT && Plugins::stare('magazin') === Plugins::PORNIT);

$vp = static fn (string $json): array => LicentaSincronizare::verificaPrimita($json);
$verifica('v6 bună: acceptată', $vp($licentaText(6, $pornite(['catalog', 'magazin', 'blog'])))['ok']);
$v6 = $licentaText(6, $pornite(['catalog', 'magazin', 'blog']));
$stricata = str_replace('"blog": "pornit"', '"blog": "se_opreste"', $v6);
$r = $vp($stricata);
$verifica('semnătura greșită (conținut schimbat după semnare): respinsă', $stricata !== $v6 && !$r['ok'] && str_contains($r['mesaj'], 'semnătura'), $r['mesaj']);
$r = $vp($licentaText(6, $pornite(['catalog']), [], $privataStraina));
$verifica('semnată cu altă cheie: respinsă', !$r['ok'] && str_contains($r['mesaj'], 'semnătura'), $r['mesaj']);
$r = $vp($licentaText(6, $pornite(['catalog']), [], null, 'bioscem'));
$verifica('pentru alt site: respinsă', !$r['ok'] && str_contains($r['mesaj'], 'bioscem'), $r['mesaj']);
$r = $vp($licentaText(4, $pornite(['catalog', 'magazin', 'blog'])));
$verifica('versiune mai veche (4 < 5): respinsă', !$r['ok'] && str_contains($r['mesaj'], 'mai veche'), $r['mesaj']);
$r = $vp($v5);
$verifica('aceeași versiune, exact aceeași licență: acceptată (la zi)', $r['ok'], $r['mesaj']);
$r = $vp($licentaText(5, $pornite(['catalog', 'magazin', 'blog'])));
$verifica('aceeași versiune (5), alt conținut: respinsă', !$r['ok'] && str_contains($r['mesaj'], 'aceeași versiune'), $r['mesaj']);
// Formatul nu are dată de expirare: un câmp semnat necunoscut (de exemplu „expira_la” trecută) nu schimbă nimic,
// exact ca la Licenta::verifica pentru un fișier urcat de mână.
$cuCamp = $licentaText(6, $pornite(['catalog']), ['expira_la' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]);
$r = $vp($cuCamp);
$verifica('fără expirare în format: un câmp „expira_la” trecut e ignorat, ca la mână', $r['ok'] && Licenta::verifica($cuCamp)['ok'], $r['mesaj']);
$verifica('JSON stricat: respins', !$vp('{"site":')['ok']);

$cuConfig = static function (string $url, string $cheie) use ($jurnalSinc): array {
    LicentaSincronizare::configureaza(['url' => $url, 'cheie' => $cheie, 'jurnal' => $jurnalSinc], 2);
    return LicentaSincronizare::ruleaza('test');
};
$raspunde($licentaText(6, $pornite(['catalog', 'magazin', 'blog'])));
$pornesteCrm();
$inainte = count($cereri());
$r = $cuConfig('http://crm.andaxi.ro/api/site/licenta', $cheieSite);
$verifica('http:// spre altă mașină: refuzat înainte de orice cerere', $r['rezultat'] === LicentaSincronizare::NECONFIGURAT
    && str_contains($r['mesaj'], 'https'), $r['mesaj']);
$r = $cuConfig('https://' . $cheieSite . ':x@crm.andaxi.ro/api/site/licenta', $cheieSite);
$verifica('adresă cu utilizator și parolă: refuzată (și fără cheie în mesaj)', $r['rezultat'] === LicentaSincronizare::NECONFIGURAT
    && !str_contains($r['mesaj'], $cheieSite), $r['mesaj']);
$r = $cuConfig($urlCrm, '');
$verifica('fără LICENTA_CHEIE: neconfigurat', $r['rezultat'] === LicentaSincronizare::NECONFIGURAT && str_contains($r['mesaj'], 'LICENTA_CHEIE'), $r['mesaj']);
$r = $cuConfig('', '');
$verifica('fără niciuna: neconfigurat', $r['rezultat'] === LicentaSincronizare::NECONFIGURAT && !LicentaSincronizare::configurata(), $r['mesaj']);
$r = $cuConfig('ftp://127.0.0.1/x', $cheieSite);
$verifica('altă schemă (ftp://): refuzată', $r['rezultat'] === LicentaSincronizare::NECONFIGURAT, $r['mesaj']);
$verifica('nicio cerere la CRM pentru o configurare refuzată', count($cereri()) === $inainte);
$verifica('licența neatinsă de refuzuri (v5)', @file_get_contents($fisier) === $v5);

// =====================================================================
// B. Cu CRM-ul simulat
// =====================================================================
$scrie('');
$scrie('== B. Preluarea de la CRM-ul simulat');
$configureaza($dosar);
@unlink($dosar . '/' . LicentaSincronizare::FISIER_STARE);
@unlink($jurnalSinc);

// B1. O licență nouă, cu blogul pornit în CRM.
$raspunde($v6);
$inainte = count($cereri());
$r = LicentaSincronizare::ruleaza('test');
$verifica('licența mai nouă (v6, cu blogul) e primită', $r['rezultat'] === LicentaSincronizare::OK && $r['versiune'] === 6, json_encode($r, JSON_UNESCAPED_UNICODE));
$verifica('licenta.json = exact răspunsul CRM-ului', @file_get_contents($fisier) === $v6);
$verifica('licenta.json are drepturile 0660, ca o licență pusă de mână', $mod($fisier) === '0660', $mod($fisier));
$verifica('niciun fișier temporar rămas', $temporare($dosar) === [], implode(', ', $temporare($dosar)));
Plugins::reseteaza();
$verifica('blogul e pornit în stările site-ului', Plugins::stare('blog') === Plugins::PORNIT);
$stareAplicata = json_decode((string) @file_get_contents($dosar . '/' . Plugins::FISIER_STARE), true);
$verifica('stare-aplicata.json are licența v6 și blogul', (int) ($stareAplicata['licenta']['versiune'] ?? 0) === 6
    && ($stareAplicata['efective']['blog'] ?? '') === Plugins::PORNIT);
$verifica('licenta-buna.json = v6', @file_get_contents($dosar . '/' . Licenta::FISIER_BUN) === $v6);
$jurnalPluginuri = (string) @file_get_contents($dosarTest . '/pluginuri.log');
$verifica('jurnalul pluginurilor notează schimbarea de stări (ca la un fișier urcat de mână)',
    str_contains($jurnalPluginuri, 'Stările pluginurilor s-au schimbat (licența biovitality v6)'), $jurnalPluginuri);
$toateCererile = $cereri();
$ultima = end($toateCererile) ?: [];
$verifica('o singură cerere GET la /api/site/licenta, cu cheia în Authorization: Bearer', count($toateCererile) === $inainte + 1
    && ($ultima['metoda'] ?? '') === 'GET' && ($ultima['auth'] ?? '') === 'Bearer ' . $cheieSite
    && !str_contains((string) ($ultima['cale'] ?? ''), $cheieSite), json_encode($ultima));

// La fel ca un fișier urcat de mână: același fișier pus de mână într-un al doilea dosar dă aceleași stări.
$stariSinc = Plugins::stari();
$amprentaSinc = Plugins::amprenta();
$configureaza($dosarTest . '/manual');
Licenta::instaleaza($v5);
Plugins::reseteaza();
Plugins::stari();
copy($fisier, $dosarTest . '/manual/' . Licenta::FISIER); // „urcat de mână”
Licenta::reseteaza();
Plugins::reseteaza();
$verifica('aceleași stări și aceeași amprentă ca fișierul urcat de mână', Plugins::stari() === $stariSinc && Plugins::amprenta() === $amprentaSinc);
$aplicataManual = json_decode((string) @file_get_contents($dosarTest . '/manual/' . Plugins::FISIER_STARE), true);
$verifica('aceeași stare-aplicata.json (stări, licență) ca la mână', ($aplicataManual['efective'] ?? null) === ($stareAplicata['efective'] ?? 1)
    && ($aplicataManual['licenta'] ?? null) == ($stareAplicata['licenta'] ?? 1) && ($aplicataManual['stari'] ?? '') === ($stareAplicata['stari'] ?? 'x'));
$configureaza($dosar);

// B2. A doua oară: la zi, nimic scris.
clearstatcache();
$mtime = filemtime($fisier);
$inod = fileinode($fisier);
sleep(1);
$r = LicentaSincronizare::ruleaza('test');
clearstatcache();
$verifica('a doua oară: „la zi”, fișierul nu e rescris', $r['rezultat'] === LicentaSincronizare::LA_ZI && filemtime($fisier) === $mtime
    && fileinode($fisier) === $inod, json_encode($r, JSON_UNESCAPED_UNICODE));

// B3. Respingerile: licența rămâne v6.
$respinsa = static function (string $eticheta, string $rezultatAsteptat, ?string $text = null) use ($fisier, $v6, $verifica): void {
    $r = LicentaSincronizare::ruleaza('test');
    Plugins::reseteaza();
    $verifica($eticheta . ' → ' . $rezultatAsteptat . ', licența rămâne v6', $r['rezultat'] === $rezultatAsteptat && @file_get_contents($fisier) === $v6
        && Plugins::stare('blog') === Plugins::PORNIT && ($text === null || str_contains($r['mesaj'], $text)), json_encode($r, JSON_UNESCAPED_UNICODE));
};
$raspunde($stricata);
$respinsa('semnătură greșită', LicentaSincronizare::RESPINSA, 'semnătura');
$raspunde($licentaText(7, $pornite(['catalog']), [], $privataStraina));
$respinsa('altă cheie', LicentaSincronizare::RESPINSA, 'semnătura');
$raspunde($licentaText(7, $pornite(['catalog']), [], null, 'bioscem'));
$respinsa('alt site', LicentaSincronizare::RESPINSA, 'bioscem');
$raspunde($v5);
$respinsa('versiune mai veche (v5, reluată)', LicentaSincronizare::RESPINSA, 'mai veche');
$raspunde($licentaText(6, $pornite(['catalog'])));
$respinsa('aceeași versiune, alt conținut', LicentaSincronizare::RESPINSA, 'aceeași versiune');
$raspunde($v6, 200, '', 'alta-cheie');
$respinsa('HTTP 401 (cheia site-ului greșită)', LicentaSincronizare::EROARE, '401');
$raspunde('{"error":"x"}', 404);
$respinsa('HTTP 404', LicentaSincronizare::EROARE, '404');
$raspunde('<html>eroare</html>', 500);
$respinsa('HTTP 500', LicentaSincronizare::EROARE, '500');
$raspunde('<html>nu e JSON</html>');
$respinsa('200 cu un corp care nu e JSON', LicentaSincronizare::RESPINSA, 'JSON');
$raspunde(str_repeat(' ', LicentaSincronizare::MARIME_MAXIMA + 10) . $v6);
$respinsa('răspuns prea mare', LicentaSincronizare::EROARE, 'octeți');
$inainte = count($cereri());
$raspunde($v6, 302, 'http://127.0.0.1:9/api/site/licenta');
$respinsa('o redirecționare nu se urmează', LicentaSincronizare::EROARE, '302');
$verifica('după redirecționare, nicio a doua cerere', count($cereri()) === $inainte + 1);

// B4. Oprirea unui plugin din CRM: tot o licență nouă.
$raspunde($licentaText(7, $pornite(['catalog', 'magazin'])));
$r = LicentaSincronizare::ruleaza('test');
Plugins::reseteaza();
$verifica('blogul oprit din CRM (v7): licența nouă, blogul oprit', $r['rezultat'] === LicentaSincronizare::OK && Plugins::stare('blog') === Plugins::OPRIT
    && Plugins::stare('magazin') === Plugins::PORNIT, json_encode($r, JSON_UNESCAPED_UNICODE));
$v7 = (string) file_get_contents($fisier);

// B5. CRM-ul căzut.
$opreste('crm');
@unlink($jurnalSinc);
$r1 = LicentaSincronizare::ruleaza('cron');
$r2 = LicentaSincronizare::ruleaza('cron');
Plugins::reseteaza();
$verifica('CRM-ul oprit: eroare, licența rămâne v7, stările la fel', $r1['rezultat'] === LicentaSincronizare::EROARE
    && str_contains($r1['mesaj'], 'nu răspunde') && @file_get_contents($fisier) === $v7 && Plugins::stare('magazin') === Plugins::PORNIT,
    json_encode($r1, JSON_UNESCAPED_UNICODE));
$stare = LicentaSincronizare::ultimaStare();
$verifica('fișierul de stare: eroarea, versiunea de acum (7), ultima reușită păstrată', ($stare['rezultat'] ?? '') === LicentaSincronizare::EROARE
    && ($stare['versiune_curenta'] ?? 0) === 7 && is_string($stare['ultima_reusita'] ?? null), json_encode($stare, JSON_UNESCAPED_UNICODE));
$verifica('fișierul de stare are drepturile 0660', $mod($dosar . '/' . LicentaSincronizare::FISIER_STARE) === '0660');
$jurnal = (string) @file_get_contents($jurnalSinc);
$verifica('jurnalul: eșecul o singură dată (a doua rulare cu același motiv nu se mai scrie)', substr_count($jurnal, 'nu răspunde') === 1, $jurnal);
$tot = $jurnal . (string) @file_get_contents($dosar . '/' . LicentaSincronizare::FISIER_STARE) . (string) @file_get_contents($dosarTest . '/pluginuri.log');
$verifica('cheia site-ului nu apare în jurnal și în fișierul de stare', !str_contains($tot, $cheieSite) && !str_contains($tot, explode('.', $cheieSite)[1]));
$pornesteCrm();

// =====================================================================
// C. Scrierea atomică
// =====================================================================
$scrie('');
$scrie('== C. Scrierea atomică: un cititor în buclă nu vede niciodată o licență pe jumătate');
$cititor = $dosarTest . '/cititor.php';
file_put_contents($cititor, '<?php
require ' . var_export($radacina . '/bootstrap.php', true) . ';
App\Support\Licenta::configureaza(["dosar" => $argv[1], "cheie_publica" => $argv[2], "site" => $argv[3]]);
$pana = microtime(true) + (float) $argv[4];
$citiri = 0; $rele = 0; $versiuni = [];
while (microtime(true) < $pana && !is_file($argv[1] . "/../gata")) {
    $text = @file_get_contents($argv[1] . "/licenta.json");
    $citiri++;
    $v = $text === false ? ["ok" => false] : App\Support\Licenta::verifica($text);
    if (!$v["ok"]) { $rele++; continue; }
    $versiuni[$v["licenta"]["versiune"]] = true;
}
echo json_encode(["citiri" => $citiri, "rele" => $rele, "versiuni" => count($versiuni)]);
');
@unlink($dosarTest . '/gata');
$procCititor = proc_open([PHP_BINARY, $cititor, $dosar, $publica, $site, '30'], [1 => ['pipe', 'w'], 2 => ['file', $dosarTest . '/cititor.err', 'a']], $tevi);
usleep(300000);
$puse = 0;
for ($v = 8; $v < 38; $v++) {
    // Liste de mărimi diferite, ca un fișier pe jumătate să nu poată trece drept unul întreg.
    $raspunde($licentaText($v, $pornite(array_slice($toate, 0, 2 + ($v % 20)))));
    $puse += LicentaSincronizare::ruleaza('test')['rezultat'] === LicentaSincronizare::OK ? 1 : 0;
}
touch($dosarTest . '/gata');
$iesireCititor = json_decode((string) stream_get_contents($tevi[1]), true);
proc_close($procCititor);
$verifica('30 de licențe puse la rând', $puse === 30, (string) $puse);
$verifica('cititorul: nicio citire pe jumătate sau nesemnată', is_array($iesireCititor) && $iesireCititor['citiri'] > 100 && $iesireCititor['rele'] === 0
    && $iesireCititor['versiuni'] > 1, json_encode($iesireCititor));
$verifica('niciun fișier temporar rămas', $temporare($dosar) === []);

// =====================================================================
// D. Cronul prin bin/platforma
// =====================================================================
$scrie('');
$scrie('== D. Cronul prin bin/platforma, pe o găzduire simulată');
$acasa = $dosarTest . '/acasa';
$versiuneCod = '2026.11.09-abcdef0';
$release = $acasa . '/platforma/releases/' . $versiuneCod;
mkdir($release, 0770, true);
exec('cd ' . escapeshellarg($radacina) . ' && tar -cf - --exclude=./.git --exclude=./storage --exclude=./node_modules . | tar -xf - -C '
    . escapeshellarg($release), $_, $codCopie);
mkdir($release . '/storage', 0770, true);
file_put_contents($release . '/VERSION', $versiuneCod . "\n");
mkdir($acasa . '/platforma/bin', 0770, true);
copy($radacina . '/bin/platforma', $acasa . '/platforma/bin/platforma');
$dosarSite = $acasa . '/domains/exemplu.ro/site';
mkdir($dosarSite . '/storage/licenta', 0770, true);
mkdir($dosarSite . '/storage/logs', 0770, true);
mkdir($acasa . '/domains/exemplu.ro/public_html', 0770, true);
file_put_contents($dosarSite . '/RELEASE', $versiuneCod . "\n");
$scrieEnv = static function (string $url, string $cheie) use ($dosarSite, $publica, $site): void {
    file_put_contents($dosarSite . '/.env', "APP_ENV=production\nLICENTA_CHEIE_PUBLICA=$publica\nLICENTA_SITE=$site\nLICENTA_DOSAR=\n"
        . "LICENTA_URL=$url\nLICENTA_CHEIE=$cheie\n");
    chmod($dosarSite . '/.env', 0600);
};
$scrieEnv($urlCrm, $cheieSite);
$cron = static function () use ($acasa, $dosarSite): array {
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($acasa . '/platforma/bin/platforma') . ' --site='
        . escapeshellarg($dosarSite) . ' scripts/licenta-sincronizare.php 2>&1', $iesire, $cod);
    return ['cod' => $cod, 'iesire' => implode("\n", $iesire)];
};
$vSite = $licentaText(3, $pornite(['catalog', 'magazin', 'campanii']));
$raspunde($vSite);
$r = $cron();
$verifica('copia codului pentru versiunea simulată', $codCopie === 0 && is_file($release . '/scripts/licenta-sincronizare.php'));
$verifica('cronul: 0, licența în site/storage/licenta/licenta.json (0660)', $r['cod'] === 0 && @file_get_contents($dosarSite . '/storage/licenta/licenta.json') === $vSite
    && $mod($dosarSite . '/storage/licenta/licenta.json') === '0660', $r['iesire']);
$aplicataSite = json_decode((string) @file_get_contents($dosarSite . '/storage/licenta/' . Plugins::FISIER_STARE), true);
$verifica('stările aplicate pe loc în site/storage/licenta (campanii pornit)', ($aplicataSite['efective']['campanii'] ?? '') === Plugins::PORNIT);
$r = $cron();
$verifica('a doua oară: 0, „la zi”', $r['cod'] === 0 && str_contains($r['iesire'], 'la_zi'), $r['iesire']);
$raspunde($licentaText(2, $pornite(['catalog'])));
$r = $cron();
$verifica('o licență mai veche: 1, licența rămâne', $r['cod'] === 1 && @file_get_contents($dosarSite . '/storage/licenta/licenta.json') === $vSite, $r['iesire']);
$scrieEnv('', '');
$r = $cron();
$verifica('fără LICENTA_URL/LICENTA_CHEIE: 2', $r['cod'] === 2, $r['iesire']);
$scrieEnv($urlCrm, $cheieSite);
$jurnalSite = (string) @file_get_contents($dosarSite . '/storage/logs/' . LicentaSincronizare::FISIER_JURNAL);
$verifica('jurnalul site-ului (site/storage/logs/licenta-sincronizare.log), fără cheie', str_contains($jurnalSite, 'cron ok')
    && !str_contains($jurnalSite, $cheieSite), $jurnalSite);
$verifica('nimic scris în copia codului (versiunea nu se schimbă)', (glob($release . '/storage/*') ?: []) === []);

// =====================================================================
// E. „Verifică acum” din admin
// =====================================================================
$scrie('');
$scrie('== E. „Verifică acum” (Admin → Abonamentul tău), pe un server al testului');
$verifica('doar administratorul general are adresa (Auth)', Auth::canAccessPath('/admin/abonament/verifica', Auth::ROLE_GENERAL)
    && !Auth::canAccessPath('/admin/abonament/verifica', Auth::ROLE_STORE) && !Auth::canAccessPath('/admin/abonament/verifica', Auth::ROLE_BLOG)
    && !Auth::canAccessPath('/admin/abonament/verifica', Auth::ROLE_EMAILS) && !Auth::canAccessPath('/admin/abonament/verifica', Auth::ROLE_CLOUD));

$dosarAdmin = $dosarTest . '/site-licenta';
mkdir($dosarAdmin, 0770, true);
// Licența serverului: site-ul „demo” al bazei de dezvoltare, toate pluginurile fără blog, semnată de „CRM-ul” testului.
$fara = array_values(array_diff($toate, ['blog']));
Licenta::configureaza(['dosar' => $dosarAdmin, 'cheie_publica' => $publica, 'site' => 'demo']);
Licenta::instaleaza($licentaText(1, $pornite($fara), [], null, 'demo'));
$phpDev = [PHP_BINARY];
foreach (['pdo_mysql.default_socket', 'sendmail_path', 'memory_limit', 'error_reporting'] as $ini) {
    if ((string) ini_get($ini) !== '') {
        array_push($phpDev, '-d', $ini . '=' . ini_get($ini));
    }
}
array_push($phpDev, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $dosarTest . '/php-erori.log');
$pornesteSite = static function (bool $cuPreluare) use ($phpDev, $portSite, $radacina, $dosarTest, $dosarAdmin, $publica, $urlCrm, $cheieSite, &$procese): void {
    putenv('LICENTA_DOSAR=' . $dosarAdmin);
    putenv('LICENTA_CHEIE_PUBLICA=' . $publica);
    putenv('LICENTA_SITE=demo');
    putenv('LICENTA_URL=' . ($cuPreluare ? $urlCrm : ''));
    putenv('LICENTA_CHEIE=' . ($cuPreluare ? $cheieSite : ''));
    putenv('APP_URL=http://127.0.0.1:' . $portSite);
    $procese['site'] = proc_open(array_merge($phpDev, ['-S', '127.0.0.1:' . $portSite, '-t', $radacina . '/public', $radacina . '/tools/dev/router.php']),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dosarTest . '/site-server.log', 'a'], 2 => ['file', $dosarTest . '/site-server.log', 'a']], $_, $radacina);
    foreach (['LICENTA_DOSAR', 'LICENTA_URL', 'LICENTA_CHEIE'] as $nume) {
        putenv($nume);
    }
    for ($i = 0; $i < 60; $i++) {
        usleep(200000);
        $c = curl_init('http://127.0.0.1:' . $portSite . '/health');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 2]);
        curl_exec($c);
        $gata = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($c);
        if ($gata) {
            return;
        }
    }
};
$cerere = static function (string $metoda, string $cale, string $jar, ?array $date = null) use ($portSite): array {
    $c = curl_init('http://127.0.0.1:' . $portSite . $cale);
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    if ($date !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    $tot = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $marime = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    curl_close($c);
    $antete = substr($tot, 0, $marime);
    return ['cod' => $cod, 'locatie' => preg_match('/^Location:\s*(\S+)/mi', $antete, $m) === 1 ? $m[1] : '', 'corp' => substr($tot, $marime)];
};
$parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$logare = static function (string $email) use ($cerere, $parola, $dosarTest): string {
    $jar = $dosarTest . '/jar-' . md5($email) . '.txt';
    @unlink($jar);
    $cerere('GET', '/admin/login', $jar);
    $cerere('POST', '/admin/login', $jar, ['email' => $email, 'password' => $parola]);
    return $jar;
};
$jeton = static fn (string $html): string => preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');

// E1. Fără preluare configurată: pagina e cea de până acum (fără panou, fără buton).
$pornesteSite(false);
$jar = $logare($adminEmail);
$pagina = $cerere('GET', '/admin/abonament', $jar);
$verifica('fără preluare configurată: pagina fără panou și fără buton', $pagina['cod'] === 200 && str_contains($pagina['corp'], 'Abonamentul tău')
    && !str_contains($pagina['corp'], 'abonament-sincronizare') && !str_contains($pagina['corp'], '/admin/abonament/verifica'), (string) $pagina['cod']);
$opreste('site');

// E2. Cu preluarea configurată.
$pornesteSite(true);
$jar = $logare($adminEmail);
$pagina = $cerere('GET', '/admin/abonament', $jar);
$verifica('panoul „Licența de la ANDAXI” și butonul „Verifică acum”, cu jetonul formularului', $pagina['cod'] === 200
    && str_contains($pagina['corp'], 'abonament-sincronizare') && str_contains($pagina['corp'], 'action="/admin/abonament/verifica"')
    && $jeton($pagina['corp']) !== '' && !str_contains($pagina['corp'], $cheieSite), (string) $pagina['cod']);
$blogInainte = $cerere('GET', '/admin/blog/posts', $jar);
$verifica('blogul nu e în abonament înainte și nu se deschide în admin (403)', !str_contains($pagina['corp'], 'data-modul="blog"')
    && $blogInainte['cod'] === 403, (string) $blogInainte['cod']);

$raspunde($licentaText(2, $pornite($toate), [], null, 'demo'));
$inainte = count($cereri());
$r = $cerere('POST', '/admin/abonament/verifica', $jar, ['_csrf' => 'gresit']);
$verifica('POST cu jeton greșit: refuzat, nicio cerere la CRM, licența neschimbată', $r['cod'] === 302 && $r['locatie'] === '/admin/abonament'
    && count($cereri()) === $inainte && str_contains((string) @file_get_contents($dosarAdmin . '/licenta.json'), '"versiune": 1'), $r['locatie']);
$r = $cerere('POST', '/admin/abonament/verifica', $jar, []);
$verifica('POST fără jeton: refuzat, nicio cerere la CRM', $r['cod'] === 302 && count($cereri()) === $inainte);
$pagina = $cerere('GET', '/admin/abonament', $jar);
$verifica('mesajul „Sesiunea formularului a expirat”', str_contains($pagina['corp'], 'Sesiunea formularului a expirat'));

$r = $cerere('POST', '/admin/abonament/verifica', $jar, ['_csrf' => $jeton($pagina['corp'])]);
$pagina = $cerere('GET', '/admin/abonament', $jar);
$verifica('POST cu jeton: o cerere la CRM, licența v2 aplicată, blogul în abonament', $r['cod'] === 302 && $r['locatie'] === '/admin/abonament'
    && count($cereri()) === $inainte + 1 && str_contains((string) @file_get_contents($dosarAdmin . '/licenta.json'), '"versiune": 2')
    && str_contains($pagina['corp'], 'data-modul="blog"') && str_contains($pagina['corp'], 'Licența nouă (v2) a fost primită și aplicată.'),
    mb_substr(strip_tags($pagina['corp']), 0, 300));
$verifica('panoul arată ultima verificare reușită (v2)', str_contains($pagina['corp'], 'data-sincronizare="ok"') && str_contains($pagina['corp'], '(versiunea 2)'));
$blog = $cerere('GET', '/admin/blog/posts', $jar);
$verifica('blogul se deschide în admin (nu mai e „Modulul nu este inclus în abonament”)', $blog['cod'] === 200, (string) $blog['cod']);

$opreste('crm');
$r = $cerere('POST', '/admin/abonament/verifica', $jar, ['_csrf' => $jeton($pagina['corp'])]);
$pagina = $cerere('GET', '/admin/abonament', $jar);
$verifica('CRM-ul oprit: mesajul, motivul în panou, site-ul merge cu licența v2', $r['cod'] === 302 && str_contains($pagina['corp'], 'nu a putut fi actualizată')
    && str_contains($pagina['corp'], 'data-sincronizare="eroare"') && str_contains($pagina['corp'], 'versiunea 2')
    && str_contains($pagina['corp'], 'data-modul="blog"'), mb_substr(strip_tags($pagina['corp']), 0, 300));
$acasaSite = $cerere('GET', '/', $jar);
$verifica('prima pagină a site-ului răspunde cu CRM-ul oprit', $acasaSite['cod'] === 200, (string) $acasaSite['cod']);
$pornesteCrm();

// E3. Administratorul de magazin.
$jarMagazin = $logare('magazin@exemplu.ro');
$inainte = count($cereri());
$paginaMagazin = $cerere('GET', '/admin/abonament', $jarMagazin);
$jetonMagazin = $jeton($cerere('GET', '/admin/products', $jarMagazin)['corp']);
$r = $cerere('POST', '/admin/abonament/verifica', $jarMagazin, ['_csrf' => $jetonMagazin !== '' ? $jetonMagazin : 'x']);
$verifica('administratorul de magazin: nici pagina, nici butonul (redirecționat, nicio cerere la CRM)', $paginaMagazin['cod'] === 302
    && $r['cod'] === 302 && $r['locatie'] !== '/admin/abonament' && count($cereri()) === $inainte, $paginaMagazin['locatie'] . ' / ' . $r['locatie']);
$faraLogare = $cerere('POST', '/admin/abonament/verifica', $dosarTest . '/jar-gol.txt', ['_csrf' => 'x']);
$verifica('fără logare: la /admin/login', $faraLogare['cod'] === 302 && $faraLogare['locatie'] === '/admin/login');
$opreste('site');
$opreste('crm');

$erori = (string) @file_get_contents($dosarTest . '/php-erori.log');
$verifica('jurnalul PHP al serverului testului fără avertismente', !preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $erori), $erori);

Licenta::reseteaza(true);
Plugins::folosesteJurnal(null);
Plugins::reseteaza();
LicentaSincronizare::configureaza(null);

$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
