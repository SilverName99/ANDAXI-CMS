<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use App\Support\DateSite;
use PDO;
use PDOException;

/**
 * Ce au în comun cele două scripturi care rulează migrările: `scripts/migreaza.php` (linia de
 * comandă a runner-ului) și `scripts/install.php` (o bază nouă = migrările, apoi seed-ul neutru și
 * primul administrator). Aceeași conexiune, aceleași opțiuni din mediu, același raport, ca o
 * instalare să nu poată face altceva decât face `migreaza.php --aplica`.
 */
final class Consola
{
    /**
     * Conexiunea runner-ului: aceleași setări ca Database::connection (utf8mb4, excepții, fusul orar
     * al aplicației), dar cu eroarea spusă pe nume (fără DSN, utilizator sau parolă), serverul
     * verificat și un `lock_wait_timeout` scurt: un ALTER care așteaptă un tabel ocupat (de exemplu
     * un export lung) nu are voie să țină coada cererilor site-ului în spatele lui (implicit pe
     * server: o zi).
     *
     * @param array<string, mixed> $db `config/app.php` → db
     * @return array{0: PDO, 1: Server}
     * @throws Eroare codul 2 (configurare, conexiune, server prea vechi)
     */
    public static function conecteaza(array $db, int $asteptare = 5): array
    {
        if (trim((string) ($db['database'] ?? '')) === '') {
            throw new Eroare('Baza de date nu e configurată (DB_DATABASE lipsește din .env).', Eroare::CONFIGURARE);
        }
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($db['host'] ?? ''), (int) ($db['port'] ?? 3306),
                    (string) $db['database']),
                (string) ($db['username'] ?? ''),
                (string) ($db['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            $cod = (int) ($e->errorInfo[1] ?? 0);
            if ($cod === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1) {
                $cod = (int) $m[1];
            }
            $motiv = match ($cod) {
                1045, 1698 => 'acces refuzat: utilizatorul sau parola din .env',
                1044 => 'utilizatorul nu are drept pe această bază',
                1049 => 'baza din DB_DATABASE nu există',
                2002, 2003, 2005, 2006 => 'serverul nu răspunde la adresa din DB_HOST/DB_PORT (sau la socket)',
                default => 'eroarea ' . ($cod ?: '?'),
            };
            throw new Eroare('Nu mă pot conecta la baza de date (' . $motiv . ').', Eroare::CONFIGURARE);
        }
        try {
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException) {
            // La fel ca aplicația: rămâne fusul serverului.
        }
        $server = Server::detecteaza($pdo);
        if (!$server->suportat()) {
            throw new Eroare('Serverul ' . $server->nume() . ' e prea vechi: minimul e MariaDB 10.3 sau MySQL 8.0 (docs/MIGRARI.md).',
                Eroare::CONFIGURARE);
        }
        $pdo->exec('SET SESSION lock_wait_timeout = ' . $asteptare . ', SESSION innodb_lock_wait_timeout = ' . $asteptare);
        return [$pdo, $server];
    }

    /**
     * Site-ul ai cărui pași rulează: `--site`, altfel SITE_SLUG din .env; cele două nu au voie să se
     * contrazică. Null = fără pașii vreunui site.
     *
     * @throws Eroare codul 2
     */
    public static function site(?string $dinArgument): ?string
    {
        $dinEnv = trim((string) (getenv('SITE_SLUG') ?: ''));
        if ($dinArgument !== null && $dinEnv !== '' && $dinEnv !== $dinArgument) {
            throw new Eroare('--site=' . $dinArgument . ', dar SITE_SLUG din .env este „' . $dinEnv . '”; refuz să rulez pașii altui site.',
                Eroare::CONFIGURARE);
        }
        if ($dinArgument !== null) {
            return $dinArgument;
        }
        if ($dinEnv === '') {
            return null;
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $dinEnv) !== 1) {
            throw new Eroare('SITE_SLUG din .env nu e un nume de site valid (litere mici, cifre, -).', Eroare::CONFIGURARE);
        }
        return $dinEnv;
    }

    /** Dosarul migrărilor: database/migrari (în teste, MIGRARI_TEST_DOSAR). */
    public static function dosarMigrari(string $radacina): string
    {
        return getenv('MIGRARI_TEST_DOSAR') ?: $radacina . '/database/migrari';
    }

    /**
     * Opțiunile runner-ului care vin din mediu: jurnalul, dosarul site-ului (migrari.json),
     * versiunea codului și, doar pentru teste (tools/dev/test-migrari.php), oprirea „ca un proces
     * omorât” după k operații sau la a k-a trecere printr-un punct din Schema, pauzele scurte. Pe
     * site-uri variabilele de test nu există.
     *
     * @param array<string, mixed> $optiuni cele din linia de comandă
     * @return array<string, mixed>
     */
    public static function optiuniDinMediu(string $radacina, array $optiuni = []): array
    {
        if (($k = getenv('MIGRARI_TEST_OPRESTE_DUPA')) !== false && ctype_digit($k) && (int) $k > 0) {
            $optiuni['opreste_dupa'] = (int) $k;
        }
        if (($punct = getenv('MIGRARI_TEST_OPRESTE_IN')) !== false && preg_match('/^[a-z_]+:[1-9]\d*$/', $punct) === 1) {
            $optiuni['opreste_in'] = $punct;
        }
        if (($pauze = getenv('MIGRARI_TEST_PAUZE')) !== false && preg_match('/^\d+(\.\d+)?(,\d+(\.\d+)?)*$/', $pauze) === 1) {
            $optiuni['pauze'] = array_map('floatval', explode(',', $pauze));
        }
        if (($pauza = getenv('MIGRARI_TEST_PAUZA_BUCATI_MS')) !== false && ctype_digit($pauza)) {
            $optiuni['pauza_bucati_ms'] = (int) $pauza;
        }
        $optiuni['jurnal'] = getenv('MIGRARI_TEST_JURNAL') ?: $radacina . '/storage/logs/migrari.log';
        $optiuni['dosar_site'] = DateSite::dosar();
        $versiune = is_file($radacina . '/VERSION') ? trim((string) file_get_contents($radacina . '/VERSION')) : '';
        $optiuni['cod_versiune'] = $versiune !== '' ? substr($versiune, 0, 40) : null;
        return $optiuni;
    }

    /** Raportul runner-ului, pentru oameni. Ultimul rând e mesajul (îl arată porti.sh și jurnalele cronului). */
    public static function text(array $r): string
    {
        $iesire = [];
        $titluri = ['plan' => 'plan (nu schimb nimic)', 'aplica' => 'aplicare', 'verifica' => 'verificare', 'stare' => 'stare'];
        $cap = 'Migrări — ' . ($titluri[$r['comanda']] ?? $r['comanda']);
        if (isset($r['server'])) {
            $cap .= ' · ' . ($r['server']['familie'] === 'mariadb' ? 'MariaDB ' : 'MySQL ') . $r['server']['versiune'] . ' · baza ' . $r['baza']
                . ($r['site'] !== null ? ' · site ' . $r['site'] : '');
        }
        $iesire[] = $cap;
        if (isset($r['registru'])) {
            $iesire[] = 'În cod: ' . $r['registru']['migrari'] . ' migrări (ultima ' . sprintf('%04d', $r['registru']['ultima_ord']) . ')'
                . ($r['site'] !== null ? ', ' . $r['registru']['pasi_site'] . ' pași ai site-ului' : '');
        }
        if ($r['comanda'] === 'aplica' && isset($r['sql_mode'])) {
            $iesire[] = 'sql_mode: ' . ($r['sql_mode'] !== '' ? $r['sql_mode'] : '(gol)') . ' (al serverului, neschimbat)';
        }
        foreach ($r['migrari'] ?? [] as $m) {
            if ($r['comanda'] === 'aplica' && isset($m['operatii_acum'])) {
                $iesire[] = sprintf('  %-10s %s  (%d operații, %d existau deja, %d ms)', $m['stare'], $m['id'], $m['operatii_acum'], $m['fara_efect'], $m['durata_ms']);
                continue;
            }
            $iesire[] = '  ' . $m['id'] . '  ' . $m['tip'] . ' · ' . ($m['stare'] === 'noua' ? 'nouă' : $m['stare'])
                . ($m['blocanta_efectiv'] ?? false ? ' · BLOCANTĂ' : '') . ($m['tip'] === 'contract' ? ' · CONTRACT' : '')
                . ' — ' . $m['descriere'];
            $faraEfect = 0;
            foreach ($m['operatii'] ?? [] as $o) {
                if ($o['clasa'] === 'fara_efect') {
                    $faraEfect++;
                    continue;
                }
                $iesire[] = sprintf('      %-9s %s %s%s', $o['clasa'], $o['fel'], $o['obiect'], $o['detaliu'] !== '' ? ' (' . $o['detaliu'] . ')' : '');
            }
            if ($faraEfect > 0) {
                $iesire[] = '      (' . $faraEfect . ' există deja)';
            }
        }
        if ($r['comanda'] === 'stare' && isset($r['ultima_ord'])) {
            $iesire[] = 'ultima_ord: ' . $r['ultima_ord'] . ($r['ultima_migrare'] !== null ? ' (ultima terminată: ' . $r['ultima_migrare'] . ')' : '');
            $iesire[] = 'sql_mode: ' . $r['sql_mode'] . ' · lock_wait_timeout: ' . $r['lock_wait_timeout'] . ' s';
            $iesire[] = 'de aplicat: ' . ($r['de_aplicat'] === [] ? 'nimic' : implode(', ', $r['de_aplicat']));
            if ($r['blocante_de_aplicat'] !== []) {
                $iesire[] = 'blocante: ' . implode(', ', $r['blocante_de_aplicat']);
            }
            if ($r['in_curs'] !== []) {
                $iesire[] = 'întrerupte: ' . implode(', ', $r['in_curs']);
            }
            $iesire[] = 'runner activ acum: ' . ($r['runner_activ'] ? 'da' : 'nu');
            if (isset($r['stare']['ultima_rulare'])) {
                $iesire[] = 'ultima rulare: ' . $r['stare']['ultima_rulare'];
            }
        }
        if (isset($r['verificare'])) {
            foreach ($r['verificare']['lipsa'] as $obiect) {
                $iesire[] = '  LIPSĂ    ' . $obiect;
            }
            foreach ($r['verificare']['avertismente'] as $text) {
                $iesire[] = '  ATENȚIE  ' . $text;
            }
            foreach (array_slice($r['verificare']['in_plus'], 0, 30) as $obiect) {
                $iesire[] = '  în plus  ' . $obiect;
            }
            if (count($r['verificare']['in_plus']) > 30) {
                $iesire[] = '  în plus  … încă ' . (count($r['verificare']['in_plus']) - 30);
            }
        }
        foreach ($r['avertismente'] ?? [] as $text) {
            $iesire[] = 'ATENȚIE: ' . $text;
        }
        $iesire[] = ($r['cod'] !== 0 ? 'Cod ' . $r['cod'] . ': ' : '') . $r['mesaj'];
        return implode("\n", $iesire) . "\n";
    }
}
