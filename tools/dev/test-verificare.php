<?php

declare(strict_types=1);

/**
 * Testele site-ului de verificare (docs/VERIFICARE.md): poarta, configurarea, starea, adresa
 * vizitatorului (WP-08a), cele 14 gărzi ale copiei, licența și ștergerea copiei (WP-08).
 *
 *   tools/dev/php.sh tools/dev/test-verificare.php
 *   CMS_DEV_PHP=php8.3 tools/dev/php.sh tools/dev/test-verificare.php   # cu PHP-ul minim
 *
 * A. Fără server: `IpClient` (X-Forwarded-For inventat de vizitator ignorat, lanțul de
 *    proxy-uri de încredere, porturi, IPv6, rețele, clase), credențialele HTTP Basic din
 *    fiecare loc în care le poate lăsa serverul web, cookie-ul semnat al porții, dosarul
 *    site-ului (`~/domains/<gazdă>/public_html`, nu `~/public_html`, nu un subdosar),
 *    citirea unui .env exact ca `Env::load`; gărzile ca funcții (destinatarul și subiectul
 *    emailurilor, nota destinatarului, ERP-ul doar spre *-verif.erp.andaxi.ro, Stripe doar
 *    sk_test_, BT doar test, EuPlătesc și FAN oprite, etichetele, login-ul Google, adresa
 *    pozelor lipsă cu fiecare cale rea refuzată, bannerul, webhook-urile, expirarea), fiecare
 *    și în afara copiei (nu schimbă nimic). PHP-urile sub minim: configureaza.php, stare.php,
 *    licenta.php și sterge.php spun singure că PHP-ul e prea vechi (cod 1).
 * B. O copie a codului (fișierele din git, cu modificările necomise) într-o găzduire
 *    simulată, `$CMS_DEV_DIR/verificare/acasa/domains/verificare.exemplu.test/public_html`,
 *    servită de `php -S` pe CMS_DEV_PORT+7 cu un mediu gol (ca pe Hostinger: totul din .env):
 *    B1 fără .env: orice cerere (pagini, admin, POST, robots, health, ping, fișiere, .env, .git,
 *       sonda) → 503 „neconfigurat”, noindex, no-store, fără sesiune; stare.php →
 *       „NECONFIGURAT” și „503 = DA”.
 *    B2 configureaza.php: refuzurile (dosar greșit, bază cu tabele, parola bazei greșită,
 *       parole ale porții diferite, scurte sau egale cu parola bazei, .env existent,
 *       --rescrie peste .env-ul unui site adevărat) fără să scrie nimic; apoi configurarea
 *       pe o bază goală: .env 0600 cu cheile așteptate, parola porții doar ca hash, niciun
 *       tabel creat, nicio parolă sau adresă de email pe ecran. Într-un terminal adevărat
 *       (pty, dacă există python3; tools/dev/lib/terminal.py): Ctrl-C în mijlocul unei
 *       parole → cod 130 și terminalul înapoi cu ecou, cu și fără pcntl; SIGTERM la fel;
 *       parola nu apare pe ecran, Backspace o corectează; Ctrl-C la o întrebare obișnuită
 *       oprește pe loc.
 *       Plus (WP-08): o bază scrisă în .env-ul altui site din cont → refuz; perechea de chei a
 *       licenței (cheia privată 0600, potrivită cu LICENTA_CHEIE_PUBLICA), păstrată la --rescrie.
 *    B3 poarta: 401 fără parolă (Basic și formularul în pagină) pe orice adresă și metodă;
 *       robots „Disallow: /”, health și pingul (`/_andaxi/ping`, al aplicației: „fara_registru” pe
 *       baza goală, niciun tabel; POST și alte adrese /_andaxi/ cer parola) fără parolă; parola
 *       bună → 503 „copia lipsește”;
 *       parola greșită → 401; a 11-a încercare greșită în 10 minute → 429 (și cu parola
 *       bună), cu amprente în loc de adrese pe disc; IPv6 numărat pe rețeaua /64 (10 adrese
 *       din același /64 → 429 pentru tot /64-ul); formularul (cookie, întoarcerea doar pe o
 *       cale locală), cookie falsificat, câmpuri trimise ca liste (tot închis, nimic în
 *       jurnal), ieșirea, HEAD, modul „formular”; webhook-urile (doar POST, doar cu
 *       VERIFICARE_WEBHOOKURI_DESCHISE=1 și doar cu o copie); sonda (un jeton, o dată, cu
 *       gărzile văzute de PHP-ul site-ului); stare.php → „GATA: …”, cele 14 gărzi DA, fără
 *       secrete; apoi fiecare gardă stricată pe rând în copia codului (14 mutații) → rândul
 *       ei NU și fără „GATA”; --rescrie cu altă parolă anulează cookie-urile vechi.
 *    B4 copie „încărcată” (cms_verif_copie: o copie a bazei de dezvoltare, cu _verificare, și
 *       licența pusă de tools/verificare/licenta.php toate): cu parola, paginile aplicației
 *       răspund 200, cu noindex și no-store chiar și unde aplicația pune alt antet (sitemap);
 *       fără parolă tot 401, în afară de /health, pe care îl dă aplicația (schema: la_zi; 503
 *       cu ultima_ord coborât o clipă), și de ping („la_zi”); copie expirată, cu dată de
 *       necitit sau din viitor → 503 (/health: din nou „ok” al porții).
 *    B5 gărzile, cu setările unui site adevărat lăsate în baza copiei (SMTP, Stripe live,
 *       EuPlătesc, ERP, FAN, BT live în .env, GA/GTM/Ads/Clarity/tawk.to, login Google):
 *       bannerul (adresa ascunsă pe site, întreagă în admin), nicio etichetă a terților chiar cu
 *       „Accept toate”, pozele lipsă → 302 spre VERIFICARE_UPLOADS_DIN (și poza de distribuire),
 *       căile rele 404, fluxul Merchant doar cu parola și noindex, robots fără „Allow”, login-ul
 *       Google oprit, checkout-ul fără EuPlătesc și BT, plata Stripe și tariful FAN refuzate, o
 *       comandă cu produsul refrigerat: emailurile (clientului și adminului, cu blocul
 *       avertismentului) doar la proprietar, cu „[VERIFICARE]”, prin căsuța copiei (serverul SMTP
 *       al testului), niciunul la SMTP-ul din setări; limita de 100 pe zi (sărit, nimic trimis);
 *       fără căsuță, fișier .eml 0600; din linia de comandă fiecare loc din cod refuză, iar
 *       drumurile permise (sk_test_, o copie *-verif a ERP-ului, contul FAN și BT de test)
 *       ajung la plasa de siguranță, ca să se vadă că ea chiar numără.
 *    B6 SITE_COPIE_VERIFICARE=0, cu aceleași setări: poarta și gărzile nu fac nimic (pagina
 *       aplicației cu etichetele terților, login-ul Google, EuPlătesc și BT în checkout, robots-ul
 *       aplicației, fără banner), deci testul vede ce ar opri gărzile.
 *    B7 tools/verificare/licenta.php (refuzurile, un pachet cu un plugin oprit, înapoi la
 *       „toate”) și tools/verificare/sterge.php (refuzurile: alt dosar, „nu”, baza altui site,
 *       o bază fără _verificare, un .env fără DB_DATABASE; apoi ștergerea: tabelele, incarcat-la, emailurile, temporarele,
 *       cache-ul, sesiunile, cod-vechi/ fără să urmeze o legătură spre afară; rămân .gitkeep,
 *       sondele, .env, licența).
 *    Pe tot parcursul: proxy-ul copiei e „gaura neagră cu contoare” (tools/dev/lib/gaura-neagra.php,
 *       CMS_DEV_PORT+12; SMTP-ul copiei +13; adresele interzise, SMTP-ul și ERP-ul din setări, FAN_API_BASE,
 *       banca: +14): la final doar ieșirile permise de mai sus; niciun tabel în baza goală, nicio sesiune
 *       pornită de o cerere oprită la poartă, niciun email prin mail(), jurnalul PHP al copiei fără avertismente.
 *
 * Bazele cms_verif_gol, cms_verif_plina, cms_verif_copie și utilizatorul lor se fac și se șterg
 * aici; copia, serverul ei și gaura neagră se opresc și se șterg la final. Raportul:
 * $CMS_DEV_DIR/test-verificare-raport.txt; cod de ieșire 0 doar dacă totul a trecut.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
require_once $radacina . '/src/Support/Env.php';
require_once $radacina . '/src/Support/IpClient.php';
require_once $radacina . '/src/Support/Verificare.php';

use App\Support\IpClient;
use App\Support\Verificare;

$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-verificare-raport.txt';
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

$scrie('Test verificare ANDAXI-CMS — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

// =====================================================================
// A. Fără server
// =====================================================================
$scrie('');
$scrie('A. Fără server');

$ip = static fn (array $server, string $incredere = ''): string => IpClient::dinCerere($server, $incredere);
$verifica('IpClient: fără proxy de încredere, X-Forwarded-For inventat e ignorat',
    $ip(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']) === '203.0.113.5');
$verifica('IpClient: REMOTE_ADDR care nu e de încredere: antetul e ignorat și cu o listă',
    $ip(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], '10.0.0.0/8') === '203.0.113.5');
$verifica('IpClient: de la un proxy de încredere, prima adresă din dreapta care nu e de încredere (partea inventată, din stânga, nu contează)',
    $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7'], '10.0.0.0/8') === '198.51.100.7');
$verifica('IpClient: două proxy-uri de încredere în lanț',
    $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.9'], '10.0.0.0/8, 192.168.0.0/16') === '198.51.100.7');
$verifica('IpClient: proxy de încredere fără antet → REMOTE_ADDR',
    $ip(['REMOTE_ADDR' => '10.0.0.5'], '10.0.0.0/8') === '10.0.0.5');
$verifica('IpClient: lanț stricat → ultima adresă scrisă de un proxy de încredere',
    $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'nu-e-adresa, 10.0.0.9'], '10.0.0.0/8') === '10.0.0.9');
$verifica('IpClient: portul de după adresă (IPv4 și [IPv6]:port)',
    $ip(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7:5555'], '10.0.0.0/8') === '198.51.100.7'
    && $ip(['REMOTE_ADDR' => '2001:db8::1', 'HTTP_X_FORWARDED_FOR' => '[2001:db9::5]:443'], '2001:db8::/32') === '2001:db9::5');
$verifica('IpClient: IPv4 scris ca IPv6 (::ffff:10.0.0.5) e tot 10.0.0.5',
    $ip(['REMOTE_ADDR' => '::ffff:10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], '10.0.0.0/8') === '198.51.100.7'
    && $ip(['REMOTE_ADDR' => '::ffff:10.0.0.5']) === '10.0.0.5');
$verifica('IpClient: fără REMOTE_ADDR → șir gol; o valoare care nu e adresă rămâne cum e',
    $ip([]) === '' && $ip(['REMOTE_ADDR' => ' ceva ']) === 'ceva');
$verifica('IpClient: rețele (172.16.0.0/12, o singură adresă, prefix invalid ignorat)',
    IpClient::inRetea('172.31.255.255', '172.16.0.0/12') && !IpClient::inRetea('172.32.0.0', '172.16.0.0/12')
    && IpClient::inRetea('10.1.2.3', '10.1.2.3') && !IpClient::inRetea('10.1.2.4', '10.1.2.3')
    && !IpClient::inRetea('10.0.0.1', '10.0.0.0/33') && IpClient::retele('10.0.0.0/8, nimic 192.168.0.0/16 1.2.3.4/x') === ['10.0.0.0/8', '192.168.0.0/16']);
$verifica('IpClient: clasele (loopback, privată, CGNAT, publică, invalidă)',
    IpClient::clasa('127.0.0.1') === 'loopback (127.0.0.0/8)' && IpClient::clasa('10.2.3.4') === 'privată (10.0.0.0/8)'
    && IpClient::clasa('100.64.1.1') === 'CGNAT (100.64.0.0/10)' && IpClient::clasa('8.8.8.8') === 'publică'
    && IpClient::clasa('x') === 'invalidă' && IpClient::clasa('fd00::1') === 'privată (fc00::/7)');
$verifica('IpClient::cheieLimita: IPv4 așa cum e (și scris ca IPv6), IPv6 → rețeaua /64, altceva neatins',
    IpClient::cheieLimita('198.51.100.7') === '198.51.100.7' && IpClient::cheieLimita('::ffff:198.51.100.7') === '198.51.100.7'
    && IpClient::cheieLimita('2001:db8:1:2::1') === '2001:db8:1:2::/64'
    && IpClient::cheieLimita('2001:0DB8:0001:0002:ffff:ffff:ffff:ffff') === '2001:db8:1:2::/64'
    && IpClient::cheieLimita('2001:db8:1:3::1') === '2001:db8:1:3::/64' && IpClient::cheieLimita('198.51.100.8') !== IpClient::cheieLimita('198.51.100.7')
    && IpClient::cheieLimita(' ceva ') === 'ceva' && IpClient::cheieLimita('') === '');
$serverVechi = $_SERVER;
$_SERVER['REMOTE_ADDR'] = '10.9.9.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.8';
putenv('IP_PROXY_DE_INCREDERE=10.0.0.0/8');
$cuIncredere = IpClient::adresa();
putenv('IP_PROXY_DE_INCREDERE');
$faraIncredere = IpClient::adresa();
$_SERVER = $serverVechi;
$verifica('IpClient::adresa citește IP_PROXY_DE_INCREDERE din mediu (implicit gol: doar REMOTE_ADDR)',
    $cuIncredere === '198.51.100.8' && $faraIncredere === '10.9.9.9', $cuIncredere . ' / ' . $faraIncredere);

$basic = static fn (string $u, string $p): string => 'Basic ' . base64_encode($u . ':' . $p);
$c = Verificare::credentialeBasic(['PHP_AUTH_USER' => 'verificare', 'PHP_AUTH_PW' => 'p'], []);
$verifica('Basic din PHP_AUTH_USER', $c !== null && $c['utilizator'] === 'verificare' && $c['parola'] === 'p' && $c['sursa'] === 'PHP_AUTH_USER');
$c = Verificare::credentialeBasic(['HTTP_AUTHORIZATION' => $basic('verificare', 'a:b')], []);
$verifica('Basic din HTTP_AUTHORIZATION (parola poate avea „:”)', $c !== null && $c['parola'] === 'a:b' && $c['sursa'] === 'HTTP_AUTHORIZATION');
$c = Verificare::credentialeBasic(['REDIRECT_HTTP_AUTHORIZATION' => $basic('u', 'p')], []);
$verifica('Basic din REDIRECT_HTTP_AUTHORIZATION (după rescrierea din .htaccess)', $c !== null && $c['sursa'] === 'REDIRECT_HTTP_AUTHORIZATION');
$c = Verificare::credentialeBasic([], ['authorization' => $basic('u', 'p')]);
$verifica('Basic din getallheaders() (ca Biovitality pe Hostinger)', $c !== null && $c['utilizator'] === 'u' && $c['sursa'] === 'getallheaders');
$c = Verificare::credentialeBasic(['HTTP_AUTHORIZATION' => 'Basic !!!'], []);
$verifica('Basic stricat → încercare greșită (utilizator și parolă goale)', $c !== null && $c['utilizator'] === '' && $c['parola'] === '');
$verifica('alt fel de Authorization (Bearer) sau nimic → null',
    Verificare::credentialeBasic(['HTTP_AUTHORIZATION' => 'Bearer abc'], []) === null && Verificare::credentialeBasic([], []) === null);

$hash = password_hash('parola-de-test-1', PASSWORD_DEFAULT);
$acum = time();
$cookie = Verificare::cookieNou('verificare', $hash, $acum + 3600);
$verifica('cookie-ul porții: valid cu același utilizator și aceeași parolă', Verificare::cookieValid($cookie, 'verificare', $hash, $acum));
$verifica('cookie-ul porții: expirat, modificat, alt utilizator, altă parolă, prea lung → invalid',
    !Verificare::cookieValid($cookie, 'verificare', $hash, $acum + 3601)
    && !Verificare::cookieValid(substr($cookie, 0, -1) . (substr($cookie, -1) === 'a' ? 'b' : 'a'), 'verificare', $hash, $acum)
    && !Verificare::cookieValid($cookie, 'altcineva', $hash, $acum)
    && !Verificare::cookieValid($cookie, 'verificare', password_hash('alta-parola-de-test', PASSWORD_DEFAULT), $acum)
    && !Verificare::cookieValid(Verificare::cookieNou('verificare', $hash, $acum + 30 * 86400), 'verificare', $hash, $acum)
    && !Verificare::cookieValid('v1.9999999999.abc', 'verificare', $hash, $acum));

$temp = $dosarDev . '/verificare-dosare-' . getmypid();
foreach (['acasa/domains/verificare.exemplu.test/public_html', 'acasa/public_html', 'acasa/domains/alt-site.exemplu.test/public_html/verificare',
    'acasa/domains/Gazda_Rea/public_html', 'acasa/domains/verificare.exemplu.test/public_html/public'] as $sub) {
    @mkdir($temp . '/' . $sub, 0770, true);
}
$verifica('dosarul site-ului: ~/domains/<gazdă>/public_html → gazda',
    Verificare::dosarSite($temp . '/acasa/domains/verificare.exemplu.test/public_html', $temp . '/acasa') === 'verificare.exemplu.test');
$verifica('dosarul site-ului: ~/public_html, subdosarul altui site, gazdă invalidă, subdosar, alt „acasă” → refuzat',
    Verificare::dosarSite($temp . '/acasa/public_html', $temp . '/acasa') === null
    && Verificare::dosarSite($temp . '/acasa/domains/alt-site.exemplu.test/public_html/verificare', $temp . '/acasa') === null
    && Verificare::dosarSite($temp . '/acasa/domains/Gazda_Rea/public_html', $temp . '/acasa') === null
    && Verificare::dosarSite($temp . '/acasa/domains/verificare.exemplu.test/public_html/public', $temp . '/acasa') === null
    && Verificare::dosarSite($temp . '/acasa/domains/verificare.exemplu.test/public_html', $temp) === null
    && Verificare::dosarSite($temp . '/nu-exista', $temp . '/acasa') === null);
exec('rm -rf ' . escapeshellarg($temp));

$env = Verificare::citesteEnv("# comentariu\nA=\"x\"\n  B = y  \n#C=1\nD=a=b\nE=\$2y\$10\$abc/.\nF=\nfara-egal\n");
$verifica('.env citit ca Env::load (ghilimele și spații tăiate, comentarii sărite, „=” în valoare, „$” neatins)',
    $env === ['A' => 'x', 'B' => 'y', 'D' => 'a=b', 'E' => '$2y$10$abc/.', 'F' => ''], json_encode($env) ?: '');

// Gărzile ca funcții (WP-08), cu .env-ul copiei pus în mediu pe rând și apoi scos (Env::get citește $_ENV și getenv).
$cuMediu = static function (array $valori, callable $f) {
    $vechi = [];
    foreach ($valori as $cheie => $valoare) {
        $vechi[$cheie] = [array_key_exists($cheie, $_ENV) ? $_ENV[$cheie] : null, getenv($cheie)];
        if ($valoare === null) {
            unset($_ENV[$cheie]);
            putenv($cheie);
        } else {
            $_ENV[$cheie] = $valoare;
            putenv($cheie . '=' . $valoare);
        }
    }
    try {
        return $f();
    } finally {
        foreach ($vechi as $cheie => [$dinEnv, $dinMediu]) {
            if ($dinEnv === null) {
                unset($_ENV[$cheie]);
            } else {
                $_ENV[$cheie] = $dinEnv;
            }
            $dinMediu === false ? putenv($cheie) : putenv($cheie . '=' . $dinMediu);
        }
    }
};
$copieDeTest = ['SITE_COPIE_VERIFICARE' => '1', 'VERIFICARE_EMAIL' => 'proprietar.verificare@exemplu.ro', 'VERIFICARE_ERP_URL' => null,
    'VERIFICARE_ERP_CHEIE' => null, 'VERIFICARE_FAN_TEST' => null, 'VERIFICARE_EUPLATESC_TEST' => null,
    'VERIFICARE_UPLOADS_DIN' => 'https://bioscem.exemplu.test', 'VERIFICARE_WEBHOOKURI_DESCHISE' => null];
$refuza = static function (callable $f, string $text): bool {
    try {
        $f();
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), $text);
    }
    return false;
};
$faraCopie = $cuMediu(['SITE_COPIE_VERIFICARE' => null, 'VERIFICARE_EMAIL' => 'proprietar.verificare@exemplu.ro',
    'VERIFICARE_UPLOADS_DIN' => 'https://bioscem.exemplu.test'], static fn (): array => [
    'destinatar' => Verificare::destinatar('client@exemplu.ro'), 'subiect' => Verificare::subiect('Comanda 1'),
    'erp' => Verificare::erp(), 'erp_url' => Verificare::adresaErpPermisa('http://127.0.0.1:9'), 'fan' => Verificare::fanPermis(),
    'stripe' => Verificare::cheieStripePermisa('sk_live_x'), 'bt' => Verificare::btLivePermis(), 'eu' => Verificare::euplatescPermis(),
    'etichete' => Verificare::eticheteTertePermise(), 'google' => Verificare::loginGooglePermis(),
    'upload' => Verificare::adresaUpload('/uploads/x.png'), 'banner' => Verificare::banner('site') . Verificare::banner('admin'),
]);
$verifica('gărzile în afara copiei (SITE_COPIE_VERIFICARE lipsă): nu schimbă nimic (destinatarul, subiectul, ERP, FAN, Stripe, BT, '
    . 'EuPlătesc, etichete, Google, poze, banner)', $faraCopie === ['destinatar' => 'client@exemplu.ro', 'subiect' => 'Comanda 1',
    'erp' => null, 'erp_url' => false, 'fan' => true, 'stripe' => true, 'bt' => true, 'eu' => true, 'etichete' => true, 'google' => true,
    'upload' => null, 'banner' => ''], json_encode($faraCopie, JSON_UNESCAPED_UNICODE) ?: '');
$cuMediu($copieDeTest, static function () use ($verifica, $refuza, $cuMediu): void {
    $verifica('emailuri: destinatarul e mereu proprietarul, subiectul cu „[VERIFICARE] ” (o singură dată)',
        Verificare::destinatar('client@exemplu.ro') === 'proprietar.verificare@exemplu.ro'
        && Verificare::subiect('Comanda 1') === '[VERIFICARE] Comanda 1' && Verificare::subiect('[VERIFICARE] Comanda 1') === '[VERIFICARE] Comanda 1');
    $verifica('emailuri: fără VERIFICARE_EMAIL (sau cu o adresă stricată) nu pleacă nimic',
        $cuMediu(['VERIFICARE_EMAIL' => 'nu-e-adresa'], static fn (): bool => $refuza(static fn () => Verificare::destinatar('x@exemplu.ro'), 'VERIFICARE_EMAIL')));
    Verificare::simuleazaEmailuri(true);
    Verificare::trimiteEmail('u1a2b3c4d5e6f@verificare.invalid', 'Comanda 2', '<html><body class="x"><p>Corp</p></body></html>', 'Firma Demo',
        static function (): void {
            throw new RuntimeException('nu trebuia chemat');
        });
    Verificare::trimiteEmail('client.real@exemplu.ro', 'Comanda 3', '<p>Fără body</p>', 'VERIFICARE Firma', static function (): void {
    });
    $notate = Verificare::simuleazaEmailuri(false);
    $verifica('emailuri (simulate): nota „pe site ar fi plecat la …” arată doar o adresă @verificare.invalid, altfel „din afara copiei”; '
        . 'fără căsuță → fișier .eml', count($notate) === 2 && $notate[0]['catre'] === 'proprietar.verificare@exemplu.ro'
        && $notate[0]['cale'] === 'eml' && str_contains($notate[0]['html'], '<body class="x"><div')
        && str_contains($notate[0]['html'], 'ar fi plecat la u1a2b3c4d5e6f@verificare.invalid')
        && str_contains($notate[1]['html'], 'o adresă din afara copiei anonimizate') && !str_contains($notate[1]['html'], 'client.real@')
        && str_starts_with($notate[1]['html'], '<div') && $notate[1]['subiect'] === '[VERIFICARE] Comanda 3'
        && Verificare::simuleazaEmailuri(false) === [], json_encode($notate, JSON_UNESCAPED_UNICODE) ?: '');
    $smtp = $cuMediu(['VERIFICARE_SMTP_HOST' => 'smtp.hostinger.com', 'VERIFICARE_SMTP_PORT' => '', 'VERIFICARE_SMTP_UTILIZATOR' => 'verificare@exemplu.ro',
        'VERIFICARE_SMTP_PAROLA' => 'p', 'VERIFICARE_SMTP_CRIPTARE' => null], static fn (): ?array => Verificare::setariSmtp());
    $verifica('emailuri: căsuța copiei din VERIFICARE_SMTP_* (portul implicit 465, criptarea ssl), fără gazdă → fără SMTP',
        ($smtp['smtp_host'] ?? '') === 'smtp.hostinger.com' && ($smtp['smtp_port'] ?? '') === '465' && ($smtp['smtp_encryption'] ?? '') === 'ssl'
        && ($smtp['email_delivery_method'] ?? '') === 'smtp' && Verificare::setariSmtp() === null, json_encode($smtp) ?: '');
    $verifica('ERP: doar https pe *-verif.erp.andaxi.ro (nu erp.andaxi.ro, http, alt domeniu, cu utilizator în adresă)',
        Verificare::adresaErpPermisa('https://bioscem-verif.erp.andaxi.ro') && Verificare::adresaErpPermisa('https://bioscem-verif.erp.andaxi.ro/api/')
        && !Verificare::adresaErpPermisa('https://erp.andaxi.ro') && !Verificare::adresaErpPermisa('http://bioscem-verif.erp.andaxi.ro')
        && !Verificare::adresaErpPermisa('https://bioscem-verif.erp.andaxi.ro.rau.test') && !Verificare::adresaErpPermisa('https://x@bioscem-verif.erp.andaxi.ro')
        && !Verificare::adresaErpPermisa('https://-verif.erp.andaxi.ro') && !Verificare::adresaErpPermisa(''));
    $verifica('ERP: oprit fără VERIFICARE_ERP_URL/_CHEIE, doar cu amândouă bune; ErpClient refuză orice altă adresă',
        Verificare::erp() === null && $cuMediu(['VERIFICARE_ERP_URL' => 'https://bioscem-verif.erp.andaxi.ro/', 'VERIFICARE_ERP_CHEIE' => 'k'],
            static fn (): ?array => Verificare::erp()) === ['url' => 'https://bioscem-verif.erp.andaxi.ro', 'cheie' => 'k']
        && $cuMediu(['VERIFICARE_ERP_URL' => 'https://erp.andaxi.ro', 'VERIFICARE_ERP_CHEIE' => 'k'], static fn (): ?array => Verificare::erp()) === null
        && $refuza(static fn () => Verificare::verificaAdresaErp('http://127.0.0.1:9'), 'ERP-ul e oprit pe site-ul de verificare'));
    $verifica('FAN: oprit (AWB și orice cerere), doar cu VERIFICARE_FAN_TEST=1 pornit',
        !Verificare::fanPermis() && $refuza(static fn () => Verificare::verificaFan(true), 'AWB-urile sunt oprite')
        && $refuza(static fn () => Verificare::verificaFan(), 'FAN Courier e oprit')
        && $cuMediu(['VERIFICARE_FAN_TEST' => '1'], static fn (): bool => Verificare::fanPermis()));
    $verifica('Stripe doar sk_test_ (nu sk_live_, rk_live_, gol); BT doar test; EuPlătesc oprit (doar cu VERIFICARE_EUPLATESC_TEST=1)',
        Verificare::cheieStripePermisa(' sk_test_abc') && !Verificare::cheieStripePermisa('sk_live_abc') && !Verificare::cheieStripePermisa('rk_live_abc')
        && !Verificare::cheieStripePermisa('') && $refuza(static fn () => Verificare::verificaCheieStripe('sk_live_x'), 'doar o cheie de test')
        && !Verificare::btLivePermis() && !Verificare::euplatescPermis()
        && $cuMediu(['VERIFICARE_EUPLATESC_TEST' => '1'], static fn (): bool => Verificare::euplatescPermis()));
    $verifica('etichetele terților și login-ul cu Google: oprite', !Verificare::eticheteTertePermise() && !Verificare::loginGooglePermis());
    $caiRele = ['/uploads/../.env', '/uploads/%2e%2e/.env', '/uploads/a/%2E%2E/%2E%2E/x', '/uploads//x.png', '/uploads/x\\y.png', "/uploads/x\ny",
        '/uploads/a b.png', '/uploads/x.png?y=1', '/uploads/x%00.png', '/uploads/x%0a.png', '/alt/x.png', '/uploads', '/og/../uploads/x.png',
        '/uploads/x"onload.png', '/uploads/x%5c..%5cy'];
    $rele = array_values(array_filter($caiRele, static fn (string $c): bool => Verificare::adresaUpload($c) !== null));
    $verifica('pozele lipsă: 302 spre VERIFICARE_UPLOADS_DIN + aceeași cale (și /og/…); fiecare cale rea (.., %2e%2e, //, \\, spațiu, '
        . 'parametri, NUL, alt dosar) → nimic', Verificare::adresaUpload('/uploads/products/x%20y.webp') === 'https://bioscem.exemplu.test/uploads/products/x%20y.webp'
        && Verificare::adresaUpload('/og/abcdef012345/uploads/products/x.webp') === 'https://bioscem.exemplu.test/og/abcdef012345/uploads/products/x.webp'
        && $rele === [], implode(' ', $rele));
    $verifica('pozele lipsă: fără VERIFICARE_UPLOADS_DIN, cu http, cu utilizator sau cu ghilimele în adresă → nimic (404-ul obișnuit)',
        $cuMediu(['VERIFICARE_UPLOADS_DIN' => null], static fn (): ?string => Verificare::adresaUpload('/uploads/x.png')) === null
        && $cuMediu(['VERIFICARE_UPLOADS_DIN' => 'http://bioscem.ro'], static fn (): ?string => Verificare::adresaUpload('/uploads/x.png')) === null
        && $cuMediu(['VERIFICARE_UPLOADS_DIN' => 'https://u:p@bioscem.ro'], static fn (): ?string => Verificare::adresaUpload('/uploads/x.png')) === null
        && $cuMediu(['VERIFICARE_UPLOADS_DIN' => 'https://bioscem.ro/"x'], static fn (): ?string => Verificare::adresaUpload('/uploads/x.png')) === null
        && $cuMediu(['VERIFICARE_UPLOADS_DIN' => 'https://bioscem.ro/'], static fn (): ?string => Verificare::adresaUpload('/uploads/x.png')) === 'https://bioscem.ro/uploads/x.png');
    $site = Verificare::banner('site');
    $admin = Verificare::banner('admin');
    $verifica('bannerul: pe site adresa ascunsă („pr…@ex…ro”), în admin întreagă; id-ul lui; textul escapat',
        str_contains($site, 'id="' . Verificare::ID_BANNER . '"') && str_contains($site, 'SITE DE VERIFICARE')
        && str_contains($site, 'pr…@ex…ro') && !str_contains($site, 'proprietar.verificare@') && str_contains($admin, 'proprietar.verificare@exemplu.ro')
        && $cuMediu(['VERIFICARE_EMAIL' => '"><script>@x.ro'], static fn (): bool => !str_contains(Verificare::banner('admin'), '<script>')),
        Verificare::emailAscuns('proprietar.verificare@exemplu.ro'));
    $verifica('webhook-urile: doar cu VERIFICARE_WEBHOOKURI_DESCHISE=1 și doar /webhook/<nume>',
        !Verificare::webhookDeschis('/webhook/stripe') && $cuMediu(['VERIFICARE_WEBHOOKURI_DESCHISE' => '1'], static fn (): bool
            => Verificare::webhookDeschis('/webhook/stripe') && Verificare::webhookDeschis('/webhook/bt-ipay')
            && !Verificare::webhookDeschis('/webhook/') && !Verificare::webhookDeschis('/webhook//x') && !Verificare::webhookDeschis('/webhooks/x')
            && !Verificare::webhookDeschis('/admin/webhook/x') && !Verificare::webhookDeschis('/webhook/../admin')));
    $acum = time();
    $verifica('expirarea: 7 zile și un minut → expirată; o oră → ok; 8 zile cu VERIFICARE_ZILE_PASTRARE=30 → expirată (cel mult 7)',
        Verificare::stareDinRand((string) ($acum - 7 * 86400 - 60), $acum, 7)['stare'] === 'expirata'
        && Verificare::stareDinRand((string) ($acum - 3600), $acum, 7)['stare'] === 'ok'
        && Verificare::stareDinRand(date('c', $acum - 8 * 86400), $acum, 30)['stare'] === 'expirata'
        && Verificare::stareDinRand('nu e o dată', $acum, 7)['stare'] === 'expirata'
        && Verificare::stareDinRand((string) ($acum + 2 * 86400), $acum, 7)['stare'] === 'expirata'
        && $cuMediu(['VERIFICARE_ZILE_PASTRARE' => '30'], static fn (): int => Verificare::zilePastrare()) === 7
        && $cuMediu(['VERIFICARE_ZILE_PASTRARE' => '3'], static fn (): int => Verificare::zilePastrare()) === 3);
});

// Un PHP prea vechi pe server (de exemplu `php` implicit al contului, sau un 8.2 când minimul e 8.3):
// cele patru scripturi trebuie să spună asta singure, nu să cadă cu o eroare de sintaxă. Cu fiecare
// PHP instalat sub php_min (opțional: un PHP 7 arată și că scripturile n-au sintaxă mai nouă de 7.0).
$phpMinim = preg_match("/'php_min'\\s*=>\\s*'([0-9.]+)'/", (string) file_get_contents($radacina . '/config/app.php'), $m) === 1 ? $m[1] : '';
$phpVechi = array_values(array_filter(['php7.0', 'php7.4', 'php8.0', 'php8.1', 'php8.2'], static fn (string $b): bool
    => version_compare(substr($b, 3), $phpMinim, '<') && trim((string) shell_exec('command -v ' . $b . ' 2>/dev/null')) !== ''));
if ($phpVechi === []) {
    $scrie('  INFO  niciun PHP sub minimul ' . $phpMinim . ' instalat (apt-get install php7.0-cli): mesajul pentru un PHP prea vechi nu s-a verificat acum');
}
foreach ($phpVechi as $binar) {
    foreach (['configureaza.php', 'stare.php', 'licenta.php', 'sterge.php'] as $script) {
        $iesire = [];
        exec(escapeshellarg($binar) . ' ' . escapeshellarg($radacina . '/tools/verificare/' . $script) . ' 2>&1', $iesire, $cod);
        $verifica($binar . ': ' . $script . ' spune că PHP-ul e prea vechi (cod 1, fără eroare de sintaxă)', $cod === 1
            && str_contains(implode("\n", $iesire), 'mai vechi decât minimul') && !str_contains(implode("\n", $iesire), 'Parse error'), implode(' ', $iesire));
    }
}

// =====================================================================
// B. Copia într-o găzduire simulată
// =====================================================================
$port = (int) (getenv('CMS_DEV_PORT') ?: 8701) + 7;
$dosarTest = $dosarDev . '/verificare';
$acasa = $dosarTest . '/acasa';
$gazda = 'verificare.exemplu.test';
$copie = $acasa . '/domains/' . $gazda . '/public_html';
$jurnalErori = $dosarTest . '/php-erori.log';
$dosarMail = $dosarTest . '/mail';
// Plasa de siguranță spre internet: „gaura neagră cu contoare” (tools/dev/lib/gaura-neagra.php). Proxy-ul copiei
// (+12) notează orice cerere care încearcă să plece; SMTP-ul copiei (+13) e căsuța de trimitere a copiei; +14 e adresa
// „interzisă”: SMTP-ul și ERP-ul din setări, FAN_API_BASE, banca. La final se numără ce a ajuns la fiecare.
$portProxy = $port + 5;
$portSmtp = $port + 6;
$portInterzis = $port + 7;
$dosarGaura = $dosarTest . '/gaura';
$gauraNeagra = 'http://127.0.0.1:' . $portProxy;
$pidGaura = 0;
$bazaCopie = 'cms_verif_copie';
$utilizatorDb = 'cms_verif_test';
$parolaDb = 'Db-' . bin2hex(random_bytes(8));
$parolaPoarta = 'Poarta-' . bin2hex(random_bytes(8));
$parolaPoarta2 = 'Poarta2-' . bin2hex(random_bytes(8));
$emailProprietar = 'proprietar.verificare@exemplu.ro';
$secrete = [$parolaDb, $parolaPoarta, $parolaPoarta2, $emailProprietar];
$pidServer = 0;

$root = new PDO('mysql:host=localhost', (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tabeleIn = static function (string $baza) use ($root): int {
    $s = $root->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $s->execute([$baza]);
    return (int) $s->fetchColumn();
};

$opresteServerul = static function () use (&$pidServer): void {
    if ($pidServer > 0) {
        exec('pkill -TERM -P ' . $pidServer . ' 2>/dev/null; kill ' . $pidServer . ' 2>/dev/null');
        for ($i = 0; $i < 50 && trim((string) shell_exec('pgrep -P ' . $pidServer . ' 2>/dev/null; kill -0 ' . $pidServer . ' 2>/dev/null && echo x')) !== ''; $i++) {
            usleep(100000);
        }
    }
    $pidServer = 0;
};
$opresteGaura = static function () use (&$pidGaura): void {
    if ($pidGaura > 0) {
        exec('kill ' . $pidGaura . ' 2>/dev/null');
        for ($i = 0; $i < 30 && trim((string) shell_exec('kill -0 ' . $pidGaura . ' 2>/dev/null && echo x')) !== ''; $i++) {
            usleep(100000);
        }
    }
    $pidGaura = 0;
};
$curata = static function () use ($opresteServerul, $opresteGaura, $root, $utilizatorDb, $dosarTest, $dosarDev, $bazaCopie): void {
    $opresteServerul();
    $opresteGaura();
    try {
        $root->exec('DROP DATABASE IF EXISTS cms_verif_gol');
        $root->exec('DROP DATABASE IF EXISTS cms_verif_plina');
        $root->exec('DROP DATABASE IF EXISTS ' . $bazaCopie);
        $root->exec("DROP USER IF EXISTS '" . $utilizatorDb . "'@'localhost'");
    } catch (Throwable) {
    }
    if (str_starts_with($dosarTest, $dosarDev . '/') && is_dir($dosarTest)) {
        exec('rm -rf ' . escapeshellarg($dosarTest));
    }
};

/** Mediul copiei: gol, ca pe Hostinger (doar PATH, HOME și plasa de siguranță spre internet). */
$mediu = static function (string $acasaData) use ($gauraNeagra, $dosarTest): array {
    return [
        'PATH' => (string) getenv('PATH'),
        'HOME' => $acasaData,
        'LANG' => 'C.UTF-8',
        'http_proxy' => $gauraNeagra, 'https_proxy' => $gauraNeagra, 'HTTP_PROXY' => $gauraNeagra,
        'HTTPS_PROXY' => $gauraNeagra, 'ALL_PROXY' => $gauraNeagra, 'all_proxy' => $gauraNeagra,
        'NO_PROXY' => '127.0.0.1,localhost', 'no_proxy' => '127.0.0.1,localhost',
        // Doar pentru mail-capturat.sh: emailurile copiei (n-ar trebui să existe) în dosarul testului.
        'CMS_DEV_DIR' => $dosarTest,
    ];
};
$ini = static function () use ($jurnalErori, $copie): array {
    return [
        '-d', 'pdo_mysql.default_socket=' . (string) ini_get('pdo_mysql.default_socket'),
        '-d', 'error_reporting=E_ALL', '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', 'error_log=' . $jurnalErori, '-d', 'sendmail_path=' . $copie . '/tools/dev/mail-capturat.sh',
        '-d', 'memory_limit=512M', '-d', 'allow_url_fopen=0',
        // Fără cache de cod: mutațiile din B3 schimbă fișiere de cod sub serverul pornit (revalidate_freq ar ține 2 s versiunea veche).
        '-d', 'opcache.enable=0',
    ];
};
/** Un script din copie (configureaza.php, stare.php), cu intrarea dată pe stdin (nu e terminal). */
$ruleaza = static function (string $script, array $argumente, string $intrare, ?string $acasaData = null) use ($copie, $acasa, $mediu, $ini): array {
    $comanda = array_merge([PHP_BINARY], $ini(), [$copie . '/' . $script], $argumente);
    $proces = proc_open($comanda, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tevi, $copie, $mediu($acasaData ?? $acasa));
    if (!is_resource($proces)) {
        return ['cod' => -1, 'iesire' => 'nu pornește'];
    }
    fwrite($tevi[0], $intrare);
    fclose($tevi[0]);
    $iesire = (string) stream_get_contents($tevi[1]);
    fclose($tevi[1]);
    return ['cod' => proc_close($proces), 'iesire' => $iesire];
};
/** O cerere spre copie, fără redirecționări urmate. */
$cerere = static function (string $metoda, string $cale, array $optiuni = []) use (&$port): array {
    $c = curl_init('http://127.0.0.1:' . $port . $cale);
    $antete = [];
    $cookies = [];
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $metoda,
        CURLOPT_NOBODY => $metoda === 'HEAD',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOPROXY => '*',
        CURLOPT_PROXY => '',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_HTTPHEADER => (array) ($optiuni['antete'] ?? []),
        CURLOPT_HEADERFUNCTION => static function ($c, string $linie) use (&$antete, &$cookies): int {
            if (str_contains($linie, ':')) {
                [$nume, $valoare] = explode(':', $linie, 2);
                $nume = strtolower(trim($nume));
                $antete[$nume] = trim($valoare);
                if ($nume === 'set-cookie') {
                    $cookies[] = trim($valoare);
                }
            }
            return strlen($linie);
        },
    ]);
    if (isset($optiuni['basic'])) {
        curl_setopt($c, CURLOPT_USERPWD, $optiuni['basic']);
    }
    if (isset($optiuni['cookie'])) {
        curl_setopt($c, CURLOPT_COOKIE, $optiuni['cookie']);
    }
    if (isset($optiuni['jar'])) {
        // Cookie-urile aplicației (sesiunea, coșul), ca un browser.
        curl_setopt($c, CURLOPT_COOKIEFILE, $optiuni['jar']);
        curl_setopt($c, CURLOPT_COOKIEJAR, $optiuni['jar']);
    }
    if (isset($optiuni['post'])) {
        curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($optiuni['post']));
    }
    $corp = (string) curl_exec($c);
    $cod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['cod' => $cod, 'antete' => $antete, 'cookies' => $cookies, 'corp' => $corp];
};
$pornesteServerul = static function () use (&$pidServer, &$port, $copie, $mediu, $acasa, $ini, $cerere, $dosarTest, $opresteServerul): bool {
    $opresteServerul();
    $env = '';
    foreach ($mediu($acasa) + ['PHP_CLI_SERVER_WORKERS' => '2'] as $nume => $valoare) {
        $env .= ' ' . $nume . '=' . escapeshellarg($valoare);
    }
    $comanda = 'cd ' . escapeshellarg($copie) . ' && env -i' . $env . ' nohup ' . escapeshellarg(PHP_BINARY) . ' '
        . implode(' ', array_map('escapeshellarg', $ini())) . ' -S 127.0.0.1:' . $port
        . ' -t public tools/dev/router.php >> ' . escapeshellarg($dosarTest . '/server.log') . ' 2>&1 & echo $!';
    $pidServer = (int) trim((string) shell_exec($comanda));
    for ($i = 0; $i < 50; $i++) {
        if ($cerere('GET', '/health')['cod'] !== 0) {
            return true;
        }
        usleep(200000);
    }
    return false;
};
/**
 * Cod PHP rulat în linia de comandă cu aplicația copiei (bootstrap.php-ul ei, deci și .env-ul ei), ca un cron: ce
 * întoarce `$cod` (o expresie, cu `return`) iese ca JSON. Fișierul stă în dosarul testului, nu în copie.
 */
$inCopie = static function (string $cod) use ($copie, $acasa, $mediu, $ini, $dosarTest): mixed {
    $fisier = $dosarTest . '/in-copie-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($fisier, '<?php declare(strict_types=1); require ' . var_export($copie . '/bootstrap.php', true) . ";\n"
        . '$rezultat = (static function () { ' . $cod . " })();\n"
        . 'echo "\n@@REZULTAT@@", json_encode($rezultat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' . "\n");
    $proces = proc_open(array_merge([PHP_BINARY], $ini(), [$fisier]), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tevi, $copie, $mediu($acasa));
    fclose($tevi[0]);
    $iesire = (string) stream_get_contents($tevi[1]);
    fclose($tevi[1]);
    proc_close($proces);
    @unlink($fisier);
    $pozitie = strrpos($iesire, '@@REZULTAT@@');
    return $pozitie === false ? ['eroare' => trim($iesire)] : json_decode(substr($iesire, $pozitie + 12), true);
};
$conexiuni = static fn (int $portul): array => is_file($dosarGaura . '/conexiuni-' . $portul . '.txt')
    ? (file($dosarGaura . '/conexiuni-' . $portul . '.txt', FILE_IGNORE_NEW_LINES) ?: []) : [];
$mesajeSmtp = static fn (): array => glob($dosarGaura . '/smtp-*.eml') ?: [];
$sesiuni = static fn (): int => count(glob($copie . '/storage/sessions/sess_*') ?: []);
$emailuri = static fn (): int => count(glob($dosarMail . '/*.eml') ?: []);
$scrieEnv = static function (array $schimbari) use ($copie): void {
    $text = (string) file_get_contents($copie . '/.env');
    foreach ($schimbari as $cheie => $valoare) {
        $text = preg_match('/^' . preg_quote($cheie, '/') . '=/m', $text) === 1
            ? (string) preg_replace('/^' . preg_quote($cheie, '/') . '=.*$/m', $cheie . '=' . str_replace('$', '\\$', $valoare), $text)
            : $text . $cheie . '=' . $valoare . "\n";
    }
    file_put_contents($copie . '/.env', $text);
};
$antetPoarta = static fn (array $r): bool => ($r['antete']['x-robots-tag'] ?? '') === Verificare::ROBOTI
    && ($r['antete']['cache-control'] ?? '') === Verificare::CACHE;
$faraSecrete = static function (string $text) use (&$secrete): string {
    $gasite = [];
    foreach ($secrete as $secret) {
        if ($secret !== '' && str_contains($text, $secret)) {
            $gasite[] = substr($secret, 0, 4) . '…';
        }
    }
    return implode(', ', $gasite);
};

try {
    $curata();
    if ($cerere('GET', '/health')['cod'] !== 0) {
        throw new RuntimeException('pe portul ' . $port . ' răspunde deja un server; oprește-l întâi');
    }

    // ── Plasa de siguranță cu contoare ──
    mkdir($dosarGaura, 0770, true);
    $pidGaura = (int) trim((string) shell_exec('nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/lib/gaura-neagra.php')
        . ' --dosar=' . escapeshellarg($dosarGaura) . ' --http=' . $portProxy . ',' . $portInterzis . ' --smtp=' . $portSmtp
        . ' >> ' . escapeshellarg($dosarTest . '/gaura.log') . ' 2>&1 & echo $!'));
    for ($i = 0; $i < 50 && !is_file($dosarGaura . '/gata'); $i++) {
        usleep(100000);
    }
    $verifica('plasa de siguranță ascultă (proxy ' . $portProxy . ', SMTP-ul copiei ' . $portSmtp . ', adresa interzisă ' . $portInterzis . ')',
        is_file($dosarGaura . '/gata'), (string) @file_get_contents($dosarTest . '/gaura.log'));

    // ── Copia codului ──
    mkdir($copie, 0770, true);
    mkdir($dosarMail, 0770, true);
    $lista = shell_exec('git -C ' . escapeshellarg($radacina) . ' ls-files -z --cached --others --exclude-standard');
    $codGit = is_string($lista) && $lista !== '' ? 0 : 1;
    $fisiere = array_values(array_filter(explode("\0", (string) $lista), static fn (string $f): bool => $f !== ''));
    $copiate = 0;
    foreach ($fisiere as $fisier) {
        if (!is_file($radacina . '/' . $fisier) || str_starts_with($fisier, '.env')
            || (str_starts_with($fisier, 'storage/') && !in_array(basename($fisier), ['.gitkeep', '.gitignore'], true))) {
            continue;
        }
        @mkdir(dirname($copie . '/' . $fisier), 0770, true);
        copy($radacina . '/' . $fisier, $copie . '/' . $fisier);
        @chmod($copie . '/' . $fisier, fileperms($radacina . '/' . $fisier) & 0777);
        $copiate++;
    }
    // Un .git ca al unui deploy din hPanel (doar cât trebuie ca să se vadă că nu e servit).
    @mkdir($copie . '/.git/refs/heads/claude', 0770, true);
    file_put_contents($copie . '/.git/HEAD', "ref: refs/heads/claude/etapa1-cod-comun\n");
    file_put_contents($copie . '/.git/refs/heads/claude/etapa1-cod-comun', str_repeat('a', 40) . "\n");
    file_put_contents($copie . '/.git/config', "[core]\n\trepositoryformatversion = 0\n");
    $verifica('copia codului în găzduirea simulată (' . $copiate . ' fișiere, fără storage/ și fără .env)', $codGit === 0 && $copiate > 100
        && !is_file($copie . '/.env') && is_file($copie . '/public/index.php') && glob($copie . '/public/*.php') === [$copie . '/public/index.php']);

    $root->exec('CREATE DATABASE cms_verif_gol CHARACTER SET utf8mb4');
    $root->exec('CREATE DATABASE cms_verif_plina CHARACTER SET utf8mb4');
    $root->exec('CREATE TABLE cms_verif_plina.settings (`key` VARCHAR(190) PRIMARY KEY, `value` TEXT)');
    $root->exec('CREATE TABLE cms_verif_plina.admins (id INT PRIMARY KEY)');
    $root->exec("CREATE USER '" . $utilizatorDb . "'@'localhost' IDENTIFIED BY " . $root->quote($parolaDb));
    $root->exec("GRANT ALL ON cms_verif_gol.* TO '" . $utilizatorDb . "'@'localhost'");
    $root->exec("GRANT ALL ON cms_verif_plina.* TO '" . $utilizatorDb . "'@'localhost'");

    // ── B1. Fără .env ──
    $scrie('');
    $scrie('B1. Deploy proaspăt, fără .env');
    $verifica('serverul copiei pornește (mediu gol, port ' . $port . ')', $pornesteServerul());
    $toate503 = true;
    $detalii = [];
    foreach ([['GET', '/'], ['GET', '/magazin'], ['GET', '/admin'], ['GET', '/admin/login'], ['POST', '/checkout'], ['POST', '/admin/login'],
        ['GET', '/robots.txt'], ['GET', '/health'], ['GET', '/sitemap.xml'], ['HEAD', '/'], ['GET', '/uploads/demo/x.png'], ['GET', '/.env'],
        ['GET', '/.git/config'], ['GET', '/tools/verificare/stare.php'], ['GET', Verificare::CALE_SONDA . '?sonda=' . str_repeat('a', 64)],
        ['PUT', '/'], ['GET', '/nu-exista'], ['GET', '/_andaxi/ping'], ['POST', '/webhook/stripe']] as [$metoda, $cale]) {
        $r = $cerere($metoda, $cale, $metoda === 'POST' ? ['post' => ['email' => 'x@exemplu.ro']] : []);
        $bun = $r['cod'] === 503 && $antetPoarta($r) && $r['cookies'] === [] && ($metoda === 'HEAD' ? $r['corp'] === '' : str_contains($r['corp'], 'Site neconfigurat'));
        if (!$bun) {
            $toate503 = false;
            $detalii[] = $metoda . ' ' . $cale . ' → ' . $r['cod'];
        }
    }
    $verifica('orice cerere → 503 „Site neconfigurat”, noindex, no-store, fără cookie (19 cereri, și pingul și un webhook)', $toate503, implode('; ', $detalii));
    $verifica('nicio sesiune pornită', $sesiuni() === 0, (string) $sesiuni());
    $s = $ruleaza('tools/verificare/stare.php', ['--adresa=http://127.0.0.1:' . $port], '');
    $ultimul = trim((string) strrchr("\n" . trim($s['iesire']), "\n"));
    $verifica('stare.php fără .env: „NECONFIGURAT”, cu „503 = DA”, cod 1', $s['cod'] === 1 && str_starts_with($ultimul, 'NECONFIGURAT:')
        && str_contains($s['iesire'], 'DA    fără configurare, / răspunde 503') && str_contains($s['iesire'], 'DA    /robots.txt răspunde 503'),
        $ultimul);

    // ── B2. configureaza.php ──
    $scrie('');
    $scrie('B2. configureaza.php');
    $intrare = static fn (array $linii): string => implode("\n", $linii) . "\n";
    $bune = ['cms_verif_gol', $utilizatorDb, $parolaDb, $emailProprietar, '', $parolaPoarta, $parolaPoarta];
    $fara = static fn (): bool => !file_exists($copie . '/.env');

    @mkdir($dosarTest . '/alt-acasa', 0770, true);
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare($bune), $dosarTest . '/alt-acasa');
    $verifica('refuză un dosar care nu e ~/domains/<gazdă>/public_html (alt „acasă”)', $r['cod'] === 1 && $fara()
        && str_contains($r['iesire'], 'nu e în ~/domains/<domeniu>/public_html'), trim($r['iesire']));
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare(['cms_verif_plina', $utilizatorDb, $parolaDb, $emailProprietar, '', $parolaPoarta, $parolaPoarta]));
    $verifica('refuză o bază cu tabele care nu e o copie de verificare', $r['cod'] === 1 && $fara()
        && str_contains($r['iesire'], 'are 2 tabele') && $tabeleIn('cms_verif_plina') === 2, trim($r['iesire']));
    $parolaGresita = 'Gresita-' . bin2hex(random_bytes(6));
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare(['cms_verif_gol', $utilizatorDb, $parolaGresita, $emailProprietar, '', $parolaPoarta, $parolaPoarta]));
    $verifica('refuză parola greșită a bazei (fără s-o arate)', $r['cod'] === 1 && $fara() && str_contains($r['iesire'], 'acces refuzat')
        && !str_contains($r['iesire'], $parolaGresita), trim($r['iesire']));
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare(array_replace($bune, [6 => $parolaPoarta . 'x'])));
    $verifica('refuză două parole ale porții diferite', $r['cod'] === 1 && $fara() && str_contains($r['iesire'], 'nu sunt la fel'));
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare(array_replace($bune, [5 => 'scurta', 6 => 'scurta'])));
    $verifica('refuză o parolă a porții sub 12 caractere', $r['cod'] === 1 && $fara() && str_contains($r['iesire'], 'sub 12 caractere'));
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare(array_replace($bune, [5 => $parolaDb, 6 => $parolaDb])));
    $verifica('refuză parola porții egală cu parola bazei', $r['cod'] === 1 && $fara() && str_contains($r['iesire'], 'alta decât parola bazei'));
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare(array_replace($bune, [3 => 'nu-e-email'])));
    $verifica('refuză o adresă de email invalidă', $r['cod'] === 1 && $fara());
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare(array_slice($bune, 0, 3)));
    $verifica('intrare încheiată înainte de răspunsuri → nimic scris', $r['cod'] === 1 && $fara() && str_contains($r['iesire'], 'intrarea s-a încheiat'));
    // Contul SSH e comun cu site-urile live: o bază scrisă în .env-ul altui site e refuzată, chiar goală (WP-08).
    @mkdir($acasa . '/domains/alt-site.exemplu.test/public_html', 0770, true);
    file_put_contents($acasa . '/domains/alt-site.exemplu.test/public_html/.env', "APP_URL=https://alt-site.exemplu.test\nDB_HOST=localhost\nDB_DATABASE=cms_verif_gol\n");
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare($bune));
    $verifica('refuză o bază scrisă în .env-ul altui site din cont (chiar goală), cu domeniul lui, fără numele bazei', $r['cod'] === 1 && $fara()
        && str_contains($r['iesire'], 'NU    baza nu e a altui site din cont (o folosește alt-site.exemplu.test)')
        && !str_contains($r['iesire'], 'cms_verif_gol') && !is_file($copie . '/storage/licenta/' . Verificare::FISIER_CHEIE_LICENTA), trim($r['iesire']));
    unlink($acasa . '/domains/alt-site.exemplu.test/public_html/.env');
    $verifica('refuzurile n-au creat niciun tabel', $tabeleIn('cms_verif_gol') === 0);

    // În terminal (pty): doar acolo se ascund parolele și doar acolo se vede ce lasă Ctrl-C în urmă.
    if (trim((string) shell_exec('command -v python3 2>/dev/null')) === '') {
        $scrie('  INFO  python3 lipsește: configureaza.php nu s-a încercat într-un terminal (Ctrl-C la o parolă)');
    } else {
        $inTerminal = static function (array $pasi, array $iniInPlus = []) use ($radacina, $copie, $acasa, $mediu, $ini): array {
            $cererePty = [
                'comanda' => array_merge([PHP_BINARY], $ini(), $iniInPlus, [$copie . '/tools/verificare/configureaza.php']),
                'cwd' => $copie,
                'env' => $mediu($acasa),
                'pasi' => $pasi,
                'asteptare' => 4,
            ];
            // json_encode scrie diacriticele ca \u…, deci escapeshellarg nu le pierde (localul C).
            $iesire = (string) shell_exec('python3 -I ' . escapeshellarg($radacina . '/tools/dev/lib/terminal.py') . ' '
                . escapeshellarg((string) json_encode($cererePty)) . ' 2>&1');
            $rezultat = json_decode($iesire, true);
            return is_array($rezultat) ? $rezultat : ['iesit' => false, 'ecran' => $iesire, 'nevazut' => null];
        };
        $terminalBun = static fn (array $t): bool => ($t['ecou'] ?? false) === true && ($t['mod_rand'] ?? false) === true
            && ($t['ctrl_c_semnal'] ?? false) === true;
        $descrie = static fn (array $t): string => json_encode(array_intersect_key($t, array_flip(['iesit', 'cod', 'semnal', 'secunde', 'ecou',
            'mod_rand', 'ctrl_c_semnal', 'nevazut']))) . ' | ' . substr(trim((string) ($t['ecran'] ?? '')), -160);
        $tastat = 'Ascuns-' . bin2hex(random_bytes(4));
        $panaLaParola = [['asteapta' => 'Numele bazei', 'trimite' => "cms_verif_gol\r"], ['asteapta' => 'Utilizatorul bazei', 'trimite' => $utilizatorDb . "\r"]];
        foreach (['cu pcntl' => [], 'fără pcntl' => ['-d', 'disable_functions=pcntl_signal,pcntl_async_signals']] as $fel => $iniPty) {
            $t = $inTerminal(array_merge($panaLaParola, [['asteapta' => 'Parola bazei', 'trimite' => $tastat . "\x03"]]), $iniPty);
            $verifica('terminal, ' . $fel . ': Ctrl-C în mijlocul parolei bazei → oprit pe loc (cod 130), terminalul cu ecou, nimic scris',
                ($t['cod'] ?? null) === 130 && (float) ($t['secunde'] ?? 9) < 2 && $terminalBun($t) && $fara()
                && str_contains((string) $t['ecran'], 'Oprit (Ctrl-C)') && !str_contains((string) $t['ecran'], $tastat), $descrie($t));
        }
        if (extension_loaded('pcntl')) {
            $t = $inTerminal(array_merge($panaLaParola, [['asteapta' => 'Parola bazei', 'trimite' => 'ab'], ['asteapta' => 'Parola bazei', 'semnal' => 15]]));
            $verifica('terminal: SIGTERM în timpul parolei (găzduirea oprește procesul) → terminalul cu ecou, cod 143, nimic scris',
                ($t['cod'] ?? null) === 143 && $terminalBun($t) && $fara(), $descrie($t));
        }
        // Parola scrisă cu o greșeală corectată (Backspace), apoi Ctrl-C la o întrebare obișnuită.
        $t = $inTerminal(array_merge($panaLaParola, [['asteapta' => 'Parola bazei', 'trimite' => $parolaDb . "xș\x7f\x7f\r"],
            ['asteapta' => 'Emailul tău', 'trimite' => "\x03"]]));
        $verifica('terminal: parola ascunsă, Backspace șterge (baza se deschide), Ctrl-C la întrebarea următoare oprește pe loc',
            str_contains((string) $t['ecran'], 'DA    legătura cu baza') && !str_contains((string) $t['ecran'], $parolaDb)
            && ($t['semnal'] ?? null) === 2 && (float) ($t['secunde'] ?? 9) < 2 && $terminalBun($t) && $fara(), $descrie($t));
    }

    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare($bune));
    $textEnv = (string) @file_get_contents($copie . '/.env');
    $cheiEnv = Verificare::citesteEnv($textEnv);
    $verifica('configurarea pe baza goală: cod 0, „Gata”', $r['cod'] === 0 && str_contains($r['iesire'], 'Gata. Pasul următor:'), trim($r['iesire']));
    $verifica('.env doar pentru proprietar (0600), fără fișiere temporare rămase', is_file($copie . '/.env')
        && (fileperms($copie . '/.env') & 0777) === 0600 && glob($copie . '/.env.*') === []);
    $verifica('.env are cheile copiei (SITE_COPIE_VERIFICARE=1, APP_URL din dosar, baza, poarta, plăți de test, licența verificare, pachetul și SITE_SLUG bioscem)',
        ($cheiEnv['SITE_COPIE_VERIFICARE'] ?? '') === '1' && ($cheiEnv['APP_URL'] ?? '') === 'https://' . $gazda
        && ($cheiEnv['APP_ENV'] ?? '') === 'verificare' && ($cheiEnv['APP_DEBUG'] ?? '') === 'false'
        && ($cheiEnv['DB_DATABASE'] ?? '') === 'cms_verif_gol' && ($cheiEnv['DB_PASSWORD'] ?? '') === $parolaDb
        && ($cheiEnv['VERIFICARE_POARTA_UTILIZATOR'] ?? '') === 'verificare' && ($cheiEnv['BT_IPAY_MODE'] ?? '') === 'test'
        && ($cheiEnv['BT_IPAY_LIVE_PASS'] ?? 'x') === '' && ($cheiEnv['LICENTA_SITE'] ?? '') === 'verificare'
        && ($cheiEnv['VERIFICARE_ZILE_PASTRARE'] ?? '') === '7' && ($cheiEnv['VERIFICARE_EMAIL'] ?? '') === $emailProprietar
        && ($cheiEnv['SITE_DATE_DOSAR'] ?? '') === realpath($copie) . '/site-uri/bioscem' && ($cheiEnv['SITE_SLUG'] ?? '') === 'bioscem',
        json_encode(array_keys($cheiEnv)) ?: '');
    $verifica('parola porții doar ca hash în .env', !str_contains($textEnv, $parolaPoarta)
        && password_verify($parolaPoarta, (string) ($cheiEnv['VERIFICARE_POARTA_HASH'] ?? '')));
    $secrete[] = (string) ($cheiEnv['VERIFICARE_POARTA_HASH'] ?? '');
    $verifica('pe ecran: nicio parolă, niciun hash, nicio adresă de email', $faraSecrete($r['iesire']) === '', $faraSecrete($r['iesire']));
    $verifica('configurarea n-a creat niciun tabel', $tabeleIn('cms_verif_gol') === 0);
    // Perechea de chei a licenței de verificare (WP-08): cea privată doar pe server, cea publică în .env.
    $fisierCheie = $copie . '/storage/licenta/' . Verificare::FISIER_CHEIE_LICENTA;
    $textCheie = trim((string) @file_get_contents($fisierCheie));
    $cheiePrivata = base64_decode($textCheie, true);
    $verifica('cheia licenței de verificare: privată în storage/licenta (0600, cu gardul dosarului), publică în .env, aceeași pereche; „DA” pe ecran',
        is_string($cheiePrivata) && strlen($cheiePrivata) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES && (fileperms($fisierCheie) & 0777) === 0600
        && base64_encode(sodium_crypto_sign_publickey_from_secretkey($cheiePrivata)) === (string) ($cheiEnv['LICENTA_CHEIE_PUBLICA'] ?? '')
        && is_file($copie . '/storage/licenta/.htaccess') && glob($copie . '/storage/licenta/.*.tmp') === []
        && str_contains($r['iesire'], 'DA    cheia licenței de verificare făcută acum'), trim($r['iesire']));
    $secrete[] = $textCheie;
    $verifica('dosarul de lucru storage/verificare, cu gardul .htaccess', is_dir($copie . '/storage/verificare')
        && str_contains((string) @file_get_contents($copie . '/storage/verificare/.htaccess'), 'Require all denied'));
    $amprentaEnv = sha1($textEnv);
    $r = $ruleaza('tools/verificare/configureaza.php', [], $intrare($bune));
    $verifica('a doua rulare fără --rescrie → refuz, .env neatins', $r['cod'] === 1 && str_contains($r['iesire'], '.env există deja')
        && sha1((string) file_get_contents($copie . '/.env')) === $amprentaEnv);
    rename($copie . '/.env', $copie . '/.env-copie-test');
    file_put_contents($copie . '/.env', "APP_URL=https://site-adevarat.exemplu.test\nDB_DATABASE=baza_site\n");
    $r = $ruleaza('tools/verificare/configureaza.php', ['--rescrie'], $intrare($bune));
    $verifica('--rescrie peste .env-ul unui site adevărat (fără SITE_COPIE_VERIFICARE=1) → refuz, neatins', $r['cod'] === 1
        && str_contains($r['iesire'], 'nu e al unei copii de verificare') && str_contains((string) file_get_contents($copie . '/.env'), 'baza_site'));
    unlink($copie . '/.env');
    rename($copie . '/.env-copie-test', $copie . '/.env');

    // ── B3. Poarta ──
    $scrie('');
    $scrie('B3. Poarta (bază goală, nicio copie încărcată)');
    $bunaBasic = 'verificare:' . $parolaPoarta;
    $r = $cerere('GET', '/');
    $verifica('fără parolă → 401, fereastra Basic, formularul în pagină, noindex, no-store, fără cookie', $r['cod'] === 401
        && str_starts_with($r['antete']['www-authenticate'] ?? '', 'Basic realm=') && str_contains($r['corp'], 'action="' . Verificare::CALE_INTRARE . '"')
        && $antetPoarta($r) && $r['cookies'] === [] && ($r['antete']['x-litespeed-cache-control'] ?? '') === 'no-cache');
    $toate401 = true;
    $detalii = [];
    foreach ([['GET', '/magazin'], ['GET', '/admin'], ['POST', '/admin/login'], ['POST', '/checkout'], ['GET', '/sitemap.xml'], ['GET', '/uploads/demo/x.png'],
        ['GET', '/.env'], ['GET', '/.git/config'], ['GET', '/tools/verificare/stare.php'], ['GET', '/storage/verificare/poarta-esecuri.json'],
        ['GET', '/ROBOTS.TXT'], ['GET', '/health/'], ['GET', '/robots.txt/'], ['PUT', '/'], ['GET', Verificare::CALE_SONDA],
        ['GET', Verificare::CALE_SONDA . '?sonda=' . str_repeat('b', 64)], ['POST', '/_andaxi/ping'], ['GET', '/_andaxi/ping/'],
        ['GET', '/_andaxi/licenta']] as [$metoda, $cale]) {
        $r = $cerere($metoda, $cale, $metoda === 'POST' ? ['post' => ['email' => 'x@exemplu.ro']] : []);
        if ($r['cod'] !== 401 || !$antetPoarta($r) || $r['cookies'] !== []) {
            $toate401 = false;
            $detalii[] = $metoda . ' ' . $cale . ' → ' . $r['cod'];
        }
    }
    $verifica('fără parolă, orice altă adresă sau metodă → 401 (19 cereri: și POST pe ping, alte adrese /_andaxi/)', $toate401, implode('; ', $detalii));
    $r = $cerere('HEAD', '/');
    $verifica('HEAD / → 401 fără corp', $r['cod'] === 401 && $r['corp'] === '' && $antetPoarta($r));
    $r = $cerere('GET', '/robots.txt');
    $verifica('/robots.txt fără parolă → 200 „User-agent: * / Disallow: /”, noindex', $r['cod'] === 200
        && $r['corp'] === "User-agent: *\nDisallow: /\n" && $antetPoarta($r));
    $r = $cerere('GET', '/health');
    $verifica('/health fără parolă → 200 „ok” (fără bază, fără sesiune)', $r['cod'] === 200 && $r['corp'] === 'ok' && $antetPoarta($r) && $r['cookies'] === []);
    // Pingul monitorizării (App\Support\Ping): fără parolă, îl dă aplicația: doar SELECT 1 și garda, deci pe baza goală
    // dinaintea primei încărcări „fara_registru”, fără niciun tabel făcut.
    $r = $cerere('GET', '/_andaxi/ping');
    $rHead = $cerere('HEAD', '/_andaxi/ping');
    $verifica('/_andaxi/ping fără parolă → 200 JSON al aplicației (baza goală: „fara_registru”), noindex, no-store, fără cookie, niciun tabel; '
        . 'HEAD fără corp', $r['cod'] === 200 && $r['corp'] === '{"ok":true,"db":true,"cod":null,"schema":"fara_registru"}' && $antetPoarta($r)
        && $r['cookies'] === [] && $tabeleIn('cms_verif_gol') === 0 && $rHead['cod'] === 200 && $rHead['corp'] === '' && $antetPoarta($rHead),
        $r['cod'] . ' ' . $r['corp'] . ' | HEAD ' . $rHead['cod']);
    $r = $cerere('GET', '/', ['basic' => $bunaBasic]);
    $verifica('parola bună (Basic) → 503 „Copia de verificare lipsește”, fără sesiune', $r['cod'] === 503
        && str_contains($r['corp'], 'Copia de verificare lipsește') && $antetPoarta($r) && $r['cookies'] === []);
    $r = $cerere('GET', '/', ['basic' => 'verificare:gresita-' . bin2hex(random_bytes(3))]);
    $verifica('parola greșită → 401 „greșite”', $r['cod'] === 401 && str_contains($r['corp'], 'Utilizator sau parolă greșite'));
    for ($i = 0; $i < 8; $i++) {
        $cerere('GET', '/', ['basic' => 'altcineva:' . $parolaPoarta]);
    }
    $r = $cerere('GET', '/', ['basic' => 'verificare:gresita']);
    $verifica('a 10-a încercare greșită → încă 401', $r['cod'] === 401);
    $r = $cerere('GET', '/', ['basic' => $bunaBasic]);
    $verifica('după 10 încercări greșite în 10 minute → 429 cu Retry-After, chiar cu parola bună', $r['cod'] === 429
        && (int) ($r['antete']['retry-after'] ?? 0) > 0 && $antetPoarta($r));
    $r = $cerere('POST', Verificare::CALE_INTRARE, ['post' => ['utilizator' => 'verificare', 'parola' => $parolaPoarta]]);
    $verifica('blocarea ține și pentru formular', $r['cod'] === 429);
    $esecuri = (string) @file_get_contents($copie . '/storage/verificare/poarta-esecuri.json');
    $verifica('pe disc doar amprente, nu adrese', $esecuri !== '' && !str_contains($esecuri, '127.0.0.1') && preg_match('/"[a-f0-9]{32}":\[/', $esecuri) === 1);
    unlink($copie . '/storage/verificare/poarta-esecuri.json');
    $r = $cerere('GET', '/', ['basic' => $bunaBasic]);
    $verifica('după ce trece blocarea, parola bună trece iar', $r['cod'] === 503);
    // IPv6: limita numără pe rețeaua /64, nu pe adresa exactă. Adresele vin aici din X-Forwarded-For,
    // de la serverul de test trecut ca proxy de încredere (IpClient).
    $scrieEnv(['IP_PROXY_DE_INCREDERE' => '127.0.0.1']);
    for ($i = 1; $i <= 10; $i++) {
        $cerere('GET', '/', ['basic' => 'verificare:gresita', 'antete' => ['X-Forwarded-For: 2001:db8:1:2::' . dechex($i)]]);
    }
    $r = $cerere('GET', '/', ['basic' => $bunaBasic, 'antete' => ['X-Forwarded-For: 2001:db8:1:2:ffff::1']]);
    $r2 = $cerere('GET', '/', ['basic' => $bunaBasic, 'antete' => ['X-Forwarded-For: 2001:db8:1:3::1']]);
    $r3 = $cerere('GET', '/', ['basic' => $bunaBasic]);
    $verifica('IPv6: 10 parole greșite de la 10 adrese din același /64 → 429 pentru tot /64-ul; alt /64 și altă adresă trec',
        $r['cod'] === 429 && $r2['cod'] === 503 && $r3['cod'] === 503, $r['cod'] . ' / ' . $r2['cod'] . ' / ' . $r3['cod']);
    $scrieEnv(['IP_PROXY_DE_INCREDERE' => '']);
    unlink($copie . '/storage/verificare/poarta-esecuri.json');

    $r = $cerere('POST', Verificare::CALE_INTRARE, ['post' => ['utilizator' => 'verificare', 'parola' => 'gresita', 'inapoi' => '/magazin']]);
    $verifica('formularul cu parola greșită → 401 fără fereastra Basic', $r['cod'] === 401 && !isset($r['antete']['www-authenticate'])
        && str_contains($r['corp'], 'greșite'));
    $r = $cerere('POST', Verificare::CALE_INTRARE, ['post' => ['utilizator' => 'verificare', 'parola' => $parolaPoarta, 'inapoi' => '/magazin?x=1']]);
    $cookiePoarta = '';
    foreach ($r['cookies'] as $setat) {
        if (str_starts_with($setat, Verificare::COOKIE . '=')) {
            $cookiePoarta = substr(explode(';', $setat)[0], strlen(Verificare::COOKIE) + 1);
        }
    }
    $verifica('formularul cu parola bună → 303 la pagina cerută, cookie HttpOnly SameSite=Lax', $r['cod'] === 303
        && ($r['antete']['location'] ?? '') === '/magazin?x=1' && $cookiePoarta !== '' && stripos(implode(';', $r['cookies']), 'httponly') !== false
        && stripos(implode(';', $r['cookies']), 'samesite=lax') !== false);
    $r = $cerere('GET', '/', ['cookie' => Verificare::COOKIE . '=' . $cookiePoarta]);
    $verifica('cu cookie-ul porții → trece de poartă (503 „copia lipsește”)', $r['cod'] === 503 && str_contains($r['corp'], 'lipsește'));
    $r = $cerere('POST', Verificare::CALE_INTRARE, ['post' => ['utilizator' => 'verificare', 'parola' => $parolaPoarta, 'inapoi' => '//rau.exemplu.test/x']]);
    $r2 = $cerere('POST', Verificare::CALE_INTRARE, ['post' => ['utilizator' => 'verificare', 'parola' => $parolaPoarta, 'inapoi' => 'https://rau.exemplu.test/']]);
    $verifica('întoarcerea după formular doar pe o cale locală (//gazdă și https:// → „/”)', ($r['antete']['location'] ?? '') === '/'
        && ($r2['antete']['location'] ?? '') === '/');
    $r = $cerere('GET', '/', ['cookie' => Verificare::COOKIE . '=v1.' . (time() + 3600) . '.' . str_repeat('0', 64)]);
    $verifica('cookie falsificat → 401', $r['cod'] === 401);
    // Câmpuri trimise ca liste (x[]=…, x[a]=…): oricine le poate trimite; tot închis și nimic în jurnal.
    $r = $cerere('GET', Verificare::CALE_SONDA . '?sonda[]=x');
    $r2 = $cerere('GET', '/', ['cookie' => Verificare::COOKIE . '[a]=1']);
    $r3 = $cerere('POST', Verificare::CALE_INTRARE, ['post' => ['utilizator' => ['x'], 'parola' => ['y'], 'inapoi' => ['/magazin']]]);
    $r4 = $cerere('POST', Verificare::CALE_INTRARE, ['post' => ['utilizator' => 'verificare', 'parola' => $parolaPoarta, 'inapoi' => ['/magazin']]]);
    $verifica('câmpuri ca liste (sonda[], cookie[a], utilizator[]/parola[]/inapoi[]) → 401, 401, 401, 303 „/”, fără avertismente în jurnal',
        $r['cod'] === 401 && $r2['cod'] === 401 && $r3['cod'] === 401 && $r4['cod'] === 303 && ($r4['antete']['location'] ?? '') === '/'
        && !str_contains((string) @file_get_contents($jurnalErori), 'Array to string'), $r['cod'] . ' ' . $r2['cod'] . ' ' . $r3['cod'] . ' ' . $r4['cod']);
    $r = $cerere('GET', Verificare::CALE_IESIRE, ['cookie' => Verificare::COOKIE . '=' . $cookiePoarta]);
    $verifica('ieșirea → 303 „/” și cookie-ul șters', $r['cod'] === 303 && ($r['antete']['location'] ?? '') === '/'
        && preg_match('/' . Verificare::COOKIE . '=(deleted)?;/', implode("\n", $r['cookies'])) === 1);
    $scrieEnv(['VERIFICARE_POARTA_MOD' => 'formular']);
    $r = $cerere('GET', '/');
    $verifica('modul „formular”: 401 fără fereastra Basic, cu formularul', $r['cod'] === 401 && !isset($r['antete']['www-authenticate'])
        && str_contains($r['corp'], Verificare::CALE_INTRARE));
    $scrieEnv(['VERIFICARE_POARTA_MOD' => 'basic']);
    // Confirmările de plată (WP-08): fără parolă doar POST /webhook/…, doar cu VERIFICARE_WEBHOOKURI_DESCHISE=1 și doar cu o copie.
    $r = $cerere('POST', '/webhook/stripe', ['post' => ['x' => '1']]);
    $scrieEnv(['VERIFICARE_WEBHOOKURI_DESCHISE' => '1']);
    $r2 = $cerere('POST', '/webhook/stripe', ['post' => ['x' => '1']]);
    $r3 = $cerere('GET', '/webhook/stripe');
    $r4 = $cerere('POST', '/webhook/../admin', ['post' => ['x' => '1']]);
    $scrieEnv(['VERIFICARE_WEBHOOKURI_DESCHISE' => '0']);
    $verifica('webhook-urile: POST /webhook/… fără parolă → 401; cu VERIFICARE_WEBHOOKURI_DESCHISE=1 dar fără copie → 503 „lipsește”; '
        . 'GET și o cale ciudată tot 401', $r['cod'] === 401 && $r2['cod'] === 503 && str_contains($r2['corp'], 'lipsește') && $antetPoarta($r2)
        && $r3['cod'] === 401 && $r4['cod'] === 401, $r['cod'] . ' / ' . $r2['cod'] . ' / ' . $r3['cod'] . ' / ' . $r4['cod']);

    $jeton = bin2hex(random_bytes(32));
    file_put_contents($copie . '/storage/verificare/sonda.json', json_encode(['amprenta' => hash('sha256', $jeton), 'expira' => time() + 60]));
    $r = $cerere('GET', Verificare::CALE_SONDA . '?sonda=' . $jeton, ['basic' => 'sonda:' . $jeton, 'cookie' => Verificare::COOKIE_SONDA . '=' . $jeton,
        'antete' => ['X-Verificare-Sonda: ' . $jeton]]);
    $fapte = json_decode($r['corp'], true);
    $verifica('sonda cu jetonul bun → faptele PHP-ului web (versiune, Basic, cookie, antet, adresă)', $r['cod'] === 200 && is_array($fapte)
        && ($fapte['php'] ?? '') === PHP_VERSION && ($fapte['basic'] ?? '') !== '' && ($fapte['cookie'] ?? false) === true
        && ($fapte['antet'] ?? false) === true && ($fapte['remote_addr'] ?? '') === 'loopback (127.0.0.0/8)' && $antetPoarta($r), $r['cod'] . ' ' . substr($r['corp'], 0, 200));
    $verifica('sonda: fără nicio adresă sau parolă în răspuns', !str_contains($r['corp'], '127.0.0.1') && !str_contains($r['corp'], $jeton));
    $r = $cerere('GET', Verificare::CALE_SONDA . '?sonda=' . $jeton);
    $verifica('sonda: jetonul se folosește o singură dată (a doua oară → 401)', $r['cod'] === 401 && !is_file($copie . '/storage/verificare/sonda.json'));
    file_put_contents($copie . '/storage/verificare/sonda.json', json_encode(['amprenta' => hash('sha256', $jeton), 'expira' => time() - 1]));
    $r = $cerere('GET', Verificare::CALE_SONDA . '?sonda=' . $jeton);
    $verifica('sonda: jeton expirat → 401', $r['cod'] === 401);
    file_put_contents($copie . '/storage/verificare/sonda.json', json_encode(['amprenta' => hash('sha256', $jeton), 'expira' => time() + 60]));
    $r = $cerere('GET', Verificare::CALE_SONDA . '?sonda=' . $jeton, ['cookie' => Verificare::COOKIE_SONDA . '[a]=' . $jeton]);
    $fapte = json_decode($r['corp'], true);
    $verifica('sonda: cookie-ul ei trimis ca listă → „cookie” NU, fără avertisment', $r['cod'] === 200 && is_array($fapte)
        && ($fapte['cookie'] ?? true) === false && !str_contains((string) @file_get_contents($jurnalErori), 'Array to string'), substr($r['corp'], 0, 120));
    @unlink($copie . '/storage/verificare/sonda.json');

    $s = $ruleaza('tools/verificare/stare.php', ['--adresa=http://127.0.0.1:' . $port], '');
    // Ieșirea întreagă în raport: așa arată textul pe care îl trimite proprietarul (aici, cu date inventate).
    file_put_contents($fisierRaport, '        | ' . str_replace("\n", "\n        | ", rtrim($s['iesire'])) . "\n", FILE_APPEND);
    $ultimul = trim((string) strrchr("\n" . trim($s['iesire']), "\n"));
    $randuriNu = static fn (string $iesire): string => implode(' | ', array_filter(explode("\n", $iesire), static fn (string $l): bool => str_contains($l, '  NU ')));
    $verifica('stare.php configurat: ultimul rând „GATA: site-ul de verificare e pregătit (toate verificările DA)”, cod 0', $s['cod'] === 0
        && $ultimul === 'GATA: site-ul de verificare e pregătit (toate verificările DA)', $ultimul . ' | ' . $randuriNu($s['iesire']));
    foreach (['DA    fără parolă, / răspunde 401', 'DA    X-Robots-Tag: noindex pe răspuns', 'DA    /robots.txt: „Disallow: /”',
        'DA    /.env nu se vede din browser', 'DA    /.git/config nu se vede din browser', 'DA    sonda răspunde',
        'DA    antetul HTTP Basic ajunge la PHP', 'DA    cookie-urile ajung la PHP', 'DA    baza e goală', 'INFO  serverul bazei: MariaDB',
        'INFO  PHP web: ' . PHP_VERSION, 'INFO  REMOTE_ADDR: loopback', 'DA    .env doar pentru tine (0600)', 'domeniul ' . $gazda,
        'INFO  codul din git: claude/etapa1-cod-comun aaaaaaa', 'INFO  copia de verificare: lipsește', 'DA    LICENTA_SITE începe cu „verificare”',
        'DA    cheia privată a licenței de verificare, doar pe server', 'DA    LICENTA_CHEIE_PUBLICA din .env e cheia ei publică',
        'DA    baza nu e a altui site din cont', 'DA    /storage/licenta/ (cheia licenței) nu se vede din browser',
        'INFO  licența copiei: încă niciuna', 'INFO  emailuri azi: 0 plecate din 100 pe zi', 'DA    emailul proprietarului e scris'] as $asteptat) {
        if (!str_contains($s['iesire'], $asteptat)) {
            $verifica('stare.php arată „' . $asteptat . '”', false);
        }
    }
    preg_match_all('/^  DA    (\d+)\. /m', $s['iesire'], $garziDa);
    $verifica('stare.php: cele 14 gărzi, fiecare DA (în linia de comandă, în PHP-ul site-ului și la locul ei din cod)',
        array_map('intval', $garziDa[1]) === range(1, 14), implode(',', $garziDa[1]));
    $verifica('stare.php: nicio parolă, niciun hash, nicio cheie, nicio adresă de email, numele bazei nu apar', $faraSecrete($s['iesire']) === ''
        && !str_contains($s['iesire'], 'cms_verif_gol') && !str_contains($s['iesire'], $utilizatorDb), $faraSecrete($s['iesire']));
    $verifica('stare.php nu lasă jetonul sondei pe disc', !is_file($copie . '/storage/verificare/sonda.json'));
    $verifica('stare.php n-a trimis și n-a scris niciun email (cel de probă e doar simulat), nicio ieșire spre plasa de siguranță',
        $emailuri() === 0 && $mesajeSmtp() === [] && glob($copie . '/storage/verificare/mail/*.eml') === [] && $conexiuni($portProxy) === []
        && $conexiuni($portInterzis) === [] && !is_file($copie . '/storage/verificare/emailuri-azi.json'));

    // Fiecare gardă stricată pe rând în copia codului: stare.php trebuie să-i arate rândul NU și să nu mai spună „GATA”.
    $mutatii = [
        1 => ['src/Support/Verificare.php', '$rezultat = self::autentificare();', "\$rezultat = 'ok';"],
        2 => ['src/Support/Verificare.php', "public const ROBOTI = 'noindex, nofollow, noarchive';", "public const ROBOTI = 'all';"],
        3 => ['src/Support/Verificare.php', '|| $acum - $moment > $zile * 86400) {', ') {'],
        4 => ['src/Support/Robots.php', 'if (Verificare::activ()) {', 'if (false) {'],
        5 => ['src/Support/OrderMailer.php', 'if (Verificare::activ()) {', 'if (false) {'],
        6 => ['src/Support/ErpClient.php', 'Verificare::verificaAdresaErp($this->baseUrl);', ''],
        7 => ['src/Support/FanCourierGateway.php', 'Verificare::verificaFan(true);', ''],
        8 => ['src/Support/StripeGateway.php', 'Verificare::verificaCheieStripe($secretKey);', ''],
        9 => ['src/Support/BtIpayGateway.php', "if (!Verificare::btLivePermis()) {\n            return self::MOD_TEST;", "if (false) {\n            return self::MOD_TEST;"],
        10 => ['src/Support/EuPlatescGateway.php', "if (!Verificare::euplatescPermis()) {\n            return false;", "if (false) {\n            return false;"],
        11 => ['views/layout.php', '\App\Support\CookieConsent::permiteUrmarire() && \App\Support\Verificare::eticheteTertePermise()', '\App\Support\CookieConsent::permiteUrmarire()'],
        12 => ['src/Http/Controllers/SiteController.php', '&& \App\Support\Verificare::loginGooglePermis();', ';'],
        13 => ['public/index.php', '\App\Support\Verificare::redirectUploadLipsa($reqPath);', ''],
        14 => ['views/layout.php', "echo \App\Support\Verificare::banner('site'); ", ''],
    ];
    $prinse = [];
    $scapate = [];
    foreach ($mutatii as $numar => [$fisierMutat, $din, $in]) {
        $original = (string) file_get_contents($copie . '/' . $fisierMutat);
        if (substr_count($original, $din) !== 1) {
            $scapate[] = $numar . ' (textul de stricat nu e o singură dată în ' . $fisierMutat . ')';
            continue;
        }
        try {
            file_put_contents($copie . '/' . $fisierMutat, str_replace($din, $in, $original));
            $sm = $ruleaza('tools/verificare/stare.php', ['--adresa=http://127.0.0.1:' . $port], '');
        } finally {
            file_put_contents($copie . '/' . $fisierMutat, $original);
        }
        $ultimulM = trim((string) strrchr("\n" . trim($sm['iesire']), "\n"));
        if ($sm['cod'] === 1 && str_starts_with($ultimulM, 'NU E GATA:') && preg_match('/^  NU    ' . $numar . '\. /m', $sm['iesire']) === 1) {
            $prinse[] = $numar;
        } else {
            $scapate[] = $numar . ' (cod ' . $sm['cod'] . ', ' . $ultimulM . ')';
        }
    }
    $verifica('stare.php prinde fiecare gardă stricată (14 mutații în copia codului: rândul ei NU, fără „GATA”)', $scapate === []
        && $prinse === range(1, 14), implode('; ', $scapate));
    $verifica('mutațiile n-au lăsat nimic să plece (plasa de siguranță a lui stare.php e 127.0.0.1:9) și nici email scris',
        $conexiuni($portProxy) === [] && $conexiuni($portInterzis) === [] && $mesajeSmtp() === [] && glob($copie . '/storage/verificare/mail/*.eml') === []);

    $r = $ruleaza('tools/verificare/configureaza.php', ['--rescrie'], $intrare(array_replace($bune, [5 => $parolaPoarta2, 6 => $parolaPoarta2])));
    $verifica('--rescrie pe copia de verificare, cu altă parolă a porții → cod 0', $r['cod'] === 0, trim($r['iesire']));
    $verifica('--rescrie păstrează cheia licenței (același fișier, aceeași cheie publică în .env)', trim((string) @file_get_contents($fisierCheie)) === $textCheie
        && (Verificare::citesteEnv((string) file_get_contents($copie . '/.env'))['LICENTA_CHEIE_PUBLICA'] ?? '') === (string) ($cheiEnv['LICENTA_CHEIE_PUBLICA'] ?? '')
        && str_contains($r['iesire'], 'DA    cheia licenței de verificare de dinainte, păstrată'), trim($r['iesire']));
    $r = $cerere('GET', '/', ['cookie' => Verificare::COOKIE . '=' . $cookiePoarta]);
    $r2 = $cerere('GET', '/', ['basic' => 'verificare:' . $parolaPoarta2]);
    $verifica('după parola nouă: cookie-ul vechi nu mai trece (401), parola nouă da', $r['cod'] === 401 && $r2['cod'] === 503);
    $bunaBasic = 'verificare:' . $parolaPoarta2;
    $verifica('poarta n-a creat niciun tabel și n-a pornit nicio sesiune', $tabeleIn('cms_verif_gol') === 0 && $sesiuni() === 0,
        $tabeleIn('cms_verif_gol') . ' tabele, ' . $sesiuni() . ' sesiuni');

    // ── B4. Copie „încărcată” ──
    $scrie('');
    $scrie('B4. Copie încărcată (cms_verif_copie: o copie a bazei de dezvoltare, cu _verificare; licența pusă de licenta.php)');
    $bazaDev = (string) getenv('DB_DATABASE');
    if (preg_match('/^cms_[a-z0-9_]+$/', $bazaDev) !== 1) {
        throw new RuntimeException('baza de dezvoltare (DB_DATABASE) nu e o bază cms_*');
    }
    $root->exec('DROP DATABASE IF EXISTS ' . $bazaCopie);
    $root->exec('CREATE DATABASE ' . $bazaCopie . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $tabeleDev = $root->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $root->quote($bazaDev)
        . " AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabeleDev as $tabel) {
        $t = '`' . str_replace('`', '``', (string) $tabel) . '`';
        $root->exec('CREATE TABLE `' . $bazaCopie . '`.' . $t . ' LIKE `' . $bazaDev . '`.' . $t);
        $root->exec('INSERT INTO `' . $bazaCopie . '`.' . $t . ' SELECT * FROM `' . $bazaDev . '`.' . $t);
    }
    $root->exec('CREATE TABLE `' . $bazaCopie . '`._verificare (sursa VARCHAR(40) NOT NULL, incarcat_la DATETIME NOT NULL, anonimizat TINYINT(1) NOT NULL)');
    $root->exec('INSERT INTO `' . $bazaCopie . "`._verificare VALUES ('test', NOW(), 1)");
    $verifica('copia bazei de dezvoltare: ' . count($tabeleDev) . ' tabele, plus _verificare', $tabeleIn($bazaCopie) === count($tabeleDev) + 1);
    $scrieEnv(['DB_DATABASE' => $bazaCopie, 'DB_USERNAME' => (string) getenv('DB_USERNAME'), 'DB_PASSWORD' => (string) getenv('DB_PASSWORD')]);
    $r = $ruleaza('tools/verificare/licenta.php', ['toate'], '');
    $verifica('licenta.php toate → licența copiei, semnată cu cheia de pe server (site „verificare”), cod 0', $r['cod'] === 0
        && str_contains($r['iesire'], 'pentru „verificare” (toate)') && is_file($copie . '/storage/licenta/licenta.json')
        && !is_file($copie . '/storage/licenta/stare-aplicata.json.tmp'), trim($r['iesire']));
    file_put_contents($copie . '/storage/verificare/incarcat-la', date('c') . "\n");
    $r = $cerere('GET', '/', ['basic' => $bunaBasic]);
    $verifica('cu parola, prima pagină a aplicației → 200, noindex, no-store', $r['cod'] === 200 && str_contains($r['corp'], '</html>') && $antetPoarta($r),
        (string) $r['cod']);
    $r = $cerere('GET', '/admin/login', ['basic' => $bunaBasic]);
    $verifica('/admin/login → 200, noindex, no-store', $r['cod'] === 200 && $antetPoarta($r), (string) $r['cod']);
    $r = $cerere('GET', '/sitemap.xml', ['basic' => $bunaBasic]);
    $verifica('/sitemap.xml: antetul aplicației (noindex) înlocuit cu al porții', $r['cod'] === 200 && $antetPoarta($r)
        && str_contains($r['corp'], '<urlset'), ($r['antete']['x-robots-tag'] ?? '-'));
    $r = $cerere('GET', '/robots.txt', ['basic' => $bunaBasic]);
    $verifica('/robots.txt rămâne „Disallow: /” și cu parola', $r['corp'] === "User-agent: *\nDisallow: /\n");
    // /health, cu copia încărcată: îl dă aplicația (fără parolă), cu starea schemei, ca o repetiție a deploy-ului să vadă
    // aici ce ar vedea pe site; cu baza în urma codului 503 (ultima_ord coborât o clipă pe baza copiei, apoi pus la loc).
    $bazaCopiei = '`' . $bazaCopie . '`';
    $ultimaDinCod = (int) ((require $radacina . '/database/migrari/ultima.php')['ord'] ?? 0);
    $r = $cerere('GET', '/health');
    $verifica('/health fără parolă, cu copia încărcată → aplicația: 200 „ok” + „schema: la_zi”, noindex, no-store '
        . '(copia bazei de dezvoltare, făcută prin migrări de tools/dev/instaleaza.sh)', $r['cod'] === 200 && $r['corp'] === "ok\nschema: la_zi" && $antetPoarta($r),
        $r['cod'] . ' ' . $r['corp']);
    $ultimaInBaza = (string) $root->query('SELECT valoare FROM ' . $bazaCopiei . "._platforma_stare WHERE cheie = 'ultima_ord'")->fetchColumn();
    try {
        $root->exec('UPDATE ' . $bazaCopiei . "._platforma_stare SET valoare = '" . ($ultimaDinCod - 1) . "' WHERE cheie = 'ultima_ord'");
        $r = $cerere('GET', '/health');
    } finally {
        $root->exec('UPDATE ' . $bazaCopiei . '._platforma_stare SET valoare = ' . $root->quote($ultimaInBaza) . " WHERE cheie = 'ultima_ord'");
    }
    $verifica('/health cu copia încărcată și baza în urma codului (o migrare neaplicată) → 503 „schema: in_urma”, fără parolă',
        $r['cod'] === 503 && str_contains($r['corp'], 'schema: in_urma') && $antetPoarta($r), $r['cod'] . ' ' . $r['corp']);
    $r = $cerere('HEAD', '/health');
    $verifica('HEAD /health cu copia încărcată → 200 al porții (routerul aplicației are doar GET și POST)', $r['cod'] === 200 && $antetPoarta($r),
        (string) $r['cod']);
    $r = $cerere('GET', '/_andaxi/ping');
    $verifica('/_andaxi/ping fără parolă, cu copia încărcată → 200, „schema”: „la_zi”, noindex, no-store, fără cookie', $r['cod'] === 200
        && $r['corp'] === '{"ok":true,"db":true,"cod":null,"schema":"la_zi"}' && $antetPoarta($r) && $r['cookies'] === [], $r['cod'] . ' ' . $r['corp']);
    $r = $cerere('GET', '/');
    $verifica('fără parolă tot 401, și cu copia încărcată', $r['cod'] === 401);
    file_put_contents($copie . '/storage/verificare/incarcat-la', date('c', time() - 8 * 86400) . "\n");
    $scrieEnv(['VERIFICARE_ZILE_PASTRARE' => '30']);
    $r = $cerere('GET', '/', ['basic' => $bunaBasic]);
    $verifica('copie de 8 zile (și cu VERIFICARE_ZILE_PASTRARE=30: cel mult 7) → 503 „a expirat”', $r['cod'] === 503
        && str_contains($r['corp'], 'a expirat') && $antetPoarta($r));
    $r = $cerere('GET', '/health');
    $verifica('/health cu copia expirată → „ok” al porții (fără bază, ca înainte de prima încărcare)', $r['cod'] === 200 && $r['corp'] === 'ok'
        && $antetPoarta($r), $r['cod'] . ' ' . $r['corp']);
    $scrieEnv(['VERIFICARE_ZILE_PASTRARE' => '7']);
    file_put_contents($copie . '/storage/verificare/incarcat-la', "nu e o dată\n");
    $r = $cerere('GET', '/', ['basic' => $bunaBasic]);
    file_put_contents($copie . '/storage/verificare/incarcat-la', date('c', time() + 2 * 86400) . "\n");
    $r2 = $cerere('GET', '/', ['basic' => $bunaBasic]);
    file_put_contents($copie . '/storage/verificare/incarcat-la', (string) (time() - 3600) . "\n");
    $r3 = $cerere('GET', '/', ['basic' => $bunaBasic]);
    $verifica('dată de necitit sau din viitor → expirată; secunde Unix de acum o oră → ok', $r['cod'] === 503 && $r2['cod'] === 503 && $r3['cod'] === 200);

    // ── B5. Gărzile copiei ──
    $scrie('');
    $scrie('B5. Gărzile copiei (setările unui site adevărat lăsate în baza copiei; plasa de siguranță numără orice ieșire)');
    $setari = static function (array $valori) use ($root, $bazaCopie): void {
        $s = $root->prepare('INSERT INTO `' . $bazaCopie . '`.settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        foreach ($valori as $cheie => $valoare) {
            $s->execute([(string) $cheie, (string) $valoare]);
        }
    };
    $casutaCopiei = 'verificare@' . $gazda;
    $parolaCasutei = 'Casuta-' . bin2hex(random_bytes(6));
    $secrete[] = $parolaCasutei;
    $scrieEnv([
        'VERIFICARE_SMTP_HOST' => '127.0.0.1', 'VERIFICARE_SMTP_PORT' => (string) $portSmtp, 'VERIFICARE_SMTP_UTILIZATOR' => $casutaCopiei,
        'VERIFICARE_SMTP_PAROLA' => $parolaCasutei, 'VERIFICARE_SMTP_CRIPTARE' => 'niciuna', 'VERIFICARE_UPLOADS_DIN' => 'https://bioscem.exemplu.test',
        // Datele BT de producție (n-au voie să fie citite) și adresa băncii: cea interzisă.
        'BT_IPAY_MODE' => 'live', 'BT_IPAY_LIVE_USER' => 'bt-live-inventat', 'BT_IPAY_LIVE_PASS' => 'bt-live-parola-inventata',
        'BT_IPAY_LIVE_CALLBACK_KEY' => 'YmFzZTY0LWludmVudGF0', 'BT_IPAY_TEST_USER' => 'bt-test-inventat', 'BT_IPAY_TEST_PASS' => 'bt-test-parola',
        'BT_IPAY_BASE_URL_OVERRIDE' => 'http://127.0.0.1:' . $portInterzis, 'FAN_API_BASE' => 'http://127.0.0.1:' . $portInterzis,
    ]);
    $setari([
        'cache_pages_enabled' => '0',
        // SMTP-ul site-ului (n-are voie să fie folosit) și destinatarii adminului.
        'email_delivery_method' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $portInterzis, 'smtp_encryption' => 'none',
        'smtp_username' => 'smtp.site@exemplu.ro', 'smtp_password' => 'smtp-parola-inventata', 'order_email_from_address' => 'comenzi@exemplu.ro',
        'email_template_new_order_admin_recipients' => 'comenzi.admin@exemplu.ro', 'email_template_new_order_recipient_mode' => 'client_admin',
        'stripe_enabled' => '1', 'stripe_secret_key' => 'sk_live_inventat0000000000', 'stripe_publishable_key' => 'pk_live_inventat0000000000',
        'euplatesc_enabled' => '1', 'euplatesc_merchant_id' => '44840000000', 'euplatesc_secret_key' => '00112233445566778899AABBCCDDEEFF',
        'bt_ipay_enabled' => '1', 'bt_ipay_admin_only' => '0',
        'erp_enabled' => '1', 'erp_stock_enabled' => '1', 'erp_url' => 'http://127.0.0.1:' . $portInterzis, 'erp_api_key' => 'cheie-erp-inventata',
        'fan_client_id' => '123456', 'fan_api_username' => 'fan.inventat', 'fan_api_password' => 'fan-parola-inventata', 'fan_awb_auto' => '1',
        'fan_live_tariff_enabled' => '0', 'shipping_fixed_enabled' => '0',
        'google_analytics_enabled' => '1', 'google_analytics_id' => 'G-VERIFTEST1', 'google_tag_manager_enabled' => '1', 'google_tag_manager_id' => 'GTM-VERIF01',
        'google_ads_enabled' => '1', 'google_ads_conversion_id' => 'AW-12345678', 'microsoft_clarity_enabled' => '1', 'microsoft_clarity_project_id' => 'clarityverif',
        'tawk_enabled' => '1', 'tawk_property_id' => 'tawkverif123', 'tawk_requires_consent' => '1',
        'customer_google_auth_enabled' => '1', 'customer_google_client_id' => 'id-inventat.apps.googleusercontent.com',
        'customer_google_client_secret' => 'secret-google-inventat', 'google_site_verification' => 'verificaregoogletest',
    ]);
    $consimtamant = 'bioscem_cookie_consent=all';
    $etichete = ['googletagmanager.com', 'clarity.ms', 'embed.tawk.to', 'name="google-site-verification"', "gtag('config'"];
    $cuEtichete = static fn (string $corp): array => array_values(array_filter($etichete, static fn (string $e): bool => str_contains($corp, $e)));
    $r = $cerere('GET', '/', ['basic' => $bunaBasic, 'cookie' => $consimtamant]);
    $verifica('pagina, cu „Accept toate” și GA, GTM, Ads, Clarity, tawk.to pornite în setări: nicio etichetă a terților, nici google-site-verification',
        $r['cod'] === 200 && $cuEtichete($r['corp']) === [], $r['cod'] . ' ' . implode(', ', $cuEtichete($r['corp'])));
    $verifica('bannerul pe site, primul în <body>, cu adresa proprietarului ascunsă pe jumătate', str_contains($r['corp'],
        '<body class="site-shell">' . "\n    " . '<div id="' . Verificare::ID_BANNER . '"') && str_contains($r['corp'], Verificare::emailAscuns($emailProprietar))
        && !str_contains($r['corp'], $emailProprietar));
    $jarAdmin = $dosarTest . '/jar-admin.txt';
    $parolaAdmin = trim((string) @file_get_contents($dosarDev . '/admin-parola.txt'));
    $r = $cerere('POST', '/admin/login', ['basic' => $bunaBasic, 'jar' => $jarAdmin,
        'post' => ['email' => (string) (getenv('ADMIN_DEFAULT_EMAIL') ?: 'admin@exemplu.ro'), 'password' => $parolaAdmin]]);
    $r = $cerere('GET', (string) ($r['antete']['location'] ?? '/admin'), ['basic' => $bunaBasic, 'jar' => $jarAdmin]);
    $verifica('bannerul în admin (după login), cu adresa proprietarului întreagă', $r['cod'] === 200
        && str_contains($r['corp'], '<div id="' . Verificare::ID_BANNER . '"') && str_contains($r['corp'], $emailProprietar)
        && str_contains($r['corp'], '/admin/logout'), (string) $r['cod']);
    // Pozele nu se copiază: o poză lipsă → 302 spre site-ul sursă; căile rele nu.
    @mkdir($copie . '/public/uploads/demo', 0770, true);
    file_put_contents($copie . '/public/uploads/demo/exista.png', "\x89PNG\r\n\x1a\nproba");
    $r = $cerere('GET', '/uploads/products/nu-exista.webp', ['basic' => $bunaBasic]);
    $r2 = $cerere('GET', '/og/abcdef012345/uploads/products/nu-exista.webp', ['basic' => $bunaBasic]);
    $r3 = $cerere('GET', '/uploads/demo/exista.png', ['basic' => $bunaBasic]);
    $verifica('pozele: una lipsă → 302 spre VERIFICARE_UPLOADS_DIN + aceeași cale (și poza de distribuire /og/…), no-store; una existentă → 200',
        $r['cod'] === 302 && ($r['antete']['location'] ?? '') === 'https://bioscem.exemplu.test/uploads/products/nu-exista.webp' && $antetPoarta($r)
        && $r2['cod'] === 302 && ($r2['antete']['location'] ?? '') === 'https://bioscem.exemplu.test/og/abcdef012345/uploads/products/nu-exista.webp'
        && $r3['cod'] === 200 && str_contains($r3['corp'], 'proba'),
        $r['cod'] . ' ' . ($r['antete']['location'] ?? '-') . ' | ' . $r2['cod'] . ' ' . ($r2['antete']['location'] ?? '-') . ' | ' . $r3['cod']);
    $rele = [];
    foreach (['/uploads/../../.env', '/uploads/%2e%2e/%2e%2e/.env', '/uploads//x.png', '/uploads/x%00.png', '/uploads/a%5c..%5cb.png'] as $caleRea) {
        $r = $cerere('GET', $caleRea, ['basic' => $bunaBasic]);
        if ($r['cod'] !== 404 || isset($r['antete']['location']) || str_contains($r['corp'], 'DB_')) {
            $rele[] = $caleRea . ' → ' . $r['cod'] . ' ' . ($r['antete']['location'] ?? '');
        }
    }
    $r = $cerere('GET', '/uploads/products/nu-exista.webp');
    $verifica('pozele: căile rele (.., %2e%2e, //, NUL, \\) → 404 fără redirect; fără parolă tot 401', $rele === [] && $r['cod'] === 401, implode('; ', $rele));
    // Fluxul Merchant (WP-R): doar cu parola, noindex; robots.txt nu-l mai lasă (fără „Allow”).
    $r = $cerere('GET', '/feed/google-merchant.xml');
    $r2 = $cerere('GET', '/feed/google-merchant.xml', ['basic' => $bunaBasic]);
    $r3 = $cerere('GET', '/robots.txt');
    $verifica('fluxul Google Merchant: fără parolă 401 (Google nu-l poate prelua), cu parola 200 cu noindex și no-store; robots.txt fără „Allow:”',
        $r['cod'] === 401 && $r2['cod'] === 200 && str_contains($r2['corp'], '<rss') && $antetPoarta($r2)
        && $r3['corp'] === "User-agent: *\nDisallow: /\n", $r['cod'] . ' / ' . $r2['cod'] . ' / ' . json_encode($r3['corp']));
    // Login-ul cu Google: oprit, deși e pornit și configurat în setări.
    $r = $cerere('GET', '/auth/google', ['basic' => $bunaBasic]);
    $verifica('login-ul cu Google: /auth/google → înapoi la /login (nu la accounts.google.com)',
        $r['cod'] === 302 && ($r['antete']['location'] ?? '') === '/login', $r['cod'] . ' ' . ($r['antete']['location'] ?? '-'));

    // Checkout-ul: metodele de plată, Stripe live, tariful FAN, apoi o comandă cu produsul refrigerat.
    $idMenta = (int) $root->query('SELECT id FROM ' . $bazaCopiei . ".products WHERE slug = 'ceai-de-menta'")->fetchColumn();
    $jarClient = $dosarTest . '/jar-client.txt';
    $optClient = ['basic' => $bunaBasic, 'jar' => $jarClient];
    $formular = static function () use ($cerere, $optClient, $root, $bazaCopiei): array {
        $root->exec('DELETE FROM ' . $bazaCopiei . '.checkout_submit_logs');
        $r = $cerere('GET', '/checkout', $optClient);
        preg_match('/name="checkout_form_token"\s+value="([^"]*)"/', $r['corp'], $t);
        preg_match('/name="checkout_form_rendered_at"\s+value="([^"]*)"/', $r['corp'], $m);
        $bife = [];
        if (preg_match_all('/name="avertisment_bifa\[([0-9a-f]{16})\]"/', $r['corp'], $b) > 0) {
            foreach (array_unique($b[1]) as $cheie) {
                $bife[$cheie] = '1';
            }
        }
        preg_match_all('/name="payment_method"[^>]*value="([a-z_]+)"/', $r['corp'], $metode);
        return ['pagina' => $r, 'campuri' => ['checkout_form_token' => (string) ($t[1] ?? ''), 'checkout_form_rendered_at' => (string) ($m[1] ?? '')]
            + ($bife !== [] ? ['avertisment_bifa' => $bife] : []), 'metode' => array_values(array_unique($metode[1]))];
    };
    $client = ['billing_first_name' => 'Test', 'billing_last_name' => 'Verificare', 'billing_phone' => '0700000999',
        'billing_email' => 'client.copie@exemplu.ro', 'billing_street' => 'Strada Exemplului', 'billing_street_no' => '9', 'billing_city' => 'Cluj-Napoca',
        'billing_county' => 'Cluj', 'billing_postcode' => '400000', 'shipping_same_as_billing' => '1', 'accept_terms' => '1'];
    $cerere('POST', '/cos/adauga/' . $idMenta, $optClient + ['post' => ['quantity' => '1']]);
    $f = $formular();
    $verifica('checkout: fără EuPlătesc (pornit în setări) și fără BT (BT_IPAY_MODE=live, cu date de producție în .env): pe copie doar modul de test',
        $f['pagina']['cod'] === 200 && in_array('cod', $f['metode'], true) && !in_array('euplatesc', $f['metode'], true)
        && !in_array('btipay', $f['metode'], true), json_encode($f['metode']) ?: '');
    $proxyInainte = count($conexiuni($portProxy));
    $interzisInainte = count($conexiuni($portInterzis));
    sleep(3); // protecția anti-robot refuză un formular trimis la mai puțin de 2 secunde
    $r = $cerere('POST', '/checkout', $optClient + ['post' => $f['campuri'] + $client + ['payment_method' => 'stripe']]);
    $r2 = $cerere('GET', '/checkout', $optClient);
    $verifica('plata cu Stripe cu o cheie live în setări: refuzată înainte de orice cerere (înapoi la /checkout, cu motivul), nicio ieșire',
        $r['cod'] === 302 && ($r['antete']['location'] ?? '') === '/checkout' && str_contains($r2['corp'], 'doar o cheie de test')
        && count($conexiuni($portProxy)) === $proxyInainte && count($conexiuni($portInterzis)) === $interzisInainte,
        $r['cod'] . ' ' . ($r['antete']['location'] ?? '-'));
    $setari(['fan_live_tariff_enabled' => '1']);
    $cerere('POST', '/cos/adauga/' . $idMenta, $optClient + ['post' => ['quantity' => '1']]);
    $f = $formular();
    sleep(3);
    $r = $cerere('POST', '/checkout', $optClient + ['post' => $f['campuri'] + $client + ['payment_method' => 'cod']]);
    $verifica('tariful FAN live pornit în setări: cererea spre FAN nu pleacă (comanda e refuzată, înapoi la /checkout), nicio ieșire',
        $r['cod'] === 302 && ($r['antete']['location'] ?? '') === '/checkout' && count($conexiuni($portInterzis)) === $interzisInainte
        && count($conexiuni($portProxy)) === $proxyInainte, $r['cod'] . ' ' . ($r['antete']['location'] ?? '-'));
    $setari(['fan_live_tariff_enabled' => '0']);
    // Comanda cu produsul refrigerat (avertismentul pe produs, WP-R): emailurile ei, cu blocul avertismentului.
    $titluAvertisment = 'ATENȚIE – PRODUS REFRIGERAT DE TEST';
    $root->prepare('DELETE FROM ' . $bazaCopiei . '.product_warnings WHERE product_id = ?')->execute([$idMenta]);
    $root->prepare('INSERT INTO ' . $bazaCopiei . '.product_warnings (product_id, title, body, checkbox_label, updated_at) VALUES (?, ?, ?, ?, NOW())')
        ->execute([$idMenta, $titluAvertisment, "Se păstrează la rece.\n\nAl doilea paragraf.", 'Am citit avertismentul de test']);
    $idMaxComenzi = (int) $root->query('SELECT COALESCE(MAX(id), 0) FROM ' . $bazaCopiei . '.orders')->fetchColumn();
    $f = $formular();
    sleep(3);
    $r = $cerere('POST', '/checkout', $optClient + ['post' => $f['campuri'] + $client + ['payment_method' => 'cod']]);
    $numarComanda = (string) preg_replace('#^.*/checkout/succes/#', '', (string) ($r['antete']['location'] ?? ''));
    $verifica('comanda ramburs cu produsul refrigerat (bifa avertismentului) → plasată, cu confirmarea avertismentului în bază',
        $r['cod'] === 302 && str_contains((string) ($r['antete']['location'] ?? ''), '/checkout/succes/') && isset($f['campuri']['avertisment_bifa'])
        && (int) $root->query('SELECT COUNT(*) FROM ' . $bazaCopiei . '.order_product_warnings WHERE order_id > ' . $idMaxComenzi)->fetchColumn() === 1,
        $r['cod'] . ' ' . ($r['antete']['location'] ?? '-'));
    $mesaje = [];
    foreach ($mesajeSmtp() as $fisierMesaj) {
        $text = (string) file_get_contents($fisierMesaj);
        $antet = static fn (string $nume): string => preg_match('/^' . $nume . ': (.*)$/mi', $text, $m) === 1 ? trim($m[1]) : '';
        $html = '';
        if (preg_match('#Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--#s', $text, $m) === 1) {
            $html = (string) base64_decode((string) preg_replace('/\s+/', '', $m[1]));
        }
        $mesaje[] = ['autentificare' => $antet('X-Plic-Autentificare'), 'catre' => $antet('X-Plic-Catre'), 'to' => $antet('To'),
            'subiect' => (string) iconv_mime_decode($antet('Subject'), 0, 'UTF-8'), 'html' => $html, 'text' => $text];
    }
    $toateLaProprietar = $mesaje !== [] && array_filter($mesaje, static fn (array $m): bool => $m['catre'] !== '<' . $emailProprietar . '>'
        || $m['to'] !== '<' . $emailProprietar . '>' || $m['autentificare'] !== $casutaCopiei) === [];
    $verifica('emailurile comenzii (clientul și adminul): ' . count($mesaje) . ', toate doar la proprietar, prin căsuța copiei (serverul SMTP al testului)',
        count($mesaje) === 2 && $toateLaProprietar, json_encode(array_map(static fn (array $m): array => array_intersect_key($m,
            array_flip(['autentificare', 'catre', 'to', 'subiect'])), $mesaje), JSON_UNESCAPED_UNICODE) ?: '');
    $verifica('emailurile comenzii: „[VERIFICARE]” în subiect, nota „pe site … ar fi plecat la o adresă din afara copiei”, blocul avertismentului '
        . 'refrigerat; nicio adresă a clientului sau a adminului în destinatari', $mesaje !== [] && array_filter($mesaje, static fn (array $m): bool
            => !str_starts_with($m['subiect'], '[VERIFICARE] ') || !str_contains($m['html'], 'ar fi plecat la o adresă din afara copiei')
            || !str_contains($m['html'], $titluAvertisment) || str_contains($m['catre'] . $m['to'], 'exemplu.ro>') && $m['catre'] !== '<' . $emailProprietar . '>') === []
        && str_contains(implode(' ', array_column($mesaje, 'subiect')), $numarComanda), implode(' | ', array_column($mesaje, 'subiect')));
    $verifica('nicio conexiune la SMTP-ul, ERP-ul, FAN-ul sau banca din setări (adresa interzisă) și nimic prin proxy, la comandă și la paginile de mai sus',
        $conexiuni($portInterzis) === [] && $conexiuni($portProxy) === [], implode(' | ', array_merge($conexiuni($portInterzis), $conexiuni($portProxy))));
    $azi = json_decode((string) @file_get_contents($copie . '/storage/verificare/emailuri-azi.json'), true);
    $verifica('contorul zilei: 2 emailuri plecate, 0 sărite (emailuri-azi.json, 0600)', is_array($azi) && ($azi['trimise'] ?? 0) === 2
        && ($azi['sarite'] ?? -1) === 0 && (fileperms($copie . '/storage/verificare/emailuri-azi.json') & 0777) === 0600, json_encode($azi) ?: '');

    // Limita de 100 pe zi și emailurile fără căsuță, din linia de comandă (ca un cron).
    file_put_contents($copie . '/storage/verificare/emailuri-azi.json', json_encode(['zi' => date('Y-m-d'), 'trimise' => 100, 'sarite' => 0]));
    $mesajeInainte = count($mesajeSmtp());
    $rez = $inCopie('try { \App\Support\OrderMailer::sendCustom("abonat.newsletter@exemplu.ro", "Newsletter", "<p>Corp</p>", \App\Support\Settings::all(\App\Support\Database::connection((require "' . $copie . '/config/app.php")["db"])), null); return "trimis"; } catch (Throwable $e) { return $e->getMessage(); }');
    $azi = json_decode((string) @file_get_contents($copie . '/storage/verificare/emailuri-azi.json'), true);
    $verifica('al 101-lea email al zilei: nu pleacă (excepție „sărit”, deci „eșuat” în istoric), se numără ca sărit, nimic la SMTP',
        is_string($rez) && str_contains($rez, 'sărit') && count($mesajeSmtp()) === $mesajeInainte && ($azi['sarite'] ?? 0) === 1
        && ($azi['trimise'] ?? 0) === 100, json_encode([$rez, $azi], JSON_UNESCAPED_UNICODE) ?: '');
    @unlink($copie . '/storage/verificare/emailuri-azi.json');
    $scrieEnv(['VERIFICARE_SMTP_HOST' => '']);
    $rez = $inCopie('\App\Support\OrderMailer::sendCustom("client.cron@exemplu.ro", "Coș abandonat", "<p>Corp cron</p>", ["email_delivery_method" => "sendgrid", "sendgrid_api_key" => "SG.inventat"], null); return glob("' . $copie . '/storage/verificare/mail/*.eml") ?: [];');
    $eml = is_array($rez) && count($rez) === 1 ? (string) file_get_contents((string) $rez[0]) : '';
    $verifica('fără căsuța copiei: emailul devine fișier .eml (0600) în storage/verificare/mail, doar la proprietar, cu „[VERIFICARE]”; nici SendGrid, nici SMTP',
        $eml !== '' && (fileperms((string) $rez[0]) & 0777) === 0600 && str_contains($eml, 'To: <' . $emailProprietar . '>')
        && str_starts_with((string) iconv_mime_decode((string) (preg_match('/^Subject: (.*)$/m', $eml, $m) === 1 ? trim($m[1]) : ''), 0, 'UTF-8'), '[VERIFICARE] Coș abandonat')
        && str_contains((string) base64_decode((string) preg_replace('/\s+/', '', substr($eml, (int) strpos($eml, "\r\n\r\n")))), 'Corp cron')
        && count($mesajeSmtp()) === $mesajeInainte && $conexiuni($portProxy) === [], json_encode($rez, JSON_UNESCAPED_UNICODE) ?: '');
    $scrieEnv(['VERIFICARE_SMTP_HOST' => '127.0.0.1']);

    // Fiecare loc din cod, din linia de comandă (cum îl cheamă un cron), cu setările de mai sus; apoi drumurile permise.
    $rez = $inCopie(<<<'COD'
        $s = \App\Support\Settings::all(\App\Support\Database::connection((require \App\Support\Paths::radacina() . '/config/app.php')['db']));
        $refuza = static function (callable $f): string { try { $f(); return 'nerefuzat'; } catch (Throwable $e) { return $e->getMessage(); } };
        $fan = ['client_id' => 123456, 'username' => 'fan.inventat', 'password' => 'fan-parola-inventata'];
        return [
            'erp_din_setari' => \App\Support\ErpClient::fromSettings($s) === null && \App\Support\ErpClient::fromDb(\App\Support\Database::connection((require \App\Support\Paths::radacina() . '/config/app.php')['db'])) === null,
            'erp_direct' => $refuza(static fn () => (new \App\Support\ErpClient('http://127.0.0.1:9', 'k'))->ping()),
            'stripe_live' => $refuza(static fn () => \App\Support\StripeGateway::createCheckoutSession((string) $s['stripe_secret_key'], [])),
            'fan_awb' => $refuza(static fn () => \App\Support\FanCourierGateway::createInternalAwb($fan, [])),
            'fan_lockere' => $refuza(static fn () => \App\Support\FanCourierGateway::pickupPoints($fan)),
            'bt_mod' => \App\Support\BtIpayGateway::mod(),
            'bt_live' => \App\Support\BtIpayGateway::apel('live', 'getOrderStatusExtended', ['orderId' => 'x'])['eroare'],
            'bt_live_configurat' => \App\Support\BtIpayGateway::esteConfigurat('live'),
            'eu' => \App\Support\EuPlatescGateway::isEnabled($s),
            'eu_cerere' => $refuza(static fn () => \App\Support\EuPlatescGateway::buildRequest($s, ['order_number' => 'x', 'amount' => 1])),
            'tawk' => \App\Support\ChatLive::activ($s),
            'robots' => \App\Support\Robots::text('https://exemplu.test'),
        ];
        COD);
    $asteptat = is_array($rez) && ($rez['erp_din_setari'] ?? false) === true && str_contains((string) ($rez['erp_direct'] ?? ''), 'ERP-ul e oprit')
        && str_contains((string) ($rez['stripe_live'] ?? ''), 'doar o cheie de test') && str_contains((string) ($rez['fan_awb'] ?? ''), 'AWB-urile sunt oprite')
        && str_contains((string) ($rez['fan_lockere'] ?? ''), 'FAN Courier e oprit') && ($rez['bt_mod'] ?? '') === 'test'
        && str_contains((string) ($rez['bt_live'] ?? ''), 'oprite pe site-ul de verificare') && ($rez['bt_live_configurat'] ?? true) === false
        && ($rez['eu'] ?? true) === false && str_contains((string) ($rez['eu_cerere'] ?? ''), 'EuPlătesc e oprit') && ($rez['tawk'] ?? true) === false
        && ($rez['robots'] ?? '') === "User-agent: *\nDisallow: /\n";
    $verifica('linia de comandă (ca un cron), cu setările site-ului adevărat: ERP null, Stripe live, AWB, lockere FAN, BT live, EuPlătesc, tawk.to, '
        . 'robots — fiecare oprit la locul lui, nicio ieșire', $asteptat && $conexiuni($portInterzis) === [] && $conexiuni($portProxy) === [],
        json_encode($rez, JSON_UNESCAPED_UNICODE) ?: '');
    // Drumurile permise ajung la plasa de siguranță (deci ea chiar numără): o cheie sk_test_, o copie *-verif a ERP-ului,
    // contul FAN de test, BT în modul de test.
    $rez = $inCopie(<<<'COD'
        $incearca = static function (callable $f): string { try { $f(); return 'fără eroare'; } catch (Throwable $e) { return $e->getMessage(); } };
        $pune = static function (string $cheie, string $valoare): void { $_ENV[$cheie] = $valoare; putenv($cheie . '=' . $valoare); };
        $stripe = $incearca(static fn () => \App\Support\StripeGateway::createCheckoutSession('sk_test_inventat', []));
        $pune('VERIFICARE_ERP_URL', 'https://copie-verif.erp.andaxi.ro');
        $pune('VERIFICARE_ERP_CHEIE', 'cheie-copie-erp');
        $client = \App\Support\ErpClient::fromSettings(['erp_url' => 'http://127.0.0.1:9', 'erp_api_key' => 'k']);
        $erp = $client === null ? 'null' : $incearca(static fn () => $client->ping());
        $pune('VERIFICARE_FAN_TEST', '1');
        $fan = $incearca(static fn () => \App\Support\FanCourierGateway::pickupPoints(['client_id' => 1, 'username' => 'fan.test', 'password' => 'p']));
        $bt = \App\Support\BtIpayGateway::apel('test', 'getOrderStatusExtended', ['orderId' => 'x'])['eroare'];
        return ['stripe' => $stripe, 'erp' => $erp, 'fan' => $fan, 'bt' => $bt];
        COD);
    $laProxy = $conexiuni($portProxy);
    $laInterzis = $conexiuni($portInterzis);
    $verifica('drumurile permise ajung la plasa de siguranță (deci ea chiar numără): sk_test_ → api.stripe.com, copia *-verif a ERP-ului, contul FAN '
        . 'de test și BT de test → adresele lor', is_array($rez) && !str_contains((string) ($rez['stripe'] ?? ''), 'doar o cheie de test')
        && ($rez['erp'] ?? 'null') !== 'null' && !str_contains((string) ($rez['fan'] ?? ''), 'FAN Courier e oprit')
        && !str_contains((string) ($rez['bt'] ?? ''), 'oprite pe site-ul de verificare')
        && count($laProxy) === 2 && str_starts_with($laProxy[0], 'CONNECT api.stripe.com:443') && str_starts_with($laProxy[1], 'CONNECT copie-verif.erp.andaxi.ro:443')
        // FAN: autentificarea cu POST, apoi (502) încă o dată cu GET, ca FanCourierGateway::token; apoi banca.
        && count($laInterzis) === 3 && str_starts_with($laInterzis[0], 'POST /login?') && str_starts_with($laInterzis[1], 'GET /login?')
        && str_starts_with($laInterzis[2], 'POST /payment/rest/getOrderStatusExtended.do'),
        json_encode(['rez' => $rez, 'proxy' => $laProxy, 'interzis' => $laInterzis], JSON_UNESCAPED_UNICODE) ?: '');
    $iesiriPermise = [$laProxy, $laInterzis];

    // Confirmările de plată, cu copia încărcată: fără parolă doar cu VERIFICARE_WEBHOOKURI_DESCHISE=1.
    $r = $cerere('POST', '/webhook/stripe', ['post' => ['x' => '1']]);
    $scrieEnv(['VERIFICARE_WEBHOOKURI_DESCHISE' => '1']);
    $r2 = $cerere('POST', '/webhook/stripe', ['post' => ['x' => '1']]);
    $scrieEnv(['VERIFICARE_WEBHOOKURI_DESCHISE' => '0']);
    $verifica('webhook-urile cu copia încărcată: 401 fără parolă; cu VERIFICARE_WEBHOOKURI_DESCHISE=1 ajung la aplicație (semnătura lipsă → 400), noindex',
        $r['cod'] === 401 && $r2['cod'] === 400 && $antetPoarta($r2), $r['cod'] . ' / ' . $r2['cod']);

    // ── B6. Fără SITE_COPIE_VERIFICARE ──
    $scrie('');
    $scrie('B6. SITE_COPIE_VERIFICARE=0 (un site obișnuit cu .env), cu aceleași setări: ce ar fi oprit gărzile');
    // ERP-ul ar cere stocul pe pagini (adresa interzisă): aici doar paginile, deci ERP-ul oprit în setări.
    $setari(['erp_enabled' => '0', 'erp_stock_enabled' => '0']);
    $scrieEnv(['SITE_COPIE_VERIFICARE' => '0']);
    $r = $cerere('GET', '/');
    $verifica('fără parolă → pagina aplicației, fără antetele porții', $r['cod'] === 200 && !isset($r['antete']['x-robots-tag'])
        && ($r['antete']['cache-control'] ?? '') !== Verificare::CACHE && !isset($r['antete']['www-authenticate']));
    $r = $cerere('GET', '/', ['cookie' => $consimtamant]);
    $verifica('cu „Accept toate”: etichetele terților din setări apar (GTM, Clarity, tawk.to, google-site-verification, gtag), fără banner',
        count($cuEtichete($r['corp'])) === count($etichete) && !str_contains($r['corp'], Verificare::ID_BANNER), implode(', ', $cuEtichete($r['corp'])));
    $r = $cerere('GET', '/robots.txt');
    $verifica('/robots.txt e cel al aplicației (cu Sitemap: și Allow: pentru fluxul de produse)', $r['cod'] === 200 && str_contains($r['corp'], 'Sitemap:')
        && str_contains($r['corp'], 'Allow: /feed/google-merchant.xml'));
    $r = $cerere('GET', '/auth/google');
    $verifica('login-ul cu Google pornit: /auth/google → accounts.google.com (doar o redirecționare a browserului)', $r['cod'] === 302
        && str_starts_with((string) ($r['antete']['location'] ?? ''), 'https://accounts.google.com/'), $r['cod'] . ' ' . ($r['antete']['location'] ?? '-'));
    $r = $cerere('GET', '/uploads/products/nu-exista.webp');
    $verifica('o poză lipsă → 404 (fără redirect)', $r['cod'] === 404 && !isset($r['antete']['location']), (string) $r['cod']);
    $jarFara = $dosarTest . '/jar-fara.txt';
    $cerere('POST', '/cos/adauga/' . $idMenta, ['jar' => $jarFara, 'post' => ['quantity' => '1']]);
    $r = $cerere('GET', '/checkout', ['jar' => $jarFara]);
    preg_match_all('/name="payment_method"[^>]*value="([a-z_]+)"/', $r['corp'], $metode);
    $verifica('checkout-ul are EuPlătesc și BT (modul live din .env), deci testul de mai sus vede ce opresc gărzile',
        in_array('euplatesc', $metode[1], true) && in_array('btipay', $metode[1], true), json_encode(array_values(array_unique($metode[1]))) ?: '');
    $verifica('nici fără gărzi, paginile de mai sus n-au trimis nimic în afară', $conexiuni($portInterzis) === $iesiriPermise[1]
        && $conexiuni($portProxy) === $iesiriPermise[0], implode(' | ', array_merge($conexiuni($portInterzis), $conexiuni($portProxy))));
    $scrieEnv(['SITE_COPIE_VERIFICARE' => '1']);

    // ── B7. licenta.php și sterge.php ──
    $scrie('');
    $scrie('B7. tools/verificare/licenta.php și tools/verificare/sterge.php');
    $r = $ruleaza('tools/verificare/licenta.php', ['bioscem'], '', $dosarTest . '/alt-acasa');
    $verifica('licenta.php în afara site-ului de verificare (alt dosar) → refuz, cod 1', $r['cod'] === 1
        && str_contains($r['iesire'], 'rulează doar din dosarul site-ului de verificare'), trim($r['iesire']));
    $scrieEnv(['LICENTA_SITE' => 'bioscem']);
    $r = $ruleaza('tools/verificare/licenta.php', ['bioscem'], '');
    $scrieEnv(['LICENTA_SITE' => 'verificare']);
    $r2 = $ruleaza('tools/verificare/licenta.php', ['nu-exista'], '');
    $r3 = $ruleaza('tools/verificare/licenta.php', ['bioscem', 'nu_exista=oprit'], '');
    $verifica('licenta.php refuză: LICENTA_SITE care nu începe cu „verificare”, un pachet sau un plugin necunoscut', $r['cod'] === 1
        && str_contains($r['iesire'], 'nu începe cu „verificare”') && $r2['cod'] === 1 && $r3['cod'] === 1, trim($r['iesire'] . ' | ' . $r2['iesire'] . ' | ' . $r3['iesire']));
    $r = $ruleaza('tools/verificare/licenta.php', ['presetari'], '');
    $verifica('licenta.php presetari: din catalogul codului (toate, bioscem, grafoanaytis, magazin …)', $r['cod'] === 0
        && count(array_intersect(['toate', 'bioscem', 'biovitality', 'vertij', 'grafoanaytis', 'prezentare', 'catalog', 'magazin', 'asociatie'],
            explode("\n", trim($r['iesire'])))) === 9, trim($r['iesire']));
    $r = $ruleaza('tools/verificare/licenta.php', ['bioscem', 'blog=oprit'], '');
    $r2 = $cerere('GET', '/blog', ['basic' => $bunaBasic]);
    $r3 = $cerere('GET', '/magazin', ['basic' => $bunaBasic]);
    $verifica('licenta.php bioscem blog=oprit (o versiune mai mică decât cea de dinainte nu contează: licența bună se șterge) → /blog 404, /magazin 200',
        $r['cod'] === 0 && str_contains($r['iesire'], '(bioscem)') && !str_contains($r['iesire'], 'blog,') && $r2['cod'] === 404 && $r3['cod'] === 200,
        trim($r['iesire']) . ' | ' . $r2['cod'] . ' ' . $r3['cod']);
    $r = $ruleaza('tools/verificare/licenta.php', ['toate'], '');
    $r2 = $cerere('GET', '/blog', ['basic' => $bunaBasic]);
    $r3 = $ruleaza('tools/verificare/licenta.php', ['arata'], '');
    $verifica('licenta.php toate → /blog din nou 200; arata: licența „verificare”, blog pornit', $r['cod'] === 0 && $r2['cod'] === 200
        && str_contains($r3['iesire'], 'Licența „verificare”') && preg_match('/^\s+blog\s+pornit$/m', $r3['iesire']) === 1, trim($r3['iesire']));

    // sterge.php: ce trebuie să rămână și ce trebuie să plece.
    $santinela = $dosarTest . '/santinela-in-afara.txt';
    file_put_contents($santinela, 'nu se șterge');
    @mkdir($acasa . '/domains/' . $gazda . '/cod-vechi/bioscem/src', 0770, true);
    file_put_contents($acasa . '/domains/' . $gazda . '/cod-vechi/bioscem/src/Vechi.php', '<?php // codul vechi');
    symlink($santinela, $acasa . '/domains/' . $gazda . '/cod-vechi/bioscem/legatura-in-afara.txt');
    @mkdir($copie . '/storage/verificare/tmp', 0770, true);
    file_put_contents($copie . '/storage/verificare/tmp/bucata.sql', 'x');
    file_put_contents($copie . '/storage/cache/pages/pagina-test.html', 'x');
    @mkdir($copie . '/storage/verificare/sonde', 0700, true);
    file_put_contents($copie . '/storage/verificare/sonde/agregate-test.txt', 'x');
    file_put_contents($copie . '/storage/sessions/sess_test', 'x');
    $tabeleInainte = $tabeleIn($bazaCopie);
    $r = $ruleaza('tools/verificare/sterge.php', ['--da'], '', $dosarTest . '/alt-acasa');
    $r2 = $ruleaza('tools/verificare/sterge.php', [], "nu\n");
    $verifica('sterge.php în alt dosar → refuz; fără „da” → nimic șters (cod 1)', $r['cod'] === 1 && str_contains($r['iesire'], 'Nu am șters nimic')
        && $r2['cod'] === 1 && str_contains($r2['iesire'], 'Nu am șters nimic') && $tabeleIn($bazaCopie) === $tabeleInainte
        && is_file($copie . '/storage/verificare/incarcat-la') && is_file($copie . '/storage/verificare/tmp/bucata.sql'), trim($r['iesire'] . ' | ' . $r2['iesire']));
    file_put_contents($acasa . '/domains/alt-site.exemplu.test/public_html/.env', 'DB_DATABASE=' . $bazaCopie . "\n");
    $r = $ruleaza('tools/verificare/sterge.php', ['--da'], '');
    unlink($acasa . '/domains/alt-site.exemplu.test/public_html/.env');
    $scrieEnv(['DB_DATABASE' => 'cms_verif_plina']);
    $r2 = $ruleaza('tools/verificare/sterge.php', ['--da'], '');
    $scrieEnv(['DB_DATABASE' => '']);
    $r3 = $ruleaza('tools/verificare/sterge.php', ['--da'], '');
    $scrieEnv(['DB_DATABASE' => $bazaCopie]);
    $verifica('sterge.php refuză o bază scrisă în .env-ul altui site, o bază cu tabele fără _verificare și un .env fără DB_DATABASE (nimic șters)',
        $r['cod'] === 1 && str_contains($r['iesire'], 'alt-site.exemplu.test') && $r2['cod'] === 1 && str_contains($r2['iesire'], 'nu e o copie de verificare')
        && $r3['cod'] === 1 && str_contains($r3['iesire'], 'DB_DATABASE lipsește') && is_file($copie . '/storage/verificare/incarcat-la')
        && $tabeleIn($bazaCopie) === $tabeleInainte && $tabeleIn('cms_verif_plina') === 2, trim($r['iesire'] . ' | ' . $r2['iesire'] . ' | ' . $r3['iesire']));
    $r = $ruleaza('tools/verificare/sterge.php', [], "da\n");
    $rPagina = $cerere('GET', '/', ['basic' => $bunaBasic]);
    $verifica('sterge.php cu „da”: GATA, baza goală, incarcat-la, emailurile, temporarele, cache-ul, sesiunile și cod-vechi/ șterse; poarta arată „lipsește”',
        $r['cod'] === 0 && str_contains($r['iesire'], 'GATA: copia a fost ștearsă') && $tabeleIn($bazaCopie) === 0
        && !is_file($copie . '/storage/verificare/incarcat-la') && glob($copie . '/storage/verificare/mail/*.eml') === []
        && !is_file($copie . '/storage/verificare/tmp/bucata.sql') && !is_file($copie . '/storage/cache/pages/pagina-test.html')
        && !is_file($copie . '/storage/sessions/sess_test') && !file_exists($acasa . '/domains/' . $gazda . '/cod-vechi')
        && $rPagina['cod'] === 503 && str_contains($rPagina['corp'], 'lipsește'), trim($r['iesire']));
    $verifica('sterge.php: legătura din cod-vechi/ ștearsă fără să urmeze (fișierul de afară rămâne); .gitkeep, sondele, .env, licența și cheia rămân; '
        . 'sondele amintite', is_file($santinela) && is_file($copie . '/storage/cache/pages/.gitkeep') && is_file($copie . '/storage/verificare/sonde/agregate-test.txt')
        && is_file($copie . '/.env') && is_file($copie . '/storage/licenta/licenta.json') && is_file($fisierCheie)
        && str_contains($r['iesire'], 'storage/verificare/sonde/ mai sunt 1 fișiere'), trim($r['iesire']));
    $r = $ruleaza('tools/verificare/sterge.php', ['--da'], '');
    $verifica('sterge.php a doua oară, pe baza goală → tot GATA, 0 tabele', $r['cod'] === 0 && str_contains($r['iesire'], '0 tabele')
        && str_contains($r['iesire'], 'GATA'), trim($r['iesire']));
    $verifica('ieșirile lui licenta.php și sterge.php: nicio parolă, cheie sau adresă de email; numele bazei nu apare', $faraSecrete($r['iesire']) === ''
        && !str_contains($r['iesire'], $bazaCopie));

    $verifica('la final, plasa de siguranță: doar ieșirile permise de mai sus (Stripe de test, copia ERP, contul FAN și BT de test), niciun alt email',
        $conexiuni($portProxy) === $iesiriPermise[0] && $conexiuni($portInterzis) === $iesiriPermise[1] && count($mesajeSmtp()) === 2,
        implode(' | ', array_merge($conexiuni($portProxy), $conexiuni($portInterzis))));
    $verifica('niciun email trimis de copie prin mail()', $emailuri() === 0, (string) $emailuri());
    $verifica('baza goală a rămas goală', $tabeleIn('cms_verif_gol') === 0);
} catch (Throwable $e) {
    $verifica('testul a mers până la capăt', false, $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    $opresteServerul();
    clearstatcache();
    $jurnal = (string) @file_get_contents($jurnalErori);
    $grave = array_values(array_filter(explode("\n", $jurnal), static fn (string $l): bool
        => preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Recoverable fatal error)/', $l) === 1));
    $scrie('');
    $verifica('jurnalul PHP al copiei: niciun avertisment sau eroare', $grave === [], implode("\n          ", array_slice($grave, 0, 10)));
    $curata();
}

$scrie('');
$scrie('Rezultat: ' . $trecute . ' verificări trecute, ' . $esuate . ' eșuate.');
exit($esuate === 0 ? 0 : 1);
