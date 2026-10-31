<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\View;

/**
 * Pagina de probă a componentelor adminului (faza D, WP-31): `GET /admin/componente`, doar pentru administratorul general,
 * fără intrare în meniu. Arată împreună fiecare componentă din views/admin/componente/, cu date inventate (nimic din bază),
 * ca proprietarul să le vadă pe site-ul de verificare și ca plimbarea cu tastatura (tools/dev/plimbare-tastatura.cjs) să le
 * verifice pe toate: focusul vizibil, Esc pe panou, filtrele păstrate la Înapoi. `?panou=<id>` dă doar detaliile unui rând
 * (fragment HTML), ca sertarul să se încarce de la o adresă, cum o va face din adresele de detalii ale paginilor reale.
 * Nu scrie nimic: bara acțiunilor în masă de aici doar arată ce ar trimite.
 */
final class ComponenteAdminController
{
    use Comun;

    /** Rândurile inventate ale tabelului de probă. @return list<array<string, mixed>> */
    public static function randuriProba(): array
    {
        $clienti = ['Ana Exemplu', 'Carmen Test', 'Dan Proba', 'Elena Model',
            'Florin Exemplu', 'Gina Demo', 'Horia Test', 'Irina Proba', 'Ionuț Model', 'Laura Exemplu', 'Mihai Demo', 'Paula Model'];
        $stari = ['noua', 'in_lucru', 'expediata', 'livrata'];
        $plati = ['card', 'ramburs', 'ordin'];
        $randuri = [];
        foreach ($clienti as $i => $client) {
            $randuri[] = [
                'id' => 1001 + $i,
                'numar' => 'DEMO-' . (1001 + $i),
                'client' => $client,
                'stare' => $stari[$i % 4],
                'plata' => $plati[$i % 3],
                'total' => round(49.9 + $i * 37.35, 2),
                'data' => sprintf('2026-10-%02d', 30 - $i),
            ];
        }
        return $randuri;
    }

    public function proba(): void
    {
        if (!$this->guard()) {
            return;
        }
        $randuri = self::randuriProba();
        if (isset($_GET['panou'])) {
            $id = (int) $_GET['panou'];
            $rand = array_values(array_filter($randuri, static fn (array $r): bool => $r['id'] === $id))[0] ?? null;
            header('Content-Type: text/html; charset=utf-8');
            if ($rand === null) {
                http_response_code(404);
                echo '<p>Rândul nu există.</p>';
                return;
            }
            View::render('admin/proba-componente-panou', ['rand' => $rand], 'admin/fragment');
            return;
        }
        View::render('admin/proba-componente', ['title' => 'Componentele adminului', 'randuri' => $randuri], 'admin/layout');
    }
}
