<?php

declare(strict_types=1);

/**
 * Poarta G-pachet (planul Etapei 1, §2.5; de-bioscem §7.4, DB-04): ce face pachetul unui site în afara HTML-ului
 * comparat de compara-cu-baza.sh, și că nucleul nu mai arată nimic al unui client.
 *
 *   tools/dev/php.sh tools/dev/test-pachet-site.php bioscem   # pachetul Bioscem dă exact valorile codului Bioscem
 *   tools/dev/php.sh tools/dev/test-pachet-site.php neutru    # pachetul demo: niciun nume de client, nicăieri
 *   tools/dev/php.sh tools/dev/test-pachet-site.php biovitality  # pachetul Biovitality dă valorile codului Biovitality, plus
 *                                                            # baza în forma Biovitality servită cu el (tools/dev/lib/test-pachet-biovitality.php)
 *
 * **bioscem** (fără server; pachetul site-uri/bioscem/ și înghețarea lui, cu valorile private din privat/, pe care le
 * scrie local tools/inghetare/genereaza-setari.php): fiecare valoare se compară cu cea scrisă în codul Bioscem însuși, la
 * „ultimul commit revizuit” din docs/de-portat.md (clona CMS_DEV_REPO_BS, citită cu git show; nimic retastat aici):
 *  B1 numele cookie-ului acordului (CookieConsent::nume, site.json) = constanta COOKIE a codului Bioscem;
 *  B2 cheia popup-ului închis (Brand::cheiePopupInchis, site.json) = cheia din views/layout.php al Bioscem;
 *  B3 numele site-ului și al expeditorului (Brand) pe o bază Bioscem fără valori (rândul lipsă, rândul gol): „Bioscem Shop”
 *     din seo.json și numele expeditorului din implicitele codului Bioscem, prin înghețare (lipsa_sau_gol);
 *  B4 iconițele categoriilor (tema.json → iconite_categorii) = HomeSections::CATEGORY_ICONS al Bioscem, în ordine, iar
 *     iconița implicită a nucleului = cea „default” a Bioscem; o categorie „Plasma Quinton” primește iconița „quinton”;
 *  B5 logo-ul sertarului de meniu (tema.json → logo_meniu_mobil) = candidații, implicitul și textul alternativ ai Bioscem;
 *  B6 stilurile paginii de mulțumire (tema/pagina-succes.css) = blocul <style> din views/site/checkout-success.php al
 *     Bioscem, octet cu octet;
 *  B7 descrierea unui produs fără descriere scurtă (înghețarea) = textul din catalogul și din caruselul Bioscem;
 *  B8 fereastra de bun venit (înghețarea) = implicitele Bioscem (pornită, titlul, textul);
 *  B9 pachetul e întreg (manifestul) și /health îl arată (PachetSite::amprenta);
 *  B10 (WP-17) tokenul de dezabonare din newsletter = formula veche, cu sarea din site.json = cea din codul Bioscem;
 *  B11 textele plății BT („Comanda <nr> …”, diferența, plata de test) = cele scrise în codul Bioscem;
 *  B12 formatul vechi al numerelor de comandă are prefixul codului Bioscem, contorul pornește de la implicitul lui;
 *  B13 cota de TVA implicită (și cea trimisă în ERP pentru un produs fără cotă) = implicitul Bioscem (19);
 *  B14 emailul comenzii cu ordin de plată: corpul și blocurile înghețate sunt cele vechi, iar ale nucleului diferă doar
 *      prin sloganul scos;
 *  B15 (WP-18) acordul GDPR: varianta „medical”, fiecare valoare înghețată (și cele private) e în textul acordului din codul
 *      Bioscem, cele personale sunt private, iar fraza operatorului și cea a retragerii, scrise de codul comun cu valorile
 *      înghețate, sunt rândurile codului Bioscem (pagina întreagă o compară G-cmp, /acorduri-gdpr).
 *
 * **neutru** (pachetul tools/dev/site-demo, baza de dezvoltare, un server al codului curent pe CMS_DEV_PORT+24): nicio
 * potrivire din lista neagră a de-bioscem §7.4 (numele Bioscem, firma și reprezentantul de pe acordul GDPR, conferința,
 * produsele și categoriile Bioscem, sloganul) în:
 *  N1 Settings::DEFAULTS, poza de rezervă a produselor, numele site-ului și al expeditorului fără setări, numele
 *     cookie-ului și al cheii popup-ului (cele implicite), iconița unei categorii fără temă (cea implicită);
 *  N2 paginile publice (cu antetul de rezervă, apoi cu un antet care are sertarul de meniu; vederile de rezervă 404 și
 *     mulțumirea, cu paginile lor din bază nepublicate), robots.txt, sitemap.xml și paginile din admin cu texte schimbate;
 *  N3 fiecare email de test din Email-uri → Trimite email test (cu numele expeditorului gol, deci cel de rezervă);
 *  N4 (WP-18) acordul GDPR fără datele operatorului (spații de completat, varianta „general”), blocul lor din admin și
 *     salvarea lor (valori inventate; varianta „medical”, adresa de retragere implicită, o variantă necunoscută = „general”).
 * Ce mai rămâne de scos în WP-urile următoare stă în $ramase (pagina sau emailul, termenul, WP-ul): acolo potrivirea
 * TREBUIE să existe încă (altfel lista nu mai e la zi și pică), iar lista doar scade.
 *
 * Pe durata lui N2–N3, în baza de dezvoltare: antetul, paginile „404” și „checkout/succes” nepublicate, numele magazinului
 * și al expeditorului goale; la final totul pus la loc (verificat) și serverul oprit. Cod de ieșire: 0 = trecut, 1 = nu.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$mod = (string) ($argv[1] ?? '');
if (!in_array($mod, ['bioscem', 'biovitality', 'neutru'], true)) {
    fwrite(STDERR, "folosire: tools/dev/php.sh tools/dev/test-pachet-site.php bioscem|biovitality|neutru\n");
    exit(2);
}
// Pachetul încercat se alege înaintea primei citiri (DateSite ține minte dosarul).
putenv('SITE_DATE_DOSAR=' . ($mod !== 'neutru' ? $radacina . '/site-uri/' . $mod : $radacina . '/tools/dev/site-demo'));
$_ENV['SITE_DATE_DOSAR'] = getenv('SITE_DATE_DOSAR');
require_once $radacina . '/bootstrap.php';
require_once __DIR__ . '/lib/salvari-admin.php';

use App\Support\Brand;
use App\Support\CookieConsent;
use App\Support\Database;
use App\Support\DateSite;
use App\Support\HomeSections;
use App\Support\Migrari\Inghetare;
use App\Support\PachetSite;
use App\Support\Settings;
use App\Support\Tema;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$esecuri = 0;
$verificari = 0;
$scrie = static function (string $linie): void {
    echo $linie, "\n";
};
$verifica = static function (string $ce, bool $ok, string $detalii = '') use (&$esecuri, &$verificari, $scrie): void {
    $verificari++;
    if (!$ok) {
        $esecuri++;
    }
    $scrie(($ok ? '  OK    ' : '  EȘEC  ') . $ce . (!$ok && $detalii !== '' ? ' — ' . $detalii : ''));
};
DateSite::reseteaza();
Tema::reseteaza();

/**
 * Lista neagră (de-bioscem §7.4; tools/dev/lib/lista-neagra.php: termenii firmei și, din privat/, cei personali, OD-49),
 * fiecare termen ca un cuvânt întreg (altfel „rațiu” ar prinde „operațiune”).
 */
require_once __DIR__ . '/lib/lista-neagra.php';
$GLOBALS['listaNeagra'] = lista_neagra($radacina);
/** @return list<string> termenii din lista neagră găsiți în text */
function potriviri_lista_neagra(string $text): array
{
    $gasite = [];
    foreach ((array) $GLOBALS['listaNeagra'] as $termen) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($termen, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) {
            $gasite[] = $termen;
        }
    }
    return $gasite;
}

// =====================================================================
// bioscem
// =====================================================================
if ($mod === 'bioscem') {
    $scrie('== G-pachet bioscem: pachetul site-uri/bioscem/ dă valorile codului Bioscem');
    $clona = (string) (getenv('CMS_DEV_REPO_BS') ?: dirname($radacina) . '/Nou-site-bioscem');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/de-portat.php') . ' --revizuit=bioscem 2>/dev/null', $iesire, $cod);
    $commit = trim(implode('', $iesire));
    if ($cod !== 0 || preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1 || !is_dir($clona)) {
        $scrie('EROARE: nu găsesc clona Bioscem (' . $clona . ') sau „ultimul commit revizuit” din docs/de-portat.md');
        exit(1);
    }
    $scrie('  INFO  codul Bioscem: ' . basename($clona) . '@' . $commit);
    try {
    /** Un fișier al codului Bioscem la commit-ul revizuit. */
    $vechi = static function (string $fisier) use ($clona, $commit): string {
        $text = shell_exec('git -C ' . escapeshellarg($clona) . ' show ' . escapeshellarg($commit . ':' . $fisier) . ' 2>/dev/null');
        if (!is_string($text) || $text === '') {
            throw new RuntimeException('codul Bioscem nu are ' . $fisier . ' la ' . $commit);
        }
        return $text;
    };
    /** Grupul 1 al unui tipar din codul Bioscem (exact o valoare). */
    $dinVechi = static function (string $fisier, string $tipar) use ($vechi): string {
        preg_match_all($tipar, $vechi($fisier), $m);
        $valori = array_values(array_unique($m[1] ?? []));
        if (count($valori) !== 1) {
            throw new RuntimeException('tiparul ' . $tipar . ' găsește ' . count($valori) . ' valori în ' . $fisier . ' (trebuie una)');
        }
        return stripcslashes($valori[0]);
    };

    // B1, B2: constantele din site.json.
    $verifica('B1 cookie-ul acordului: ' . CookieConsent::nume() . ' (site.json) = constanta COOKIE din src/Support/CookieConsent.php al Bioscem',
        CookieConsent::nume() === $dinVechi('src/Support/CookieConsent.php', "/const COOKIE = '([^']+)'/"));
    $verifica('B2 cheia popup-ului închis: ' . Brand::cheiePopupInchis() . ' (site.json) = cheia din views/layout.php al Bioscem',
        Brand::cheiePopupInchis() === $dinVechi('views/layout.php', "/var CHEIE = '([^']+)'/"));

    // Înghețarea, cu valorile private (ca pasul runner-ului pe o bază Bioscem).
    $inghetare = Inghetare::dinFisier($radacina . '/site-uri/bioscem/setari-inghetate.json');
    try {
        $private = $inghetare->valoriPrivate('site/bioscem/setari-inghetate');
    } catch (Throwable $e) {
        $scrie('EROARE: ' . $e->getMessage() . '. Scrie-l din clona Bioscem: tools/dev/php.sh tools/inghetare/genereaza-setari.php --site=bioscem');
        exit(1);
    }
    /** Setările unei baze după pasul înghețării (aceleași reguli ca Schema::setare): $randuri = ce avea baza înainte. */
    $dupaInghetare = static function (array $randuri) use ($inghetare, $private): array {
        foreach ($inghetare->setari() as $cheie => $intrare) {
            $valoare = $intrare['privat'] ? $private[$cheie] : (string) $intrare['valoare'];
            if (!array_key_exists($cheie, $randuri) || ($intrare['mod'] === 'lipsa_sau_gol' && trim((string) $randuri[$cheie]) === '')) {
                $randuri[$cheie] = $valoare;
            }
        }
        return array_merge((new ReflectionClassConstant(Settings::class, 'DEFAULTS'))->getValue(), $randuri);
    };
    $implicitVechi = static fn (string $cheie): string => $dinVechi('src/Support/Settings.php', "/'" . preg_quote($cheie, '/') . "' => '((?:[^'\\\\]|\\\\.)*)'/");

    // B3: numele site-ului și al expeditorului.
    $faraRand = $dupaInghetare([]);
    $cuGol = $dupaInghetare(['order_email_from_name' => '', 'store_name' => '']);
    $cuValoare = $dupaInghetare(['order_email_from_name' => 'Alt Expeditor']);
    $verifica('B3 numele site-ului fără store_name: „' . Brand::numeSite($faraRand) . '” = seo.json → organizatie.nume (titlurile Bioscem)',
        Brand::numeSite($faraRand) === 'Bioscem Shop' && Brand::numeSite($cuGol) === 'Bioscem Shop');
    $expeditorVechi = $implicitVechi('order_email_from_name');
    $verifica('B3 numele expeditorului cu rândul lipsă și cu rândul gol: „' . $expeditorVechi . '” (implicitul codului Bioscem, prin înghețare); '
        . 'o valoare scrisă rămâne', Brand::numeExpeditor($faraRand) === $expeditorVechi && Brand::numeExpeditor($cuGol) === $expeditorVechi
        && Brand::numeExpeditor($cuValoare) === 'Alt Expeditor');
    $destinatariVechi = $implicitVechi('contact_form_recipients');
    $verifica('B3 destinatarii formularului de contact cu rândul lipsă și cu rândul gol = implicitul codului Bioscem (valoare privată, din privat/)',
        Brand::adrese((string) $faraRand['contact_form_recipients']) === [$destinatariVechi]
        && Brand::emailContact(null, $dupaInghetare(['contact_form_recipients' => ' '])) === $destinatariVechi);

    // B4: iconițele categoriilor.
    $fisierIconite = tempnam(sys_get_temp_dir(), 'iconite-bioscem-');
    file_put_contents($fisierIconite, $vechi('src/Support/HomeSections.php'));
    $iconiteVechi = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require $argv[1]; echo json_encode((new ReflectionClassConstant("App\\\\Support\\\\HomeSections", "CATEGORY_ICONS"))->getValue());')
        . ' ' . escapeshellarg($fisierIconite) . ' 2>&1'), true);
    @unlink($fisierIconite);
    $perechiVechi = [];
    foreach ((array) $iconiteVechi as $cuvant => $svg) {
        if ($cuvant !== 'default') {
            $perechiVechi[] = [(string) $cuvant, (string) $svg];
        }
    }
    $iconitaImplicita = (string) (new ReflectionClassConstant(HomeSections::class, 'ICONITA_IMPLICITA'))->getValue();
    $iconFor = new ReflectionMethod(HomeSections::class, 'iconFor');
    $verifica('B4 iconițele categoriilor din tema.json = cele ' . count($perechiVechi) . ' din HomeSections::CATEGORY_ICONS al Bioscem, în ordine',
        $perechiVechi !== [] && Tema::iconiteCategorii() === $perechiVechi, json_encode(Tema::iconiteCategorii()) ?: '');
    $verifica('B4 iconița implicită a nucleului = „default” a Bioscem; „Plasma Quinton” → „quinton”, „Altceva” → implicita',
        $iconitaImplicita === ($iconiteVechi['default'] ?? null) && $iconFor->invoke(null, 'Plasma Quinton') === ($iconiteVechi['quinton'] ?? '')
        && $iconFor->invoke(null, 'Altceva') === $iconitaImplicita);

    // B5: logo-ul sertarului.
    $layoutVechi = $vechi('views/layout.php');
    preg_match("/foreach \\(\\[([^\\]]+)\\] as \\\$logoCandidate\\)/", $layoutVechi, $mCandidati);
    preg_match_all("/'([^']+)'/", (string) ($mCandidati[1] ?? ''), $mNume);
    $candidatiVechi = array_map(static fn (string $n): string => '/uploads/gallery/' . $n, $mNume[1] ?? []);
    $logoTema = Tema::valori()['logo_meniu_mobil'];
    $verifica('B5 logo-ul sertarului din tema.json = candidații, implicitul și textul alternativ din views/layout.php al Bioscem',
        $candidatiVechi !== [] && is_array($logoTema) && $logoTema['candidati'] === $candidatiVechi
        && $logoTema['implicit'] === $dinVechi('views/layout.php', "/\\\$mobileMenuLogoPath = '([^']+)';/")
        && $logoTema['alt'] === $dinVechi('views/layout.php', "/htmlspecialchars\\(\\\$mobileMenuLogoPath, ENT_QUOTES\\) \\. '\" alt=\"([^\"]+)\"/"));

    // B6: blocul paginii de mulțumire.
    preg_match('#<style>\n(.*?)</style>#s', $vechi('views/site/checkout-success.php'), $mStil);
    $verifica('B6 stilurile paginii de mulțumire (tema/pagina-succes.css) = blocul <style> din checkout-success.php al Bioscem, octet cu octet',
        isset($mStil[1]) && Tema::cssInline('pagina_succes', 'NUCLEU') === $mStil[1]);

    // B7, B8: textele din înghețare.
    $verifica('B7 descrierea unui produs fără descriere scurtă = textul din catalogul și din caruselul Bioscem',
        $faraRand['catalog_descriere_implicita'] === $dinVechi('views/site/components/shop-catalog.php', "/\\\$desc !== '' \\? \\\$desc : '([^']+)'/")
        && $faraRand['catalog_descriere_implicita'] === $dinVechi('views/site/product.php', "/item\\.short_description \\|\\| '([^']+)'/"));
    $verifica('B8 fereastra de bun venit: pornită, cu titlul și textul din implicitele Bioscem',
        $faraRand['welcome_popup_enabled'] === $implicitVechi('welcome_popup_enabled')
        && $faraRand['welcome_popup_title'] === $implicitVechi('welcome_popup_title')
        && $faraRand['welcome_popup_body'] === stripcslashes($dinVechi('src/Support/Settings.php', '/\'welcome_popup_body\' => "((?:[^"\\\\]|\\\\.)*)"/')));

    // B9: pachetul.
    $probleme = PachetSite::verifica($radacina . '/site-uri/bioscem', 'bioscem');
    $amprenta = PachetSite::amprenta(DateSite::dosar());
    $verifica('B9 pachetul e întreg (manifestul, site.json și tema/tema.json obligatorii) și /health arată „pachet: ' . $amprenta . '”',
        $probleme === [] && is_string($amprenta) && preg_match('/^[0-9a-f]{12}$/', $amprenta) === 1
        && array_intersect(['site.json', 'tema/tema.json'], (array) (PachetSite::manifest(DateSite::dosar())['obligatorii'] ?? [])) === ['site.json', 'tema/tema.json'],
        implode('; ', $probleme));

    // WP-17. B10: linkurile din newslettere semnate cu aceeași cheie (sarea din site.json = cea din codul Bioscem).
    $sareVeche = $dinVechi('src/Support/NewsletterService.php', "/'newsletter-unsubscribe\\|' \\. \\\$url \\. '\\|' \\. \\\$dbName \\. '\\|([a-z]+)'\\)/");
    $config = require $radacina . '/config/app.php';
    $cheieVeche = hash('sha256', 'newsletter-unsubscribe|' . (string) ($config['url'] ?? '') . '|' . (string) ($config['db']['database'] ?? '') . '|' . $sareVeche);
    $token = (new ReflectionMethod(\App\Support\NewsletterService::class, 'buildUnsubscribeToken'))->invoke(null, 42, 'Abonat@Exemplu.ro');
    $payloadVechi = rtrim(strtr(base64_encode('42|abonat@exemplu.ro'), '+/', '-_'), '=');
    $semnaturaVeche = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadVechi, $cheieVeche, true)), '+/', '-_'), '=');
    $verifica('B10 newsletterul: sarea „' . $sareVeche . '” (site.json) = cea din codul Bioscem; tokenul de dezabonare = formula veche, '
        . 'iar fără .env cheia vine din site.json', $token === $payloadVechi . '.' . $semnaturaVeche
        && \App\Support\NewsletterService::sursaCheiiSemnare() === 'site.json'
        && \App\Support\NewsletterService::cheieDerivata($sareVeche) === $cheieVeche);
    // B11: textele plății BT (le vede cumpărătorul pe extras), din înghețare, nu din APP_URL.
    $btVechi = $vechi('src/Support/BtIpayPayments.php');
    $btPayments = \App\Support\BtIpayPayments::class;
    $verifica('B11 textele plății la Banca Transilvania = cele trei texte scrise în codul Bioscem (cu numărul comenzii)',
        str_contains($btVechi, "'Comanda ' . \$numarComanda . ' " . substr($btPayments::descriere($btPayments::TIP_COMANDA, '', $faraRand), strlen('Comanda  ')) . "'")
        && $btPayments::descriere($btPayments::TIP_COMANDA, '203801', $faraRand) === 'Comanda 203801 ' . $dinVechi('src/Support/BtIpayPayments.php', "/'Comanda ' \\. \\\$numarComanda \\. ' ([^']+)'/")
        && $btPayments::descriere($btPayments::TIP_LINK, '203801', $faraRand) === 'Diferenta comanda 203801 ' . $dinVechi('src/Support/BtIpayPayments.php', "/'Diferenta comanda ' \\. \\(string\\) \\\$comanda\\['order_number'\\] \\. ' ([^']+)'/")
        && $btPayments::descriere($btPayments::TIP_TEST, '', $faraRand) === $dinVechi('src/Support/BtIpayPayments.php', "/'(Plata de test 1 leu [^']+)'/"));
    // B12: formatul vechi al numerelor de comandă și contorul.
    $numarVechi = \App\Support\OrderNumber::vechi($faraRand);
    $prefixVechiBs = $dinVechi('src/Support/OrderNumber.php', "/return '([A-Z]+)' \\. date\\('YmdHis'\\)/");
    $verifica('B12 numerotarea: formatul vechi „' . $numarVechi . '” are prefixul din codul Bioscem („' . $prefixVechiBs . '”), contorul pornește de la '
        . 'implicitul Bioscem', preg_match('/^' . $prefixVechiBs . '\d{17}$/', $numarVechi) === 1
        && \App\Support\OrderNumber::normalizeazaStart($faraRand['order_number_next']) === (int) $implicitVechi('order_number_next'));
    // B13: TVA-ul implicit (prețurile fără cotă în fișă, liniile trimise în ERP fără cotă pe produs).
    $verifica('B13 cota de TVA implicită „' . Settings::cotaTvaImplicita($faraRand) . '” = implicitul codului Bioscem (și în ERP)',
        Settings::cotaTvaImplicita($faraRand) === $implicitVechi('default_vat_percent')
        && round((float) Settings::cotaTvaImplicita($faraRand), 2) === 19.0);
    // B14: emailul comenzii cu ordin de plată: textul și blocurile vechi prin înghețare; nucleul diferă doar prin slogan.
    $definitii = \App\Support\OrderMailer::templateDefinitions();
    $corpNou = (string) $definitii['new_order_op']['default_body'];
    $corpVechi = (string) $faraRand['email_template_new_order_op_body'];
    $randSlogan = '<p style="margin:0 0 4px;color:#4f6b66;font-size:16px;line-height:1.55;">Vă mulțumim și vă dorim VITALITATE ȘI PROTECȚIE!</p>' . "\n";
    $blocuriVechi = json_decode((string) $faraRand['email_template_new_order_op_blocks_json'], true);
    $blocuriNoi = (new ReflectionMethod(\App\Http\Controllers\AdminController::class, 'newOrderOpEcommerceDefaultBlocks'))
        ->invoke((new ReflectionClass(\App\Http\Controllers\AdminController::class))->newInstanceWithoutConstructor());
    $diferaBlocuri = [];
    foreach ((array) $blocuriVechi as $i => $bloc) {
        if ($bloc !== ($blocuriNoi[$i] ?? null)) {
            $diferaBlocuri[] = $i;
        }
    }
    $verifica('B14 emailul OP: corpul și blocurile Bioscem (înghețate) au sloganul; implicitele nucleului sunt aceleași fără el',
        str_contains($corpVechi, $randSlogan) && str_replace($randSlogan, '', $corpVechi) === $corpNou
        && count((array) $blocuriVechi) === count($blocuriNoi) && count($diferaBlocuri) === 1
        && (string) ($blocuriVechi[$diferaBlocuri[0]]['content'] ?? '') === "Vă mulțumim și vă dorim VITALITATE ȘI PROTECȚIE!\nEchipa {{store_name}}"
        && (string) ($blocuriNoi[$diferaBlocuri[0]]['content'] ?? '') === 'Echipa {{store_name}}');

    // B15 (WP-18): acordul GDPR, cu datele operatorului din înghețare (cu cele private).
    $siteVechi = $vechi('src/Http/Controllers/SiteController.php');
    $gdpr = array_filter($faraRand, static fn (string $cheie): bool => str_starts_with($cheie, 'gdpr_'), ARRAY_FILTER_USE_KEY);
    $lipsaDinVechi = [];
    foreach ($gdpr as $cheie => $valoare) {
        if ($cheie !== 'gdpr_formular_varianta' && ($valoare === '' || !str_contains($siteVechi, htmlspecialchars($valoare, ENT_QUOTES)))) {
            $lipsaDinVechi[] = $cheie;
        }
    }
    $intrari = $inghetare->setari();
    $verifica('B15 acordul GDPR: varianta „medical” și cele 10 valori înghețate (' . count($gdpr) . ' chei) sunt în textul acordului din codul '
        . 'Bioscem; reprezentantul, telefonul și emailul sunt private', ($gdpr['gdpr_formular_varianta'] ?? '') === 'medical' && count($gdpr) === 11
        && $lipsaDinVechi === [] && ($intrari['gdpr_operator_reprezentant']['privat'] ?? false) === true
        && ($intrari['gdpr_operator_telefon']['privat'] ?? false) === true && ($intrari['gdpr_operator_email']['privat'] ?? false) === true,
        'lipsă din codul vechi: ' . implode(', ', $lipsaDinVechi));
    // Rândurile cu operatorul și cu retragerea acordului, din șablonul codului comun cu valorile înghețate.
    $siteNou = (string) file_get_contents($radacina . '/src/Http/Controllers/SiteController.php');
    $email = htmlspecialchars($gdpr['gdpr_operator_email'], ENT_QUOTES);
    $inlocuiri = ['{$operatorNume}' => $gdpr['gdpr_operator_nume'], '{$operatorSediu}' => $gdpr['gdpr_operator_sediu'],
        '{$operatorTelefon}' => $gdpr['gdpr_operator_telefon'], '{$operatorRegCom}' => $gdpr['gdpr_operator_regcom'],
        '{$operatorCui}' => $gdpr['gdpr_operator_cui'], '{$operatorMarca}' => $gdpr['gdpr_operator_marca'],
        '{$operatorReprezentant}' => $gdpr['gdpr_operator_reprezentant'], '{$adresaRetragere}' => $gdpr['gdpr_operator_adresa_retragere'],
        '{$legaturaEmail}' => '<a href="mailto:' . $email . '">' . $email . '</a>'];
    $rand = static function (string $text, string $inceput): string {
        preg_match_all('/^[ \t]*' . preg_quote($inceput, '/') . '.*$/mu', $text, $m);
        return count($m[0]) === 1 ? trim($m[0][0]) : '(' . count($m[0]) . ' rânduri)';
    };
    $operatorNou = strtr($rand($siteNou, 'de către operatorul de date cu caracter personal <strong>{$operatorNume}'), array_map(
        static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES), $inlocuiri));
    $retragereNou = strtr($rand($siteNou, '<p>Prezentul consimțământ este valabil până la retragerea expresă'), ['{$adresaRetragere}'
        => htmlspecialchars($inlocuiri['{$adresaRetragere}'], ENT_QUOTES), '{$legaturaEmail}' => $inlocuiri['{$legaturaEmail}']]);
    $verifica('B15 acordul GDPR: fraza operatorului și cea a retragerii acordului, scrise de codul comun cu valorile înghețate = rândurile '
        . 'codului Bioscem', $operatorNou === $rand($siteVechi, 'de către operatorul de date cu caracter personal <strong>')
        && $retragereNou === $rand($siteVechi, '<p>Prezentul consimțământ este valabil până la retragerea expresă'));

    } catch (Throwable $e) {
        $verifica('citirea codului Bioscem și a pachetului', false, $e->getMessage());
    }
    $scrie('test-pachet-site bioscem: ' . $verificari . ' verificări, ' . $esecuri . ' greșite');
    exit($esecuri === 0 ? 0 : 1);
}

// =====================================================================
// biovitality (pachetul Biovitality, B5: primul site pe codul comun)
// =====================================================================
if ($mod === 'biovitality') {
    require __DIR__ . '/lib/test-pachet-biovitality.php';
}

// =====================================================================
// neutru
// =====================================================================
$scrie('== G-pachet neutru: cu pachetul demo, nimic din lista neagră (' . implode(', ', LISTA_NEAGRA_FIRMA) . ' și '
    . count(lista_neagra_private($radacina)) . ' termeni personali din privat/, nescriși aici)');

/**
 * Ce mai rămâne de scos, cu WP-ul care îl scoate (pagina sau emailul → termenii). Aici potrivirea trebuie să existe încă:
 * când WP-ul ei o scoate, intrarea pleacă din listă (lista doar scade).
 */
$ramase = [
    // WP-22 (DB-13, D1): eticheta listei de articole „Jurnalul Bioscem” (blog-posts.php), cu textele avansate ale blogului.
    'pagina /blog' => ['bioscem', 'jurnalul bioscem', 'WP-22'],
    // WP-22 (DB-13, D1, D7): previzualizarea listei de articole din editorul de pagini (aceeași etichetă) și autorul ei
    // inventat „Echipa Bioscem” (AdminController::loadBlogPostsPreview). Previzualizarea e un șir JSON cu „<”, „>” scrise
    // \u003C, \u003E, deci „Jurnalul” lipit de un „E” nu e un cuvânt întreg: se vede doar „bioscem”.
    'pagina /admin/pages/new' => ['bioscem', 'WP-22'],
];
$ramaseVazute = [];
/** O potrivire e permisă doar dacă e exact cea așteptată în $ramase. */
$faraListaNeagra = static function (string $unde, string $text) use ($ramase, &$ramaseVazute, $verifica): void {
    $gasite = potriviri_lista_neagra($text);
    if (isset($ramase[$unde])) {
        $asteptate = array_slice($ramase[$unde], 0, -1);
        $wp = (string) end($ramase[$unde]);
        $ramaseVazute[$unde] = true;
        $verifica($unde . ': doar ce rămâne pentru ' . $wp . ' (' . implode(', ', $asteptate) . '), încă acolo',
            $gasite === $asteptate, 'găsite: ' . (implode(', ', $gasite) ?: 'nimic — scoate intrarea din $ramase'));
        return;
    }
    $verifica($unde . ': nimic din lista neagră', $gasite === [], 'găsite: ' . implode(', ', $gasite));
};

// N1: fără server.
$implicite = (new ReflectionClassConstant(Settings::class, 'DEFAULTS'))->getValue();
$cuListaNeagra = array_keys(array_filter($implicite, static fn ($v): bool => potriviri_lista_neagra((string) $v) !== []));
$verifica('N1 Settings::DEFAULTS: nicio valoare din lista neagră (' . count($implicite) . ' chei)', $cuListaNeagra === [], implode(', ', $cuListaNeagra));
$verifica('N1 fereastra de bun venit oprită și goală implicit', $implicite['welcome_popup_enabled'] === '0' && $implicite['welcome_popup_title'] === ''
    && $implicite['welcome_popup_body'] === '');
$faraListaNeagra('N1 poza de rezervă a produselor (public/assets/img/product-placeholder.svg)',
    (string) file_get_contents($radacina . '/public/assets/img/product-placeholder.svg'));
$numeFaraSetari = Brand::numeSite($implicite);
$verifica('N1 numele site-ului și al expeditorului fără setări: cel din pachetul demo (seo.json → organizatie.nume, altfel APP_NAME; „'
    . $numeFaraSetari . '”), fără nimic din lista neagră', $numeFaraSetari === \App\Support\SeoMeta::numeMagazinImplicit()
    && $numeFaraSetari !== '' && Brand::numeExpeditor($implicite) === $numeFaraSetari && potriviri_lista_neagra($numeFaraSetari) === []);
$verifica('N1 cookie-ul acordului „' . CookieConsent::nume() . '” și cheia popup-ului „' . Brand::cheiePopupInchis() . '” (implicitele)',
    CookieConsent::nume() === CookieConsent::IMPLICIT && CookieConsent::nume() === 'cookie_consent'
    && Brand::cheiePopupInchis() === 'welcome_popup_inchis');
$iconFor = new ReflectionMethod(HomeSections::class, 'iconFor');
$verifica('N1 fără temă, „Plasma Quinton” primește iconița implicită',
    $iconFor->invoke(null, 'Plasma Quinton') === (new ReflectionClassConstant(HomeSections::class, 'ICONITA_IMPLICITA'))->getValue());
$verifica('N1 adresa de contact fără setări: adresa primului administrator general',
    Brand::emailContact(null, ['contact_email' => '', 'contact_form_recipients' => '']) === ''
    && Brand::emailContact(null, ['contact_email' => 'a@exemplu.ro', 'contact_form_recipients' => 'b@exemplu.ro']) === 'a@exemplu.ro'
    && Brand::emailContact(null, ['contact_email' => 'nu-e-adresa', 'contact_form_recipients' => 'x; B@exemplu.ro,c@exemplu.ro']) === 'B@exemplu.ro');

// WP-17: plata BT, numerotarea, TVA-ul, newsletterul și emailurile conturilor, fără nimic al unui client.
$verifica('N1 (WP-17) TVA implicit 21, numerotarea de la 1001, formatul vechi cu „CMD” (OD-07, OD-08)',
    Settings::cotaTvaImplicita($implicite) === '21' && \App\Support\OrderNumber::normalizeazaStart($implicite['order_number_next']) === 1001
    && preg_match('/^CMD\d{17}$/', \App\Support\OrderNumber::vechi($implicite)) === 1
    && preg_match('/^CMD\d{17}$/', \App\Support\OrderNumber::vechi(['order_number_legacy_prefix' => 'bv; drop'])) === 1);
$verifica('N1 (WP-17) textul plății BT fără setare: gazda site-ului („' . \App\Support\BtIpayPayments::descriere('order', '1001', $implicite) . '”)',
    \App\Support\BtIpayPayments::descriere('order', '1001', $implicite) === 'Comanda 1001 ' . Brand::gazda() && Brand::gazda() !== ''
    && \App\Support\BtIpayPayments::descriere('test', '', ['bt_ipay_descriere_magazin' => 'exemplu.ro']) === 'Plata de test 1 leu exemplu.ro');
$verifica('N1 (WP-17) cheia linkurilor din newslettere: fără .env și fără sare în pachet, derivată din LICENTA_SITE (diagnostic.php o arată ca ATENȚIE)',
    \App\Support\NewsletterService::sursaCheiiSemnare() === 'licenta');
$emailCont = \App\Support\BrandedEmail::render($implicite, ['title' => 'Confirmă adresa', 'greeting' => 'Bună!', 'intro' => 'Text.',
    'button' => ['label' => 'Confirmă', 'url' => 'https://exemplu.ro/x'], 'recipient' => 'client@exemplu.ro']);
$faraListaNeagra('N1 (WP-17) emailul unui cont (BrandedEmail) cu setările implicite', $emailCont);
$verifica('N1 (WP-17) BrandedEmail: numele de rezervă e gazda, culorile implicite neutre; o culoare greșită din setări nu ajunge în email',
    \App\Support\BrandedEmail::brandName($implicite) === Brand::gazda() && str_contains($emailCont, \App\Support\BrandedEmail::CULOARE_2_IMPLICITA)
    && \App\Support\BrandedEmail::culoare(['email_brand_color' => 'red;x:y'], 'email_brand_color', '#000000') === '#000000'
    && \App\Support\BrandedEmail::brandName(['email_brand_name' => '', 'store_name' => 'Magazin X']) === 'Magazin X');

// N2, N3: un server al codului curent cu pachetul demo, pe baza de dezvoltare.
$app = require $radacina . '/config/app.php';
$db = Database::connection((array) $app['db']);
if (!$db instanceof PDO || (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() < 10) {
    $scrie('EROARE: baza de test nu are datele demo; rulează întâi tools/dev/instaleaza.sh');
    exit(1);
}
$verifica('N1 adresa de contact fără setări, cu baza: a primului administrator general',
    Brand::emailContact($db, ['contact_email' => '', 'contact_form_recipients' => ''])
        === (string) $db->query("SELECT email FROM admins WHERE role = 'administrator_general' ORDER BY id LIMIT 1")->fetchColumn());

$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 24;
$cerere = static function (string $metoda, string $cale, string $jar, array $date = []) use (&$port): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    if ($metoda === 'POST') {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($date));
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $location = (string) curl_getinfo($c, CURLINFO_REDIRECT_URL);
    curl_close($c);
    return ['cod' => $cod, 'corp' => $corp, 'location' => $location];
};
$jarViz = $dosarDev . '/test-pachet-viz-' . getmypid() . '.txt';
$jarAdm = $dosarDev . '/test-pachet-adm-' . getmypid() . '.txt';
$tabele = [
    ['tabel' => 'settings', 'unde' => "`key` IN ('design_header_html', 'store_name', 'order_email_from_name', 'order_number_next', 'order_number_mode') OR `key` LIKE 'gdpr\\_%'", 'cheie' => 'key'],
    ['tabel' => 'pages', 'unde' => "slug IN ('404', 'checkout/succes')", 'cheie' => 'id'],
];
$original = salvari_instantaneu($db, $tabele);
$pid = 0;
$puneLaLoc = static function () use ($db, $tabele, $original, &$pid, $jarViz, $jarAdm): void {
    salvari_pune_la_loc($db, $tabele, $original);
    if ($pid > 0) {
        exec('pkill -TERM -P ' . $pid . ' 2>/dev/null; kill ' . $pid . ' 2>/dev/null');
        for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . $pid . ' 2>/dev/null')) !== ''; $i++) {
            usleep(100000);
        }
        $pid = 0;
    }
    @unlink($jarViz);
    @unlink($jarAdm);
};
register_shutdown_function($puneLaLoc);
try {
    $ini = [];
    foreach (['pdo_mysql.default_socket', 'error_reporting', 'display_errors', 'log_errors', 'error_log', 'sendmail_path', 'memory_limit',
        'allow_url_fopen'] as $cheie) {
        $valoare = ini_get($cheie);
        if ($valoare !== false && $valoare !== '') {
            $ini[] = '-d ' . escapeshellarg($cheie . '=' . $valoare);
        }
    }
    $pid = (int) trim((string) shell_exec('cd ' . escapeshellarg($radacina) . ' && env PHP_CLI_SERVER_WORKERS=2 nohup ' . escapeshellarg(PHP_BINARY)
        . ' ' . implode(' ', $ini) . ' -S 127.0.0.1:' . $port . ' -t public tools/dev/router.php >> '
        . escapeshellarg($dosarDev . '/server-pachet-' . $port . '.log') . ' 2>&1 & echo $!'));
    $pornit = false;
    for ($i = 0; $i < 50 && !$pornit; $i++) {
        $pornit = $cerere('GET', '/health', $jarViz)['cod'] === 200;
        $pornit || usleep(200000);
    }
    if (!$pornit) {
        throw new RuntimeException('serverul de pe portul ' . $port . ' nu pornește');
    }
    $health = $cerere('GET', '/health', $jarViz)['corp'];
    $verifica('N2 /health: al treilea rând arată pachetul demo („pachet: ' . PachetSite::amprenta(DateSite::dosar()) . '”)',
        $health === "ok\nschema: la_zi\npachet: " . PachetSite::amprenta(DateSite::dosar()), str_replace("\n", ' | ', $health));

    // Cu numele magazinului și al expeditorului goale: rezervele nucleului (APP_NAME) se văd peste tot.
    $db->exec("UPDATE settings SET `value` = '' WHERE `key` IN ('store_name', 'order_email_from_name')");
    $nrComanda = (string) $db->query('SELECT order_number FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
    $slugBlog = (string) $db->query('SELECT slug FROM blog_posts WHERE is_published = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $publice = ['/', '/magazin', '/categorie/ceaiuri', '/produs/ceai-de-menta', '/produs/ceai-de-musetel', '/cos', '/checkout', '/contact',
        '/blog', '/blog/' . $slugBlog, '/termeni-si-conditii', '/pagina-care-nu-exista', '/login', '/register', '/robots.txt', '/sitemap.xml'];
    foreach ($publice as $cale) {
        $r = $cerere('GET', $cale, $jarViz);
        $faraListaNeagra(isset($ramase['pagina ' . $cale]) ? 'pagina ' . $cale : 'N2 ' . $cale . ' (' . $r['cod'] . ', antetul de rezervă)', $r['corp']);
    }
    $prima = $cerere('GET', '/', $jarViz)['corp'];
    $verifica('N2 antetul de rezervă: numele site-ului, fără alt nume', str_contains($prima,
        '<a href="/" class="logo">' . htmlspecialchars($numeFaraSetari, ENT_QUOTES) . '</a>'));

    // Un antet cu sertarul de meniu și vederile de rezervă (paginile lor din bază nepublicate).
    $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('design_header_html', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
        ->execute(['<header class="antet-test-neutru"><a href="/">Demo</a> {{cart_count}}{{mobile_menu}}</header>']);
    $db->exec("UPDATE pages SET is_published = 0 WHERE slug IN ('404', 'checkout/succes')");
    @unlink($jarViz);
    $prima = $cerere('GET', '/', $jarViz)['corp'];
    $faraListaNeagra('N2 / (sertarul de meniu)', $prima);
    $verifica('N2 sertarul de meniu fără logo în setări și fără temă: numele site-ului ca text, linkul „Contul meu”',
        str_contains($prima, '<a href="/" class="bv-mobile-menu-token__brand" aria-label="Acasă"><span class="bv-mobile-menu-token__brand-text">'
            . htmlspecialchars($numeFaraSetari, ENT_QUOTES) . '</span></a>') && str_contains($prima, '<a href="/login">Contul meu</a>'));
    foreach (['/pagina-care-nu-exista', '/checkout/bt/retur', '/checkout/succes/' . rawurlencode($nrComanda)] as $cale) {
        $r = $cerere('GET', $cale, $jarViz);
        $faraListaNeagra('N2 ' . $cale . ' (vederea de rezervă, ' . $r['cod'] . ')', $r['corp']);
        if (str_starts_with($cale, '/checkout/succes/')) {
            $verifica('N2 mulțumirea: paleta neutră a nucleului (nu cea Bioscem)', str_contains($r['corp'], '--bs-succes-accent:#334155;')
                && !str_contains($r['corp'], '#075d61'));
        }
    }

    // Adminul: paginile cu textele schimbate în WP-16.
    $parola = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
    $login = $cerere('POST', '/admin/login', $jarAdm, ['email' => (string) (getenv('CMS_DEV_ADMIN_EMAIL') ?: 'admin@exemplu.ro'), 'password' => $parola]);
    if (!str_ends_with($login['location'], '/admin')) {
        throw new RuntimeException('logarea adminului nu merge (' . $login['cod'] . ' → ' . $login['location'] . ')');
    }
    foreach (['/admin', '/admin/settings/store', '/admin/settings/erp', '/admin/settings/payments', '/admin/settings/floating-cart',
        '/admin/emails/newsletters?tab=contact_forms', '/admin/emails/sender', '/admin/pages/new', '/admin/design?section=footer',
        '/admin/blog/categories', '/admin/emails/test'] as $cale) {
        $r = $cerere('GET', $cale, $jarAdm);
        $faraListaNeagra(isset($ramase['pagina ' . $cale]) ? 'pagina ' . $cale : 'N2 ' . $cale . ' (' . $r['cod'] . ')', $r['corp']);
        if ($cale === '/admin') {
            $verifica('N2 titlul filei din admin: „Admin ' . $numeFaraSetari . '”', str_contains($r['corp'],
                ' | Admin ' . htmlspecialchars($numeFaraSetari, ENT_QUOTES) . '</title>'));
        }
    }

    // N4 (WP-18): acordul GDPR fără datele operatorului, blocul lor din admin și salvarea lor (valori inventate).
    $db->exec("DELETE FROM settings WHERE `key` LIKE 'gdpr\\_%'");
    $acord = $cerere('GET', '/acorduri-gdpr', $jarViz);
    $faraListaNeagra('N4 /acorduri-gdpr (' . $acord['cod'] . ', fără datele operatorului)', $acord['corp']);
    $verifica('N4 /acorduri-gdpr fără datele operatorului: spații de completat (cu stilul lor), varianta „general” (CUI, „în scopul”), '
        . 'fără link de email', $acord['cod'] === 200 && substr_count($acord['corp'], '<span class="de-completat">') >= 10
        && str_contains($acord['corp'], '.gdpr-agreement .de-completat{') && str_contains($acord['corp'], '<tr><th>CUI</th>')
        && str_contains($acord['corp'], 'în scopul') && !str_contains($acord['corp'], 'CUIM') && !str_contains($acord['corp'], 'mailto:'));
    $lista = $cerere('GET', '/admin/users/gdpr-agreements', $jarAdm);
    $faraListaNeagra('N4 /admin/users/gdpr-agreements (' . $lista['cod'] . ')', $lista['corp']);
    $verifica('N4 Acorduri GDPR din admin: blocul „Datele operatorului”, salvat la /admin/pages/gdpr-agreements/settings',
        $lista['cod'] === 200 && str_contains($lista['corp'], 'action="/admin/pages/gdpr-agreements/settings"')
        && str_contains($lista['corp'], 'name="gdpr_operator_adresa_retragere"'));
    $operatorDemo = ['gdpr_formular_varianta' => 'medical', 'gdpr_scop' => 'Congresul Demo 2030', 'gdpr_operator_nume' => 'SC Firma Demo SRL',
        'gdpr_operator_reprezentant' => 'Administrator Ion Exemplu', 'gdpr_operator_regcom' => 'J40/123/2020', 'gdpr_operator_cui' => 'RO12345678',
        'gdpr_operator_sediu' => 'Str. Exemplu nr. 1, București', 'gdpr_operator_telefon' => '0700 000 000',
        'gdpr_operator_email' => 'gdpr@exemplu.ro', 'gdpr_operator_marca' => 'Demo', 'gdpr_operator_adresa_retragere' => ''];
    $salvare = $cerere('POST', '/admin/pages/gdpr-agreements/settings', $jarAdm, $operatorDemo);
    $acord = $cerere('GET', '/acorduri-gdpr', $jarViz)['corp'];
    $verifica('N4 salvarea datelor operatorului (varianta „medical”): 302 spre listă, textul acordului cu ele, adresa de retragere '
        . 'implicită „<denumire> - <sediu>”, linkul de email, fără spații de completat și fără stilul lor', $salvare['cod'] === 302
        && str_ends_with($salvare['location'], '/admin/users/gdpr-agreements')
        && str_contains($acord, 'la evenimentul medical' . "\n" . '            <strong>Congresul Demo 2030</strong>,')
        && str_contains($acord, '<strong>SC Firma Demo SRL</strong>, persoană juridică română, cu sediul în Str. Exemplu nr. 1, București, telefon 0700 000 000')
        && str_contains($acord, 'reprezentată legal prin Administrator Ion Exemplu, în calitate de')
        && str_contains($acord, 'serviciilor poștale la SC Firma Demo SRL - Str. Exemplu nr. 1, București sau prin')
        && str_contains($acord, '<a href="mailto:gdpr@exemplu.ro">gdpr@exemplu.ro</a>') && str_contains($acord, '<tr><th>CUIM</th>')
        && !str_contains($acord, 'de-completat'), $salvare['cod'] . ' → ' . $salvare['location']);
    $cerere('POST', '/admin/pages/gdpr-agreements/settings', $jarAdm, ['gdpr_formular_varianta' => 'altceva', 'gdpr_operator_nume' => '  ']
        + $operatorDemo);
    $randuri = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('gdpr_formular_varianta', 'gdpr_operator_nume')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $acord = $cerere('GET', '/acorduri-gdpr', $jarViz)['corp'];
    $verifica('N4 o variantă necunoscută se salvează „general”, o denumire goală apare iar ca spațiu de completat (și în adresa de retragere)',
        ($randuri['gdpr_formular_varianta'] ?? '') === 'general' && ($randuri['gdpr_operator_nume'] ?? null) === ''
        && str_contains($acord, 'serviciilor poștale la <span class="de-completat">') && str_contains($acord, '<tr><th>CUI</th>'));

    // N4 (revizuirea WP-17): numerotarea salvată cu câmpul gol păstrează contorul (nu sare la începutul implicit, 1001).
    $db->exec("INSERT INTO settings (`key`, `value`) VALUES ('order_number_next', '4321') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $cerere('POST', '/admin/settings/store', $jarAdm, ['action' => 'save_order_numbering', 'order_number_mode' => 'sequential',
        'order_number_next' => ' ']);
    $dupaGol = (string) $db->query("SELECT `value` FROM settings WHERE `key` = 'order_number_next'")->fetchColumn();
    $cerere('POST', '/admin/settings/store', $jarAdm, ['action' => 'save_order_numbering', 'order_number_mode' => 'sequential',
        'order_number_next' => '4 400']);
    $dupaScris = (string) $db->query("SELECT `value` FROM settings WHERE `key` = 'order_number_next'")->fetchColumn();
    $verifica('N4 numerotarea: salvată cu câmpul gol, contorul rămâne (4321); cu un număr scris („4 400”), acela (4400)',
        $dupaGol === '4321' && $dupaScris === '4400', $dupaGol . ' / ' . $dupaScris);

    // N3: emailurile de test, fiecare tip din pagină (și cel simplu), cu numele expeditorului gol.
    $dosarMail = $dosarDev . '/mail';
    $tipuri = ['', 'new_order', 'new_order_op', 'processing', 'shipped', 'delivered', 'cancelled', 'abandoned_cart', 'account_created'];
    foreach ($tipuri as $tip) {
        $inainte = glob($dosarMail . '/*.eml') ?: [];
        $cerere('POST', '/admin/emails/test', $jarAdm, ['template_type' => $tip, 'test_email' => 'test@exemplu.ro']);
        $noi = array_values(array_diff(glob($dosarMail . '/*.eml') ?: [], $inainte));
        $eticheta = $tip === '' ? 'emailul de test simplu' : 'emailul ' . $tip;
        if (count($noi) !== 1) {
            $verifica($eticheta . ': trimis (un fișier nou în ' . $dosarMail . ')', false, count($noi) . ' fișiere noi');
            continue;
        }
        $continut = (string) file_get_contents($noi[0]);
        // Corpul e de obicei în base64 sau quoted-printable: se citește decodat.
        $text = $continut;
        if (preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $continut, $m) > 0) {
            foreach ($m[1] as $bucata) {
                $text .= "\n" . base64_decode(preg_replace('/\s+/', '', $bucata) ?? '');
            }
        }
        if (preg_match('/Content-Transfer-Encoding: quoted-printable/i', $continut) === 1) {
            $text .= "\n" . quoted_printable_decode($continut);
        }
        // Un octet invalid ar face căutarea (/u) să nu găsească nimic.
        $text = mb_scrub($text, 'UTF-8');
        if (preg_match('/^Subject: (.*)$/m', $continut, $s) === 1) {
            $text .= "\n" . iconv_mime_decode(trim($s[1]), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        }
        $faraListaNeagra('N3 ' . $eticheta, $text);
        if ($tip === '') {
            $verifica('N3 emailul de test simplu: subiectul „Email test ' . $numeFaraSetari . '” (numele de rezervă al expeditorului)',
                str_contains($text, 'Email test ' . $numeFaraSetari));
        }
    }
    foreach (array_keys($ramase) as $unde) {
        $verifica('$ramase „' . $unde . '”: verificat în această rulare', isset($ramaseVazute[$unde]));
    }
} catch (Throwable $e) {
    $verifica('rularea pe server', false, $e->getMessage());
} finally {
    $puneLaLoc();
}
$verifica('baza de dezvoltare pusă la loc exact (antetul, numele, datele operatorului GDPR, paginile „404” și „checkout/succes”)', salvari_instantaneu($db, $tabele) === $original);

$scrie('test-pachet-site neutru: ' . $verificari . ' verificări, ' . $esecuri . ' greșite');
exit($esecuri === 0 ? 0 : 1);
