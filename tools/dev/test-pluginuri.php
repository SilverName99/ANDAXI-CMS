<?php

declare(strict_types=1);

/**
 * Testele mecanismului de pluginuri (fără browser).
 *
 *   tools/dev/php.sh tools/dev/test-pluginuri.php            # tot: A, B, C, D, E
 *   tools/dev/php.sh tools/dev/test-pluginuri.php --doar-licenta
 *   … --fara-garda     # fără B (cererea fiecărei rute, cea mai lungă parte)
 *   … --fara-browser   # C fără Playwright
 *   … --fara-baza      # fără E (comparația cu codul de bază)
 *   … --capturi=DIR    # unde se pun capturile meniului adminului (implicit $CMS_DEV_DIR/capturi)
 *
 * A. Licența (fără server, într-un dosar temporar, cu o pereche de chei nouă):
 *    bună, semnătură greșită/lipsă/altă cheie, versiune mai veche, lipsă, chei și
 *    stări necunoscute, alt site, JSON stricat, ultima bună stricată, regulile de
 *    dependență, jurnalul „o singură dată”, vectorul de test pentru CRM.
 * B. Garda rutelor (cu serverul de dezvoltare, pe baza de test cu date demo):
 *    pentru fiecare pachet (toate, magazin, catalog, prezentare, asociatie), fără
 *    licență, cu magazinul „se oprește” și cu o redirecționare, cere FIECARE rută
 *    din router și verifică: rutele pluginurilor vizibile ajung la controller;
 *    celelalte dau 403 „Modulul nu este inclus în abonament” (admin, logat), login
 *    (admin, nelogat) sau 404 (site); adresele de confirmare răspund mereu
 *    (400/401/403, niciodată 404/500). Plus meniul, dashboard-ul, cronurile,
 *    checkout-ul închis și plățile noi la „se oprește”.
 * C. Fiecare pachet (toate, magazin, catalog, prezentare, asociatie) parcurs din
 *    afară: site-ul pornind de la prima pagină și din harta site-ului (refăcută
 *    singură la schimbarea licenței), contul clientului, fiecare pagină din meniul
 *    adminului. Nicio pagină cu 500 sau eroare PHP (nici în jurnal), niciun link,
 *    formular sau semn (coș, oferte, recenzii, chat…) al unui plugin ascuns, nicio
 *    adresă ascunsă în harta site-ului. Apoi aceleași pagini cu Playwright: DOM-ul
 *    după JavaScript, erorile JS noi față de „toate” și captura meniului adminului.
 * D. Magazinul „se oprește”: checkout închis, coșul refuză produse noi, o comandă
 *    existentă se editează în admin, notificarea Stripe (semnată cu un secret de
 *    test) o marchează plătită și emailul ei pleacă; ERP-ul „se oprește” ține
 *    comenzile noi, „oprit” nu mai vorbește deloc; cronurile care termină ce e în
 *    curs rulează, cele care pornesc ceva nou tac.
 * E. Cu licența „toate”, paginile-cheie (prima pagină, produs, coș, checkout,
 *    dashboard, comenzi…) au HTML identic cu codul de bază (compara-cu-baza.sh).
 *
 * Cererile POST de admin se fac nelogat (garda sau loginul răspund înainte de
 * controller), ca testul să nu schimbe setări sau comenzi. La final pune la loc
 * licența „toate” și oprește serverul, dacă l-a pornit el.
 * Raportul: $CMS_DEV_DIR/test-pluginuri-raport.txt; cod de ieșire 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/bootstrap.php';

use App\Http\Controllers\SiteController;
use App\Http\Router;
use App\Support\Database;
use App\Support\Licenta;
use App\Support\PaymentLink;
use App\Support\Plugins;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-pluginuri-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . $detalii : ''));
    }
};
$contine = static fn (array $lista, string $text): bool => array_filter($lista, static fn ($m): bool => str_contains((string) $m, $text)) !== [];

$scrie('Test pluginuri ANDAXI-CMS — ' . date('Y-m-d H:i:s'));
$scrie('');

// =====================================================================
// A. Licența
// =====================================================================
$scrie('== A. Licența (dosar temporar, chei noi)');
$tmp = $dosarDev . '/test-licenta-' . getmypid();
@mkdir($tmp, 0770, true);
$pereche = sodium_crypto_sign_keypair();
$privata = sodium_crypto_sign_secretkey($pereche);
$publica = base64_encode(sodium_crypto_sign_publickey($pereche));
$alta = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
Licenta::configureaza(['dosar' => $tmp, 'cheie_publica' => $publica, 'site' => 'test']);
Plugins::folosesteJurnal($tmp . '/jurnal.log');

$licenta = static function (array $pluginuri, int $versiune = 1, string $site = 'test', array $extra = []): array {
    return array_merge(['site' => $site, 'emisa_la' => '2026-10-18T10:00:00Z', 'versiune' => $versiune, 'pluginuri' => $pluginuri], $extra);
};
$scrieFisier = static function (string $nume, string $continut) use ($tmp): void {
    file_put_contents($tmp . '/' . $nume, $continut);
};
$reincarca = static function (): void {
    Plugins::reseteaza();
    Plugins::stari();
};
$toate = array_fill_keys(Plugins::chei(), 'pornit');

// 1. Nicio licență.
$reincarca();
$verifica('fără licență: „Licența lipsește”, doar nucleul', Plugins::licentaLipseste()
    && array_unique(array_values(Plugins::stari())) === ['oprit'] && Plugins::vizibil('nucleu') && Plugins::activ('nucleu'));
$verifica('fără licență: motivul e în mesaje', $contine(Plugins::mesaje(), 'lipsește'));

// 2. Licență bună.
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta($toate), $privata));
$reincarca();
$verifica('licență bună: toate pornite', !Plugins::licentaLipseste() && array_unique(array_values(Plugins::stari())) === ['pornit']);
$verifica('licență bună: copiată ca ultima bună', is_file($tmp . '/' . Licenta::FISIER_BUN)
    && file_get_contents($tmp . '/' . Licenta::FISIER_BUN) === file_get_contents($tmp . '/' . Licenta::FISIER));

// 3. Semnătură greșită: conținut modificat după semnare (se păstrează v1).
$falsa = json_decode(Licenta::semneaza($licenta(['catalog' => 'pornit'], 2), $privata), true);
$falsa['pluginuri']['magazin'] = 'pornit';
$scrieFisier(Licenta::FISIER, (string) json_encode($falsa));
$reincarca();
$verifica('semnătură greșită: ignorată, rămâne ultima bună (v1)', (Plugins::licenta()['versiune'] ?? 0) === 1 && Plugins::activ('blog')
    && $contine(Plugins::mesaje(), 'semnătura nu se potrivește'));

// 4. Semnătură lipsă.
unset($falsa['semnatura']);
$scrieFisier(Licenta::FISIER, (string) json_encode($falsa));
$reincarca();
$verifica('semnătură lipsă: ignorată, rămâne ultima bună', (Plugins::licenta()['versiune'] ?? 0) === 1 && $contine(Plugins::mesaje(), 'lipsește semnătura'));

// 5. Semnată cu altă cheie.
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta(['catalog' => 'pornit'], 3), $alta));
$reincarca();
$verifica('semnată cu altă cheie: ignorată', (Plugins::licenta()['versiune'] ?? 0) === 1 && Plugins::activ('blog'));

// 6. Versiune mai veche decât ultima bună.
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta(['catalog' => 'pornit'], 5), $privata));
$reincarca();
$verifica('v5 bună: devine ultima bună', (Plugins::licenta()['versiune'] ?? 0) === 5 && Plugins::activ('catalog') && !Plugins::vizibil('blog'));
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta($toate, 4), $privata));
$reincarca();
$verifica('v4 după v5: ignorată (nu se poate da înapoi)', (Plugins::licenta()['versiune'] ?? 0) === 5 && !Plugins::vizibil('blog')
    && $contine(Plugins::mesaje(), 'mai veche'));
$instalare = Licenta::instaleaza(Licenta::semneaza($licenta($toate, 4), $privata));
$verifica('instaleaza() refuză o versiune mai veche', !$instalare['ok'] && str_contains($instalare['eroare'], 'mai veche'));
$instalare = Licenta::instaleaza((string) json_encode($falsa));
$verifica('instaleaza() refuză o licență nesemnată (și nu scrie nimic)', !$instalare['ok']
    && str_contains((string) file_get_contents($tmp . '/' . Licenta::FISIER), '"versiune": 4'));

// 7. Aceeași versiune, alt conținut: acceptată (versiunea nu scade).
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta(['catalog' => 'pornit', 'blog' => 'pornit'], 5), $privata));
$reincarca();
$verifica('aceeași versiune, alt conținut: acceptată', Plugins::activ('blog'));

// 8–10. Chei necunoscute, stări necunoscute, chei lipsă.
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta(['catalog' => 'pornit', 'plugin_din_viitor' => 'pornit', 'blog' => 'pornita'], 6), $privata));
$reincarca();
$verifica('cheie necunoscută: ignorată, restul se aplică', Plugins::activ('catalog') && $contine(Plugins::mesaje(), 'plugin_din_viitor'));
$verifica('stare necunoscută („pornita”): tratat ca oprit', Plugins::stare('blog') === 'oprit' && $contine(Plugins::mesaje(), 'pornita'));
$verifica('cheie lipsă din licență: oprit', Plugins::stare('newsletter') === 'oprit');
$verifica('cheie întrebată de cod, inexistentă în catalog: oprit', Plugins::stare('nu_exista') === 'oprit' && !Plugins::vizibil('nu_exista'));

// 11. Alt site, JSON stricat, fără cheie publică.
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta($toate, 9, 'alt-site'), $privata));
$reincarca();
$verifica('licență pentru alt site: ignorată', (Plugins::licenta()['versiune'] ?? 0) === 6 && $contine(Plugins::mesaje(), 'alt-site'));
$scrieFisier(Licenta::FISIER, '{"site":"test",');
$reincarca();
$verifica('JSON stricat: ignorat, rămâne ultima bună', (Plugins::licenta()['versiune'] ?? 0) === 6 && $contine(Plugins::mesaje(), 'JSON'));
@unlink($tmp . '/' . Licenta::FISIER_BUN);
$reincarca();
$verifica('JSON stricat și nicio ultimă bună: „Licența lipsește”', Plugins::licentaLipseste());
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta($toate, 10), $privata));
Licenta::configureaza(['dosar' => $tmp, 'cheie_publica' => '', 'site' => 'test']);
$reincarca();
$verifica('fără LICENTA_CHEIE_PUBLICA: nicio licență acceptată', Plugins::licentaLipseste() && $contine(Plugins::mesaje(), 'LICENTA_CHEIE_PUBLICA'));
Licenta::configureaza(['dosar' => $tmp, 'cheie_publica' => $publica, 'site' => '']);
$reincarca();
$verifica('fără LICENTA_SITE: nicio licență acceptată', Plugins::licentaLipseste() && $contine(Plugins::mesaje(), 'LICENTA_SITE'));
Licenta::configureaza(['dosar' => $tmp, 'cheie_publica' => 'cheie-veche-ignorata-xyz,' . $publica, 'site' => 'test']);
$reincarca();
$verifica('două chei publice (schimbarea cheii CRM): merge cu oricare', !Plugins::licentaLipseste() && Plugins::activ('blog'));
Licenta::configureaza(['dosar' => $tmp, 'cheie_publica' => $publica, 'site' => 'test']);

// 12. Ultima bună stricată, licența curentă bună: se folosește curenta și se reface ultima bună.
$scrieFisier(Licenta::FISIER_BUN, '{"stricat": true}');
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta($toate, 11), $privata));
$reincarca();
$verifica('ultima bună stricată: curenta (v11) se folosește și o înlocuiește', (Plugins::licenta()['versiune'] ?? 0) === 11
    && str_contains((string) file_get_contents($tmp . '/' . Licenta::FISIER_BUN), '"versiune": 11'));

// 13. Ordinea cheilor și spațiile nu contează (JSON canonic), dar orice valoare da.
$compact = json_decode(Licenta::semneaza($licenta(['blog' => 'pornit', 'catalog' => 'se_opreste'], 12), $privata), true);
$inversat = array_reverse($compact, true);
$inversat['pluginuri'] = array_reverse($inversat['pluginuri'], true);
$scrieFisier(Licenta::FISIER, (string) json_encode($inversat, JSON_UNESCAPED_SLASHES));
$reincarca();
$verifica('altă ordine a cheilor, fără spații: semnătura rămâne validă', (Plugins::licenta()['versiune'] ?? 0) === 12
    && Plugins::stare('catalog') === 'se_opreste');

// 14. Regula dependențelor.
$calc = static fn (array $l): array => Plugins::calculeazaStari($l)['stari'];
$s = $calc(['magazin' => 'pornit', 'livrare_fan' => 'pornit', 'cupoane' => 'pornit']);
$verifica('dependență oprită (catalog): magazin, livrare_fan, cupoane → oprit', $s['magazin'] === 'oprit' && $s['livrare_fan'] === 'oprit'
    && $s['cupoane'] === 'oprit');
$s = $calc(['catalog' => 'pornit', 'magazin' => 'se_opreste', 'livrare_fan' => 'pornit', 'cupoane' => 'pornit', 'erp' => 'pornit']);
$verifica('dependență care se oprește: dependenții se opresc și ei (FAN termină AWB-urile)', $s['magazin'] === 'se_opreste'
    && $s['livrare_fan'] === 'se_opreste' && $s['cupoane'] === 'se_opreste' && $s['erp'] === 'se_opreste' && $s['catalog'] === 'pornit');
$s = $calc(['catalog' => 'pornit', 'magazin' => 'pornit', 'link_plata' => 'pornit']);
$verifica('„cel puțin una din”: link de plată fără nicio plată cu cardul → oprit', $s['link_plata'] === 'oprit');
$s = $calc(['catalog' => 'pornit', 'magazin' => 'pornit', 'link_plata' => 'pornit', 'plata_bt' => 'se_opreste']);
$verifica('„cel puțin una din”: singura plată se oprește → linkul se oprește', $s['link_plata'] === 'se_opreste');
$s = $calc(['catalog' => 'pornit', 'magazin' => 'pornit', 'link_plata' => 'pornit', 'plata_bt' => 'se_opreste', 'plata_stripe' => 'pornit']);
$verifica('„cel puțin una din”: altă plată pornită → linkul rămâne pornit', $s['link_plata'] === 'pornit');
$s = $calc(['magazin' => 'pornit', 'catalog' => 'pornit', 'fidelitate' => 'pornit']);
$verifica('dependențe multiple: fidelitate fără conturi de clienți → oprit', $s['fidelitate'] === 'oprit');
$r = Plugins::calculeazaStari(['magazin' => 'pornit']);
$verifica('dependența lipsă e explicată în mesaje', $contine($r['mesaje'], 'magazin') && $contine($r['mesaje'], 'catalog'));
$s = $calc(['donatii' => 'pornit', 'plata_stripe' => 'pornit']);
$verifica('pachetul Asociație: donații cu Stripe, fără magazin → pornit', $s['donatii'] === 'pornit' && $s['magazin'] === 'oprit');

// 15. API: pornit / se oprește / oprit.
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta(['catalog' => 'pornit', 'magazin' => 'se_opreste', 'blog' => 'pornit'], 13), $privata));
$reincarca();
$verifica('se_opreste: activ=nu, permiteInceput=nu, vizibil=da', !Plugins::activ('magazin') && !Plugins::permiteInceput('magazin')
    && Plugins::vizibil('magazin') && Plugins::stare('magazin') === 'se_opreste');
$verifica('pornit: activ=da, permiteInceput=da, vizibil=da', Plugins::activ('blog') && Plugins::permiteInceput('blog') && Plugins::vizibil('blog'));
$verifica('oprit: activ=nu, vizibil=nu', !Plugins::activ('newsletter') && !Plugins::vizibil('newsletter'));
$verifica('cron „pornit” (coș abandonat) nu rulează când magazinul se oprește', !Plugins::cronPermis($radacina . '/scripts/abandoned-cart-emails.php'));
$verifica('cron „vizibil” al unui plugin oprit (ERP) nu rulează', !Plugins::cronPermis('scripts/erp-sync.php'));
$verifica('script al nucleului (install.php) rulează mereu', Plugins::cronPermis('scripts/install.php'));

// 16. Jurnalul: o problemă se scrie o singură dată, nu la fiecare cerere.
$scrieFisier(Licenta::FISIER, '{"stricat"');
@unlink($tmp . '/jurnal.log');
$reincarca();
$reincarca();
$reincarca();
$jurnal = (string) @file_get_contents($tmp . '/jurnal.log');
$verifica('aceeași problemă, trei încărcări: o singură linie în jurnal', substr_count($jurnal, 'licenta.json respinsă') === 1, trim($jurnal));
$scrieFisier(Licenta::FISIER, '{"site": "test"}');
$reincarca();
$jurnal = (string) @file_get_contents($tmp . '/jurnal.log');
$verifica('problemă nouă: scrisă din nou', substr_count($jurnal, 'licenta.json respinsă') === 2);

// 17. Redirecționările: doar căi din același site.
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta(['blog' => 'pornit'], 20, 'test', ['redirectionari' => [
    'magazin' => '/', 'catalog' => 'https://alt-site.ro', 'cupoane' => '//alt-site.ro', 'recenzii' => '/contact',
]]), $privata));
$reincarca();
$verifica('redirecționări: „/” și „/contact” păstrate, adresele externe aruncate', Plugins::redirectionare('magazin') === '/'
    && Plugins::redirectionare('recenzii') === '/contact' && Plugins::redirectionare('catalog') === null && Plugins::redirectionare('cupoane') === null);

// 18. Vectorul de test pentru CRM (aceeași semnătură trebuie să iasă și din Node).
$seed = str_repeat("\x01", SODIUM_CRYPTO_SIGN_SEEDBYTES);
$perecheFixa = sodium_crypto_sign_seed_keypair($seed);
$vector = ['site' => 'exemplu', 'emisa_la' => '2026-10-18T09:15:02Z', 'versiune' => 17,
    'pluginuri' => ['magazin' => 'se_opreste', 'catalog' => 'pornit']];
$mesajVector = Licenta::mesajDeSemnat(json_decode((string) json_encode($vector)));
$verifica('JSON canonic: chei sortate, fără spații', $mesajVector === "andaxi-licenta-v1\n"
    . '{"emisa_la":"2026-10-18T09:15:02Z","pluginuri":{"catalog":"pornit","magazin":"se_opreste"},"site":"exemplu","versiune":17}');
$semnaturaVector = base64_encode(sodium_crypto_sign_detached($mesajVector, sodium_crypto_sign_secretkey($perecheFixa)));
$verifica('vector de test: semnătura documentată în docs/PLUGINURI.md', str_contains(
    (string) @file_get_contents($radacina . '/docs/PLUGINURI.md'),
    $semnaturaVector
), 'semnătura calculată: ' . $semnaturaVector . ', cheia publică: ' . base64_encode(sodium_crypto_sign_publickey($perecheFixa)));

// Curățenie A.
foreach (glob($tmp . '/*') ?: [] as $fisier) {
    @unlink($fisier);
}
@rmdir($tmp);
Plugins::folosesteJurnal(null);
Licenta::reseteaza(true);
Plugins::reseteaza();

if (in_array('--doar-licenta', $argv, true)) {
    $scrie('');
    $scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
    exit($esuate === 0 ? 0 : 1);
}

// =====================================================================
// B. Garda rutelor, cu serverul de dezvoltare
// =====================================================================
$scrie('');
$scrie('== B. Garda rutelor (server de dezvoltare)');
$port = (string) (getenv('CMS_DEV_PORT') ?: '8701');
$baza = 'http://127.0.0.1:' . $port;
$cerere = static function (string $metoda, string $cale, ?string $jar = null, array $date = []) use ($baza): array {
    $c = curl_init($baza . $cale);
    $antete = [];
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antete): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $antete[strtolower(trim($nume))] = trim($valoare);
            }
            return strlen($linie);
        },
    ]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    if ($jar !== null) {
        curl_setopt($c, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($c, CURLOPT_COOKIEJAR, $jar);
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $antete, 'corp' => $corp];
};

$amPornitServerul = false;
if ($cerere('GET', '/health')['cod'] !== 200) {
    exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' 2>&1', $iesire, $codServer);
    if ($codServer !== 0) {
        $scrie('EROARE: serverul nu pornește: ' . implode(' ', $iesire));
        exit(1);
    }
    $amPornitServerul = true;
}

$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() < 10) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
// Prima valoare din bază; „1” dacă tabelul e gol (adresa rămâne o rută validă).
$unu = static fn (string $sql): string => (string) ($db->query($sql)->fetchColumn() ?: '1');
$tag = '';
foreach ($db->query("SELECT tags_json FROM products WHERE tags_json IS NOT NULL AND tags_json <> '' AND tags_json <> '[]' LIMIT 20")->fetchAll(PDO::FETCH_COLUMN) as $json) {
    $lista = json_decode((string) $json, true);
    if (is_array($lista) && $lista !== []) {
        $tag = (string) reset($lista);
        break;
    }
}
$mostre = [
    'produs' => $unu("SELECT slug FROM products WHERE slug = 'ceai-de-menta'"),
    'categorie' => $unu('SELECT slug FROM product_categories ORDER BY id LIMIT 1'),
    'marca' => 'livada-demo',
    'eticheta' => $tag !== '' ? $tag : 'demo',
    'blog' => $unu('SELECT slug FROM blog_posts WHERE is_published = 1 ORDER BY id LIMIT 1'),
    'utilizator' => $unu('SELECT id FROM users ORDER BY id LIMIT 1'),
    'comanda_id' => $unu('SELECT id FROM orders WHERE deleted_at IS NULL ORDER BY id LIMIT 1'),
    'comanda_nr' => $unu('SELECT order_number FROM orders WHERE deleted_at IS NULL ORDER BY id LIMIT 1'),
    'sablon_produs' => $unu('SELECT id FROM product_templates ORDER BY id LIMIT 1'),
    'sablon_blog' => $unu('SELECT id FROM blog_templates ORDER BY id LIMIT 1'),
    'promo' => $unu('SELECT id FROM promotional_products ORDER BY id LIMIT 1'),
    'optin' => $unu('SELECT slug FROM newsletter_optin_forms ORDER BY id LIMIT 1'),
    'link' => $unu('SELECT token FROM order_payment_links ORDER BY id LIMIT 1'),
    'pagina' => $unu('SELECT id FROM pages ORDER BY id LIMIT 1'),
];

/** Valoarea unui parametru de rută pentru cereri GET (date reale) și POST (inexistente, fără efect). */
$valoare = static function (string $tipar, string $nume, string $metoda) use ($mostre): string {
    if ($metoda === 'POST') {
        return match ($nume) {
            'slug', 'token' => 'nu-exista',
            'orderNumber' => $mostre['comanda_nr'],
            'actiune' => 'verifica',
            default => '999999',
        };
    }
    return match (true) {
        str_starts_with($tipar, '/categorie/') => $mostre['categorie'],
        str_starts_with($tipar, '/marca/') => $mostre['marca'],
        str_starts_with($tipar, '/eticheta/') => $mostre['eticheta'],
        str_starts_with($tipar, '/produs/') => $mostre['produs'],
        str_starts_with($tipar, '/blog/'), str_starts_with($tipar, '/api/blog/') => $mostre['blog'],
        str_starts_with($tipar, '/plata/') => $mostre['link'],
        str_starts_with($tipar, '/checkout/succes/') => $mostre['comanda_nr'],
        str_starts_with($tipar, '/admin/users/') => $mostre['utilizator'],
        str_starts_with($tipar, '/admin/products/templates/') => $mostre['sablon_produs'],
        str_starts_with($tipar, '/admin/blog/templates/') => $mostre['sablon_blog'],
        str_starts_with($tipar, '/admin/orders/') => $mostre['comanda_id'],
        str_starts_with($tipar, '/admin/promo-products/') => $mostre['promo'],
        str_starts_with($tipar, '/admin/pages/') => $mostre['pagina'],
        $nume === 'slug' && $tipar === '/{slug*}' => 'termeni-si-conditii',
        in_array($nume, ['campaignId', 'subscriberId'], true) => '1',
        default => 'x',
    };
};

// Lista cererilor: fiecare rută din router, plus variantele rutelor împărțite și paginile pluginurilor.
$router = new Router();
(require $radacina . '/src/Http/rute.php')($router);
$cereri = [];
foreach ($router->rute() as $ruta) {
    $metoda = $ruta['metoda'];
    $tipar = $ruta['tipar'];
    $parametri = [];
    $cale = (string) preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(\*?)\}#', static function (array $m) use ($tipar, $metoda, $valoare, &$parametri): string {
        $v = $valoare($tipar, $m[1], $metoda);
        $parametri[$m[1]] = $v;
        return $m[2] === '*' ? implode('/', array_map('rawurlencode', explode('/', $v))) : rawurlencode($v);
    }, str_replace('\\.', '.', $tipar));
    $variante = [['get' => [], 'post' => [], 'parametri' => $parametri, 'cale' => $cale]];
    $impartita = Plugins::impartitaNormalizata($metoda, $tipar);
    if ($impartita !== null) {
        $variante = [];
        foreach ($impartita as [$conditii]) {
            $v = ['get' => [], 'post' => [], 'parametri' => $parametri, 'cale' => $cale];
            foreach ((array) $conditii as $cheie => $valori) {
                [$sursa, $nume] = explode('.', (string) $cheie, 2);
                $primul = rtrim((string) ((array) $valori)[0], '*');
                if ($sursa === 'ruta') {
                    $v['parametri'][$nume] = $primul;
                    $v['cale'] = str_replace('/' . rawurlencode($parametri[$nume]), '/' . rawurlencode($primul), $v['cale']);
                } else {
                    $v[$sursa][$nume] = $primul . (str_ends_with((string) ((array) $valori)[0], '*') ? 'save' : '');
                }
            }
            $variante[] = $v;
        }
    }
    if ($tipar === '/{slug*}') {
        foreach (Plugins::chei() as $cheie) {
            foreach ((array) (Plugins::definitie($cheie)['pagini'] ?? []) as $pagina) {
                $variante[] = ['get' => [], 'post' => [], 'parametri' => ['slug' => $pagina], 'cale' => '/' . $pagina];
            }
        }
    }
    foreach ($variante as $v) {
        $cereri[] = ['metoda' => $metoda, 'tipar' => $tipar] + $v;
    }
}
$scrie('  INFO  ' . count($router->rute()) . ' rute, ' . count($cereri) . ' cereri pe scenariu');

// Licențele scenariilor, semnate cu cheia de dezvoltare (ca tools/dev/licenta.php).
$cheieDev = Licenta::decodeazaBase64((string) @file_get_contents($dosarDev . '/licenta-cheie-privata.txt'));
if ($cheieDev === null) {
    $scrie('EROARE: lipsește cheia de dezvoltare; rulează tools/dev/instaleaza.sh');
    exit(1);
}
$catalog = Plugins::catalog();
$pachet = static function (string $nume) use ($catalog): array {
    if ($nume === 'toate') {
        return array_fill_keys(array_keys($catalog['pluginuri']), 'pornit');
    }
    $p = $catalog['pachete'][$nume];
    return array_fill_keys(array_merge($p['pluginuri'] ?? [], $p['cel_putin_una_din'] ?? []), 'pornit');
};
$puneLicenta = static function (?array $pluginuri, array $extra = []) use ($cheieDev): void {
    $dosar = Licenta::dosar();
    if ($pluginuri === null) {
        @unlink($dosar . '/' . Licenta::FISIER);
        @unlink($dosar . '/' . Licenta::FISIER_BUN);
    } else {
        $versiune = 0;
        foreach ([Licenta::FISIER, Licenta::FISIER_BUN] as $fisier) {
            $date = json_decode((string) @file_get_contents($dosar . '/' . $fisier), true);
            $versiune = max($versiune, is_array($date) ? (int) ($date['versiune'] ?? 0) : 0);
        }
        $rez = Licenta::instaleaza(Licenta::semneaza(array_merge([
            'site' => Licenta::site(), 'emisa_la' => gmdate('Y-m-d\TH:i:s\Z'), 'versiune' => $versiune + 1,
            'pluginuri' => array_filter($pluginuri, static fn ($s): bool => $s !== 'oprit'),
        ], $extra), $cheieDev));
        if (!$rez['ok']) {
            throw new RuntimeException('Licența de test nu s-a instalat: ' . $rez['eroare']);
        }
    }
    Plugins::reseteaza();
    Plugins::stari();
};

$parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
$adminEmail = (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro');
$jar = $dosarDev . '/test-pluginuri-cookie-' . getmypid() . '.txt';
$jarClient = $dosarDev . '/test-pluginuri-client-' . getmypid() . '.txt';
$formularComanda = [];
$webhookuri = ['/webhook/stripe', '/webhook/euplatesc', '/webhook/bt-ipay', '/api/erp/notificare', '/api/erp/adrese'];
$scriptCron = static function (string $script) use ($radacina): array {
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' ' . escapeshellarg($script) . ' 2>&1', $iesire, $cod);
    return ['cod' => $cod, 'text' => implode(' ', $iesire), 'linii' => count($iesire)];
};

$scenarii = [
    'toate' => [$pachet('toate'), []],
    'magazin' => [$pachet('magazin'), []],
    'catalog' => [$pachet('catalog'), []],
    'prezentare' => [$pachet('prezentare'), []],
    'asociatie' => [$pachet('asociatie'), []],
    'fără licență' => [null, []],
    'toate, magazinul se oprește' => [array_merge($pachet('toate'), ['magazin' => 'se_opreste', 'plata_euplatesc' => 'se_opreste']), []],
    'prezentare, catalogul duce la /contact' => [$pachet('prezentare'), ['redirectionari' => ['catalog' => '/contact']]],
];

if (in_array('--fara-garda', $argv, true)) {
    $scenarii = [];
}
try {
    foreach ($scenarii as $numeScenariu => [$pluginuri, $extra]) {
        $scrie('');
        $scrie('-- Scenariul „' . $numeScenariu . '”');
        $puneLicenta($pluginuri, $extra);
        $vizibile = array_keys(array_filter(Plugins::stari(), static fn (string $s): bool => $s !== 'oprit'));
        $scrie('  INFO  vizibile: ' . ($vizibile === [] ? 'doar nucleul' : implode(', ', $vizibile)));

        @unlink($jar);
        $login = $cerere('POST', '/admin/login', $jar, ['email' => $adminEmail, 'password' => $parolaAdmin]);
        $verifica('login admin', $login['cod'] === 302);

        $rezumat = ['la controller' => 0, '403 admin' => 0, 'login' => 0, '404 site' => 0, 'redirect' => 0, 'mereu deschise' => 0];
        $greseli = [];
        foreach ($cereri as $c) {
            $proprietar = Plugins::proprietarRuta($c['metoda'], $c['tipar'], $c['parametri'], $c['get'], $c['post']);
            $vizibil = Plugins::oricareVizibil($proprietar['chei']);
            $admin = str_starts_with($c['tipar'], '/admin') && !in_array($c['tipar'], ['/admin/login', '/admin/logout'], true);
            $logat = $admin && $c['metoda'] === 'GET';
            $cale = $c['cale'] . ($c['get'] !== [] ? '?' . http_build_query($c['get']) : '');
            $r = $cerere($c['metoda'], $cale, $logat ? $jar : null, $c['post']);
            $garda = $r['antete']['x-andaxi-garda'] ?? null;
            $eticheta = $c['metoda'] . ' ' . $cale . ' [' . implode('|', $proprietar['chei']) . ']';
            $fatal = $r['cod'] >= 500 || preg_match('/(Fatal error|Parse error|Uncaught )/', $r['corp']) === 1;

            if ($vizibil || $proprietar['mereu_deschis']) {
                $rezumat[$vizibil ? 'la controller' : 'mereu deschise']++;
                if ($garda !== null || $fatal || str_contains($r['corp'], 'Modulul nu este inclus în abonament')) {
                    $greseli[] = $eticheta . ': trebuia să ajungă la controller, a răspuns ' . $r['cod'] . ($garda !== null ? ' (garda)' : '');
                }
                if ($proprietar['mereu_deschis'] && $c['metoda'] === 'POST' && !in_array($r['cod'], [200, 302, 400, 401, 403], true)) {
                    $greseli[] = $eticheta . ': adresa de confirmare a răspuns ' . $r['cod'];
                }
                continue;
            }
            if ($garda === null) {
                $greseli[] = $eticheta . ': pluginul nu e vizibil, dar a ajuns la controller (' . $r['cod'] . ')';
                continue;
            }
            if ($admin && $logat) {
                $rezumat['403 admin']++;
                if ($r['cod'] !== 403 || !str_contains($r['corp'], 'Modulul nu este inclus în abonament')) {
                    $greseli[] = $eticheta . ': aștepta 403 „Modulul nu este inclus în abonament”, a primit ' . $r['cod'];
                }
            } elseif ($admin) {
                $rezumat['login']++;
                if ($r['cod'] !== 302 || ($r['antete']['location'] ?? '') !== '/admin/login') {
                    $greseli[] = $eticheta . ': nelogat, aștepta login, a primit ' . $r['cod'];
                }
            } else {
                $redirect = null;
                foreach ($proprietar['chei'] as $k) {
                    $redirect ??= Plugins::redirectionare($k);
                }
                if ($redirect !== null && $c['metoda'] === 'GET' && !str_starts_with($c['tipar'], '/api/')) {
                    $rezumat['redirect']++;
                    if ($r['cod'] !== 302 || ($r['antete']['location'] ?? '') !== $redirect) {
                        $greseli[] = $eticheta . ': aștepta redirecționare spre ' . $redirect . ', a primit ' . $r['cod'];
                    }
                } else {
                    $rezumat['404 site']++;
                    if ($r['cod'] !== 404) {
                        $greseli[] = $eticheta . ': aștepta 404, a primit ' . $r['cod'];
                    }
                }
            }
        }
        $verifica(count($cereri) . ' cereri: ' . implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($rezumat), $rezumat)),
            $greseli === [], implode("\n          ", array_slice($greseli, 0, 25)) . (count($greseli) > 25 ? "\n          … încă " . (count($greseli) - 25) : ''));

        // Adresele de confirmare, nesemnate: refuzate, niciodată 404/500, oricare ar fi licența.
        $coduri = [];
        foreach ($webhookuri as $w) {
            $coduri[$w] = $cerere('POST', $w)['cod'];
        }
        $verifica('adresele de confirmare răspund (' . implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($coduri), $coduri)) . ')',
            array_diff($coduri, [400, 401, 403]) === []);

        // Meniul adminului: exact intrările pluginurilor vizibile (adminul general vede tot ce există).
        $pagina = $cerere('GET', '/admin', $jar);
        preg_match('#<nav class="admin-nav">(.*?)</nav>#s', $pagina['corp'], $nav);
        preg_match_all('#href="([^"]+)"#', $nav[1] ?? '', $hrefuri);
        $inMeniu = array_map(static fn ($h) => html_entity_decode($h), $hrefuri[1]);
        $asteptate = [];
        foreach (Plugins::meniuAdmin() as $slot) {
            foreach ($slot['intrari'] as $intrare) {
                $asteptate[] = $intrare['href'];
            }
        }
        $toateIntrarile = [];
        foreach (array_merge([$catalog['nucleu']], $catalog['pluginuri']) as $def) {
            foreach ((array) ($def['meniu'] ?? []) as $intrare) {
                $toateIntrarile[] = $intrare['href'];
            }
        }
        sort($inMeniu);
        sort($asteptate);
        $verifica('meniul: ' . count($inMeniu) . ' din ' . count($toateIntrarile) . ' intrări, exact cele ale pluginurilor vizibile',
            $inMeniu === $asteptate, 'în plus: ' . implode(', ', array_diff($inMeniu, $asteptate)) . '; lipsă: ' . implode(', ', array_diff($asteptate, $inMeniu)));
        $grupuriGoale = preg_match_all('#<div class="group-items">\s*</div>#', $nav[1] ?? '');
        $verifica('meniul: niciun grup gol', $grupuriGoale === 0);
        $corpDashboard = $pagina['corp'];
        $verifica('dashboard: „Comenzi recente” doar cu magazinul', str_contains($corpDashboard, 'Comenzi recente') === Plugins::vizibil('magazin'));
        $verifica('dashboard: „Utilizatori recenți” doar cu conturile de clienți',
            str_contains($corpDashboard, 'Utilizatori recenți') === Plugins::vizibil('conturi_clienti'));
        $verifica('dashboard: cardul „Produse” doar cu catalogul', str_contains($corpDashboard, '<p>Produse</p>') === Plugins::vizibil('catalog'));
        $verifica('„Licența lipsește” apare doar fără licență', str_contains($corpDashboard, 'Licența lipsește') === Plugins::licentaLipseste());

        // Cronurile pluginurilor care nu au voie să ruleze ies imediat, fără să facă nimic.
        foreach ($catalog['pluginuri'] as $cheie => $def) {
            foreach ((array) ($def['cron'] ?? []) as $script => $cerinta) {
                if (Plugins::cronPermis($script)) {
                    continue;
                }
                $rez = $scriptCron($script);
                $verifica('cron ' . $script . ' (' . $cheie . ' ' . Plugins::stare($cheie) . '): nu rulează (exit 0, o linie)',
                    $rez['cod'] === 0 && $rez['linii'] === 1 && str_contains($rez['text'], 'nu este pornit pe acest site'), $rez['text']);
            }
        }

        if ($numeScenariu === 'toate, magazinul se oprește') {
            $checkout = $cerere('GET', '/checkout');
            $verifica('se oprește: /checkout arată „Momentan nu se mai pot plasa comenzi online”',
                $checkout['cod'] === 200 && str_contains($checkout['corp'], 'Momentan nu se mai pot plasa comenzi online')
                && !str_contains($checkout['corp'], 'name="payment_method"'));
            // Clientul a deschis checkout-ul cât magazinul era pornit (formular valid, coș plin)
            // și trimite comanda după ce magazinul a trecut în „se oprește”: nu trebuie să intre.
            $inainte = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            $trimis = $cerere('POST', '/checkout', $jarClient, $formularComanda);
            $dupa = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            $verifica('se oprește: o comandă cu formular valid, început înainte, e refuzată', $formularComanda !== []
                && $trimis['cod'] === 302 && ($trimis['antete']['location'] ?? '') === '/checkout' && $dupa === $inainte,
                'cod ' . $trimis['cod'] . ', comenzi ' . $inainte . ' → ' . $dupa);
            $comenzi = $cerere('GET', '/admin/orders', $jar);
            $verifica('se oprește: comenzile se văd în admin (ca să se termine)', $comenzi['cod'] === 200);
            $setariCard = ['euplatesc_enabled' => '1', 'euplatesc_merchant_id' => 'demo', 'euplatesc_secret_key' => 'demo',
                'stripe_enabled' => '1', 'stripe_secret_key' => 'sk_test_demo'];
            $metode = (new SiteController())->metodeCardActive($setariCard);
            $verifica('se oprește: EuPlătesc nu mai e oferit pentru plăți noi, Stripe da', !in_array('euplatesc', $metode, true)
                && in_array('stripe', $metode, true), implode(',', $metode));
            $verifica('se oprește: un link de plată nou nu mai pleacă prin EuPlătesc, unul trimis se poate plăti',
                PaymentLink::procesorPentruLinkNou($setariCard) === '' && PaymentLink::procesorDisponibil('euplatesc', $setariCard));
            $link = $cerere('POST', '/admin/orders/' . $mostre['comanda_id'] . '/payment-link', $jar);
            $raspunsLink = json_decode($link['corp'], true);
            $verifica('se oprește: adminul nu mai poate trimite un link de plată nou',
                is_array($raspunsLink) && str_contains((string) ($raspunsLink['error'] ?? ''), 'Linkurile de plată noi nu mai sunt disponibile'),
                $link['corp']);
            $abandonat = $scriptCron('scripts/abandoned-cart-emails.php');
            $verifica('se oprește: emailurile de coș abandonat nu mai pleacă', str_contains($abandonat['text'], 'nu este pornit'));
        }
        if ($numeScenariu === 'toate') {
            $setariCard = ['euplatesc_enabled' => '1', 'euplatesc_merchant_id' => 'demo', 'euplatesc_secret_key' => 'demo',
                'stripe_enabled' => '1', 'stripe_secret_key' => 'sk_test_demo'];
            $metode = (new SiteController())->metodeCardActive($setariCard);
            $verifica('toate pornite: EuPlătesc și Stripe oferite ca înainte', in_array('euplatesc', $metode, true) && in_array('stripe', $metode, true));
            $checkout = $cerere('GET', '/checkout');
            $verifica('toate pornite: checkout-ul normal', str_contains($checkout['corp'], 'name="payment_method"'));
            // Pentru scenariul „se oprește”: un client cu coș și formular de checkout valid.
            @unlink($jarClient);
            $idProdus = $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
            $cerere('POST', '/cos/adauga/' . $idProdus, $jarClient, ['quantity' => '1']);
            $pag = $cerere('GET', '/checkout', $jarClient)['corp'];
            preg_match('/name="checkout_form_token" value="([^"]*)"/', $pag, $token);
            preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $pag, $randat);
            if (isset($token[1], $randat[1])) {
                $formularComanda = [
                    'checkout_form_token' => $token[1], 'checkout_form_rendered_at' => $randat[1],
                    'billing_first_name' => 'Test', 'billing_last_name' => 'Pluginuri', 'billing_phone' => '0700000777',
                    'billing_email' => 'pluginuri@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '7',
                    'billing_city' => 'Cluj-Napoca', 'billing_county' => 'Cluj', 'billing_postcode' => '400000',
                    'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
                ];
            }
            $verifica('toate pornite: client cu coș și formular de checkout (pentru „se oprește”)', $formularComanda !== []);
        }
        if ($numeScenariu === 'prezentare') {
            $p404 = $cerere('GET', '/magazin');
            $verifica('prezentare: pagina 404 fără „Vezi toate produsele”', $p404['cod'] === 404 && !str_contains($p404['corp'], 'Vezi toate produsele'));
            // Baza găsește paginile și cu alte litere sau fără diacritice; tot 404 trebuie să dea.
            $variante = [];
            foreach (['/MAGAZIN', '/Cos', '/CHECKOUT', '/Blog', '/m%C3%A1gazin', '/acorduri-gdpr%20', '/checkout/succes'] as $cale) {
                $variante[$cale] = $cerere('GET', $cale)['cod'];
            }
            $verifica('prezentare: paginile pluginurilor scrise altfel dau tot 404 ('
                . implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($variante), $variante)) . ')',
                array_unique(array_values($variante)) === [404]);
        }
    }

    // =================================================================
    // C. Fiecare pachet văzut din afară: site-ul pornind de la prima pagină și
    //    din harta site-ului, contul clientului și tot meniul adminului
    // =================================================================
    $scrie('');
    $scrie('== C. Pachetele, pagină cu pagină (linkuri, formulare, erori PHP/JS)');
    $faraCod = static fn (string $html): string => (string) preg_replace('#<(script|style|textarea)\b[^>]*>.*?</\1\s*>#is', '', $html);
    /** Linkurile și formularele din HTML (în afara scripturilor, ca filtrul din Plugins). */
    $linkuriSiFormulare = static function (string $html) use ($faraCod): array {
        $vizibil = $faraCod($html);
        preg_match_all('#<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1#is', $vizibil, $a);
        preg_match_all('#<form\b([^>]*)>#is', $vizibil, $f);
        $formulare = [];
        foreach ($f[1] as $atribute) {
            if (preg_match('#\baction\s*=\s*(["\'])(.*?)\1#is', $atribute, $m) === 1) {
                $formulare[] = [html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    preg_match('#\bmethod\s*=\s*["\']?post#i', $atribute) === 1 ? 'POST' : 'GET'];
            }
        }
        return [array_map(static fn ($h): string => html_entity_decode((string) $h, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $a[2]), $formulare];
    };
    /** Calea internă a unui link (cu query), sau null pentru linkurile din afară, ancore, mailto… */
    $caleInterna = static function (string $href): ?string {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        $p = parse_url($href);
        if (!is_array($p) || (isset($p['scheme']) && !in_array(strtolower((string) $p['scheme']), ['http', 'https'], true))) {
            return null;
        }
        if (isset($p['host']) && !in_array(strtolower((string) $p['host']), ['127.0.0.1', 'localhost'], true)) {
            return null;
        }
        $cale = (string) ($p['path'] ?? '/');
        if ($cale === '' || $cale[0] !== '/') {
            return null;
        }
        return $cale . (isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '');
    };
    // Căile pe care nu le urmăm: adminul (are trecerea lui), API-urile, fișierele,
    // autentificarea Google (pleacă în afară) și linkurile din emailuri.
    $deUrmat = static function (string $cale): bool {
        foreach (['/admin', '/api/', '/assets/', '/uploads/', '/webhook', '/auth/', '/newsletter/', '/checkout/bt/',
            '/contul-meu/resetare-parola/', '/logout'] as $prefix) {
            if (str_starts_with($cale, $prefix)) {
                return false;
            }
        }
        return preg_match('#\.[a-z0-9]{2,4}(\?|$)#i', $cale) !== 1;
    };
    // Semnele pluginurilor în pagina afișată (fără scripturi și stiluri) și în scripturi.
    $semneVizibile = [
        'magazin' => ['Adaugă în coș', 'data-cart-button-total', 'class="bv-cart-v2'],
        'oferte_bbd' => ['class="bv-bbd-sidebar"'],
        'recenzii' => ['data-tab="reviews"'],
        'precomenzi' => ['Precomandă'],
        'cupoane' => ['class="bv-cart-v2__coupon'],
    ];
    $semneScript = [
        'chat_live' => ['embed.tawk.to'],
        'magazin' => ['/assets/js/floating-cart.js'],
    ];
    $fisierErori = $dosarDev . '/php-erori.log';
    $pozitieErori = 0;
    $eroriPhpNoi = static function () use ($fisierErori, &$pozitieErori): array {
        clearstatcache(true, $fisierErori);
        $marime = (int) @filesize($fisierErori);
        if ($marime < $pozitieErori) {
            $pozitieErori = 0;
        }
        $text = $marime > $pozitieErori ? (string) @file_get_contents($fisierErori, false, null, $pozitieErori) : '';
        $pozitieErori = $marime;
        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => $l !== ''));
    };
    $eroriPhpNoi();

    // Harta site-ului: o facem o dată (cu „toate”); la fiecare schimbare de pachet
    // site-ul o reface singur, fără ce nu mai e în abonament.
    $puneLicenta($pachet('toate'));
    $fisierHarta = $radacina . '/public/sitemap.xml';
    $hartaExista = is_file($fisierHarta);
    @unlink($jar);
    $cerere('POST', '/admin/login', $jar, ['email' => $adminEmail, 'password' => $parolaAdmin]);
    $cerere('POST', '/admin/settings/store', $jar, ['action' => 'generate_sitemap', 'sitemap_filename' => 'sitemap.xml']);
    $verifica('harta site-ului generată pentru test (public/sitemap.xml)', is_file($fisierHarta));

    $nodePath = (string) (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules');
    $areBrowser = !in_array('--fara-browser', $argv, true) && is_dir($nodePath . '/playwright');
    $dosarCapturi = $dosarDev . '/capturi';
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, '--capturi=')) {
            $dosarCapturi = rtrim(substr((string) $argument, 10), '/');
        }
    }
    $eroriJsToate = [];

    foreach (['toate', 'magazin', 'catalog', 'prezentare', 'asociatie'] as $numePachet) {
        $scrie('');
        $scrie('-- Pachetul „' . $numePachet . '”');
        $puneLicenta($pachet($numePachet));
        $ascunse = array_keys(array_filter(Plugins::stari(), static fn (string $s): bool => $s === 'oprit'));
        $scrie('  INFO  ascunse: ' . ($ascunse === [] ? 'niciunul' : implode(', ', $ascunse)));

        // Prima cerere reface harta site-ului (semnalul pus la schimbarea licenței).
        $cerere('GET', '/health');
        $cerere('GET', '/');
        $harta = $cerere('GET', '/sitemap.xml');
        preg_match_all('#<loc>(.*?)</loc>#', $harta['corp'], $locuri);
        $dinHarta = [];
        $ascunseInHarta = [];
        foreach ($locuri[1] as $loc) {
            $loc = html_entity_decode((string) $loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (!Plugins::adresaVizibila($loc)) {
                $ascunseInHarta[] = $loc;
            }
            $cale = $caleInterna($loc);
            if ($cale !== null) {
                $dinHarta[] = $cale;
            }
        }
        $verifica('harta site-ului: ' . count($locuri[1]) . ' adrese, niciuna a unui plugin ascuns',
            $harta['cod'] === 200 && $locuri[1] !== [] && $ascunseInHarta === [], 'cod ' . $harta['cod'] . '; ' . implode(', ', array_slice($ascunseInHarta, 0, 10)));

        // Trecerea prin site, ca vizitator și (dacă pachetul are conturi) ca client logat.
        $jarViz = $dosarDev . '/test-pluginuri-viz-' . getmypid() . '.txt';
        $jarCli = $dosarDev . '/test-pluginuri-cli-' . getmypid() . '.txt';
        @unlink($jarViz);
        @unlink($jarCli);
        $treceri = [['vizitator', $jarViz, array_merge(['/'], $dinHarta), 220]];
        if (Plugins::vizibil('conturi_clienti')) {
            $cerere('POST', '/login', $jarCli, ['email' => 'andrei.exemplu@exemplu.ro', 'password' => $parolaAdmin]);
            $treceri[] = ['client', $jarCli, ['/contul-meu'], 40];
        }
        $problemeSite = [];
        $vizitatePublic = [];
        foreach ($treceri as [$rol, $jarRol, $start, $maxim]) {
            $coada = $start;
            $vazute = [];
            $variantePeCale = [];
            $pagini = 0;
            while ($coada !== [] && $pagini < $maxim) {
                $cale = (string) array_shift($coada);
                if (isset($vazute[$cale])) {
                    continue;
                }
                $vazute[$cale] = true;
                $doarCale = (string) strtok($cale, '?');
                if (str_contains($cale, '?')) {
                    $variantePeCale[$doarCale] = ($variantePeCale[$doarCale] ?? 0) + 1;
                    if ($variantePeCale[$doarCale] > 2) {
                        continue;
                    }
                }
                $r = $cerere('GET', $cale, $jarRol);
                $pagini++;
                if ($rol === 'vizitator' && $r['cod'] === 200) {
                    $vizitatePublic[] = $cale;
                }
                $eticheta = $rol . ' ' . $cale;
                if ($r['cod'] >= 500 || $r['cod'] === 0) {
                    $problemeSite[] = $eticheta . ': cod ' . $r['cod'];
                }
                if (preg_match('/(Fatal error|Parse error|Uncaught |<b>Warning<\/b>|<b>Notice<\/b>|<b>Deprecated<\/b>)/', $r['corp']) === 1) {
                    $problemeSite[] = $eticheta . ': eroare PHP în pagină';
                }
                if (!str_contains((string) ($r['antete']['content-type'] ?? ''), 'text/html')) {
                    continue;
                }
                [$hrefuri, $formulare] = $linkuriSiFormulare($r['corp']);
                foreach ($hrefuri as $href) {
                    if (!Plugins::adresaVizibila($href)) {
                        $problemeSite[] = $eticheta . ': link spre un plugin ascuns: ' . $href;
                    }
                    $tinta = $caleInterna($href);
                    if ($tinta !== null && $deUrmat($tinta) && !isset($vazute[$tinta])) {
                        $coada[] = $tinta;
                    }
                }
                foreach ($formulare as [$actiune, $metoda]) {
                    if (!Plugins::adresaVizibila($actiune, $metoda)) {
                        $problemeSite[] = $eticheta . ': formular spre un plugin ascuns: ' . $metoda . ' ' . $actiune;
                    }
                }
                $vizibil = $faraCod($r['corp']);
                foreach ($semneVizibile as $plugin => $semne) {
                    if (Plugins::vizibil($plugin)) {
                        continue;
                    }
                    foreach ($semne as $semn) {
                        if (str_contains($vizibil, $semn)) {
                            $problemeSite[] = $eticheta . ': „' . $semn . '” (' . $plugin . ' ascuns)';
                        }
                    }
                }
                foreach ($semneScript as $plugin => $semne) {
                    if (Plugins::vizibil($plugin)) {
                        continue;
                    }
                    foreach ($semne as $semn) {
                        if (str_contains($r['corp'], $semn)) {
                            $problemeSite[] = $eticheta . ': „' . $semn . '” în cod (' . $plugin . ' ascuns)';
                        }
                    }
                }
                // Catalog fără magazin: produsele se văd, prețurile nu.
                if (Plugins::vizibil('catalog') && !Plugins::vizibil('magazin')
                    && preg_match('#\d[\d.]*,\d{2}\s*lei|\d+\.\d{2}\s*lei#i', $vizibil, $pret) === 1) {
                    $problemeSite[] = $eticheta . ': preț afișat fără magazin („' . $pret[0] . '”)';
                }
            }
            $scrie('  INFO  ' . $rol . ': ' . $pagini . ' pagini parcurse');
        }
        @unlink($jarViz);
        @unlink($jarCli);
        $problemeSite = array_values(array_unique($problemeSite));
        $verifica('site: nicio pagină cu eroare, niciun link/formular/semn al unui plugin ascuns', $problemeSite === [],
            implode("\n          ", array_slice($problemeSite, 0, 30)) . (count($problemeSite) > 30 ? "\n          … încă " . (count($problemeSite) - 30) : ''));

        // Un vizitator cu coș: checkout-ul are formularul întreg (filtrul nu are voie să
        // strice un formular care trece peste un <textarea> sau o pagină lungă).
        if (Plugins::permiteInceput('magazin')) {
            $jarCos = $dosarDev . '/test-pluginuri-cos-' . getmypid() . '.txt';
            @unlink($jarCos);
            $cerere('POST', '/cos/adauga/' . $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'"), $jarCos, ['quantity' => '1']);
            $checkout = $cerere('GET', '/checkout', $jarCos);
            @unlink($jarCos);
            $verifica('checkout cu produse în coș: formularul e întreg', $checkout['cod'] === 200
                && str_contains($checkout['corp'], 'name="checkout_form_token"') && str_contains($checkout['corp'], 'name="payment_method"')
                && preg_match('#<form\b[^>]*action="/checkout"#', $checkout['corp']) === 1);
        }
        if (!Plugins::nimicAscuns()) {
            $pagina = '<ul><li><a href="/">Acasă</a></li><li><a href="/contact">Contact</a></li></ul>'
                . '<form method="post" action="/contact/trimite">' . str_repeat('<div class="rand">text text</div>', 30000)
                . '<textarea name="mesaj"><a href="/x">nu se atinge</a></textarea></form>'
                . '<script>var u = "/blog";</script>';
            $ascunsaPrima = null;
            foreach (['/blog', '/magazin', '/cos', '/contul-meu'] as $cale) {
                if (!Plugins::adresaVizibila($cale)) {
                    $ascunsaPrima = $cale;
                    break;
                }
            }
            if ($ascunsaPrima !== null) {
                // Formular GET spre pagina ascunsă (cu un textarea înăuntru): se scoate întreg.
                $pagina .= '<ul><li><a href="' . $ascunsaPrima . '">Ascuns</a></li></ul><form method="get" action="' . $ascunsaPrima
                    . '"><textarea>t</textarea></form>';
            }
            $filtrata = Plugins::filtreazaLinkuri($pagina);
            $verifica('filtrul pe o pagină de ' . round(strlen($pagina) / 1024) . ' KB: păstrează tot ce e vizibil, scoate doar ce e ascuns',
                str_contains($filtrata, 'action="/contact/trimite"') && substr_count($filtrata, '<div class="rand">') === 30000
                && str_contains($filtrata, '<a href="/x">nu se atinge</a>') && str_contains($filtrata, 'var u = "/blog";')
                && str_contains($filtrata, '<a href="/contact">Contact</a>')
                && ($ascunsaPrima === null || (!str_contains($filtrata, 'href="' . $ascunsaPrima . '"') && !str_contains($filtrata, 'action="' . $ascunsaPrima . '"')
                    && !str_contains($filtrata, '<textarea>t</textarea>'))),
                'lungime ' . strlen($pagina) . ' → ' . strlen($filtrata));
        }

        // Meniul adminului: fiecare pagină din el se deschide, fără linkuri spre pluginuri ascunse.
        $panou = $cerere('GET', '/admin', $jar);
        preg_match('#<nav class="admin-nav">(.*?)</nav>#s', $panou['corp'], $nav);
        preg_match_all('#href="([^"]+)"#', $nav[1] ?? '', $hrefuriMeniu);
        $paginiMeniu = array_values(array_unique(array_map(static fn ($h): string => html_entity_decode((string) $h), $hrefuriMeniu[1])));
        $problemeAdmin = [];
        foreach ($paginiMeniu as $cale) {
            $r = $cerere('GET', $cale, $jar);
            $eticheta = 'admin ' . $cale;
            if ($r['cod'] !== 200) {
                $problemeAdmin[] = $eticheta . ': cod ' . $r['cod'];
                continue;
            }
            if (str_contains($r['corp'], 'Modulul nu este inclus în abonament')) {
                $problemeAdmin[] = $eticheta . ': refuzată de gardă, deși e în meniu';
            }
            [$hrefuri, $formulare] = $linkuriSiFormulare($r['corp']);
            foreach ($hrefuri as $href) {
                if (!Plugins::adresaVizibila($href)) {
                    $problemeAdmin[] = $eticheta . ': link spre un plugin ascuns: ' . $href;
                }
            }
            foreach ($formulare as [$actiune, $metoda]) {
                if (!Plugins::adresaVizibila($actiune, $metoda)) {
                    $problemeAdmin[] = $eticheta . ': formular spre un plugin ascuns: ' . $metoda . ' ' . $actiune;
                }
            }
        }
        $problemeAdmin = array_values(array_unique($problemeAdmin));
        $verifica('admin: ' . count($paginiMeniu) . ' pagini din meniu se deschid, fără linkuri/formulare spre pluginuri ascunse',
            $paginiMeniu !== [] && $problemeAdmin === [], implode("\n          ", array_slice($problemeAdmin, 0, 30)));

        // Cu browserul: aceleași linkuri după ce rulează JavaScript-ul, plus erorile JS.
        if ($areBrowser) {
            $fisierPagini = $dosarDev . '/crawl-pagini-' . getmypid() . '.json';
            $fisierRezultat = $dosarDev . '/crawl-rezultat-' . getmypid() . '.json';
            $esantion = array_values(array_unique(array_merge(['/'], array_slice($vizitatePublic, 0, 35))));
            file_put_contents($fisierPagini, json_encode(['public' => $esantion, 'admin' => $paginiMeniu]));
            $comanda = 'CMS_DEV_URL=' . escapeshellarg($baza) . ' CMS_DEV_ADMIN_EMAIL=' . escapeshellarg($adminEmail)
                . ' CMS_DEV_PAROLA=' . escapeshellarg($parolaAdmin) . ' CMS_DEV_PAGINI=' . escapeshellarg($fisierPagini)
                . ' CMS_DEV_REZULTAT=' . escapeshellarg($fisierRezultat) . ' CMS_DEV_CAPTURI=' . escapeshellarg($dosarCapturi)
                . ' CMS_DEV_CAPTURI_SUFIX=' . escapeshellarg('-' . $numePachet) . ' NODE_PATH=' . escapeshellarg($nodePath)
                . ' node ' . escapeshellarg($radacina . '/tools/dev/crawl-pachet.cjs') . ' 2>&1';
            exec($comanda, $iesireNode, $codNode);
            $rez = json_decode((string) @file_get_contents($fisierRezultat), true);
            @unlink($fisierPagini);
            @unlink($fisierRezultat);
            if ($codNode !== 0 || !is_array($rez)) {
                $verifica('browser: trecerea cu Playwright', false, implode(' ', $iesireNode));
            } else {
                $problemeDom = [];
                $eroriJs = [];
                foreach ((array) $rez['pagini'] as $p) {
                    $eticheta = $p['rol'] . ' ' . $p['cale'];
                    foreach ((array) $p['linkuri'] as $href) {
                        if (!Plugins::adresaVizibila((string) $href)) {
                            $problemeDom[] = $eticheta . ': link în DOM spre un plugin ascuns: ' . $href;
                        }
                    }
                    foreach ((array) $p['formulare'] as [$actiune, $metoda]) {
                        if (!Plugins::adresaVizibila((string) $actiune, (string) $metoda)) {
                            $problemeDom[] = $eticheta . ': formular în DOM spre un plugin ascuns: ' . $metoda . ' ' . $actiune;
                        }
                    }
                    foreach ((array) $p['erori'] as $eroare) {
                        $cheie = $p['rol'] . ' ' . $p['cale'] . ' ' . $eroare;
                        if ($numePachet === 'toate') {
                            $eroriJsToate[$cheie] = true;
                        } elseif (!isset($eroriJsToate[$cheie])) {
                            // O eroare care apare și cu toate pluginurile nu e a pachetului.
                            $eroriJs[] = $eticheta . ': ' . $eroare;
                        }
                    }
                }
                $problemeDom = array_values(array_unique($problemeDom));
                $verifica('browser: ' . count((array) $rez['pagini']) . ' pagini, niciun link/formular în DOM spre pluginuri ascunse',
                    $problemeDom === [], implode("\n          ", array_slice($problemeDom, 0, 20)));
                $verifica('browser: nicio eroare JavaScript nouă față de „toate”', $eroriJs === [],
                    implode("\n          ", array_slice(array_unique($eroriJs), 0, 20)));
                if ($numePachet === 'toate' && $eroriJsToate !== []) {
                    $scrie('  INFO  erori JavaScript și cu toate pluginurile (nu țin de pluginuri): '
                        . implode(' | ', array_slice(array_keys($eroriJsToate), 0, 5)));
                }
                foreach ((array) $rez['capturi'] as $captura) {
                    $scrie('  INFO  captura meniului: ' . $captura);
                }
                if ((array) $rez['blocate'] !== []) {
                    $scrie('  INFO  cereri spre alte servere, blocate: ' . implode(', ', (array) $rez['blocate']));
                }
            }
        }

        $eroriPhp = $eroriPhpNoi();
        $verifica('jurnalul PHP: niciun avertisment sau eroare nouă', $eroriPhp === [],
            implode("\n          ", array_slice($eroriPhp, 0, 15)));
    }
    if (!$hartaExista) {
        @unlink($fisierHarta);
    }

    // =================================================================
    // D. Magazinul „se oprește”: comenzile noi nu mai intră, cele plasate
    //    se termină (editare în admin, plata confirmată de procesator)
    // =================================================================
    $scrie('');
    $scrie('== D. Magazinul „se oprește” (comenzile existente se termină)');
    $puneLicenta(array_merge($pachet('toate'), ['magazin' => 'se_opreste', 'plata_stripe' => 'se_opreste',
        'fidelitate' => 'se_opreste', 'cupoane' => 'se_opreste', 'erp' => 'se_opreste']));
    $cerere('GET', '/health');
    $eroriPhpNoi();
    @unlink($jar);
    $cerere('POST', '/admin/login', $jar, ['email' => $adminEmail, 'password' => $parolaAdmin]);

    $checkout = $cerere('GET', '/checkout');
    $verifica('se oprește: checkout-ul închis, cu mesajul în română', $checkout['cod'] === 200
        && str_contains($checkout['corp'], 'Momentan nu se mai pot plasa comenzi online') && !str_contains($checkout['corp'], 'name="payment_method"'));
    $jarCos = $dosarDev . '/test-pluginuri-cos-' . getmypid() . '.txt';
    @unlink($jarCos);
    $idMenta = $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
    $adaugat = $cerere('POST', '/cos/adauga/' . $idMenta, $jarCos, ['quantity' => '1']);
    $sumar = json_decode($cerere('GET', '/api/cart/summary', $jarCos)['corp'], true);
    $verifica('se oprește: „Adaugă în coș” refuzat (coșul rămâne gol)', $adaugat['cod'] === 302
        && is_array($sumar) && (int) ($sumar['items_count'] ?? -1) === 0, 'cod ' . $adaugat['cod']);
    @unlink($jarCos);
    $produs = $cerere('GET', '/produs/ceai-de-menta');
    $verifica('se oprește: pagina produsului se vede, fără butonul de coș', $produs['cod'] === 200
        && !str_contains($faraCod($produs['corp']), 'Adaugă în coș') && str_contains($produs['corp'], 'Momentan nu se mai pot plasa comenzi online'));

    // O comandă existentă: se editează în admin.
    $idComanda = (int) $mostre['comanda_id'];
    $rand = $db->query('SELECT order_number, admin_notes, payment_method, payment_status, status, paid_at, paid_amount, payment_error, '
        . 'stripe_session_id, stripe_payment_intent_id FROM orders WHERE id = ' . $idComanda)->fetch(PDO::FETCH_ASSOC);
    $nota = 'Notă de test „se oprește” ' . getmypid();
    $salvare = json_decode($cerere('POST', '/admin/orders/' . $idComanda . '/note-interne', $jar, ['admin_notes' => $nota])['corp'], true);
    $notaSalvata = (string) $db->query('SELECT admin_notes FROM orders WHERE id = ' . $idComanda)->fetchColumn();
    $verifica('se oprește: o comandă existentă se editează în admin (nota internă)', is_array($salvare) && ($salvare['ok'] ?? false) === true
        && $notaSalvata === $nota, json_encode($salvare, JSON_UNESCAPED_UNICODE));
    $comenzi = $cerere('GET', '/admin/orders?search=' . rawurlencode((string) $rand['order_number']), $jar);
    $verifica('se oprește: comanda apare în lista de comenzi', $comenzi['cod'] === 200 && str_contains($comenzi['corp'], (string) $rand['order_number']));

    // Plata unei comenzi existente, confirmată de procesator după oprire (Stripe, semnat cu un secret de test).
    $setariVechi = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('stripe_webhook_secret')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $secret = 'whsec_test_pluginuri_' . getmypid();
    \App\Support\Settings::save($db, ['stripe_webhook_secret' => $secret]);
    $db->prepare("UPDATE orders SET payment_method = 'stripe', payment_status = 'pending', status = 'pending_payment', paid_at = NULL,
        payment_error = NULL WHERE id = :id")->execute(['id' => $idComanda]);
    // Emailul „comandă nouă” pleacă o singură dată pe comandă (email_logs); îl
    // scoatem din jurnal pe durata testului și îl punem la loc după.
    $jurnalEmail = $db->query("SELECT * FROM email_logs WHERE order_id = " . $idComanda . " AND email_type = 'new_order'")->fetch(PDO::FETCH_ASSOC);
    $db->exec("DELETE FROM email_logs WHERE order_id = " . $idComanda . " AND email_type = 'new_order'");
    $mailuriInainte = glob($dosarDev . '/mail/*') ?: [];
    $eveniment = json_encode(['id' => 'evt_test', 'type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs_test_pluginuri', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_pluginuri',
        'client_reference_id' => (string) $rand['order_number'],
        'metadata' => ['order_id' => (string) $idComanda, 'order_number' => (string) $rand['order_number']],
    ]]]);
    $moment = time();
    $semnatura = 't=' . $moment . ',v1=' . hash_hmac('sha256', $moment . '.' . $eveniment, $secret);
    $c = curl_init($baza . '/webhook/stripe');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $eveniment, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Stripe-Signature: ' . $semnatura]]);
    $raspunsWebhook = (string) curl_exec($c);
    $codWebhook = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    $dupa = $db->query('SELECT payment_status, status FROM orders WHERE id = ' . $idComanda)->fetch(PDO::FETCH_ASSOC);
    $verifica('se oprește: notificarea Stripe a unei comenzi existente e procesată (comanda devine plătită)',
        $codWebhook === 200 && trim($raspunsWebhook) === 'ok' && ($dupa['payment_status'] ?? '') === 'paid' && ($dupa['status'] ?? '') === 'pending',
        'cod ' . $codWebhook . ' „' . trim($raspunsWebhook) . '”, ' . json_encode($dupa));
    $mailuriNoi = array_diff(glob($dosarDev . '/mail/*') ?: [], $mailuriInainte);
    $verifica('se oprește: emailul comenzii existente pleacă în continuare', $mailuriNoi !== []);
    array_map('unlink', $mailuriNoi);
    // Comanda și setările înapoi cum erau.
    $db->prepare('UPDATE orders SET admin_notes = :admin_notes, payment_method = :payment_method, payment_status = :payment_status,
        status = :status, paid_at = :paid_at, paid_amount = :paid_amount, payment_error = :payment_error,
        stripe_session_id = :stripe_session_id, stripe_payment_intent_id = :stripe_payment_intent_id WHERE id = :id')
        ->execute(array_diff_key($rand, ['order_number' => 1]) + ['id' => $idComanda]);
    $db->exec("DELETE FROM email_logs WHERE order_id = " . $idComanda . " AND email_type = 'new_order'");
    if (is_array($jurnalEmail)) {
        $coloane = array_keys($jurnalEmail);
        $db->prepare('INSERT INTO email_logs (`' . implode('`, `', $coloane) . '`) VALUES (:' . implode(', :', $coloane) . ')')->execute($jurnalEmail);
    }
    if (isset($setariVechi['stripe_webhook_secret'])) {
        \App\Support\Settings::save($db, ['stripe_webhook_secret' => (string) $setariVechi['stripe_webhook_secret']]);
    } else {
        $db->exec("DELETE FROM settings WHERE `key` = 'stripe_webhook_secret'");
    }

    // ERP „se oprește”: o comandă care n-a ajuns în ERP nu mai pleacă, stă „în așteptare”.
    \App\Support\ErpSync::ensureSchema($db);
    $erpVechi = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('erp_enabled')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $randErp = $db->query('SELECT erp_status, erp_last_error, erp_next_retry_at, erp_attempts, erp_order_id FROM orders WHERE id = '
        . $idComanda)->fetch(PDO::FETCH_ASSOC);
    \App\Support\Settings::save($db, ['erp_enabled' => '1']);
    $db->prepare("UPDATE orders SET erp_status = 'pending', erp_order_id = NULL WHERE id = :id")->execute(['id' => $idComanda]);
    $impins = \App\Support\ErpSync::push($db, $idComanda, true);
    $stareErp = (string) $db->query('SELECT erp_status FROM orders WHERE id = ' . $idComanda)->fetchColumn();
    $verifica('se oprește (erp): comanda nouă nu mai pleacă în ERP, rămâne „în așteptare”', ($impins['tinuta'] ?? false) === true
        && $stareErp === 'pending' && str_contains((string) $impins['message'], 'se oprește'), json_encode($impins, JSON_UNESCAPED_UNICODE));
    $puneLicenta($pachet('magazin'));
    $impins = \App\Support\ErpSync::push($db, $idComanda, true);
    $verifica('oprit (erp): nicio legătură cu ERP-ul, comanda rămâne doar pe site', ($impins['ok'] ?? true) === false
        && str_contains((string) $impins['message'], 'nu este inclusă în abonament') && \App\Support\ErpClient::fromSettings(['erp_url' => 'x', 'erp_api_key' => 'y']) === null,
        json_encode($impins, JSON_UNESCAPED_UNICODE));
    $db->prepare('UPDATE orders SET erp_status = :erp_status, erp_last_error = :erp_last_error, erp_next_retry_at = :erp_next_retry_at,
        erp_attempts = :erp_attempts, erp_order_id = :erp_order_id WHERE id = :id')->execute($randErp + ['id' => $idComanda]);
    \App\Support\Settings::save($db, ['erp_enabled' => (string) ($erpVechi['erp_enabled'] ?? '0')]);

    // Cronurile: cele care termină ce e în curs rulează, cele care pornesc ceva nou tac.
    $puneLicenta(array_merge($pachet('toate'), ['magazin' => 'se_opreste', 'newsletter' => 'se_opreste']));
    $bt = $scriptCron('scripts/bt-ipay-sync.php');
    $verifica('se oprește: cronul BT (termină plățile începute) rulează în continuare', !str_contains($bt['text'], 'nu este pornit'), $bt['text']);
    $abandonat = $scriptCron('scripts/abandoned-cart-emails.php');
    $verifica('se oprește: cronul coșurilor abandonate tace (exit 0, o linie)', $abandonat['cod'] === 0
        && str_contains($abandonat['text'], 'nu este pornit') && $abandonat['linii'] === 1, $abandonat['text']);
    $nl = $scriptCron('scripts/newsletter-campaigns.php');
    $verifica('se oprește: cronul newsletterului termină campaniile pornite, nu pornește programate', $nl['cod'] === 0
        && str_contains($nl['text'], 'programate_pornite=0'), $nl['text']);
    $eroriPhp = $eroriPhpNoi();
    $verifica('jurnalul PHP: niciun avertisment sau eroare nouă (D)', $eroriPhp === [], implode("\n          ", array_slice($eroriPhp, 0, 15)));
} finally {
    // Dezvoltarea continuă cu toate pluginurile pornite.
    $puneLicenta($pachet('toate'));
    @unlink($jar);
    @unlink($jarClient);
    if ($amPornitServerul) {
        exec(escapeshellarg($radacina . '/tools/dev/server.sh') . ' --opreste >/dev/null 2>&1');
    }
}

// =====================================================================
// E. Cu toate pluginurile, paginile-cheie ies exact ca în codul de bază
// =====================================================================
if (!in_array('--fara-baza', $argv, true)) {
    $scrie('');
    $scrie('== E. Licența „toate”: HTML identic cu codul dinaintea pluginurilor (tools/dev/compara-cu-baza.sh)');
    exec(escapeshellarg($radacina . '/tools/dev/compara-cu-baza.sh') . ' 2>&1', $iesireBaza, $codBaza);
    $ultima = trim((string) end($iesireBaza));
    $diferite = array_filter($iesireBaza, static fn (string $l): bool => !str_starts_with(trim($l), 'OK') && trim($l) !== '');
    $verifica($ultima !== '' ? $ultima : 'compara-cu-baza', $codBaza === 0, implode("\n          ", array_slice($diferite, 0, 20)));
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
