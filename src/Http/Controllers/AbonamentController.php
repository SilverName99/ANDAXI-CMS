<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminCsrf;
use App\Support\Flash;
use App\Support\LicentaSincronizare;
use App\Support\Plugins;
use App\Support\View;

/**
 * „Abonamentul tău” (faza D, WP-32; decizia OD-52 = b, G-m12): `GET /admin/abonament`, doar pentru administratorul
 * general (nicio listă de roluri nu are adresa). Arată ce module are site-ul acum: pluginurile în starea „pornit” sau „se
 * oprește” (Plugins::stari(), adică licența aplicată), cu numele și descrierea din catalog (config/plugins.php), iar pentru
 * cele care se opresc, data din care se opresc (Plugins::seOpresteDin). Fără uneltele interne ANDAXI (`'intern' => true`, de
 * exemplu migrarea din WordPress): nu se vând, deci nu sunt în abonament. Nu numește niciodată un plugin absent sau oprit.
 *
 * Cu preluarea automată configurată (LICENTA_URL, LICENTA_CHEIE; App\Support\LicentaSincronizare) pagina arată și ultima
 * preluare a licenței de la ANDAXI, iar „Verifică acum” (`POST /admin/abonament/verifica`, tot doar administratorul general,
 * cu jetonul formularului) o cere pe loc, exact ca cronul. Fără ea, pagina e cea de până acum.
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
            if (!empty($definitie['intern'])) {
                continue;
            }
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
            'sincronizare' => self::sincronizare(),
        ], 'admin/layout');
    }

    /** „Verifică acum”: preluarea licenței de la CRM, pe loc (ca cronul), apoi înapoi la pagină cu rezultatul. */
    public function verifica(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/abonament');
            return;
        }
        if (!LicentaSincronizare::configurata()) {
            Flash::set('error', 'Preluarea automată a licenței nu e configurată pe acest site.');
            header('Location: /admin/abonament');
            return;
        }
        $rezultat = LicentaSincronizare::ruleaza('admin');
        $bun = in_array($rezultat['rezultat'], [LicentaSincronizare::OK, LicentaSincronizare::LA_ZI], true);
        Flash::set($bun ? 'success' : 'error', match ($rezultat['rezultat']) {
            LicentaSincronizare::OK => 'Licența nouă (v' . $rezultat['versiune'] . ') a fost primită și aplicată.',
            LicentaSincronizare::LA_ZI => 'Licența e la zi (v' . $rezultat['versiune'] . ').',
            LicentaSincronizare::OCUPAT => 'O verificare rulează chiar acum; încearcă din nou peste câteva secunde.',
            default => 'Licența nu a putut fi actualizată acum; site-ul merge mai departe cu cea de până acum. Detalii mai jos.',
        });
        header('Location: /admin/abonament');
    }

    /**
     * Ce arată pagina despre preluarea automată; null dacă nu e configurată și n-a rulat niciodată (pagina de până acum).
     *
     * @return array{configurata: bool, stare: ?array}|null
     */
    public static function sincronizare(): ?array
    {
        $configurata = LicentaSincronizare::configurata();
        $stare = LicentaSincronizare::ultimaStare();
        if (!$configurata && $stare === null) {
            return null;
        }
        return ['configurata' => $configurata, 'stare' => $stare];
    }
}
