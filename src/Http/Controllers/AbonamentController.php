<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Plugins;
use App\Support\View;

/**
 * „Abonamentul tău” (faza D, WP-32; decizia OD-52 = b, G-m12): `GET /admin/abonament`, doar pentru administratorul
 * general (nicio listă de roluri nu are adresa). Arată ce module are site-ul acum: pluginurile în starea „pornit” sau „se
 * oprește” (Plugins::stari(), adică licența aplicată), cu numele și descrierea din catalog (config/plugins.php), iar pentru
 * cele care se opresc, data din care se opresc (Plugins::seOpresteDin). Nu numește niciodată un plugin absent sau oprit și
 * nu cere nimic de la CRM: e doar licența de pe server, citită.
 */
final class AbonamentController
{
    use Comun;

    /**
     * Modulele de arătat: cele vizibile, în ordinea catalogului.
     *
     * @return list<array{cheie: string, nume: string, descriere: string, stare: string, din: ?int}>
     */
    public static function module(): array
    {
        $module = [];
        foreach (Plugins::stari() as $cheie => $stare) {
            if ($stare !== Plugins::PORNIT && $stare !== Plugins::SE_OPRESTE) {
                continue;
            }
            $definitie = Plugins::definitie((string) $cheie) ?? [];
            $module[] = [
                'cheie' => (string) $cheie,
                'nume' => (string) ($definitie['nume'] ?? $cheie),
                'descriere' => (string) ($definitie['descriere'] ?? ''),
                'stare' => $stare,
                'din' => $stare === Plugins::SE_OPRESTE ? Plugins::seOpresteDin((string) $cheie) : null,
            ];
        }
        return $module;
    }

    public function arata(): void
    {
        if (!$this->guard()) {
            return;
        }
        $nucleu = Plugins::definitie(Plugins::NUCLEU) ?? [];
        View::render('admin/abonament', [
            'title' => 'Abonamentul tău',
            'module' => self::module(),
            'nucleu' => ['nume' => (string) ($nucleu['nume'] ?? 'Nucleu'), 'descriere' => (string) ($nucleu['descriere'] ?? '')],
            'licentaLipseste' => Plugins::licentaLipseste(),
        ], 'admin/layout');
    }
}
