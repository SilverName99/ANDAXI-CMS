<?php

declare(strict_types=1);

/**
 * Testele mecanismului de pluginuri (fără browser).
 *
 *   tools/dev/php.sh tools/dev/test-pluginuri.php            # tot: A, B0, B, C, D, E, G
 *   tools/dev/php.sh tools/dev/test-pluginuri.php --doar-licenta
 *   … --fara-garda     # fără B (cererea fiecărei rute, cea mai lungă parte)
 *   … --fara-browser   # C fără Playwright
 *   … --fara-baza      # fără E (comparația cu codul de bază)
 *   … --capturi=DIR    # unde se pun capturile meniului adminului (implicit $CMS_DEV_DIR/capturi)
 *   … --fiecare-plugin # și F: câte un plugin oprit, restul pornite (cu browserul, ~1 minut pe plugin)
 *   … --fara-seo       # fără G (testul SEO, ~1 minut)
 *
 * A. Licența (fără server, într-un dosar temporar, cu o pereche de chei nouă):
 *    bună, semnătură greșită/lipsă/altă cheie, versiune mai veche, lipsă, chei și
 *    stări necunoscute, alt site, JSON stricat, ultima bună stricată, regulile de
 *    dependență, jurnalul „o singură dată”, vectorul de test pentru CRM.
 * B0. Instantaneul rutelor (tools/dev/instantaneu-rute.php, fără server): suprafața
 *    fiecărei licențe de test față de fișierele din tools/dev/instantanee/, regulile
 *    fixe care nu depind de catalog și mecanismul diferențelor declarate din
 *    compara-cu-baza.php (marcajele de port).
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
 *    după JavaScript, textul vizibil (fără etichetele și explicațiile pluginurilor
 *    oprite; și în ferestrele comenzilor și ale produselor, în tab-urile setărilor
 *    și în contul clientului, cu un produs în coș), erorile JS noi față de „toate”
 *    și captura meniului adminului.
 * D. Magazinul „se oprește”: checkout închis, coșul refuză produse noi, o comandă
 *    existentă se editează în admin, notificarea Stripe (semnată cu un secret de
 *    test) o marchează plătită și emailul ei pleacă; ERP-ul „se oprește” ține
 *    comenzile noi, „oprit” nu mai vorbește deloc; cronurile care termină ce e în
 *    curs rulează, cele care pornesc ceva nou tac. Plus magazinul fără livrarea
 *    FAN: checkout-ul fără nomenclatorul FAN și o comandă cu localitatea scrisă
 *    de mână; un link de plată al cărui procesator a fost scos din licență.
 * E. Cu licența „toate” și cu licența de dezvoltare a Bioscem, paginile-cheie (prima
 *    pagină, produs, coș, checkout, dashboard, comenzi…) au HTML identic cu codul
 *    Bioscem însuși (compara-cu-baza.sh, la ultimul commit revizuit din docs/de-portat.md).
 * F. (--fiecare-plugin) Pe rând, fiecare plugin oprit cu restul pornite: aceleași
 *    verificări cu browserul ca la C, pentru pluginul oprit și cele care depind de el.
 * G. SEO (tools/dev/test-seo.php; sărit cu --fara-seo): adresa unică (www, „/” final,
 *    majuscule), redirecționările adreselor vechi din datele site-ului, niciodată pe
 *    POST, plăți, webhook-uri și adresele mereu deschise ale pluginurilor; sitemap-ul,
 *    robots.txt și datele structurate pe fiecare pachet; oprirea de urgență.
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

// --doar-tema: doar secțiunea T (tema site-ului), de exemplu după o schimbare în Tema sau în pachetul temei demo.
if (in_array('--doar-tema', $argv, true)) {
    require __DIR__ . '/lib/test-tema.php';
    $scrie('');
    $scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
    exit($esuate === 0 ? 0 : 1);
}

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
$verifica('JSON stricat și nicio ultimă bună, dar site-ul a avut licență: merge pe ultima aplicată (v6), nu doar cu nucleul',
    Plugins::licentaNeverificata() && !Plugins::licentaLipseste() && (Plugins::licenta()['versiune'] ?? 0) === 6
    && Plugins::activ('catalog') && Plugins::notaLicentaAdmin() !== null && $contine(Plugins::mesaje(), 'ultima licență aplicată'));
@unlink($tmp . '/' . Plugins::FISIER_STARE);
$reincarca();
$verifica('site care n-a avut niciodată licență (fără stare aplicată): „Licența lipsește”, doar nucleul', Plugins::licentaLipseste()
    && !Plugins::licentaNeverificata() && array_unique(array_values(Plugins::stari())) === ['oprit']);
[$mesajCron, $codCron] = Plugins::cronOprit('scripts/bt-ipay-sync.php');
$verifica('fără nicio licență, cronul unui plugin iese cu codul 2 (de văzut în monitorizare), nu 0', $codCron === 2
    && str_contains($mesajCron, 'Licența site-ului lipsește'), $mesajCron);
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

// 12b. Cheia sau site-ul din .env greșite după o licență bună (aici: altă cheie publică):
//      site-ul merge mai departe cu ultima licență aplicată, cronurile banilor rulează.
Licenta::configureaza(['dosar' => $tmp, 'cheie_publica' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())), 'site' => 'test']);
$reincarca();
$verifica('cheia publică schimbată (nicio licență nu se verifică): rămân stările ultimei licențe aplicate (v11, toate pornite)',
    Plugins::licentaNeverificata() && (Plugins::licenta()['versiune'] ?? 0) === 11 && array_unique(array_values(Plugins::stari())) === ['pornit'],
    implode(' | ', Plugins::mesaje()));
$verifica('…și cronurile care termină plățile rulează (BT, ERP, FAN)', Plugins::cronPermis('scripts/bt-ipay-sync.php')
    && Plugins::cronPermis('scripts/erp-sync.php') && Plugins::cronPermis('scripts/fan-tracking-sync.php'));
Licenta::configureaza(['dosar' => $tmp, 'cheie_publica' => $publica, 'site' => 'test']);
$reincarca();
$verifica('cheia bună la loc: licența se verifică din nou', !Plugins::licentaNeverificata() && Plugins::notaLicentaAdmin() === null);

// 12c. Unealta internă nu pornește filtrele: licența Bioscem (fără migrare_wordpress) lasă HTML-ul neatins.
$faraIntern = array_filter($toate, static fn (string $s, string $k): bool => ($k !== 'migrare_wordpress'), ARRAY_FILTER_USE_BOTH);
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta($faraIntern, 11), $privata));
$reincarca();
$html = '<div class="slider"><ul class="slider-dots" data-dots></ul><ol id="pasi"></ol><ul class="lista"><li class="placeholder"></li><li>Text</li></ul></div>';
$verifica('licența Bioscem (toate, fără unealta internă): nimic de ascuns, HTML-ul iese neschimbat', Plugins::nimicAscuns()
    && !Plugins::vizibil('migrare_wordpress') && Plugins::filtreazaLinkuri($html) === $html);
// Cu un plugin oprit: doar listele golite de filtru dispar, nu și cele scrise goale de client.
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta(array_merge($toate, ['blog' => 'oprit']), 11), $privata));
$reincarca();
$html = '<ul id="rezultate-js"></ul><ol class="pasi">' . "\n" . '</ol><ul class="meniu"><li class="divider"></li><li><a href="/blog">Blog</a>'
    . '<ul class="sub"><li><a href="/blog/x">X</a></li></ul></li><li><a href="/contact">Contact</a></li></ul><ul class="doar-blog"><li> <a href="/blog">B</a> </li></ul>';
$filtrat = Plugins::filtreazaLinkuri($html);
$verifica('filtrul scoate doar ce a golit el: listele goale și separatorul scrise de client rămân', !Plugins::nimicAscuns()
    && $filtrat === '<ul id="rezultate-js"></ul><ol class="pasi">' . "\n" . '</ol><ul class="meniu"><li class="divider"></li><li><a href="/contact">Contact</a></li></ul>',
    $filtrat);

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
$dinMagazin = Plugins::seOpresteDin('magazin');
$reincarca();
$stareSalvata = json_decode((string) @file_get_contents($tmp . '/' . Plugins::FISIER_STARE), true);
$verifica('se_opreste: momentul intrării se ține minte (aceeași valoare la o nouă încărcare, scrisă în stare-aplicata.json)',
    $dinMagazin !== null && Plugins::seOpresteDin('magazin') === $dinMagazin && isset($stareSalvata['se_opreste_din']['magazin'])
    && Plugins::seOpresteDin('blog') === null);
$verifica('se_opreste prin licență (magazin) vs. doar din cauza unei dependențe (FAN nu e în licență, deci oprit)',
    Plugins::seOprestePrinLicenta('magazin') && !Plugins::seOprestePrinLicenta('catalog'));
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
foreach ([1, 2, 3] as $_) {
    Plugins::jurnalOdataPeZi('test|GET /newsletter/track/open', 'Cerere repetată de test');
}
Plugins::jurnalOdataPeZi('test|GET /checkout/bt/retur', 'Altă adresă de test');
$jurnal = (string) @file_get_contents($tmp . '/jurnal.log');
$verifica('cererile repetate la o adresă mereu deschisă: o linie pe zi și pe adresă', substr_count($jurnal, 'Cerere repetată de test') === 1
    && substr_count($jurnal, 'Altă adresă de test') === 1);
// Dosarul licenței fără drept de scriere (aici: stare-aplicata.json e un dosar, deci nu se poate înlocui).
@unlink($tmp . '/' . Plugins::FISIER_STARE);
@mkdir($tmp . '/' . Plugins::FISIER_STARE);
$scrieFisier(Licenta::FISIER, Licenta::semneaza($licenta(['blog' => 'pornit'], 14), $privata));
$reincarca();
$reincarca();
$jurnal = (string) @file_get_contents($tmp . '/jurnal.log');
$verifica('starea nu se poate salva: adminul vede o notă, jurnalul o linie (nu una pe cerere), stările se aplică oricum',
    Plugins::activ('blog') && str_contains((string) Plugins::notaLicentaAdmin(), 'nu poate fi salvată')
    && substr_count($jurnal, 'Nu pot scrie') === 1, trim($jurnal));
@rmdir($tmp . '/' . Plugins::FISIER_STARE);

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

$canonic = Licenta::jsonCanonic(json_decode((string) json_encode(['z' => "x\u{2028}y\u{2029}", 'redirectionari' => ['blog' => '/pagină-ăîșț/€']])));
$asteptat = '{"redirectionari":{"blog":"/pagină-ăîșț/€"},"z":"x' . "\u{2028}" . 'y' . "\u{2029}" . '"}';
$dinNode = '';
if (trim((string) shell_exec('command -v node 2>/dev/null')) !== '') {
    // Scriptul stă într-un fișier: pe PHP 8.0 (minimul de atunci), escapeshellarg scoate literele cu diacritice.
    file_put_contents($tmp . '/canonic.cjs', 'const o={redirectionari:{blog:"/pagină-ăîșț/€"},z:"x\u2028y\u2029"};'
        . 'process.stdout.write(JSON.stringify(o))');
    $dinNode = (string) shell_exec('node ' . escapeshellarg($tmp . '/canonic.cjs'));
}
$verifica('JSON canonic: U+2028/U+2029 și diacriticele scrise ca JSON.stringify (nu \\u2028)', $canonic === $asteptat
    && ($dinNode === '' || $dinNode === $canonic), $canonic . ' / node: ' . $dinNode);

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
// B0. Instantaneul rutelor (fără server și fără bază)
// =====================================================================
$scrie('');
$scrie('== B0. Instantaneul rutelor (tools/dev/instantanee/) și diferențele declarate ale comparației cu baza');
$iesireB0 = [];
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' tools/dev/instantaneu-rute.php 2>&1', $iesireB0, $codB0);
$verifica('instantaneul rutelor: ' . trim((string) end($iesireB0)), $codB0 === 0,
    implode("\n          ", array_slice(array_filter($iesireB0, static fn (string $l): bool => !str_starts_with($l, '  OK')), 0, 40)));
$iesireB0 = [];
exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' tools/dev/compara-cu-baza.php --autotest 2>&1', $iesireB0, $codB0);
$verifica('compara-cu-baza: marcajele de port și textele declarate (declarat dar lipsă, de două ori, nedeclarat, fără pereche)',
    $codB0 === 0, implode("\n          ", $iesireB0));

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

// Lista cererilor: fiecare rută din router, plus variantele rutelor împărțite și paginile
// pluginurilor (aceeași listă ca instantaneul rutelor, tools/dev/lib/cereri-rute.php).
require_once __DIR__ . '/lib/cereri-rute.php';
$router = new Router();
(require $radacina . '/src/Http/rute.php')($router);
$cereri = cereri_rute($router, $valoare);
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
        // Un site care n-a avut niciodată licență: și fără starea aplicată
        // (altfel ar merge mai departe pe ultima licență aplicată).
        @unlink($dosar . '/' . Licenta::FISIER);
        @unlink($dosar . '/' . Licenta::FISIER_BUN);
        @unlink($dosar . '/' . Plugins::FISIER_STARE);
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
                // Fără pluginul pe site, controllerul răspunde 404 unei cereri care nu e a
                // procesatorului (fără plată semnată): adresa a răspuns, garda nu s-a amestecat.
                if ($proprietar['mereu_deschis'] && $c['metoda'] === 'POST'
                    && !in_array($r['cod'], array_merge([200, 302, 400, 401, 403], $vizibil ? [] : [404]), true)) {
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
                // Redirecționarea se face doar spre o pagină care se vede pe site (altfel 404, nu buclă).
                if ($redirect !== null && $c['metoda'] === 'GET' && !str_starts_with($c['tipar'], '/api/') && Plugins::adresaVizibila($redirect)) {
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
                if (Plugins::licentaLipseste()) {
                    // Fără nicio licență: o problemă de rezolvat, deci un cod de ieșire pe care îl vede monitorizarea.
                    $verifica('cron ' . $script . ' (fără licență): nu rulează (exit 2, o linie)',
                        $rez['cod'] === 2 && $rez['linii'] === 1 && str_contains($rez['text'], 'Licența site-ului lipsește'), $rez['text']);
                    continue;
                }
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
        // Linkul de urmărire din newsletter: doar adresele semnate duc în afară.
        $clickRau = $cerere('GET', '/newsletter/track/click/1/1/x?url=' . rawurlencode('https://exemplu-rau.test/phish'));
        $clickGol = $cerere('GET', '/newsletter/track/click/1/1/x');
        $verifica('linkul de urmărire nesemnat nu duce în afară (' . $clickRau['cod'] . ' ' . ($clickRau['antete']['location'] ?? '') . ')',
            Plugins::vizibil('newsletter')
                ? ($clickRau['cod'] === 302 && ($clickRau['antete']['location'] ?? '') === $baza . '/' && ($clickGol['antete']['location'] ?? '') === $baza . '/')
                : ($clickRau['cod'] === 404 && $clickGol['cod'] === 404));
        $robots = $cerere('GET', '/robots.txt')['corp'];
        $verifica('robots.txt pomenește doar adresele pluginurilor de pe site', str_contains($robots, 'Disallow: /cos') === Plugins::vizibil('magazin')
            && str_contains($robots, 'Disallow: /contul-meu') === Plugins::vizibil('conturi_clienti')
            && str_contains($robots, 'Disallow: /newsletter/') === Plugins::vizibil('newsletter') && str_contains($robots, 'Disallow: /admin'));
        if (Plugins::vizibil('catalog')) {
            $catalogApi = json_decode($cerere('GET', '/api/shop/catalog')['corp'], true);
            $bestApi = json_decode($cerere('GET', '/api/products/best-sellers?limit=3')['corp'], true);
            $primul = (array) ($catalogApi['items'][0] ?? []);
            $primulBest = (array) ($bestApi['items'][0] ?? []);
            $verifica('API-urile catalogului: fără recenzii și vânzări când pluginurile lor nu sunt pe site',
                $primul !== [] && $primulBest !== []
                && array_key_exists('reviews_count', $primul) === Plugins::vizibil('recenzii')
                && array_key_exists('reviews_count', $primulBest) === Plugins::vizibil('recenzii')
                && array_key_exists('sold_qty', $primul) === Plugins::vizibil('magazin')
                && array_key_exists('sold_qty', $primulBest) === Plugins::vizibil('magazin')
                && array_key_exists('price', $primulBest) === Plugins::vizibil('magazin'),
                json_encode([$primul, $primulBest], JSON_UNESCAPED_UNICODE));
        }
        // „Trimite email test” și Setări magazin nu ating ce ține de pluginurile ascunse.
        $mailuriInainteTest = glob($dosarDev . '/mail/*') ?: [];
        $cerere('POST', '/admin/emails/test', $jar, ['template_type' => 'new_order', 'test_email' => 'test-pluginuri@exemplu.ro']);
        $mailuriTest = array_diff(glob($dosarDev . '/mail/*') ?: [], $mailuriInainteTest);
        $verifica('„Trimite email test”: șablonul unei comenzi pleacă doar cu magazinul pe site', ($mailuriTest !== []) === Plugins::vizibil('magazin'));
        array_map('unlink', $mailuriTest);
        $stilInainte = (string) $db->query("SELECT `value` FROM settings WHERE `key` = 'store_quantity_control_style'")->fetchColumn();
        $cerere('POST', '/admin/settings/store', $jar, ['action' => 'actiune-necunoscuta',
            'store_quantity_control_style' => $stilInainte === 'stepper' ? 'default' : 'stepper']);
        $stilDupa = (string) $db->query("SELECT `value` FROM settings WHERE `key` = 'store_quantity_control_style'")->fetchColumn();
        $verifica('Setări magazin: o acțiune necunoscută nu salvează nimic', $stilDupa === $stilInainte, $stilInainte . ' → ' . $stilDupa);

        if ($numeScenariu === 'prezentare') {
            $faraCerere = [
                'POST /checkout/succes/' . $mostre['comanda_nr'] => $cerere('POST', '/checkout/succes/' . $mostre['comanda_nr'])['cod'],
                'GET /checkout/bt/retur' => $cerere('GET', '/checkout/bt/retur')['cod'],
                'GET /newsletter/unsubscribe/abc' => $cerere('GET', '/newsletter/unsubscribe/abc')['cod'],
            ];
            $verifica('prezentare: adresele mereu deschise, fără o plată sau un link adevărat, dau 404 ('
                . implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($faraCerere), $faraCerere)) . ')',
                array_unique(array_values($faraCerere)) === [404]);
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

    if ($scenarii !== []) {
        // Redirecționări din licență care duc la pagini ale altor pluginuri oprite: 404, nu buclă.
        $scrie('');
        $scrie('-- Redirecționări în buclă (blog → /magazin, catalog → /blog, ambele oprite)');
        $puneLicenta($pachet('prezentare'), ['redirectionari' => ['blog' => '/magazin', 'catalog' => '/blog', 'acorduri_gdpr' => '/contact']]);
        $bucla = [$cerere('GET', '/blog'), $cerere('GET', '/magazin'), $cerere('GET', '/acorduri-gdpr')];
        $verifica('redirecționarea spre o pagină ascunsă dă 404 (nu buclă), cea spre o pagină vizibilă merge',
            $bucla[0]['cod'] === 404 && $bucla[1]['cod'] === 404 && $bucla[2]['cod'] === 302
            && ($bucla[2]['antete']['location'] ?? '') === '/contact',
            $bucla[0]['cod'] . ' ' . $bucla[1]['cod'] . ' ' . $bucla[2]['cod'] . ' ' . ($bucla[2]['antete']['location'] ?? ''));
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

    // Harta site-ului: /sitemap.xml se construiește din bază la fiecare cerere, doar
    // cu paginile pluginurilor de pe site (docs/SEO.md); butonul din admin doar
    // verifică lista și șterge un fișier static vechi.
    $puneLicenta($pachet('toate'));
    $fisierHarta = $radacina . '/public/sitemap.xml';
    $hartaExista = is_file($fisierHarta);
    @unlink($jar);
    $cerere('POST', '/admin/login', $jar, ['email' => $adminEmail, 'password' => $parolaAdmin]);
    $cerere('POST', '/admin/settings/store', $jar, ['action' => 'generate_sitemap', 'sitemap_filename' => 'sitemap.xml']);
    $hartaGenerata = $cerere('GET', '/sitemap.xml');
    $verifica('harta site-ului construită din bază (/sitemap.xml), fără fișier static în public/', !is_file($fisierHarta)
        && $hartaGenerata['cod'] === 200 && str_contains($hartaGenerata['corp'], '<urlset'));

    $nodePath = (string) (getenv('NODE_PATH') ?: '/opt/node22/lib/node_modules');
    $areBrowser = !in_array('--fara-browser', $argv, true) && is_dir($nodePath . '/playwright');
    $dosarCapturi = $dosarDev . '/capturi';
    foreach ($argv as $argument) {
        if (str_starts_with((string) $argument, '--capturi=')) {
            $dosarCapturi = rtrim(substr((string) $argument, 10), '/');
        }
    }
    $eroriJsToate = [];
    $ultimaEroareBrowser = '';

    // Semnele unui plugin în textul vizibil (ce vede omul pe ecran, după JavaScript):
    // etichete și explicații ale pluginului, nu date. Datele vechi rămân (de exemplu
    // „Reducere cupon (COD)” pe o comandă veche, ca totalul să se lege).
    $semneText = [
        'catalog' => ['/Categorii produse/u', '/Vezi toate produsele/u', '/Câmpuri suplimentare/u'],
        // Avertismentul pe produs (Bioscem 333ba9e, fa76c4f): cardul din editorul produsului și codul din emailuri.
        'magazin' => ['/Adaugă în coș/u', '/Finalizează comanda/u', '/Comenzi recente/u', '/Numerotare comenzi/u', '/Coș flotant/u',
            '/Avertisment la coș/u', '/Avertismentele confirmate/u', '/Comandă online pe/u'],
        'livrare_fan' => ['/FAN Courier/u', '/FANbox/u', '/\bAWB\b/u', '/Setări livrare/u'],
        // Procesatorii: textele lor, nu datele comenzilor vechi („euplatesc” ca metodă,
        // „ID tranzacție EuPlătesc”, „Card BT” rămân pe comenzile plătite așa).
        'plata_stripe' => ['/prin Stripe/u', '/Stripe \\(rezervă\\)/u', '/Stripe Publishable/u', '/Card bancar — Stripe/u'],
        'plata_euplatesc' => ['/prin EuPlătesc/u', '/pe EuPlătesc/u', '/procesatorului EuPlătesc/u', '/Card bancar — EuPlătesc/u',
            '/comerciant EuPlătesc/u'],
        'plata_bt' => ['/Banca Transilvania/u', '/\bBT iPay\b/u', '/\bSTAR\b/u'],
        'link_plata' => ['/Trimite link de plată/u'],
        'erp' => ['/\bERP\b/u'],
        'cupoane' => ['/Cod cupon/u', '/Cupon folosit/u', '/Cupoane unice/u'],
        'fidelitate' => ['/Puncte fidelit/u', '/Puncte fidelizare/u', '/Punctele mele/u', '/Reducere puncte/u', '/puncte la această comandă/u'],
        'recenzii' => ['/Lasă o recenzie/u', '/\bRecenzii\b/u'],
        'precomenzi' => ['/Precomand[ăa]/u', '/Precomenzi/u'],
        'oferte_bbd' => ['/\bBBD\b/u', '/Sertar Oferte/u', '/Sertar lateral „Oferte”/u'],
        'produse_promo' => ['/[Pp]romoțional/u'],
        'conturi_clienti' => ['/Contul meu/u', '/Creează cont/u', '/Listă utilizatori/u', '/Setări utilizatori/u'],
        'blog' => ['/\bBlog\b/u', '/Articole recente/u', '/Pornește videoclipul/u'],
        'newsletter' => ['/[Nn]ewsletter/u', '/[Aa]bonaț/u', '/opt-in/u'],
        'chat_live' => ['/tawk/iu', '/Chat live/u'],
        'manechin' => ['/[Mm]anechin/u'],
        'acorduri_gdpr' => ['/Acorduri GDPR/iu'],
        'migrare_wordpress' => ['/backup WordPress/u', '/UpdraftPlus/u'],
    ];
    /** Semnele pluginurilor oprite găsite în textul paginilor (fiecare pagină: cale, rol, text). */
    $problemeText = static function (array $pagini) use ($semneText): array {
        $oprite = array_keys(array_filter(Plugins::stari(), static fn (string $s): bool => $s === 'oprit'));
        $probleme = [];
        foreach ($pagini as $p) {
            $text = (string) ($p['text'] ?? '');
            foreach ($oprite as $plugin) {
                foreach ($semneText[$plugin] ?? [] as $semn) {
                    if (preg_match($semn, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                        $context = trim((string) preg_replace('/\s+/u', ' ', substr($text, max(0, $m[0][1] - 50), 120)));
                        $probleme[] = $p['rol'] . ' ' . $p['cale'] . ': „' . $m[0][0] . '” (' . $plugin . ' oprit) … ' . $context . ' …';
                    }
                }
            }
        }
        return array_values(array_unique($probleme));
    };
    /**
     * Trece cu Chromium prin paginile date (vizitator), prin meniul adminului cu
     * ferestrele comenzilor și ale produselor și, dacă site-ul are conturi, prin
     * contul clientului (cu un produs în coș, cât magazinul primește comenzi).
     */
    $treceCuBrowserul = static function (array $public, array $admin, string $sufixCapturi) use (
        $dosarDev, $baza, $adminEmail, $parolaAdmin, $nodePath, $radacina, &$dosarCapturi, &$ultimaEroareBrowser, $unu
    ): ?array {
        $fisierPagini = $dosarDev . '/crawl-pagini-' . getmypid() . '.json';
        $fisierRezultat = $dosarDev . '/crawl-rezultat-' . getmypid() . '.json';
        $intrare = ['public' => $public, 'admin' => $admin, 'ferestre' => $admin !== []];
        if (Plugins::vizibil('conturi_clienti')) {
            $intrare['client'] = ['email' => 'andrei.exemplu@exemplu.ro', 'pagini' => ['/contul-meu', '/contul-meu?section=orders',
                '/contul-meu?section=points', '/contul-meu?section=addresses']];
            if (Plugins::permiteInceput('magazin')) {
                $intrare['client']['produs'] = $unu("SELECT id FROM products WHERE slug = 'ceai-de-menta'");
                array_push($intrare['client']['pagini'], '/cos', '/checkout');
            }
        }
        file_put_contents($fisierPagini, json_encode($intrare));
        $comanda = 'CMS_DEV_URL=' . escapeshellarg($baza) . ' CMS_DEV_ADMIN_EMAIL=' . escapeshellarg($adminEmail)
            . ' CMS_DEV_PAROLA=' . escapeshellarg($parolaAdmin) . ' CMS_DEV_PAGINI=' . escapeshellarg($fisierPagini)
            . ' CMS_DEV_REZULTAT=' . escapeshellarg($fisierRezultat) . ' CMS_DEV_CAPTURI=' . escapeshellarg($sufixCapturi !== '' ? $dosarCapturi : '')
            . ' CMS_DEV_CAPTURI_SUFIX=' . escapeshellarg($sufixCapturi) . ' NODE_PATH=' . escapeshellarg($nodePath)
            . ' node ' . escapeshellarg($radacina . '/tools/dev/crawl-pachet.cjs') . ' 2>&1';
        exec($comanda, $iesireNode, $codNode);
        $rez = json_decode((string) @file_get_contents($fisierRezultat), true);
        @unlink($fisierPagini);
        @unlink($fisierRezultat);
        if ($codNode !== 0 || !is_array($rez)) {
            $ultimaEroareBrowser = implode(' ', $iesireNode);
            return null;
        }
        return $rez;
    };
    /**
     * Paginile adminului care nu sunt în meniu, dar au text de verificat: fiecare
     * tab al setărilor magazinului (tab-urile se aleg în browser, deci se deschid
     * pe rând cu ?tab=), tab-ul BT, fișa unui client, importul de utilizatori.
     */
    $paginiAdminExtra = static function () use ($mostre): array {
        $extra = ['/admin/settings/payments?tab=bt', '/admin/users/' . $mostre['utilizator'], '/admin/users?panel=import',
            '/admin/orders/trash', '/admin/products/new'];
        foreach (['pages', 'sitemap', 'branding', 'seo', 'clarity', 'chat', 'quantity', 'widgets', 'caching', 'maintenance',
            'numerotare', 'tva', 'popup', 'cookies'] as $tab) {
            $extra[] = '/admin/settings/store?tab=' . $tab;
        }
        return array_values(array_filter($extra, static fn (string $cale): bool => Plugins::adresaVizibila($cale)));
    };
    /** Verificările pe rezultatul trecerii cu browserul: linkuri, text, erori JS, capturi. */
    $verificaBrowser = static function (array $rez, string $eticheta, bool $referinta) use (
        $verifica, $scrie, $problemeText, &$eroriJsToate
    ): void {
        $problemeDom = [];
        $eroriJs = [];
        $cuEroare = [];
        foreach ((array) $rez['pagini'] as $p) {
            $etichetaPagina = $p['rol'] . ' ' . $p['cale'];
            if ((int) ($p['status'] ?? 0) >= 500) {
                $cuEroare[] = $etichetaPagina . ': cod ' . $p['status'];
            }
            foreach ((array) $p['linkuri'] as $href) {
                if (!Plugins::adresaVizibila((string) $href)) {
                    $problemeDom[] = $etichetaPagina . ': link în DOM spre un plugin ascuns: ' . $href;
                }
            }
            foreach ((array) $p['formulare'] as [$actiune, $metoda]) {
                if (!Plugins::adresaVizibila((string) $actiune, (string) $metoda)) {
                    $problemeDom[] = $etichetaPagina . ': formular în DOM spre un plugin ascuns: ' . $metoda . ' ' . $actiune;
                }
            }
            foreach ((array) $p['erori'] as $eroare) {
                $cheie = $p['rol'] . ' ' . $p['cale'] . ' ' . $eroare;
                if ($referinta) {
                    $eroriJsToate[$cheie] = true;
                } elseif (!isset($eroriJsToate[$cheie])) {
                    // O eroare care apare și cu toate pluginurile nu e a pachetului.
                    $eroriJs[] = $etichetaPagina . ': ' . $eroare;
                }
            }
        }
        $problemeDom = array_values(array_unique(array_merge($cuEroare, $problemeDom)));
        $verifica('browser: ' . count((array) $rez['pagini']) . ' pagini și ferestre, niciun 500, niciun link/formular în DOM spre pluginuri ascunse',
            $problemeDom === [], implode("\n          ", array_slice($problemeDom, 0, 20)));
        $text = $problemeText((array) $rez['pagini']);
        $verifica('browser: textul vizibil (site, cont, admin, ferestrele comenzilor și produselor) fără semnele pluginurilor oprite',
            $text === [], implode("\n          ", array_slice($text, 0, 25)) . (count($text) > 25 ? "\n          … încă " . (count($text) - 25) : ''));
        $verifica('browser: nicio eroare JavaScript nouă față de „toate”', $eroriJs === [],
            implode("\n          ", array_slice(array_unique($eroriJs), 0, 20)));
        if ($referinta && $eroriJsToate !== []) {
            $scrie('  INFO  erori JavaScript și cu toate pluginurile (nu țin de pluginuri): '
                . implode(' | ', array_slice(array_keys($eroriJsToate), 0, 5)));
        }
        foreach ((array) $rez['capturi'] as $captura) {
            $scrie('  INFO  captura meniului: ' . $captura);
        }
        if ((array) $rez['blocate'] !== []) {
            $scrie('  INFO  cereri spre alte servere, blocate: ' . implode(', ', (array) $rez['blocate']));
        }
    };

    foreach (['toate', 'magazin', 'catalog', 'prezentare', 'asociatie'] as $numePachet) {
        $scrie('');
        $scrie('-- Pachetul „' . $numePachet . '”');
        $puneLicenta($pachet($numePachet));
        $ascunse = array_keys(array_filter(Plugins::stari(), static fn (string $s): bool => $s === 'oprit'));
        $scrie('  INFO  ascunse: ' . ($ascunse === [] ? 'niciunul' : implode(', ', $ascunse)));

        // Harta site-ului ascultă de licență la fiecare cerere (și prima cerere reface
        // un eventual fișier static, semnalul pus la schimbarea licenței).
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

        // Editoarele (pagini, șablon de produs) pun previzualizările codurilor în sursa
        // paginii, ca date pentru JavaScript: nici acolo nimic din pluginurile oprite
        // (catalogul întreg, checkout-ul cu procesatorii, punctele, recenziile…).
        $editoare = ['/admin/pages/new'];
        if (Plugins::vizibil('catalog')) {
            $editoare[] = '/admin/products/templates/' . $mostre['sablon_produs'] . '/builder';
        }
        $sursePagini = [];
        foreach ($editoare as $cale) {
            $r = $cerere('GET', $cale, $jar);
            // Doar conținutul paginii (meniul și stilurile adminului au verificările lor), fără
            // ce e de editat (textarea, al clientului), fără stiluri și comentarii; textul demo
            // al notei de sub butonul de coș e al magazinului, nu al produselor promoționale.
            $sursa = preg_match('#<main class="admin-content">(.*)</main>#s', $r['corp'], $main) === 1 ? $main[1] : $r['corp'];
            // (CSS-ul site-ului, pus în pagină pentru previzualizare, are doar nume de clase.)
            $sursa = (string) preg_replace(['#<textarea\b[^>]*>.*?</textarea>#is', '#<style\b[^>]*>.*?</style>#is', '#/\*.*?\*/#s', '#<!--.*?-->#s',
                '#const previewAppCssContent = [^\n]*#'], '', $sursa);
            $sursa = str_replace('Text promoțional sub butonul de comandă', '', $sursa);
            $sursePagini[] = ['rol' => 'sursa', 'cale' => $cale . ' (' . $r['cod'] . ')', 'text' => html_entity_decode($sursa, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        }
        $faraProduse = Plugins::vizibil('catalog') || !str_contains($sursePagini[0]['text'], 'Ceai de mentă');
        $text = $problemeText($sursePagini);
        $verifica('sursa editoarelor (pagini, șablon de produs): fără previzualizările și codurile pluginurilor oprite',
            $text === [] && $faraProduse, implode("\n          ", array_slice($text, 0, 15)) . ($faraProduse ? '' : ' (catalogul în sursă)'));

        // Cu browserul: aceleași linkuri după ce rulează JavaScript-ul, textul vizibil
        // (și în ferestrele comenzilor și ale produselor, și în contul clientului), plus erorile JS.
        if ($areBrowser) {
            $rez = $treceCuBrowserul(array_values(array_unique(array_merge(['/'], array_slice($vizitatePublic, 0, 35)))),
                array_values(array_unique(array_merge($paginiMeniu, $paginiAdminExtra()))), '-' . $numePachet);
            if ($rez === null) {
                $verifica('browser: trecerea cu Playwright', false, $ultimaEroareBrowser);
            } else {
                $verificaBrowser($rez, $numePachet, $numePachet === 'toate');
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

    // ERP „se oprește”: comenzile plasate înainte de oprire (plătite sau confirmate
    // abia acum) pleacă în ERP; doar cele plasate după oprire stau „în așteptare”.
    // ERP-ul de dezvoltare e gaura neagră (127.0.0.1:9): „a încercat” = eroare de legătură, nu „ținută”.
    \App\Support\ErpSync::ensureSchema($db);
    $erpVechi = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('erp_enabled')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $randErp = $db->query('SELECT erp_status, erp_last_error, erp_next_retry_at, erp_attempts, erp_order_id, created_at FROM orders WHERE id = '
        . $idComanda)->fetch(PDO::FETCH_ASSOC);
    \App\Support\Settings::save($db, ['erp_enabled' => '1']);
    $puneErpDeTrimis = static function () use ($db, $idComanda): void {
        $db->prepare("UPDATE orders SET erp_status = 'pending', erp_order_id = NULL, erp_next_retry_at = NULL WHERE id = :id")
            ->execute(['id' => $idComanda]);
    };
    $puneErpDeTrimis();
    $impins = \App\Support\ErpSync::push($db, $idComanda, true);
    $verifica('se oprește (magazin și erp): o comandă plasată înainte de oprire pleacă în continuare în ERP (nu e ținută)',
        ($impins['tinuta'] ?? false) === false && !str_contains((string) ($impins['message'] ?? ''), 'se oprește'),
        json_encode($impins, JSON_UNESCAPED_UNICODE));
    // Aceeași comandă, ca și cum ar fi fost plasată după trecerea ERP-ului în „se oprește”.
    $puneErpDeTrimis();
    $db->prepare('UPDATE orders SET created_at = :c WHERE id = :id')->execute(['c' => date('Y-m-d H:i:s', time() + 1), 'id' => $idComanda]);
    $impins = \App\Support\ErpSync::push($db, $idComanda, true);
    $stareErp = (string) $db->query('SELECT erp_status FROM orders WHERE id = ' . $idComanda)->fetchColumn();
    $verifica('se oprește (erp): o comandă plasată după oprire nu mai pleacă, rămâne „în așteptare”', ($impins['tinuta'] ?? false) === true
        && $stareErp === 'pending' && str_contains((string) $impins['message'], 'se oprește'), json_encode($impins, JSON_UNESCAPED_UNICODE));
    // Doar magazinul se oprește (ERP-ul e „pornit” în licență): checkout-ul e închis, tot ce e pe site pleacă.
    $puneLicenta(array_merge($pachet('toate'), ['magazin' => 'se_opreste']));
    $puneErpDeTrimis();
    $impins = \App\Support\ErpSync::push($db, $idComanda, true);
    $verifica('se oprește doar magazinul (ERP pornit în licență): comenzile de pe site pleacă în ERP, oricând ar fi plasate',
        Plugins::stare('erp') === 'se_opreste' && ($impins['tinuta'] ?? false) === false, json_encode($impins, JSON_UNESCAPED_UNICODE));
    $db->prepare('UPDATE orders SET created_at = :c WHERE id = :id')->execute(['c' => $randErp['created_at'], 'id' => $idComanda]);
    unset($randErp['created_at']);
    $puneLicenta($pachet('magazin'));
    $impins = \App\Support\ErpSync::push($db, $idComanda, true);
    $verifica('oprit (erp): nicio legătură cu ERP-ul, comanda rămâne doar pe site', ($impins['ok'] ?? true) === false
        && str_contains((string) $impins['message'], 'nu este inclusă în abonament') && \App\Support\ErpClient::fromSettings(['erp_url' => 'x', 'erp_api_key' => 'y']) === null,
        json_encode($impins, JSON_UNESCAPED_UNICODE));
    // Setări livrare (FAN e pe site) nu mai spune că numărul de colete se schimbă din ERP.
    @unlink($jar);
    $cerere('POST', '/admin/login', $jar, ['email' => $adminEmail, 'password' => $parolaAdmin]);
    $livrareFaraErp = $cerere('GET', '/admin/settings/shipping', $jar);
    $verifica('oprit (erp): Setări livrare fără fraza despre coletele alese în ERP', $livrareFaraErp['cod'] === 200
        && str_contains($livrareFaraErp['corp'], "0 = fără împărțire automată.</small>")
        && !str_contains($livrareFaraErp['corp'], 'fiecare comandă din ERP'), 'cod ' . $livrareFaraErp['cod']);
    $db->prepare('UPDATE orders SET erp_status = :erp_status, erp_last_error = :erp_last_error, erp_next_retry_at = :erp_next_retry_at,
        erp_attempts = :erp_attempts, erp_order_id = :erp_order_id WHERE id = :id')->execute($randErp + ['id' => $idComanda]);
    \App\Support\Settings::save($db, ['erp_enabled' => (string) ($erpVechi['erp_enabled'] ?? '0')]);

    // Cronurile: cele care termină ce e în curs rulează, cele care pornesc ceva nou tac.
    $puneLicenta(array_merge($pachet('toate'), ['magazin' => 'se_opreste', 'newsletter' => 'se_opreste']));
    $bt = $scriptCron('scripts/bt-ipay-sync.php');
    $verifica('se oprește: cronul BT (termină plățile începute) rulează în continuare', !str_contains($bt['text'], 'nu este pornit'), $bt['text']);
    // AWB-urile și ERP-ul comenzilor deja plasate merg mai departe (FAN și ERP depind de magazin, deci „se opresc” și ele).
    foreach (['scripts/fan-tracking-sync.php' => 'urmărirea AWB-urilor FAN', 'scripts/erp-sync.php' => 'sincronizarea cu ERP-ul'] as $script => $ce) {
        $rez = $scriptCron($script);
        $verifica('se oprește: ' . $ce . ' (' . $script . ') rulează în continuare', !str_contains($rez['text'], 'nu este pornit'),
            substr($rez['text'], 0, 300));
    }
    $abandonat = $scriptCron('scripts/abandoned-cart-emails.php');
    $verifica('se oprește: cronul coșurilor abandonate tace (exit 0, o linie)', $abandonat['cod'] === 0
        && str_contains($abandonat['text'], 'nu este pornit') && $abandonat['linii'] === 1, $abandonat['text']);
    $nl = $scriptCron('scripts/newsletter-campaigns.php');
    $verifica('se oprește: cronul newsletterului termină campaniile pornite, nu pornește programate', $nl['cod'] === 0
        && str_contains($nl['text'], 'programate_pornite=0'), $nl['text']);
    // Magazinul fără livrarea FAN: checkout-ul nu mai cere localitățile FAN și nu
    // oferă FANbox; o comandă cu localitatea scrisă de mână intră ca oricare alta.
    // Regula coletelor din comanda trimisă în ERP („coleteCalcul”) e a livrării FAN:
    // pleacă și cât FAN „se oprește” (comenzile existente își fac AWB-ul), lipsește fără FAN.
    $randComanda = (array) $db->query('SELECT * FROM orders WHERE id = ' . $idComanda)->fetch(PDO::FETCH_ASSOC);
    $stareFan = Plugins::stare('livrare_fan');
    $coleteCuFan = \App\Support\ErpSync::buildPayload($db, $randComanda)['coleteCalcul'] ?? null;
    $puneLicenta(array_merge($pachet('toate'), ['livrare_fan' => 'oprit']));
    $cerere('GET', '/health');
    $payloadFaraFan = \App\Support\ErpSync::buildPayload($db, $randComanda);
    $verifica('regula coletelor pleacă în ERP doar cu FAN pe site (și cât FAN se oprește)', $stareFan === 'se_opreste'
        && is_array($coleteCuFan) && array_keys($coleteCuFan) === ['tip', 'kgMaxColet', 'coleteMinim', 'greutateImplicitaKg', 'unSingurAwb']
        && !array_key_exists('coleteCalcul', $payloadFaraFan) && ($payloadFaraFan['numarSite'] ?? '') !== '',
        'FAN ' . $stareFan . ', cu FAN ' . json_encode($coleteCuFan) . ', fără FAN ' . (array_key_exists('coleteCalcul', $payloadFaraFan) ? 'are cheia' : 'fără cheie'));
    // Niciun AWB cerut la FAN: nici din aprobarea venită din ERP, nici din acțiunea în masă.
    $adminController = new \App\Http\Controllers\AdminController();
    $creeazaAwb = new ReflectionMethod($adminController, 'createFanAwbInternal');
    $creeazaAwb->setAccessible(true);
    $awbFaraFan = $creeazaAwb->invoke($adminController, $db, $idComanda);
    @unlink($jar);
    $cerere('POST', '/admin/login', $jar, ['email' => $adminEmail, 'password' => $parolaAdmin]);
    $inMasa = $cerere('POST', '/admin/orders/bulk', $jar, ['bulk_action' => 'awb', 'order_ids' => [$idComanda]]);
    $export = $cerere('GET', '/admin/orders/export', $jar);
    $antetExport = (string) strtok(ltrim($export['corp'], "\xEF\xBB\xBF"), "\n");
    $verifica('fără FAN: niciun AWB (aprobarea din ERP nu cere nimic de la FAN, acțiunea în masă dă „Modulul nu este inclus”), exportul fără AWB',
        ($awbFaraFan['ok'] ?? true) === false && ($awbFaraFan['message'] ?? 'x') === '' && $inMasa['cod'] === 403
        && str_contains($inMasa['corp'], 'Modulul nu este inclus în abonament') && $export['cod'] === 200 && !str_contains($antetExport, 'AWB')
        && str_contains($antetExport, 'Cupon'), json_encode($awbFaraFan, JSON_UNESCAPED_UNICODE) . '; bulk ' . $inMasa['cod'] . '; antet ' . $antetExport);
    $jarFaraFan = $dosarDev . '/test-pluginuri-farafan-' . getmypid() . '.txt';
    @unlink($jarFaraFan);
    $cerere('POST', '/cos/adauga/' . $idMenta, $jarFaraFan, ['quantity' => '1']);
    $pagCheckout = $cerere('GET', '/checkout', $jarFaraFan)['corp'];
    $verifica('fără FAN: checkout-ul nu cere localitățile FAN și nu oferă FANbox',
        str_contains($pagCheckout, 'const localitiesEndpoint = "";') && !str_contains($pagCheckout, '/api/fan/localities')
        && !str_contains($pagCheckout, 'name="livrare_fanbox"') && str_contains($pagCheckout, 'name="payment_method"'),
        'endpoint gol: ' . (str_contains($pagCheckout, 'const localitiesEndpoint = "";') ? 'da' : 'nu')
        . ', /api/fan/localities: ' . (str_contains($pagCheckout, '/api/fan/localities') ? 'da' : 'nu')
        . ', FANbox: ' . (str_contains($pagCheckout, 'name="livrare_fanbox"') ? 'da' : 'nu'));
    preg_match('/name="checkout_form_token" value="([^"]*)"/', $pagCheckout, $tokenFaraFan);
    preg_match('/name="checkout_form_rendered_at" value="([^"]*)"/', $pagCheckout, $randatFaraFan);
    sleep(3); // garda anti-bot cere câteva secunde între afișare și trimitere
    $ultimaComanda = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn();
    $mailuriInainte = glob($dosarDev . '/mail/*') ?: [];
    $trimis = $cerere('POST', '/checkout', $jarFaraFan, [
        'checkout_form_token' => $tokenFaraFan[1] ?? '', 'checkout_form_rendered_at' => $randatFaraFan[1] ?? '',
        'billing_first_name' => 'Test', 'billing_last_name' => 'Fara FAN', 'billing_phone' => '0700000778',
        'billing_email' => 'fara-fan@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '8',
        'billing_city' => 'Satu Inventat', 'billing_county' => 'Cluj', 'billing_postcode' => '400001',
        'shipping_same_as_billing' => '1', 'payment_method' => 'cod', 'accept_terms' => '1',
    ]);
    $comandaFaraFan = $db->query('SELECT id, billing_city, shipping_method FROM orders WHERE id > ' . $ultimaComanda
        . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $verifica('fără FAN: o comandă cu localitatea scrisă de mână intră (ramburs, regulile generale de transport)',
        $trimis['cod'] === 302 && str_starts_with((string) ($trimis['antete']['location'] ?? ''), '/checkout/succes/')
        && is_array($comandaFaraFan) && ($comandaFaraFan['billing_city'] ?? '') === 'Satu Inventat',
        'cod ' . $trimis['cod'] . ' → ' . ($trimis['antete']['location'] ?? '') . '; ' . json_encode($comandaFaraFan));
    // Curățenie: comanda de test, emailurile ei, coșul.
    if (is_array($comandaFaraFan)) {
        foreach (['order_items', 'email_logs'] as $tabel) {
            $db->exec('DELETE FROM ' . $tabel . ' WHERE order_id = ' . (int) $comandaFaraFan['id']);
        }
        $db->exec('DELETE FROM orders WHERE id = ' . (int) $comandaFaraFan['id']);
    }
    array_map('unlink', array_diff(glob($dosarDev . '/mail/*') ?: [], $mailuriInainte));
    @unlink($jarFaraFan);

    // Cupoanele și punctele „se opresc” (magazinul e pornit): nu se mai aplică unei
    // comenzi noi, iar comenzile vechi își păstrează cuponul și reducerea.
    $puneLicenta(array_merge($pachet('toate'), ['cupoane' => 'se_opreste', 'fidelitate' => 'se_opreste']));
    $cerere('GET', '/health');
    $jarCupon = $dosarDev . '/test-pluginuri-cupon-' . getmypid() . '.txt';
    @unlink($jarCupon);
    $cerere('POST', '/cos/adauga/' . $idMenta, $jarCupon, ['quantity' => '2']);
    $cupon = $cerere('POST', '/api/cart/coupon/apply', $jarCupon, ['coupon_code' => 'DEMO10']);
    $cerere('POST', '/cos/puncte', $jarCupon, ['points' => '10']);
    $sumarCupon = json_decode($cerere('GET', '/api/cart/summary', $jarCupon)['corp'], true);
    $cosCupon = $cerere('GET', '/cos', $jarCupon);
    @unlink($jarCupon);
    $verifica('se oprește (cupoane, puncte): cuponul și punctele nu se mai aplică unei comenzi noi, câmpul de cupon dispare',
        $cupon['cod'] === 409 && is_array($sumarCupon) && trim((string) ($sumarCupon['coupon']['code'] ?? '')) === ''
        && (float) ($sumarCupon['points_discount'] ?? 0) === 0.0 && !str_contains($faraCod($cosCupon['corp']), 'Cod cupon'),
        'cupon ' . $cupon['cod'] . ' ' . $cupon['corp'] . '; sumar ' . substr(json_encode($sumarCupon), 0, 200));
    $nrCuCupon = $unu("SELECT order_number FROM orders WHERE coupon_code = 'DEMO10' AND deleted_at IS NULL ORDER BY id LIMIT 1");
    $comenziCupon = $cerere('GET', '/admin/orders?search=' . rawurlencode($nrCuCupon), $jar);
    $verifica('se oprește (cupoane): o comandă veche își păstrează cuponul în admin',
        $comenziCupon['cod'] === 200 && str_contains($comenziCupon['corp'], 'DEMO10'));

    // Un link de plată deja trimis prin EuPlătesc, cu EuPlătesc scos din licență (și BT
    // nefolosibil pentru linkuri în modul test): pagina se vede, dar nu mai pornește plata.
    $puneLicenta(array_merge($pachet('toate'), ['plata_euplatesc' => 'oprit']));
    $cerere('GET', '/health');
    $tokenLink = $unu("SELECT token FROM order_payment_links WHERE status = 'pending' ORDER BY id LIMIT 1");
    $paginaLink = $cerere('GET', '/plata/' . $tokenLink);
    $pornire = $cerere('POST', '/plata/' . $tokenLink);
    $verifica('link de plată cu procesatorul scos din licență: pagina cere contactul, plata nu pornește',
        $paginaLink['cod'] === 200 && str_contains($paginaLink['corp'], 'Plata online a acestui link nu mai este')
        && !str_contains($faraCod($paginaLink['corp']), 'Plătește cu cardul') && !str_contains($paginaLink['corp'], 'EuPlătesc')
        && $pornire['cod'] === 302 && ($pornire['antete']['location'] ?? '') === '/plata/' . $tokenLink,
        'GET ' . $paginaLink['cod'] . ', POST ' . $pornire['cod'] . ' → ' . ($pornire['antete']['location'] ?? ''));

    $eroriPhp = $eroriPhpNoi();
    $verifica('jurnalul PHP: niciun avertisment sau eroare nouă (D)', $eroriPhp === [], implode("\n          ", array_slice($eroriPhp, 0, 15)));

    // =================================================================
    // F. (opțional, --fiecare-plugin) Toate pluginurile pornite, în afară de
    //    unul: niciun semn al lui (și al celor care depind de el) pe site, în
    //    cont, în admin sau în ferestrele comenzilor și ale produselor
    // =================================================================
    if (in_array('--fiecare-plugin', $argv, true) && $areBrowser) {
        $scrie('');
        $scrie('== F. Câte un plugin oprit, restul pornite (textul și linkurile din browser)');
        $publicFix = ['/', '/magazin', '/produs/ceai-de-menta', '/produs/' . $unu("SELECT slug FROM products WHERE bbd_enabled = 1 ORDER BY id LIMIT 1"),
            '/produs/' . $unu("SELECT slug FROM products WHERE preorder_enabled = 1 ORDER BY id LIMIT 1"), '/cos', '/checkout', '/contact',
            '/blog', '/blog/' . $mostre['blog'], '/termeni-si-conditii', '/pagina-care-nu-exista', '/plata/' . $mostre['link'], '/acorduri-gdpr'];
        foreach (Plugins::chei() as $cheie) {
            $definitie = Plugins::definitie($cheie);
            if (($definitie['portat'] ?? true) === false) {
                continue;
            }
            $puneLicenta(array_merge($pachet('toate'), [$cheie => 'oprit']));
            $cerere('GET', '/health');
            $eroriPhpNoi();
            @unlink($jar);
            $cerere('POST', '/admin/login', $jar, ['email' => $adminEmail, 'password' => $parolaAdmin]);
            $panou = $cerere('GET', '/admin', $jar);
            preg_match('#<nav class="admin-nav">(.*?)</nav>#s', $panou['corp'], $nav);
            preg_match_all('#href="([^"]+)"#', $nav[1] ?? '', $hrefuriMeniu);
            $meniu = array_values(array_unique(array_map(static fn ($h): string => html_entity_decode((string) $h), $hrefuriMeniu[1])));
            $oprite = array_keys(array_filter(Plugins::stari(), static fn (string $st): bool => $st === 'oprit'));
            $scrie('-- fără „' . $cheie . '” (oprite: ' . implode(', ', array_diff($oprite, ['pachete_produse', 'donatii', 'membri',
                'cereri_oferta', 'fisiere_cloud', 'concurenta'])) . ')');
            $rez = $treceCuBrowserul($publicFix, array_values(array_unique(array_merge($meniu, $paginiAdminExtra()))), '');
            if ($rez === null) {
                $verifica('browser: trecerea cu Playwright', false, $ultimaEroareBrowser);
                continue;
            }
            $verificaBrowser($rez, $cheie, false);
            $eroriPhp = $eroriPhpNoi();
            $verifica('jurnalul PHP: niciun avertisment sau eroare nouă', $eroriPhp === [], implode("\n          ", array_slice($eroriPhp, 0, 15)));
        }
    }
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
    $scrie('== E. Licența „toate” și licența Bioscem: HTML identic cu codul Bioscem (tools/dev/compara-cu-baza.sh)');
    foreach (['toate' => '', 'bioscem (fără unealta internă)' => ' --licenta=bioscem'] as $numeLicenta => $optiune) {
        $iesireBaza = [];
        exec(escapeshellarg($radacina . '/tools/dev/compara-cu-baza.sh') . $optiune . ' 2>&1', $iesireBaza, $codBaza);
        $ultima = trim((string) end($iesireBaza));
        $diferite = array_filter($iesireBaza, static fn (string $l): bool => !str_starts_with(trim($l), 'OK') && !str_starts_with(trim($l), 'INFO')
            && trim($l) !== '');
        $verifica('licența ' . $numeLicenta . ': ' . ($ultima !== '' ? $ultima : 'compara-cu-baza'), $codBaza === 0,
            implode("\n          ", array_slice($diferite, 0, 20)));
    }
}

// =================================================================
// G. SEO: adresa unică, redirecționările, sitemap, robots, JSON-LD
//    (tools/dev/test-seo.php, pe fiecare pachet)
// =================================================================
if (!in_array('--fara-seo', $argv, true)) {
    $scrie('');
    $scrie('== G. SEO (tools/dev/test-seo.php: adresa unică, harta, sitemap, robots.txt, JSON-LD, pe fiecare pachet)');
    exec(escapeshellarg($radacina . '/tools/dev/php.sh') . ' tools/dev/test-seo.php 2>&1', $iesireSeo, $codSeo);
    foreach ($iesireSeo as $linie) {
        if (str_starts_with($linie, '  EȘEC') || str_starts_with($linie, 'Rezultat:') || str_starts_with($linie, 'EROARE')) {
            $scrie('  ' . trim($linie));
        }
    }
    $verifica('testul SEO (raport: ' . $dosarDev . '/test-seo-raport.txt)', $codSeo === 0);
}

// =================================================================
// T. Tema site-ului (WP-13, GR-18): fiecare cheie din tema.json, cu pachetul inventat tools/dev/site-demo-tema;
//    fără temă (sau cu fiecare cheie la implicit) paginile rămân octet cu octet aceleași (docs/PACHETE.md)
// =================================================================
if (!in_array('--fara-tema', $argv, true)) {
    require __DIR__ . '/lib/test-tema.php';
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
