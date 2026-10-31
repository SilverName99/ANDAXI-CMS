<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Datele paginii „Acasă” a adminului (faza D, WP-32; macheta docs/ui/B-Acasa.dc.html): cifrele zilei, graficul vânzărilor
 * pe 14 zile, comenzile recente, lista „De făcut” și rândul de sănătate. Doar citiri, fiecare pe un index (indecșii
 * `magazin/0029` și `catalog/0025`, MIG-19): pagina se deschide la fel de repede cu 50 de comenzi sau cu 50.000.
 *
 * Fiecare bucată e a unui plugin: cifrele comenzilor și ale coșurilor doar cu magazinul, stocul doar cu magazinul (pe un
 * catalog de prezentare stocul nu înseamnă nimic). Intrările din „De făcut” și bucățile rândului de sănătate vin din
 * punctele `acasa.de_facut` și `acasa.sanatate` (App\Support\Hooks, config/hooks.php): o agățare a unui plugin pe care
 * site-ul nu-l are nu rulează, deci textul ei nu apare deloc (`$semneText` din tools/dev/test-pluginuri.php).
 *
 * Ce e „vânzare”: o comandă neștearsă care nu e anulată, eșuată, rambursată, returnată sau încă în așteptarea plății cu
 * cardul (`pending_payment`); suma e totalul comenzii, cu TVA și transport, în lei.
 */
final class AcasaAdmin
{
    /** Stările care nu sunt vânzări (comanda nu s-a încheiat sau banii s-au dat înapoi). */
    public const FARA_VANZARE = ['cancelled', 'failed', 'refunded', 'returned', 'pending_payment'];

    /** Stările unei comenzi încă de lucrat (de confirmat sau în procesare). */
    public const DE_LUCRAT = ['pending', 'processing'];

    /** Sub atâtea bucăți, un produs activ intră la „Stoc scăzut” (ca în machetă: „cu mai puțin de 5 bucăți”). */
    public const PRAG_STOC = 5;

    /** Câte zile arată graficul. */
    public const ZILE_GRAFIC = 14;

    /** Lista stărilor în SQL, din constantele de mai sus (doar cuvinte fixe, fără date de la utilizator). */
    private static function lista(array $stari): string
    {
        return implode(', ', array_map(static fn (string $s): string => "'" . preg_replace('/[^a-z_]/', '', $s) . "'", $stari));
    }

    /**
     * Comenzile de lucrat: câte noi (de confirmat), câte în procesare și ora celei mai vechi.
     *
     * @return array{total: int, noi: int, in_procesare: int, cea_mai_veche: ?string}
     */
    public static function deProcesat(PDO $db): array
    {
        $rezultat = ['total' => 0, 'noi' => 0, 'in_procesare' => 0, 'cea_mai_veche' => null];
        try {
            $stmt = $db->query(
                'SELECT status, COUNT(*) AS n, MIN(created_at) AS prima FROM orders
                  WHERE status IN (' . self::lista(self::DE_LUCRAT) . ') AND deleted_at IS NULL
                  GROUP BY status'
            );
            foreach ($stmt->fetchAll() ?: [] as $rand) {
                $n = (int) ($rand['n'] ?? 0);
                $rezultat['total'] += $n;
                $rezultat[(string) $rand['status'] === 'pending' ? 'noi' : 'in_procesare'] += $n;
                $prima = (string) ($rand['prima'] ?? '');
                if ($prima !== '' && ($rezultat['cea_mai_veche'] === null || strcmp($prima, $rezultat['cea_mai_veche']) < 0)) {
                    $rezultat['cea_mai_veche'] = $prima;
                }
            }
        } catch (Throwable) {
        }
        return $rezultat;
    }

    /**
     * Vânzările dintre două momente: numărul comenzilor și suma lor.
     *
     * @return array{comenzi: int, suma: float}
     */
    public static function vanzari(PDO $db, DateTimeImmutable $de, DateTimeImmutable $pana): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS suma FROM orders
                  WHERE created_at >= :de AND created_at <= :pana AND deleted_at IS NULL
                    AND status NOT IN (' . self::lista(self::FARA_VANZARE) . ')'
            );
            $stmt->execute(['de' => $de->format('Y-m-d H:i:s'), 'pana' => $pana->format('Y-m-d H:i:s')]);
            $rand = $stmt->fetch() ?: [];
            return ['comenzi' => (int) ($rand['n'] ?? 0), 'suma' => round((float) ($rand['suma'] ?? 0), 2)];
        } catch (Throwable) {
            return ['comenzi' => 0, 'suma' => 0.0];
        }
    }

    /**
     * Vânzările de azi (până acum) și cele din aceeași zi a săptămânii trecute, până la aceeași oră.
     *
     * @return array{azi: array{comenzi: int, suma: float}, saptamana_trecuta: array{comenzi: int, suma: float}, ziua_trecuta: string}
     */
    public static function vanzariAzi(PDO $db, DateTimeImmutable $acum): array
    {
        $azi = $acum->setTime(0, 0);
        $acumSaptamana = $acum->modify('-7 days');
        return [
            'azi' => self::vanzari($db, $azi, $acum),
            'saptamana_trecuta' => self::vanzari($db, $azi->modify('-7 days'), $acumSaptamana),
            'ziua_trecuta' => self::numeZi($acumSaptamana),
        ];
    }

    /**
     * Vânzările pe zile, ultimele ZILE_GRAFIC zile (azi inclusiv, până acum), plus totalul celor 14 zile dinainte.
     *
     * @return array{zile: list<array{data: string, eticheta: string, zi: string, comenzi: int, suma: float}>, total: float,
     *               comenzi: int, total_inainte: float}
     */
    public static function grafic(PDO $db, DateTimeImmutable $acum): array
    {
        $prima = $acum->setTime(0, 0)->modify('-' . (self::ZILE_GRAFIC - 1) . ' days');
        $peZi = [];
        try {
            $stmt = $db->prepare(
                'SELECT DATE(created_at) AS zi, COUNT(*) AS n, COALESCE(SUM(total), 0) AS suma FROM orders
                  WHERE created_at >= :de AND created_at <= :pana AND deleted_at IS NULL
                    AND status NOT IN (' . self::lista(self::FARA_VANZARE) . ')
                  GROUP BY DATE(created_at)'
            );
            $stmt->execute(['de' => $prima->format('Y-m-d H:i:s'), 'pana' => $acum->format('Y-m-d H:i:s')]);
            foreach ($stmt->fetchAll() ?: [] as $rand) {
                $peZi[(string) $rand['zi']] = ['comenzi' => (int) $rand['n'], 'suma' => round((float) $rand['suma'], 2)];
            }
        } catch (Throwable) {
        }
        $zile = [];
        $total = 0.0;
        $comenzi = 0;
        for ($i = 0; $i < self::ZILE_GRAFIC; $i++) {
            $zi = $prima->modify('+' . $i . ' days');
            $cheie = $zi->format('Y-m-d');
            $date = $peZi[$cheie] ?? ['comenzi' => 0, 'suma' => 0.0];
            $total += $date['suma'];
            $comenzi += $date['comenzi'];
            $zile[] = ['data' => $cheie, 'eticheta' => $zi->format('d'), 'zi' => $i === self::ZILE_GRAFIC - 1 ? 'azi' : self::numeZiScurt($zi)]
                + $date;
        }
        $inainte = self::vanzari($db, $prima->modify('-' . self::ZILE_GRAFIC . ' days'), $prima->modify('-1 second'));
        return ['zile' => $zile, 'total' => round($total, 2), 'comenzi' => $comenzi, 'total_inainte' => $inainte['suma']];
    }

    /**
     * Coșurile abandonate de azi (văzute ultima dată azi, de cel puțin pragul emailului de coș abandonat, fără comandă)
     * și câte dintre coșurile de azi s-au terminat cu o comandă.
     *
     * @return array{abandonate: int, recuperate: int}
     */
    public static function cosuriAzi(PDO $db, DateTimeImmutable $acum, array $setari): array
    {
        $minute = (int) ($setari['email_abandoned_after_minutes'] ?? 60);
        $minute = $minute > 0 ? $minute : 60;
        $azi = $acum->setTime(0, 0)->format('Y-m-d H:i:s');
        try {
            $stmt = $db->prepare(
                'SELECT SUM(converted_at IS NULL AND last_seen_at <= :prag) AS abandonate, SUM(converted_at IS NOT NULL) AS recuperate
                   FROM cart_abandonments WHERE last_seen_at >= :azi'
            );
            $stmt->execute(['azi' => $azi, 'prag' => $acum->modify('-' . $minute . ' minutes')->format('Y-m-d H:i:s')]);
            $rand = $stmt->fetch() ?: [];
            return ['abandonate' => (int) ($rand['abandonate'] ?? 0), 'recuperate' => (int) ($rand['recuperate'] ?? 0)];
        } catch (Throwable) {
            return ['abandonate' => 0, 'recuperate' => 0];
        }
    }

    /**
     * Produsele active cu stoc scăzut: întâi cele epuizate (bifa „Stoc epuizat” sau 0 bucăți), apoi cele sub PRAG_STOC.
     *
     * @return array{total: int, epuizate: int, putine: int, produse: list<array{id: int, nume: string, stoc: int, epuizat: bool}>}
     */
    public static function stocScazut(PDO $db, int $cate = 6): array
    {
        $rezultat = ['total' => 0, 'epuizate' => 0, 'putine' => 0, 'produse' => []];
        $produse = [];
        try {
            // Două interogări, fiecare pe indexul ei (catalog/0025): bifa „epuizat” și stocul mic.
            foreach ([
                'SELECT id, name, stock, out_of_stock FROM products WHERE out_of_stock = 1 AND is_active = 1 AND deleted_at IS NULL',
                'SELECT id, name, stock, out_of_stock FROM products WHERE is_active = 1 AND stock < ' . self::PRAG_STOC . ' AND deleted_at IS NULL',
            ] as $sql) {
                foreach ($db->query($sql)->fetchAll() ?: [] as $rand) {
                    $produse[(int) $rand['id']] = $rand;
                }
            }
        } catch (Throwable) {
            return $rezultat;
        }
        $lista = [];
        foreach ($produse as $id => $rand) {
            $stoc = max(0, (int) ($rand['stock'] ?? 0));
            $epuizat = (int) ($rand['out_of_stock'] ?? 0) === 1 || $stoc <= 0;
            $rezultat[$epuizat ? 'epuizate' : 'putine']++;
            $lista[] = ['id' => $id, 'nume' => (string) ($rand['name'] ?? ''), 'stoc' => $stoc, 'epuizat' => $epuizat];
        }
        usort($lista, static fn (array $a, array $b): int => [(int) !$a['epuizat'], $a['stoc'], $a['nume']] <=> [(int) !$b['epuizat'], $b['stoc'], $b['nume']]);
        $rezultat['total'] = count($lista);
        $rezultat['produse'] = array_slice($lista, 0, max(1, $cate));
        return $rezultat;
    }

    /**
     * Ultimele comenzi (după id, cel mai nou primul).
     *
     * @return list<array<string, mixed>>
     */
    public static function comenziRecente(PDO $db, int $cate = 7): array
    {
        try {
            $coloane = 'id, order_number, created_at, billing_first_name, billing_last_name, billing_email, total, status, payment_method, payment_status';
            if (Plugins::vizibil('erp')) {
                $coloane .= ', erp_status';
            }
            if (self::areColoana($db, 'orders', 'erp_client_agresiv')) {
                $coloane .= ', erp_client_agresiv';
            }
            return $db->query('SELECT ' . $coloane . ' FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT ' . max(1, min(50, $cate)))
                ->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Lista „De făcut”: intrările fiecărui plugin vizibil (punctul `acasa.de_facut`), doar cele cu ceva de făcut.
     *
     * @return list<array{plugin: string, numar: int, text: string, href: string, actiune: string, ton: string}>
     */
    public static function deFacut(PDO $db, array $setari): array
    {
        $lista = [];
        foreach (Hooks::ruleaza('acasa.de_facut', $db, $setari) as $plugin => $intrari) {
            foreach (is_array($intrari) ? $intrari : [] as $intrare) {
                if (!is_array($intrare) || (int) ($intrare['numar'] ?? 0) <= 0) {
                    continue;
                }
                $lista[] = [
                    'plugin' => (string) $plugin,
                    'numar' => (int) $intrare['numar'],
                    'text' => (string) ($intrare['text'] ?? ''),
                    'href' => (string) ($intrare['href'] ?? ''),
                    'actiune' => (string) ($intrare['actiune'] ?? 'Vezi'),
                    'ton' => in_array($intrare['ton'] ?? '', ['ok', 'atentie', 'eroare', 'info'], true) ? (string) $intrare['ton'] : 'atentie',
                ];
            }
        }
        return $lista;
    }

    /**
     * Rândul de sănătate: bucățile fiecărui plugin vizibil (punctul `acasa.sanatate`).
     *
     * @return list<array{plugin: string, text: string, ton: string, href: string}>
     */
    public static function sanatate(PDO $db, array $setari): array
    {
        $lista = [];
        foreach (Hooks::ruleaza('acasa.sanatate', $db, $setari) as $plugin => $bucati) {
            foreach (is_array($bucati) ? $bucati : [] as $bucata) {
                if (!is_array($bucata) || trim((string) ($bucata['text'] ?? '')) === '') {
                    continue;
                }
                $lista[] = [
                    'plugin' => (string) $plugin,
                    'text' => (string) $bucata['text'],
                    'ton' => in_array($bucata['ton'] ?? '', ['ok', 'atentie', 'eroare'], true) ? (string) $bucata['ton'] : 'ok',
                    'href' => (string) ($bucata['href'] ?? ''),
                ];
            }
        }
        return $lista;
    }

    // ------------------------------------------------- agățările pluginurilor (config/hooks.php)

    /** Câte comenzi sunt într-o condiție (pe indexul stării: doar comenzile cu stările date). */
    private static function numara(PDO $db, array $stari, string $conditie = '', array $parametri = []): int
    {
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM orders WHERE status IN (' . self::lista($stari) . ') AND deleted_at IS NULL'
                . ($conditie !== '' ? ' AND ' . $conditie : ''));
            $stmt->execute($parametri);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Magazinul: comenzile noi de confirmat, ordinele de plată de verificat în bancă și produsele cu stoc scăzut (o singură
     * agățare pe plugin: Hooks::ruleaza ține rezultatele după cheia pluginului).
     */
    public static function deFacutMagazin(PDO $db, array $setari): array
    {
        $noi = self::numara($db, ['pending']);
        $op = self::numara($db, ['pending', 'processing'], "LOWER(payment_method) = 'bank_transfer' AND LOWER(payment_status) <> 'paid'");
        $stoc = self::stocScazut($db)['total'];
        return [
            ['numar' => $noi, 'text' => $noi === 1 ? 'comandă nouă de confirmat' : 'comenzi noi de confirmat',
                'href' => '/admin/orders?status=pending', 'actiune' => 'Procesează'],
            ['numar' => $op, 'text' => $op === 1 ? 'ordin de plată de verificat în bancă' : 'ordine de plată de verificat în bancă',
                'href' => '/admin/orders?payment_method=bank_transfer&payment_status=unpaid', 'actiune' => 'Verifică'],
            ['numar' => $stoc, 'text' => $stoc === 1 ? 'produs cu stoc scăzut' : 'produse cu stoc scăzut',
                'href' => '#acasa-stoc', 'actiune' => 'Vezi lista', 'ton' => 'info'],
        ];
    }

    /** Livrarea FAN: comenzile în procesare care încă n-au AWB. */
    public static function deFacutFan(PDO $db, array $setari): array
    {
        $n = self::numara($db, ['processing'], "COALESCE(fan_awb, '') = ''");
        return [['numar' => $n, 'text' => $n === 1 ? 'comandă confirmată fără AWB' : 'comenzi confirmate fără AWB',
            'href' => '/admin/orders?status=processing&livrare=fara_awb', 'actiune' => 'Generează']];
    }

    /**
     * ERP: comenzile a căror trimitere a eșuat și care pot pleca (pragul `erp_trimite_din`, WP-12 E1: aceeași regulă ca
     * cronul, deci istoricul de dinainte de legare nu se numără). Doar cu ERP-ul pornit din setări.
     */
    public static function deFacutErp(PDO $db, array $setari): array
    {
        if ((string) ($setari['erp_enabled'] ?? '0') !== '1') {
            return [];
        }
        [$sqlPrag, $parametriPrag] = ErpSync::filtruPrag(ErpSync::pragTrimitere($db));
        $n = self::numara($db, ['pending', 'pending_payment', 'processing', 'completed'], "erp_status = 'failed'" . $sqlPrag, $parametriPrag);
        return [['numar' => $n, 'text' => $n === 1 ? 'comandă n-a ajuns în ERP' : 'comenzi n-au ajuns în ERP',
            'href' => '/admin/orders?erp=esuate', 'actiune' => 'Retrimite', 'ton' => 'eroare']];
    }

    /** Recenziile: cele care așteaptă aprobarea (fără spam), ca bulina din meniu. */
    public static function deFacutRecenzii(PDO $db, array $setari): array
    {
        try {
            try {
                $n = (int) $db->query('SELECT COUNT(*) FROM product_reviews WHERE is_approved = 0 AND is_spam = 0')->fetchColumn();
            } catch (Throwable) {
                $n = (int) $db->query('SELECT COUNT(*) FROM product_reviews WHERE is_approved = 0')->fetchColumn();
            }
        } catch (Throwable) {
            $n = 0;
        }
        return [['numar' => $n, 'text' => $n === 1 ? 'recenzie nouă de aprobat' : 'recenzii noi de aprobat',
            'href' => '/admin/products/reviews', 'actiune' => 'Vezi', 'ton' => 'info']];
    }

    /** Precomenzile: cele care așteaptă butonul ▶ (marfa n-a venit încă). */
    public static function deFacutPrecomenzi(PDO $db, array $setari): array
    {
        $n = self::numara($db, ['pending', 'pending_payment', 'processing'], "preorder_status = 'asteptare'");
        return [['numar' => $n, 'text' => $n === 1 ? 'Precomandă de eliberat' : 'Precomenzi de eliberat',
            'href' => '/admin/orders?precomanda=asteptare', 'actiune' => 'Vezi', 'ton' => 'info']];
    }

    /**
     * Linkul de plată: comenzile încă de lucrat, plătite, al căror total a crescut după încasare (diferența se cere prin
     * link). Doar cele de lucrat (pe indexul stării); lista „Doar cu rest de încasat” le arată pe toate, și pe cele încheiate.
     */
    public static function deFacutLinkPlata(PDO $db, array $setari): array
    {
        $n = self::numara($db, ['pending', 'pending_payment', 'processing'],
            "LOWER(payment_status) = 'paid' AND paid_amount IS NOT NULL AND ROUND(total - paid_amount, 2) > 0");
        return [['numar' => $n, 'text' => $n === 1 ? 'comandă cu diferență de încasat' : 'comenzi cu diferență de încasat',
            'href' => '/admin/orders?rest_incasat=1', 'actiune' => 'Trimite link de plată']];
    }

    /** Sănătate, ERP: pornit sau nu, câte trimiteri au eșuat, ultima trimitere reușită (ultimele 30 de zile). */
    public static function sanatateErp(PDO $db, array $setari): array
    {
        if ((string) ($setari['erp_enabled'] ?? '0') !== '1') {
            return [['text' => 'Legătura cu ERP-ul e oprită (Setări → ERP ANDAXI)', 'ton' => 'atentie', 'href' => '/admin/settings/erp']];
        }
        $esuate = self::deFacutErp($db, $setari)[0]['numar'] ?? 0;
        $ultima = null;
        try {
            $stmt = $db->prepare('SELECT MAX(erp_synced_at) FROM orders WHERE created_at >= :de');
            $stmt->execute(['de' => (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]);
            $ultima = $stmt->fetchColumn() ?: null;
        } catch (Throwable) {
        }
        $text = 'Legătura cu ERP-ul e pornită';
        if ($ultima !== null) {
            $text .= ' · ultima trimitere reușită: ' . self::cand((string) $ultima);
        }
        if ($esuate > 0) {
            $text .= ' · ' . $esuate . ($esuate === 1 ? ' trimitere eșuată' : ' trimiteri eșuate');
        }
        return [['text' => $text, 'ton' => $esuate > 0 ? 'eroare' : 'ok', 'href' => '/admin/settings/erp']];
    }

    /** Sănătate, FAN Courier: contul e completat în Setări livrare? */
    public static function sanatateFan(PDO $db, array $setari): array
    {
        $complet = (int) ($setari['fan_client_id'] ?? 0) > 0 && trim((string) ($setari['fan_api_username'] ?? '')) !== ''
            && trim((string) ($setari['fan_api_password'] ?? '')) !== '';
        return [$complet
            ? ['text' => 'FAN Courier: contul e completat', 'ton' => 'ok', 'href' => '/admin/settings/shipping']
            : ['text' => 'FAN Courier: contul nu e completat (Setări livrare)', 'ton' => 'atentie', 'href' => '/admin/settings/shipping']];
    }

    // ------------------------------------------------- texte

    /** „azi, 10:42”, „ieri, 18:05” sau „28.10, 09:12”. */
    public static function cand(string $moment, ?DateTimeImmutable $acum = null): string
    {
        $ts = strtotime($moment);
        if ($ts === false) {
            return $moment;
        }
        $acum ??= new DateTimeImmutable();
        $zi = date('Y-m-d', $ts);
        if ($zi === $acum->format('Y-m-d')) {
            return 'azi, ' . date('H:i', $ts);
        }
        if ($zi === $acum->modify('-1 day')->format('Y-m-d')) {
            return 'ieri, ' . date('H:i', $ts);
        }
        return date('d.m', $ts) . ', ' . date('H:i', $ts);
    }

    /** O sumă în lei, ca în machete: „4.287,40”. */
    public static function lei(float $suma): string
    {
        return number_format($suma, 2, ',', '.');
    }

    public static function numeZi(DateTimeImmutable $zi): string
    {
        return ['duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă'][(int) $zi->format('w')];
    }

    public static function numeZiScurt(DateTimeImmutable $zi): string
    {
        return ['Du', 'Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ'][(int) $zi->format('w')];
    }

    /** „Sâmbătă, 31 octombrie 2026”. */
    public static function dataLunga(DateTimeImmutable $zi): string
    {
        $luni = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie',
            'noiembrie', 'decembrie'];
        return mb_convert_case(self::numeZi($zi), MB_CASE_TITLE, 'UTF-8') . ', ' . (int) $zi->format('j') . ' '
            . $luni[(int) $zi->format('n') - 1] . ' ' . $zi->format('Y');
    }

    private static function areColoana(PDO $db, string $tabel, string $coloana): bool
    {
        try {
            $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$tabel, $coloana]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
