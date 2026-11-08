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
 * nopții, deci un vizitator nu trece dintr-o zi în alta: zilele sunt independente. Evenimentele zilei se citesc pe 16 bucăți,
 * după primul octet al hash-ului (fiecare vizitator e întreg într-o bucată), deci memoria nu crește cu traficul zilei. O zi e
 * „încheiată” când s-a agregat după 00:15 a zilei următoare (ultimele plecări au sosit); atunci nu se mai reface, hash-ul de pe
 * comenzile ei se golește și evenimentele „comandă” ale zilei se șterg (nimic nu mai leagă o comandă de evenimentele brute).
 *
 * **Gunoiul** nu ajunge în agregatele păstrate: paginile văzute de un singur vizitator (sau peste primele MAX_PAGINI ale zilei)
 * devin „(altele)” (și ca pagină de intrare), produsele care nu există în catalog se sar.
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
    /** Câte căi distincte păstrează o zi în statistici_pagini (cele mai văzute, cu cel puțin 2 vizitatori); restul „(altele)”. */
    public const MAX_PAGINI = 500;
    public const ALTELE = '(altele)';
    /** Câți termeni de căutare păstrează o zi (ai cel puțin 2 vizitatori); restul „(altele)”. */
    public const MAX_CAUTARI = 200;

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
     * La deschiderea paginii din admin: doar dacă agregarea a rămas în urmă de peste `$secunde` (cronul lipsește sau n-a rulat de
     * două ore), cel mult `$maxZile` zile (cea mai veche neîncheiată și azi), cu o limită de timp. Nu aruncă niciodată: întoarce
     * „proaspat” (nimic de făcut), „facut”, „ocupat” (rulează altă agregare: se arată ultimele date) sau „eroare”.
     *
     * @param array<string, mixed> $setari
     */
    public static function laNevoie(PDO $db, array $setari, int $secunde = 7200, ?int $acum = null, int $maxZile = 2): string
    {
        $acum ??= time();
        try {
            $ultima = (string) ($db->query('SELECT MAX(agregat_la) FROM statistici_zile')->fetchColumn() ?: '');
            $stmt = $db->prepare('SELECT COUNT(*) FROM statistici_zile WHERE zi = ?');
            $stmt->execute([date('Y-m-d', $acum)]);
            $aziAgregata = (int) $stmt->fetchColumn() > 0;
            if ($aziAgregata && $ultima !== '' && (int) strtotime($ultima) > $acum - $secunde) {
                return 'proaspat';
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(60);
            }
            return self::ruleaza($db, $setari, $acum, $maxZile)['ocupat'] ? 'ocupat' : 'facut';
        } catch (Throwable $e) {
            error_log('ANDAXI statistici: agregarea nu a mers: ' . $e->getMessage());
            return 'eroare';
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
     * Reface agregatele zilei `$zi` (Y-m-d) din evenimentele ei brute, pe bucăți după hash.
     *
     * @return array{evenimente: int, vizite: int}
     */
    public static function agregaZiua(PDO $db, string $zi, ?int $acum = null): array
    {
        $acum ??= time();
        $deLa = $zi . ' 00:00:00';
        $panaLa = date('Y-m-d', (int) strtotime($zi . ' +1 day')) . ' 00:00:00';

        // Căile păstrate: văzute de cel puțin 2 vizitatori, cele mai văzute MAX_PAGINI; restul „(altele)”.
        $stmtCai = $db->prepare('SELECT cale FROM statistici_evenimente WHERE creat_la >= ? AND creat_la < ? AND tip = ?
            GROUP BY cale HAVING COUNT(DISTINCT vizitator) >= 2 ORDER BY COUNT(*) DESC LIMIT ' . self::MAX_PAGINI);
        $stmtCai->execute([$deLa, $panaLa, Statistici::TIP_VIZUALIZARE]);
        $caiPastrate = array_fill_keys(array_map('strval', $stmtCai->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        $cale = static fn (string $c): string => isset($caiPastrate[$c]) ? $c : self::ALTELE;
        // Produsele care există (un id inventat de cineva nu ajunge în agregate).
        $stmtProd = $db->prepare('SELECT DISTINCT produs_id FROM statistici_evenimente WHERE creat_la >= ? AND creat_la < ? AND produs_id IS NOT NULL');
        $stmtProd->execute([$deLa, $panaLa]);
        $iduri = array_map('intval', $stmtProd->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $produseBune = [];
        foreach (array_chunk($iduri, 500) as $bucata) {
            try {
                $st = $db->prepare('SELECT id FROM products WHERE id IN (' . implode(',', array_fill(0, count($bucata), '?')) . ')');
                $st->execute($bucata);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                    $produseBune[(int) $id] = true;
                }
            } catch (Throwable) {
            }
        }
        // Comenzile zilei cu hash, pe vizitator.
        $comenziPeVizitator = [];
        try {
            $stmtC = $db->prepare('SELECT id, created_at, statistici_vizitator FROM orders WHERE created_at >= ? AND created_at < ?
                AND statistici_vizitator IS NOT NULL ORDER BY created_at, id');
            $stmtC->execute([$deLa, $panaLa]);
            foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $comenziPeVizitator[(string) $c['statistici_vizitator']][] = ['id' => (int) $c['id'], 'creat_la' => (string) $c['created_at']];
            }
        } catch (Throwable) {
            $comenziPeVizitator = [];
        }

        $pagini = [];
        $produse = [];
        $cautari = [];
        $numarEvenimente = 0;
        $numarVizite = 0;
        $nrVizitator = 0;
        // Numărul vizitatorului în vizite: aleator (32 de biți, unic în zi), altul la fiecare agregare, deci nu spune ordinea sosirii
        // și nu leagă vizitele aceluiași om de la o agregare la alta; numărătoarea COUNT(DISTINCT zi, vizitator_nr) rămâne aceeași.
        $numereFolosite = [];
        $atribuite = [];
        $vizitatoriTermen = [];

        $db->beginTransaction();
        try {
            foreach (['statistici_vizite', 'statistici_pagini', 'statistici_produse', 'statistici_cautari', 'statistici_comenzi'] as $tabel) {
                $db->prepare('DELETE FROM ' . $tabel . ' WHERE zi = ?')->execute([$zi]);
            }
            $insVizita = $db->prepare('INSERT INTO statistici_vizite (zi, inceput, sfarsit, vizitator_nr, sursa, detaliu, utm_sursa, utm_mediu,
                    utm_campanie, utm_continut, utm_termen, pagina_intrare, pagini, durata, angajata, dispozitiv, browser, os, tara,
                    adaugari_cos, checkout, comenzi)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insComanda = $db->prepare('INSERT INTO statistici_comenzi (comanda_id, zi, creat_la, sursa, detaliu, utm_campanie, dispozitiv)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt = $db->prepare('SELECT creat_la, vizitator, tip, cale, tip_pagina, produs_id, sursa, detaliu, utm_sursa, utm_mediu,
                    utm_campanie, utm_continut, utm_termen, dispozitiv, browser, os, tara, durata, cautare, rezultate
                FROM statistici_evenimente WHERE creat_la >= ? AND creat_la < ? AND vizitator >= ? AND vizitator < ?
                ORDER BY vizitator, creat_la, id');
            for ($bucata = 0; $bucata < 16; $bucata++) {
                $stmt->bindValue(1, $deLa);
                $stmt->bindValue(2, $panaLa);
                $stmt->bindValue(3, chr($bucata * 16), PDO::PARAM_LOB);
                // Ultima bucată: până după cel mai mare hash posibil (16 octeți 0xFF).
                $stmt->bindValue(4, $bucata === 15 ? str_repeat("\xFF", 17) : chr($bucata * 16 + 16), PDO::PARAM_LOB);
                $stmt->execute();
                $vizitatoriPagina = [];
                $vizitatoriProdus = [];
                $vizitatoriCautare = [];
                $vizitatorCurent = null;
                $vizita = null;
                $viziteleLui = [];
                $ultimulMoment = 0;
                $inchide = static function () use (&$vizita, &$viziteleLui, $insVizita, $zi, &$numarVizite): void {
                    if ($vizita === null) {
                        return;
                    }
                    $durata = $vizita['durata_activa'] > 0 ? $vizita['durata_activa']
                        : max(0, (int) strtotime($vizita['sfarsit']) - (int) strtotime($vizita['inceput']));
                    $angajata = $vizita['pagini'] >= 2 || $vizita['durata_activa'] >= 10 || $vizita['adaugari_cos'] > 0 || $vizita['comenzi'] > 0 ? 1 : 0;
                    $insVizita->execute([$zi, $vizita['inceput'], $vizita['sfarsit'], $vizita['vizitator_nr'], $vizita['sursa'],
                        mb_substr($vizita['detaliu'], 0, 100), $vizita['utm_sursa'], $vizita['utm_mediu'], $vizita['utm_campanie'],
                        $vizita['utm_continut'], $vizita['utm_termen'], $vizita['pagina_intrare'], min(65535, $vizita['pagini']), $durata,
                        $angajata, $vizita['dispozitiv'], $vizita['browser'], $vizita['os'], $vizita['tara'], min(65535, $vizita['adaugari_cos']),
                        min(65535, $vizita['checkout']), min(65535, $vizita['comenzi'])]);
                    $viziteleLui[] = ['inceput' => $vizita['inceput'], 'sursa' => $vizita['sursa'],
                        'detaliu' => $vizita['detaliu'], 'utm_campanie' => $vizita['utm_campanie'], 'dispozitiv' => $vizita['dispozitiv']];
                    $numarVizite++;
                    $vizita = null;
                };
                // La schimbarea vizitatorului: comenzile lui, atribuite ultimei vizite venite de undeva (nu „direct”) de dinaintea
                // comenzii; fără ea, vizitei în care a comandat.
                $atribuie = static function (?string $viz) use (&$viziteleLui, $comenziPeVizitator, $insComanda, $zi, &$atribuite): void {
                    if ($viz !== null) {
                        foreach ($comenziPeVizitator[$viz] ?? [] as $c) {
                            $aleasa = null;
                            $inCare = null;
                            foreach ($viziteleLui as $v) {
                                if ($v['inceput'] > $c['creat_la']) {
                                    break;
                                }
                                $inCare = $v;
                                if ($v['sursa'] !== 'direct') {
                                    $aleasa = $v;
                                }
                            }
                            $aleasa ??= $inCare;
                            $insComanda->execute([$c['id'], $zi, $c['creat_la'], $aleasa['sursa'] ?? 'necunoscuta', $aleasa['detaliu'] ?? '',
                                $aleasa['utm_campanie'] ?? '', $aleasa['dispozitiv'] ?? '']);
                            $atribuite[$c['id']] = true;
                        }
                    }
                    $viziteleLui = [];
                };
                while (($ev = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $numarEvenimente++;
                    $moment = (int) strtotime((string) $ev['creat_la']);
                    $viz = (string) $ev['vizitator'];
                    if ($viz !== $vizitatorCurent) {
                        $inchide();
                        $atribuie($vizitatorCurent);
                        $vizitatorCurent = $viz;
                        do {
                            $nrVizitator = random_int(1, 4294967295);
                        } while (isset($numereFolosite[$nrVizitator]));
                        $numereFolosite[$nrVizitator] = true;
                        $ultimulMoment = 0;
                    }
                    $tip = (int) $ev['tip'];
                    if ($vizita !== null && $moment - $ultimulMoment > self::PAUZA_VIZITA) {
                        $inchide();
                    }
                    if ($vizita === null && $tip === Statistici::TIP_VIZUALIZARE) {
                        $sursa = (string) $ev['sursa'];
                        if ($sursa === 'intern' || $sursa === '' || !isset(Statistici::SURSE[$sursa])) {
                            $sursa = 'direct';
                        }
                        $vizita = [
                            'inceput' => (string) $ev['creat_la'], 'sfarsit' => (string) $ev['creat_la'], 'vizitator_nr' => $nrVizitator,
                            'sursa' => $sursa, 'detaliu' => $sursa === 'direct' ? '' : (string) $ev['detaliu'],
                            'utm_sursa' => (string) $ev['utm_sursa'], 'utm_mediu' => (string) $ev['utm_mediu'],
                            'utm_campanie' => (string) $ev['utm_campanie'], 'utm_continut' => (string) $ev['utm_continut'],
                            'utm_termen' => (string) $ev['utm_termen'], 'pagina_intrare' => $cale((string) $ev['cale']), 'pagini' => 0,
                            'durata_activa' => 0, 'dispozitiv' => (string) $ev['dispozitiv'], 'browser' => (string) $ev['browser'],
                            'os' => (string) $ev['os'], 'tara' => (string) $ev['tara'], 'adaugari_cos' => 0, 'checkout' => 0, 'comenzi' => 0,
                        ];
                    }
                    $ultimulMoment = $moment;
                    $sursaVizita = $vizita['sursa'] ?? 'direct';
                    if ($vizita !== null) {
                        $vizita['sfarsit'] = (string) $ev['creat_la'];
                    }
                    switch ($tip) {
                        case Statistici::TIP_VIZUALIZARE:
                            $vizita['pagini']++;
                            $c = $cale((string) $ev['cale']);
                            $cheie = $sursaVizita . "\n" . $c;
                            $pagini[$cheie] ??= ['sursa' => $sursaVizita, 'cale' => $c, 'tip_pagina' => $c === self::ALTELE ? '' : (string) $ev['tip_pagina'],
                                'vizualizari' => 0, 'vizitatori' => 0, 'intrari' => 0];
                            $pagini[$cheie]['vizualizari']++;
                            if (!isset($vizitatoriPagina[$cheie][$viz])) {
                                $vizitatoriPagina[$cheie][$viz] = true;
                                $pagini[$cheie]['vizitatori']++;
                            }
                            if ($vizita['pagini'] === 1) {
                                $pagini[$cheie]['intrari']++;
                            }
                            $produs = (int) ($ev['produs_id'] ?? 0);
                            if ($produs > 0 && isset($produseBune[$produs])) {
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
                            if ($vizita !== null) {
                                $vizita['durata_activa'] += min(1800, (int) $ev['durata']);
                            }
                            break;
                        case Statistici::TIP_COS:
                            if ($vizita !== null) {
                                $vizita['adaugari_cos']++;
                            }
                            $produs = (int) ($ev['produs_id'] ?? 0);
                            if ($produs > 0 && isset($produseBune[$produs])) {
                                $cheieP = $sursaVizita . "\n" . $produs;
                                $produse[$cheieP] ??= ['sursa' => $sursaVizita, 'produs_id' => $produs, 'vizualizari' => 0, 'vizitatori' => 0, 'adaugari_cos' => 0];
                                $produse[$cheieP]['adaugari_cos']++;
                            }
                            break;
                        case Statistici::TIP_CHECKOUT:
                            if ($vizita !== null) {
                                $vizita['checkout']++;
                            }
                            break;
                        case Statistici::TIP_COMANDA:
                            if ($vizita !== null) {
                                $vizita['comenzi']++;
                            }
                            break;
                        case Statistici::TIP_CAUTARE:
                            $text = (string) $ev['cautare'];
                            if ($text !== '') {
                                $cheieC = $sursaVizita . "\n" . $text;
                                $cautari[$cheieC] ??= ['sursa' => $sursaVizita, 'cautare' => $text, 'cautari' => 0, 'vizitatori' => 0, 'fara_rezultat' => 0];
                                $cautari[$cheieC]['cautari']++;
                                if (!isset($vizitatoriCautare[$cheieC][$viz])) {
                                    $vizitatoriCautare[$cheieC][$viz] = true;
                                    $cautari[$cheieC]['vizitatori']++;
                                }
                                if (!isset($vizitatoriCautare["\0" . $text][$viz])) {
                                    $vizitatoriCautare["\0" . $text][$viz] = true;
                                    $vizitatoriTermen[$text] = ($vizitatoriTermen[$text] ?? 0) + 1;
                                }
                                if ($ev['rezultate'] !== null && (int) $ev['rezultate'] === 0) {
                                    $cautari[$cheieC]['fara_rezultat']++;
                                }
                            }
                            break;
                    }
                }
                $stmt->closeCursor();
                $inchide();
                $atribuie($vizitatorCurent);
            }
            // Comenzile cu hash fără niciun eveniment al vizitatorului (scriptul blocat): „necunoscuta”.
            foreach ($comenziPeVizitator as $lista) {
                foreach ($lista as $c) {
                    if (!isset($atribuite[$c['id']])) {
                        $insComanda->execute([$c['id'], $zi, $c['creat_la'], 'necunoscuta', '', '', '']);
                    }
                }
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
            // Căutările păstrate: ale cel puțin 2 vizitatori diferiți ai zilei, cele mai multe MAX_CAUTARI; restul „(altele)”.
            $termeniBuni = array_filter($vizitatoriTermen, static fn (int $n): bool => $n >= 2);
            arsort($termeniBuni);
            $termeniBuni = array_slice($termeniBuni, 0, self::MAX_CAUTARI, true);
            $cautariPastrate = [];
            foreach ($cautari as $c) {
                $termen = isset($termeniBuni[$c['cautare']]) ? $c['cautare'] : self::ALTELE;
                $cheieC = $c['sursa'] . "\n" . $termen;
                $cautariPastrate[$cheieC] ??= ['sursa' => $c['sursa'], 'cautare' => $termen, 'cautari' => 0, 'vizitatori' => 0, 'fara_rezultat' => 0];
                $cautariPastrate[$cheieC]['cautari'] += $c['cautari'];
                $cautariPastrate[$cheieC]['vizitatori'] += $c['vizitatori'];
                $cautariPastrate[$cheieC]['fara_rezultat'] += $c['fara_rezultat'];
            }
            $insCautare = $db->prepare('INSERT INTO statistici_cautari (zi, sursa, cautare, cautari, vizitatori, fara_rezultat) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($cautariPastrate as $c) {
                $insCautare->execute([$zi, $c['sursa'], $c['cautare'], $c['cautari'], $c['vizitatori'], $c['fara_rezultat']]);
            }
            $incheiata = $acum >= (int) strtotime($panaLa) + self::INCHEIERE_DUPA ? 1 : 0;
            $db->prepare('INSERT INTO statistici_zile (zi, agregat_la, incheiata) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE agregat_la = VALUES(agregat_la), incheiata = VALUES(incheiata)')
                ->execute([$zi, date('Y-m-d H:i:s', $acum), $incheiata]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        if ($incheiata === 1) {
            self::inchideZiua($db, $deLa, $panaLa);
        }
        return ['evenimente' => $numarEvenimente, 'vizite' => $numarVizite];
    }

    /**
     * Ziua încheiată: atribuirea e în statistici_comenzi, deci hash-ul zilei se golește de pe comenzile ei (după cheia primară, în
     * tranzacții scurte, ca să nu țină tabelul comenzilor blocat) și evenimentele „comandă” ale zilei se șterg (momentul lor ar
     * lega comanda de evenimentele vizitatorului).
     */
    private static function inchideZiua(PDO $db, string $deLa, string $panaLa): void
    {
        try {
            $stmt = $db->prepare('SELECT id FROM orders WHERE created_at >= ? AND created_at < ? AND statistici_vizitator IS NOT NULL');
            $stmt->execute([$deLa, $panaLa]);
            $iduri = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            foreach (array_chunk($iduri, 200) as $bucata) {
                $db->beginTransaction();
                $db->prepare('UPDATE orders SET statistici_vizitator = NULL WHERE id IN (' . implode(',', array_fill(0, count($bucata), '?')) . ')')
                    ->execute($bucata);
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('ANDAXI statistici: hash-ul comenzilor nu s-a golit: ' . $e->getMessage());
        }
        try {
            $db->prepare('DELETE FROM statistici_evenimente WHERE creat_la >= ? AND creat_la < ? AND tip = ?')
                ->execute([$deLa, $panaLa, Statistici::TIP_COMANDA]);
            // Cheia limitei pe adresă nu mai trebuie (limita privește doar ultimul minut): golită.
            $db->prepare("UPDATE statistici_evenimente SET ip_cheie = 0x0000000000000000 WHERE creat_la >= ? AND creat_la < ?
                AND ip_cheie <> 0x0000000000000000")->execute([$deLa, $panaLa]);
        } catch (Throwable) {
        }
    }
}
