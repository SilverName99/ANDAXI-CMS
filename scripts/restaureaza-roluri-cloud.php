<?php

declare(strict_types=1);

/**
 * Pune la loc rolul „Cloud user” (`utilizator_cloud`) conturilor pe care adoptarea unui site cu pluginul `fisiere_cloud` l-a
 * scris peste (adoptarea din 5 nov. 2026, singurul site cu rolul): migrarea de date nucleu/0020 (rolurile adminilor) știa doar
 * cele patru roluri ale nucleului, deci un cont „Cloud user” a devenit Administrator General (docs/PLUGINURI.md, „Fișiere în
 * cloud” → „Rolul Cloud user după adoptare”). 0020 e publicată și aplicată, deci nu se mai schimbă (codul 12) și nu mai rulează
 * pe baza asta; codul de azi (Auth::roles) știe rolul.
 *
 *   php bin/platforma --site=exemplu.ro scripts/restaureaza-roluri-cloud.php \
 *       --copie=<lucru>/copii/<data>/baza-inainte.sql.gz              # simulare: arată ce ar face, nu scrie nimic
 *   php bin/platforma --site=exemplu.ro scripts/restaureaza-roluri-cloud.php \
 *       --copie=<…>/baza-inainte.sql.gz --aplica                       # scrie
 *
 * Opțiuni: --copie=<fișier> (copia bazei de dinainte de adoptare, .sql.gz sau .sql, făcută de deploy/adopta.php),
 * --aplica (fără ea doar simulează), --din="2026-11-05 20:00:00" (de când se socotesc cheile de aplicație „de după adoptare”).
 *
 * Ce face:
 *  - citește din copie DOAR rândurile tabelului `admins` (o trece rând cu rând, fără s-o încarce în memorie sau în bază; restul
 *    tabelelor se sar) și ține doar email, role și roles_json; hash-urile parolelor nu se păstrează și nu se afișează;
 *  - pentru fiecare email care avea atunci `utilizator_cloud` (în role sau în roles_json) și există și acum, compară rândul de
 *    acum cu ce ar fi scris 0020 din rândul vechi. Dacă e același, rândul n-a fost atins de la adoptare: se pune la loc
 *    exact role și roles_json de atunci („email: acum <roluri> → <roluri vechi>”). Dacă diferă, cineva l-a schimbat de mână
 *    după adoptare: nu se atinge, doar se arată. Conturile care nu mai există se arată și ele;
 *  - cheile de aplicație (admin_app_tokens) ale acestor conturi făcute după --din: cu --aplica se retrag, altfel se arată
 *    (cât contul a fost Administrator General, cheia lui avea și drepturile acelea);
 *  - cu --aplica, totul într-o singură tranzacție: fiecare UPDATE cere ca rândul să fie încă exact cel citit (altfel nimic nu
 *    se scrie, cod 1), plus un rând în Jurnal activitate (`roluri_cloud_restaurate`).
 *
 * Copia: un mysqldump (cu INSERT-uri lungi sau rând cu rând, cu sau fără lista coloanelor); ordinea coloanelor se ia din
 * structura tabelului scrisă în copie. Rulat a doua oară nu mai are ce face (rândurile sunt deja la loc). Sesiunile deja deschise ale acestor conturi țin rolul
 * din momentul logării până la delogare (sau 12 ore fără activitate): după --aplica, oamenii se deloghează și se loghează din
 * nou. Coduri: 0 = gata (sau nimic de făcut), 1 = n-a mers, 2 = argumente greșite.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\AdminActivityLog;
use App\Support\Database;

const ROL_CLOUD = 'utilizator_cloud';

$copie = null;
$aplica = false;
$din = '2026-11-05 20:00:00';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--copie=')) {
        $copie = substr($argument, 8);
    } elseif ($argument === '--aplica') {
        $aplica = true;
    } elseif (str_starts_with($argument, '--din=')) {
        $din = substr($argument, 6);
    } else {
        fwrite(STDERR, 'argument necunoscut: ' . $argument . " (vezi începutul fișierului)\n");
        exit(2);
    }
}
if ($copie === null || $copie === '') {
    fwrite(STDERR, "--copie=<baza-inainte.sql.gz> lipsește\n");
    exit(2);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $din) !== 1 || strtotime($din) === false) {
    fwrite(STDERR, "--din= trebuie să fie o dată, ca „2026-11-05 20:00:00”\n");
    exit(2);
}
if (!is_file($copie) || !is_readable($copie)) {
    fwrite(STDERR, 'copia nu există sau nu se poate citi: ' . $copie . "\n");
    exit(2);
}

/**
 * Rândurile tabelului `admins` din copie: [email mic => [email, role, roles_json]]. Citește linie cu linie (gzopen merge și pe
 * un .sql necomprimat); doar structura tabelului `admins` (ordinea coloanelor) și INSERT INTO `admins` se despart, restul se sare.
 *
 * @return array<string, array{email: string, role: ?string, roles_json: ?string}>
 */
function citesteAdminii(string $fisier): array
{
    $f = gzopen($fisier, 'rb');
    if ($f === false) {
        throw new RuntimeException('nu pot deschide copia');
    }
    $coloane = null;
    $inCreare = false;
    $randuri = [];
    $instructiune = '';
    try {
        while (($linie = gzgets($f)) !== false) {
            if ($instructiune !== '') {
                $instructiune .= $linie;
                if (strlen($instructiune) > 256 * 1024 * 1024) {
                    throw new RuntimeException('o instrucțiune INSERT pentru admins e prea lungă');
                }
                if (str_ends_with(rtrim($linie), ';')) {
                    adaugaRanduri($instructiune, $coloane, $randuri);
                    $instructiune = '';
                }
                continue;
            }
            if ($inCreare) {
                if (preg_match('/^\s*`([^`]+)`\s/', $linie, $m) === 1) {
                    $coloane[] = $m[1];
                } elseif (str_starts_with(ltrim($linie), ')')) {
                    $inCreare = false;
                }
                continue;
            }
            if (preg_match('/^CREATE\s+TABLE\s+`admins`\s*\(/', $linie) === 1) {
                $inCreare = true;
                $coloane = [];
                continue;
            }
            if (str_starts_with($linie, 'INSERT INTO `admins` ')) {
                if (str_ends_with(rtrim($linie), ';')) {
                    adaugaRanduri($linie, $coloane, $randuri);
                } else {
                    $instructiune = $linie;
                }
            }
        }
    } finally {
        gzclose($f);
    }
    if ($instructiune !== '') {
        throw new RuntimeException('copia se termină în mijlocul unui INSERT pentru admins (e întreagă?)');
    }
    if ($coloane === null) {
        throw new RuntimeException('copia nu are structura tabelului admins (e o copie mysqldump a bazei?)');
    }

    return $randuri;
}

/**
 * Desparte un INSERT INTO `admins` [(`col`, …)] VALUES (…),(…); și păstrează doar email, role, roles_json.
 *
 * @param list<string>|null $coloane
 * @param array<string, array{email: string, role: ?string, roles_json: ?string}> $randuri
 */
function adaugaRanduri(string $sql, ?array $coloane, array &$randuri): void
{
    $poz = strlen('INSERT INTO `admins` ');
    if (substr($sql, $poz, 1) === '(') {
        $sfarsit = strpos($sql, ')', $poz);
        if ($sfarsit === false) {
            throw new RuntimeException('INSERT pentru admins fără sfârșitul listei de coloane');
        }
        preg_match_all('/`([^`]+)`/', substr($sql, $poz, $sfarsit - $poz), $m);
        $coloane = $m[1];
        $poz = $sfarsit + 1;
    }
    if ($coloane === null || $coloane === []) {
        throw new RuntimeException('INSERT pentru admins înaintea structurii tabelului (nu știu ordinea coloanelor)');
    }
    $iEmail = array_search('email', $coloane, true);
    $iRol = array_search('role', $coloane, true);
    $iJson = array_search('roles_json', $coloane, true);
    if ($iEmail === false || $iRol === false) {
        throw new RuntimeException('tabelul admins din copie nu are coloanele email și role');
    }
    $poz = stripos($sql, 'VALUES', $poz);
    if ($poz === false) {
        throw new RuntimeException('INSERT pentru admins fără VALUES');
    }
    $poz += 6;
    $lungime = strlen($sql);
    while (true) {
        while ($poz < $lungime && ctype_space($sql[$poz])) {
            $poz++;
        }
        if ($poz >= $lungime || $sql[$poz] === ';') {
            return;
        }
        if ($sql[$poz] === ',') {
            $poz++;
            continue;
        }
        if ($sql[$poz] !== '(') {
            throw new RuntimeException('INSERT pentru admins: aștept „(” la poziția ' . $poz);
        }
        $poz++;
        $valori = [];
        while (true) {
            while ($poz < $lungime && ctype_space($sql[$poz])) {
                $poz++;
            }
            $valori[] = citesteValoare($sql, $poz);
            while ($poz < $lungime && ctype_space($sql[$poz])) {
                $poz++;
            }
            $c = $sql[$poz] ?? '';
            $poz++;
            if ($c === ')') {
                break;
            }
            if ($c !== ',') {
                throw new RuntimeException('INSERT pentru admins: aștept „,” sau „)” la poziția ' . ($poz - 1));
            }
        }
        if (count($valori) !== count($coloane)) {
            throw new RuntimeException('INSERT pentru admins: ' . count($valori) . ' valori pentru ' . count($coloane) . ' coloane');
        }
        $email = trim((string) $valori[$iEmail]);
        if ($email !== '') {
            $randuri[mb_strtolower($email)] = [
                'email' => $email,
                'role' => $valori[$iRol],
                'roles_json' => $iJson !== false ? $valori[$iJson] : null,
            ];
        }
    }
}

/** O valoare din lista VALUES: 'șir' (cu escape-urile mysqldump), NULL, un număr, 0x… (hex-blob), _binary '…'. */
function citesteValoare(string $sql, int &$poz): ?string
{
    if (substr($sql, $poz, 8) === '_binary ') {
        $poz += 8;
    }
    $c = $sql[$poz] ?? '';
    if ($c === "'") {
        $poz++;
        $text = '';
        $lungime = strlen($sql);
        while ($poz < $lungime) {
            $ch = $sql[$poz];
            if ($ch === '\\') {
                $urm = $sql[$poz + 1] ?? '';
                $text .= match ($urm) {
                    '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1A",
                    default => $urm,
                };
                $poz += 2;
                continue;
            }
            if ($ch === "'") {
                if (($sql[$poz + 1] ?? '') === "'") {
                    $text .= "'";
                    $poz += 2;
                    continue;
                }
                $poz++;
                return $text;
            }
            $text .= $ch;
            $poz++;
        }
        throw new RuntimeException('INSERT pentru admins: șir neterminat');
    }
    $start = $poz;
    while ($poz < strlen($sql) && $sql[$poz] !== ',' && $sql[$poz] !== ')') {
        $poz++;
    }
    $brut = trim(substr($sql, $start, $poz - $start));
    if (strcasecmp($brut, 'NULL') === 0) {
        return null;
    }
    if (preg_match('/^0x([0-9A-Fa-f]*)$/', $brut, $m) === 1) {
        return (string) hex2bin(strlen($m[1]) % 2 === 1 ? '0' . $m[1] : $m[1]);
    }

    return $brut;
}

/** Rolurile scrise în role / roles_json, așa cum sunt (pentru afișare și pentru căutarea lui utilizator_cloud). @return list<string> */
function roluriBrute(?string $rol, ?string $json): array
{
    $roluri = [];
    if (is_string($json) && trim($json) !== '') {
        $decodat = json_decode($json, true);
        if (is_array($decodat)) {
            foreach ($decodat as $r) {
                if (is_scalar($r) && trim((string) $r) !== '') {
                    $roluri[] = trim((string) $r);
                }
            }
        }
    }
    if ($roluri === [] && $rol !== null && trim($rol) !== '') {
        $roluri[] = trim($rol);
    }

    return array_values(array_unique($roluri));
}

/**
 * Ce ar fi scris nucleu/0020 dintr-un rând (aceeași regulă, copiată de acolo: cele patru roluri ale nucleului de atunci).
 *
 * @return array{role: string, roles_json: string}
 */
function dupa0020(?string $role, ?string $rolesJson): array
{
    $general = 'administrator_general';
    $cunoscute = [$general, 'administrator_magazin', 'administrator_blog', 'administrator_emailuri'];
    $prioritate = [$general, 'administrator_emailuri', 'administrator_magazin', 'administrator_blog'];
    // 0020 întreabă serverul (role IS NULL OR role = '', cu colația PAD SPACE a coloanei); aici: gol sau doar spații.
    $vechi = $role === null || rtrim($role, ' ') === '' ? $general : $role;
    $roluri = [];
    if (is_string($rolesJson) && trim($rolesJson) !== '') {
        $decodat = json_decode($rolesJson, true);
        if (is_array($decodat)) {
            $roluri = $decodat;
        }
    }
    $roluri[] = $vechi;
    $normalizate = [];
    foreach ($roluri as $rol) {
        $rol = is_scalar($rol) ? trim((string) $rol) : '';
        if (in_array($rol, $cunoscute, true) && !in_array($rol, $normalizate, true)) {
            $normalizate[] = $rol;
        }
    }
    if ($normalizate === []) {
        $normalizate[] = in_array(trim($vechi), $cunoscute, true) ? trim($vechi) : $general;
    }
    $principal = $normalizate[0];
    foreach ($prioritate as $rol) {
        if (in_array($rol, $normalizate, true)) {
            $principal = $rol;
            break;
        }
    }

    return ['role' => $principal, 'roles_json' => (string) json_encode($normalizate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

$arata = static fn (?string $rol, ?string $json): string => implode(', ', roluriBrute($rol, $json)) ?: '(niciunul)';

try {
    $vechi = citesteAdminii($copie);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Copia nu se poate citi: ' . $e->getMessage() . "\n");
    exit(1);
}
$cuCloud = array_filter($vechi, static fn (array $r): bool => in_array(ROL_CLOUD, roluriBrute($r['role'], $r['roles_json']), true));

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection((array) ($config['db'] ?? []));
if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibilă.\n");
    exit(1);
}

echo 'mod      : ' . ($aplica ? 'aplică (scrie în bază)' : 'simulare (nu scrie nimic; --aplica scrie)') . "\n";
echo 'copie    : ' . $copie . ' (' . count($vechi) . ' administratori, ' . count($cuCloud) . ' cu „Cloud user”)' . "\n";

$citeste = $db->prepare('SELECT id, email, role, roles_json FROM admins WHERE LOWER(email) = :email LIMIT 1');
$dePus = [];
$deMana = [];
$dejaLaLoc = [];
$lipsa = [];
foreach ($cuCloud as $cheie => $rand) {
    $citeste->execute(['email' => $cheie]);
    $acum = $citeste->fetch(PDO::FETCH_ASSOC);
    if (!is_array($acum)) {
        $lipsa[] = $rand['email'];
        continue;
    }
    $acumRol = $acum['role'] !== null ? (string) $acum['role'] : null;
    $acumJson = $acum['roles_json'] !== null ? (string) $acum['roles_json'] : null;
    if ($acumRol === $rand['role'] && $acumJson === $rand['roles_json']) {
        $dejaLaLoc[] = $rand['email'];
        continue;
    }
    $asteptat = dupa0020($rand['role'], $rand['roles_json']);
    $intrare = ['id' => (int) $acum['id'], 'email' => (string) $acum['email'], 'acum_rol' => $acumRol, 'acum_json' => $acumJson,
        'vechi_rol' => $rand['role'], 'vechi_json' => $rand['roles_json']];
    if ($acumRol === $asteptat['role'] && $acumJson === $asteptat['roles_json']) {
        $dePus[] = $intrare;
    } else {
        $deMana[] = $intrare;
    }
}

echo "\n";
echo 'De pus la loc (' . count($dePus) . '):' . "\n";
foreach ($dePus as $i) {
    echo '  ' . $i['email'] . ': acum ' . $arata($i['acum_rol'], $i['acum_json']) . ' → ' . $arata($i['vechi_rol'], $i['vechi_json']) . "\n";
}
if ($deMana !== []) {
    echo 'Schimbate de mână după adoptare, rămân cum sunt (' . count($deMana) . '):' . "\n";
    foreach ($deMana as $i) {
        echo '  ' . $i['email'] . ': acum ' . $arata($i['acum_rol'], $i['acum_json']) . ' (înainte de adoptare: '
            . $arata($i['vechi_rol'], $i['vechi_json']) . ')' . "\n";
    }
}
if ($dejaLaLoc !== []) {
    echo 'Deja la loc (' . count($dejaLaLoc) . '): ' . implode(', ', $dejaLaLoc) . "\n";
}
if ($lipsa !== []) {
    echo 'Nu mai există (' . count($lipsa) . '): ' . implode(', ', $lipsa) . "\n";
}

// Cheile de aplicație făcute după adoptare de conturile puse la loc (cât au fost Administrator General).
$chei = [];
if ($dePus !== []) {
    $iduri = array_map(static fn (array $i): int => $i['id'], $dePus);
    $semne = implode(', ', array_fill(0, count($iduri), '?'));
    try {
        $s = $db->prepare('SELECT t.id, t.admin_id, t.name, t.created_at, a.email FROM admin_app_tokens t JOIN admins a ON a.id = t.admin_id
            WHERE t.admin_id IN (' . $semne . ') AND t.revoked_at IS NULL AND t.created_at >= ? ORDER BY t.id');
        $s->execute([...$iduri, $din]);
        $chei = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $chei = [];
        echo 'Cheile de aplicație: tabelul admin_app_tokens nu se poate citi (pluginul fisiere_cloud n-a ajuns pe bază?)' . "\n";
    }
}
echo 'Chei de aplicație făcute după ' . $din . ' de aceste conturi (' . count($chei) . ')'
    . ($chei !== [] ? ($aplica ? ', se retrag:' : ', de retras cu --aplica:') : '') . "\n";
foreach ($chei as $c) {
    echo '  ' . $c['email'] . ': „' . $c['name'] . '” (#' . $c['id'] . '), făcută ' . $c['created_at'] . "\n";
}

if (!$aplica || ($dePus === [] && $chei === [])) {
    echo "\n" . ($aplica ? 'Nimic de scris.' : 'Simulare: nimic scris.') . "\n";
    exit(0);
}

try {
    $db->beginTransaction();
    $scrie = $db->prepare('UPDATE admins SET role = :rol, roles_json = :json
        WHERE id = :id AND role <=> :acum_rol AND roles_json <=> :acum_json');
    foreach ($dePus as $i) {
        $scrie->execute(['rol' => $i['vechi_rol'] ?? 'administrator_general', 'json' => $i['vechi_json'], 'id' => $i['id'],
            'acum_rol' => $i['acum_rol'], 'acum_json' => $i['acum_json']]);
        if ($scrie->rowCount() !== 1) {
            throw new RuntimeException($i['email'] . ' s-a schimbat între citire și scriere');
        }
    }
    $retrase = 0;
    if ($chei !== []) {
        $retrage = $db->prepare('UPDATE admin_app_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
        foreach ($chei as $c) {
            $retrage->execute([(int) $c['id']]);
            $retrase += $retrage->rowCount();
        }
    }
    AdminActivityLog::log($db, 'roluri_cloud_restaurate', [
        'conturi' => array_map(static fn (array $i): string => $i['email'], $dePus),
        'chei_retrase' => array_map(static fn (array $c): int => (int) $c['id'], $chei),
        'copie' => basename($copie),
    ]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Nimic scris: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\n" . 'Scris: ' . count($dePus) . ' conturi puse la loc, ' . $retrase . ' chei retrase. Cei cu sesiunea deschisă se deloghează și se loghează din nou.' . "\n";
exit(0);
