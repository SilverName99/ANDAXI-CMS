<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Produsele dintr-un email: blocul „Produse” al builderului de newsletter (și, mai târziu, al automatizărilor). Aici stau,
 * într-un singur loc, alegerea produselor și cardurile lor în HTML de email, ca orice email care arată produse să le aleagă
 * și să le deseneze la fel.
 *
 * Două feluri de bloc (`mod`):
 *  - „manual” („Aleg eu”): produsele alese de om, după id (`produse` = [{id, name, image_url, url, price, old_price}], copia
 *    din momentul alegerii). La trimitere se citesc din nou din catalog (numele, poza și prețul de atunci); un produs șters
 *    sau oprit între timp nu mai apare. Fără bază (nu se poate citi catalogul), rămâne copia salvată.
 *  - „auto” („Automat”): produsele se aleg la trimitere, după `sursa`:
 *      `vandute`      cele mai vândute în ultimele `zile` zile (comenzile fără cele anulate, eșuate, rambursate, returnate,
 *                     neplătite), altfel cele mai noi;
 *      `noi`          cele mai noi produse;
 *      `categorie`    din categoria `categorie_id` (categoria principală sau una suplimentară), cele mai vândute întâi;
 *      `reduse`       cu un preț redus acum (prețul redus sau o perioadă de reducere în curs), cea mai mare reducere întâi;
 *      `recomandate`  pentru fiecare abonat: din categoriile ultimei lui comenzi (după adresa de email), fără ce a cumpărat
 *                     atunci; fără comenzi (sau fără adresă, de exemplu în previzualizare), cele mai vândute.
 *    La „auto” intră doar produsele active, cu poză (o poză SVG nu se vede în Gmail), care nu sunt ascunse din catalog.
 *
 * Prețurile apar doar cu pluginul magazin pe site (ca la șabloanele magazinului, NewsletterSabloane).
 */
final class ProduseEmail
{
    public const SURSE = ['vandute', 'noi', 'categorie', 'reduse', 'recomandate'];

    /** Comenzile care nu se numără la vânzări (și nici la „ultima comandă”). */
    private const STARI_FARA_VANZARE = ['cancelled', 'failed', 'refunded', 'returned', 'pending_payment'];

    /** @var array<string, list<array<string, mixed>>> cele mai vândute, pe zile și număr (o trimitere le citește o dată) */
    private static array $vandute = [];

    /** Pentru teste și pentru procesele lungi: uită ce s-a citit. */
    public static function uita(): void
    {
        self::$vandute = [];
    }

    /**
     * Setările blocului, curățate (aceleași reguli la salvare și la trimitere).
     *
     * @param array<string, mixed> $bloc
     * @return array{mod: string, sursa: string, zile: int, categorie_id: int, numar: int, pe_rand: int, ids: list<int>}
     */
    public static function setari(array $bloc): array
    {
        $mod = (string) ($bloc['mod'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
        $sursa = in_array((string) ($bloc['sursa'] ?? ''), self::SURSE, true) ? (string) $bloc['sursa'] : 'vandute';
        $ids = [];
        foreach (is_array($bloc['produse'] ?? null) ? $bloc['produse'] : [] as $p) {
            $id = is_array($p) ? (int) ($p['id'] ?? 0) : (int) $p;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return [
            'mod' => $mod,
            'sursa' => $sursa,
            'zile' => max(1, min(365, (int) ($bloc['zile'] ?? 30))),
            'categorie_id' => max(0, (int) ($bloc['categorie_id'] ?? 0)),
            'numar' => max(1, min(12, (int) ($bloc['numar'] ?? 3))),
            'pe_rand' => in_array((int) ($bloc['pe_rand'] ?? 3), [1, 2, 3], true) ? (int) ($bloc['pe_rand'] ?? 3) : 3,
            'ids' => array_slice($ids, 0, 12),
        ];
    }

    /** Blocul se alege pentru fiecare abonat (recomandările după ultima comandă)? */
    public static function ePeAbonat(array $bloc): bool
    {
        $s = self::setari($bloc);
        return $s['mod'] === 'auto' && $s['sursa'] === 'recomandate';
    }

    /**
     * Produsele blocului, gata de desenat: [{id, name, url, image_url, price, old_price}] (`old_price` null fără reducere).
     * `$email` = adresa abonatului, doar pentru „recomandate”.
     *
     * @param array<string, mixed> $bloc
     * @return list<array{id: int, name: string, url: string, image_url: string, price: float, old_price: ?float}>
     */
    public static function rezolva(?PDO $db, array $bloc, ?string $email = null): array
    {
        $s = self::setari($bloc);
        if ($s['mod'] === 'manual') {
            $copie = self::dinCopie($bloc);
            if (!$db instanceof PDO || $s['ids'] === []) {
                return $copie;
            }
            try {
                $randuri = self::dupaId($db, $s['ids'], false);
            } catch (Throwable) {
                return $copie;
            }
            return $randuri;
        }
        if (!$db instanceof PDO) {
            return [];
        }
        try {
            return match ($s['sursa']) {
                'noi' => self::noi($db, $s['numar']),
                'categorie' => self::dinCategorie($db, $s['categorie_id'], $s['numar']),
                'reduse' => self::reduse($db, $s['numar']),
                'recomandate' => self::recomandate($db, (string) $email, $s['numar']),
                default => self::vandute($db, $s['zile'], $s['numar']),
            };
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Căutarea din builder („Aleg eu”): produsele active care se potrivesc cu textul (nume, cod), cele potrivite la început
     * întâi; cu un text gol, cele mai noi.
     *
     * @return list<array{id: int, name: string, url: string, image_url: string, price: float, old_price: ?float}>
     */
    public static function cauta(PDO $db, string $text, int $cate = 12): array
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        $cate = max(1, min(30, $cate));
        if ($text === '') {
            return self::produse($db, 'p.deleted_at IS NULL AND p.is_active = 1', [], 'p.id DESC', $cate, false);
        }
        $like = '%' . addcslashes($text, '%_\\') . '%';
        return self::produse(
            $db,
            'p.deleted_at IS NULL AND p.is_active = 1 AND (LOWER(p.name) LIKE :like OR LOWER(COALESCE(p.sku, "")) LIKE :like)',
            ['like' => $like, 'inceput' => addcslashes($text, '%_\\') . '%'],
            'CASE WHEN LOWER(p.name) LIKE :inceput THEN 0 ELSE 1 END, p.name ASC',
            $cate,
            false
        );
    }

    /**
     * Produsele cu id-urile date, în ordinea dată, doar cele active și neșterse.
     *
     * @param list<int> $ids
     * @return list<array{id: int, name: string, url: string, image_url: string, price: float, old_price: ?float}>
     */
    public static function dupaId(PDO $db, array $ids, bool $doarCuPoza = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $semne = [];
        $param = [];
        foreach ($ids as $k => $id) {
            $semne[] = ':id' . $k;
            $param['id' . $k] = $id;
        }
        $gasite = self::produse($db, 'p.deleted_at IS NULL AND p.is_active = 1 AND p.id IN (' . implode(',', $semne) . ')', $param,
            'p.id', count($ids), $doarCuPoza);
        $dupaId = [];
        foreach ($gasite as $p) {
            $dupaId[$p['id']] = $p;
        }
        $rezultat = [];
        foreach ($ids as $id) {
            if (isset($dupaId[$id])) {
                $rezultat[] = $dupaId[$id];
            }
        }
        return $rezultat;
    }

    /**
     * Cardurile produselor, în HTML de email: câte `pe_rand` pe un rând (1, 2 sau 3), care trec unul sub altul pe un ecran
     * îngust fără media query (coloane „spongioase”: inline-block cu lățime maximă, ca secțiunile builderului), cu tabel pentru
     * Outlook. Fiecare card: poza (link spre produs), numele, prețul (cu cel vechi tăiat), butonul. Fără produse: ''.
     *
     * @param array<string, mixed> $bloc
     * @param list<array<string, mixed>> $produse
     */
    public static function html(array $bloc, array $produse, string $bazaUrl = ''): string
    {
        if ($produse === []) {
            return '';
        }
        $s = self::setari($bloc);
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $culoare = static function (mixed $v, string $implicit): string {
            $v = trim((string) $v);
            return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) === 1 ? $v : $implicit;
        };
        $perRand = $s['pe_rand'];
        $latimeMax = $perRand === 1 ? 320 : (int) floor(600 / $perRand);
        $fundalCard = $culoare($bloc['card_background'] ?? '', '#ffffff');
        $culoareText = $culoare($bloc['text_color'] ?? '', '#1f2937');
        $culoarePret = $culoare($bloc['price_color'] ?? '', '#1f2937');
        $culoareButon = $culoare($bloc['button_color'] ?? '', '#2d7d5f');
        $textButon = $culoare($bloc['button_text_color'] ?? '', '#ffffff');
        $eticheta = trim((string) ($bloc['button_label'] ?? ''));
        $eticheta = $eticheta !== '' ? $eticheta : 'Vezi produsul';
        $cuPret = (int) ($bloc['show_price'] ?? 1) === 1 && Plugins::vizibil('magazin');
        $cuButon = (int) ($bloc['show_button'] ?? 1) === 1;
        $absolut = static function (string $url) use ($bazaUrl): string {
            $url = trim($url);
            if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
                return $url;
            }
            return $url[0] === '/' && $bazaUrl !== '' ? $bazaUrl . $url : $url;
        };

        $celule = '';
        foreach (array_values($produse) as $k => $p) {
            $nume = (string) ($p['name'] ?? '');
            $adresa = $absolut((string) ($p['url'] ?? ''));
            $poza = trim((string) ($p['image_url'] ?? ''));
            $poza = self::pozaBuna($poza) ? $absolut($poza) : '';
            $interior = '';
            if ($poza !== '') {
                $img = '<img src="' . $e($poza) . '" alt="' . $e($nume) . '" width="' . ($latimeMax - 36) . '"'
                    . ' style="display:block;width:100%;max-width:' . ($latimeMax - 36) . 'px;height:auto;border:0;margin:0 auto;border-radius:6px;">';
                $interior .= $adresa !== '' ? '<a href="' . $e($adresa) . '" target="_blank" style="text-decoration:none;border:none;">' . $img . '</a>' : $img;
            }
            $interior .= '<div style="font-size:14px;font-weight:700;line-height:1.35;color:' . $culoareText . ';margin-top:' . ($poza !== '' ? 8 : 0) . 'px;">'
                . $e($nume) . '</div>';
            $pret = (float) ($p['price'] ?? 0);
            $vechi = isset($p['old_price']) && $p['old_price'] !== null ? (float) $p['old_price'] : null;
            if ($cuPret && $pret > 0) {
                $interior .= '<div style="font-size:14px;font-weight:700;color:' . $culoarePret . ';margin-top:4px;">'
                    . ($vechi !== null && $vechi > $pret ? '<s style="color:#9ca3af;font-weight:400;">' . $e(self::lei($vechi)) . '</s> ' : '')
                    . $e(self::lei($pret)) . '</div>';
            }
            if ($cuButon && $adresa !== '') {
                $interior .= '<div style="margin-top:10px;"><a href="' . $e($adresa) . '" target="_blank" style="display:inline-block;background:'
                    . $culoareButon . ';color:' . $textButon . ';padding:9px 16px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;">'
                    . $e($eticheta) . '</a></div>';
            }
            $inceputRand = $k % $perRand === 0;
            $celule .= ($inceputRand && $k > 0 ? '<!--[if mso]></tr><tr><![endif]-->' : '')
                . '<!--[if mso]><td width="' . (int) floor(100 / $perRand) . '%" valign="top"><![endif]-->'
                . '<div class="nl-prod" style="display:inline-block;vertical-align:top;width:100%;max-width:' . $latimeMax . 'px;font-size:14px;text-align:center;">'
                . '<div style="padding:8px;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;background:' . $fundalCard . ';border-radius:8px;">'
                . '<tr><td style="padding:10px;text-align:center;font-family:Arial,Helvetica,sans-serif;">' . $interior . '</td></tr></table>'
                . '</div></div>'
                . '<!--[if mso]></td><![endif]-->';
        }

        return '<!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;"><tr><![endif]-->'
            . $celule
            . '<!--[if mso]></tr></table><![endif]-->';
    }

    // ── Sursele ────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function noi(PDO $db, int $cate): array
    {
        return self::produse($db, self::conditieAuto(), [], 'p.created_at DESC, p.id DESC', $cate, true);
    }

    /** @return list<array<string, mixed>> */
    private static function vandute(PDO $db, int $zile, int $cate, array $faraIds = []): array
    {
        $cheie = $zile . '|' . $cate . '|' . implode(',', $faraIds);
        if (isset(self::$vandute[$cheie])) {
            return self::$vandute[$cheie];
        }
        [$fara, $param] = self::faraIds($faraIds);
        $vanzari = '(SELECT oi.product_id, SUM(oi.quantity) AS vandut FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id'
            . ' WHERE ' . self::conditieComanda('o') . ' AND o.created_at >= NOW() - INTERVAL ' . $zile . ' DAY GROUP BY oi.product_id)';
        $rezultat = self::produse($db, self::conditieAuto() . $fara, $param, 'v.vandut DESC, p.id DESC', $cate, true,
            'INNER JOIN ' . $vanzari . ' v ON v.product_id = p.id');
        if (count($rezultat) < $cate) {
            // Prea puține vânzări în perioadă: se completează cu cele mai noi (blocul nu rămâne gol).
            $avute = array_merge($faraIds, array_column($rezultat, 'id'));
            [$fara2, $param2] = self::faraIds($avute);
            $rezultat = array_merge($rezultat, self::produse($db, self::conditieAuto() . $fara2, $param2, 'p.created_at DESC, p.id DESC',
                $cate - count($rezultat), true));
        }
        return self::$vandute[$cheie] = $rezultat;
    }

    /** @return list<array<string, mixed>> */
    private static function dinCategorie(PDO $db, int $categorie, int $cate): array
    {
        if ($categorie <= 0) {
            return [];
        }
        $vanzari = '(SELECT oi.product_id, SUM(oi.quantity) AS vandut FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id'
            . ' WHERE ' . self::conditieComanda('o') . ' GROUP BY oi.product_id)';
        return self::produse(
            $db,
            self::conditieAuto() . ' AND (p.category_id = :cat OR EXISTS (SELECT 1 FROM product_category_links l WHERE l.product_id = p.id AND l.category_id = :cat2))',
            ['cat' => $categorie, 'cat2' => $categorie],
            'COALESCE(v.vandut, 0) DESC, p.id DESC',
            $cate,
            true,
            'LEFT JOIN ' . $vanzari . ' v ON v.product_id = p.id'
        );
    }

    /** @return list<array<string, mixed>> */
    private static function reduse(PDO $db, int $cate): array
    {
        $candidati = self::produse($db, self::conditieAuto() . ' AND ((p.sale_price IS NOT NULL AND p.sale_price > 0)'
            . ' OR (p.sale_price_periods_json IS NOT NULL AND p.sale_price_periods_json <> "" AND p.sale_price_periods_json <> "[]"))',
            [], 'p.id DESC', 200, true);
        $reduse = array_values(array_filter($candidati, static fn (array $p): bool => $p['old_price'] !== null));
        usort($reduse, static function (array $a, array $b): int {
            $ra = 1 - $a['price'] / max(0.01, (float) $a['old_price']);
            $rb = 1 - $b['price'] / max(0.01, (float) $b['old_price']);
            return $rb <=> $ra ?: $b['id'] <=> $a['id'];
        });
        return array_slice($reduse, 0, $cate);
    }

    /** @return list<array<string, mixed>> */
    private static function recomandate(PDO $db, string $email, int $cate): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::vandute($db, 90, $cate);
        }
        $comanda = $db->prepare('SELECT o.id FROM orders o WHERE LOWER(o.billing_email) = :email AND ' . self::conditieComanda('o')
            . ' ORDER BY o.created_at DESC, o.id DESC LIMIT 1');
        $comanda->execute(['email' => $email]);
        $idComanda = (int) ($comanda->fetchColumn() ?: 0);
        if ($idComanda <= 0) {
            return self::vandute($db, 90, $cate);
        }
        $cumparate = array_map('intval', $db->query('SELECT DISTINCT product_id FROM order_items WHERE order_id = ' . $idComanda
            . ' AND product_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $categorii = [];
        if ($cumparate !== []) {
            $lista = implode(',', $cumparate);
            $categorii = array_map('intval', array_merge(
                $db->query('SELECT DISTINCT category_id FROM products WHERE id IN (' . $lista . ') AND category_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) ?: [],
                $db->query('SELECT DISTINCT category_id FROM product_category_links WHERE product_id IN (' . $lista . ')')->fetchAll(PDO::FETCH_COLUMN) ?: []
            ));
            $categorii = array_values(array_unique(array_filter($categorii, static fn (int $v): bool => $v > 0)));
        }
        $rezultat = [];
        if ($categorii !== []) {
            [$fara, $param] = self::faraIds($cumparate);
            $listaCat = implode(',', $categorii);
            $vanzari = '(SELECT oi.product_id, SUM(oi.quantity) AS vandut FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id'
                . ' WHERE ' . self::conditieComanda('o') . ' GROUP BY oi.product_id)';
            $rezultat = self::produse($db, self::conditieAuto() . $fara . ' AND (p.category_id IN (' . $listaCat . ')'
                . ' OR EXISTS (SELECT 1 FROM product_category_links l WHERE l.product_id = p.id AND l.category_id IN (' . $listaCat . ')))',
                $param, 'COALESCE(v.vandut, 0) DESC, p.id DESC', $cate, true, 'LEFT JOIN ' . $vanzari . ' v ON v.product_id = p.id');
        }
        if (count($rezultat) < $cate) {
            $avute = array_merge($cumparate, array_column($rezultat, 'id'));
            $rezultat = array_merge($rezultat, self::vandute($db, 90, $cate - count($rezultat), $avute));
        }
        return array_slice($rezultat, 0, $cate);
    }

    // ── Citirea ────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $param
     * @return list<array{id: int, name: string, url: string, image_url: string, price: float, old_price: ?float}>
     */
    private static function produse(PDO $db, string $unde, array $param, string $ordine, int $cate, bool $doarCuPoza, string $join = ''): array
    {
        $cate = max(1, min(200, $cate));
        // De 3 ori mai multe rânduri: cele fără poză bună se sar după citire (SVG-ul nu se vede în Gmail).
        $limita = $doarCuPoza ? min(600, $cate * 3 + 10) : $cate;
        $stmt = $db->prepare('SELECT p.id, p.name, p.slug, p.price, p.sale_price, p.sale_price_periods_json, p.image_url FROM products p '
            . $join . ' WHERE ' . $unde . ' ORDER BY ' . $ordine . ' LIMIT ' . $limita);
        $stmt->execute($param);
        $rezultat = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $poza = trim((string) ($r['image_url'] ?? ''));
            if ($doarCuPoza && !self::pozaBuna($poza)) {
                continue;
            }
            [$pret, $vechi] = self::preturi($r);
            $slug = trim((string) ($r['slug'] ?? ''));
            $rezultat[] = [
                'id' => (int) $r['id'],
                'name' => trim((string) ($r['name'] ?? '')),
                'url' => $slug !== '' ? '/produs/' . rawurlencode($slug) : '',
                'image_url' => $poza,
                'price' => $pret,
                'old_price' => $vechi,
            ];
            if (count($rezultat) >= $cate) {
                break;
            }
        }
        return $rezultat;
    }

    private static function conditieAuto(): string
    {
        return 'p.deleted_at IS NULL AND p.is_active = 1 AND COALESCE(p.hidden_in_catalog, 0) = 0';
    }

    private static function conditieComanda(string $alias): string
    {
        return $alias . '.deleted_at IS NULL AND ' . $alias . '.status NOT IN ("' . implode('", "', self::STARI_FARA_VANZARE) . '")';
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private static function faraIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0)));
        if ($ids === []) {
            return ['', []];
        }
        $semne = [];
        $param = [];
        foreach ($ids as $k => $id) {
            $semne[] = ':fara' . $k;
            $param['fara' . $k] = $id;
        }
        return [' AND p.id NOT IN (' . implode(',', $semne) . ')', $param];
    }

    /** O poză care se vede într-un email (nu goală, nu SVG). */
    public static function pozaBuna(string $url): bool
    {
        $url = trim($url);
        return $url !== '' && preg_match('#\.svgz?(\?|$)#i', $url) !== 1;
    }

    /**
     * Prețul de acum și cel de dinainte (null fără reducere): prețul redus sau cel al unei perioade de reducere în curs (cel
     * mai mic), doar dacă e sub preț. Aceeași regulă ca la oferta campaniei (Campanii::preturi).
     *
     * @return array{0: float, 1: ?float}
     */
    private static function preturi(array $p): array
    {
        $baza = max(0.0, (float) ($p['price'] ?? 0));
        $redus = null;
        if (($p['sale_price'] ?? null) !== null && $p['sale_price'] !== '' && (float) $p['sale_price'] > 0) {
            $redus = (float) $p['sale_price'];
        }
        $perioade = json_decode((string) ($p['sale_price_periods_json'] ?? ''), true);
        $acum = new \DateTimeImmutable('now');
        $dinPerioada = null;
        foreach (is_array($perioade) ? $perioade : [] as $perioada) {
            $valoare = is_array($perioada) ? max(0.0, (float) ($perioada['sale_price'] ?? 0)) : 0.0;
            if ($valoare <= 0.0 || (string) ($perioada['start_date'] ?? '') === '' || (string) ($perioada['end_date'] ?? '') === '') {
                continue;
            }
            try {
                $inceput = new \DateTimeImmutable((string) $perioada['start_date']);
                $sfarsit = new \DateTimeImmutable((string) $perioada['end_date']);
            } catch (Throwable) {
                continue;
            }
            if ($acum >= $inceput && $acum <= $sfarsit && ($dinPerioada === null || $valoare < $dinPerioada)) {
                $dinPerioada = $valoare;
            }
        }
        $redus = $dinPerioada ?? $redus;
        if ($redus !== null && ($baza <= 0.0 || $redus < $baza)) {
            return [$redus, $baza > 0.0 ? $baza : null];
        }
        return [$baza, null];
    }

    /**
     * Copia salvată în bloc („Aleg eu”), curățată: folosită doar când catalogul nu se poate citi.
     *
     * @return list<array{id: int, name: string, url: string, image_url: string, price: float, old_price: ?float}>
     */
    private static function dinCopie(array $bloc): array
    {
        $rezultat = [];
        foreach (is_array($bloc['produse'] ?? null) ? $bloc['produse'] : [] as $p) {
            if (!is_array($p) || (int) ($p['id'] ?? 0) <= 0) {
                continue;
            }
            $url = trim((string) ($p['url'] ?? ''));
            $rezultat[] = [
                'id' => (int) $p['id'],
                'name' => trim((string) ($p['name'] ?? '')),
                'url' => preg_match('#^(/(?!/)|https?://)#i', $url) === 1 ? $url : '',
                'image_url' => trim((string) ($p['image_url'] ?? '')),
                'price' => max(0.0, (float) ($p['price'] ?? 0)),
                'old_price' => isset($p['old_price']) && $p['old_price'] !== null && (float) $p['old_price'] > 0 ? (float) $p['old_price'] : null,
            ];
        }
        return array_slice($rezultat, 0, 12);
    }

    private static function lei(float $suma): string
    {
        return number_format($suma, 2, ',', '.') . ' lei';
    }
}
