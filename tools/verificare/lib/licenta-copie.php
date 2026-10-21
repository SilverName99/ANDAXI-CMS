<?php

declare(strict_types=1);

/**
 * Licența copiei de verificare (docs/VERIFICARE.md, „Licența de verificare”): pachetul de pluginuri ales din catalogul
 * codului deployat, semnat cu cheia de verificare de pe server, și pașii de activare de pe o copie încărcată. O folosesc
 * tools/verificare/licenta.php (proprietarul alege pachetul) și tools/verificare/incarca.php (încărcarea pune pachetul
 * site-ului copiat). Mutată aici din licenta.php în WP-09, cu aceleași texte.
 *
 * Se încarcă după verificarea versiunii PHP și după bootstrap.php (codul aplicației, fără sesiune în linia de comandă).
 */

namespace Unelte\Verificare;

use PDO;
use RuntimeException;
use Throwable;

final class LicentaCopie
{
    /** Numele pachetelor: „toate”, licențele de dezvoltare ale site-urilor (licente_dev) și pachetele (pachete). @return list<string> */
    public static function presetari(array $catalog): array
    {
        return array_merge(['toate'], array_keys((array) ($catalog['licente_dev'] ?? [])), array_keys((array) ($catalog['pachete'] ?? [])));
    }

    /**
     * Pluginurile unui pachet (plugin → stare), cu schimbările `plugin=stare` date după el; doar ce nu e oprit (lipsă = oprit),
     * ca în licența CRM-ului. Un pachet, un plugin sau o stare necunoscută: RuntimeException cu motivul.
     *
     * @param list<string> $schimbari
     * @return array<string, string>
     */
    public static function pluginuri(array $catalog, string $pachet, array $schimbari = []): array
    {
        $chei = array_keys((array) ($catalog['pluginuri'] ?? []));
        $pluginuri = [];
        if ($pachet === 'toate') {
            $pluginuri = array_fill_keys($chei, 'pornit');
        } elseif (isset($catalog['licente_dev'][$pachet])) {
            foreach ((array) $catalog['licente_dev'][$pachet] as $cheie) {
                $pluginuri[(string) $cheie] = 'pornit';
            }
        } elseif (isset($catalog['pachete'][$pachet])) {
            $definitie = $catalog['pachete'][$pachet];
            foreach (array_merge((array) ($definitie['pluginuri'] ?? []), (array) ($definitie['cel_putin_una_din'] ?? [])) as $cheie) {
                $pluginuri[(string) $cheie] = 'pornit';
            }
        } else {
            throw new RuntimeException('nu există pachetul „' . $pachet . '” (licenta.php presetari arată numele).');
        }
        foreach ($schimbari as $argument) {
            if (!str_contains($argument, '=')) {
                throw new RuntimeException('nu înțeleg „' . $argument . '” (aștept plugin=stare, de exemplu blog=oprit).');
            }
            [$cheie, $stare] = array_map('trim', explode('=', $argument, 2));
            if (!in_array($cheie, $chei, true)) {
                throw new RuntimeException('pluginul „' . $cheie . '” nu există în config/plugins.php.');
            }
            if (!in_array($stare, \App\Support\Licenta::STARI, true)) {
                throw new RuntimeException('starea „' . $stare . '” nu există (pornit, se_opreste, oprit).');
            }
            $pluginuri[$cheie] = $stare;
        }
        foreach (array_keys($pluginuri) as $cheie) {
            if (!in_array($cheie, $chei, true)) {
                throw new RuntimeException('pachetul „' . $pachet . '” are pluginul necunoscut „' . $cheie . '” (config/plugins.php).');
            }
        }
        return array_filter($pluginuri, static fn (string $stare): bool => $stare !== 'oprit');
    }

    /**
     * Pune licența: șterge întâi `licenta-buna.json` și `stare-aplicata.json` (orice pachet poate urma oricărui alt pachet;
     * pe un site adevărat o licență mai veche decât ultima bună e refuzată), apoi o semnează cu cheia privată de verificare și
     * o instalează. Întoarce versiunea, pluginurile care nu sunt oprite și notele catalogului.
     *
     * @param array<string, string> $pluginuri
     * @return array{versiune: int, site: string, pornite: list<string>, mesaje: list<string>}
     */
    public static function pune(array $pluginuri, string $cheiePrivata): array
    {
        $dosarLicenta = \App\Support\Licenta::dosar();
        $versiune = 0;
        foreach ([\App\Support\Licenta::FISIER, \App\Support\Licenta::FISIER_BUN] as $fisier) {
            $date = json_decode((string) @file_get_contents($dosarLicenta . '/' . $fisier), true);
            $versiune = max($versiune, is_array($date) ? (int) ($date['versiune'] ?? 0) : 0);
        }
        foreach ([\App\Support\Licenta::FISIER_BUN, \App\Support\Plugins::FISIER_STARE] as $fisier) {
            if (is_file($dosarLicenta . '/' . $fisier) && !@unlink($dosarLicenta . '/' . $fisier)) {
                throw new RuntimeException('nu pot șterge storage/licenta/' . $fisier . ' (drepturi de scriere?).');
            }
        }
        $licenta = [
            'site' => \App\Support\Licenta::site(),
            'emisa_la' => gmdate('Y-m-d\TH:i:s\Z'),
            'versiune' => $versiune + 1,
            'pluginuri' => $pluginuri,
        ];
        $rezultat = \App\Support\Licenta::instaleaza(\App\Support\Licenta::semneaza($licenta, $cheiePrivata));
        if (!$rezultat['ok']) {
            throw new RuntimeException('licența nu a fost pusă: ' . $rezultat['eroare']);
        }
        \App\Support\Plugins::reseteaza();
        $pornite = [];
        foreach (\App\Support\Plugins::stari() as $cheie => $stare) {
            if ($stare !== 'oprit') {
                $pornite[] = $cheie . ($stare === 'se_opreste' ? ' (se oprește)' : '');
            }
        }
        return ['versiune' => $licenta['versiune'], 'site' => $licenta['site'], 'pornite' => $pornite, 'mesaje' => \App\Support\Plugins::mesaje()];
    }

    /**
     * Pașii de activare ai pluginurilor pornite acum (config/activari.php), ca după o licență nouă venită de la CRM, doar pe
     * o copie de verificare (tabelul `_verificare`) care nu e baza altui site din cont; pe o bază care nu e la zi cu migrările
     * așteaptă. Întoarce rândurile de afișat (`Activare …` și notele).
     *
     * @return list<string>
     */
    public static function activari(string $acasa): array
    {
        $app = require \App\Support\Paths::radacina() . '/config/app.php';
        try {
            $db = \App\Support\Database::connection((array) ($app['db'] ?? []));
        } catch (Throwable) {
            $db = null;
        }
        if (!$db instanceof PDO) {
            return ['Notă: baza nu răspunde; pașii de activare rulează la prima cerere de pe copie.'];
        }
        $copie = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_verificare'")->fetchColumn() > 0;
        if (!$copie || Cont::bazaAltuiSite($acasa, (string) \App\Support\Env::get('DB_DATABASE', ''), (string) \App\Support\Env::get('DB_HOST', 'localhost')) !== null) {
            return ['Notă: pe bază nu e încă o copie de verificare; pașii de activare ai pluginurilor rulează după încărcare.'];
        }
        $randuri = [];
        try {
            foreach (\App\Support\Activari::ruleaza($db) as $pas) {
                $randuri[] = 'Activare ' . $pas['plugin'] . ': ' . $pas['rezultat'];
            }
            $asteapta = \App\Support\Activari::asteptare($db);
            if ($asteapta !== null) {
                $randuri[] = 'Notă: pașii de activare așteaptă migrările (garda: ' . $asteapta . '); rulează după scripts/migreaza.php --aplica.';
            }
        } catch (Throwable $e) {
            $randuri[] = 'Notă: pașii de activare n-au rulat acum (' . \App\Support\Migrari\Schema::faraValori($e->getMessage()) . '); rulează la prima cerere.';
        }
        return $randuri;
    }
}
