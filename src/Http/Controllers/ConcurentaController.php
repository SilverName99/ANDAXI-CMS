<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\AdminCsrf;
use App\Support\Flash;
use App\Support\View;
use PDO;
use Throwable;

/**
 * Pluginul `concurenta` (din Biovitality 649c837, 7ee1a86, 729d6fa, ea927aa; metodele `competition*` ale lui AdminController la
 * d95598e; docs/PLUGINURI.md, „Analiza prețurilor concurenței”): Produse → Analiza competiție (`/admin/competition`): partenerii
 * (concurenții) urmăriți, câte o pereche de coloane pe partener activ, cu prețul lui (sau cel de campanie, bifat la fiecare produs,
 * în perioada lui) și diferența față de prețul nostru (cel pe care îl vede clientul, cu reducerea), stocul („Indisponibil”, „Doar în
 * magazin”), linkul produsului; salvarea pe celulă, din mers (`POST /admin/competition/cell`), „Salvează tot” fără JavaScript, golirea
 * tabelului și exportul Excel (două foi: comparația și partenerii).
 *
 * Copiat cum e (aceleași texte, aceleași reguli, aceleași adrese și câmpuri), cu trei schimbări: fiecare POST cere jetonul formularului
 * (AdminCsrf: câmpul `_csrf`, iar la celule antetul `X-CSRF-Token`; codul Biovitality se baza doar pe cookie-ul `SameSite=Lax`), schema
 * o face migrarea `concurenta/0060` (nu `ensureCompetitionSchema` la fiecare cerere), iar bifa „doar în magazin” se citește din bază (în
 * codul Biovitality nu ajungea în celulă).
 */
final class ConcurentaController
{
    use Comun;

    /** Câmpurile unei celule partener × produs care se pot scrie (singurele nume de coloane puse în SQL). */
    private const CAMPURI_CELULA = ['price', 'campaign_active', 'campaign_price', 'campaign_start', 'campaign_end', 'out_of_stock', 'only_in_store', 'product_url'];


    /**
     * Produsele din catalog cu prețul pe care îl vede clientul pe site.
     *
     * Nu se ia `products.price` direct: dacă produsul are preț redus sau o
     * perioadă de reducere activă, comparația cu partenerii ar porni de la o
     * cifră pe care nimeni nu o plătește. Folosim același calcul ca listarea
     * de produse din admin.
     */
    private function competitionProducts(PDO $db): array
    {
        $randuri = [];
        try {
            $randuri = $db->query(
                'SELECT p.id, p.name, p.sku, p.price, p.sale_price, p.sale_price_periods_json,
                        c.name AS category_name
                 FROM products p
                 LEFT JOIN product_categories c ON c.id = p.category_id
                 WHERE p.deleted_at IS NULL
                 ORDER BY p.name ASC, p.id ASC'
            )->fetchAll() ?: [];
        } catch (Throwable) {
            // Schemă parțial migrată: fără coloanele de reducere rămâne prețul simplu.
            try {
                $randuri = $db->query(
                    'SELECT p.id, p.name, p.sku, p.price, NULL AS sale_price,
                            NULL AS sale_price_periods_json, NULL AS category_name
                     FROM products p
                     WHERE p.deleted_at IS NULL
                     ORDER BY p.name ASC, p.id ASC'
                )->fetchAll() ?: [];
            } catch (Throwable) {
                $randuri = [];
            }
        }

        $produse = [];
        foreach ($randuri as $rand) {
            if (!is_array($rand)) {
                continue;
            }
            $id = (int) ($rand['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $pricing = (new AdminController())->pretulProdusului(
                (float) ($rand['price'] ?? 0.0),
                $rand['sale_price'] ?? null,
                $rand['sale_price_periods_json'] ?? '[]'
            );
            $produse[] = [
                'id' => $id,
                'name' => (string) ($rand['name'] ?? ''),
                'sku' => (string) ($rand['sku'] ?? ''),
                'category_name' => (string) ($rand['category_name'] ?? ''),
                'base_price' => (float) ($rand['price'] ?? 0.0),
                'price' => (float) ($pricing['effective_price'] ?? 0.0),
                'has_sale_price' => (bool) ($pricing['has_sale_price'] ?? false),
            ];
        }

        return $produse;
    }

    /** Partenerii, în ordinea în care se vor vedea coloanele. */
    private function competitionPartners(PDO $db): array
    {
        try {
            $randuri = $db->query(
                'SELECT id, name, website, has_campaign, campaign_start, campaign_end,
                        is_active, sort_order, created_at
                 FROM competition_partners
                 ORDER BY sort_order ASC, name ASC, id ASC'
            )->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        $parteneri = [];
        foreach ($randuri as $rand) {
            if (!is_array($rand)) {
                continue;
            }
            $parteneri[] = [
                'id' => (int) ($rand['id'] ?? 0),
                'name' => (string) ($rand['name'] ?? ''),
                'website' => trim((string) ($rand['website'] ?? '')),
                'has_campaign' => ((int) ($rand['has_campaign'] ?? 0)) === 1 ? 1 : 0,
                'campaign_start' => $this->competitionDate($rand['campaign_start'] ?? null),
                'campaign_end' => $this->competitionDate($rand['campaign_end'] ?? null),
                'is_active' => ((int) ($rand['is_active'] ?? 1)) === 1 ? 1 : 0,
                'sort_order' => (int) ($rand['sort_order'] ?? 0),
            ];
        }

        return $parteneri;
    }

    /**
     * Toate celulele într-o singură interogare, indexate [partener][produs].
     * O interogare per produs ar însemna sute de round-trip-uri la o pagină.
     */
    private function competitionPricesMap(PDO $db): array
    {
        try {
            $randuri = $db->query(
                'SELECT partner_id, product_id, price, campaign_active, campaign_price,
                        campaign_start, campaign_end, out_of_stock, only_in_store, product_url
                 FROM competition_prices'
            )->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }

        $harta = [];
        foreach ($randuri as $rand) {
            if (!is_array($rand)) {
                continue;
            }
            $partnerId = (int) ($rand['partner_id'] ?? 0);
            $productId = (int) ($rand['product_id'] ?? 0);
            if ($partnerId <= 0 || $productId <= 0) {
                continue;
            }
            $harta[$partnerId][$productId] = [
                'price' => $rand['price'] !== null ? (float) $rand['price'] : null,
                'campaign_active' => ((int) ($rand['campaign_active'] ?? 0)) === 1 ? 1 : 0,
                'campaign_price' => $rand['campaign_price'] !== null ? (float) $rand['campaign_price'] : null,
                'campaign_start' => $this->competitionDate($rand['campaign_start'] ?? null),
                'campaign_end' => $this->competitionDate($rand['campaign_end'] ?? null),
                'out_of_stock' => ((int) ($rand['out_of_stock'] ?? 0)) === 1 ? 1 : 0,
                // Lipsea în codul Biovitality (coloana se citea, dar nu ajungea în celulă): bifa „doar în magazin” apărea mereu
                // goală după reîncărcare, „Salvează tot” o scria înapoi 0, iar exportul n-o arăta niciodată.
                'only_in_store' => ((int) ($rand['only_in_store'] ?? 0)) === 1 ? 1 : 0,
                'product_url' => trim((string) ($rand['product_url'] ?? '')),
            ];
        }

        return $harta;
    }

    /** Data în format ISO sau null — orice altceva strică inputurile `type="date"`. */
    private function competitionDate(mixed $raw): ?string
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '') {
            return null;
        }
        $value = substr($value, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * Prețul care contează azi la partener: cel de campanie dacă e bifat,
     * completat și perioada îl acoperă, altfel prețul normal.
     *
     * Campania se bifează la fiecare produs în parte. Înainte era o proprietate
     * a partenerului întreg, ceea ce însemna că o promoție la un singur produs
     * nu se putea marca fără să pară că tot partenerul e în campanie.
     *
     * Perioada lipsă din celulă cade pe perioada partenerului — dacă partenerul
     * chiar ține o campanie generală — ca să nu fie rescrisă la fiecare produs.
     *
     * @return array{price: float|null, is_campaign: bool, start: ?string, end: ?string, discount: ?float}
     */
    private function competitionEffectivePrice(array $cell, array $partner, ?string $today = null): array
    {
        $azi = $today ?? date('Y-m-d');
        $pretNormal = isset($cell['price']) && $cell['price'] !== null ? (float) $cell['price'] : null;
        $pretCampanie = isset($cell['campaign_price']) && $cell['campaign_price'] !== null
            ? (float) $cell['campaign_price']
            : null;
        $perioadaPartener = ((int) ($partner['has_campaign'] ?? 0)) === 1;
        $start = $cell['campaign_start'] ?? ($perioadaPartener ? ($partner['campaign_start'] ?? null) : null);
        $end = $cell['campaign_end'] ?? ($perioadaPartener ? ($partner['campaign_end'] ?? null) : null);

        $activa = ((int) ($cell['campaign_active'] ?? 0)) === 1
            && $pretCampanie !== null
            && $pretCampanie > 0.0
            && ($start === null || $start <= $azi)
            && ($end === null || $end >= $azi);

        $discount = null;
        if ($pretCampanie !== null && $pretNormal !== null && $pretNormal > 0.0 && $pretCampanie < $pretNormal) {
            $discount = round((($pretNormal - $pretCampanie) / $pretNormal) * 100, 1);
        }

        return [
            'price' => $activa ? $pretCampanie : $pretNormal,
            'is_campaign' => $activa,
            'start' => $start,
            'end' => $end,
            'discount' => $discount,
        ];
    }

    /** Tabelul de comparație: produse pe rânduri, câte două coloane per partener. */
    public function competition(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        $partners = [];
        $rows = [];
        $editing = null;
        $azi = date('Y-m-d');

        if ($db instanceof PDO) {
            $products = $this->competitionProducts($db);
            $partners = $this->competitionPartners($db);
            $prices = $this->competitionPricesMap($db);

            // În tabel intră doar partenerii activi: bifa „Activ” e felul în care
            // se scoate un partener din comparație fără să i se piardă prețurile.
            $partneriInTabel = array_values(array_filter(
                $partners,
                static fn (array $partener): bool => (int) ($partener['is_active'] ?? 1) === 1
            ));

            // Rândurile se pregătesc aici, nu în vizualizare: prețul efectiv al
            // partenerului depinde de campanie, iar regula trebuie să fie una
            // singură, aceeași și pentru export.
            foreach ($products as $product) {
                $productId = (int) $product['id'];
                $cells = [];
                foreach ($partneriInTabel as $partner) {
                    $partnerId = (int) $partner['id'];
                    $cell = $prices[$partnerId][$productId] ?? [];
                    $cells[$partnerId] = [
                        'cell' => $cell,
                        'effective' => $this->competitionEffectivePrice($cell, $partner, $azi),
                    ];
                }
                $rows[] = ['product' => $product, 'cells' => $cells];
            }

            $editId = (int) ($_GET['edit'] ?? 0);
            if ($editId > 0) {
                foreach ($partners as $partner) {
                    if ((int) ($partner['id'] ?? 0) === $editId) {
                        $editing = $partner;
                        break;
                    }
                }
            }
        }

        View::render('admin/competition', [
            'title' => 'Analiza competiție',
            'partners' => $partners,
            'partnersInTable' => $partneriInTabel ?? [],
            'rows' => $rows,
            'editing' => $editing,
            'today' => $azi,
        ], 'admin/layout');
    }

    public function competitionPartnerSave(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/competition');
            return;
        }

        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $website = $this->competitionUrl((string) ($_POST['website'] ?? ''), 255);
        $hasCampaign = isset($_POST['has_campaign']) ? 1 : 0;
        $campaignStart = $this->competitionDate($_POST['campaign_start'] ?? null);
        $campaignEnd = $this->competitionDate($_POST['campaign_end'] ?? null);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if ($name === '' || mb_strlen($name) > 190) {
            Flash::set('error', 'Numele partenerului este obligatoriu (maxim 190 caractere).');
            header('Location: /admin/competition' . ($id > 0 ? ('?edit=' . $id) : ''));
            return;
        }
        // O perioadă inversată ar ascunde campania la nesfârșit, fără explicație.
        if ($campaignStart !== null && $campaignEnd !== null && $campaignStart > $campaignEnd) {
            Flash::set('error', 'Perioada campaniei este inversată: data de început este după cea de final.');
            header('Location: /admin/competition' . ($id > 0 ? ('?edit=' . $id) : ''));
            return;
        }

        $params = [
            'name' => $name,
            'website' => $website !== '' ? $website : null,
            'has_campaign' => $hasCampaign,
            'campaign_start' => $campaignStart,
            'campaign_end' => $campaignEnd,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ];

        try {
            if ($id > 0) {
                $params['id'] = $id;
                $db->prepare(
                    'UPDATE competition_partners
                     SET name = :name, website = :website, has_campaign = :has_campaign,
                         campaign_start = :campaign_start, campaign_end = :campaign_end,
                         is_active = :is_active, sort_order = :sort_order
                     WHERE id = :id LIMIT 1'
                )->execute($params);
                Flash::set('success', 'Partenerul a fost actualizat.');
            } else {
                $db->prepare(
                    'INSERT INTO competition_partners
                        (name, website, has_campaign, campaign_start, campaign_end, is_active, sort_order)
                     VALUES (:name, :website, :has_campaign, :campaign_start, :campaign_end, :is_active, :sort_order)'
                )->execute($params);
                $id = (int) $db->lastInsertId();
                Flash::set('success', 'Partenerul a fost adăugat.');
            }
            AdminActivityLog::log($db, 'competition_partner_save', ['id' => $id, 'name' => $name]);
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut salva partenerul.');
        }

        header('Location: /admin/competition');
    }

    public function competitionPartnerDelete(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid()) {
            return;
        }
        $db = $this->db();
        $id = (int) ($params['id'] ?? 0);
        if (!$db instanceof PDO || $id <= 0) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/competition');
            return;
        }

        try {
            $stmt = $db->prepare('SELECT name FROM competition_partners WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $name = (string) ($stmt->fetchColumn() ?: '');

            $db->prepare('DELETE FROM competition_partners WHERE id = :id LIMIT 1')->execute(['id' => $id]);
            // Fără ștergerea celulelor, un partener nou ar putea moșteni prețurile
            // celui vechi dacă baza reciclează id-ul.
            $db->prepare('DELETE FROM competition_prices WHERE partner_id = :id')->execute(['id' => $id]);

            AdminActivityLog::log($db, 'competition_partner_delete', ['id' => $id, 'name' => $name]);
            Flash::set('success', 'Partenerul și prețurile lui au fost șterse.');
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut șterge partenerul.');
        }

        header('Location: /admin/competition');
    }

    /**
     * Salvarea unei singure celule, apelată prin fetch din tabel.
     *
     * Tabelul are produse × parteneri celule; o reîncărcare de pagină la fiecare
     * preț introdus ar face secțiunea inutilizabilă, deci fiecare câmp se scrie
     * singur, cu upsert pe cheia unică.
     */
    public function competitionCellSave(): void
    {
        if (!$this->guard()) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        // Jetonul formularului vine în antetul X-CSRF-Token (scriptul tabelului îl trimite la fiecare celulă).
        if (!AdminCsrf::valid()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            echo json_encode(['ok' => false, 'error' => 'Conexiunea DB nu este disponibilă.']);
            return;
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $partnerId = (int) ($payload['partner_id'] ?? 0);
        $productId = (int) ($payload['product_id'] ?? 0);
        $field = trim((string) ($payload['field'] ?? ''));

        if ($partnerId <= 0 || $productId <= 0 || !in_array($field, self::CAMPURI_CELULA, true)) {
            echo json_encode(['ok' => false, 'error' => 'Date invalide.']);
            return;
        }

        $value = $this->competitionCellValue($field, $payload['value'] ?? null);
        if (!$this->competitionWriteCell($db, $partnerId, $productId, $field, $value)) {
            echo json_encode(['ok' => false, 'error' => 'Nu am putut salva valoarea.']);
            return;
        }

        echo json_encode(['ok' => true, 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Varianta fără JS: salvează tot ce s-a completat în tabel dintr-un POST.
     *
     * Pe cataloage mari formularul poate depăși `max_input_vars` din PHP; de
     * aceea scrierea obișnuită rămâne cea pe celulă, iar butonul ăsta e doar
     * plasa de siguranță când JS-ul nu rulează.
     */
    public function competitionSaveAll(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/competition');
            return;
        }

        $cells = $_POST['cells'] ?? [];
        if (!is_array($cells)) {
            $cells = [];
        }

        // Sute de upsert-uri într-un singur commit: altfel fiecare celulă ar
        // costa o tranzacție proprie și salvarea ar dura zeci de secunde.
        $inTranzactie = false;
        try {
            $inTranzactie = $db->beginTransaction();
        } catch (Throwable) {
            $inTranzactie = false;
        }

        $salvate = 0;
        foreach ($cells as $partnerRaw => $produse) {
            $partnerId = (int) $partnerRaw;
            if ($partnerId <= 0 || !is_array($produse)) {
                continue;
            }
            foreach ($produse as $productRaw => $campuri) {
                $productId = (int) $productRaw;
                if ($productId <= 0 || !is_array($campuri)) {
                    continue;
                }
                foreach (self::CAMPURI_CELULA as $field) {
                    if (!array_key_exists($field, $campuri)) {
                        continue;
                    }
                    $value = $this->competitionCellValue($field, $campuri[$field]);
                    if ($this->competitionWriteCell($db, $partnerId, $productId, $field, $value)) {
                        $salvate++;
                    }
                }
            }
        }

        if ($inTranzactie) {
            try {
                $db->commit();
            } catch (Throwable) {
                $salvate = 0;
            }
        }

        if ($salvate > 0) {
            AdminActivityLog::log($db, 'competition_prices_save_all', ['campuri' => $salvate]);
            Flash::set('success', 'Tabelul a fost salvat.');
        } else {
            Flash::set('error', 'Nu era nimic de salvat.');
        }

        header('Location: /admin/competition');
    }

    /** Un singur câmp dintr-o celulă, cu upsert pe (partner_id, product_id). */
    private function competitionWriteCell(PDO $db, int $partnerId, int $productId, string $field, mixed $value): bool
    {
        if (!in_array($field, self::CAMPURI_CELULA, true)) {
            return false;
        }

        try {
            $db->prepare(
                "INSERT INTO competition_prices (partner_id, product_id, `{$field}`)
                 VALUES (:partner_id, :product_id, :value)
                 ON DUPLICATE KEY UPDATE `{$field}` = VALUES(`{$field}`)"
            )->execute([
                'partner_id' => $partnerId,
                'product_id' => $productId,
                'value' => $value,
            ]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Curăță valoarea unei celule după tipul câmpului ei. */
    private function competitionCellValue(string $field, mixed $raw): string|int|null
    {
        return match ($field) {
            'price', 'campaign_price' => $this->competitionMoney($raw),
            'campaign_start', 'campaign_end' => $this->competitionDate($raw),
            'campaign_active', 'out_of_stock', 'only_in_store' => in_array((string) $raw, ['1', 'true', 'on'], true) ? 1 : 0,
            'product_url' => $this->competitionUrl((string) ($raw ?? ''), 500) ?: null,
            default => null,
        };
    }

    /** Suma introdusă, acceptând și virgula ca separator zecimal. Gol → null. */
    private function competitionMoney(mixed $raw): ?string
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '') {
            return null;
        }
        $value = str_replace([' ', "\u{00A0}"], '', $value);
        // „1.234,50" și „1,234.50" înseamnă același lucru: ultimul separator e
        // cel zecimal, celălalt desparte miile.
        $virgula = strrpos($value, ',');
        $punct = strrpos($value, '.');
        if ($virgula !== false && $punct !== false) {
            $value = $virgula > $punct
                ? str_replace(',', '.', str_replace('.', '', $value))
                : str_replace(',', '', $value);
        } elseif ($virgula !== false) {
            $value = str_replace(',', '.', $value);
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = round((float) $value, 2);
        // Un preț zero la partener nu înseamnă „gratis", ci „nu am aflat încă".
        return $number > 0.0 ? number_format($number, 2, '.', '') : null;
    }

    /** Linkul se deschide în tab nou: fără schemă, browserul l-ar trata ca pe o cale internă. */
    private function competitionUrl(string $raw, int $maxLength): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . ltrim($value, '/');
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        return mb_substr($value, 0, $maxLength);
    }

    /** Toată situația din tabel, într-un Excel: parteneri, prețuri, campanii, linkuri. */
    /**
     * Goleste tot ce s-a completat in tabel: preturi, campanii, stoc, linkuri
     * si semnele de pe produse. Partenerii raman - ei sunt coloanele, iar
     * stergerea lor e alta actiune, cu butonul ei.
     */
    public function competitionClear(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!$this->formularValid()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibila.');
            header('Location: /admin/competition');
            return;
        }

        $sterse = 0;
        try {
            $stmtNumar = $db->query('SELECT COUNT(*) FROM competition_prices');
            $sterse = $stmtNumar !== false ? (int) $stmtNumar->fetchColumn() : 0;
            $db->exec('DELETE FROM competition_prices');
            AdminActivityLog::log($db, 'competition_clear', ['randuri_sterse' => $sterse]);
            Flash::set('success', $sterse > 0
                ? 'Tabelul a fost golit: ' . $sterse . ' randuri de preturi sterse. Partenerii au ramas.'
                : 'Tabelul era deja gol. Partenerii au ramas.');
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut goli tabelul.');
        }

        header('Location: /admin/competition');
    }

    public function competitionExport(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/competition');
            return;
        }

        // Un catalog mare înmulțit cu zece parteneri înseamnă zeci de mii de
        // celule; limita implicită de timp ar tăia exportul la jumătate, iar
        // omul ar primi un fișier stricat fără nicio explicație.
        @set_time_limit(300);

        $products = $this->competitionProducts($db);
        $partners = $this->competitionPartners($db);
        $prices = $this->competitionPricesMap($db);
        $azi = date('Y-m-d');

        // Foaia de comparație are aceleași coloane ca tabelul din pagină, deci
        // doar partenerii activi; foaia „Parteneri” îi listează pe toți.
        $partneriComparatie = array_values(array_filter(
            $partners,
            static fn (array $partener): bool => (int) ($partener['is_active'] ?? 1) === 1
        ));

        $coloaneParteneri = [
            ['label' => 'Partener', 'width' => 26],
            ['label' => 'Site', 'width' => 34],
            ['label' => 'Campanie', 'width' => 12],
            ['label' => 'Campanie de la', 'width' => 15],
            ['label' => 'Campanie până la', 'width' => 16],
            ['label' => 'Status', 'width' => 10],
            ['label' => 'Ordine afișare', 'width' => 14],
        ];
        $randuriParteneri = [];
        foreach ($partners as $partner) {
            $randuriParteneri[] = [
                (string) $partner['name'],
                (string) $partner['website'],
                $partner['has_campaign'] === 1 ? 'Da' : 'Nu',
                (string) ($partner['campaign_start'] ?? ''),
                (string) ($partner['campaign_end'] ?? ''),
                $partner['is_active'] === 1 ? 'Activ' : 'Inactiv',
                (int) $partner['sort_order'],
            ];
        }

        // Un grup de coloane per partener, în aceeași ordine ca în tabelul din admin.
        $coloaneCompare = [
            ['label' => 'ID', 'width' => 8],
            ['label' => 'Produs', 'width' => 40],
            ['label' => 'SKU', 'width' => 16],
            ['label' => 'Categorie', 'width' => 22],
            ['label' => 'Prețul nostru (RON)', 'width' => 18],
        ];
        foreach ($partneriComparatie as $partner) {
            $nume = (string) $partner['name'];
            $coloaneCompare[] = ['label' => $nume . ' — preț (RON)', 'width' => 18];
            $coloaneCompare[] = ['label' => $nume . ' — diferență (RON)', 'width' => 20];
            $coloaneCompare[] = ['label' => $nume . ' — diferență (%)', 'width' => 18];
            $coloaneCompare[] = ['label' => $nume . ' — preț campanie', 'width' => 18];
            $coloaneCompare[] = ['label' => $nume . ' — discount campanie (%)', 'width' => 22];
            $coloaneCompare[] = ['label' => $nume . ' — perioadă campanie', 'width' => 24];
            $coloaneCompare[] = ['label' => $nume . ' — stoc', 'width' => 14];
            $coloaneCompare[] = ['label' => $nume . ' — link produs', 'width' => 44];
        }

        $randuriCompare = [];
        foreach ($products as $product) {
            $productId = (int) $product['id'];
            $pretulNostru = (float) $product['price'];
            $rand = [
                $productId,
                (string) $product['name'],
                (string) $product['sku'],
                (string) $product['category_name'],
                round($pretulNostru, 2),
            ];

            foreach ($partneriComparatie as $partner) {
                $cell = $prices[(int) $partner['id']][$productId] ?? [];
                $efectiv = $this->competitionEffectivePrice($cell, $partner, $azi);
                $pretPartener = $efectiv['price'];

                $rand[] = $pretPartener !== null ? round($pretPartener, 2) : '';
                if ($pretPartener !== null && $pretPartener > 0.0) {
                    $diferenta = $pretulNostru - $pretPartener;
                    $rand[] = round($diferenta, 2);
                    $rand[] = round(($diferenta / $pretPartener) * 100, 1);
                } else {
                    $rand[] = '';
                    $rand[] = '';
                }

                // Campania se raportează doar unde e chiar bifată la produsul
                // ăsta: un preț rămas într-o celulă nebifată e o notiță, nu o promoție.
                $campanieBifata = ((int) ($cell['campaign_active'] ?? 0)) === 1;
                $pretCampanie = $campanieBifata ? ($cell['campaign_price'] ?? null) : null;
                $rand[] = $pretCampanie !== null ? round((float) $pretCampanie, 2) : '';
                $rand[] = $campanieBifata && $efectiv['discount'] !== null ? $efectiv['discount'] : '';
                $perioada = $campanieBifata ? $this->competitionPeriodLabel($efectiv['start'], $efectiv['end']) : '';
                if ($campanieBifata && $perioada === '') {
                    // Bifată, dar fără capete: e o campanie deschisă, nu una lipsă.
                    $perioada = 'fără perioadă';
                }
                if ($campanieBifata && ($efectiv['is_campaign'] ?? false) === false) {
                    $perioada = trim($perioada . ' (inactivă azi)');
                }
                $rand[] = $perioada;
                // Trei stări, nu două: „doar în magazin" e altceva decât
                // „nu-l are deloc".
                $rand[] = match (true) {
                    ((int) ($cell['only_in_store'] ?? 0)) === 1 => 'Doar în magazin',
                    ((int) ($cell['out_of_stock'] ?? 0)) === 1 => 'Indisponibil',
                    default => 'Disponibil',
                };
                $rand[] = (string) ($cell['product_url'] ?? '');
            }

            $randuriCompare[] = $rand;
        }

        $xlsx = new \App\Support\XlsxWriter();
        $xlsx->addSheet('Comparație prețuri', $coloaneCompare, $randuriCompare);
        $xlsx->addSheet('Parteneri', $coloaneParteneri, $randuriParteneri);

        AdminActivityLog::log($db, 'competition_export', [
            'produse' => count($products),
            'parteneri' => count($partners),
        ]);

        if (!$xlsx->trimite('analiza-competitie-' . date('Y-m-d') . '.xlsx')) {
            Flash::set('error', 'Nu am putut genera fișierul Excel. ' . $xlsx->ultimaEroare());
            header('Location: /admin/competition');
        }
    }

    /** „01.08.2026 – 31.08.2026", cu capetele lipsă tratate ca deschise. */
    private function competitionPeriodLabel(?string $start, ?string $end): string
    {
        $fmt = static function (?string $value): string {
            if ($value === null || $value === '') {
                return '';
            }
            $ts = strtotime($value);
            return $ts !== false ? date('d.m.Y', $ts) : $value;
        };
        $de = $fmt($start);
        $pana = $fmt($end);
        if ($de === '' && $pana === '') {
            return '';
        }
        if ($de === '') {
            return 'până la ' . $pana;
        }
        if ($pana === '') {
            return 'din ' . $de;
        }
        return $de . ' – ' . $pana;
    }

    /** Jetonul formularului (AdminCsrf) e cel al sesiunii? Altfel mesajul și întoarcerea la tabel, fără să se facă nimic. */
    private function formularValid(): bool
    {
        if (AdminCsrf::valid()) {
            return true;
        }
        Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
        header('Location: /admin/competition');
        return false;
    }
}
