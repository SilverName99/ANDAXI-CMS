<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Agregarea statisticilor (pluginul `statistici`): evenimentele brute ale unei zile devin vizite, pagini, produse, căutări și
 * comenzile atribuite. Rulează din cron (`scripts/statistici-agregare.php`, o dată pe oră) și, dacă cronul lipsește, la
 * deschiderea paginii din admin (`laNevoie`).
 *
 * Fiecare zi se reface întreagă (șterge și scrie, într-o tranzacție), deci rularea e idempotentă. Sarea se schimbă la miezul
 * nopții, deci un vizitator nu trece dintr-o zi în alta: zilele sunt independente. O zi e „încheiată” când s-a agregat după
 * 00:15 a zilei următoare (ultimele plecări au sosit); atunci nu se mai reface, iar hash-ul de pe comenzile ei se golește.
 *
 * **Vizita**: același vizitator, cu cel mult 30 de minute între două evenimente. Sursa ei e a primei pagini văzute (o pagină
 * cerută din site, `intern`, la începutul unei vizite noi = direct). „Angajată” (opusul respingerii): cel puțin două pagini,
 * cel puțin 10 secunde active sau o adăugare în coș / o comandă.
 * **Comanda** se atribuie ultimei vizite a vizitatorului din aceeași zi, de dinaintea comenzii, care n-a fost „direct”
 * (ca în Google Analytics); fără ea, vizitei în care a fost plasată; fără nicio vizită, sursa „necunoscuta”.
 */
final class StatisticiAgregare
{
    public const PAUZA_VIZITA = 1800;
    private const INCHEIERE_DUPA = 900;
    private const LACAT = 'andaxi_statistici_agregare';

    /**
     * Agregă zilele neîncheiate (cel mult `$maxZile`, de la ultima încheiată până azi), apoi șterge evenimentele brute vechi și
     * sărurile altor zile. Întoarce ce a făcut.
     *
     * @param array<string, mixed> $setari
     * @return array{zile: list<string>, evenimente: int, vizite: int, sterse: int, ocupat: bool}
     */
    public static function ruleaza(PDO $db, array $setari, ?int $acum = null, int $maxZile = 100): array
    {
        $acum ??= time();
        $rez = ['zile' => [], 'evenimente' => 0, 'vizite' => 0, 'sterse' => 0, 'ocupat' => false];
        if (!self::ia($db)) {
            $rez['ocupat'] = true;
            return $rez;
        }
        try {
            $azi = date('Y-m-d', $acum);
            $pastrare = Statistici::zileBrute($setari);
            $prag = date('Y-m-d', strtotime($azi . ' -' . $pastrare . ' days') ?: $acum);
            $ultimaIncheiata = (string) ($db->query('SELECT MAX(zi) FROM statistici_zile WHERE incheiata = 1')->fetchColumn() ?: '');
            $primulEveniment = (string) ($db->query('SELECT MIN(creat_la) FROM statistici_evenimente')->fetchColumn() ?: '');
            $start = $ultimaIncheiata !== '' ? date('Y-m-d', (int) strtotime($ultimaIncheiata . ' +1 day'))
                : ($primulEveniment !== '' ? substr($primulEveniment, 0, 10) : $azi);
            if ($primulEveniment !== '' && substr($primulEveniment, 0, 10) > $start) {
                $start = substr($primulEveniment, 0, 10);
            }
            if ($start < $prag) {
                $start = $prag;
            }
            $zile = [];
            for ($zi = $start; $zi <= $azi && count($zile) < $maxZile - 1; $zi = date('Y-m-d', (int) strtotime($zi . ' +1 day'))) {
                $zile[] = $zi;
            }
            // Azi întotdeauna (cu multe zile rămase în urmă, restul vin la rulările următoare).
            if (!in_array($azi, $zile, true)) {
                $zile[] = $azi;
            }
            foreach ($zile as $zi) {
                $facut = self::agregaZiua($db, $zi, $acum);
                $rez['zile'][] = $zi;
                $rez['evenimente'] += $facut['evenimente'];
                $rez['vizite'] += $facut['vizite'];
            }
            $rez['sterse'] = self::stergeVechi($db, $prag);
            Statistici::stergeSaruriVechi(Statistici::dosarSare(), date('Ymd', $acum));
        } finally {
            self::elibereaza($db);
        }
        return $rez;
    }

    /**
     * La deschiderea paginii din admin: dacă azi n-a mai fost agregată de `$secunde` (cronul lipsește sau n-a rulat încă), o
     * agregă acum, cu zilele rămase în urmă.
     *
     * @param array<string, mixed> $setari
     */
    public static function laNevoie(PDO $db, array $setari, int $secunde = 120, ?int $acum = null): bool
    {
        $acum ??= time();
        try {
            $stmt = $db->prepare('SELECT agregat_la FROM statistici_zile WHERE zi = ?');
            $stmt->execute([date('Y-m-d', $acum)]);
            $ultima = (string) ($stmt->fetchColumn() ?: '');
            if ($ultima !== '' && (int) strtotime($ultima) > $acum - $secunde) {
                return false;
            }
            self::ruleaza($db, $setari, $acum, 14);
            return true;
        } catch (Throwable $e) {
            error_log('ANDAXI statistici: agregarea nu a mers: ' . $e->getMessage());
            return false;
        }
    }

    private static function ia(PDO $db): bool
    {
        try {
            return (int) $db->query("SELECT GET_LOCK('" . self::LACAT . "', 0)")->fetchColumn() === 1;
        } catch (Throwable) {
            return true;
        }
    }

    private static function elibereaza(PDO $db): void
    {
        try {
            $db->query("SELECT RELEASE_LOCK('" . self::LACAT . "')");
        } catch (Throwable) {
        }
    }

    /** Șterge evenimentele brute de dinaintea zilei `$prag` (pe bucăți de 5000). */
    public static function stergeVechi(PDO $db, string $prag): int
    {
        $sterse = 0;
        $stmt = $db->prepare('DELETE FROM statistici_evenimente WHERE creat_la < ? ORDER BY id LIMIT 5000');
        for ($i = 0; $i < 200; $i++) {
            $stmt->execute([$prag . ' 00:00:00']);
            $n = $stmt->rowCount();
            $sterse += $n;
            if ($n < 5000) {
                break;
            }
        }
        return $sterse;
    }

    /**
     * Reface agregatele zilei `$zi` (Y-m-d) din evenimentele ei brute.
     *
     * @return array{evenimente: int, vizite: int}
     */
    public static function agregaZiua(PDO $db, string $zi, ?int $acum = null): array
    {
        $acum ??= time();
        $deLa = $zi . ' 00:00:00';
        $panaLa = date('Y-m-d', (int) strtotime($zi . ' +1 day')) . ' 00:00:00';
        $stmt = $db->prepare('SELECT id, creat_la, vizitator, tip, cale, tip_pagina, produs_id, sursa, detaliu, utm_sursa, utm_mediu,
                utm_campanie, utm_continut, utm_termen, dispozitiv, browser, os, tara, durata, cautare, rezultate, comanda_id
            FROM statistici_evenimente WHERE creat_la >= ? AND creat_la < ? ORDER BY vizitator, creat_la, id');
        $stmt->execute([$deLa, $panaLa]);

        $vizite = [];
        $pagini = [];
        $produse = [];
        $cautari = [];
        $comenziEv = [];
        $numarEvenimente = 0;
        $vizitatorCurent = null;
        $nrVizitator = 0;
        $vizitaCurenta = null;
        $ultimulMoment = 0;
        /** @var array<string, array<string, true>> $vizitatoriPagina */
        $vizitatoriPagina = [];
        $vizitatoriProdus = [];

        $inchide = static function () use (&$vizitaCurenta, &$vizite): void {
            if ($vizitaCurenta !== null) {
                $vizite[] = $vizitaCurenta;
                $vizitaCurenta = null;
            }
        };

        while (($ev = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $numarEvenimente++;
            $moment = (int) strtotime((string) $ev['creat_la']);
            $viz = (string) $ev['vizitator'];
            if ($viz !== $vizitatorCurent) {
                $inchide();
                $vizitatorCurent = $viz;
                $nrVizitator++;
                $ultimulMoment = 0;
            }
            $tip = (int) $ev['tip'];
            $eVizualizare = $tip === Statistici::TIP_VIZUALIZARE;
            if ($vizitaCurenta !== null && $moment - $ultimulMoment > self::PAUZA_VIZITA) {
                $inchide();
            }
            if ($vizitaCurenta === null && $eVizualizare) {
                $sursa = (string) $ev['sursa'];
                if ($sursa === 'intern' || $sursa === '' || !isset(Statistici::SURSE[$sursa])) {
                    $sursa = 'direct';
                }
                $vizitaCurenta = [
                    'zi' => $zi, 'inceput' => (string) $ev['creat_la'], 'sfarsit' => (string) $ev['creat_la'], 'vizitator_nr' => $nrVizitator,
                    'vizitator' => $viz,
                    'sursa' => $sursa, 'detaliu' => $sursa === 'direct' ? '' : (string) $ev['detaliu'],
                    'utm_sursa' => (string) $ev['utm_sursa'], 'utm_mediu' => (string) $ev['utm_mediu'], 'utm_campanie' => (string) $ev['utm_campanie'],
                    'utm_continut' => (string) $ev['utm_continut'], 'utm_termen' => (string) $ev['utm_termen'],
                    'pagina_intrare' => (string) $ev['cale'], 'pagini' => 0, 'durata_activa' => 0,
                    'dispozitiv' => (string) $ev['dispozitiv'], 'browser' => (string) $ev['browser'], 'os' => (string) $ev['os'],
                    'tara' => (string) $ev['tara'], 'adaugari_cos' => 0, 'checkout' => 0, 'comenzi' => 0, 'momente' => [],
                ];
            }
            $ultimulMoment = $moment;
            $sursaVizita = $vizitaCurenta['sursa'] ?? 'direct';
            if ($vizitaCurenta !== null) {
                $vizitaCurenta['sfarsit'] = (string) $ev['creat_la'];
            }
            switch ($tip) {
                case Statistici::TIP_VIZUALIZARE:
                    $vizitaCurenta['pagini']++;
                    $cheie = $sursaVizita . "\n" . (string) $ev['cale'];
                    $pagini[$cheie] ??= ['sursa' => $sursaVizita, 'cale' => (string) $ev['cale'], 'tip_pagina' => (string) $ev['tip_pagina'],
                        'vizualizari' => 0, 'vizitatori' => 0, 'intrari' => 0];
                    $pagini[$cheie]['vizualizari']++;
                    if (!isset($vizitatoriPagina[$cheie][$viz])) {
                        $vizitatoriPagina[$cheie][$viz] = true;
                        $pagini[$cheie]['vizitatori']++;
                    }
                    if ($vizitaCurenta['pagini'] === 1) {
                        $pagini[$cheie]['intrari']++;
                    }
                    $produs = (int) ($ev['produs_id'] ?? 0);
                    if ($produs > 0) {
                        $cheieP = $sursaVizita . "\n" . $produs;
                        $produse[$cheieP] ??= ['sursa' => $sursaVizita, 'produs_id' => $produs, 'vizualizari' => 0, 'vizitatori' => 0, 'adaugari_cos' => 0];
                        $produse[$cheieP]['vizualizari']++;
                        if (!isset($vizitatoriProdus[$cheieP][$viz])) {
                            $vizitatoriProdus[$cheieP][$viz] = true;
                            $produse[$cheieP]['vizitatori']++;
                        }
                    }
                    break;
                case Statistici::TIP_PLECARE:
                    if ($vizitaCurenta !== null) {
                        $vizitaCurenta['durata_activa'] += min(1800, (int) $ev['durata']);
                    }
                    break;
                case Statistici::TIP_COS:
                    if ($vizitaCurenta !== null) {
                        $vizitaCurenta['adaugari_cos']++;
                    }
                    $produs = (int) ($ev['produs_id'] ?? 0);
                    if ($produs > 0) {
                        $cheieP = $sursaVizita . "\n" . $produs;
                        $produse[$cheieP] ??= ['sursa' => $sursaVizita, 'produs_id' => $produs, 'vizualizari' => 0, 'vizitatori' => 0, 'adaugari_cos' => 0];
                        $produse[$cheieP]['adaugari_cos']++;
                    }
                    break;
                case Statistici::TIP_CHECKOUT:
                    if ($vizitaCurenta !== null) {
                        $vizitaCurenta['checkout']++;
                    }
                    break;
                case Statistici::TIP_COMANDA:
                    if ($vizitaCurenta !== null) {
                        $vizitaCurenta['comenzi']++;
                    }
                    break;
                case Statistici::TIP_CAUTARE:
                    $text = (string) $ev['cautare'];
                    if ($text !== '') {
                        $cheieC = $sursaVizita . "\n" . $text;
                        $cautari[$cheieC] ??= ['sursa' => $sursaVizita, 'cautare' => $text, 'cautari' => 0, 'fara_rezultat' => 0];
                        $cautari[$cheieC]['cautari']++;
                        if ($ev['rezultate'] !== null && (int) $ev['rezultate'] === 0) {
                            $cautari[$cheieC]['fara_rezultat']++;
                        }
                    }
                    break;
            }
        }
        $inchide();

        // Comenzile zilei cu hash: vizitele aceluiași vizitator din aceeași zi.
        $comenzi = [];
        try {
            $stmtC = $db->prepare('SELECT id, created_at, statistici_vizitator FROM orders WHERE created_at >= ? AND created_at < ?
                AND statistici_vizitator IS NOT NULL');
            $stmtC->execute([$deLa, $panaLa]);
            $comenzi = $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $comenzi = [];
        }
        $vizitePeVizitator = [];
        foreach ($vizite as $i => $v) {
            $vizitePeVizitator[$v['vizitator']][] = $i;
        }
        $atribuiri = [];
        foreach ($comenzi as $c) {
            $moment = (string) $c['created_at'];
            $aleasa = null;
            $inCare = null;
            foreach ($vizitePeVizitator[(string) $c['statistici_vizitator']] ?? [] as $i) {
                if ($vizite[$i]['inceput'] > $moment) {
                    break;
                }
                $inCare = $i;
                if ($vizite[$i]['sursa'] !== 'direct') {
                    $aleasa = $i;
                }
            }
            $aleasa ??= $inCare;
            $atribuiri[(int) $c['id']] = ['creat_la' => $moment, 'vizita' => $aleasa];
        }

        $db->beginTransaction();
        try {
            foreach (['statistici_vizite', 'statistici_pagini', 'statistici_produse', 'statistici_cautari', 'statistici_comenzi'] as $tabel) {
                $db->prepare('DELETE FROM ' . $tabel . ' WHERE zi = ?')->execute([$zi]);
            }
            $insVizita = $db->prepare('INSERT INTO statistici_vizite (zi, inceput, sfarsit, vizitator_nr, sursa, detaliu, utm_sursa, utm_mediu,
                    utm_campanie, utm_continut, utm_termen, pagina_intrare, pagini, durata, angajata, dispozitiv, browser, os, tara,
                    adaugari_cos, checkout, comenzi)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $iduri = [];
            foreach ($vizite as $i => $v) {
                $durata = $v['durata_activa'] > 0 ? $v['durata_activa']
                    : max(0, (int) strtotime($v['sfarsit']) - (int) strtotime($v['inceput']));
                $angajata = $v['pagini'] >= 2 || $v['durata_activa'] >= 10 || $v['adaugari_cos'] > 0 || $v['comenzi'] > 0 ? 1 : 0;
                $insVizita->execute([$zi, $v['inceput'], $v['sfarsit'], $v['vizitator_nr'], $v['sursa'], mb_substr($v['detaliu'], 0, 100),
                    $v['utm_sursa'], $v['utm_mediu'], $v['utm_campanie'], $v['utm_continut'], $v['utm_termen'], $v['pagina_intrare'],
                    min(65535, $v['pagini']), $durata, $angajata, $v['dispozitiv'], $v['browser'], $v['os'], $v['tara'],
                    min(65535, $v['adaugari_cos']), min(65535, $v['checkout']), min(65535, $v['comenzi'])]);
                $iduri[$i] = (int) $db->lastInsertId();
            }
            $insPagina = $db->prepare('INSERT INTO statistici_pagini (zi, sursa, cale, tip_pagina, vizualizari, vizitatori, intrari)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($pagini as $p) {
                $insPagina->execute([$zi, $p['sursa'], $p['cale'], $p['tip_pagina'], $p['vizualizari'], $p['vizitatori'], $p['intrari']]);
            }
            $insProdus = $db->prepare('INSERT INTO statistici_produse (zi, sursa, produs_id, vizualizari, vizitatori, adaugari_cos)
                VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($produse as $p) {
                $insProdus->execute([$zi, $p['sursa'], $p['produs_id'], $p['vizualizari'], $p['vizitatori'], $p['adaugari_cos']]);
            }
            $insCautare = $db->prepare('INSERT INTO statistici_cautari (zi, sursa, cautare, cautari, fara_rezultat) VALUES (?, ?, ?, ?, ?)');
            foreach ($cautari as $c) {
                $insCautare->execute([$zi, $c['sursa'], $c['cautare'], $c['cautari'], $c['fara_rezultat']]);
            }
            $insComanda = $db->prepare('INSERT INTO statistici_comenzi (comanda_id, zi, creat_la, sursa, detaliu, utm_campanie, dispozitiv, vizita_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($atribuiri as $id => $a) {
                $v = $a['vizita'] !== null ? $vizite[$a['vizita']] : null;
                $insComanda->execute([$id, $zi, $a['creat_la'], $v['sursa'] ?? 'necunoscuta', $v['detaliu'] ?? '', $v['utm_campanie'] ?? '',
                    $v['dispozitiv'] ?? '', $a['vizita'] !== null ? $iduri[$a['vizita']] : null]);
            }
            $incheiata = $acum >= (int) strtotime($panaLa) + self::INCHEIERE_DUPA ? 1 : 0;
            $db->prepare('INSERT INTO statistici_zile (zi, agregat_la, incheiata) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE agregat_la = VALUES(agregat_la), incheiata = VALUES(incheiata)')
                ->execute([$zi, date('Y-m-d H:i:s', $acum), $incheiata]);
            if ($incheiata === 1) {
                // Atribuirea e acum în statistici_comenzi: hash-ul zilei nu mai rămâne pe comenzi.
                $db->prepare('UPDATE orders SET statistici_vizitator = NULL WHERE created_at >= ? AND created_at < ? AND statistici_vizitator IS NOT NULL')
                    ->execute([$deLa, $panaLa]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return ['evenimente' => $numarEvenimente, 'vizite' => count($vizite)];
    }
}
