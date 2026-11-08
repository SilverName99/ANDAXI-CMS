<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Rapoartele statisticilor (Rapoarte → Statistici; pluginul `statistici`): totul din agregate (App\Support\StatisticiAgregare),
 * plus „Acum pe site” din evenimentele brute ale ultimelor 5 minute. Filtrele (sursa, detaliul ei, campania utm, dispozitivul)
 * se aplică vizitelor și comenzilor; paginilor, produselor și căutărilor doar sursa (ele nu țin restul vizitei).
 *
 * Comenzile și veniturile: comenzile atribuite (statistici_comenzi) care sunt vânzări acum (neșterse, nu anulate, eșuate,
 * rambursate, returnate sau încă neplătite: AcasaAdmin::FARA_VANZARE), cu totalul lor de acum din `orders`.
 */
final class StatisticiRaport
{
    public const FILTRE = ['sursa', 'detaliu', 'campanie', 'dispozitiv'];
    public const PERIOADE = ['azi' => 'Azi', 'ieri' => 'Ieri', '7' => 'Ultimele 7 zile', '30' => 'Ultimele 30 de zile',
        '90' => 'Ultimele 90 de zile', 'custom' => 'Altă perioadă'];

    /**
     * Perioada cerută (`perioada`, `de_la`, `pana_la` din adresă) și cea dinainte, de aceeași lungime.
     *
     * @param array<string, mixed> $get
     * @return array{cheie: string, de_la: string, pana_la: string, eticheta: string, zile: int, pe_ora: bool, inainte_de_la: string, inainte_pana_la: string}
     */
    public static function perioada(array $get, ?int $acum = null): array
    {
        $acum ??= time();
        $azi = date('Y-m-d', $acum);
        $cheie = is_string($get['perioada'] ?? null) && isset(self::PERIOADE[$get['perioada']]) ? (string) $get['perioada'] : '7';
        $data = static fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1
            && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;
        switch ($cheie) {
            case 'azi':
                $deLa = $panaLa = $azi;
                break;
            case 'ieri':
                $deLa = $panaLa = date('Y-m-d', (int) strtotime($azi . ' -1 day'));
                break;
            case 'custom':
                $deLa = $data($get['de_la'] ?? null) ?? date('Y-m-d', (int) strtotime($azi . ' -6 days'));
                $panaLa = $data($get['pana_la'] ?? null) ?? $azi;
                if ($panaLa > $azi) {
                    $panaLa = $azi;
                }
                if ($deLa > $panaLa) {
                    [$deLa, $panaLa] = [$panaLa, $deLa];
                }
                if ((int) ((strtotime($panaLa) - strtotime($deLa)) / 86400) > 731) {
                    $deLa = date('Y-m-d', (int) strtotime($panaLa . ' -731 days'));
                }
                break;
            default:
                $deLa = date('Y-m-d', (int) strtotime($azi . ' -' . ((int) $cheie - 1) . ' days'));
                $panaLa = $azi;
        }
        $zile = (int) round((strtotime($panaLa) - strtotime($deLa)) / 86400) + 1;
        $inainteDeLa = date('Y-m-d', (int) strtotime($deLa . ' -' . $zile . ' days'));
        $inaintePanaLa = date('Y-m-d', (int) strtotime($deLa . ' -1 day'));
        $eticheta = $deLa === $panaLa ? date('d.m.Y', (int) strtotime($deLa)) : date('d.m.Y', (int) strtotime($deLa)) . ' – ' . date('d.m.Y', (int) strtotime($panaLa));
        return ['cheie' => $cheie, 'de_la' => $deLa, 'pana_la' => $panaLa, 'eticheta' => $eticheta, 'zile' => $zile, 'pe_ora' => $zile <= 2,
            'inainte_de_la' => $inainteDeLa, 'inainte_pana_la' => $inaintePanaLa];
    }

    /**
     * Filtrele din adresă (valori scurte, text).
     *
     * @param array<string, mixed> $get
     * @return array<string, string>
     */
    public static function filtre(array $get): array
    {
        $f = [];
        foreach (self::FILTRE as $cheie) {
            $v = $get[$cheie] ?? null;
            if (is_string($v) && trim($v) !== '' && strlen($v) <= 120) {
                $f[$cheie] = trim($v);
            }
        }
        return $f;
    }

    /**
     * WHERE pentru vizite sau comenzi atribuite (aceleași nume de coloane), pe perioada [de la, până la].
     *
     * @param array<string, string> $f
     * @return array{0: string, 1: list<string>}
     */
    private static function unde(string $deLa, string $panaLa, array $f, string $alias = 'v', bool $doarSursa = false): array
    {
        $sql = $alias . '.zi >= ? AND ' . $alias . '.zi <= ?';
        $p = [$deLa, $panaLa];
        $coloane = ['sursa' => 'sursa', 'detaliu' => 'detaliu', 'campanie' => 'utm_campanie', 'dispozitiv' => 'dispozitiv'];
        foreach ($coloane as $cheie => $coloana) {
            if (!isset($f[$cheie]) || ($doarSursa && $cheie !== 'sursa')) {
                continue;
            }
            $sql .= ' AND ' . $alias . '.' . $coloana . ' = ?';
            $p[] = $f[$cheie];
        }
        return [$sql, $p];
    }

    /** Condiția „comanda e o vânzare acum” pe `orders o`. */
    private static function vanzare(): string
    {
        return "o.deleted_at IS NULL AND o.status NOT IN ('" . implode("', '", AcasaAdmin::FARA_VANZARE) . "')";
    }

    /** @param list<mixed> $p */
    private static function randuri(PDO $db, string $sql, array $p): array
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($p);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Cifrele perioadei.
     *
     * @param array<string, string> $f
     * @return array<string, float|int>
     */
    public static function kpi(PDO $db, string $deLa, string $panaLa, array $f): array
    {
        [$w, $p] = self::unde($deLa, $panaLa, $f);
        $r = self::randuri($db, 'SELECT COUNT(DISTINCT v.zi, v.vizitator_nr) AS vizitatori, COUNT(*) AS vizite, COALESCE(SUM(v.pagini), 0) AS pagini,
                COALESCE(AVG(v.durata), 0) AS durata, COALESCE(AVG(v.angajata), 0) AS angajate, COALESCE(SUM(v.adaugari_cos), 0) AS adaugari,
                COALESCE(SUM(v.checkout > 0), 0) AS checkout
            FROM statistici_vizite v WHERE ' . $w, $p)[0] ?? [];
        [$wc, $pc] = self::unde($deLa, $panaLa, $f, 'c');
        $c = self::randuri($db, 'SELECT COUNT(*) AS comenzi, COALESCE(SUM(o.total), 0) AS venit FROM statistici_comenzi c
            JOIN orders o ON o.id = c.comanda_id WHERE ' . $wc . ' AND ' . self::vanzare(), $pc)[0] ?? [];
        $vizite = (int) ($r['vizite'] ?? 0);
        $comenzi = (int) ($c['comenzi'] ?? 0);
        return [
            'vizitatori' => (int) ($r['vizitatori'] ?? 0),
            'vizite' => $vizite,
            'pagini' => (int) ($r['pagini'] ?? 0),
            'pagini_vizita' => $vizite > 0 ? (int) ($r['pagini'] ?? 0) / $vizite : 0.0,
            'durata' => (float) ($r['durata'] ?? 0),
            'respingere' => $vizite > 0 ? 1 - (float) ($r['angajate'] ?? 0) : 0.0,
            'adaugari' => (int) ($r['adaugari'] ?? 0),
            'checkout' => (int) ($r['checkout'] ?? 0),
            'comenzi' => $comenzi,
            'conversie' => $vizite > 0 ? $comenzi / $vizite : 0.0,
            'venit' => round((float) ($c['venit'] ?? 0), 2),
        ];
    }

    /**
     * Seria graficului: pe ore (perioade de 1–2 zile) sau pe zile. Vizitele după ora începutului; comenzile după ora plasării.
     *
     * @param array<string, string> $f
     * @return list<array{cheie: string, eticheta: string, vizite: int, pagini: int, comenzi: int, venit: float}>
     */
    public static function serie(PDO $db, string $deLa, string $panaLa, array $f, bool $peOra): array
    {
        $puncte = [];
        if ($peOra) {
            for ($t = (int) strtotime($deLa . ' 00:00:00'); $t < (int) strtotime($panaLa . ' +1 day'); $t += 3600) {
                $puncte[date('Y-m-d H', $t)] = ['cheie' => date('Y-m-d H:00', $t), 'eticheta' => date('H', $t) . ':00', 'vizite' => 0, 'pagini' => 0, 'comenzi' => 0, 'venit' => 0.0];
            }
            $grupV = "DATE_FORMAT(v.inceput, '%Y-%m-%d %H')";
            $grupC = "DATE_FORMAT(c.creat_la, '%Y-%m-%d %H')";
        } else {
            for ($t = (int) strtotime($deLa); $t <= (int) strtotime($panaLa); $t = (int) strtotime(date('Y-m-d', $t) . ' +1 day')) {
                $puncte[date('Y-m-d', $t)] = ['cheie' => date('Y-m-d', $t), 'eticheta' => date('d.m', $t), 'vizite' => 0, 'pagini' => 0, 'comenzi' => 0, 'venit' => 0.0];
            }
            $grupV = 'v.zi';
            $grupC = 'c.zi';
        }
        [$w, $p] = self::unde($deLa, $panaLa, $f);
        foreach (self::randuri($db, 'SELECT ' . $grupV . ' AS k, COUNT(*) AS vizite, SUM(v.pagini) AS pagini FROM statistici_vizite v WHERE ' . $w
            . ' GROUP BY k', $p) as $r) {
            if (isset($puncte[(string) $r['k']])) {
                $puncte[(string) $r['k']]['vizite'] = (int) $r['vizite'];
                $puncte[(string) $r['k']]['pagini'] = (int) $r['pagini'];
            }
        }
        [$wc, $pc] = self::unde($deLa, $panaLa, $f, 'c');
        foreach (self::randuri($db, 'SELECT ' . $grupC . ' AS k, COUNT(*) AS comenzi, SUM(o.total) AS venit FROM statistici_comenzi c
            JOIN orders o ON o.id = c.comanda_id WHERE ' . $wc . ' AND ' . self::vanzare() . ' GROUP BY k', $pc) as $r) {
            if (isset($puncte[(string) $r['k']])) {
                $puncte[(string) $r['k']]['comenzi'] = (int) $r['comenzi'];
                $puncte[(string) $r['k']]['venit'] = round((float) $r['venit'], 2);
            }
        }
        return array_values($puncte);
    }

    /**
     * Vizitele, comenzile și veniturile grupate după o coloană a vizitei (`sursa`, `detaliu`, `utm_campanie`, `pagina_intrare`,
     * `dispozitiv`, `browser`, `os`, `tara`), cu filtrele.
     *
     * @param array<string, string> $f
     * @param list<string> $coloane
     * @return list<array<string, mixed>>
     */
    public static function grupeVizite(PDO $db, string $deLa, string $panaLa, array $f, array $coloane, int $limita = 50, string $conditie = ''): array
    {
        $permise = ['sursa', 'detaliu', 'utm_sursa', 'utm_mediu', 'utm_campanie', 'utm_continut', 'pagina_intrare', 'dispozitiv', 'browser', 'os', 'tara'];
        $coloane = array_values(array_intersect($coloane, $permise));
        if ($coloane === []) {
            return [];
        }
        [$w, $p] = self::unde($deLa, $panaLa, $f);
        $lista = implode(', ', array_map(static fn (string $c): string => 'v.' . $c, $coloane));
        $sql = 'SELECT ' . $lista . ', COUNT(*) AS vizite, COUNT(DISTINCT v.zi, v.vizitator_nr) AS vizitatori, SUM(v.pagini) AS pagini,
                AVG(v.durata) AS durata, AVG(v.angajata) AS angajate, SUM(v.adaugari_cos) AS adaugari, SUM(v.comenzi > 0) AS cu_comanda
            FROM statistici_vizite v WHERE ' . $w . ($conditie !== '' ? ' AND ' . $conditie : '') . ' GROUP BY ' . $lista . ' ORDER BY vizite DESC LIMIT ' . max(1, $limita);
        $randuri = self::randuri($db, $sql, $p);
        // Comenzile și veniturile atribuite, pe aceleași chei (doar coloanele pe care le are și atribuirea).
        $comune = array_values(array_intersect($coloane, ['sursa', 'detaliu', 'utm_campanie', 'dispozitiv']));
        $vanzari = [];
        if ($comune === $coloane) {
            [$wc, $pc] = self::unde($deLa, $panaLa, $f, 'c');
            $listaC = implode(', ', array_map(static fn (string $c): string => 'c.' . $c, $coloane));
            foreach (self::randuri($db, 'SELECT ' . $listaC . ', COUNT(*) AS comenzi, SUM(o.total) AS venit FROM statistici_comenzi c
                JOIN orders o ON o.id = c.comanda_id WHERE ' . $wc . ' AND ' . self::vanzare() . ' GROUP BY ' . $listaC, $pc) as $r) {
                $vanzari[implode("\n", array_map(static fn (string $c): string => (string) $r[$c], $coloane))] = $r;
            }
        } else {
            // Pagina de intrare, browserul…: comenzile din vizitele însele (fără venit).
            $vanzari = null;
        }
        foreach ($randuri as &$r) {
            $cheie = implode("\n", array_map(static fn (string $c): string => (string) $r[$c], $coloane));
            $r['vizite'] = (int) $r['vizite'];
            $r['vizitatori'] = (int) $r['vizitatori'];
            $r['pagini'] = (int) $r['pagini'];
            $r['durata'] = (float) $r['durata'];
            $r['respingere'] = 1 - (float) $r['angajate'];
            $r['adaugari'] = (int) $r['adaugari'];
            $r['comenzi'] = $vanzari === null ? (int) $r['cu_comanda'] : (int) ($vanzari[$cheie]['comenzi'] ?? 0);
            $r['venit'] = $vanzari === null ? null : round((float) ($vanzari[$cheie]['venit'] ?? 0), 2);
            $r['conversie'] = $r['vizite'] > 0 ? $r['comenzi'] / $r['vizite'] : 0.0;
            unset($r['angajate'], $r['cu_comanda']);
        }
        unset($r);
        return $randuri;
    }

    /**
     * Comenzile atribuite fără nicio vizită (vizitatorul n-a încărcat scriptul: blocat, sau a comandat din altă zi).
     *
     * @param array<string, string> $f
     * @return array{comenzi: int, venit: float}
     */
    public static function comenziNecunoscute(PDO $db, string $deLa, string $panaLa, array $f): array
    {
        if (isset($f['sursa']) && $f['sursa'] !== 'necunoscuta') {
            return ['comenzi' => 0, 'venit' => 0.0];
        }
        $r = self::randuri($db, "SELECT COUNT(*) AS comenzi, COALESCE(SUM(o.total), 0) AS venit FROM statistici_comenzi c JOIN orders o ON o.id = c.comanda_id
            WHERE c.zi >= ? AND c.zi <= ? AND c.sursa = 'necunoscuta' AND " . self::vanzare(), [$deLa, $panaLa])[0] ?? [];
        return ['comenzi' => (int) ($r['comenzi'] ?? 0), 'venit' => round((float) ($r['venit'] ?? 0), 2)];
    }

    /**
     * Paginile (sau doar un fel de pagină: `categorie`), după vizualizări.
     *
     * @param array<string, string> $f
     * @return list<array<string, mixed>>
     */
    public static function pagini(PDO $db, string $deLa, string $panaLa, array $f, ?string $tip = null, int $limita = 50): array
    {
        [$w, $p] = self::unde($deLa, $panaLa, $f, 'p', true);
        if ($tip !== null) {
            $w .= ' AND p.tip_pagina = ?';
            $p[] = $tip;
        }
        return array_map(static fn (array $r): array => ['cale' => (string) $r['cale'], 'tip_pagina' => (string) $r['tip_pagina'],
            'vizualizari' => (int) $r['vizualizari'], 'vizitatori' => (int) $r['vizitatori'], 'intrari' => (int) $r['intrari']],
            self::randuri($db, 'SELECT p.cale, MAX(p.tip_pagina) AS tip_pagina, SUM(p.vizualizari) AS vizualizari, SUM(p.vizitatori) AS vizitatori,
                SUM(p.intrari) AS intrari FROM statistici_pagini p WHERE ' . $w . ' GROUP BY p.cale ORDER BY vizualizari DESC LIMIT ' . max(1, $limita), $p));
    }

    /**
     * Produsele cele mai văzute, cu adăugările în coș și rata lor.
     *
     * @param array<string, string> $f
     * @return list<array<string, mixed>>
     */
    public static function produse(PDO $db, string $deLa, string $panaLa, array $f, int $limita = 50): array
    {
        [$w, $p] = self::unde($deLa, $panaLa, $f, 'p', true);
        $randuri = self::randuri($db, 'SELECT p.produs_id, SUM(p.vizualizari) AS vizualizari, SUM(p.vizitatori) AS vizitatori, SUM(p.adaugari_cos) AS adaugari
            FROM statistici_produse p WHERE ' . $w . ' GROUP BY p.produs_id ORDER BY vizualizari DESC, adaugari DESC LIMIT ' . max(1, $limita), $p);
        $nume = [];
        $iduri = array_map(static fn (array $r): int => (int) $r['produs_id'], $randuri);
        if ($iduri !== []) {
            try {
                foreach (self::randuri($db, 'SELECT id, name, slug FROM products WHERE id IN (' . implode(',', array_fill(0, count($iduri), '?')) . ')', $iduri) as $r) {
                    $nume[(int) $r['id']] = ['nume' => (string) $r['name'], 'slug' => (string) $r['slug']];
                }
            } catch (Throwable) {
            }
        }
        return array_map(static fn (array $r): array => [
            'produs_id' => (int) $r['produs_id'],
            'nume' => $nume[(int) $r['produs_id']]['nume'] ?? ('Produsul #' . (int) $r['produs_id']),
            'slug' => $nume[(int) $r['produs_id']]['slug'] ?? '',
            'vizualizari' => (int) $r['vizualizari'],
            'vizitatori' => (int) $r['vizitatori'],
            'adaugari' => (int) $r['adaugari'],
            'rata_cos' => (int) $r['vizitatori'] > 0 ? min(1.0, (int) $r['adaugari'] / (int) $r['vizitatori']) : 0.0,
        ], $randuri);
    }

    /**
     * Căutările pe site (toate sau doar cele fără rezultat).
     *
     * @param array<string, string> $f
     * @return list<array{cautare: string, cautari: int, fara_rezultat: int}>
     */
    public static function cautari(PDO $db, string $deLa, string $panaLa, array $f, bool $doarFaraRezultat = false, int $limita = 50): array
    {
        [$w, $p] = self::unde($deLa, $panaLa, $f, 'c', true);
        $randuri = self::randuri($db, 'SELECT c.cautare, SUM(c.cautari) AS cautari, SUM(c.fara_rezultat) AS fara_rezultat FROM statistici_cautari c
            WHERE ' . $w . ' GROUP BY c.cautare' . ($doarFaraRezultat ? ' HAVING fara_rezultat > 0' : '') . ' ORDER BY '
            . ($doarFaraRezultat ? 'fara_rezultat' : 'cautari') . ' DESC, c.cautare LIMIT ' . max(1, $limita), $p);
        return array_map(static fn (array $r): array => ['cautare' => (string) $r['cautare'], 'cautari' => (int) $r['cautari'],
            'fara_rezultat' => (int) $r['fara_rezultat']], $randuri);
    }

    /**
     * Sursa comenzilor din `orders.sursa` (WP-40, cookie-ul cu acord), pe perioadă: doar unde e cunoscută.
     *
     * @return list<array{sursa: string, comenzi: int, venit: float}>
     */
    public static function surseComenziCookie(PDO $db, string $deLa, string $panaLa): array
    {
        try {
            $randuri = self::randuri($db, "SELECT COALESCE(NULLIF(o.sursa, ''), 'necunoscuta') AS sursa, COUNT(*) AS comenzi, COALESCE(SUM(o.total), 0) AS venit
                FROM orders o WHERE o.created_at >= ? AND o.created_at < ? AND " . self::vanzare() . ' GROUP BY 1 ORDER BY comenzi DESC',
                [$deLa . ' 00:00:00', date('Y-m-d', (int) strtotime($panaLa . ' +1 day')) . ' 00:00:00']);
        } catch (Throwable) {
            return [];
        }
        return array_map(static fn (array $r): array => ['sursa' => (string) $r['sursa'], 'comenzi' => (int) $r['comenzi'],
            'venit' => round((float) $r['venit'], 2)], $randuri);
    }

    /**
     * „Acum pe site”: vizitatorii din ultimele 5 minute (din evenimentele brute), cu paginile și sursele lor.
     *
     * @return array{vizitatori: int, pagini: list<array{cale: string, vizitatori: int}>, surse: list<array{sursa: string, vizitatori: int}>, la: string}
     */
    public static function acum(PDO $db, ?int $acum = null): array
    {
        $acum ??= time();
        $deLa = date('Y-m-d H:i:s', $acum - 300);
        $total = (int) (self::randuri($db, 'SELECT COUNT(DISTINCT vizitator) AS n FROM statistici_evenimente WHERE creat_la >= ?', [$deLa])[0]['n'] ?? 0);
        $pagini = array_map(static fn (array $r): array => ['cale' => (string) $r['cale'], 'vizitatori' => (int) $r['n']],
            self::randuri($db, 'SELECT cale, COUNT(DISTINCT vizitator) AS n FROM statistici_evenimente WHERE creat_la >= ? AND tip = 1
                GROUP BY cale ORDER BY n DESC LIMIT 5', [$deLa]));
        $surse = array_map(static fn (array $r): array => ['sursa' => (string) $r['sursa'], 'vizitatori' => (int) $r['n']],
            self::randuri($db, "SELECT sursa, COUNT(DISTINCT vizitator) AS n FROM statistici_evenimente WHERE creat_la >= ? AND tip = 1
                AND sursa NOT IN ('', 'intern') GROUP BY sursa ORDER BY n DESC LIMIT 5", [$deLa]));
        return ['vizitatori' => $total, 'pagini' => $pagini, 'surse' => $surse, 'la' => date('H:i:s', $acum)];
    }

    /**
     * Impactul unei postări sau campanii: vizitele, comenzile și veniturile pe ore, de la `$start`, dintr-o sursă (sau o campanie
     * utm), față de aceleași ore cu o săptămână înainte.
     *
     * @return list<array{ora: string, eticheta: string, vizite: int, comenzi: int, venit: float, vizite_inainte: int, comenzi_inainte: int, venit_inainte: float}>
     */
    public static function impact(PDO $db, string $sursa, string $campanie, int $start, int $ore = 48): array
    {
        $ore = max(1, min(168, $ore));
        $start -= $start % 3600;
        $conditie = $campanie !== '' ? 'utm_campanie = ?' : 'sursa = ?';
        $valoare = $campanie !== '' ? $campanie : $sursa;
        $serie = static function (int $de) use ($db, $conditie, $valoare, $ore): array {
            $deLa = date('Y-m-d H:i:s', $de);
            $panaLa = date('Y-m-d H:i:s', $de + $ore * 3600);
            $v = [];
            foreach (self::randuri($db, "SELECT DATE_FORMAT(inceput, '%Y-%m-%d %H') AS k, COUNT(*) AS n FROM statistici_vizite
                WHERE inceput >= ? AND inceput < ? AND " . $conditie . ' GROUP BY k', [$deLa, $panaLa, $valoare]) as $r) {
                $v[(string) $r['k']] = (int) $r['n'];
            }
            $c = [];
            foreach (self::randuri($db, "SELECT DATE_FORMAT(c.creat_la, '%Y-%m-%d %H') AS k, COUNT(*) AS n, SUM(o.total) AS venit FROM statistici_comenzi c
                JOIN orders o ON o.id = c.comanda_id WHERE c.creat_la >= ? AND c.creat_la < ? AND c." . $conditie . ' AND ' . self::vanzare()
                . ' GROUP BY k', [$deLa, $panaLa, $valoare]) as $r) {
                $c[(string) $r['k']] = ['n' => (int) $r['n'], 'venit' => round((float) $r['venit'], 2)];
            }
            return [$v, $c];
        };
        [$v, $c] = $serie($start);
        [$vi, $ci] = $serie($start - 7 * 86400);
        $rez = [];
        for ($i = 0; $i < $ore; $i++) {
            $t = $start + $i * 3600;
            $k = date('Y-m-d H', $t);
            $ki = date('Y-m-d H', $t - 7 * 86400);
            $rez[] = ['ora' => date('Y-m-d H:00', $t), 'eticheta' => date('d.m H', $t) . ':00', 'vizite' => $v[$k] ?? 0,
                'comenzi' => $c[$k]['n'] ?? 0, 'venit' => $c[$k]['venit'] ?? 0.0,
                'vizite_inainte' => $vi[$ki] ?? 0, 'comenzi_inainte' => $ci[$ki]['n'] ?? 0, 'venit_inainte' => $ci[$ki]['venit'] ?? 0.0];
        }
        return $rez;
    }

    /** Numele unei surse (cheia, dacă nu e cunoscută). */
    public static function numeSursa(string $sursa): string
    {
        return Statistici::SURSE[$sursa] ?? match ($sursa) {
            'necunoscuta' => 'Necunoscută (fără vizită numărată)',
            default => $sursa,
        };
    }

    /** Campaniile utm cunoscute (pentru lista „Impactul”), după vizite, în ultimele 90 de zile. */
    public static function campaniiRecente(PDO $db, ?int $acum = null): array
    {
        $acum ??= time();
        return array_map(static fn (array $r): string => (string) $r['utm_campanie'], self::randuri($db, "SELECT utm_campanie, COUNT(*) AS n
            FROM statistici_vizite WHERE zi >= ? AND utm_campanie <> '' GROUP BY utm_campanie ORDER BY n DESC LIMIT 50",
            [date('Y-m-d', $acum - 90 * 86400)]));
    }
}
