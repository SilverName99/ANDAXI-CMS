<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\View;
use PDO;
use Throwable;

/**
 * „Oferte BBD” (faza D, WP-35, IA-05 din docs/ARHITECTURA-ADMIN.md): `GET /admin/products/oferte-bbd`, doar cu pluginul
 * oferte_bbd. Toate ofertele cu dată minimă de valabilitate (BBD) din toate produsele, într-o singură listă: până acum o
 * ofertă se vedea doar deschizând produsul ei. Pagina doar citește (`products.bbd_enabled`, `products.bbd_entries_json`);
 * ofertele se schimbă tot din editorul produsului, la care duce fiecare rând. Câmpurile unei oferte sunt cele citite de
 * coș și de panoul „Oferte” (data, lotul, prețul redus, stocul, eticheta).
 */
final class OferteBbdController
{
    use Comun;

    /**
     * Ofertele, câte un rând pe ofertă, în ordinea datei (cele mai apropiate de expirare întâi).
     *
     * @return list<array{produs_id: int, produs: string, sku: string, pret: float, activ_produs: bool, bbd_pornit: bool,
     *                    data: string, lot: string, pret_redus: ?float, stoc: ?int, eticheta: string, expirata: bool}>
     */
    public static function oferte(PDO $db, string $azi): array
    {
        try {
            $randuri = $db->query(
                'SELECT id, name, sku, price, is_active, bbd_enabled, bbd_entries_json FROM products
                 WHERE deleted_at IS NULL AND bbd_entries_json IS NOT NULL AND bbd_entries_json <> \'\' AND bbd_entries_json <> \'[]\'
                 ORDER BY name, id'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
        $oferte = [];
        foreach ($randuri as $rand) {
            $lista = json_decode((string) $rand['bbd_entries_json'], true);
            foreach (is_array($lista) ? $lista : [] as $intrare) {
                if (!is_array($intrare)) {
                    continue;
                }
                $dataBruta = trim((string) ($intrare['date'] ?? $intrare['bbd_date'] ?? ''));
                $moment = $dataBruta !== '' ? strtotime($dataBruta) : false;
                if ($moment === false) {
                    continue;
                }
                $pretRedus = $intrare['reduced_price'] ?? $intrare['price'] ?? null;
                $pretRedus = is_numeric(str_replace(',', '.', (string) $pretRedus)) ? (float) str_replace(',', '.', (string) $pretRedus) : null;
                $stoc = trim((string) ($intrare['stock'] ?? $intrare['bbd_stock'] ?? ''));
                $data = date('Y-m-d', $moment);
                $oferte[] = [
                    'produs_id' => (int) $rand['id'],
                    'produs' => (string) $rand['name'],
                    'sku' => (string) ($rand['sku'] ?? ''),
                    'pret' => (float) ($rand['price'] ?? 0),
                    'activ_produs' => (int) ($rand['is_active'] ?? 0) === 1,
                    'bbd_pornit' => (int) ($rand['bbd_enabled'] ?? 0) === 1,
                    'data' => $data,
                    'lot' => strtoupper(trim((string) ($intrare['lot'] ?? $intrare['bbd_lot'] ?? ''))),
                    'pret_redus' => $pretRedus !== null && $pretRedus > 0 ? $pretRedus : null,
                    'stoc' => is_numeric(str_replace(',', '.', $stoc)) ? max(0, (int) floor((float) str_replace(',', '.', $stoc))) : null,
                    'eticheta' => trim((string) ($intrare['label'] ?? '')),
                    'expirata' => $data < $azi,
                ];
            }
        }
        usort($oferte, static fn (array $a, array $b): int => [$a['data'], $a['produs']] <=> [$b['data'], $b['produs']]);
        return $oferte;
    }

    public function lista(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $azi = date('Y-m-d');
        $toate = $db instanceof PDO ? self::oferte($db, $azi) : [];
        $cautare = trim((string) (is_scalar($_GET['q'] ?? null) ? $_GET['q'] : ''));
        $stare = (string) (is_scalar($_GET['stare'] ?? null) ? $_GET['stare'] : '');
        $stare = in_array($stare, ['valabile', 'expirate', 'oprite'], true) ? $stare : '';
        $numar = ['' => count($toate), 'valabile' => 0, 'expirate' => 0, 'oprite' => 0];
        foreach ($toate as $oferta) {
            $numar[$oferta['expirata'] ? 'expirate' : ($oferta['bbd_pornit'] ? 'valabile' : 'oprite')]++;
        }
        $oferte = array_values(array_filter($toate, static function (array $o) use ($cautare, $stare): bool {
            if ($cautare !== '' && mb_stripos($o['produs'] . ' ' . $o['sku'] . ' ' . $o['lot'], $cautare) === false) {
                return false;
            }
            return match ($stare) {
                'valabile' => !$o['expirata'] && $o['bbd_pornit'],
                'expirate' => $o['expirata'],
                'oprite' => !$o['expirata'] && !$o['bbd_pornit'],
                default => true,
            };
        }));
        View::render('admin/oferte-bbd', [
            'title' => 'Oferte BBD',
            'oferte' => $oferte,
            'numar' => $numar,
            'cautare' => $cautare,
            'stare' => $stare,
        ], 'admin/layout');
    }
}
