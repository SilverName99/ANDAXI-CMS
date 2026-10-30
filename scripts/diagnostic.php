<?php

declare(strict_types=1);

/**
 * Diagnostic pentru rulările din cron.
 *
 * Cand un script de cron spune ca o setare lipseste, desi in admin ea se vede
 * completata, intrebarea reala e: cronul citeste aceeasi baza de date ca
 * site-ul? Scriptul asta raspunde — arata ce fisier .env a gasit, la ce baza
 * s-a conectat si ce chei are efectiv in tabelul `settings`.
 *
 * NU afiseaza valori sensibile: pentru parole si chei API scrie doar daca sunt
 * completate si cate caractere au.
 *
 * Arată și licența: dacă `licenta.json` / `licenta-buna.json` se verifică cu
 * cheia publică și site-ul din .env și ce stări dau pluginurilor. Înainte de a
 * urca o versiune nouă de cod pe un site, rulează `--licenta`: iese cu 1 dacă
 * nicio licență nu se verifică (atunci site-ul ar merge doar pe ultima licență
 * aplicată sau, pe un site nou, doar cu nucleul).
 *
 * Arată și versiunea de PHP a liniei de comandă față de minimul cerut de cod
 * (`php_min` din config/app.php) și iese cu 1 sub el. Versiunea site-ului (web) poate
 * fi alta: pe Hostinger se vede în hPanel → PHP Configuration.
 *
 * Arată și pachetul de date al site-ului (`--pachet`, docs/PACHETE.md): dosarul (`SITE_DATE_DOSAR`), manifestul
 * `pachet.json` (fiecare fișier cu sha256-ul lui, site-ul = `LICENTA_SITE` = `SITE_SLUG`), tema și copiile fișierelor publice ale
 * pachetului în docroot (`tema/public/` în `public/tema/`, `public/` peste `public/`). Iese cu 1 dacă ceva nu se
 * potrivește: un pachet lipsă sau stricat nu are voie să treacă neobservat (numele cookie-ului de acord, de exemplu,
 * ar reveni la cel implicit).
 *
 * Rulare:
 *   /usr/bin/php /cale/catre/scripts/diagnostic.php
 *   /usr/bin/php /cale/catre/scripts/diagnostic.php --licenta    # doar licența și PHP-ul (verificarea dinaintea unui deploy)
 *   /usr/bin/php /cale/catre/scripts/diagnostic.php --pachet     # doar pachetul de date al site-ului (la adoptare, după copiere)
 *   /usr/bin/php /cale/catre/scripts/diagnostic.php --cheie-newsletter   # rândul NEWSLETTER_CHEIE_SEMNARE=… de pus în .env la
 *                                                                        # adoptare: cheia derivată pe care site-ul o folosește acum
 *   /usr/bin/php /cale/catre/scripts/diagnostic.php --email      # emailurile trimise, doar numere (WP-26, VT-O1): pe tipuri și
 *                                                                # stări (24 de ore, 7 zile), ultima reușită și ultimul eșec,
 *                                                                # linkurile de confirmare a adresei, înregistrările; nicio adresă,
 *                                                                # niciun IP, niciun text de eroare (verifica-email.php: configurația)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// .env-ul site-ului (App\Support\Paths: azi în rădăcina codului, în Etapa 2 în dosarul site-ului), văzut înainte
// ca bootstrap.php să-l citească.
require_once __DIR__ . '/../src/Support/Paths.php';
$envPath = App\Support\Paths::env();
$envExista = is_file($envPath);
$envCitibil = $envExista && is_readable($envPath);

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\DateSite;
use App\Support\Env;
use App\Support\Licenta;
use App\Support\PachetSite;
use App\Support\Paths;
use App\Support\Plugins;
use App\Support\PozeOptimizate;
use App\Support\Settings;
use App\Support\Tema;
use App\Support\Verificare;

$config = require __DIR__ . '/../config/app.php';

// PHP-ul minim pe care trebuie să meargă codul (config/app.php → php_min).
$phpMin = (string) ($config['php_min'] ?? '8.3');
$phpPreaVechi = version_compare(PHP_VERSION, $phpMin, '<');
$arataPhp = static function () use ($phpMin, $phpPreaVechi): void {
    echo 'versiune PHP (CLI)  : ' . PHP_VERSION . ', minim ' . $phpMin
        . ($phpPreaVechi ? ' -> PREA VECHE: codul nu e făcut pentru ea (config/app.php php_min)' : ' -> ok') . "\n";
};

/** Licența: se verifică acum? Întoarce true dacă da. */
$arataLicenta = static function (): bool {
    echo "=== Licență ===\n";
    echo 'dosar        : ' . Licenta::dosar() . "\n";
    echo 'site (.env)  : ' . (Licenta::site() !== '' ? Licenta::site() : 'LIPSESTE (LICENTA_SITE)') . "\n";
    $rezultat = Licenta::incarca();
    $licenta = $rezultat['licenta'];
    if ($licenta !== null) {
        echo 'licenta      : ok, ' . $licenta['site'] . ' v' . $licenta['versiune'] . ' din ' . $licenta['emisa_la']
            . ' (' . (string) $rezultat['sursa'] . ")\n";
    } else {
        echo "licenta      : NU SE VERIFICA\n";
    }
    foreach ($rezultat['probleme'] as $problema) {
        echo '  problema   : ' . $problema . "\n";
    }
    if (Plugins::licentaNeverificata()) {
        echo "  site-ul merge pe ultima licenta aplicata (stare-aplicata.json)\n";
    } elseif (Plugins::licentaLipseste()) {
        echo "  site-ul merge DOAR CU NUCLEUL (magazinul, platile si cronurile lor sunt oprite)\n";
    }
    $pornite = [];
    foreach (Plugins::stari() as $cheie => $stare) {
        if ($stare !== 'oprit') {
            $pornite[] = $cheie . ($stare === 'se_opreste' ? ' (se opreste)' : '');
        }
    }
    echo 'pluginuri    : ' . ($pornite === [] ? 'doar nucleul' : implode(', ', $pornite)) . "\n";
    return $licenta !== null;
};

// La adoptare (docs/MIGRARI.md, „Pe server, la adoptare”): cheia cu care site-ul își semnează acum linkurile din newslettere,
// ca rând de pus în .env. Rulat pe site-ul live, cu .env-ul lui (APP_URL și baza lui), înainte de comutare.
if (in_array('--cheie-newsletter', $argv, true)) {
    $sursa = \App\Support\NewsletterService::sursaCheiiSemnare();
    if ($sursa === 'env') {
        echo "NEWSLETTER_CHEIE_SEMNARE e deja in .env; nimic de facut.\n";
        exit(0);
    }
    $sare = \App\Support\DateSite::site('newsletter_sare_semnatura');
    if (!is_string($sare) || trim($sare) === '') {
        fwrite(STDERR, "Pachetul site-ului n-are site.json → newsletter_sare_semnatura: nu stiu ce cheie folosea site-ul.\n");
        exit(1);
    }
    echo 'NEWSLETTER_CHEIE_SEMNARE=' . \App\Support\NewsletterService::cheieDerivata(trim($sare)) . "\n";
    exit(0);
}

// Emailurile, doar agregate (WP-26, VT-O1: în locul scriptului vertij diagnose-account-emails.php, care afișa adrese și IP-uri).
if (in_array('--email', $argv, true)) {
    $dbEmail = Database::connection((array) ($config['db'] ?? []));
    if (!$dbEmail instanceof PDO) {
        echo "baza         : NU (conexiunea nu merge)\n";
        exit(1);
    }
    $setariEmail = Settings::all($dbEmail);
    echo "=== Emailuri (doar numere) ===\n";
    echo 'metoda       : ' . (trim((string) ($setariEmail['email_delivery_method'] ?? '')) ?: 'smtp') . "\n";
    $numar = static function (string $sql) use ($dbEmail): ?string {
        try {
            $v = $dbEmail->query($sql)->fetchColumn();
            return $v === false || $v === null ? '-' : (string) $v;
        } catch (Throwable) {
            return null;
        }
    };
    foreach (['24 HOUR' => '24 de ore', '7 DAY' => '7 zile'] as $interval => $eticheta) {
        try {
            $randuri = $dbEmail->query('SELECT email_type, status, COUNT(*) AS n FROM email_send_history WHERE created_at >= NOW() - INTERVAL '
                . $interval . ' GROUP BY email_type, status ORDER BY email_type, status')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $randuri = null;
        }
        echo 'trimise ' . str_pad($eticheta, 5) . ': ' . ($randuri === null ? 'istoricul nu se poate citi' : ($randuri === [] ? 'niciunul' : '')) . "\n";
        foreach ((array) $randuri as $r) {
            echo '  ' . str_pad((string) $r['email_type'], 36) . ' ' . str_pad((string) $r['status'], 8) . ' ' . (int) $r['n'] . "\n";
        }
    }
    echo 'ultima reușită: ' . ($numar("SELECT MAX(created_at) FROM email_send_history WHERE status = 'sent'") ?? '-') . "\n";
    echo 'ultimul eșec  : ' . ($numar("SELECT MAX(created_at) FROM email_send_history WHERE status <> 'sent'") ?? '-') . "\n";
    $confirmari = $numar('SELECT COUNT(*) FROM customer_email_confirmations WHERE created_at >= NOW() - INTERVAL 7 DAY');
    if ($confirmari !== null) {
        echo 'linkuri de confirmare (7 zile): ' . $confirmari . ' trimise, '
            . ($numar('SELECT COUNT(*) FROM customer_email_confirmations WHERE created_at >= NOW() - INTERVAL 7 DAY AND used_at IS NOT NULL') ?? '-')
            . " folosite\n";
        echo 'conturi neconfirmate: ' . ($numar('SELECT COUNT(*) FROM users WHERE email_confirmed_at IS NULL') ?? '-') . "\n";
    }
    $inregistrari = $numar('SELECT COUNT(*) FROM register_submit_logs WHERE created_at >= NOW() - INTERVAL 24 HOUR');
    if ($inregistrari !== null) {
        echo 'încercări de înregistrare (24 de ore): ' . $inregistrari . "\n";
    }
    exit(0);
}

if (in_array('--licenta', $argv, true)) {
    $licentaOk = $arataLicenta();
    $arataPhp();
    exit($licentaOk && !$phpPreaVechi ? 0 : 1);
}

/**
 * Pachetul de date al site-ului: manifestul, tema, copiile publice. Întoarce true dacă totul se potrivește. Al cui e
 * site-ul se spune de două ori în .env, și ambele trebuie să fie ale pachetului: SITE_SLUG (migrările rulează cu el pașii
 * site-ului, înghețarea setărilor; docs/MIGRARI.md) și LICENTA_SITE (licența, pluginurile). Una lipsă e o problemă:
 * fără ea nu se poate spune că pachetul copiat e chiar al acestui site. Singura excepție e copia de verificare
 * (SITE_COPIE_VERIFICARE=1, docs/VERIFICARE.md), a cărei licență e a ei (LICENTA_SITE începe cu „verificare”): acolo
 * pachetul e al lui SITE_SLUG.
 */
$arataPachet = static function (): bool {
    echo "=== Pachetul site-ului ===\n";
    $dosar = DateSite::dosar();
    $site = Licenta::site();
    $slug = trim((string) Env::get('SITE_SLUG', ''));
    $copieVerificare = Verificare::activ() && str_starts_with($site, 'verificare');
    echo 'dosar        : ' . $dosar . (is_dir($dosar) ? '' : ' (LIPSESTE)') . "\n";
    echo 'site (.env)  : ' . ($site !== '' ? $site : 'LIPSESTE (LICENTA_SITE)')
        . ($copieVerificare ? ' (licenta copiei de verificare; pachetul e al lui SITE_SLUG)' : '') . "\n";
    echo 'SITE_SLUG    : ' . ($slug !== '' ? $slug : 'LIPSESTE') . "\n";
    $probleme = PachetSite::verifica($dosar, $slug !== '' ? $slug : ($site !== '' && !$copieVerificare ? $site : null));
    if ($site === '') {
        $probleme[] = 'LICENTA_SITE lipsește din .env: nu se poate verifica al cui e pachetul';
    }
    if ($slug === '') {
        $probleme[] = 'SITE_SLUG lipsește din .env: migrările n-ar rula pașii site-ului (înghețarea setărilor)';
    } elseif ($site !== '' && $slug !== $site && !$copieVerificare) {
        $probleme[] = 'SITE_SLUG („' . $slug . '”) diferă de LICENTA_SITE („' . $site . '”)';
    }
    $amprenta = PachetSite::amprenta($dosar);
    echo 'manifest     : ' . ($amprenta !== null ? 'pachet.json, amprenta ' . $amprenta : 'LIPSESTE') . "\n";
    if (is_file($dosar . '/' . Tema::FISIER)) {
        $problemeTema = Tema::probleme();
        echo 'tema         : ' . ($problemeTema === [] ? 'ok' : 'PROBLEME') . "\n";
        foreach ($problemeTema as $problema) {
            $probleme[] = Tema::FISIER . ': ' . $problema;
        }
    } else {
        echo "tema         : fara tema.json (paginile nucleului, neschimbate)\n";
    }
    // Fișierele publice ale pachetului se servesc din docroot: tema/public/ ca public/tema/, public/ la aceeași adresă.
    $copii = 0;
    foreach (is_dir($dosar) ? PachetSite::fisiere($dosar) : [] as $relativ) {
        $tinta = str_starts_with($relativ, 'tema/public/') ? 'tema/' . substr($relativ, strlen('tema/public/'))
            : (str_starts_with($relativ, 'public/') ? substr($relativ, strlen('public/')) : null);
        if ($tinta === null) {
            continue;
        }
        $copii++;
        $fisier = Paths::public($tinta);
        if (!is_file($fisier) || !hash_equals((string) hash_file('sha256', $dosar . '/' . $relativ), (string) hash_file('sha256', $fisier))) {
            $probleme[] = 'public/' . $tinta . (is_file($fisier) ? ' diferă de ' : ' lipsește; se copiază din ') . $relativ;
        }
    }
    echo 'publice      : ' . $copii . " fisiere ale pachetului, in docroot (tema/public/ -> public/tema/, public/ -> public/)\n";
    // Pozele refăcute ale site-ului (PozeOptimizate, poze-optimizate.json): fiecare trece pe WebP doar dacă poza veche de
    // pe disc are exact mărimea din pachet. O mărime luată printr-o descărcare (CDN-ul trimite alți octeți) nu se potrivește
    // niciodată (lecția Bioscem 070e1af): aici se vede pe server, cu mărimea de pe disc. Nu e o problemă a pachetului (o
    // poză înlocuită în galerie rămâne, voit, cea nouă), deci doar „atentie”.
    if (is_file($dosar . '/' . PozeOptimizate::FISIER)) {
        $poze = PozeOptimizate::verificaPeDisc();
        $peWebp = count(array_filter($poze, static fn (array $p): bool => $p['pe_disc'] === $p['octeti'] && $p['noua_exista']));
        echo 'poze refacute: ' . $peWebp . ' din ' . count($poze) . " trec pe WebP (marimea pozei vechi pe disc = cea din pachet)\n";
        if ($poze === []) {
            $probleme[] = PozeOptimizate::FISIER . ' nu se poate folosi (marimi_de_pe trebuie sa fie „disc”, cu cel putin o poza; vezi tools/dev/verifica-pachet.php)';
        }
        foreach ($poze as $p) {
            if ($p['pe_disc'] !== $p['octeti']) {
                echo '  atentie    : ' . $p['veche'] . ($p['pe_disc'] === null ? ' lipseste de pe disc' : ' are ' . $p['pe_disc'] . ' octeti pe disc')
                    . ', pachetul spune ' . $p['octeti'] . ": ramane poza veche (marimea se ia pe server, cu stat -c %s, nu dintr-o descarcare prin CDN)\n";
            }
        }
    }
    foreach ($probleme as $problema) {
        echo '  problema   : ' . $problema . "\n";
    }
    echo 'rezultat     : ' . ($probleme === [] ? 'ok' : 'NU E IN REGULA (' . count($probleme) . ' probleme)') . "\n";
    return $probleme === [];
};

if (in_array('--pachet', $argv, true)) {
    exit($arataPachet() ? 0 : 1);
}

echo "=== Mediu ===\n";
echo 'radacina aplicatiei : ' . Paths::radacina() . "\n";
echo 'fisier .env         : ' . ($envExista ? $envPath : 'LIPSESTE') . "\n";
echo 'citibil             : ' . ($envCitibil ? 'da' : 'NU (cronul ruleaza cu alt utilizator?)') . "\n";
echo 'dosarul storage     : ' . Paths::stocare() . "\n";
// Al acestui proces: în așezarea de azi linia de comandă n-are fișier (erorile pe ecran, stderr), iar paginile
// scriu în storage/logs/php.log dacă serverul web nu are alt jurnal (App\Support\Paths::alegeJurnalulPhp).
echo 'jurnal erori PHP    : ' . ((string) ini_get('error_log') !== '' ? (string) ini_get('error_log')
    : 'pe ecran (stderr); paginile: ' . Paths::stocare('logs/php.log') . ' daca serverul web nu are alt jurnal') . "\n";
echo 'utilizator sistem   : ' . (function_exists('posix_geteuid') ? (string) posix_geteuid() : 'necunoscut') . "\n";
$arataPhp();

echo "\n=== Baza de date ===\n";
echo 'host      : ' . (string) ($config['db']['host'] ?? '') . "\n";
echo 'baza      : ' . ((string) ($config['db']['database'] ?? '') !== '' ? (string) $config['db']['database'] : 'GOALA (.env necitit)') . "\n";
echo 'utilizator: ' . (string) ($config['db']['username'] ?? '') . "\n";

$db = Database::connection($config['db']);
if (!$db instanceof PDO) {
    echo "conectare : ESUATA\n";
    exit(1);
}
echo "conectare : ok\n";

try {
    $numeReal = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    echo 'baza activa: ' . $numeReal . "\n";
} catch (Throwable $e) {
    echo 'baza activa: eroare (' . $e->getMessage() . ")\n";
}

echo "\n=== Setari ===\n";
try {
    $total = (int) $db->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    echo 'randuri in tabelul settings: ' . $total . "\n";
} catch (Throwable $e) {
    echo 'tabelul settings: eroare (' . $e->getMessage() . ")\n";
    exit(1);
}

$settings = Settings::all($db);

// Cheile pe care se sprijina cronurile. Valorile sensibile nu se afiseaza.
$deVerificat = [
    'fan_client_id' => 'public',
    'fan_api_username' => 'public',
    'fan_api_password' => 'secret',
    'email_delivery_method' => 'public',
    'smtp_host' => 'public',
    'smtp_password' => 'secret',
    'sendgrid_api_key' => 'secret',
    'erp_api_key' => 'secret',
];

foreach ($deVerificat as $cheie => $fel) {
    $valoare = trim((string) ($settings[$cheie] ?? ''));
    if ($valoare === '') {
        printf("  %-24s NECOMPLETAT\n", $cheie);
        continue;
    }
    printf(
        "  %-24s %s\n",
        $cheie,
        $fel === 'secret' ? 'completat (' . strlen($valoare) . ' caractere)' : $valoare
    );
}

// Cheile de semnare ale linkurilor din newslettere. Din WP-R de la 30 oct (codul vechi 9aae46a, fd07fc3) linkurile noi se semnează
// cu o cheie aleatoare (NEWSLETTER_SECRET din .env, altfel newsletter_cheie_semnare, făcută la prima semnare); cheia de dinainte
// (WP-17: fixată în .env la adoptare, altfel derivată) verifică doar linkurile trimise înainte, până la pragul scris la adoptare.
// Cheile însele nu se afișează; doar de unde vin.
echo "\n=== Newsletter ===\n";
$sursaNewsletter = \App\Support\NewsletterService::sursaCheiiSemnare();
$secretNewsletter = trim((string) \App\Support\Env::get('NEWSLETTER_SECRET', '')) !== '';
$cheieInBaza = '';
$pragNewsletter = '';
try {
    $cheieInBaza = (string) ($db->query("SELECT `value` FROM settings WHERE `key` = 'newsletter_cheie_semnare'")->fetchColumn() ?: '');
    $pragNewsletter = (string) ($db->query("SELECT `value` FROM settings WHERE `key` = 'newsletter_cheie_limita'")->fetchColumn() ?: '');
} catch (Throwable) {
}
echo 'cheia linkurilor noi: ' . ($secretNewsletter ? 'din .env (NEWSLETTER_SECRET)'
    : (strlen($cheieInBaza) >= 32 ? 'aleatoare, in baza (newsletter_cheie_semnare)' : 'inca nefacuta (se face la prima semnare)')) . "\n";
echo 'cheia veche (doar linkurile trimise inainte): ' . match ($sursaNewsletter) {
    'env' => 'din .env (NEWSLETTER_CHEIE_SEMNARE)',
    'env_gresit' => 'ATENTIE: NEWSLETTER_CHEIE_SEMNARE din .env nu are 64 de cifre hexazecimale mici, deci se ignora',
    'site.json' => 'derivata cu sarea din pachetul site-ului (site.json → newsletter_sare_semnatura); la adoptare se scrie in .env',
    default => 'ATENTIE: derivata din LICENTA_SITE (nici .env, nici pachetul nu o fixeaza); linkurile trimise nu mai merg daca se schimba APP_URL, baza sau LICENTA_SITE',
} . "\n";
$prag = json_decode($pragNewsletter, true);
echo 'pragul cheii vechi: ' . (is_array($prag) ? 'campania ' . (int) ($prag['campanie'] ?? 0) . ', abonatul ' . (int) ($prag['abonat'] ?? 0)
    : ($pragNewsletter === '' ? 'ATENTIE: lipsa (il scrie migrarea newsletter/0040)' : 'niciun link vechi crezut (' . $pragNewsletter . ')')) . "\n";

echo "\n=== Comenzi cu AWB ===\n";
try {
    $cuAwb = (int) $db->query(
        'SELECT COUNT(*) FROM orders WHERE fan_awb IS NOT NULL AND fan_awb <> "" AND deleted_at IS NULL'
    )->fetchColumn();
    echo 'comenzi cu AWB: ' . $cuAwb . "\n";
} catch (Throwable $e) {
    echo 'comenzi: eroare (' . $e->getMessage() . ")\n";
}

echo "\n";
$licentaOk = $arataLicenta();

echo "\nGata.\n";
exit($licentaOk && !$phpPreaVechi ? 0 : 1);
