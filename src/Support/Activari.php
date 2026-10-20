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
 * **Doar pe o bază la zi cu migrările** (Migrari\Garda: `la_zi`). Pe una `fara_registru` (un site
 * încă neadoptat, o copie de dinainte de adoptare restaurată fără runner), `in_urma` (un release
 * urcat înainte de `migreaza.php --aplica`, o adoptare oprită la jumătate) sau `eroare` nu se scrie
 * nimic, nici înregistrarea: pașii așteaptă prima rulare de după `--aplica`. Altfel pragul ERP ar
 * primi momentul de acum înaintea înghețării setărilor site-ului (WP-06), care scrie doar o cheie
 * lipsă și nu l-ar mai putea face gol. Pe o astfel de bază funcțiile ensure* lucrează ca înainte
 * (readuc și pagina /acorduri-gdpr), deci nu lipsește nimic. Starea se citește doar când e ceva de
 * făcut (o interogare după cheia primară, ținută minte pe conexiune).
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
     * Pe o bază care nu e la zi cu migrările nu face nimic (vezi capul clasei).
     *
     * @param array<string, string>|null $setari
     * @return list<array{plugin: string, pas: string, rezultat: string}> ce a rulat (gol: nimic de făcut,
     *         sau baza încă nu e la zi, ce spune asteptare())
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
        $deRulat = [];
        foreach ($pasi as $plugin => $lista) {
            $stare = Plugins::stare($plugin);
            if ($stare === Plugins::OPRIT) {
                unset($facute[$plugin]);
                continue;
            }
            if ($stare === Plugins::PORNIT && !isset($facute[$plugin])) {
                $deRulat[$plugin] = $lista;
            }
        }
        if ($deRulat === [] && $facute == $inainte) {
            return [];
        }
        // Ceva de făcut: doar pe o bază la zi cu migrările (garda), altfel nimic, nici înregistrarea.
        if (self::asteptare($db) !== null) {
            return [];
        }
        $rulate = [];
        foreach ($deRulat as $plugin => $lista) {
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

    /**
     * De ce așteaptă pașii pe această bază: starea gărzii (`fara_registru`, `in_urma`, `eroare`), sau
     * null pe o bază la zi cu migrările (pașii pot rula). Pentru mesajele uneltelor (licenta.php).
     */
    public static function asteptare(PDO $db): ?string
    {
        $stare = Migrari\Garda::stare($db);
        return $stare === Migrari\Garda::LA_ZI ? null : $stare;
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
