<?php

declare(strict_types=1);

/**
 * Contul SSH al site-ului de verificare e comun cu site-urile live (docs/VERIFICARE.md): uneltele care ating baza
 * copiei (configureaza.php, stare.php, licenta.php, sterge.php) se uită întâi dacă nu cumva aceeași bază e a altui site
 * din cont. Se încarcă după verificarea versiunii PHP și după src/Support/Verificare.php.
 */

namespace Unelte\Verificare;

use App\Support\Verificare;

final class Cont
{
    /**
     * Site-ul din același cont care folosește aceeași bază de date, sau null: o bază scrisă în `.env`-ul altui site se
     * refuză, pe lângă regula „goală sau copie de verificare”. Se citesc, în memorie, doar `DB_DATABASE` și `DB_HOST`
     * din `.env`-urile dosarelor `~/public_html`, `~/domains/<d>/public_html` și `~/domains/<d>/site` (așezarea din
     * Etapa 2), în afară de al nostru (cel al copiei codului vechi, `~/domains/<verificare>/cod-vechi/`, nu e printre ele:
     * el chiar folosește baza copiei). Nicio valoare nu se tipărește, doar numele dosarului (`~/public_html`) sau domeniul.
     */
    public static function bazaAltuiSite(string $acasa, string $baza, string $gazdaDb = 'localhost'): ?string
    {
        $acasa = rtrim($acasa, '/');
        if ($acasa === '' || trim($baza) === '') {
            return null;
        }
        $alNostru = realpath(Verificare::caleEnv());
        $locala = static fn (string $gazda): string => in_array(strtolower(trim($gazda)), ['', 'localhost', '127.0.0.1', '::1'], true)
            ? 'localhost' : strtolower(trim($gazda));
        $candidati = ['~/public_html' => $acasa . '/public_html/.env'];
        foreach (glob($acasa . '/domains/*', GLOB_ONLYDIR) ?: [] as $dosar) {
            $candidati[basename($dosar)] = $dosar . '/public_html/.env';
            $candidati[basename($dosar) . ' (site/)'] = $dosar . '/site/.env';
        }
        foreach ($candidati as $nume => $fisier) {
            if (!is_file($fisier) || !is_readable($fisier)) {
                continue;
            }
            $real = realpath($fisier);
            if ($real === false || ($alNostru !== false && $real === $alNostru)) {
                continue;
            }
            $chei = Verificare::citesteEnv((string) @file_get_contents($fisier, false, null, 0, 262144));
            if (($chei['DB_DATABASE'] ?? '') === $baza && $locala($chei['DB_HOST'] ?? 'localhost') === $locala($gazdaDb)) {
                return (string) $nume;
            }
        }
        return null;
    }
}
