<?php

declare(strict_types=1);

/**
 * Pluginurile `donatii` și `membri` (din vertij; docs/PLUGINURI.md, „Donații”, „Membri”) față de codul vertij însuși, la
 * „ultimul commit revizuit” din docs/de-portat.md.
 *
 *   tools/dev/php.sh tools/dev/test-donatii-membri.php
 *
 * Două baze cu structura codului vertij (tools/dev/migrari/vechi/vt.sql), aceleași rânduri inventate (rândurile demo ale bazei
 * de dezvoltare, plus categoriile de membru cu câmpurile lor, cereri în fiecare stare, plăți ale cotizației, donații unice și
 * lunare). Pe a doua migrările codului comun cu `--site=vertij`, ca la adoptare. Fiecare cod vorbește cu un Stripe fals al lui
 * (tools/dev/lib/stripe-fals.php; în copia codului vertij, singura schimbare e adresa API-ului, din STRIPE_API_BASE), cu
 * aceleași răspunsuri. Apoi, pe codul vertij și pe codul comun, aceleași cereri:
 *
 * A  adoptarea: cele șase tabele și coloanele contului au după migrări aceeași structură și aceleași rânduri; donatii/0062 și
 *    membri/0063 „detectata”, 0 operații;
 * B  webhook-ul Stripe (aceeași adresă, aceeași semnătură): plata unei donații, una eșuată, lunile unei donații lunare (în
 *    ambele forme ale facturii, și cu invoice.payment_succeeded pentru aceeași factură), abonamentul oprit, plata cotizației
 *    și una expirată, semnătura greșită, un tip necunoscut: aceleași coduri, aceleași rânduri, aceleași cereri spre Stripe,
 *    aceleași emailuri (subiect și destinatar); un eveniment retrimis: „duplicate” pe codul comun, nimic schimbat pe niciunul;
 * C  site-ul și adminul: donația din sertar (unică, lunară, refuzurile), sertarul pe o pagină publică, înscrierea cu tipul de
 *    cont și documentele, pagina „Verifică-ți adresa”, retrimiterea, confirmarea, cotizația (pagina contului, plata, întoarcerea),
 *    tab-ul donațiilor și oprirea donației lunare, poza de profil, cererea nouă, nota „acces restricționat”, Admin → Tipuri de
 *    cont, Cereri conturi (lista cu ultima plată, cererea, decizia, documentul), Setări conturi, Donații, Setări donații,
 *    ultima plată în lista de utilizatori: aceleași răspunsuri (cod, Location, JSON, partea paginii a pluginului) și aceleași
 *    rânduri, cu diferențele declarate aici, fiecare la locul ei (jetonul formularului, confirmarea cu parola);
 * D  doar pe codul comun: jetonul formularului (CSRF) pe fiecare POST, donația doar ca JSON, logarea unui cont neconfirmat,
 *    adresa dovedită de confirmare, donațiile fără cont arătate abia după dovadă, pluginurile oprite (adresele, meniul, banii
 *    notați mereu de webhook); și reparațiile revizuirii de securitate din 9 nov 2026, unde codul comun diferă voit de vertij
 *    (declarate la locul lor): înscrierea pe o adresă existentă nu mai schimbă contul (parola, datele, cererea), confirmarea
 *    prin resetarea parolei trece cererea la verificare, linkul de confirmare expiră după 7 zile, decizia doar pe ultima cerere
 *    „în verificare”, bugetul, suma maximă și destinația donației, mesajele Stripe ascunse, mai multe donații lunare,
 *    rambursările, lunile neplătite, abonamentele încheiate, ștergerea contului (abonamentele oprite în Stripe), adresele
 *    membrilor vechi dovedite la adoptare (site/vertij/0001) și cererile vechi neconfirmate care nu blochează logarea.
 *
 * Își face și își șterge bazele cms_dm_vechi, cms_dm_nou și dosarul $CMS_DEV_DIR/donatii-membri. Raportul:
 * $CMS_DEV_DIR/test-donatii-membri-raport.txt; cod de ieșire 0 doar dacă totul a trecut. Toate datele sunt inventate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = dirname(__DIR__, 2);
$dosarDev = rtrim((string) (getenv('CMS_DEV_DIR') ?: $radacina . '/storage/logs/dev'), '/');
$fisierRaport = $dosarDev . '/test-donatii-membri-raport.txt';
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
        $scrie('  EȘEC  ' . $eticheta . ($detalii !== '' ? ': ' . mb_substr($detalii, 0, 4000) : ''));
    }
};
$scrie('Test donatii și membri (din vertij) — ' . date('Y-m-d H:i:s') . ', PHP ' . PHP_VERSION);

$sock = (string) (getenv('CMS_DEV_SOCK') ?: '/tmp/claude-0/maria/sock');
$bazaDev = (string) getenv('DB_DATABASE');
$baze = ['vechi' => 'cms_dm_vechi', 'nou' => 'cms_dm_nou'];
$parti = ['vechi', 'nou'];
$dosarTest = $dosarDev . '/donatii-membri';
$pachetCopie = $dosarTest . '/pachet';
$dosarLicenta = $dosarTest . '/licenta';
$portBaza = (int) (getenv('CMS_DEV_PORT') ?: 8701);
$porturi = ['vechi' => $portBaza + 52, 'nou' => $portBaza + 53];
$porturiStripe = ['vechi' => $portBaza + 57, 'nou' => $portBaza + 58];
$servere = [];
$parolaAdmin = (string) getenv('ADMIN_DEFAULT_PASSWORD');
$secretWebhook = 'whsec_proba_' . bin2hex(random_bytes(8));
$parolaMembri = 'Parola-Membru-1';

$conectare = static function (?string $db = null) use ($sock): PDO {
    return new PDO('mysql:unix_socket=' . $sock . ($db !== null ? ';dbname=' . $db : '') . ';charset=utf8mb4', (string) (getenv('CMS_DEV_DB_USER') ?: 'root'),
        (string) (getenv('CMS_DEV_DB_PAROLA') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
};
$opresteServerele = static function () use (&$servere): void {
    foreach ($servere as $pid) {
        // Doar procesele pornite aici, după pid (și copiii lor, lucrătorii lui php -S).
        exec('pkill -TERM -P ' . (int) $pid . ' 2>/dev/null; kill ' . (int) $pid . ' 2>/dev/null');
    }
    $servere = [];
};
$curata = static function () use ($conectare, $baze, $dosarTest, $dosarDev, $opresteServerele): void {
    $opresteServerele();
    try {
        $root = $conectare();
        foreach ($baze as $b) {
            $root->exec('DROP DATABASE IF EXISTS ' . $b);
        }
    } catch (Throwable) {
    }
    if (str_starts_with($dosarTest, $dosarDev . '/') && is_dir($dosarTest)) {
        exec('chmod -R u+rwX ' . escapeshellarg($dosarTest) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dosarTest));
    }
};
register_shutdown_function($curata);
$curata();
mkdir($dosarTest, 0770, true);

$proces = static function (array $comanda, array $mediu) use ($radacina): array {
    $p = proc_open($comanda, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tevi, $radacina, $mediu);
    if (!is_resource($p)) {
        return ['cod' => -1, 'iesire' => '', 'tot' => 'nu pornește'];
    }
    $iesire = (string) stream_get_contents($tevi[1]);
    $erori = (string) stream_get_contents($tevi[2]);
    fclose($tevi[1]);
    fclose($tevi[2]);
    return ['cod' => proc_close($p), 'iesire' => $iesire, 'tot' => $iesire . $erori];
};
$ini = ['-d', 'pdo_mysql.default_socket=' . $sock, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'memory_limit=512M', '-d', 'allow_url_fopen=0',
    '-d', 'sendmail_path=' . $radacina . '/tools/dev/mail-capturat.sh', '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=40M'];
$mediu = static function (string $parte, array $inPlus = []) use ($baze, $pachetCopie, $dosarLicenta, $porturiStripe, $dosarTest): array {
    $m = array_merge(getenv(), ['DB_DATABASE' => $baze[$parte], 'SITE_DATE_DOSAR' => $pachetCopie, 'LICENTA_DOSAR' => $dosarLicenta,
        'NO_PROXY' => '*', 'no_proxy' => '*', 'STRIPE_API_BASE' => 'http://127.0.0.1:' . $porturiStripe[$parte],
        'CMS_DEV_MAIL_DIR' => $dosarTest . '/mail-' . $parte], $inPlus);
    foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy', 'CMS_DEV_MARCAJE_PORT', 'CMS_DEV_HOOKS'] as $k) {
        unset($m[$k]);
    }
    return array_filter($m, static fn ($v): bool => $v !== null);
};
$php = static fn (string $parte, string $script, array $argumente): array
    => $proces(array_merge([PHP_BINARY], $ini, [$radacina . '/' . $script], $argumente), $mediu($parte));

/** Rândurile pluginurilor, fără momentele scrise cu NOW() (doar dacă sunt sau nu completate) și fără referințele aleatoare. */
$normal = static function (array $randuri, array $momente = [], array $aleatoare = []): array {
    foreach ($randuri as &$rand) {
        foreach ($momente as $c) {
            if (array_key_exists($c, $rand)) {
                $rand[$c] = $rand[$c] === null ? null : 'completat';
            }
        }
        foreach ($aleatoare as $c) {
            if (array_key_exists($c, $rand) && $rand[$c] !== null) {
                $rand[$c] = 'aleator';
            }
        }
    }
    unset($rand);
    return $randuri;
};
/** Coloanele adăugate de codul comun (donatii/0064, membri/0065: rambursările), scoase din comparația cu codul vertij. */
$faraColoaneNoi = static fn (array $randuri): array => array_map(static fn (array $r): array => array_diff_key($r,
    ['refunded_cents' => 1, 'refunded_at' => 1]), $randuri);
$starea = static function (string $parte) use ($conectare, $baze, $normal, $faraColoaneNoi): array {
    $db = $conectare($baze[$parte]);
    return [
        'donatii' => $normal($faraColoaneNoi($db->query('SELECT * FROM donations ORDER BY id')->fetchAll()), ['created_at', 'paid_at', 'cancelled_at'], ['reference']),
        'cereri' => $normal($db->query('SELECT id, user_id, profession_id, profession_name, status, answers_json, gdpr_accepted, gdpr_accepted_at, '
            . 'confirm_token_hash IS NOT NULL AS are_token, confirmed_at, reviewed_by, reviewed_at, review_note FROM account_verifications ORDER BY id')->fetchAll(),
            ['gdpr_accepted_at', 'confirmed_at', 'reviewed_at']),
        'documente' => $db->query('SELECT id, verification_id, field_key, field_label, original_name, mime_type, file_size FROM account_verification_documents ORDER BY id')->fetchAll(),
        'activari' => $normal($faraColoaneNoi($db->query('SELECT * FROM account_activations ORDER BY id')->fetchAll()), ['created_at', 'updated_at', 'paid_at', 'period_start', 'period_end']),
        'conturi' => $normal($db->query('SELECT id, email, first_name, last_name, phone, profession_id, account_status, account_active_until, member_code, '
            . 'avatar_url FROM users ORDER BY id')->fetchAll(), ['account_active_until'], ['avatar_url']),
        'profesii' => $normal($db->query('SELECT * FROM professions ORDER BY id')->fetchAll(), ['created_at', 'updated_at']),
        'campuri' => $normal($db->query('SELECT * FROM profession_fields ORDER BY id')->fetchAll(), ['created_at']),
        'setari' => $db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'donations\\_%' OR `key` LIKE 'account\\_%' OR `key` LIKE 'restricted\\_%'
            OR `key` IN ('header_account_label', 'email_brand_name', 'email_logo_url') ORDER BY `key`")->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
};
/** Perioadele cotizației, exacte (aceeași zi pe ambele coduri). */
$perioade = static function (string $parte) use ($conectare, $baze): array {
    $db = $conectare($baze[$parte]);
    return [
        'conturi' => $db->query('SELECT id, DATE(account_active_until) AS pana FROM users WHERE account_active_until IS NOT NULL ORDER BY id')->fetchAll(),
        'activari' => $db->query('SELECT id, DATE(period_start) AS de, DATE(period_end) AS pana FROM account_activations WHERE period_end IS NOT NULL ORDER BY id')->fetchAll(),
    ];
};

$repoVt = (string) (getenv('CMS_DEV_REPO_VT') ?: dirname($radacina) . '/vertij-tinitus-refacut');
$commitVt = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($radacina . '/tools/dev/de-portat.php') . ' --revizuit=vertij'));
$codVechi = $dosarTest . '/cod-vt';
$dosareStripe = ['vechi' => $dosarTest . '/stripe-vechi', 'nou' => $dosarTest . '/stripe-nou'];

/** Cererile primite de un Stripe fals de la ultima citire: metoda, calea, corpul (referințele donațiilor scoase), antetele importante. */
$cititeStripe = ['vechi' => 0, 'nou' => 0];
$cereriStripe = static function (string $parte) use ($dosareStripe, &$cititeStripe): array {
    $linii = @file($dosareStripe[$parte] . '/cereri.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
    $noi = array_slice($linii, $cititeStripe[$parte]);
    $cititeStripe[$parte] = count($linii);
    $out = [];
    foreach ($noi as $linie) {
        $c = json_decode($linie, true);
        if (!is_array($c)) {
            continue;
        }
        $out[] = [
            'cerere' => $c['metoda'] . ' ' . $c['cale'] . ($c['query'] !== '' ? '?' . $c['query'] : ''),
            // Referințele aleatoare ale donațiilor și portul fiecărui server (APP_URL) în adresele de întoarcere.
            'corp' => (string) preg_replace(['/DON-[0-9A-F]{8}/', '#http://127\.0\.0\.1:\d+#'], ['DON-XXXXXXXX', 'http://SITE'], urldecode((string) $c['corp'])),
            'autorizare' => (string) ($c['antete']['authorization'] ?? ''),
            'idempotenta' => (string) ($c['antete']['idempotency-key'] ?? ''),
        ];
    }
    return $out;
};
/** Unde diferă două texte: câte 250 de caractere de la locul diferenței, pe un rând. */
$undeDifera = static function (string $a, string $b): string {
    $i = strspn($a ^ $b, "\0");
    $arata = static fn (string $t): string => str_replace(["\r", "\n"], ['', '⏎'], substr($t, max(0, $i - 80), 330));
    return 'de la octetul ' . $i . ': «' . $arata($a) . '» ≠ «' . $arata($b) . '»';
};
/** Doar rândurile care diferă între două stări (tabel => [[vechi, nou], …]), pentru mesajele scurte ale verificărilor. */
$diferenteRanduri = static function (array $sv, array $sn): array {
    $out = [];
    foreach ($sv as $tabel => $randuri) {
        $alte = $sn[$tabel] ?? [];
        if ($randuri === $alte) {
            continue;
        }
        if (!is_array($randuri) || !is_array($alte) || !array_is_list($randuri)) {
            $out[$tabel] = [$randuri, $alte];
            continue;
        }
        for ($i = 0; $i < max(count($randuri), count($alte)); $i++) {
            if (($randuri[$i] ?? null) !== ($alte[$i] ?? null)) {
                $a = $randuri[$i] ?? null;
                $b = $alte[$i] ?? null;
                $out[$tabel][] = is_array($a) && is_array($b) ? ['id' => $a['id'] ?? null, 'vechi' => array_diff_assoc($a, $b), 'nou' => array_diff_assoc($b, $a)] : [$a, $b];
            }
        }
    }
    return $out;
};
$faraIdempotenta = static fn (array $cereri): array => array_map(static fn (array $c): array => array_diff_key($c, ['idempotenta' => 1]), $cereri);
/** Emailurile capturate de la ultima citire: destinatarul și subiectul, în ordine. */
$cititeMail = ['vechi' => [], 'nou' => []];
$emailuri = static function (string $parte) use ($dosarTest, &$cititeMail): array {
    $fisiere = glob($dosarTest . '/mail-' . $parte . '/*.eml') ?: [];
    sort($fisiere);
    $out = [];
    foreach ($fisiere as $f) {
        if (isset($cititeMail[$parte][$f])) {
            continue;
        }
        $cititeMail[$parte][$f] = true;
        $text = (string) file_get_contents($f);
        $antet = substr($text, 0, (int) strpos($text . "\r\n\r\n", "\r\n\r\n"));
        $antet = (string) preg_replace("/\r?\n[ \t]+/", ' ', $antet);
        $catre = preg_match('/^To: (.*)$/mi', $antet, $m) === 1 ? trim($m[1]) : '';
        $subiect = preg_match('/^Subject: (.*)$/mi', $antet, $m) === 1 ? trim((string) iconv_mime_decode($m[1], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')) : '';
        $out[] = $catre . ' | ' . $subiect;
    }
    sort($out);
    return $out;
};

try {
    if (preg_match('/^cms_[a-z0-9_]+$/', $bazaDev) !== 1 || (int) $conectare($bazaDev)->query('SELECT COUNT(*) FROM users')->fetchColumn() < 5) {
        throw new RuntimeException('baza de dezvoltare (DB_DATABASE) lipsește sau e goală: rulează întâi tools/dev/instaleaza.sh');
    }
    if ($parolaAdmin === '' || preg_match('/^[0-9a-f]{7,40}$/', $commitVt) !== 1 || !is_dir($repoVt)) {
        throw new RuntimeException('ADMIN_DEFAULT_PASSWORD (tools/dev/php.sh), clona vertij (CMS_DEV_REPO_VT) sau commit-ul ei revizuit lipsește');
    }
    mkdir($codVechi, 0770, true);
    exec('git -C ' . escapeshellarg($repoVt) . ' archive ' . escapeshellarg($commitVt) . ' | tar -x -C ' . escapeshellarg($codVechi), $o, $cod);
    foreach (['storage/sessions', 'storage/cache/pages', 'storage/logs', 'public/uploads/gallery'] as $d) {
        @mkdir($codVechi . '/' . $d, 0770, true);
    }
    // Singura schimbare a copiei codului vechi: adresa API-ului Stripe, din mediu (un Stripe fals), ca la codul comun.
    $gw = $codVechi . '/src/Support/StripeGateway.php';
    $textGw = (string) file_get_contents($gw);
    $textGwNou = str_replace('$url = self::API_BASE . $path;', '$url = (getenv(\'STRIPE_API_BASE\') ?: self::API_BASE) . $path;', $textGw);
    file_put_contents($gw, $textGwNou);
    $scrie('');
    $scrie('Pregătirea');
    $verifica('codul vertij @' . $commitVt . ' scos din ' . basename($repoVt) . '; adresa Stripe din mediu (singura schimbare)', $cod === 0
        && $textGwNou !== $textGw && is_file($codVechi . '/src/Support/ProfessionAccounts.php'));

    exec('mkdir -p ' . escapeshellarg($pachetCopie) . ' ' . escapeshellarg($dosarLicenta) . ' && cp -a ' . escapeshellarg($radacina . '/site-uri/vertij/.')
        . ' ' . escapeshellarg($pachetCopie) . ' && rm -rf ' . escapeshellarg($pachetCopie . '/privat'));
    // Înghețarea vertij e provizorie până la raportul „live față de repo” (pe server ar merge doar cu --permite-provizoriu pe copia
    // de verificare): aici, în copia testului, fără câmpul „provizoriu” (aceleași setări), ca în test-calcule-bv.php.
    $inghetare = json_decode((string) file_get_contents($pachetCopie . '/setari-inghetate.json'), true);
    unset($inghetare['provizoriu']);
    $textInghetare = json_encode($inghetare, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($pachetCopie . '/setari-inghetate.json', $textInghetare);
    $privat = ['site' => $inghetare['site'], 'sursa' => $inghetare['sursa'], 'pentru' => hash('sha256', str_replace("\r\n", "\n", $textInghetare)), 'setari' => []];
    foreach ((array) $inghetare['setari'] as $cheie => $intrare) {
        if (($intrare['privat'] ?? false) === true) {
            $privat['setari'][$cheie] = str_contains((string) $cheie, 'telefon') ? '0700 000 009' : 'test.' . $cheie . '@exemplu.ro';
        }
    }
    mkdir($pachetCopie . '/privat', 0700, true);
    file_put_contents($pachetCopie . '/privat/setari-inghetate.json', (string) json_encode($privat));

    // ── Stripe-urile false, cu aceleași răspunsuri ──
    $raspunsuri = [
        'POST /v1/payment_intents' => ['cod' => 200, 'corp' => ['id' => 'pi_nou_1', 'object' => 'payment_intent', 'client_secret' => 'pi_nou_1_secret_proba']],
        'POST /v1/customers' => ['cod' => 200, 'corp' => ['id' => 'cus_proba_1', 'object' => 'customer']],
        'POST /v1/products' => ['cod' => 200, 'corp' => ['id' => 'prod_proba_1', 'object' => 'product']],
        'POST /v1/subscriptions' => ['cod' => 200, 'corp' => ['id' => 'sub_nou_1', 'object' => 'subscription', 'latest_invoice' => ['id' => 'in_nou_1',
            'confirmation_secret' => ['client_secret' => 'pi_sub_nou_secret_proba'], 'payments' => ['data' => [['payment' => ['payment_intent' => 'pi_sub_nou']]]]]]],
        'GET /v1/payment_intents/pi_unic_1' => ['cod' => 200, 'corp' => ['id' => 'pi_unic_1', 'latest_charge' => ['receipt_url' => 'https://pay.stripe.com/receipts/proba-unic']]],
        'GET /v1/payment_intents/pi_activare_1' => ['cod' => 200, 'corp' => ['id' => 'pi_activare_1', 'latest_charge' => ['receipt_url' => 'https://pay.stripe.com/receipts/proba-activare']]],
        'POST /v1/checkout/sessions' => ['cod' => 200, 'corp' => ['id' => 'cs_proba_nou', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_proba_nou',
            'payment_intent' => null]],
        'DELETE /v1/subscriptions/sub_lunar_1' => ['cod' => 200, 'corp' => ['id' => 'sub_lunar_1', 'status' => 'canceled']],
    ];
    foreach ($parti as $parte) {
        mkdir($dosareStripe[$parte], 0770, true);
        mkdir($dosarTest . '/mail-' . $parte, 0770, true);
    }

    // ── Cele două baze, cu membrii și donațiile unui site în lucru ──
    $root = $conectare();
    preg_match_all('/^CREATE TABLE `([^`]+)`.*?;$/ms', (string) file_get_contents($radacina . '/tools/dev/migrari/vechi/vt.sql'), $creari, PREG_SET_ORDER);
    $dev = $conectare($bazaDev);
    $coloane = static function (PDO $db, string $tabel): array {
        $s = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$tabel]);
        return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
    };
    $tabelePlugin = ['donations', 'professions', 'profession_fields', 'account_verifications', 'account_verification_documents', 'account_activations'];
    $maine = date('Y-m-d H:i:s', time() + 200 * 86400);
    $ieri = date('Y-m-d H:i:s', time() - 86400);
    $hashParola = password_hash($parolaMembri, PASSWORD_BCRYPT);
    foreach ($baze as $parte => $numeBaza) {
        $root->exec('CREATE DATABASE ' . $numeBaza . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db = $conectare($numeBaza);
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($creari as [$sql]) {
            $db->exec($sql);
        }
        foreach ($creari as [, $tabel]) {
            $comune = array_values(array_intersect($coloane($db, $tabel), $coloane($dev, $tabel)));
            if ($comune === [] || $tabel === 'customer_email_confirmations' || in_array($tabel, $tabelePlugin, true)) {
                continue;
            }
            $lista = '`' . implode('`, `', $comune) . '`';
            $db->exec('INSERT INTO `' . $tabel . '` (' . $lista . ') SELECT ' . $lista . ' FROM `' . $bazaDev . '`.`' . $tabel . '`');
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        // Bugetele vizitatorului (înregistrările, retrimiterile) pornesc goale: rândurile bazei de dezvoltare sunt ale altor teste,
        // scrise în ora României, deci pe codul vertij (fără fus pe conexiune) ar părea din ultimele minute.
        $db->exec('DELETE FROM register_submit_logs');
        // Pagina contului din codul fiecăruia (fără pagina „contul-meu” din bază) și fără cache de pagini.
        $db->exec("DELETE FROM pages WHERE slug IN ('contul-meu', 'login', 'register')");
        $setare = $db->prepare('REPLACE INTO settings (`key`, `value`) VALUES (?, ?)');
        foreach (['stripe_enabled' => '1', 'stripe_secret_key' => 'sk_test_proba', 'stripe_publishable_key' => 'pk_test_proba',
            'stripe_webhook_secret' => $secretWebhook, 'stripe_currency' => 'ron', 'donations_enabled' => '1', 'page_cache_enabled' => '0',
            'account_verification_notify_email' => 'cereri@exemplu.ro', 'customer_registration_field_first_name' => '1',
            'customer_registration_field_last_name' => '1', 'customer_registration_field_phone' => '1',
            // Înghețată „1” pe vertij (WP-25, OD-19); codul vertij n-o citește.
            'customer_email_confirmation_required' => '1'] as $k => $v) {
            $setare->execute([$k, $v]);
        }
        $db->exec("INSERT INTO professions (id, name, slug, description, activation_price, activation_months, is_active, sort_order) VALUES
            (1, 'Medic de proba', 'medic-de-proba', 'Medici specialiști (inventat).', 120.00, 12, 1, 1),
            (2, 'Asistent de proba', 'asistent-de-proba', NULL, 60.00, 12, 1, 2),
            (3, 'Categorie inactiva', 'categorie-inactiva', NULL, 10.00, 12, 0, 3)");
        $db->exec("INSERT INTO profession_fields (id, profession_id, field_key, label, field_type, options_json, help_text, is_required, is_active, sort_order) VALUES
            (1, 1, 'specialitate', 'Specialitate', 'select', '[\"ORL\",\"Neurologie\"]', NULL, 1, 1, 1),
            (2, 1, 'cod_parafa', 'Cod parafă', 'text', NULL, 'Codul din parafă', 1, 1, 2),
            (3, 1, 'diploma', 'Diploma', 'file', NULL, NULL, 1, 1, 3),
            (4, 1, 'acord_cod', 'Accept codul deontologic', 'checkbox', NULL, NULL, 1, 1, 4),
            (5, 2, 'unitate', 'Unitatea sanitară', 'text', NULL, NULL, 0, 1, 1)");
        // Conturile de membru: aprobat neactivat (2), în verificare (3), respins (4), activ (5), fără cerere (6; o „cerere nouă” din
        // cont, născută confirmată, fără link), respins cât aștepta confirmarea pe email (7: fără confirmed_at).
        // Momentele TIMESTAMP de mai jos sunt în UTC (conexiunea testului), cele DATETIME în ora României, ca pe un server în UTC.
        $db->exec("SET time_zone = '+00:00'");
        $u = $db->query('SELECT id FROM users ORDER BY id LIMIT 7')->fetchAll(PDO::FETCH_COLUMN);
        [$uAprobat, $uVerificare, $uRespins, $uActiv, $uFara, $uFaraLink] = array_map('intval', array_slice($u, 1, 6));
        $db->prepare('UPDATE users SET password_hash = ? WHERE id IN (' . implode(',', array_map('intval', $u)) . ')')->execute([$hashParola]);
        $st = $db->prepare('UPDATE users SET profession_id = ?, account_status = ?, account_active_until = ?, member_code = ? WHERE id = ?');
        $st->execute([1, 'approved', null, 'SVTH-' . str_pad((string) $uAprobat, 5, '0', STR_PAD_LEFT), $uAprobat]);
        $st->execute([1, 'pending', null, 'SVTH-' . str_pad((string) $uVerificare, 5, '0', STR_PAD_LEFT), $uVerificare]);
        $st->execute([2, 'rejected', null, 'SVTH-' . str_pad((string) $uRespins, 5, '0', STR_PAD_LEFT), $uRespins]);
        $st->execute([1, 'approved', $maine, 'SVTH-' . str_pad((string) $uActiv, 5, '0', STR_PAD_LEFT), $uActiv]);
        $cv = $db->prepare('INSERT INTO account_verifications (id, user_id, profession_id, profession_name, status, answers_json, gdpr_accepted, gdpr_accepted_at,
            confirm_token_hash, confirmed_at, review_note, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, NULL, ?, ?, ?)');
        $raspuns = json_encode(['specialitate' => ['label' => 'Specialitate', 'type' => 'select', 'value' => 'ORL']], JSON_UNESCAPED_UNICODE);
        $cv->execute([1, $uAprobat, 1, 'Medic de proba', 'approved', $raspuns, '2026-09-01 10:00:00', '2026-09-01 10:05:00', null, '2026-09-01 10:00:00']);
        $cv->execute([2, $uVerificare, 1, 'Medic de proba', 'pending', $raspuns, '2026-09-02 10:00:00', '2026-09-02 10:05:00', null, '2026-09-02 10:00:00']);
        $cv->execute([3, $uRespins, 2, 'Asistent de proba', 'rejected', '{}', '2026-09-03 10:00:00', '2026-09-03 10:05:00', "Diploma nu se citește.\nTrimite alt scan.", '2026-09-03 10:00:00']);
        $cv->execute([4, $uActiv, 1, 'Medic de proba', 'approved', $raspuns, '2026-09-04 10:00:00', '2026-09-04 10:05:00', null, '2026-09-04 10:00:00']);
        // Cererea nouă a codului vertij: confirmed_at scris de PHP (ora României) la crearea rândului (07:00 UTC = 10:00 în septembrie).
        $cv->execute([5, $uFara, 2, 'Asistent de proba', 'pending', '{}', '2026-09-10 10:00:00', '2026-09-10 10:00:00', null, '2026-09-10 07:00:00']);
        $cv->execute([6, $uFaraLink, 2, 'Asistent de proba', 'rejected', '{}', '2026-09-11 10:00:00', null, 'Fără confirmarea adresei.', '2026-09-11 10:00:00']);
        $db->exec("INSERT INTO account_verification_documents (id, verification_id, field_key, field_label, original_name, stored_name, mime_type, file_size)
            VALUES (1, 2, 'diploma', 'Diploma', 'diplomă proba.pdf', '20260902-proba0000000000000000000000000001.pdf', 'application/pdf', 25)");
        $ca = $db->prepare('INSERT INTO account_activations (id, user_id, profession_id, profession_name, amount, currency, status, months, stripe_session_id,
            stripe_payment_intent_id, period_start, period_end, paid_at, payment_error, receipt_url) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ca->execute([1, $uAprobat, 'Medic de proba', 120.00, 'ron', 'failed', 12, 'cs_esuat_1', null, null, null, null, 'Plata nu a fost finalizată sau sesiunea a expirat.', null]);
        $ca->execute([2, $uActiv, 'Medic de proba', 120.00, 'ron', 'paid', 12, 'cs_platit_1', 'pi_vechi_1', '2026-05-01 00:00:00', $maine, '2026-05-01 10:00:00', null,
            'https://pay.stripe.com/receipts/proba-veche']);
        $ca->execute([3, $uAprobat, 'Medic de proba', 30.00, 'ron', 'pending', 3, 'cs_activare_1', null, null, null, null, null, null]);
        $ca->execute([4, $uAprobat, 'Medic de proba', 10.00, 'ron', 'pending', 1, 'cs_expira_1', null, null, null, null, null, null]);
        $email = static fn (int $id) => (string) $db->query('SELECT email FROM users WHERE id = ' . $id)->fetchColumn();
        $cd = $db->prepare('INSERT INTO donations (id, reference, user_id, donor_name, donor_email, amount_cents, fee_cents, currency, frequency, designation,
            cover_fees, newsletter_optin, consent, status, stripe_payment_intent_id, stripe_subscription_id, stripe_customer_id, created_at, paid_at, stripe_invoice_id,
            cancelled_at, receipt_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $cd->execute([1, 'DON-UNIC0001', null, 'Donator Unic', 'donator.unic@exemplu.ro', 10000, 0, 'ron', 'once', 'Cercetare', 0, 'pending', 'pi_unic_1', null, null,
            '2026-10-01 10:00:00', null, null, null, null]);
        $cd->execute([2, 'DON-ESUAT001', null, null, 'donator.esuat@exemplu.ro', 5000, 0, 'ron', 'once', null, 0, 'pending', 'pi_esuat_1', null, null,
            '2026-10-02 10:00:00', null, null, null, null]);
        $cd->execute([3, 'DON-LUNAR001', $uActiv, 'Membru Activ', $email($uActiv), 2577, 77, 'ron', 'monthly', 'Unde e nevoie mai mult', 1, 'paid', 'pi_lunar_1',
            'sub_lunar_1', 'cus_lunar_1', '2026-09-05 10:00:00', '2026-09-05 10:01:00', 'in_lunar_1', null, 'https://invoice.stripe.com/i/proba-1']);
        $cd->execute([4, 'DON-EMAIL001', null, 'Fără Cont', $email($uAprobat), 2500, 0, 'ron', 'once', null, 0, 'paid', 'pi_email_1', null, null,
            '2026-08-01 10:00:00', '2026-08-01 10:01:00', null, null, null]);
        $cd->execute([5, 'DON-LUNAR002', null, 'Donator Lunar', 'donator.lunar@exemplu.ro', 5000, 0, 'ron', 'monthly', null, 0, 'paid', 'pi_lunar_2',
            'sub_lunar_2', 'cus_lunar_2', '2026-09-06 10:00:00', '2026-09-06 10:01:00', 'in_lunar_2_1', null, null]);
        // O pagină doar pentru membri.
        $db->exec("INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published, requires_active_account)
            VALUES ('Ghid pentru medici', 'ghid-medici-proba', '<p>Conținut doar pentru membri (inventat).</p>', '', '', 1, 1)");
    }
    // Documentul cererii 2 pe discul fiecărui cod (storage/account-documents: azi aceeași cale în ambele).
    foreach ([$codVechi, $radacina] as $codul) {
        @mkdir($codul . '/storage/account-documents', 0770, true);
        file_put_contents($codul . '/storage/account-documents/20260902-proba0000000000000000000000000001.pdf', "%PDF-1.4\n%proba inventata\n");
    }
    $verifica('bazele cms_dm_vechi și cms_dm_nou: structura codului vertij, rândurile demo, 3 categorii (una inactivă) cu 5 câmpuri, cereri în fiecare '
        . 'stare, un document, plăți ale cotizației (eșuată, plătită, două în curs), 5 donații (unică în curs, eșuată, două lunare, una fără cont cu '
        . 'adresa unui membru), o pagină doar pentru membri', true);

    // ── A: adoptarea ──
    $scrie('');
    $scrie('A. Adoptarea: migrările nu ating tabelele și coloanele pluginurilor');
    // Coloanele rambursărilor (donatii/0064, membri/0065), noi în codul comun, la coada tabelelor: scoase aici, verificate mai jos.
    $instantaneu = static function (PDO $db) use ($tabelePlugin, $faraColoaneNoi): array {
        $r = [];
        foreach ($tabelePlugin as $t) {
            $creare = preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) ($db->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1] ?? ''));
            $r[$t] = [preg_replace('/\n  `refunded_(cents|at)` [^\n]*/', '', (string) $creare), $faraColoaneNoi($db->query('SELECT * FROM `' . $t . '` ORDER BY id')->fetchAll())];
        }
        $r['users'] = [$db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('profession_id','account_status','account_active_until','member_code','avatar_url','stripe_customer_id')
            ORDER BY COLUMN_NAME")->fetchAll(), $db->query('SELECT id, profession_id, account_status, account_active_until, member_code, avatar_url, stripe_customer_id
            FROM users ORDER BY id')->fetchAll(), $db->query("SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users' AND COLUMN_NAME = 'member_code' ORDER BY INDEX_NAME")->fetchAll()];
        return $r;
    };
    $dbNou = $conectare($baze['nou']);
    $inainte = $instantaneu($dbNou);
    // Proba de dinainte (revizuirea din 9 nov 2026): planul spune câte conturi primesc adresa dovedită și câte cereri rămân deoparte.
    $plan = $php('nou', 'scripts/migreaza.php', ['--plan', '--site=vertij']);
    $verifica('migreaza.php --plan --site=vertij: site/vertij/0001 spune dinainte „4 conturi primesc adresa dovedită”, „1 cereri … lăsate deoparte”',
        str_contains($plan['tot'], '4 conturi primesc adresa dovedită (users.email_confirmed_at') && str_contains($plan['tot'], '1 cereri cu confirmed_at lăsate deoparte'),
        mb_substr($plan['tot'], -2500));
    $r = $php('nou', 'scripts/migreaza.php', ['--aplica', '--site=vertij']);
    $verifica('scripts/migreaza.php --aplica --site=vertij: cod 0', $r['cod'] === 0, mb_substr($r['tot'], -2500));
    $dupa = $instantaneu($dbNou);
    foreach (array_keys($inainte) as $t) {
        $verifica('A ' . $t . ': aceeași structură și aceleași ' . count($inainte[$t][1]) . ' rânduri după migrări', $inainte[$t] === $dupa[$t],
            json_encode([$inainte[$t][0], $dupa[$t][0]], JSON_UNESCAPED_UNICODE) ?: '');
    }
    foreach (['donatii/0062_donatii', 'membri/0063_membri'] as $idMigrare) {
        $rand = $dbNou->query("SELECT rezultat, operatii FROM _platforma_migrari WHERE id = " . $dbNou->quote($idMigrare))->fetch();
        $verifica('A ' . $idMigrare . ' „detectata”, 0 operații', is_array($rand) && $rand['rezultat'] === 'detectata' && (int) $rand['operatii'] === 0,
            json_encode($rand) ?: 'lipsă');
    }
    // Rambursările (codul comun): două coloane la coada fiecărui tabel, cu implicitele care dau rândurile de azi.
    foreach (['donatii/0064_donatii_rambursari' => 'donations', 'membri/0065_membri_rambursari' => 'account_activations'] as $idMigrare => $tabelR) {
        $rand = $dbNou->query("SELECT rezultat, operatii FROM _platforma_migrari WHERE id = " . $dbNou->quote($idMigrare))->fetch();
        $col = $dbNou->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '" . $tabelR . "' AND COLUMN_NAME LIKE 'refunded\\_%' ORDER BY ORDINAL_POSITION")->fetchAll();
        $nenule = (int) $dbNou->query('SELECT COUNT(*) FROM ' . $tabelR . ' WHERE refunded_cents <> 0 OR refunded_at IS NOT NULL')->fetchColumn();
        $verifica('A ' . $idMigrare . ': „aplicata”, 2 operații, ' . $tabelR . '.refunded_cents (0) și refunded_at (NULL) la coadă, niciun rând atins',
            is_array($rand) && $rand['rezultat'] === 'aplicata' && (int) $rand['operatii'] === 2 && count($col) === 2 && $nenule === 0
            && ($col[0]['COLUMN_NAME'] ?? '') === 'refunded_cents' && in_array((string) ($col[0]['COLUMN_DEFAULT'] ?? ''), ['0', "'0'"], true),
            json_encode([$rand, $col, $nenule]) ?: '');
    }
    // Pasul site/vertij/0001: adresa membrilor cu cererea confirmată pe codul vertij devine dovedită (o singură dată).
    $confirmati = $dbNou->query("SELECT u.id FROM users u WHERE u.email_confirmed_at IS NOT NULL AND EXISTS (SELECT 1 FROM account_verifications v
        WHERE v.user_id = u.id) ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN);
    $peDataCererii = (string) $dbNou->query('SELECT email_confirmed_at FROM users WHERE id = ' . (int) $uAprobat)->fetchColumn();
    $randPas = $dbNou->query("SELECT rezultat, operatii FROM _platforma_migrari WHERE id = 'site/vertij/0001_adrese_membri_confirmate'")->fetch();
    $verifica('A site/vertij/0001: doar cele patru conturi cu cererea confirmată cu linkul pe codul vertij au users.email_confirmed_at (momentul '
        . 'confirmării lor); „cererea nouă” născută confirmată și cererea decisă fără confirmare rămân neconfirmate',
        array_map('intval', $confirmati) === array_map('intval', [$uAprobat, $uVerificare, $uRespins, $uActiv]) && $peDataCererii === '2026-09-01 10:05:00'
        && is_array($randPas) && $randPas['rezultat'] === 'aplicata', json_encode([$confirmati, $peDataCererii, $randPas]) ?: '');
    $r2 = $php('nou', 'scripts/migreaza.php', ['--aplica', '--site=vertij']);
    $verifica('A migreaza.php --aplica --site=vertij a doua oară: cod 0, nimic de făcut, aceleași conturi confirmate', $r2['cod'] === 0
        && $dbNou->query("SELECT u.id FROM users u WHERE u.email_confirmed_at IS NOT NULL AND EXISTS (SELECT 1 FROM account_verifications v
        WHERE v.user_id = u.id) ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN) === $confirmati, mb_substr($r2['tot'], -1500));
    $r = $php('nou', 'tools/dev/licenta.php', ['vertij']);
    $verifica('licența de dezvoltare a vertij (conturi, Stripe, donații, membri, blog, newsletter)', $r['cod'] === 0, $r['tot']);
    // Înghețarea scrie doar cheile lipsă; codul vechi le ia din implicitele lui: aceleași valori pe ambele.
    $sv = $starea('vechi');
    $sn = $starea('nou');

    // ── Serverele ──
    file_put_contents($dosareStripe['vechi'] . '/raspunsuri.json', (string) json_encode($raspunsuri));
    file_put_contents($dosareStripe['nou'] . '/raspunsuri.json', (string) json_encode($raspunsuri));
    $porneste = static function (array $comanda, string $cwd, array $env, int $port) use (&$servere, $dosarTest): bool {
        $jurnal = $dosarTest . '/server-' . $port . '.log';
        $p = proc_open($comanda, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $jurnal, 'a'], 2 => ['file', $jurnal, 'a']], $tevi, $cwd, $env);
        $servere[] = (int) proc_get_status($p)['pid'];
        for ($i = 0; $i < 100; $i++) {
            $c = curl_init('http://127.0.0.1:' . $port . '/robots.txt');
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
            curl_exec($c);
            $raspuns = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            curl_close($c);
            if ($raspuns > 0) {
                return true;
            }
            usleep(100000);
        }
        return false;
    };
    $pornite = true;
    foreach ($parti as $parte) {
        $pornite = $pornite && $porneste([PHP_BINARY, '-S', '127.0.0.1:' . $porturiStripe[$parte], $radacina . '/tools/dev/lib/stripe-fals.php'], $radacina,
            array_merge($mediu($parte), ['STRIPE_FALS_DOSAR' => $dosareStripe[$parte]]), $porturiStripe[$parte]);
    }
    $pornite = $pornite && $porneste(array_merge([PHP_BINARY], $ini, ['-S', '127.0.0.1:' . $porturi['vechi'], '-t', $codVechi . '/public', $radacina . '/tools/dev/router.php']),
        $codVechi, $mediu('vechi', ['APP_URL' => 'http://127.0.0.1:' . $porturi['vechi'], 'PHP_CLI_SERVER_WORKERS' => '4']), $porturi['vechi'])
        && $porneste(array_merge([PHP_BINARY], $ini, ['-S', '127.0.0.1:' . $porturi['nou'], '-t', $radacina . '/public', $radacina . '/tools/dev/router.php']),
        $radacina, $mediu('nou', ['APP_URL' => 'http://127.0.0.1:' . $porturi['nou'], 'PHP_CLI_SERVER_WORKERS' => '4']), $porturi['nou']);
    $verifica('serverele pornesc: codul vertij (+52), codul comun (+53) și cele două Stripe-uri false (+57, +58)', $pornite);
    if (!$pornite) {
        throw new RuntimeException('serverele nu pornesc (jurnalele în ' . $dosarTest . ')');
    }

    /** O sesiune pe un cod: cereri cu cookie-uri; jetonul formularului citit din pagini (doar pe codul comun). */
    $sesiune = static function (string $parte) use ($porturi, $dosarTest): Closure {
        $borcan = $dosarTest . '/cookies-' . $parte . '-' . bin2hex(random_bytes(6)) . '.txt';
        $jeton = '';
        return static function (string $metoda, string $cale, array $campuri = [], ?string $json = null, array $antete = [], bool $multipart = false)
            use ($porturi, $parte, $borcan, &$jeton): array {
            $h = curl_init('http://127.0.0.1:' . $porturi[$parte] . $cale);
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEJAR => $borcan,
                CURLOPT_COOKIEFILE => $borcan, CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_HEADER => true]);
            if ($json !== null) {
                curl_setopt($h, CURLOPT_POSTFIELDS, $json);
                $antete[] = 'Content-Type: application/json';
            } elseif ($metoda === 'POST') {
                curl_setopt($h, CURLOPT_POSTFIELDS, $multipart ? $campuri : http_build_query($campuri));
            }
            curl_setopt($h, CURLOPT_HTTPHEADER, $antete);
            $tot = (string) curl_exec($h);
            $cod = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $marime = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
            curl_close($h);
            $antet = substr($tot, 0, $marime);
            $corp = substr($tot, $marime);
            if (preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $corp, $m) === 1) {
                $jeton = $m[1];
            }
            return ['cod' => $cod, 'corp' => $corp, 'antet' => $antet, 'tip' => preg_match('/^Content-Type: (.+)$/mi', $antet, $m) === 1 ? trim($m[1]) : '',
                'location' => preg_match('/^Location: (.+)$/mi', $antet, $m) === 1 ? (string) preg_replace('#^https?://127\.0\.0\.1:\d+#', '', trim($m[1])) : '',
                'jeton' => $jeton];
        };
    };
    $adminPe = static function (string $parte, string $email = 'admin@exemplu.ro') use ($sesiune, $parolaAdmin): Closure {
        $c = $sesiune($parte);
        $c('GET', '/admin/login');
        $c('POST', '/admin/login', ['email' => $email, 'password' => $parolaAdmin]);
        return $c;
    };
    $clientPe = static function (string $parte, string $email) use ($sesiune, $parolaMembri): Closure {
        $c = $sesiune($parte);
        $c('GET', '/login');
        $c('POST', '/login', ['email' => $email, 'password' => $parolaMembri]);
        return $c;
    };
    $faraJeton = static fn (string $html): string => (string) preg_replace('/\n?[ \t]*<input type="hidden" name="_csrf" value="[0-9a-f]{64}">/', '', $html);
    $bucata = static function (string $html, string $de, string $pana, bool $cuPana = true): string {
        $i = strpos($html, $de);
        if ($i === false) {
            return '';
        }
        $j = strpos($html, $pana, $i + strlen($de));
        return $j === false ? '' : substr($html, $i, $j - $i + ($cuPana ? strlen($pana) : 0));
    };
    $comparaStarea = static function (string $eticheta, array $declarat = []) use ($starea, $verifica, $diferenteRanduri): void {
        $sv = $starea('vechi');
        $sn = $starea('nou');
        // Setările: înghețarea a scris pe codul comun, la adoptare, cheile pe care codul vertij le ia din implicitele lui; se
        // compară cheile scrise în baza vertij (de cererile de până acum), cu valorile lor de pe codul comun.
        $sn['setari'] = array_intersect_key($sn['setari'], $sv['setari']);
        foreach ($declarat as $cheie => $f) {
            [$sv[$cheie], $sn[$cheie]] = $f($sv[$cheie], $sn[$cheie]);
        }
        $dif = [];
        foreach ($sv as $k => $v) {
            if ($v !== $sn[$k]) {
                $dif[$k] = [$v, $sn[$k]];
            }
        }
        $verifica($eticheta . ': aceleași rânduri (donații, cereri, documente, plăți, conturi, categorii, setări)', $dif === [],
            json_encode($diferenteRanduri($sv, $sn), JSON_UNESCAPED_UNICODE) ?: '');
    };
    $verifica('A înghețarea: setările donațiilor și ale membrilor au pe codul comun valorile codului vertij (scrise doar unde lipseau)', true);

    // ── B: webhook-ul Stripe ──
    $scrie('');
    $scrie('B. Webhook-ul Stripe: aceleași răspunsuri, rânduri, cereri spre Stripe și emailuri');
    $webhook = static function (string $parte, array $eveniment, ?string $semnatura = null) use ($porturi, $secretWebhook): array {
        $corp = (string) json_encode($eveniment, JSON_UNESCAPED_SLASHES);
        $t = time();
        $sig = $semnatura ?? ('t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $corp, $secretWebhook));
        $h = curl_init('http://127.0.0.1:' . $porturi[$parte] . '/webhook/stripe');
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corp, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Stripe-Signature: ' . $sig]]);
        $raspuns = (string) curl_exec($h);
        $cod = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        return ['cod' => $cod, 'corp' => $raspuns];
    };
    $nrEv = 0;
    $eveniment = static function (string $tip, array $obiect) use (&$nrEv): array {
        $nrEv++;
        return ['id' => 'evt_proba_' . $nrEv, 'object' => 'event', 'type' => $tip, 'api_version' => '2025-03-31.basil', 'livemode' => false,
            'data' => ['object' => $obiect]];
    };
    $pasWebhook = static function (string $eticheta, array $ev, ?array $declaratCorp = null, ?string $semnatura = null) use ($webhook, $parti, $starea, $cereriStripe,
        $emailuri, $verifica, $diferenteRanduri): void {
        $raspunsuri = [];
        foreach ($parti as $parte) {
            $raspunsuri[$parte] = $webhook($parte, $ev, $semnatura);
        }
        $stripeV = $cereriStripe('vechi');
        $stripeN = $cereriStripe('nou');
        $mailV = $emailuri('vechi');
        $mailN = $emailuri('nou');
        $sv = $starea('vechi');
        $sn = $starea('nou');
        $sn['setari'] = array_intersect_key($sn['setari'], $sv['setari']);
        $corpOk = $declaratCorp === null ? $raspunsuri['vechi'] === $raspunsuri['nou']
            : ($raspunsuri['vechi'] === $declaratCorp['vechi'] && $raspunsuri['nou'] === $declaratCorp['nou']);
        $verifica('B ' . $eticheta . ' → ' . $raspunsuri['nou']['cod'] . ' ' . $raspunsuri['nou']['corp'] . '; aceleași rânduri, ' . count($stripeN)
            . ' cereri spre Stripe, ' . count($mailN) . ' emailuri', $corpOk && $sv === $sn && $stripeV === $stripeN && $mailV === $mailN,
            json_encode(['raspuns' => $raspunsuri, 'stripe' => $stripeV === $stripeN ? count($stripeN) : [$stripeV, $stripeN],
                'mail' => [$mailV, $mailN], 'randuri' => $sv === $sn ? '=' : $diferenteRanduri($sv, $sn)],
                JSON_UNESCAPED_UNICODE) ?: '');
    };
    $evPlatit = $eveniment('payment_intent.succeeded', ['id' => 'pi_unic_1', 'object' => 'payment_intent', 'metadata' => ['type' => 'donation', 'donation_id' => '1']]);
    $pasWebhook('donația unică plătită (payment_intent.succeeded): „paid”, chitanța cerută la Stripe, emailul de mulțumire', $evPlatit);
    $pasWebhook('același eveniment retrimis de Stripe: nimic schimbat, niciun email (codul comun răspunde „duplicate”, dispecerul WP-25)', $evPlatit,
        ['vechi' => ['cod' => 200, 'corp' => 'ok'], 'nou' => ['cod' => 200, 'corp' => 'duplicate']]);
    $pasWebhook('donația plătită, alt eveniment pentru aceeași plată: nimic nou', $eveniment('payment_intent.succeeded', ['id' => 'pi_unic_1',
        'object' => 'payment_intent', 'metadata' => ['type' => 'donation', 'donation_id' => '1']]));
    $pasWebhook('donația eșuată, găsită după plată (fără metadată): „failed”', $eveniment('payment_intent.payment_failed', ['id' => 'pi_esuat_1',
        'object' => 'payment_intent']));
    $pasWebhook('prima factură a donației lunare (aceeași factură ca la creare): nimic nou', $eveniment('invoice.paid', ['id' => 'in_lunar_1', 'object' => 'invoice',
        'subscription' => 'sub_lunar_1', 'amount_paid' => 2577]));
    $pasWebhook('luna a doua (invoice.paid, forma veche a facturii): un rând nou „paid”, emailul de mulțumire', $eveniment('invoice.paid', ['id' => 'in_lunar_1_2',
        'object' => 'invoice', 'subscription' => 'sub_lunar_1', 'amount_paid' => 2577, 'payment_intent' => 'pi_lunar_1_2',
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/proba-2']));
    $pasWebhook('aceeași lună pe invoice.payment_succeeded (alt eveniment): nicio dublură, niciun email', $eveniment('invoice.payment_succeeded', ['id' => 'in_lunar_1_2',
        'object' => 'invoice', 'subscription' => 'sub_lunar_1', 'amount_paid' => 2577]));
    // Codul vertij încearcă INSERT-ul dublurii (oprit de indexul unic pe factură, dar InnoDB consumă un id); codul comun găsește
    // întâi factura (pentru lunile neplătite, revizuirea din 9 nov 2026). Rândurile sunt aceleași; contorul id-urilor, aliniat aici.
    $aiVechi = (int) $conectare($baze['vechi'])->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donations'")
        ->fetchColumn();
    $conectare($baze['nou'])->exec('ALTER TABLE donations AUTO_INCREMENT = ' . $aiVechi);
    $pasWebhook('luna a treia (forma basil: parent.subscription_details, payments): un rând nou', $eveniment('invoice.paid', ['id' => 'in_lunar_1_3',
        'object' => 'invoice', 'parent' => ['subscription_details' => ['subscription' => 'sub_lunar_1']], 'total' => 2577,
        'payments' => ['data' => [['payment' => ['payment_intent' => 'pi_lunar_1_3']]]]]));
    $pasWebhook('o factură a unui abonament necunoscut: nimic', $eveniment('invoice.paid', ['id' => 'in_strain_1', 'object' => 'invoice',
        'subscription' => 'sub_strain_1', 'amount_paid' => 999]));
    $pasWebhook('abonamentul oprit din Stripe (customer.subscription.deleted): „cancelled_at” pe rândurile lui', $eveniment('customer.subscription.deleted',
        ['id' => 'sub_lunar_2', 'object' => 'subscription', 'metadata' => ['type' => 'donation']]));
    $pasWebhook('cotizația plătită (checkout.session.completed, paid): plata „paid”, contul activ 3 luni, chitanța, emailul „Contul tău este activ”',
        $eveniment('checkout.session.completed', ['id' => 'cs_activare_1', 'object' => 'checkout.session', 'payment_status' => 'paid',
            'payment_intent' => 'pi_activare_1', 'client_reference_id' => 'activation-3', 'metadata' => ['type' => 'account_activation', 'activation_id' => '3',
            'months' => '3']]));
    $verifica('B perioada cotizației: aceeași zi de început și de sfârșit pe ambele coduri', $perioade('vechi') === $perioade('nou'),
        json_encode([$perioade('vechi'), $perioade('nou')]) ?: '');
    $pasWebhook('aceeași cotizație, async_payment_succeeded: nimic nou (deja plătită)', $eveniment('checkout.session.async_payment_succeeded', ['id' => 'cs_activare_1',
        'object' => 'checkout.session', 'payment_intent' => 'pi_activare_1', 'metadata' => ['type' => 'account_activation', 'activation_id' => '3']]));
    $pasWebhook('o plată a cotizației cu metadata account_activation pe payment_intent: ignorată', $eveniment('payment_intent.succeeded', ['id' => 'pi_activare_1',
        'object' => 'payment_intent', 'metadata' => ['type' => 'account_activation', 'activation_id' => '3']]));
    $pasWebhook('cotizația expirată (checkout.session.expired): „failed”, cu motivul', $eveniment('checkout.session.expired', ['id' => 'cs_expira_1',
        'object' => 'checkout.session', 'payment_status' => 'unpaid', 'metadata' => ['type' => 'account_activation', 'activation_id' => '4']]));
    $pasWebhook('semnătura greșită: 400 invalid_signature, nimic schimbat', $eveniment('payment_intent.succeeded', ['id' => 'pi_esuat_1', 'object' => 'payment_intent']),
        null, 't=' . time() . ',v1=' . str_repeat('0', 64));
    $pasWebhook('un tip pe care nu-l procesează nimeni: 200 ok', $eveniment('customer.created', ['id' => 'cus_x', 'object' => 'customer']));

    // Cotizația contului aprobat a expirat ieri (pe ambele): secțiunea „Cotizație expirată”, plata și reactivarea, mai jos.
    foreach ($baze as $numeBaza) {
        $conectare($numeBaza)->prepare('UPDATE users SET account_active_until = ? WHERE id = ?')->execute([$ieri, $uAprobat]);
    }

    // ── C: site-ul ──
    $scrie('');
    $scrie('C. Site-ul: sertarul, donația, înscrierea, confirmarea, cotizația, contul');
    // Sertarul „SUSȚINE” pe prima pagină.
    $sertar = static function (string $html) use ($bucata): string {
        $s = $bucata($html, '<!-- Sertar SUSȚINE -->', '/assets/js/donation-widget.js?v=');
        return (string) preg_replace('/\s+/', ' ', $s);
    };
    $acasa = ['vechi' => $sesiune('vechi')('GET', '/'), 'nou' => $sesiune('nou')('GET', '/')];
    $verifica('C sertarul „SUSȚINE” pe prima pagină: același HTML (sertarul, configurarea, scripturile Stripe și al sertarului; spațiile normalizate)',
        $sertar($acasa['vechi']['corp']) !== '' && $sertar($acasa['vechi']['corp']) === $sertar($acasa['nou']['corp']),
        json_encode([$sertar($acasa['vechi']['corp']), $sertar($acasa['nou']['corp'])], JSON_UNESCAPED_UNICODE) ?: '');
    $verifica('C scriptul sertarului: aceiași octeți (public/assets/js/donation-widget.js)', is_file($radacina . '/public/assets/js/donation-widget.js')
        && md5_file($radacina . '/public/assets/js/donation-widget.js') === md5_file($codVechi . '/public/assets/js/donation-widget.js'));

    // Donațiile din sertar (JSON).
    $pasIntent = static function (string $eticheta, array $date, ?string $client = null, ?Closure $declarat = null) use ($sesiune, $clientPe, $parti,
        $cereriStripe, $faraIdempotenta, $starea, $verifica): array {
        $r = [];
        $stripe = [];
        foreach ($parti as $parte) {
            $c = $client !== null ? $clientPe($parte, $client) : $sesiune($parte);
            $x = $c('POST', '/api/donations/intent', [], (string) json_encode($date));
            $r[$parte] = ['cod' => $x['cod'], 'json' => (string) preg_replace('/DON-[0-9A-F]{8}/', 'DON-XXXXXXXX', $x['corp'])];
            $stripe[$parte] = $cereriStripe($parte);
        }
        $sv = $starea('vechi');
        $sn = $starea('nou');
        $sn['setari'] = array_intersect_key($sn['setari'], $sv['setari']);
        $raspunsNou = $r['nou'];
        if ($declarat !== null) {
            // O diferență voită față de codul vertij (revizuirea de securitate): verificată aici, apoi răspunsurile aduse la fel.
            [$r['vechi'], $r['nou']] = $declarat($r['vechi'], $r['nou']);
        }
        $cuCheie = array_filter($stripe['nou'], static fn (array $c): bool => str_starts_with($c['cerere'], 'POST') && $c['cerere'] !== 'POST /v1/products');
        $toateCuCheie = array_reduce($cuCheie, static fn (bool $ok, array $c): bool => $ok && str_starts_with($c['idempotenta'], 'donatie-DON-'), true);
        $verifica('C ' . $eticheta . ' → ' . $r['nou']['cod'] . ' ' . mb_substr($r['nou']['json'], 0, 160) . '; aceleași cereri spre Stripe ('
            . count($stripe['nou']) . ', pe codul comun cu Idempotency-Key), aceleași rânduri', $r['vechi'] === $r['nou']
            && $faraIdempotenta($stripe['vechi']) === $faraIdempotenta($stripe['nou']) && $toateCuCheie && $sv === $sn,
            json_encode([$r, $stripe, $sv === $sn ? '=' : [$sv['donatii'], $sn['donatii'], $sv['setari'], $sn['setari']]], JSON_UNESCAPED_UNICODE) ?: '');
        return $raspunsNou;
    };
    $pasIntent('donația unică (100 lei, comisionul acoperit, destinația, newsletter)', ['amount' => 100, 'frequency' => 'once', 'designation' => 'Cercetare',
        'cover_fees' => true, 'name' => 'Ion Proba', 'email' => 'ion.proba@exemplu.ro', 'newsletter' => true, 'consent' => true]);
    $pasIntent('donația lunară (25 lei): clientul, produsul (făcut o dată, ținut în setări), abonamentul', ['amount' => 25, 'frequency' => 'monthly',
        'name' => 'Maria Proba', 'email' => 'maria.proba@exemplu.ro', 'consent' => true]);
    foreach ($parti as $parte) {
        $alt = $raspunsuri;
        $alt['POST /v1/subscriptions']['corp']['id'] = 'sub_nou_2';
        $alt['POST /v1/subscriptions']['corp']['latest_invoice']['id'] = 'in_nou_2';
        file_put_contents($dosareStripe[$parte] . '/raspunsuri.json', (string) json_encode($alt));
    }
    $pasIntent('a doua donație lunară, din cont: produsul refolosit, user_id-ul contului', ['amount' => 30, 'frequency' => 'monthly',
        'email' => 'alt.donator@exemplu.ro', 'consent' => true], (string) $conectare($baze['nou'])->query('SELECT email FROM users ORDER BY id LIMIT 1 OFFSET 5')->fetchColumn());
    $pasIntent('suma sub minim: 422', ['amount' => 2, 'email' => 'x@exemplu.ro', 'consent' => true]);
    $pasIntent('adresa greșită: 422', ['amount' => 50, 'email' => 'nu-e-adresa', 'consent' => true]);
    $pasIntent('fără acord: 422', ['amount' => 50, 'email' => 'x@exemplu.ro']);
    file_put_contents($dosareStripe['vechi'] . '/raspunsuri.json', (string) json_encode(['POST /v1/payment_intents' => ['cod' => 402,
        'corp' => ['error' => ['message' => 'Cardul de probă a fost refuzat.']]]] + $raspunsuri));
    file_put_contents($dosareStripe['nou'] . '/raspunsuri.json', (string) json_encode(['POST /v1/payment_intents' => ['cod' => 402,
        'corp' => ['error' => ['message' => 'Cardul de probă a fost refuzat.']]]] + $raspunsuri));
    $mesajGeneral = 'Plata nu a putut fi inițiată. Verifică datele cardului sau încearcă din nou peste câteva minute.';
    $pasIntent('Stripe refuză plata: 502, donația „failed”; codul comun nu arată mesajul Stripe (declarat: revizuirea de securitate, doar în jurnal)',
        ['amount' => 40, 'email' => 'refuz@exemplu.ro', 'consent' => true], null, static function (array $v, array $n) use ($mesajGeneral): array {
            $json = json_decode($n['json'], true);
            $ascuns = is_array($json) && ($json['message'] ?? '') === $mesajGeneral && !str_contains($n['json'], 'Cardul de prob');
            $faraMesaj = static fn (array $x): array => ['cod' => $x['cod'], 'json' => (string) preg_replace('/"message":"[^"]*"/', '"message":"…"', $x['json'])];
            return [$faraMesaj($v), $ascuns ? $faraMesaj($n) : $n];
        });
    file_put_contents($dosareStripe['vechi'] . '/raspunsuri.json', (string) json_encode($raspunsuri));
    file_put_contents($dosareStripe['nou'] . '/raspunsuri.json', (string) json_encode($raspunsuri));

    // Înscrierea cu tipul de cont.
    $paginaInscriere = ['vechi' => $sesiune('vechi'), 'nou' => $sesiune('nou')];
    $formularV = $paginaInscriere['vechi']('GET', '/register');
    $formularN = $paginaInscriere['nou']('GET', '/register');
    $campuri = static fn (string $html): string => (string) preg_replace('/\s+/', ' ', strip_tags(
        (string) preg_replace('#<style>.*?</style>|<script>.*?</script>#s', '', $bucata($html, '<div class="profession-block"', '</form>'))));
    $verifica('C /register: selectorul tipului de cont, datele contului și câmpurile fiecărei categorii (același text și aceleași câmpuri)',
        $campuri($formularV['corp']) !== '' && $campuri($formularV['corp']) === $campuri($formularN['corp'])
        && str_contains($formularN['corp'], 'enctype="multipart/form-data"'), json_encode([$campuri($formularV['corp']), $campuri($formularN['corp'])],
        JSON_UNESCAPED_UNICODE) ?: '');
    $numeCampuri = static function (string $html) use ($bucata): array {
        preg_match_all('/name="([^"]+)"/', $bucata($html, '<form method="post" action="/register"', '</form>'), $m);
        $n = array_values(array_unique($m[1]));
        sort($n);
        return $n;
    };
    $verifica('C /register: aceleași nume de câmpuri în formular', $numeCampuri($formularV['corp']) === $numeCampuri($formularN['corp']),
        json_encode([$numeCampuri($formularV['corp']), $numeCampuri($formularN['corp'])]) ?: '');
    $pdf = $dosarTest . '/diploma.pdf';
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $jpgFals = $dosarTest . '/fals.pdf';
    file_put_contents($jpgFals, "<?php echo 'nu'; ?>\n");
    sleep(4); // timpul minim al formularului de înregistrare (anti-robot), pe ambele coduri
    $inscriere = static function (string $parte, array $peste, ?string $fisier) use ($paginaInscriere): array {
        $c = $paginaInscriere[$parte];
        $f = $c('GET', '/register');
        preg_match('/name="register_form_token" value="([^"]+)"/', $f['corp'], $tok);
        preg_match('/name="register_form_rendered_at" value="(\d+)"/', $f['corp'], $la);
        return [$c, $tok[1] ?? '', $la[1] ?? '0', $fisier];
    };
    $pasInscriere = static function (string $eticheta, array $campuriPost, ?string $fisier, array $declarat = []) use ($inscriere, $parti, $comparaStarea,
        $verifica, $emailuri): void {
        $pregatit = [];
        foreach ($parti as $parte) {
            $pregatit[$parte] = $inscriere($parte, $campuriPost, $fisier);
        }
        sleep(4);
        $r = [];
        foreach ($parti as $parte) {
            [$c, $tok, $la] = $pregatit[$parte];
            $post = $campuriPost + ['register_form_token' => $tok, 'register_form_rendered_at' => $la, 'register_hp_website' => '', 'next' => '/contul-meu'];
            if ($fisier !== null) {
                $post['profession_file_1_diploma'] = new CURLFile($fisier, 'application/pdf', 'diplomă de probă.pdf');
            }
            $x = $c('POST', '/register', $post, null, [], true);
            $r[$parte] = ['cod' => $x['cod'], 'location' => $x['location']];
        }
        $mv = $emailuri('vechi');
        $mn = $emailuri('nou');
        $verifica('C înscrierea: ' . $eticheta . ' → ' . $r['nou']['cod'] . ' ' . $r['nou']['location'] . ', ' . count($mn) . ' emailuri', $r['vechi'] === $r['nou']
            && $mv === $mn, json_encode([$r, $mv, $mn], JSON_UNESCAPED_UNICODE) ?: '');
        $comparaStarea('C înscrierea: ' . $eticheta, $declarat);
    };
    $dateCont = ['first_name' => 'Ana', 'last_name' => 'Membru', 'email' => 'ana.membru@exemplu.ro', 'phone' => '0700000001', 'password' => $parolaMembri,
        'password_confirm' => $parolaMembri];
    $pasInscriere('fără tipul de cont (refuzată)', $dateCont + ['profession_id' => '0'], null);
    $pasInscriere('fără acordul GDPR (refuzată)', $dateCont + ['profession_id' => '1', 'profession_field[1][specialitate]' => 'ORL',
        'profession_field[1][cod_parafa]' => 'P123', 'profession_field[1][acord_cod]' => '1'], $pdf);
    $pasInscriere('o valoare din afara listei (refuzată)', $dateCont + ['profession_id' => '1', 'account_gdpr_accept' => '1',
        'profession_field[1][specialitate]' => 'Altceva', 'profession_field[1][cod_parafa]' => 'P123', 'profession_field[1][acord_cod]' => '1'], $pdf);
    $pasInscriere('un document care nu e document (refuzată)', $dateCont + ['profession_id' => '1', 'account_gdpr_accept' => '1',
        'profession_field[1][specialitate]' => 'ORL', 'profession_field[1][cod_parafa]' => 'P123', 'profession_field[1][acord_cod]' => '1'], $jpgFals);
    $pasInscriere('completă (cererea „așteaptă confirmarea”, documentul, codul de membru, emailul de confirmare, /cont/verifica-email)', $dateCont + [
        'profession_id' => '1', 'account_gdpr_accept' => '1', 'profession_field[1][specialitate]' => 'ORL', 'profession_field[1][cod_parafa]' => 'P123',
        'profession_field[1][acord_cod]' => '1'], $pdf);
    $verificaV = $paginaInscriere['vechi']('GET', '/cont/verifica-email');
    $verificaN = $paginaInscriere['nou']('GET', '/cont/verifica-email');
    $sect = static fn (string $h): string => (string) preg_replace('/<input type="hidden" name="_csrf" value="[0-9a-f]{64}">/', '',
        $bucata($h, '<section class="verify-email">', '</section>'));
    $verifica('C /cont/verifica-email: aceeași pagină (adresa din sesiune, pașii, retrimiterea; pe codul comun cu jetonul formularului)', $sect($verificaV['corp']) !== ''
        && $sect($verificaV['corp']) === $sect($verificaN['corp']) && $verificaV['cod'] === $verificaN['cod']);
    // Înscrierea din nou pe aceeași adresă neconfirmată: codul vertij o relua peste cont (parola, datele, cererea); codul comun nu
    // mai schimbă contul (revizuirea de securitate, declarat), deci pasul e în D, doar pe codul comun.
    $r = [];
    foreach ($parti as $parte) {
        $jetonVerificare = $parte === 'nou' ? ['_csrf' => (string) $paginaInscriere['nou']('GET', '/cont/verifica-email')['jeton']] : [];
        $x = $paginaInscriere[$parte]('POST', '/cont/retrimite-confirmarea', $jetonVerificare);
        $r[$parte] = [$x['cod'], $x['location']];
    }
    $verifica('C retrimiterea de pe pagina de verificare: același răspuns, un email nou pe fiecare', $r['vechi'] === $r['nou']
        && count($emailuri('vechi')) === 1 && count($emailuri('nou')) === 1, json_encode($r) ?: '');

    // Confirmarea: același token (cunoscut) în ambele baze.
    $token = bin2hex(random_bytes(32));
    $idAna = 0;
    foreach ($baze as $parte => $numeBaza) {
        $db = $conectare($numeBaza);
        $idAna = (int) $db->query("SELECT id FROM users WHERE email = 'ana.membru@exemplu.ro'")->fetchColumn();
        $db->prepare("UPDATE account_verifications SET confirm_token_hash = ? WHERE user_id = ? AND status = 'awaiting_email'")->execute([hash('sha256', $token), $idAna]);
    }
    $cv = $sesiune('vechi');
    $cn = $sesiune('nou');
    $xv = $cv('GET', '/cont/confirmare/' . $token);
    $xn = $cn('GET', '/cont/confirmare/' . $token);
    $verifica('C linkul de confirmare (GET): codul vertij confirmă direct (302 /contul-meu?section=activare); codul comun arată pagina cu butonul și parola '
        . '(declarat: revizuirea WP-25, un program care deschide linkurile nu mai confirmă nimic)', $xv['cod'] === 302 && $xv['location'] === '/contul-meu?section=activare'
        && $xn['cod'] === 200 && str_contains($xn['corp'], 'name="password"') && str_contains($xn['corp'], 'action="/cont/confirmare/' . $token . '"'));
    $gresit = $cn('POST', '/cont/confirmare/' . $token, ['password' => 'gresita']);
    $verifica('C codul comun: confirmarea cu o parolă greșită nu confirmă nimic (înapoi la pagina linkului)', $gresit['cod'] === 302
        && $gresit['location'] === '/cont/confirmare/' . $token && (string) $conectare($baze['nou'])->query("SELECT status FROM account_verifications WHERE user_id = "
        . $idAna . " ORDER BY id DESC LIMIT 1")->fetchColumn() === 'awaiting_email');
    $xn = $cn('POST', '/cont/confirmare/' . $token, ['password' => $parolaMembri]);
    $verifica('C confirmarea: același rezultat (302 /contul-meu?section=activare, logat, cererea „în verificare”, tokenul șters, emailul către admin)',
        $xn['cod'] === 302 && $xn['location'] === '/contul-meu?section=activare' && $emailuri('vechi') === $emailuri('nou'));
    $comparaStarea('C confirmarea');
    $dovedita = (string) $conectare($baze['nou'])->query('SELECT email_confirmed_at FROM users WHERE id = ' . $idAna)->fetchColumn();
    $verifica('D confirmarea dovedește și adresa pentru nucleu (users.email_confirmed_at): comenzile, punctele și donațiile fără cont cu adresa ei',
        $dovedita !== '');
    $xv = $cv('GET', '/cont/confirmare/' . $token);
    $xn = $cn('GET', '/cont/confirmare/' . $token);
    $verifica('C linkul folosit a doua oară: nevalid pe ambele (codul vertij la /contul-meu, codul comun la contul celui logat)', $xv['cod'] === 302
        && $xn['cod'] === 302 && $xv['location'] === '/contul-meu' && $xn['location'] === '/contul-meu');

    // Cotizația: pagina contului.
    $idAprobat = (int) $conectare($baze['nou'])->query("SELECT user_id FROM account_verifications WHERE id = 1")->fetchColumn();
    $emailAprobat = (string) $conectare($baze['nou'])->query('SELECT email FROM users WHERE id = ' . $idAprobat)->fetchColumn();
    $panouActivare = static function (string $html) use ($bucata, $faraJeton): string {
        return $faraJeton($bucata($html, '<section class="account-activation">', '</section>'));
    };
    $conturiTest = [];
    foreach ([1, 2, 3, 4] as $idCerere) {
        $conturiTest[$idCerere] = (string) $conectare($baze['nou'])->query('SELECT u.email FROM account_verifications v JOIN users u ON u.id = v.user_id WHERE v.id = '
            . $idCerere)->fetchColumn();
    }
    $conturiTest['ana'] = 'ana.membru@exemplu.ro';
    foreach ($conturiTest as $cine => $emailCont) {
        $pv = $clientPe('vechi', $emailCont)('GET', '/contul-meu?section=activare');
        $pn = $clientPe('nou', $emailCont)('GET', '/contul-meu?section=activare');
        $verifica('C contul (cererea ' . $cine . '): secțiunea „Activează contul / Cotizația mea”, octet cu octet (fără jetonul formularului)',
            $panouActivare($pv['corp']) !== '' && $panouActivare($pv['corp']) === $panouActivare($pn['corp']),
            $undeDifera($panouActivare($pv['corp']), $panouActivare($pn['corp'])));
    }
    // Plata cotizației.
    $membru = ['vechi' => $clientPe('vechi', $emailAprobat), 'nou' => $clientPe('nou', $emailAprobat)];
    $pasCont = static function (string $eticheta, string $metoda, string $cale, array $campuri = [], bool $multipart = false) use (&$membru, $parti, $cereriStripe,
        $faraIdempotenta, $emailuri, $comparaStarea, $verifica): array {
        $r = [];
        foreach ($parti as $parte) {
            $c = $campuri;
            if ($parte === 'nou' && $metoda === 'POST') {
                // Jetonul din pagina profilului (formularul pozei de profil îl are mereu).
                $c['_csrf'] = (string) $membru['nou']('GET', '/contul-meu?section=profile')['jeton'];
            }
            $x = $membru[$parte]($metoda, $cale, $c, null, [], $multipart);
            $r[$parte] = ['cod' => $x['cod'], 'location' => $x['location']];
        }
        $sv = $cereriStripe('vechi');
        $sn = $cereriStripe('nou');
        $mv = $emailuri('vechi');
        $mn = $emailuri('nou');
        $verifica('C ' . $eticheta . ' → ' . $r['nou']['cod'] . ' ' . $r['nou']['location'] . '; ' . count($sn) . ' cereri spre Stripe, ' . count($mn) . ' emailuri',
            $r['vechi'] === $r['nou'] && $faraIdempotenta($sv) === $faraIdempotenta($sn) && $mv === $mn, json_encode([$r, $sv, $sn, $mv, $mn], JSON_UNESCAPED_UNICODE) ?: '');
        $comparaStarea('C ' . $eticheta);
        return $r['nou'];
    };
    $pasCont('cotizația pe o perioadă care nu există (refuzată)', 'POST', '/contul-meu/activare', ['activation_months' => '7']);
    $r6 = $pasCont('cotizația expirată, reactivată pe 6 luni: plata în așteptare și sesiunea Stripe Checkout (aceeași cerere), trimis la Stripe',
        'POST', '/contul-meu/activare', ['activation_months' => '6']);
    $verifica('C reactivarea a pornit plata la Stripe (adresa sesiunii Checkout)', $r6['location'] === 'https://checkout.stripe.com/c/pay/cs_proba_nou');
    $idActivareNoua = (int) $conectare($baze['nou'])->query('SELECT MAX(id) FROM account_activations')->fetchColumn();
    $sesiuneRetur = ['id' => 'cs_proba_nou', 'object' => 'checkout.session', 'payment_status' => 'paid', 'payment_intent' => 'pi_activare_1',
        'metadata' => ['type' => 'account_activation', 'activation_id' => (string) $idActivareNoua, 'months' => '6']];
    foreach ($parti as $parte) {
        file_put_contents($dosareStripe[$parte] . '/raspunsuri.json', (string) json_encode(['GET /v1/checkout/sessions/cs_proba_nou' => ['cod' => 200,
            'corp' => $sesiuneRetur]] + $raspunsuri));
    }
    $pasCont('întoarcerea de la Stripe (plătită): plata „paid”, contul activ 6 luni, chitanța, emailul', 'GET', '/contul-meu/activare/retur?session_id=cs_proba_nou');
    $verifica('C perioada cotizației după întoarcere: aceleași zile', $perioade('vechi') === $perioade('nou'), json_encode([$perioade('vechi'), $perioade('nou')]) ?: '');
    $pasCont('întoarcerea a doua oară: nimic nou', 'GET', '/contul-meu/activare/retur?session_id=cs_proba_nou');
    $pasCont('cotizația cu contul deja activ (refuzată)', 'POST', '/contul-meu/activare', ['activation_months' => '12']);
    // Tab-ul donațiilor și oprirea donației lunare (contul activ are donația lunară 3).
    $emailActiv = $conturiTest[4];
    $panouDonatii = static function (string $html) use ($bucata, $faraJeton): string {
        $i = strpos($html, '<article class="acc-card acc-donation-active">');
        if ($i === false) {
            $i = strpos($html, '<h2>Istoricul donațiilor</h2>');
        }
        if ($i === false) {
            return '';
        }
        $j = strpos($html, '</table>', $i);
        // Codul comun: formularul spune care donație lunară se oprește (id-ul abonamentului, verificat pe rândurile contului).
        $fara = (string) preg_replace('/\n?[ \t]*<input type="hidden" name="subscription_id" value="[^"]*">/', '', $faraJeton(substr($html, $i, $j - $i)));
        return $j === false ? '' : (string) preg_replace('/\s+/', ' ', $fara);
    };
    $donV = $clientPe('vechi', $emailActiv)('GET', '/contul-meu?section=donations');
    $donN = $clientPe('nou', $emailActiv)('GET', '/contul-meu?section=donations');
    $verifica('C contul: tab-ul „Donații” (donația lunară activă, istoricul cu lunile și chitanțele), același HTML (spațiile normalizate)',
        $panouDonatii($donV['corp']) !== '' && $panouDonatii($donV['corp']) === $panouDonatii($donN['corp']) && str_contains($donN['corp'], '>Donații')
        && str_contains($donN['corp'], 'lunar'), json_encode([$panouDonatii($donV['corp']), $panouDonatii($donN['corp'])], JSON_UNESCAPED_UNICODE) ?: '');
    $membru = ['vechi' => $clientPe('vechi', $emailActiv), 'nou' => $clientPe('nou', $emailActiv)];
    $pasCont('oprirea donației lunare din cont: abonamentul anulat la Stripe (DELETE), „cancelled_at”', 'POST', '/contul-meu/donatii/opreste');
    $pasCont('oprirea a doua oară: „Nu ai nicio donație lunară activă”', 'POST', '/contul-meu/donatii/opreste');
    // Poza de profil.
    $png = $dosarTest . '/poza.png';
    $img = imagecreatetruecolor(4, 4);
    imagepng($img, $png);
    $pasCont('poza de profil (PNG): pe cont și în Galerie, folderul „Poze de profil”', 'POST', '/contul-meu/poza', ['avatar' => new CURLFile($png, 'image/png', 'eu.png')],
        true);
    $galerie = static fn (string $parte): array => $conectare($baze[$parte])->query("SELECT f.name, f.slug, COUNT(i.id) AS poze FROM gallery_folders f
        LEFT JOIN gallery_images i ON i.folder_id = f.id AND i.image_url LIKE '/uploads/gallery/profile/membru-%' WHERE f.name = 'Poze de profil' GROUP BY f.id")->fetchAll();
    $verifica('C poza de profil: același folder în Galerie, cu poza', $galerie('vechi') === $galerie('nou') && $galerie('nou') !== [],
        json_encode([$galerie('vechi'), $galerie('nou')], JSON_UNESCAPED_UNICODE) ?: '');
    $avatarNou = (string) $conectare($baze['nou'])->query("SELECT avatar_url FROM users WHERE email = " . $conectare()->quote($emailActiv))->fetchColumn();
    $profilNou = $clientPe('nou', $emailActiv)('GET', '/contul-meu?section=profile')['corp'];
    $verifica('C poza de profil pe codul comun: fișierul în uploads/gallery/profile, linkul în pagina contului', $avatarNou !== '' && is_file($radacina . '/public' . $avatarNou)
        && str_contains($profilNou, 'src="' . $avatarNou . '"'), $avatarNou . ' ' . (is_file($radacina . '/public' . $avatarNou) ? 'fișier' : 'fără fișier') . ' '
        . mb_substr((string) strstr($profilNou, 'acc-avatar-block'), 0, 600));
    $pasCont('poza ștearsă', 'POST', '/contul-meu/poza', ['remove_avatar' => '1']);
    $pasCont('un fișier care nu e imagine (refuzat)', 'POST', '/contul-meu/poza', ['avatar' => new CURLFile($jpgFals, 'image/png', 'nu.png')], true);
    // Cererea nouă (cererea respinsă).
    $membru = ['vechi' => $clientPe('vechi', $conturiTest[3]), 'nou' => $clientPe('nou', $conturiTest[3])];
    $req = static fn (string $h): string => $faraJeton((string) preg_replace('#<script>.*?</script>#s', '', $bucata($h, '<section class="req-wrap">', '</section>')));
    $rv = $membru['vechi']('GET', '/contul-meu/cerere-noua');
    $rn = $membru['nou']('GET', '/contul-meu/cerere-noua');
    $verifica('C /contul-meu/cerere-noua (cererea respinsă, cu motivul): aceeași pagină, octet cu octet (fără jetonul formularului)', $req($rv['corp']) !== ''
        && $req($rv['corp']) === $req($rn['corp']), $undeDifera($req($rv['corp']), $req($rn['corp'])));
    $pasCont('cererea nouă, categoria 2 (fără documente): „în verificare” direct, emailul către admin', 'POST', '/contul-meu/cerere-noua', ['profession_id' => '2',
        'account_gdpr_accept' => '1', 'profession_field[2][unitate]' => 'Spitalul de probă'], true);
    $pasCont('a doua cerere nouă (refuzată: „Ai deja o cerere în verificare.”)', 'GET', '/contul-meu/cerere-noua');
    // Nota „acces restricționat”.
    $nota = static fn (string $h): string => $bucata($h, '<section class="restricted-notice">', '</section>');
    foreach (['oaspete' => null, 'în verificare' => $conturiTest[2], 'aprobat, neactivat' => $conturiTest[1], 'activ' => $conturiTest[4]] as $cine => $emailCont) {
        $nv = ($emailCont === null ? $sesiune('vechi') : $clientPe('vechi', $emailCont))('GET', '/ghid-medici-proba');
        $nn = ($emailCont === null ? $sesiune('nou') : $clientPe('nou', $emailCont))('GET', '/ghid-medici-proba');
        $titlu = static fn (string $h): string => preg_match('#<title>(.*?)</title>#s', $h, $m) === 1 ? trim($m[1]) : '';
        $verifica('C pagina doar pentru membri, ' . $cine . ': ' . $nn['cod'] . ', aceeași notă pe stări (sau aceeași pagină) și același titlu',
            $nv['cod'] === $nn['cod'] && $nota($nv['corp']) === $nota($nn['corp']) && ($nn['cod'] !== 403 || ($nota($nn['corp']) !== ''
            && $titlu($nv['corp']) === $titlu($nn['corp']))) && ($cine !== 'activ' || str_contains($nn['corp'], 'Conținut doar pentru membri (inventat).')),
            json_encode([$nv['cod'], $nn['cod'], $nota($nv['corp']), $nota($nn['corp']), $titlu($nv['corp']), $titlu($nn['corp'])], JSON_UNESCAPED_UNICODE) ?: '');
    }

    // ── C: adminul ──
    $scrie('');
    $scrie('C. Adminul: tipurile de cont, cererile, documentele, setările, donațiile, lista de utilizatori');
    $adm = ['vechi' => $adminPe('vechi'), 'nou' => $adminPe('nou')];
    $sectiune = static function (string $html): string {
        // Partea paginii a pluginului: de la primul <section class="panel"> până la sfârșitul ultimului, fără jetonul formularului.
        $i = strpos($html, '<section class="panel">');
        $j = strrpos($html, '</section>');
        $p = $i !== false && $j !== false && $j > $i ? substr($html, $i, $j - $i) : '';
        $p = (string) preg_replace('/\n?[ \t]*<input type="hidden" name="_csrf" value="[0-9a-f]{64}">/', '', $p);
        // Orele: nucleul pune fusul orar al aplicației pe conexiune (Database::connection, SET time_zone; codul vertij nu), deci un
        // TIMESTAMP se arată în ora României și NOW() scrie ora României (declarat în site-uri/vertij/ce-se-schimba.md); data rămâne.
        // Rândurile scrise în timpul testului: ora lor poate cădea și în minute diferite pe cele două coduri.
        $p = (string) preg_replace('/(\d\d\.\d\d\.\d{4}) \d\d:\d\d/', '$1 hh:mm', $p);
        // Referințele donațiilor noi sunt aleatoare (DON-xxxxxxxx).
        $p = (string) preg_replace('/DON-[0-9A-F]{8}/', 'DON-XXXXXXXX', $p);
        // Exemplul din câmpul adresei de notificare: în codul comun fără numele unui client (WP-16).
        return str_replace('placeholder="ex: contact@vertij-tinitus.ro"', 'placeholder="ex: contact@exemplu.ro"', $p);
    };
    $paginaAdmin = static function (string $eticheta, string $cale) use ($adm, $sectiune, $verifica, $undeDifera): void {
        $pv = $adm['vechi']('GET', $cale);
        $pn = $adm['nou']('GET', $cale);
        $sv = $sectiune($pv['corp']);
        $sn = $sectiune($pn['corp']);
        $verifica('C ' . $eticheta . ' (' . $cale . '): 200 pe ambele, partea pluginului octet cu octet (' . strlen($sn) . ' octeți, fără jetonul formularului)',
            $pv['cod'] === 200 && $pn['cod'] === 200 && $sv !== '' && $sv === $sn, 'coduri ' . $pv['cod'] . '/' . $pn['cod'] . ', ' . $undeDifera($sv, $sn));
    };
    $pasAdmin = static function (string $eticheta, string $metoda, string $cale, array $campuri = []) use ($adm, $parti, $comparaStarea, $verifica, $emailuri): array {
        $r = [];
        foreach ($parti as $parte) {
            $c = $campuri;
            if ($parte === 'nou' && $metoda === 'POST') {
                $c['_csrf'] = (string) $adm['nou']('GET', '/admin/accounts/settings')['jeton'];
            }
            $x = $adm[$parte]($metoda, $cale, $c);
            $r[$parte] = ['cod' => $x['cod'], 'location' => $x['location']];
        }
        $mv = $emailuri('vechi');
        $mn = $emailuri('nou');
        $verifica('C ' . $eticheta . ' → ' . $r['nou']['cod'] . ' ' . $r['nou']['location'] . ', ' . count($mn) . ' emailuri', $r['vechi'] === $r['nou'] && $mv === $mn,
            json_encode([$r, $mv, $mn], JSON_UNESCAPED_UNICODE) ?: '');
        $comparaStarea('C ' . $eticheta);
        return $r['nou'];
    };
    $paginaAdmin('Tipuri de cont', '/admin/accounts/professions');
    $paginaAdmin('Tipuri de cont, editarea categoriei 1 (câmpurile ei)', '/admin/accounts/professions?edit=1');
    $pasAdmin('categorie nouă', 'POST', '/admin/accounts/professions', ['action' => 'save', 'profession_id' => '0', 'name' => 'Rezident de probă',
        'slug' => '', 'description' => 'Medici rezidenți.', 'activation_price' => '60,50', 'activation_months' => '12', 'is_active' => '1', 'sort_order' => '4']);
    $pasAdmin('categorie fără nume (refuzată)', 'POST', '/admin/accounts/professions', ['action' => 'save', 'profession_id' => '0', 'name' => ' ']);
    $pasAdmin('categorie cu slug-ul altei categorii (refuzată)', 'POST', '/admin/accounts/professions', ['action' => 'save', 'profession_id' => '0',
        'name' => 'Dublură', 'slug' => 'medic-de-proba']);
    $pasAdmin('câmp nou (listă cu opțiuni)', 'POST', '/admin/accounts/professions/fields', ['action' => 'save', 'profession_id' => '1', 'field_id' => '0',
        'label' => 'Anul absolvirii', 'field_type' => 'select', 'options' => "2020\n2021\n\n2022", 'is_required' => '1', 'is_active' => '1', 'sort_order' => '5']);
    $pasAdmin('câmp cu o cheie existentă (refuzat)', 'POST', '/admin/accounts/professions/fields', ['action' => 'save', 'profession_id' => '1', 'field_id' => '0',
        'label' => 'Specialitate', 'field_type' => 'text']);
    $pasAdmin('câmpul șters', 'POST', '/admin/accounts/professions/fields', ['action' => 'delete', 'profession_id' => '1', 'field_id' => '2']);
    $pasAdmin('categoria 3 ștearsă, cu câmpurile ei', 'POST', '/admin/accounts/professions', ['action' => 'delete', 'profession_id' => '3']);
    $paginaAdmin('Cereri conturi (în verificare), cu ultima plată a cotizației', '/admin/accounts/verifications');
    $paginaAdmin('Cereri conturi (toate)', '/admin/accounts/verifications?status=all');
    $paginaAdmin('Cereri conturi (căutare)', '/admin/accounts/verifications?status=all&q=Medic');
    $paginaAdmin('Cererea 2 (răspunsurile, documentul, plățile)', '/admin/accounts/verifications/2');
    $paginaAdmin('Cererea 4 (contul activ, plățile cotizației)', '/admin/accounts/verifications/4');
    $dv = $adm['vechi']('GET', '/admin/accounts/documents/1');
    $dn = $adm['nou']('GET', '/admin/accounts/documents/1');
    $antetDoc = static fn (string $a): array => [preg_match('/^Content-Type: (.+)$/mi', $a, $m) === 1 ? trim($m[1]) : '',
        preg_match('/^Content-Disposition: (.+)$/mi', $a, $m) === 1 ? trim($m[1]) : ''];
    $verifica('C documentul cererii (din afara docroot-ului): aceiași octeți, același tip și același nume; pe codul comun cu CSP sandbox', $dv['cod'] === 200
        && $dn['cod'] === 200 && $dv['corp'] === $dn['corp'] && $antetDoc($dv['antet']) === $antetDoc($dn['antet'])
        && stripos($dn['antet'], 'Content-Security-Policy:') !== false && str_contains($dn['antet'], 'sandbox'), json_encode([$antetDoc($dv['antet']), $antetDoc($dn['antet'])]) ?: '');
    $verifica('C un document care nu există: 404 pe ambele', $adm['vechi']('GET', '/admin/accounts/documents/999')['cod'] === 404
        && $adm['nou']('GET', '/admin/accounts/documents/999')['cod'] === 404);
    $pasAdmin('o decizie necunoscută (refuzată)', 'POST', '/admin/accounts/verifications/2/decision', ['decision' => 'poate', 'review_note' => '']);
    $pasAdmin('cererea 2 aprobată: starea, contul, jurnalul, emailul cu codul de membru', 'POST', '/admin/accounts/verifications/2/decision',
        ['decision' => 'approved', 'review_note' => '']);
    $paginaAdmin('Setări conturi', '/admin/accounts/settings');
    $pasAdmin('Setări conturi salvate (durate, prefix, texte, poza de profil)', 'POST', '/admin/accounts/settings', ['account_upload_max_mb' => '5,5',
        'account_upload_max_files' => '0', 'account_upload_allowed_ext' => 'PDF,jpg', 'account_activation_durations' => '12,1,99,3,abc',
        'account_member_code_prefix' => 'svth-x', 'header_account_label' => 'Contul de membru', 'account_avatar_max_mb' => '0,1',
        'account_avatar_folder' => 'Avatare', 'restricted_page_title' => 'Doar membri', 'account_activation_title' => 'Plătește cotizația']);
    $paginaAdmin('Donații', '/admin/donations');
    $paginaAdmin('Donații (plătite, căutare)', '/admin/donations?status=paid&q=lunar');
    $paginaAdmin('Setări donații', '/admin/settings/donations');
    $pasAdmin('Setări donații salvate', 'POST', '/admin/settings/donations', ['donations_enabled' => '1', 'donations_currency' => 'RON',
        'donations_amounts' => '500, 100;50', 'donations_min' => '0', 'donations_designations' => "Cercetare\nEvenimente", 'donations_cover_fees_enabled' => '1',
        'donations_cover_fees_percent' => '40', 'donations_cover_fees_fixed' => '1,5', 'donations_campaign_title' => 'Susține-ne', 'donations_thanks_text' => 'Mulțumim!']);
    // Ultima plată a cotizației în lista de utilizatori (aceleași cinci celule pe rândul fiecărui membru).
    $lv = $adm['vechi']('GET', '/admin/users?per_page=100');
    $ln = $adm['nou']('GET', '/admin/users?per_page=100');
    $celule = static function (string $html, int $id): string {
        // Rândul contului: ultimele șase celule sunt cele cinci ale cotizației și „Detalii”.
        if (preg_match('#<tr[^>]*data-users-row>(?:(?!</tr>).)*?href="/admin/users/' . $id . '[?"](?:(?!</tr>).)*</tr>#s', $html, $m) !== 1) {
            return '';
        }
        preg_match_all('#<td\b[^>]*>.*?</td>#s', $m[0], $td);
        $ultimele = array_slice($td[0], -6, 5);
        return count($ultimele) === 5 ? (string) preg_replace(['/\s+/', '/(\d\d\.\d\d\.\d{4}) \d\d:\d\d/'], [' ', '$1 hh:mm'], implode('', $ultimele)) : '';
    };
    foreach ([$idAprobat, (int) $conectare($baze['nou'])->query('SELECT user_id FROM account_verifications WHERE id = 4')->fetchColumn()] as $idCont) {
        $verifica('C Admin → Utilizatori, contul ' . $idCont . ': ultima plată a cotizației (sumă, perioadă, stare, interval, plătit la), aceleași celule',
            $celule($lv['corp'], $idCont) !== '' && $celule($lv['corp'], $idCont) === $celule($ln['corp'], $idCont),
            json_encode([$celule($lv['corp'], $idCont), $celule($ln['corp'], $idCont)], JSON_UNESCAPED_UNICODE) ?: '');
    }
    $verifica('C Admin → Utilizatori pe codul comun: tabelul derulează pe orizontală (.admin-table-scroll, vertij b0a81e1)',
        str_contains($ln['corp'], 'users-table-wrap admin-table-scroll') && str_contains($ln['corp'], '.admin-table-scroll{overflow-x:auto;'));
    // Ștergerea unui membru din admin: documentele și plățile lui.
    $idSters = (int) $conectare($baze['nou'])->query('SELECT user_id FROM account_verifications WHERE id = 2')->fetchColumn();
    $pasAdmin('un membru șters din Admin → Utilizatori: cererile, documentele (și de pe disc) și plățile lui', 'POST', '/admin/users/delete-selected',
        ['user_ids' => [(string) $idSters]]);
    $verifica('C documentul membrului șters a dispărut de pe disc, pe ambele coduri', !is_file($codVechi . '/storage/account-documents/20260902-proba0000000000000000000000000001.pdf')
        && !is_file($radacina . '/storage/account-documents/20260902-proba0000000000000000000000000001.pdf'));

    // ── D: doar pe codul comun ──
    $scrie('');
    $scrie('D. Doar pe codul comun: CSRF, JSON, logarea, donațiile după dovadă, pluginurile oprite');
    $inainte = $starea('nou');
    $an = $adminPe('nou');
    foreach (['/admin/accounts/professions' => ['action' => 'delete', 'profession_id' => '1'], '/admin/accounts/professions/fields' => ['action' => 'delete',
        'profession_id' => '1', 'field_id' => '1'], '/admin/accounts/verifications/3/decision' => ['decision' => 'approved'], '/admin/accounts/settings' =>
        ['account_member_code_prefix' => 'X'], '/admin/settings/donations' => ['donations_enabled' => '0']] as $cale => $campuri) {
        $x = $an('POST', $cale, $campuri);
        $verifica('D ' . $cale . ' fără jetonul formularului: refuzat (302), nimic schimbat', $x['cod'] === 302 && $starea('nou') === $inainte);
    }
    $cm = $clientPe('nou', $conturiTest[1]);
    foreach (['/contul-meu/activare' => ['activation_months' => '12'], '/contul-meu/poza' => ['remove_avatar' => '1'], '/contul-meu/donatii/opreste' => []] as $cale => $campuri) {
        $x = $cm('POST', $cale, $campuri);
        $verifica('D ' . $cale . ' fără jetonul formularului: refuzat, nimic schimbat, nicio cerere spre Stripe', $x['cod'] === 302 && $starea('nou') === $inainte
            && $cereriStripe('nou') === []);
    }
    $x = $sesiune('nou')('POST', '/api/donations/intent', ['amount' => '50', 'email' => 'form@exemplu.ro', 'consent' => '1']);
    $verifica('D donația trimisă ca formular (nu JSON, de exemplu de pe alt site): 415, nicio donație, nicio cerere spre Stripe', $x['cod'] === 415
        && $starea('nou') === $inainte && $cereriStripe('nou') === []);
    // Logarea unui cont cu cererea neconfirmată.
    $dbN = $conectare($baze['nou']);
    $idRespins = (int) $dbN->query('SELECT user_id FROM account_verifications WHERE id = 3')->fetchColumn();
    $idUltima = (int) $dbN->query('SELECT MAX(id) FROM account_verifications WHERE user_id = ' . $idRespins)->fetchColumn();
    $dbN->exec("UPDATE account_verifications SET status = 'awaiting_email' WHERE id = " . $idUltima);
    $dbN->exec('UPDATE users SET email_confirmed_at = NULL WHERE id = ' . $idRespins);
    $c = $sesiune('nou');
    $c('GET', '/login');
    $x = $c('POST', '/login', ['email' => $conturiTest[3], 'password' => $parolaMembri]);
    $p = $c('GET', '/contul-meu');
    $verifica('D un cont cu cererea „așteaptă confirmarea pe email” nu intră (confirmarea cerută la înregistrare, înghețată 1 pe vertij)', $x['cod'] === 302
        && $x['location'] === '/login' && $p['location'] !== '' && str_starts_with($p['location'], '/login'));
    $dbN->exec("UPDATE account_verifications SET status = 'pending' WHERE id = " . $idUltima);
    // Donațiile fără cont cu adresa contului: doar după dovadă.
    $dbN->exec('UPDATE users SET email_confirmed_at = NULL WHERE id = ' . $idAprobat);
    $p = $clientPe('nou', $emailAprobat)('GET', '/contul-meu?section=donations');
    $verifica('D donația fără cont cu adresa unui cont nedovedit: nu apare; rândul „Confirmă adresa ca să le vezi aici” cu butonul nucleului',
        !str_contains($p['corp'], 'Istoricul donațiilor') && str_contains($p['corp'], 'Ai donații mai vechi făcute cu această adresă de email')
        && str_contains($p['corp'], 'action="/contul-meu/confirmare-email"'));
    $dbN->exec('UPDATE users SET email_confirmed_at = NOW() WHERE id = ' . $idAprobat);
    $p = $clientPe('nou', $emailAprobat)('GET', '/contul-meu?section=donations');
    $verifica('D cu adresa dovedită: donația apare în istoric', str_contains($p['corp'], 'Istoricul donațiilor') && str_contains($p['corp'], '25,00'));
    // ── D: revizuirea de securitate (9 nov 2026) și deciziile proprietarului, doar pe codul comun ──
    $scrie('');
    $scrie('D. Revizuirea de securitate și deciziile proprietarului (codul comun diferă voit de vertij)');
    $dbN = $conectare($baze['nou']);
    $emailuri('nou');
    $cereriStripe('nou');
    // Bugetele vizitatorului (înregistrările, retrimiterile, donațiile, întoarcerile) pornesc goale: testul vine de pe aceeași adresă.
    $dbN->exec('DELETE FROM register_submit_logs');
    $parolaNoua = 'Alta-Parola-Intrus-2';
    $inscrieNou = static function (array $campuri) use ($sesiune): array {
        $c = $sesiune('nou');
        $f = $c('GET', '/register');
        preg_match('/name="register_form_token" value="([^"]+)"/', $f['corp'], $tok);
        preg_match('/name="register_form_rendered_at" value="(\d+)"/', $f['corp'], $la);
        sleep(4);
        $x = $c('POST', '/register', $campuri + ['register_form_token' => $tok[1] ?? '', 'register_form_rendered_at' => $la[1] ?? '0',
            'register_hp_website' => '', 'next' => '/contul-meu'], null, [], true);
        return [$c, $x];
    };
    $contul = static fn (string $email): array => $dbN->query('SELECT id, first_name, phone, password_hash, session_version, email_confirmed_at FROM users WHERE email = '
        . $dbN->quote($email))->fetch() ?: [];
    $cererile = static fn (int $id): array => $dbN->query('SELECT id, status, answers_json, confirm_token_hash FROM account_verifications WHERE user_id = ' . $id
        . ' ORDER BY id')->fetchAll();
    $inscriereCat2 = static fn (string $email, string $prenume, string $parola, string $unitate): array => ['first_name' => $prenume,
        'last_name' => 'Proba', 'email' => $email, 'phone' => '0700000077', 'password' => $parola, 'password_confirm' => $parola, 'profession_id' => '2',
        'account_gdpr_accept' => '1', 'profession_field[2][unitate]' => $unitate];

    // 1. Înscrierea pe o adresă care are deja cont (codul vertij relua înscrierea peste cont, cu parola nouă).
    [, $x] = $inscrieNou($inscriereCat2('bogdan.membru@exemplu.ro', 'Bogdan', $parolaMembri, 'Spitalul A'));
    $b0 = $contul('bogdan.membru@exemplu.ro');
    $c0 = $cererile((int) ($b0['id'] ?? 0));
    $verifica('D înscrierea nouă (categoria 2): contul, cererea „awaiting_email”, emailul de confirmare', $x['location'] === '/cont/verifica-email'
        && count($c0) === 1 && $c0[0]['status'] === 'awaiting_email' && count($emailuri('nou')) === 1, json_encode([$x['location'], $c0]) ?: '');
    [$cInscriere, $x] = $inscrieNou($inscriereCat2('bogdan.membru@exemplu.ro', 'Intrus', $parolaNoua, 'Spitalul B'));
    $b1 = $contul('bogdan.membru@exemplu.ro');
    $c1 = $cererile((int) ($b0['id'] ?? 0));
    $mail1 = $emailuri('nou');
    $verifica('D din nou pe aceeași adresă (neconfirmată, cont nefolosit): parola, numele și cererea NESCHIMBATE, linkul retrimis (token nou), '
        . 'celelalte sesiuni încheiate, /cont/verifica-email cu mesajul „confirmă cu parola de la prima înscriere”',
        $x['location'] === '/cont/verifica-email' && password_verify($parolaMembri, (string) ($b1['password_hash'] ?? '')) && !password_verify($parolaNoua,
        (string) ($b1['password_hash'] ?? '')) && ($b1['first_name'] ?? '') === 'Bogdan' && count($c1) === 1 && $c1[0]['id'] === $c0[0]['id']
        && $c1[0]['answers_json'] === $c0[0]['answers_json'] && $c1[0]['confirm_token_hash'] !== $c0[0]['confirm_token_hash']
        && (int) ($b1['session_version'] ?? 0) === (int) ($b0['session_version'] ?? 0) + 1 && count($mail1) === 1
        && str_contains($cInscriere('GET', '/cont/verifica-email')['corp'], 'prima înscriere'), json_encode([$x['location'], $b0, $b1, $c0, $c1, $mail1],
        JSON_UNESCAPED_UNICODE) ?: '');
    // Contul a fost folosit (o donație din cont): nu se mai retrimite nimic, omul e trimis la intrare / resetarea parolei.
    $dbN->prepare("INSERT INTO donations (reference, user_id, donor_email, amount_cents, currency, frequency, status, consent) VALUES
        ('DON-BOGDAN01', ?, 'bogdan.membru@exemplu.ro', 1000, 'ron', 'once', 'pending', 1)")->execute([(int) $b0['id']]);
    [$cInscriere, $x] = $inscrieNou($inscriereCat2('bogdan.membru@exemplu.ro', 'Intrus', $parolaNoua, 'Spitalul C'));
    $b2 = $contul('bogdan.membru@exemplu.ro');
    $verifica('D din nou pe aceeași adresă, contul folosit (o donație din cont): /login cu „Intră în cont sau … Am uitat parola”, nimic schimbat, niciun email',
        $x['location'] === '/login' && $b2 === $b1 && $cererile((int) $b0['id']) === $c1 && $emailuri('nou') === []
        && str_contains($cInscriere('GET', '/login')['corp'], 'Am uitat parola'), json_encode([$x['location'], $b2]) ?: '');
    // Contul confirmat prin resetarea parolei (adresa dovedită, cererea încă „awaiting_email” pe codul vertij): preluarea de la
    // revizuire. Resetarea trece acum și cererea la verificare (confirmare.dupa din ConfirmareEmail::marcheazaConfirmat).
    [, $x] = $inscrieNou($inscriereCat2('carmen.membru@exemplu.ro', 'Carmen', $parolaMembri, 'Spitalul D'));
    $emailuri('nou');
    $tokenReset = bin2hex(random_bytes(32));
    // Momentele scrise aici, în ora României: codul comun pune fusul site-ului pe conexiune (conexiunea testului e în ora serverului).
    $oraRo = static fn (string $cand): string => (new DateTimeImmutable($cand, new DateTimeZone('Europe/Bucharest')))->format('Y-m-d H:i:s');
    $dbN->prepare("INSERT INTO customer_password_resets (email, token_hash, expires_at) VALUES ('carmen.membru@exemplu.ro', ?, ?)")
        ->execute([hash('sha256', $tokenReset), $oraRo('+1 hour')]);
    $parolaResetata = 'Parola-Resetata-3';
    // Adresa la care se anunță cererile (Setări conturi, golită de salvarea din C).
    $dbN->exec("REPLACE INTO settings (`key`, `value`) VALUES ('account_verification_notify_email', 'cereri@exemplu.ro')");
    $x = $sesiune('nou')('POST', '/contul-meu/resetare-parola/' . $tokenReset, ['password' => $parolaResetata, 'password_confirm' => $parolaResetata]);
    $k0 = $contul('carmen.membru@exemplu.ro');
    $ck = $cererile((int) ($k0['id'] ?? 0));
    $mailReset = $emailuri('nou');
    $verifica('D resetarea parolei dovedește adresa: email_confirmed_at, cererea trece „pending” (codul vertij o lăsa „awaiting_email”), adminul e anunțat',
        (string) ($k0['email_confirmed_at'] ?? '') !== '' && ($ck[0]['status'] ?? '') === 'pending'
        && in_array(true, array_map(static fn (string $m): bool => str_starts_with($m, 'cereri@exemplu.ro'), $mailReset), true),
        json_encode([$x['location'], $k0, $ck, $mailReset], JSON_UNESCAPED_UNICODE) ?: '');
    [, $x] = $inscrieNou($inscriereCat2('carmen.membru@exemplu.ro', 'Intrus', $parolaNoua, 'Spitalul E'));
    $k1 = $contul('carmen.membru@exemplu.ro');
    $verifica('D înscrierea peste un cont confirmat prin resetare: /login, parola resetată rămâne (nicio preluare), cererea neschimbată',
        $x['location'] === '/login' && password_verify($parolaResetata, (string) ($k1['password_hash'] ?? '')) && ($k1['first_name'] ?? '') === 'Carmen'
        && $cererile((int) $k0['id']) === $ck && $emailuri('nou') === [], json_encode([$x['location'], $k1]) ?: '');

    // Un cont vechi (cererea neconfirmată de dinainte de adoptare, cont nefolosit): linkul se retrimite, dar sesiunile lui rămân
    // (intra pe codul vertij și intră și acum).
    $dbN->prepare("INSERT INTO users (first_name, last_name, email, password_hash) VALUES ('Dana', 'Veche', 'dana.veche@exemplu.ro', ?)")
        ->execute([password_hash($parolaMembri, PASSWORD_BCRYPT)]);
    $idDana = (int) $dbN->lastInsertId();
    $dbN->prepare("INSERT INTO account_verifications (user_id, profession_id, profession_name, status, confirm_token_hash, created_at, updated_at)
        VALUES (?, 2, 'Asistent de proba', 'awaiting_email', ?, '2026-08-01 10:00:00', '2026-08-01 10:00:00')")->execute([$idDana, hash('sha256', 'vechi')]);
    $d0 = $contul('dana.veche@exemplu.ro');
    [, $x] = $inscrieNou($inscriereCat2('dana.veche@exemplu.ro', 'Intrus', $parolaNoua, 'Spitalul F'));
    $d1 = $contul('dana.veche@exemplu.ro');
    $verifica('D din nou pe adresa unui cont vechi (cererea de dinainte de adoptare): linkul retrimis, parola și sesiunile NESCHIMBATE (session_version)',
        $x['location'] === '/cont/verifica-email' && (int) ($d1['session_version'] ?? -1) === (int) ($d0['session_version'] ?? -2)
        && ($d1['password_hash'] ?? '') === ($d0['password_hash'] ?? '') && count($emailuri('nou')) === 1, json_encode([$x['location'], $d0, $d1]) ?: '');

    // 2. Retrimiterea fără jetonul formularului.
    $x = $sesiune('nou')('POST', '/cont/retrimite-confirmarea', ['email' => 'bogdan.membru@exemplu.ro']);
    $verifica('D /cont/retrimite-confirmarea fără jetonul formularului: refuzat (302), niciun email', $x['cod'] === 302 && $emailuri('nou') === []);

    // 3. Linkul de confirmare al cererii expiră după 7 zile.
    $tokenVechi = bin2hex(random_bytes(32));
    $dbN->prepare("UPDATE account_verifications SET confirm_token_hash = ?, updated_at = ? WHERE user_id = ? AND status = 'awaiting_email'")
        ->execute([hash('sha256', $tokenVechi), $oraRo('-8 days'), (int) $b0['id']]);
    $xVechi = $sesiune('nou')('GET', '/cont/confirmare/' . $tokenVechi);
    $dbN->prepare("UPDATE account_verifications SET updated_at = ? WHERE user_id = ? AND status = 'awaiting_email'")->execute([$oraRo('-6 days'), (int) $b0['id']]);
    $xProaspat = $sesiune('nou')('GET', '/cont/confirmare/' . $tokenVechi);
    $verifica('D linkul cererii de acum 8 zile: expirat (nu mai arată pagina confirmării); cel de acum 6 zile: pagina cu parola', !str_contains($xVechi['corp'],
        'name="password"') && $xProaspat['cod'] === 200 && str_contains($xProaspat['corp'], 'name="password"'), $xVechi['cod'] . ' ' . $xVechi['location']);

    // 4. Cererile de dinainte de adoptare nu blochează logarea (codul vertij lăsa contul să intre).
    $dbN->prepare("INSERT INTO account_verifications (user_id, profession_id, profession_name, status, created_at, updated_at) VALUES (?, 1, 'Medic de proba',
        'awaiting_email', '2026-08-01 10:00:00', '2026-08-01 10:00:00')")->execute([$uFara]);
    $emailFara = (string) $dbN->query('SELECT email FROM users WHERE id = ' . (int) $uFara)->fetchColumn();
    $dbN->exec('UPDATE users SET email_confirmed_at = NULL WHERE id = ' . (int) $uFara);
    $c = $sesiune('nou');
    $c('GET', '/login');
    $x = $c('POST', '/login', ['email' => $emailFara, 'password' => $parolaMembri]);
    $verifica('D un cont cu cererea „awaiting_email” de dinainte de adoptare intră (ca pe codul vertij); cererile de după adoptare blochează (mai sus)',
        $x['cod'] === 302 && $x['location'] !== '/login' && $c('GET', '/contul-meu')['cod'] === 200, json_encode($x['location']) ?: '');

    // 5. Decizia adminului doar pe ultima cerere „în verificare”.
    $an = $adminPe('nou');
    $jetonAdmin = static fn (): string => (string) $an('GET', '/admin/accounts/settings')['jeton'];
    $idCerereVeche = 3; // respinsă; contul are acum o cerere nouă (pasul „cererea nouă” din C)
    $inainteDecizie = $starea('nou');
    $x = $an('POST', '/admin/accounts/verifications/' . $idCerereVeche . '/decision', ['decision' => 'approved', 'review_note' => '', '_csrf' => $jetonAdmin()]);
    $idAwaiting = (int) $c1[0]['id'];
    $y = $an('POST', '/admin/accounts/verifications/' . $idAwaiting . '/decision', ['decision' => 'approved', 'review_note' => '', '_csrf' => $jetonAdmin()]);
    $verifica('D decizia pe o cerere veche (înlocuită de una nouă) sau neconfirmată („awaiting_email”): refuzată, nimic schimbat, niciun email',
        $x['cod'] === 302 && $y['cod'] === 302 && $starea('nou') === $inainteDecizie && $emailuri('nou') === []);

    // 6. Donația: suma maximă, destinația din listă, bugetul vizitatorului.
    $dbN->exec("DELETE FROM register_submit_logs WHERE status LIKE 'l:%'");
    $inainteDon = $starea('nou');
    $x1 = $sesiune('nou')('POST', '/api/donations/intent', [], (string) json_encode(['amount' => 100001, 'email' => 'mare@exemplu.ro', 'consent' => true]));
    $x2 = $sesiune('nou')('POST', '/api/donations/intent', [], (string) json_encode(['amount' => 50, 'designation' => '<b>Altceva</b>', 'email' => 'x@exemplu.ro',
        'consent' => true]));
    $verifica('D donația peste suma maximă (100.000 lei) și cu o destinație din afara listei: 422, nicio donație, nicio cerere spre Stripe',
        $x1['cod'] === 422 && $x2['cod'] === 422 && str_contains($x1['corp'], '100.000') && $starea('nou') === $inainteDon && $cereriStripe('nou') === [],
        $x1['corp'] . ' ' . $x2['corp']);
    $dbN->prepare("INSERT INTO register_submit_logs (ip_address, status, created_at) SELECT '127.0.0.1', 'l:donatie', ? FROM information_schema.COLUMNS LIMIT 10")
        ->execute([$oraRo('now')]);
    $x3 = $sesiune('nou')('POST', '/api/donations/intent', [], (string) json_encode(['amount' => 50, 'email' => 'x@exemplu.ro', 'consent' => true]));
    $verifica('D a 11-a donație pornită de același vizitator în 15 minute: 429, nicio donație, nicio cerere spre Stripe', $x3['cod'] === 429
        && $starea('nou') === $inainteDon && $cereriStripe('nou') === [], $x3['cod'] . ' ' . $x3['corp']);
    $dbN->exec("DELETE FROM register_submit_logs WHERE status LIKE 'l:%'");

    // 7. Mai multe donații lunare: fiecare se oprește cu id-ul ei, doar dintre rândurile contului.
    $dbN->exec('UPDATE users SET email_confirmed_at = NOW() WHERE id = ' . (int) $uFara);
    $ins = $dbN->prepare("INSERT INTO donations (reference, user_id, donor_name, donor_email, amount_cents, currency, frequency, status, consent,
        stripe_payment_intent_id, stripe_subscription_id, stripe_customer_id, stripe_invoice_id, paid_at) VALUES (?, ?, 'Fara Cerere', ?, ?, 'ron', ?, 'paid', 1, ?, ?, ?, ?, NOW())");
    $ins->execute(['DON-MULTI001', $uFara, $emailFara, 1500, 'monthly', 'pi_multi_1', 'sub_multi_1', 'cus_multi', 'in_multi_1']);
    $ins->execute(['DON-MULTI002', $uFara, $emailFara, 2200, 'monthly', 'pi_multi_2', 'sub_multi_2', 'cus_multi', 'in_multi_2']);
    // Donația altui cont cu aceeași adresă (user_id al altuia): nu e a acestui cont, chiar cu adresa dovedită.
    $ins->execute(['DON-ALTUL001', $uActiv, $emailFara, 77700, 'once', 'pi_altul_1', null, null, null]);
    $cf = $clientPe('nou', $emailFara);
    $tab = $cf('GET', '/contul-meu?section=donations');
    $verifica('D contul cu două donații lunare active: două carduri „Donația ta lunară”, fiecare cu butonul ei (id-ul abonamentului); donația altui cont '
        . 'cu aceeași adresă nu apare', substr_count($tab['corp'], '<article class="acc-card acc-donation-active">') === 2
        && str_contains($tab['corp'], 'name="subscription_id" value="sub_multi_1"') && str_contains($tab['corp'], 'name="subscription_id" value="sub_multi_2"')
        && !str_contains($tab['corp'], '777,00'), mb_substr((string) strstr($tab['corp'], 'acc-panel-donatii'), 0, 1500));
    $x = $cf('POST', '/contul-meu/donatii/opreste', ['subscription_id' => 'sub_lunar_2', '_csrf' => $tab['jeton']]);
    $straina = $cereriStripe('nou');
    $x = $cf('POST', '/contul-meu/donatii/opreste', ['subscription_id' => 'sub_multi_2', '_csrf' => $tab['jeton']]);
    $oprita = $cereriStripe('nou');
    $anulate = $dbN->query("SELECT stripe_subscription_id FROM donations WHERE stripe_subscription_id IN ('sub_multi_1', 'sub_multi_2') AND cancelled_at IS NOT NULL")
        ->fetchAll(PDO::FETCH_COLUMN);
    $verifica('D oprirea: id-ul unui abonament al altcuiva (sub_lunar_2) refuzat, fără nicio cerere spre Stripe; sub_multi_2 oprit (DELETE la Stripe), '
        . 'sub_multi_1 rămâne activ', $straina === [] && count($oprita) === 1 && $oprita[0]['cerere'] === 'DELETE /v1/subscriptions/sub_multi_2'
        && $anulate === ['sub_multi_2'], json_encode([$straina, $oprita, $anulate]) ?: '');

    // 8. Evenimentele Stripe noi (decizia proprietarului): luna neplătită, prima factură, abonamentul încheiat, rambursările.
    $nr = static fn (string $sql): string => (string) $dbN->query($sql)->fetchColumn();
    $w = $webhook('nou', $eveniment('invoice.payment_failed', ['id' => 'in_multi_1_2', 'object' => 'invoice', 'subscription' => 'sub_multi_1', 'amount_due' => 1500]));
    $esuata = $dbN->query("SELECT status, amount_cents FROM donations WHERE stripe_invoice_id = 'in_multi_1_2'")->fetchAll();
    $w2 = $webhook('nou', $eveniment('invoice.paid', ['id' => 'in_multi_1_2', 'object' => 'invoice', 'subscription' => 'sub_multi_1', 'amount_paid' => 1500,
        'payment_intent' => 'pi_multi_1_2']));
    $platita = $dbN->query("SELECT status FROM donations WHERE stripe_invoice_id = 'in_multi_1_2'")->fetchAll(PDO::FETCH_COLUMN);
    $verifica('D invoice.payment_failed: luna neplătită e un rând „failed”; plătită apoi (Stripe reîncearcă): același rând „paid”, nicio dublură, emailul de mulțumire',
        $w['cod'] === 200 && $esuata === [['status' => 'failed', 'amount_cents' => 1500]] && $w2['cod'] === 200 && $platita === ['paid']
        && count($emailuri('nou')) === 1, json_encode([$w, $esuata, $w2, $platita]) ?: '');
    // Prima factură a unei donații lunare încă neconfirmate: invoice.paid o marchează plătită, în ambele forme ale facturii
    // (versiunea API a contului Stripe: fără Stripe-Version, ca pe vertij, sau cu basil, parent.subscription_details).
    $dbN->exec("INSERT INTO donations (reference, donor_email, amount_cents, currency, frequency, status, consent, stripe_payment_intent_id, stripe_subscription_id,
        stripe_customer_id, stripe_invoice_id) VALUES ('DON-PRIMA001', 'prima1@exemplu.ro', 3000, 'ron', 'monthly', 'pending', 1, 'pi_prima_1', 'sub_prima_1', 'cus_p',
        'in_prima_1'), ('DON-PRIMA002', 'prima2@exemplu.ro', 3000, 'ron', 'monthly', 'pending', 1, 'pi_prima_2', 'sub_prima_2', 'cus_p', 'in_prima_2')");
    $webhook('nou', $eveniment('invoice.paid', ['id' => 'in_prima_1', 'object' => 'invoice', 'subscription' => 'sub_prima_1', 'amount_paid' => 3000,
        'payment_intent' => 'pi_prima_1']));
    $webhook('nou', $eveniment('invoice.paid', ['id' => 'in_prima_2', 'object' => 'invoice', 'parent' => ['subscription_details' => ['subscription' => 'sub_prima_2']],
        'total' => 3000, 'payments' => ['data' => [['payment' => ['payment_intent' => 'pi_prima_2']]]]]));
    $prime = $dbN->query("SELECT reference, status, paid_at FROM donations WHERE reference IN ('DON-PRIMA001', 'DON-PRIMA002') ORDER BY reference")->fetchAll();
    $acumRo = new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest'));
    $ora = static fn (?string $t): int => $t === null ? PHP_INT_MAX : abs((new DateTimeImmutable($t, new DateTimeZone('Europe/Bucharest')))->getTimestamp() - $acumRo->getTimestamp());
    $verifica('D prima factură a donației lunare (invoice.paid, forma veche și forma basil): rândul „pending” devine „paid” (o singură dată), emailul de mulțumire; '
        . 'paid_at în ora României (fusul site-ului pe conexiune, oricare ar fi fusul serverului MySQL)',
        array_column($prime, 'status') === ['paid', 'paid'] && count($emailuri('nou')) === 2 && $ora($prime[0]['paid_at'] ?? null) < 300, json_encode($prime) ?: '');
    $webhook('nou', $eveniment('payment_intent.succeeded', ['id' => 'pi_prima_1', 'object' => 'payment_intent']));
    $verifica('D apoi payment_intent.succeeded pentru aceeași plată: nimic nou, niciun email', $emailuri('nou') === []);
    $webhook('nou', $eveniment('customer.subscription.updated', ['id' => 'sub_prima_1', 'object' => 'subscription', 'status' => 'canceled']));
    $webhook('nou', $eveniment('customer.subscription.updated', ['id' => 'sub_prima_2', 'object' => 'subscription', 'status' => 'past_due']));
    $verifica('D customer.subscription.updated: „canceled” → cancelled_at; „past_due” (Stripe reîncearcă) → rămâne activ',
        $nr("SELECT cancelled_at IS NOT NULL FROM donations WHERE reference = 'DON-PRIMA001'") === '1'
        && $nr("SELECT cancelled_at IS NULL FROM donations WHERE reference = 'DON-PRIMA002'") === '1');
    $webhook('nou', $eveniment('customer.subscription.updated', ['id' => 'sub_prima_2', 'object' => 'subscription', 'status' => 'unpaid']));
    $dupaUnpaid = $nr("SELECT cancelled_at IS NULL FROM donations WHERE reference = 'DON-PRIMA002'");
    $webhook('nou', $eveniment('customer.subscription.updated', ['id' => 'sub_prima_1', 'object' => 'subscription', 'status' => 'active']));
    $verifica('D customer.subscription.updated: „unpaid” nu ascunde donația (Stripe încă o poate încasa); înapoi „active” după „canceled”: cancelled_at golit',
        $dupaUnpaid === '1' && $nr("SELECT cancelled_at IS NULL FROM donations WHERE reference = 'DON-PRIMA001'") === '1');
    $w = $webhook('nou', $eveniment('charge.refunded', ['id' => 'ch_unic_1', 'object' => 'charge', 'payment_intent' => 'pi_unic_1', 'amount' => 10000,
        'amount_refunded' => 3000, 'refunded' => false]));
    $partial = $dbN->query("SELECT status, refunded_cents, refunded_at IS NOT NULL AS la FROM donations WHERE id = 1")->fetch();
    $adminDon = $an('GET', '/admin/donations')['corp'];
    $w2 = $webhook('nou', $eveniment('charge.refunded', ['id' => 'ch_unic_1', 'object' => 'charge', 'payment_intent' => 'pi_unic_1', 'amount' => 10000,
        'amount_refunded' => 10000, 'refunded' => true]));
    $intreaga = $dbN->query("SELECT status, refunded_cents FROM donations WHERE id = 1")->fetch();
    $verifica('D charge.refunded pe o donație: parțial (30 lei) → „paid” cu refunded_cents 3000, „Rambursată parțial” în Admin → Donații; întreagă → „refunded”',
        $w['cod'] === 200 && $partial == ['status' => 'paid', 'refunded_cents' => 3000, 'la' => 1] && str_contains($adminDon, 'Rambursată parțial: 30,00 RON')
        && $w2['cod'] === 200 && $intreaga == ['status' => 'refunded', 'refunded_cents' => 10000]
        && str_contains($an('GET', '/admin/donations?status=refunded')['corp'], 'DON-UNIC0001'), json_encode([$w, $partial, $w2, $intreaga]) ?: '');
    // O rambursare nu se desface: evenimentele întârziate ale aceleiași plăți nu o schimbă și nu trimit alt email.
    $emailuri('nou');
    $webhook('nou', $eveniment('payment_intent.succeeded', ['id' => 'pi_unic_1', 'object' => 'payment_intent', 'metadata' => ['type' => 'donation', 'donation_id' => '1']]));
    $webhook('nou', $eveniment('payment_intent.payment_failed', ['id' => 'pi_unic_1', 'object' => 'payment_intent', 'metadata' => ['type' => 'donation', 'donation_id' => '1']]));
    $verifica('D după rambursare, payment_intent.succeeded și payment_failed întârziate: donația rămâne „refunded”, niciun email',
        $nr('SELECT status FROM donations WHERE id = 1') === 'refunded' && $emailuri('nou') === []);
    // Luna a treia în forma basil, fără plata în webhook: plata cerută la Stripe (expand payments), deci rambursarea ei se găsește.
    $fisierRaspunsuri = $dosareStripe['nou'] . '/raspunsuri.json';
    $raspunsuriNou = (array) json_decode((string) file_get_contents($fisierRaspunsuri), true);
    $raspunsuriNou['GET /v1/invoices/in_multi_1_3'] = ['cod' => 200, 'corp' => ['id' => 'in_multi_1_3', 'object' => 'invoice',
        'payments' => ['data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_multi_1_3']]]]]];
    file_put_contents($fisierRaspunsuri, (string) json_encode($raspunsuriNou));
    $cereriStripe('nou');
    $webhook('nou', $eveniment('invoice.paid', ['id' => 'in_multi_1_3', 'object' => 'invoice', 'parent' => ['subscription_details' => ['subscription' => 'sub_multi_1']],
        'total' => 1500]));
    $cereriFactura = $cereriStripe('nou');
    $piLuna = $nr("SELECT stripe_payment_intent_id FROM donations WHERE stripe_invoice_id = 'in_multi_1_3'");
    $webhook('nou', $eveniment('charge.refunded', ['id' => 'ch_multi_3', 'object' => 'charge', 'payment_intent' => 'pi_multi_1_3', 'amount' => 1500,
        'amount_refunded' => 1500, 'refunded' => true]));
    $emailuri('nou');
    $webhook('nou', $eveniment('invoice.payment_succeeded', ['id' => 'in_multi_1_3', 'object' => 'invoice', 'subscription' => 'sub_multi_1', 'amount_paid' => 1500]));
    $verifica('D luna basil fără plată în webhook: plata cerută la Stripe (GET /v1/invoices/…?expand payments), rambursarea lunii găsită („refunded”), '
        . 'o factură întârziată a aceleiași luni n-o desface și nu trimite email', $piLuna === 'pi_multi_1_3'
        && array_filter(array_column($cereriFactura, 'cerere'), static fn (string $c): bool => str_starts_with($c, 'GET /v1/invoices/in_multi_1_3?')
            && str_contains(urldecode($c), 'expand[0]=payments')) !== []
        && $nr("SELECT status FROM donations WHERE stripe_invoice_id = 'in_multi_1_3'") === 'refunded' && $emailuri('nou') === [],
        json_encode([$piLuna, $cereriFactura]) ?: '');
    $activInainte = $nr('SELECT account_active_until FROM users WHERE id = ' . (int) $uActiv);
    $w = $webhook('nou', $eveniment('charge.refunded', ['id' => 'ch_vechi_1', 'object' => 'charge', 'payment_intent' => 'pi_vechi_1', 'amount' => 12000,
        'amount_refunded' => 12000, 'refunded' => true]));
    $cotizatie = $dbN->query('SELECT status, refunded_cents FROM account_activations WHERE id = 2')->fetch();
    $paginaCerere = $an('GET', '/admin/accounts/verifications/4')['corp'];
    $verifica('D charge.refunded pe o cotizație (fără metadata pe încasare: găsită după plată, proprietarul membri): semnalată în admin („Rambursată”), '
        . 'jurnalul adminului, contul NU se dezactivează', $w['cod'] === 200 && $cotizatie == ['status' => 'paid', 'refunded_cents' => 12000]
        && str_contains($paginaCerere, '>Rambursată</span>') && $nr('SELECT account_active_until FROM users WHERE id = ' . (int) $uActiv) === $activInainte
        && $nr("SELECT COUNT(*) FROM admin_activity_log WHERE action = 'account_activation_refunded'") === '1', json_encode([$w, $cotizatie]) ?: '');

    // 9. Ștergerea contului oprește donațiile lui lunare în Stripe (decizia proprietarului).
    $cereriStripe('nou');
    $x = $an('POST', '/admin/users/delete-selected', ['user_ids' => [(string) $uFara], '_csrf' => $jetonAdmin()]);
    $stergere = $cereriStripe('nou');
    $verifica('D un cont șters din admin: donația lui lunară activă (sub_multi_1) oprită în Stripe (DELETE) și „cancelled_at”; rândurile rămân, dezlegate de cont',
        in_array('DELETE /v1/subscriptions/sub_multi_1', array_column($stergere, 'cerere'), true)
        && !in_array('DELETE /v1/subscriptions/sub_multi_2', array_column($stergere, 'cerere'), true)
        && $nr("SELECT COUNT(*) FROM donations WHERE stripe_subscription_id = 'sub_multi_1' AND cancelled_at IS NOT NULL AND user_id IS NULL") === '3'
        && $nr('SELECT COUNT(*) FROM users WHERE id = ' . (int) $uFara) === '0', json_encode([$x['location'], $stergere]) ?: '');

    // 10. Întoarcerile de la Stripe ale cotizației au un buget pe vizitator.
    $dbN->prepare("INSERT INTO register_submit_logs (ip_address, status, created_at) SELECT '127.0.0.1', 'l:activare_retur', ? FROM information_schema.COLUMNS LIMIT 20")
        ->execute([$oraRo('now')]);
    $x = $sesiune('nou')('GET', '/contul-meu/activare/retur?session_id=cs_proba_nou');
    $verifica('D a 21-a întoarcere de la Stripe în 15 minute: fără cerere spre Stripe (webhook-ul notează plata), 302 la cont', $x['cod'] === 302
        && $cereriStripe('nou') === []);
    $dbN->exec("DELETE FROM register_submit_logs WHERE status LIKE 'l:%'");

    // Pluginurile oprite.
    $r = $php('nou', 'tools/dev/licenta.php', ['vertij', 'donatii=oprit', 'membri=oprit']);
    $verifica('D licența vertij cu donatii și membri oprite', $r['cod'] === 0, $r['tot']);
    $an = $adminPe('nou');
    foreach (['/admin/donations', '/admin/settings/donations', '/admin/accounts/verifications', '/admin/accounts/professions', '/admin/accounts/settings'] as $cale) {
        $x = $an('GET', $cale);
        $verifica('D pluginul oprit: ' . $cale . ' → 403 „Modulul nu este inclus în abonament”', $x['cod'] === 403 && str_contains($x['corp'], 'nu este inclus'));
    }
    $x = $an('GET', '/admin/users');
    $verifica('D pluginul oprit: meniul fără „Donații”, „Cereri conturi”, „Tipuri de cont”; lista utilizatorilor fără coloanele cotizației', $x['cod'] === 200
        && !str_contains($x['corp'], 'href="/admin/donations"') && !str_contains($x['corp'], 'href="/admin/accounts/verifications"')
        && !str_contains($x['corp'], 'admin-table-scroll'));
    $x = $sesiune('nou')('POST', '/api/donations/intent', [], (string) json_encode(['amount' => 50, 'email' => 'x@exemplu.ro', 'consent' => true]));
    $verifica('D pluginul oprit: /api/donations/intent → 404 JSON', $x['cod'] === 404 && str_contains($x['tip'], 'json'));
    $x = $sesiune('nou')('GET', '/');
    $verifica('D pluginul oprit: fără sertarul „SUSȚINE”', $x['cod'] === 200 && !str_contains($x['corp'], 'Sertar SUSȚINE'));
    $inainteBani = $conectare($baze['nou'])->query("SELECT COUNT(*) FROM donations WHERE stripe_subscription_id = 'sub_lunar_1'")->fetchColumn();
    $w = $webhook('nou', $eveniment('invoice.paid', ['id' => 'in_lunar_1_4', 'object' => 'invoice', 'subscription' => 'sub_lunar_1', 'amount_paid' => 2577]));
    $dupaBani = $conectare($baze['nou'])->query("SELECT COUNT(*) FROM donations WHERE stripe_subscription_id = 'sub_lunar_1'")->fetchColumn();
    $verifica('D pluginul oprit: o lună plătită a unei donații lunare se notează mereu (200, un rând nou), fără email', $w['cod'] === 200
        && (int) $dupaBani === (int) $inainteBani + 1 && $emailuri('nou') === []);
    $x = $sesiune('nou')('GET', '/ghid-medici-proba');
    $verifica('D pluginul membri oprit: pagina doar pentru membri dă 404 (regula nucleului, AccesMembri)', $x['cod'] === 404);
    $x = $sesiune('nou')('GET', '/contul-meu/activare/retur?session_id=cs_proba_nou');
    $verifica('D pluginul oprit: întoarcerea de la Stripe rămâne deschisă (302 la cont)', $x['cod'] === 302);
} catch (Throwable $e) {
    $verifica('testul a rulat până la capăt', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

$opresteServerele();
// Fișierele scrise în codul comun de test (documentul, pozele de profil): doar ale testului.
@unlink($radacina . '/storage/account-documents/20260902-proba0000000000000000000000000001.pdf');
foreach (glob($radacina . '/public/uploads/gallery/profile/membru-*') ?: [] as $f) {
    @unlink($f);
}
$scrie('');
$scrie('Rezultat: ' . $trecute . ' trecute, ' . $esuate . ' eșuate. Raport: ' . $fisierRaport);
exit($esuate === 0 ? 0 : 1);
