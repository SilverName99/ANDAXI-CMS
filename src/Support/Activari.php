<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use Throwable;

/**
 * Pașii de activare ai pluginurilor (config/activari.php; docs/MIGRARI.md, „Pașii de activare”).
 *
 * Migrările fac aceeași schemă pe orice site, oricare ar fi pluginurile. Conținutul pe care îl cere
 * un plugin (pagina /acorduri-gdpr, pragul comenzilor trimise în ERP) se pune o singură dată pe
 * bază, când pluginul devine „pornit”, nu la fiecare cerere (cum făceau funcțiile ensure* până acum,
 * care readuceau și o pagină ștearsă de admin).
 *
 * Ce s-a făcut pe o bază stă chiar în ea, în setarea `activari_pluginuri` (pluginul => momentul):
 *  - un plugin „pornit” care nu e acolo își rulează pașii, apoi intră acolo (o eroare: rămâne pe
 *    dinafară și se încearcă din nou la următoarea cerere);
 *  - un plugin „oprit” iese de acolo, deci o repornire rulează pașii din nou (fiecare pas e
 *    idempotent: o pagină care există, chiar și ștearsă, nu se mai face; o cheie existentă nu se
 *    atinge);
 *  - „se oprește” nu schimbă nimic.
 * Așa contează baza, nu dosarul site-ului: o bază nouă, una restaurată dintr-o copie mai veche sau
 * o licență schimbată din linia de comandă, fără nicio cerere după, își primesc pașii la prima
 * rulare. Setarea se citește oricum la fiecare cerere (Settings::all din public/index.php), deci
 * verificarea nu costă nicio interogare în plus; doar o schimbare scrie ceva.
 *
 * Fiecare pas rulat scrie un rând în jurnalul de activitate al adminului (`activare_plugin`, fără
 * administrator: nu e acțiunea cuiva). Nu rulează migrări și nu trimite DDL.
 */
final class Activari
{
    public const CHEIE_SETARE = 'activari_pluginuri';
    public const ACTIUNE_JURNAL = 'activare_plugin';

    /** @var array<string, array<string, callable>>|null */
    private static ?array $pasi = null;

    /**
     * Pașii pluginurilor pornite care n-au rulat pe această bază; pluginurile oprite ies din
     * înregistrare. `$setari` = Settings::all($db) dacă îl ai deja (altfel se citește doar cheia).
     *
     * @param array<string, string>|null $setari
     * @return list<array{plugin: string, pas: string, rezultat: string}> ce a rulat (gol: nimic de făcut)
     */
    public static function ruleaza(?PDO $db, ?array $setari = null): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        $pasi = self::pasi();
        $text = $setari !== null ? (string) ($setari[self::CHEIE_SETARE] ?? '') : self::citesteInregistrarea($db);
        $facute = json_decode($text, true);
        $facute = is_array($facute) ? $facute : [];
        $inainte = $facute;

        // Doar pluginurile care au pași (un plugin scos din config/activari.php iese și de aici).
        $facute = array_intersect_key($facute, $pasi);
        $rulate = [];
        foreach ($pasi as $plugin => $lista) {
            $stare = Plugins::stare($plugin);
            if ($stare === Plugins::OPRIT) {
                unset($facute[$plugin]);
                continue;
            }
            if ($stare !== Plugins::PORNIT || isset($facute[$plugin])) {
                continue;
            }
            $toateBune = true;
            foreach ($lista as $nume => $pas) {
                try {
                    $rezultat = (string) $pas($db);
                } catch (Throwable $e) {
                    $toateBune = false;
                    $rezultat = 'eroare: ' . substr(Migrari\Schema::faraValori($e->getMessage()), 0, 200);
                }
                $rulate[] = ['plugin' => $plugin, 'pas' => $nume, 'rezultat' => $rezultat];
                self::jurnal($db, $plugin, $nume, $rezultat);
            }
            if ($toateBune) {
                $facute[$plugin] = date('Y-m-d H:i:s');
            }
        }
        if ($facute != $inainte) {
            ksort($facute);
            Settings::save($db, [self::CHEIE_SETARE => (string) json_encode((object) $facute, JSON_UNESCAPED_SLASHES)]);
        }
        return $rulate;
    }

    /**
     * La fiecare cerere (public/index.php), după conexiune și după stările pluginurilor: ca ruleaza(),
     * dar o eroare (baza căzută, un tabel lipsă pe o bază încă neadoptată) nu oprește niciodată pagina.
     *
     * @param array<string, string> $setari Settings::all($db), citit deja de cerere
     */
    public static function laCerere(?PDO $db, array $setari): void
    {
        if (!$db instanceof PDO) {
            return;
        }
        try {
            self::ruleaza($db, $setari);
        } catch (Throwable) {
            // Se încearcă din nou la următoarea cerere: înregistrarea n-a fost scrisă.
        }
    }

    // ------------------------------------------------------------------ pașii

    /**
     * acorduri_gdpr: pagina formularului de acorduri, doar dacă nu există (nici ștearsă). Aceeași
     * instrucțiune ca în AdminController::ensureOptionalSchema; readucerea unei pagini șterse, de
     * acolo, nu se mai face: ștergerea adminului rămâne.
     */
    public static function paginaAcorduriGdpr(PDO $db): string
    {
        try {
            $facute = $db->exec(
                "INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published)
                 SELECT 'Acorduri GDPR', 'acorduri-gdpr', '<section class=\"gdpr-agreements-page\">{{gdpr_agreements_form}}</section>', '', '', 1
                 FROM DUAL
                 WHERE NOT EXISTS (
                    SELECT 1 FROM pages WHERE slug = 'acorduri-gdpr'
                 )"
            );
        } catch (PDOException $e) {
            // Altă cerere a făcut-o chiar acum (slug-ul e unic).
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $facute = 0;
        }
        return (int) $facute > 0 ? 'pagina /acorduri-gdpr creată' : 'pagina /acorduri-gdpr exista (neatinsă)';
    }

    /**
     * erp: pragul `erp_trimite_din` = acum, doar dacă cheia lipsește cu totul (o valoare goală
     * înseamnă „fără prag”, de exemplu înghețarea Bioscem, și rămâne). Comenzile de dinainte nu mai
     * pleacă în ERP când pluginul pornește pe un site care nu l-a avut (WP-12 E1 îl citește).
     */
    public static function pragTrimitereErp(PDO $db): string
    {
        $stmt = $db->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (:k, :v)');
        $stmt->execute(['k' => 'erp_trimite_din', 'v' => date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0 ? 'erp_trimite_din scris (de acum)' : 'erp_trimite_din exista (neatins)';
    }

    // ---------------------------------------------------------------- intern

    /** @return array<string, array<string, callable>> */
    private static function pasi(): array
    {
        if (self::$pasi === null) {
            $config = require dirname(__DIR__, 2) . '/config/activari.php';
            self::$pasi = is_array($config) ? $config : [];
        }
        return self::$pasi;
    }

    private static function citesteInregistrarea(PDO $db): string
    {
        $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
        $stmt->execute(['k' => self::CHEIE_SETARE]);
        $valoare = $stmt->fetchColumn();
        return is_string($valoare) ? $valoare : '';
    }

    private static function jurnal(PDO $db, string $plugin, string $pas, string $rezultat): void
    {
        try {
            $stmt = $db->prepare(
                'INSERT INTO admin_activity_log (admin_id, admin_email, action, context_json, ip)
                 VALUES (NULL, NULL, :action, :context_json, NULL)'
            );
            $stmt->execute([
                'action' => self::ACTIUNE_JURNAL,
                'context_json' => json_encode(['plugin' => $plugin, 'pas' => $pas, 'rezultat' => $rezultat],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // Jurnalul e doar informație (pe o bază încă neadoptată tabelul poate lipsi).
        }
    }
}
