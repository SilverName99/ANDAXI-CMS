<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * `GET /_andaxi/ping`: e viu site-ul și îi răspunde baza? Îl cer monitorul CRM-ului (la 5 minute) și verificarea de
 * după un deploy (Etapa 2), deci răspunde repede și nu lasă nimic în urmă:
 *
 *  - `200 {"ok":true,"db":true,"cod":"<versiune>","schema":"<stare>"}`: `cod` e versiunea codului (fișierul `VERSION`
 *    al versiunii urcate; null fără el, de exemplu în dezvoltare), `schema` e starea migrărilor după gardă
 *    (`la_zi`, `in_urma`, `fara_registru`, `eroare`; docs/MIGRARI.md, „Garda”). O bază în urma codului e tot 200: site-ul
 *    merge; verificarea deploy-ului citește `schema` (sau `/health`, care dă atunci 503).
 *  - `503 {"ok":false,"db":false}`: baza nu răspunde la `SELECT 1` în 2 secunde (conectarea și citirea au fiecare
 *    limita lor, `PDO::ATTR_TIMEOUT` și `mysqlnd.net_read_timeout`: un server care acceptă conexiunea și apoi tace
 *    ar ține altfel cererea minute întregi).
 *
 * Mereu `Cache-Control: no-store`, fără sesiune și fără cookie (bootstrap.php). `public/index.php` îl servește imediat
 * după bootstrap (și pe `/_andaxi/ping/`, `eCererea()`), înaintea setărilor, a pluginurilor, a pașilor de activare și a
 * cache-ului de pagini, cu o conexiune a lui: nimic altceva nu rulează și nu așteaptă baza. Ruta din
 * `src/Http/rute.php` (proprietar: nucleul, catalogul `nucleu.rute_publice`) face același lucru; e acolo pentru catalog
 * și unelte, o cerere prin `public/index.php` nu ajunge la ea. Ocolește mentenanța din admin (`Maintenance`) și pe cea
 * a deploy-ului (`MENTENANTA`, stub-ul), redirecționările SEO și, pe site-ul de verificare, parola porții (doar GET și
 * HEAD pe adresa exactă, fără „/” la sfârșit).
 */
final class Ping
{
    public const CALE = '/_andaxi/ping';
    /** Secunde: conectarea la bază și fiecare citire. */
    public const LIMITA_BAZA = 2;

    /**
     * Cererea curentă e pingul? GET sau HEAD, cu sau fără parametri, pe calea lui sau pe ea cu „/” la sfârșit
     * (`/_andaxi/ping/`): routerul scoate „/”-ul final și le duce pe amândouă la ruta pingului, iar redirecționările SEO
     * nu ating `/_andaxi`, deci și forma cu „/” trebuie servită aici, înaintea sesiunii și a conexiunii aplicației.
     */
    public static function eCererea(): bool
    {
        $metoda = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        return ($metoda === 'GET' || $metoda === 'HEAD') && $cale !== '' && rtrim($cale, '/') === self::CALE;
    }

    /** Trimite răspunsul (codul, antetele și, în afară de HEAD, corpul JSON). Nu oprește scriptul. */
    public static function raspunde(): void
    {
        $config = require Paths::radacina() . '/config/app.php';
        $db = self::conexiune((array) ($config['db'] ?? []));
        $raspuns = $db !== null
            ? ['ok' => true, 'db' => true, 'cod' => self::versiuneCod(), 'schema' => Migrari\Garda::stare($db)]
            : ['ok' => false, 'db' => false];
        http_response_code($db !== null ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo json_encode($raspuns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    /** Versiunea codului din `VERSION` (scris în versiunea urcată, analiza infra §4.5), sau null. */
    public static function versiuneCod(): ?string
    {
        $fisier = Paths::radacina() . '/VERSION';
        $versiune = is_file($fisier) ? trim((string) @file_get_contents($fisier)) : '';
        return $versiune !== '' ? substr($versiune, 0, 40) : null;
    }

    /** O conexiune proprie, cu limita de 2 secunde, care a răspuns la `SELECT 1`; null altfel. */
    private static function conexiune(array $config): ?PDO
    {
        if (trim((string) ($config['database'] ?? '')) === '') {
            return null;
        }
        // Citirea salutului serverului și a fiecărui răspuns: fără ea, PDO::ATTR_TIMEOUT limitează doar conectarea TCP.
        @ini_set('mysqlnd.net_read_timeout', (string) self::LIMITA_BAZA);
        try {
            $db = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) ($config['host'] ?? ''), (int) ($config['port'] ?? 3306),
                    (string) $config['database']),
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => self::LIMITA_BAZA]
            );
            $unu = $db->query('SELECT 1');
            return $unu !== false && (string) $unu->fetchColumn() === '1' ? $db : null;
        } catch (Throwable) {
            return null;
        }
    }
}
