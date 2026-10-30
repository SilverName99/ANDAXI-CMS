<?php

declare(strict_types=1);

/**
 * Unde ajung emailurile trimise de site și dacă pot pleca (WP-26: GR-21, din grafoanaytis `scripts/verifica-email.php`,
 * `fe0c041`; generic, fără adresele unui client scrise în cod). Rulează pe server, pe baza site-ului: valorile din tabelul
 * `settings` câștigă oricum asupra celor implicite din cod.
 *
 *   php scripts/verifica-email.php                                         # doar arată
 *   php scripts/verifica-email.php --dns                                   # și verifică domeniul expeditorului (MX sau A)
 *   php scripts/verifica-email.php --repara --destinatari=a@x.ro,b@x.ro     # scrie contact_form_recipients
 *   php scripts/verifica-email.php --repara --expeditor=noreply@x.ro       # scrie order_email_from_address
 *
 * Verifică: destinatarii formularului de contact (și ai cererilor de ofertă) sunt adrese valide; expeditorul
 * (`order_email_from_address`) e o adresă adevărată (nu `@localhost`, nu un domeniu de exemplu); un cont SMTP pe alt
 * domeniu decât expeditorul (avertismentul DMARC); ultimele 5 trimiteri, doar starea, data și destinatarul ascuns
 * (`a***@domeniu`). Adresele se arată și ele ascunse. Parola SMTP nu se afișează și nu se scrie niciodată (doar dacă e
 * pusă). `--repara` ia valorile doar din parametri. Coduri: 0 = în regulă, 1 = probleme găsite, 2 = argumente greșite
 * sau fără bază. Îl poate chema pasul de sănătate al orchestratorului.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Brand;
use App\Support\Database;
use App\Support\Settings;

/** `ana.pop@exemplu.ro` → `a***@exemplu.ro`. */
function ascunde(string $adresa): string
{
    $adresa = trim($adresa);
    $poz = strrpos($adresa, '@');
    if ($poz === false || $poz === 0) {
        return $adresa === '' ? '' : '***';
    }
    return mb_substr($adresa, 0, 1) . '***' . substr($adresa, $poz);
}

function domeniu(string $adresa): string
{
    $poz = strrpos($adresa, '@');
    return $poz === false ? '' : strtolower(trim(substr($adresa, $poz + 1)));
}

$repara = false;
$dns = false;
$destinatariNoi = null;
$expeditorNou = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--repara') {
        $repara = true;
    } elseif ($argument === '--dns') {
        $dns = true;
    } elseif (str_starts_with($argument, '--destinatari=')) {
        $destinatariNoi = substr($argument, 14);
    } elseif (str_starts_with($argument, '--expeditor=')) {
        $expeditorNou = trim(substr($argument, 12));
    } else {
        fwrite(STDERR, 'argument necunoscut: ' . $argument . " (vezi începutul fișierului)\n");
        exit(2);
    }
}
if ($repara && $destinatariNoi === null && $expeditorNou === null) {
    fwrite(STDERR, "--repara cere --destinatari=… sau --expeditor=… (valorile se dau aici, nu sunt scrise în cod)\n");
    exit(2);
}
if (!$repara && ($destinatariNoi !== null || $expeditorNou !== null)) {
    fwrite(STDERR, "--destinatari și --expeditor merg doar cu --repara\n");
    exit(2);
}
if ($destinatariNoi !== null && Brand::adrese($destinatariNoi) === []) {
    fwrite(STDERR, "--destinatari: nicio adresă validă\n");
    exit(2);
}
if ($expeditorNou !== null && !filter_var($expeditorNou, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "--expeditor: nu e o adresă validă\n");
    exit(2);
}

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibilă.\n");
    exit(2);
}

if ($repara) {
    $scrie = [];
    if ($destinatariNoi !== null) {
        $scrie['contact_form_recipients'] = implode(', ', Brand::adrese($destinatariNoi));
    }
    if ($expeditorNou !== null) {
        $scrie['order_email_from_address'] = $expeditorNou;
    }
    Settings::save($db, $scrie);
    foreach ($scrie as $cheie => $valoare) {
        echo 'scris: ' . $cheie . ' = ' . implode(', ', array_map('ascunde', Brand::adrese($valoare))) . "\n";
    }
    echo "\n";
}

$setari = Settings::all($db);
$probleme = [];

echo "── Unde ajung mesajele ──\n";
$brut = trim((string) ($setari['contact_form_recipients'] ?? ''));
$valide = Brand::adrese($brut);
$bucati = array_values(array_filter(preg_split('/[\s,;]+/', $brut) ?: [], static fn (string $b): bool => trim($b) !== ''));
$invalide = count($bucati) - count($valide);
printf("  %-26s %s\n", 'contact_form_recipients', $valide === [] ? '(nicio adresă validă)' : implode(', ', array_map('ascunde', $valide)));
if ($invalide > 0) {
    $probleme[] = $invalide . ' din adresele din contact_form_recipients nu sunt valide (se sar la trimitere).';
}
if ($valide === []) {
    $rezerva = Brand::emailContact($db, $setari);
    printf("  %-26s %s\n", 'rezerva (adresa de contact)', $rezerva !== '' ? ascunde($rezerva) : '(niciuna)');
    $probleme[] = $rezerva === ''
        ? 'Nicio adresă primește mesajele formularului de contact și cererile de ofertă: rămân doar în admin.'
        : 'contact_form_recipients nu are nicio adresă validă: mesajele merg la adresa de contact a site-ului.';
}

echo "\n── Dacă pot pleca ──\n";
$metoda = strtolower(trim((string) ($setari['email_delivery_method'] ?? 'smtp')));
$gazda = trim((string) ($setari['smtp_host'] ?? ''));
$utilizator = trim((string) ($setari['smtp_username'] ?? ''));
$parolaPusa = trim((string) ($setari['smtp_password'] ?? '')) !== '';
$expeditor = trim((string) ($setari['order_email_from_address'] ?? ''));
printf("  %-26s %s\n", 'email_delivery_method', $metoda !== '' ? $metoda : '(nesetat)');
printf("  %-26s %s\n", 'smtp_host', $gazda !== '' ? $gazda : '(gol)');
printf("  %-26s %s\n", 'smtp_username', $utilizator !== '' ? ascunde($utilizator) : '(gol)');
printf("  %-26s %s\n", 'smtp_password', $parolaPusa ? '(pusă)' : '(goală)');
printf("  %-26s %s\n", 'order_email_from_address', $expeditor !== '' ? ascunde($expeditor) : '(gol)');

if ($metoda === 'smtp' && $gazda === '') {
    $probleme[] = 'Metoda e „smtp”, dar nu există server SMTP: mesajele cad pe funcția mail() a serverului, care le poate pierde fără urmă.';
}
if ($metoda === 'smtp' && $gazda !== '' && ($utilizator === '' || !$parolaPusa)) {
    $probleme[] = 'Serverul SMTP e scris, dar lipsește utilizatorul sau parola: autentificarea eșuează la fiecare mesaj.';
}
$domeniuExpeditor = domeniu($expeditor);
if ($expeditor === '' || !filter_var($expeditor, FILTER_VALIDATE_EMAIL) || $domeniuExpeditor === 'localhost'
    || !str_contains($domeniuExpeditor, '.') || preg_match('/(^|\.)(example|exemplu|test|invalid|local)(\.[a-z]+)?$/', $domeniuExpeditor) === 1) {
    $probleme[] = 'Expeditorul (order_email_from_address) nu e o adresă adevărată: multe servere resping mesajul înainte să-l vadă cineva.';
} elseif ($dns && !checkdnsrr($domeniuExpeditor, 'MX') && !checkdnsrr($domeniuExpeditor, 'A')) {
    $probleme[] = 'Domeniul expeditorului nu are înregistrări MX sau A (--dns).';
}
$domeniuSmtp = domeniu($utilizator);
if ($metoda === 'smtp' && $domeniuSmtp !== '' && $domeniuExpeditor !== '' && $domeniuSmtp !== $domeniuExpeditor) {
    // GR fe0c041: plicul poartă contul SMTP, antetul From adresa expeditorului; pe domenii diferite, DMARC-ul domeniului
    // din From hotărăște soarta mesajului (de exemplu yahoo.com îl aruncă).
    $probleme[] = 'Contul SMTP (@' . $domeniuSmtp . ') e pe alt domeniu decât expeditorul (@' . $domeniuExpeditor . '): mesajul trece pe '
        . 'mâna DMARC-ului domeniului expeditorului și poate ajunge în spam sau deloc. Cel mai sigur, expeditorul e pe domeniul contului SMTP.';
}

echo "\n── Ultimele 5 trimiteri ──\n";
try {
    $istoric = $db->query('SELECT recipient, email_type, status, created_at FROM email_send_history ORDER BY id DESC LIMIT 5')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($istoric === []) {
        echo "  Nicio trimitere înregistrată încă.\n";
    }
    foreach ($istoric as $rand) {
        printf("  %s  %-8s %-30s %s\n", (string) $rand['created_at'], (string) $rand['status'], (string) $rand['email_type'],
            ascunde((string) $rand['recipient']));
    }
} catch (Throwable) {
    echo "  Istoricul trimiterilor nu se poate citi.\n";
}

echo "\n";
if ($probleme === []) {
    echo "Configurația de trimitere pare în regulă.\n";
    exit(0);
}
foreach ($probleme as $i => $problema) {
    echo ($i + 1) . '. ' . $problema . "\n";
}
exit(1);
