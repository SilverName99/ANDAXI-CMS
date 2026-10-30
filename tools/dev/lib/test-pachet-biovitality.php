<?php

declare(strict_types=1);

/**
 * G-pachet `biovitality` (pachetul Biovitality; planul Etapei 1, §0c B5, perechea lui WP-14 pentru Biovitality): partea lui
 * tools/dev/test-pachet-site.php care verifică pachetul site-uri/biovitality/ față de codul Biovitality însuși, la „ultimul
 * commit revizuit” din docs/de-portat.md (clona CMS_DEV_REPO_BV, citită cu git show; nimic retastat aici), apoi baza de
 * dezvoltare în forma Biovitality (tools/dev/instaleaza.sh --site=biovitality, pe o bază a testului) servită cu pachetul.
 *
 * Fără server (pachetul și înghețarea lui, cu valorile private din privat/, pe care le scrie local
 * tools/inghetare/genereaza-setari.php --site=biovitality):
 *  V1 numele cookie-ului acordului (site.json) = constanta COOKIE a codului Biovitality;
 *  V2 fereastra de bun venit: codul Biovitality n-o are (nicio cheie welcome_popup_*), înghețarea o ține oprită;
 *  V3 numele site-ului (titlurile) și al expeditorului cu rândul lipsă și cu rândul gol = rezervele codului Biovitality;
 *     destinatarii formularului de contact (privați) = implicitul lui;
 *  V4 logo-ul sertarului de meniu mobil (tema.json) = calea și textul alternativ din views/layout.php al codului Biovitality,
 *     fără alte variante căutate;
 *  V5 pagina de mulțumire și iconițele categoriilor: codul Biovitality n-are nici blocul de stiluri, nici iconițele, deci nici
 *     pachetul (rămân ale nucleului);
 *  V6 descrierea unui produs fără descriere scurtă = textul din catalogul și din caruselul Biovitality;
 *  V7 tokenul de dezabonare din newsletter = formula veche, cu sarea din site.json = cea din codul Biovitality;
 *  V8 numerotarea: modul vechi (implicitul codului Biovitality) și prefixul lui;
 *  V9 cota de TVA implicită, câmpurile înregistrării, coletele neîmpărțite după greutate (fan_kg_max_colet = 0) și un singur
 *     AWB, Stripe fără comutator: înghețarea dă ce face codul Biovitality;
 *  V10 acordul GDPR: varianta „medical”, fiecare valoare înghețată (și cele private) e în textul acordului din codul
 *     Biovitality, fraza operatorului și cea a retragerii, scrise de codul comun, sunt rândurile codului Biovitality;
 *  V11 pluginurile de dezvoltare (licente_dev.biovitality): fără BT, EuPlătesc și precomenzi, pe care codul Biovitality nu le
 *     are, cu Stripe, pe care îl are;
 *  V12 pachetul e întreg (manifestul, site.json și tema/tema.json obligatorii) și nicio valoare a lui nu numește Bioscem.
 * Cu server (baza cms_pachet_bv, făcută de instaleaza.sh --site=biovitality, serverul pe CMS_DEV_PORT+25 cu server.sh
 * --site=biovitality; la final serverul oprit, baza și dosarul licenței ei șterse):
 *  S1 /health: „pachet: <amprenta pachetului Biovitality>”, baza la zi;
 *  S2 înghețarea pusă de emulare (numerotarea, prefixul, coletele, TVA, acordul, câmpurile înregistrării, descrierea), cu
 *     valorile private ca înlocuitori inventați, și nicio adresă din afara @exemplu.ro în setările de destinatar;
 *  S3 licența site-ului: pluginurile lui pornite, BT, EuPlătesc și precomenzile oprite; licența bazei cms_dev neatinsă;
 *  S4 paginile publice: cookie-ul acordului „bv_cookie_consent” în banner, sertarul cu logo-ul Biovitality, acordul GDPR cu
 *     marca Biovitality, nicio pomenire a Bioscem ($ramaseBv gol din WP-22), textele Biovitality ale listei de articole.
 *
 * Variabilele din test-pachet-site.php: $radacina, $dosarDev, $verifica, $scrie, $verificari, $esecuri.
 */

use App\Support\Brand;
use App\Support\CookieConsent;
use App\Support\Database;
use App\Support\DateSite;
use App\Support\Migrari\Inghetare;
use App\Support\PachetSite;
use App\Support\Settings;
use App\Support\Tema;

$scrie('== G-pachet biovitality: pachetul site-uri/biovitality/ dă valorile codului Biovitality');
$clona = (string) (getenv('CMS_DEV_REPO_BV') ?: dirname($radacina) . '/Mutare-Site-Biovitality');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/de-portat.php') . ' --revizuit=biovitality 2>/dev/null', $iesire, $cod);
$commit = trim(implode('', $iesire));
if ($cod !== 0 || preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1 || !is_dir($clona)) {
    $scrie('EROARE: nu găsesc clona Biovitality (' . $clona . ') sau „ultimul commit revizuit” din docs/de-portat.md');
    exit(1);
}
$scrie('  INFO  codul Biovitality: ' . basename($clona) . '@' . $commit);
$pachetBv = $radacina . '/site-uri/biovitality';

try {
    /** Un fișier al codului Biovitality la commit-ul revizuit ('' dacă lipsește și $poateLipsi). */
    $vechi = static function (string $fisier, bool $poateLipsi = false) use ($clona, $commit): string {
        $text = shell_exec('git -C ' . escapeshellarg($clona) . ' show ' . escapeshellarg($commit . ':' . $fisier) . ' 2>/dev/null');
        if (!is_string($text) || $text === '') {
            if ($poateLipsi) {
                return '';
            }
            throw new RuntimeException('codul Biovitality nu are ' . $fisier . ' la ' . $commit);
        }
        return $text;
    };
    /** Grupul 1 al unui tipar din codul Biovitality (exact o valoare). */
    $dinVechi = static function (string $fisier, string $tipar) use ($vechi): string {
        preg_match_all($tipar, $vechi($fisier), $m);
        $valori = array_values(array_unique($m[1] ?? []));
        if (count($valori) !== 1) {
            throw new RuntimeException('tiparul ' . $tipar . ' găsește ' . count($valori) . ' valori în ' . $fisier . ' (trebuie una)');
        }
        return stripcslashes($valori[0]);
    };
    $faraFisier = static function (string $fisier) use ($clona, $commit): bool {
        exec('git -C ' . escapeshellarg($clona) . ' cat-file -e ' . escapeshellarg($commit . ':' . $fisier) . ' 2>/dev/null', $o, $c);
        return $c !== 0;
    };

    // V1, V2: cookie-ul și fereastra de bun venit.
    $verifica('V1 cookie-ul acordului: ' . CookieConsent::nume() . ' (site.json) = constanta COOKIE din src/Support/CookieConsent.php al Biovitality',
        CookieConsent::nume() === $dinVechi('src/Support/CookieConsent.php', "/const COOKIE = '([^']+)'/"));
    $inghetare = Inghetare::dinFisier($pachetBv . '/setari-inghetate.json');
    try {
        $private = $inghetare->valoriPrivate('site/biovitality/setari-inghetate');
    } catch (Throwable $e) {
        $scrie('EROARE: ' . $e->getMessage() . '. Scrie-l din clona Biovitality: tools/dev/php.sh tools/inghetare/genereaza-setari.php --site=biovitality');
        exit(1);
    }
    /** Setările unei baze Biovitality după pasul înghețării (regulile lui Schema::setare): $randuri = ce avea baza înainte. */
    $dupaInghetare = static function (array $randuri) use ($inghetare, $private): array {
        foreach ($inghetare->setari() as $cheie => $intrare) {
            $valoare = $intrare['privat'] ? $private[$cheie] : (string) $intrare['valoare'];
            if (!array_key_exists($cheie, $randuri) || ($intrare['mod'] === 'lipsa_sau_gol' && trim((string) $randuri[$cheie]) === '')) {
                $randuri[$cheie] = $valoare;
            }
        }
        return array_merge((new ReflectionClassConstant(Settings::class, 'DEFAULTS'))->getValue(), $randuri);
    };
    $faraRand = $dupaInghetare([]);
    $cuGol = $dupaInghetare(['order_email_from_name' => '', 'store_name' => '']);
    $layoutVechi = $vechi('views/layout.php');
    $verifica('V2 fereastra de bun venit: codul Biovitality n-are nicio cheie welcome_popup_*, înghețarea o ține oprită (și cu rândul lipsă)',
        !str_contains($layoutVechi, 'welcome_popup') && !str_contains($vechi('src/Support/Settings.php'), 'welcome_popup')
        && $faraRand['welcome_popup_enabled'] === '0');

    // V3: numele site-ului și al expeditorului, destinatarii.
    $numeVechi = $dinVechi('views/layout.php', "/\\\$designSettings\\['store_name'\\] \\?\\? '([^']+)'/");
    $implicitVechi = static fn (string $cheie): string => $dinVechi('src/Support/Settings.php', "/'" . preg_quote($cheie, '/') . "' => '((?:[^'\\\\]|\\\\.)*)'/");
    $verifica('V3 numele site-ului cu rândul lipsă și cu rândul gol: „' . Brand::numeSite($faraRand) . '” = rezerva din views/layout.php al Biovitality („'
        . $numeVechi . '”)', Brand::numeSite($faraRand) === $numeVechi && Brand::numeSite($cuGol) === $numeVechi);
    $expeditorVechi = $implicitVechi('order_email_from_name');
    $verifica('V3 numele expeditorului cu rândul lipsă și cu rândul gol: „' . $expeditorVechi . '” (implicitul codului Biovitality)',
        Brand::numeExpeditor($faraRand) === $expeditorVechi && Brand::numeExpeditor($cuGol) === $expeditorVechi);
    $verifica('V3 destinatarii formularului de contact cu rândul lipsă și cu rândul gol = implicitul codului Biovitality (valoare privată, din privat/)',
        Brand::adrese((string) $faraRand['contact_form_recipients']) === [$implicitVechi('contact_form_recipients')]
        && Brand::emailContact(null, $dupaInghetare(['contact_form_recipients' => ' '])) === $implicitVechi('contact_form_recipients'));

    // V4, V5: tema.
    $logo = Tema::logoMeniuMobil();
    $verifica('V4 logo-ul sertarului de meniu (tema.json) = calea și textul alternativ din views/layout.php al Biovitality, fără variante căutate',
        $logo === ['cale' => $dinVechi('views/layout.php', "/\\\$mobileMenuLogoPath = '([^']+)';/"),
            'alt' => $dinVechi('views/layout.php', "/htmlspecialchars\\(\\\$mobileMenuLogoPath, ENT_QUOTES\\) \\. '\" alt=\"([^\"]+)\"/")]
        && (Tema::valori()['logo_meniu_mobil']['candidati'] ?? null) === [] && !str_contains($layoutVechi, '$logoCandidate'),
        json_encode($logo, JSON_UNESCAPED_UNICODE) ?: '');
    $verifica('V5 pagina de mulțumire și iconițele categoriilor: codul Biovitality n-are blocul de stiluri și nici HomeSections, deci pachetul '
        . 'nu le are (rămân ale nucleului)', !str_contains($vechi('views/site/checkout-success.php'), '<style')
        && $faraFisier('src/Support/HomeSections.php') && Tema::cssInline('pagina_succes', 'NUCLEU') === 'NUCLEU' && Tema::iconiteCategorii() === null);

    // V6: descrierea implicită.
    $verifica('V6 descrierea unui produs fără descriere scurtă = textul din catalogul și din caruselul Biovitality',
        $faraRand['catalog_descriere_implicita'] === $dinVechi('views/site/components/product-card.php', "/\\\$desc !== '' \\? \\\$desc : '([^']+)'/")
        && $faraRand['catalog_descriere_implicita'] === $dinVechi('views/site/product.php', "/item\\.short_description \\|\\| '([^']+)'/"));

    // V7: newsletterul.
    $sareVeche = $dinVechi('src/Support/NewsletterService.php', "/'newsletter-unsubscribe\\|' \\. \\\$url \\. '\\|' \\. \\\$dbName \\. '\\|([a-z]+)'\\)/");
    $config = require $radacina . '/config/app.php';
    $cheieVeche = hash('sha256', 'newsletter-unsubscribe|' . (string) ($config['url'] ?? '') . '|' . (string) ($config['db']['database'] ?? '') . '|' . $sareVeche);
    // Din WP-R (Bioscem 739f58c) linkurile noi se semnează cu cheia nouă, aleatoare; cheia veche (cea a linkurilor din
    // emailurile Biovitality de până la adoptare) rămâne crezută până la prag și trebuie să fie exact formula veche.
    $cheieVecheNucleu = (new ReflectionMethod(\App\Support\NewsletterService::class, 'cheieVeche'))->invoke(null);
    $verifica('V7 newsletterul: sarea „' . $sareVeche . '” (site.json) = cea din codul Biovitality; cheia veche (a linkurilor de dezabonare de '
        . 'până la adoptare) = formula veche', $cheieVecheNucleu === $cheieVeche && \App\Support\NewsletterService::sursaCheiiSemnare() === 'site.json');

    // V8: numerotarea.
    $numarVechi = \App\Support\OrderNumber::vechi($faraRand);
    $prefixVechi = $dinVechi('src/Support/OrderNumber.php', "/return '([A-Z]+)' \\. date\\('YmdHis'\\)/");
    $modVechi = $dinVechi('src/Support/OrderNumber.php', "/const MOD_VECHI = '([a-z]+)'/");
    $verifica('V8 numerotarea: modul „' . $faraRand['order_number_mode'] . '” (implicitul codului Biovitality e MOD_VECHI, „' . $modVechi . '”), formatul vechi „'
        . $numarVechi . '” cu prefixul lui („' . $prefixVechi . '”)', $faraRand['order_number_mode'] === $modVechi
        && str_contains($vechi('src/Support/OrderNumber.php'), 'MOD_IMPLICIT = self::MOD_VECHI') && preg_match('/^' . $prefixVechi . '\d{17}$/', $numarVechi) === 1);

    // V9: TVA, înregistrarea, coletele, Stripe.
    $siteVechi = $vechi('src/Http/Controllers/SiteController.php');
    $erpVechi = $vechi('src/Support/ErpSync.php');
    $verifica('V9 cota de TVA implicită „' . Settings::cotaTvaImplicita($faraRand) . '” = rezerva codului Biovitality',
        Settings::cotaTvaImplicita($faraRand) === $dinVechi('views/admin/settings-store.php', "/\\\$settings\\['default_vat_percent'\\] \\?\\? '([0-9]+)'/"));
    $verifica('V9 câmpurile înregistrării (prenume, nume, telefon) cerute ca în codul Biovitality (rezerva „1”)', $faraRand['customer_registration_field_first_name'] === '1'
        && $faraRand['customer_registration_field_last_name'] === '1' && $faraRand['customer_registration_field_phone'] === '1'
        && str_contains($siteVechi, "(\$settings['customer_registration_field_first_name'] ?? '1')"));
    $verifica('V9 coletele: fan_kg_max_colet = 0 și un singur AWB, ca ErpSync::coleteCalcul al codului Biovitality (kgMaxColet 0.0, unSingurAwb true)',
        $faraRand['fan_kg_max_colet'] === '0' && $faraRand['fan_awb_single'] === '1' && str_contains($erpVechi, "'kgMaxColet' => 0.0,")
        && str_contains($erpVechi, "'unSingurAwb' => true,"));
    $verifica('V9 Stripe: codul Biovitality n-are comutatorul stripe_enabled (îl oferă doar cu cheia), înghețarea îl pornește',
        $faraRand['stripe_enabled'] === '1' && !str_contains($siteVechi, 'stripe_enabled') && !str_contains($vechi('src/Support/Settings.php'), 'stripe_enabled'));

    // V10: acordul GDPR.
    $gdpr = array_filter($faraRand, static fn (string $cheie): bool => str_starts_with($cheie, 'gdpr_'), ARRAY_FILTER_USE_KEY);
    $lipsaDinVechi = [];
    foreach ($gdpr as $cheie => $valoare) {
        if ($cheie !== 'gdpr_formular_varianta' && ($valoare === '' || !str_contains($siteVechi, htmlspecialchars($valoare, ENT_QUOTES)))) {
            $lipsaDinVechi[] = $cheie;
        }
    }
    $intrari = $inghetare->setari();
    $verifica('V10 acordul GDPR: varianta „medical” și cele 10 valori înghețate (' . count($gdpr) . ' chei) sunt în textul acordului din codul Biovitality; '
        . 'reprezentantul, telefonul și emailul sunt private', ($gdpr['gdpr_formular_varianta'] ?? '') === 'medical' && count($gdpr) === 11
        && $lipsaDinVechi === [] && ($intrari['gdpr_operator_reprezentant']['privat'] ?? false) === true
        && ($intrari['gdpr_operator_telefon']['privat'] ?? false) === true && ($intrari['gdpr_operator_email']['privat'] ?? false) === true,
        'lipsă din codul vechi: ' . implode(', ', $lipsaDinVechi));
    $siteNou = (string) file_get_contents($radacina . '/src/Http/Controllers/SiteController.php');
    $email = htmlspecialchars($gdpr['gdpr_operator_email'], ENT_QUOTES);
    $inlocuiri = ['{$operatorNume}' => $gdpr['gdpr_operator_nume'], '{$operatorSediu}' => $gdpr['gdpr_operator_sediu'],
        '{$operatorTelefon}' => $gdpr['gdpr_operator_telefon'], '{$operatorRegCom}' => $gdpr['gdpr_operator_regcom'],
        '{$operatorCui}' => $gdpr['gdpr_operator_cui'], '{$operatorMarca}' => $gdpr['gdpr_operator_marca'],
        '{$operatorReprezentant}' => $gdpr['gdpr_operator_reprezentant']];
    $rand = static function (string $text, string $inceput): string {
        preg_match_all('/^[ \t]*' . preg_quote($inceput, '/') . '.*$/mu', $text, $m);
        return count($m[0]) === 1 ? trim($m[0][0]) : '(' . count($m[0]) . ' rânduri)';
    };
    $operatorNou = strtr($rand($siteNou, 'de către operatorul de date cu caracter personal <strong>{$operatorNume}'), array_map(
        static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES), $inlocuiri));
    $retragereNou = strtr($rand($siteNou, '<p>Prezentul consimțământ este valabil până la retragerea expresă'), ['{$adresaRetragere}'
        => htmlspecialchars($gdpr['gdpr_operator_adresa_retragere'], ENT_QUOTES), '{$legaturaEmail}' => '<a href="mailto:' . $email . '">' . $email . '</a>']);
    $verifica('V10 acordul GDPR: fraza operatorului și cea a retragerii, scrise de codul comun cu valorile înghețate = rândurile codului Biovitality',
        $operatorNou === $rand($siteVechi, 'de către operatorul de date cu caracter personal <strong>')
        && $retragereNou === $rand($siteVechi, '<p>Prezentul consimțământ este valabil până la retragerea expresă'));

    // V11: pluginurile de dezvoltare ale site-ului.
    $catalog = require $radacina . '/config/plugins.php';
    $licentaBv = (array) ($catalog['licente_dev']['biovitality'] ?? []);
    $verifica('V11 licente_dev.biovitality: fără plata_bt, plata_euplatesc și precomenzi (codul Biovitality n-are BtIpay*, EuPlatesc* și Precomanda), '
        . 'cu plata_stripe (are StripeGateway)', $licentaBv !== [] && array_intersect(['plata_bt', 'plata_euplatesc', 'precomenzi'], $licentaBv) === []
        && $faraFisier('src/Support/BtIpayGateway.php') && $faraFisier('src/Support/EuPlatescGateway.php') && $faraFisier('src/Support/Precomanda.php')
        && in_array('plata_stripe', $licentaBv, true) && !$faraFisier('src/Support/StripeGateway.php'));

    // V12: pachetul.
    $probleme = PachetSite::verifica($pachetBv, 'biovitality');
    $manifest = PachetSite::manifest($pachetBv);
    $valoriPachet = json_encode([DateSite::site('cookie_consimtamant', ''), DateSite::site('newsletter_sare_semnatura', ''), Tema::valori(),
        array_map(static fn (array $i): string => (string) $i['valoare'], $inghetare->setari())], JSON_UNESCAPED_UNICODE) ?: '';
    $verifica('V12 pachetul e întreg (manifestul; site.json și tema/tema.json obligatorii), iar nicio valoare a lui (site.json, tema, înghețarea) nu '
        . 'numește Bioscem', $probleme === [] && array_values((array) ($manifest['obligatorii'] ?? [])) === ['site.json', 'tema/tema.json']
        && preg_match('/bioscem/i', $valoriPachet) !== 1, implode('; ', $probleme));
} catch (Throwable $e) {
    $verifica('citirea codului Biovitality și a pachetului', false, $e->getMessage());
}

// ── Cu server: baza în forma Biovitality ──
$bazaBv = 'cms_pachet_bv';
$portBv = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 25;
$dosarLicentaBv = $dosarDev . '/licenta-pachet-bv';
$mediuBv = ['CMS_DEV_DB' => $bazaBv, 'CMS_DEV_PORT' => (string) $portBv, 'CMS_DEV_LICENTA_DOSAR' => $dosarLicentaBv];
$licentaDev = static function () use ($radacina): array {
    $r = [];
    foreach (glob($radacina . '/storage/licenta/*.json') ?: [] as $f) {
        $r[basename($f)] = sha1((string) file_get_contents($f));
    }
    return $r;
};
$licentaInainte = $licentaDev();
$unealta = static function (array $comanda) use ($radacina, $mediuBv): array {
    $p = proc_open($comanda, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tevi, $radacina, $mediuBv + getenv());
    $iesire = (string) stream_get_contents($tevi[1]);
    fclose($tevi[1]);
    return [proc_close($p), $iesire];
};
$cerereBv = static function (string $cale) use ($portBv): array {
    $c = curl_init('http://127.0.0.1:' . $portBv . $cale);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROXY => '*', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60]);
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'corp' => $corp];
};
$curataBv = static function () use ($unealta, $radacina, $dosarLicentaBv, $dosarDev): void {
    $unealta([$radacina . '/tools/dev/server.sh', '--site=biovitality', '--opreste']);
    $unealta([$radacina . '/tools/dev/instaleaza.sh', '--sterge-baza']);
    if (str_starts_with($dosarLicentaBv, $dosarDev . '/') && is_dir($dosarLicentaBv)) {
        exec('rm -rf ' . escapeshellarg($dosarLicentaBv));
    }
};
register_shutdown_function($curataBv);
try {
    [$codInst, $iesireInst] = $unealta([$radacina . '/tools/dev/instaleaza.sh', '--site=biovitality']);
    $verifica('instaleaza.sh --site=biovitality (baza ' . $bazaBv . '): install, înghețarea emulată, datele demo cu antetul, licența, garda adreselor',
        $codInst === 0 && str_contains($iesireInst, 'Înghețarea biovitality: ') && str_contains($iesireInst, 'Garda adreselor: toate')
        && str_contains($iesireInst, 'Antetul demo al site-ului biovitality'), mb_substr($iesireInst, -1500));
    if ($codInst !== 0) {
        throw new RuntimeException('baza în forma Biovitality nu s-a făcut');
    }
    $app = require $radacina . '/config/app.php';
    $db = Database::connection(array_merge((array) $app['db'], ['database' => $bazaBv]));
    if (!$db instanceof PDO) {
        throw new RuntimeException('nu mă leg la ' . $bazaBv);
    }
    $setari = $db->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $inghetareBv = Inghetare::dinFisier($pachetBv . '/setari-inghetate.json');
    $asteptate = ['order_number_mode', 'order_number_legacy_prefix', 'fan_kg_max_colet', 'default_vat_percent', 'gdpr_formular_varianta', 'gdpr_operator_marca',
        'gdpr_scop', 'gdpr_operator_reprezentant', 'gdpr_operator_telefon', 'gdpr_operator_email', 'customer_registration_field_phone', 'catalog_descriere_implicita',
        'catalog_ascunde_inactive'];
    $altfel = [];
    foreach ($asteptate as $cheie) {
        if (($setari[$cheie] ?? null) !== (string) ($inghetareBv->setari()[$cheie]['valoare'] ?? '?')) {
            $altfel[] = $cheie;
        }
    }
    // Ce scriu datele demo după emulare (numele, adresele @exemplu.ro, plățile de test, fereastra demo) rămâne, ca o setare schimbată de client.
    $verifica('S2 înghețarea Biovitality pusă de emulare (' . count($asteptate) . ' chei verificate: numerotarea, prefixul, coletele, TVA, acordul, '
        . 'înregistrarea, catalogul), cu valorile private ca înlocuitori inventați', $altfel === []
        && Inghetare::eInlocuitor((string) ($setari['gdpr_operator_reprezentant'] ?? '')) && Inghetare::eInlocuitor((string) ($setari['gdpr_operator_email'] ?? '')),
        implode(', ', $altfel));
    $rele = [];
    foreach ($setari as $cheie => $valoare) {
        if (preg_match('/(email|recipient|notify|from_address)/', (string) $cheie) === 1 && Inghetare::emailuriReale((string) $valoare) !== []) {
            $rele[] = (string) $cheie;
        }
    }
    $verifica('S2 garda: nicio setare de destinatar sau de expeditor cu o adresă din afara @exemplu.ro', $rele === [], implode(', ', $rele));
    [$codStari, $stari] = $unealta([$radacina . '/tools/dev/php.sh', 'tools/dev/licenta.php', 'stari']);
    $pornite = [];
    foreach (explode("\n", trim($stari)) as $linie) {
        [$cheie, $stare] = array_pad(explode(' ', trim($linie), 2), 2, '');
        if ($stare === 'pornit') {
            $pornite[] = $cheie;
        }
    }
    sort($pornite);
    $licentaAsteptata = (array) ((require $radacina . '/config/plugins.php')['licente_dev']['biovitality'] ?? []);
    sort($licentaAsteptata);
    $verifica('S3 licența bazei: exact pluginurile de dezvoltare ale Biovitality pornite (BT, EuPlătesc, precomenzile oprite), în dosarul ei; '
        . 'licența bazei cms_dev (storage/licenta) neatinsă', $codStari === 0 && $pornite === $licentaAsteptata && $licentaDev() === $licentaInainte
        && is_file($dosarLicentaBv . '/licenta.json'), $stari);

    [$codServer, $iesireServer] = $unealta([$radacina . '/tools/dev/server.sh', '--site=biovitality']);
    if ($codServer !== 0) {
        throw new RuntimeException('server.sh --site=biovitality nu pornește: ' . $iesireServer);
    }
    $health = $cerereBv('/health')['corp'];
    $verifica('S1 /health pe baza Biovitality, cu pachetul ei: „pachet: ' . PachetSite::amprenta($pachetBv) . '”, schema la zi',
        $health === "ok\nschema: la_zi\npachet: " . PachetSite::amprenta($pachetBv), str_replace("\n", ' | ', $health));

    // Bannerul de cookie-uri se vede doar cu un text (înghețat gol, ca la Biovitality): pentru verificare, un text inventat.
    $db->exec("UPDATE settings SET `value` = 'Folosim cookie-uri (text de test).' WHERE `key` = 'cookie_banner_text'");
    $prima = $cerereBv('/')['corp'];
    $verifica('S4 prima pagină: bannerul scrie cookie-ul „bv_cookie_consent”, iar sertarul de meniu are logo-ul Biovitality ('
        . '/uploads/gallery/biovitality-logo.png, „BioVitality”)', str_contains($prima, 'document.cookie = "bv_cookie_consent" + ')
        && str_contains($prima, '<img src="/uploads/gallery/biovitality-logo.png" alt="BioVitality"'));
    $acord = $cerereBv('/acorduri-gdpr');
    $verifica('S4 /acorduri-gdpr: varianta „medical”, marca „Biovitality”, valorile private ca înlocuitori inventați', $acord['cod'] === 200
        && str_contains($acord['corp'], 'prin marca <strong>„Biovitality”</strong>') && str_contains($acord['corp'], 'la evenimentul medical')
        && str_contains($acord['corp'], 'Valoare demo') && str_contains($acord['corp'], 'adresa1@exemplu.ro'));
    // Ce mai pomenește Bioscem pe un site Biovitality, cu WP-ul care îl scoate (aceeași listă ca $ramase din modul neutru).
    // Gol din WP-22 (DB-13): /blog are textele Biovitality din înghețare.
    $ramaseBv = [];
    $listaBlog = $cerereBv('/blog')['corp'];
    $verifica('S4 /blog: eticheta „Jurnalul BioVitality” și descrierea din înghețare, blogul avansat pornit (căutarea)',
        str_contains($listaBlog, '<span class="blog-listing__eyebrow">Jurnalul BioVitality</span>')
        && str_contains($listaBlog, '<p>Articole utile despre wellness, nutriție și un stil de viață echilibrat.</p>')
        && str_contains($listaBlog, '<form class="blog-search"'));
    $slugProdus = (string) $db->query('SELECT slug FROM products WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();
    $slugBlog = (string) $db->query('SELECT slug FROM blog_posts WHERE is_published = 1 ORDER BY id LIMIT 1')->fetchColumn();
    foreach (['/', '/magazin', '/produs/' . $slugProdus, '/cos', '/checkout', '/contact', '/blog', '/blog/' . $slugBlog, '/acorduri-gdpr', '/login',
        '/register', '/pagina-care-nu-exista', '/robots.txt', '/sitemap.xml'] as $cale) {
        $r = $cerereBv($cale);
        $gasit = preg_match('/(?<![\p{L}\p{N}])bioscem(?![\p{L}\p{N}])/iu', $r['corp']) === 1;
        if (isset($ramaseBv[$cale])) {
            $verifica('S4 ' . $cale . ': Bioscem încă pomenit (rămâne pentru ' . $ramaseBv[$cale] . '; scoate rândul din $ramaseBv când nu mai e)', $gasit);
            continue;
        }
        $verifica('S4 ' . $cale . ' (' . $r['cod'] . '): niciun „Bioscem”', $r['cod'] > 0 && !$gasit);
    }
} catch (Throwable $e) {
    $verifica('baza și serverul în forma Biovitality', false, $e->getMessage());
} finally {
    $curataBv();
}
$verifica('la final: serverul oprit, baza ' . $bazaBv . ' și dosarul licenței ei șterse, licența bazei cms_dev neatinsă', !is_dir($dosarLicentaBv)
    && $cerereBv('/health')['cod'] === 0 && $licentaDev() === $licentaInainte);

$scrie('test-pachet-site biovitality: ' . $verificari . ' verificări, ' . $esecuri . ' greșite');
exit($esecuri === 0 ? 0 : 1);
