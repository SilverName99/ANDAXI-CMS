<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\AdminCsrf;
use App\Support\Flash;
use App\Support\Settings;
use App\Support\Statistici;
use App\Support\StatisticiAgregare;
use App\Support\StatisticiRaport;
use App\Support\View;
use PDO;
use Throwable;

/**
 * Rapoarte → Statistici (pluginul `statistici`; App\Support\Statistici, StatisticiAgregare, StatisticiRaport): tabloul cu
 * perioada, comparația, „Acum pe site”, cifrele, graficul, tabelele cu filtrul la clic și exportul CSV; „Impactul unei campanii
 * sau postări”; setările. Doar GET în afară de setări (un POST din admin golește cache-ul paginilor). Administratorul general,
 * „Administrator magazin” și „Administrator emailuri” (Auth::canAccessPath).
 */
final class StatisticiController
{
    use Comun;

    /** Tabelele tabloului: cheie => titlu (aceleași chei la export). */
    public const TABELE = [
        'surse' => 'Surse',
        'campanii' => 'Campanii UTM',
        'pagini' => 'Pagini cele mai vizitate',
        'intrari' => 'Pagini de intrare',
        'produse' => 'Produse cele mai văzute',
        'categorii' => 'Categorii',
        'cautari' => 'Căutări pe site',
        'fara_rezultat' => 'Căutări fără rezultat',
        'dispozitive' => 'Dispozitive',
        'browsere' => 'Browsere',
        'sisteme' => 'Sisteme de operare',
        'tari' => 'Țări',
        'comenzi_cookie' => 'Sursa comenzilor (cookie, cu acord)',
    ];

    /** POST /api/statistici, când nu l-a luat public/index.php înainte (de exemplu în teste): același răspuns. */
    public function eveniment(): void
    {
        $db = $this->db();
        $corp = (string) @file_get_contents('php://input', false, null, 0, Statistici::MAX_CORP + 1);
        try {
            $cod = $db instanceof PDO ? Statistici::primeste($db, $_SERVER, $corp, Settings::all($db)) : 503;
        } catch (Throwable) {
            $cod = 500;
        }
        http_response_code($cod);
        header('Cache-Control: no-store');
    }

    public function tablou(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            View::render('admin/statistici', ['title' => 'Statistici', 'eroare' => 'Baza de date nu răspunde.'], 'admin/layout');
            return;
        }
        $setari = Settings::all($db);
        try {
            // Agregarea la deschidere (doar cu cronul rămas în urmă de peste 2 ore, cel mult 2 zile): niciodată o eroare 500; dacă
            // rulează altă agregare sau nu a mers, pagina arată ultimele date agregate, cu nota „se calculează”.
            $stareAgregare = StatisticiAgregare::laNevoie($db, $setari);
            $perioada = StatisticiRaport::perioada($_GET);
            $filtre = StatisticiRaport::filtre($_GET);
            $compara = ($_GET['compara'] ?? '') === '1';
            $kpi = StatisticiRaport::kpi($db, $perioada['de_la'], $perioada['pana_la'], $filtre);
            $kpiInainte = $compara ? StatisticiRaport::kpi($db, $perioada['inainte_de_la'], $perioada['inainte_pana_la'], $filtre) : null;
            $serie = StatisticiRaport::serie($db, $perioada['de_la'], $perioada['pana_la'], $filtre, $perioada['pe_ora']);
            $serieInainte = $compara ? StatisticiRaport::serie($db, $perioada['inainte_de_la'], $perioada['inainte_pana_la'], $filtre, $perioada['pe_ora']) : null;
            $tabele = [];
            foreach (array_keys(self::TABELE) as $cheie) {
                $tabele[$cheie] = self::tabel($db, $cheie, $perioada, $filtre);
            }
            View::render('admin/statistici', [
                'title' => 'Statistici',
                'perioada' => $perioada,
                'filtre' => $filtre,
                'compara' => $compara,
                'kpi' => $kpi,
                'kpiInainte' => $kpiInainte,
                'comenziToate' => $filtre === [] ? StatisticiRaport::comenziToate($db, $perioada['de_la'], $perioada['pana_la']) : null,
                'serie' => $serie,
                'serieInainte' => $serieInainte,
                'tabele' => $tabele,
                'acum' => StatisticiRaport::acum($db),
                'ultimaAgregare' => (string) ($db->query('SELECT MAX(agregat_la) FROM statistici_zile')->fetchColumn() ?: ''),
                'respectaDnt' => Statistici::respectaDnt($setari),
                'seCalculeaza' => in_array($stareAgregare, ['ocupat', 'eroare'], true),
            ], 'admin/layout');
        } catch (Throwable $e) {
            error_log('ANDAXI statistici: tabloul nu s-a putut face: ' . $e->getMessage());
            View::render('admin/statistici', ['title' => 'Statistici',
                'eroare' => 'Statisticile nu se pot citi acum. Rulează migrările (scripts/migreaza.php --aplica) dacă pluginul e nou pe site.'], 'admin/layout');
        }
    }

    /** GET /admin/statistici/acum: „Acum pe site”, în JSON (reîmprospătarea din pagină). */
    public function acum(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        header('Cache-Control: no-store');
        if (!$db instanceof PDO) {
            $this->jsonResponse(['ok' => false], 503);
            return;
        }
        try {
            $acum = StatisticiRaport::acum($db);
            foreach ($acum['surse'] as &$s) {
                $s['nume'] = StatisticiRaport::numeSursa($s['sursa']);
            }
            unset($s);
            $this->jsonResponse(['ok' => true] + $acum);
        } catch (Throwable) {
            $this->jsonResponse(['ok' => false], 500);
        }
    }

    /** GET /admin/statistici/export?tabel=…: tabelul cerut, cu perioada și filtrele paginii, în CSV (Excel: „;” și BOM). */
    public function export(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $cheie = (string) ($_GET['tabel'] ?? '');
        if (!$db instanceof PDO || (!isset(self::TABELE[$cheie]) && $cheie !== 'serie' && $cheie !== 'impact')) {
            http_response_code(404);
            echo 'Tabel necunoscut.';
            return;
        }
        $perioada = StatisticiRaport::perioada($_GET);
        $filtre = StatisticiRaport::filtre($_GET);
        if ($cheie === 'serie') {
            $antet = [$perioada['pe_ora'] ? 'Ora' : 'Ziua', 'Vizite', 'Pagini vizualizate', 'Comenzi', 'Venituri (lei)'];
            $randuri = array_map(static fn (array $p): array => [$p['cheie'], $p['vizite'], $p['pagini'], $p['comenzi'], self::suma($p['venit'])],
                StatisticiRaport::serie($db, $perioada['de_la'], $perioada['pana_la'], $filtre, $perioada['pe_ora']));
        } elseif ($cheie === 'impact') {
            $impact = self::parametriImpact($_GET);
            $antet = ['Ora', 'Vizite', 'Comenzi', 'Venituri (lei)', 'Vizite (săptămâna dinainte)', 'Comenzi (săptămâna dinainte)', 'Venituri (săptămâna dinainte)'];
            $randuri = array_map(static fn (array $r): array => [$r['ora'], $r['vizite'], $r['comenzi'], self::suma($r['venit']), $r['vizite_inainte'],
                $r['comenzi_inainte'], self::suma($r['venit_inainte'])],
                StatisticiRaport::impact($db, $impact['sursa'], $impact['campanie'], $impact['start'], $impact['ore']));
        } else {
            $tabel = self::tabel($db, $cheie, $perioada, $filtre);
            $antet = array_map(static fn (array $c): string => $c['eticheta'], $tabel['coloane']);
            $randuri = [];
            foreach ($tabel['randuri'] as $r) {
                $randuri[] = self::randCsv($tabel['coloane'], $r['valori']);
                foreach ($r['copii'] ?? [] as $copil) {
                    $randuri[] = self::randCsv($tabel['coloane'], $copil['valori'], '  ');
                }
            }
        }
        $nume = 'statistici-' . $cheie . '-' . $perioada['de_la'] . '-' . $perioada['pana_la'] . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nume . '"');
        header('Cache-Control: no-store');
        $iesire = fopen('php://output', 'wb');
        if ($iesire === false) {
            return;
        }
        fwrite($iesire, "\xEF\xBB\xBF");
        fputcsv($iesire, $antet, ';', '"', '');
        foreach ($randuri as $r) {
            // Celulele care încep cu = + - @ nu devin formule în Excel.
            fputcsv($iesire, array_map([self::class, 'celulaCsv'], $r), ';', '"', '');
        }
        fclose($iesire);
    }

    /** GET /admin/statistici/impact: impactul unei postări sau campanii, pe ore. */
    public function impact(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!$db instanceof PDO) {
            View::render('admin/statistici-impact', ['title' => 'Impactul unei campanii', 'eroare' => 'Baza de date nu răspunde.'], 'admin/layout');
            return;
        }
        try {
            StatisticiAgregare::laNevoie($db, Settings::all($db));
            $p = self::parametriImpact($_GET);
            View::render('admin/statistici-impact', [
                'title' => 'Impactul unei campanii sau postări',
                'parametri' => $p,
                'serie' => isset($_GET['start']) ? StatisticiRaport::impact($db, $p['sursa'], $p['campanie'], $p['start'], $p['ore']) : null,
                'campanii' => StatisticiRaport::campaniiRecente($db),
            ], 'admin/layout');
        } catch (Throwable $e) {
            error_log('ANDAXI statistici: impactul nu s-a putut face: ' . $e->getMessage());
            View::render('admin/statistici-impact', ['title' => 'Impactul unei campanii', 'eroare' => 'Statisticile nu se pot citi acum.'], 'admin/layout');
        }
    }

    /** GET /admin/statistici/setari și POST (jetonul formularului, un rând în Jurnal activitate). */
    public function setari(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            if (!AdminCsrf::valid()) {
                Flash::set('error', 'Sesiunea formularului a expirat. Încearcă din nou.');
                header('Location: /admin/statistici/setari');
                return;
            }
            $zile = (int) ($_POST['statistici_zile_brute'] ?? Statistici::ZILE_BRUTE_IMPLICIT);
            $maxMinut = (int) ($_POST['statistici_max_minut'] ?? Statistici::LIMITA_SITE_MINUT);
            $maxZi = (int) ($_POST['statistici_max_zi'] ?? Statistici::MAX_ZI_IMPLICIT);
            Settings::save($db, [
                'statistici_max_zi' => (string) max(1000, min(5000000, $maxZi)),
                'statistici_max_minut' => (string) max(60, min(100000, $maxMinut)),
                'statistici_respecta_dnt' => ($_POST['statistici_respecta_dnt'] ?? '') === '1' ? '1' : '0',
                'statistici_zile_brute' => (string) max(7, min(400, $zile)),
            ]);
            try {
                if ($db instanceof PDO) {
                    AdminActivityLog::log($db, 'statistici_setari_salvate', ['respecta_dnt' => ($_POST['statistici_respecta_dnt'] ?? '') === '1' ? 1 : 0]);
                }
            } catch (Throwable) {
            }
            Flash::set('success', 'Setările statisticilor au fost salvate.');
            header('Location: /admin/statistici/setari');
            return;
        }
        $setari = Settings::all($db);
        View::render('admin/statistici-setari', [
            'title' => 'Setări statistici',
            'respectaDnt' => Statistici::respectaDnt($setari),
            'zileBrute' => Statistici::zileBrute($setari),
            'maxZi' => Statistici::maxZi($setari),
            'maxMinut' => Statistici::maxMinut($setari),
        ], 'admin/layout');
    }

    /**
     * @param array<string, mixed> $get
     * @return array{sursa: string, campanie: string, start: int, ore: int, start_text: string}
     */
    public static function parametriImpact(array $get): array
    {
        $sursa = is_string($get['sursa'] ?? null) && isset(Statistici::SURSE[$get['sursa']]) ? (string) $get['sursa'] : 'tiktok';
        $campanie = is_string($get['campanie'] ?? null) ? mb_substr(trim((string) $get['campanie']), 0, 100) : '';
        $startText = is_string($get['start'] ?? null) ? (string) $get['start'] : '';
        $start = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $startText) === 1 ? (int) strtotime(str_replace('T', ' ', $startText)) : 0;
        if ($start <= 0) {
            $start = (int) strtotime(date('Y-m-d H:00', time() - 24 * 3600));
        }
        $ore = (int) ($get['ore'] ?? 48);
        return ['sursa' => $sursa, 'campanie' => $campanie, 'start' => $start, 'ore' => max(6, min(168, $ore)),
            'start_text' => date('Y-m-d\TH:i', $start)];
    }

    /**
     * O celulă CSV fără formulă: textul care, fără spațiile de la început, începe cu = + - @ (sau tab, CR) primește un apostrof în
     * față, ca Excel să nu-l ia drept formulă (și pe rândurile de detaliu, care încep cu spații).
     */
    public static function celulaCsv(mixed $v): string
    {
        $text = (string) $v;
        if (is_string($v) && preg_match('/^[=+\-@\t\r]/', ltrim($text, " \n\x0B\0")) === 1) {
            return "'" . $text;
        }
        return $text;
    }

    private static function suma(float $v): string
    {
        return number_format($v, 2, ',', '');
    }

    /**
     * @param list<array{eticheta: string, cheie: string, tip: string}> $coloane
     * @param array<string, mixed> $valori
     * @return list<string|int>
     */
    private static function randCsv(array $coloane, array $valori, string $prefix = ''): array
    {
        $rand = [];
        foreach ($coloane as $i => $c) {
            $v = $valori[$c['cheie']] ?? '';
            $rand[] = match ($c['tip']) {
                'lei' => $v === null ? '' : self::suma((float) $v),
                'procent' => number_format((float) $v * 100, 1, ',', '') . '%',
                'zecimal' => number_format((float) $v, 1, ',', ''),
                'durata' => (string) (int) round((float) $v),
                'numar' => (int) $v,
                default => ($i === 0 ? $prefix : '') . (string) $v,
            };
        }
        return $rand;
    }

    /**
     * Un tabel al tabloului: coloanele (eticheta, cheia, tipul: text, numar, lei, procent, zecimal, durata) și rândurile (valorile,
     * filtrul la clic, rândurile de sub el).
     *
     * @param array<string, mixed> $perioada
     * @param array<string, string> $filtre
     * @return array{titlu: string, coloane: list<array{eticheta: string, cheie: string, tip: string}>, randuri: list<array<string, mixed>>, nota?: string}
     */
    public static function tabel(PDO $db, string $cheie, array $perioada, array $filtre): array
    {
        $deLa = (string) $perioada['de_la'];
        $panaLa = (string) $perioada['pana_la'];
        $col = static fn (string $eticheta, string $cheie, string $tip = 'numar'): array => ['eticheta' => $eticheta, 'cheie' => $cheie, 'tip' => $tip];
        $coloaneVizite = [$col('Vizitatori', 'vizitatori'), $col('Vizite', 'vizite'), $col('Pagini / vizită', 'pagini_vizita', 'zecimal'),
            $col('Respingere', 'respingere', 'procent'), $col('Adăugări în coș', 'adaugari'), $col('Comenzi', 'comenzi'),
            $col('Conversie', 'conversie', 'procent'), $col('Venituri', 'venit', 'lei')];
        $randVizite = static function (array $r, string $eticheta, array $filtru = []): array {
            $r['pagini_vizita'] = $r['vizite'] > 0 ? $r['pagini'] / $r['vizite'] : 0.0;
            return ['valori' => ['eticheta' => $eticheta] + $r, 'filtru' => $filtru];
        };
        $titlu = self::TABELE[$cheie] ?? $cheie;
        switch ($cheie) {
            case 'surse':
                $copii = [];
                foreach (StatisticiRaport::grupeVizite($db, $deLa, $panaLa, $filtre, ['sursa', 'detaliu'], 500) as $r) {
                    if ((string) $r['detaliu'] === '') {
                        continue;
                    }
                    $copii[(string) $r['sursa']][] = $randVizite($r, (string) $r['detaliu'], ['sursa' => (string) $r['sursa'], 'detaliu' => (string) $r['detaliu']]);
                }
                $randuri = [];
                foreach (StatisticiRaport::grupeVizite($db, $deLa, $panaLa, $filtre, ['sursa'], 50) as $r) {
                    $rand = $randVizite($r, StatisticiRaport::numeSursa((string) $r['sursa']), ['sursa' => (string) $r['sursa']]);
                    $rand['copii'] = array_slice($copii[(string) $r['sursa']] ?? [], 0, 30);
                    $randuri[] = $rand;
                }
                $necunoscute = StatisticiRaport::comenziNecunoscute($db, $deLa, $panaLa, $filtre);
                if ($necunoscute['comenzi'] > 0) {
                    $randuri[] = ['valori' => ['eticheta' => StatisticiRaport::numeSursa('necunoscuta'), 'vizitatori' => '', 'vizite' => '',
                        'pagini_vizita' => '', 'respingere' => '', 'adaugari' => '', 'comenzi' => $necunoscute['comenzi'], 'conversie' => '',
                        'venit' => $necunoscute['venit']], 'filtru' => []];
                }
                return ['titlu' => $titlu, 'coloane' => array_merge([$col('Sursa', 'eticheta', 'text')], $coloaneVizite), 'randuri' => $randuri];
            case 'campanii':
                $randuri = array_map(static fn (array $r): array => $randVizite($r, (string) $r['utm_campanie'], ['campanie' => (string) $r['utm_campanie']]),
                    StatisticiRaport::grupeVizite($db, $deLa, $panaLa, $filtre, ['utm_campanie'], 50, "v.utm_campanie <> ''"));
                return ['titlu' => $titlu, 'coloane' => array_merge([$col('Campania (utm_campaign)', 'eticheta', 'text')], $coloaneVizite),
                    'randuri' => $randuri, 'nota' => 'Linkurile cu utm_campaign (de exemplu din postări, reclame, newslettere).'];
            case 'pagini':
            case 'categorii':
                $randuri = array_map(static fn (array $r): array => ['valori' => ['eticheta' => $r['cale']] + $r,
                    'link' => $r['cale'] === StatisticiAgregare::ALTELE ? '' : $r['cale']],
                    StatisticiRaport::pagini($db, $deLa, $panaLa, $filtre, $cheie === 'categorii' ? 'categorie' : null));
                return ['titlu' => $titlu, 'coloane' => [$col('Pagina', 'eticheta', 'text'), $col('Vizualizări', 'vizualizari'),
                    $col('Vizitatori', 'vizitatori'), $col('Intrări', 'intrari')], 'randuri' => $randuri];
            case 'intrari':
                $randuri = array_map(static fn (array $r): array => $randVizite($r, (string) $r['pagina_intrare'])
                    + ['link' => (string) $r['pagina_intrare'] === StatisticiAgregare::ALTELE ? '' : (string) $r['pagina_intrare']],
                    StatisticiRaport::grupeVizite($db, $deLa, $panaLa, $filtre, ['pagina_intrare'], 50));
                return ['titlu' => $titlu, 'coloane' => [$col('Pagina de intrare', 'eticheta', 'text'), $col('Vizite', 'vizite'),
                    $col('Respingere', 'respingere', 'procent'), $col('Adăugări în coș', 'adaugari'), $col('Vizite cu comandă', 'comenzi'),
                    $col('Conversie', 'conversie', 'procent')], 'randuri' => $randuri];
            case 'produse':
                $randuri = array_map(static fn (array $r): array => ['valori' => ['eticheta' => $r['nume']] + $r,
                    'link' => $r['slug'] !== '' ? '/produs/' . rawurlencode($r['slug']) : ''],
                    StatisticiRaport::produse($db, $deLa, $panaLa, $filtre));
                return ['titlu' => $titlu, 'coloane' => [$col('Produs', 'eticheta', 'text'), $col('Vizualizări', 'vizualizari'),
                    $col('Vizitatori', 'vizitatori'), $col('Adăugări în coș', 'adaugari'), $col('Rata de adăugare', 'rata_cos', 'procent')],
                    'randuri' => $randuri];
            case 'cautari':
            case 'fara_rezultat':
                $randuri = array_map(static fn (array $r): array => ['valori' => ['eticheta' => $r['cautare']] + $r],
                    StatisticiRaport::cautari($db, $deLa, $panaLa, $filtre, $cheie === 'fara_rezultat'));
                return ['titlu' => $titlu, 'coloane' => [$col('Căutarea', 'eticheta', 'text'), $col('Căutări', 'cautari'),
                    $col('Fără rezultat', 'fara_rezultat')], 'randuri' => $randuri];
            case 'dispozitive':
            case 'browsere':
            case 'sisteme':
            case 'tari':
                $coloana = ['dispozitive' => 'dispozitiv', 'browsere' => 'browser', 'sisteme' => 'os', 'tari' => 'tara'][$cheie];
                $nume = ['dispozitiv' => ['desktop' => 'Desktop', 'mobil' => 'Telefon', 'tableta' => 'Tabletă']];
                $randuri = array_map(static fn (array $r): array => $randVizite($r, (string) ($nume[$coloana][(string) $r[$coloana]] ?? $r[$coloana]),
                    $coloana === 'dispozitiv' ? ['dispozitiv' => (string) $r[$coloana]] : []),
                    StatisticiRaport::grupeVizite($db, $deLa, $panaLa, $filtre, [$coloana], 50, $coloana === 'tara' ? "v.tara <> ''" : ''));
                $coloane = $coloana === 'dispozitiv' ? $coloaneVizite : [$col('Vizitatori', 'vizitatori'), $col('Vizite', 'vizite'),
                    $col('Respingere', 'respingere', 'procent'), $col('Vizite cu comandă', 'comenzi'), $col('Conversie', 'conversie', 'procent')];
                return ['titlu' => $titlu, 'coloane' => array_merge([$col(['dispozitiv' => 'Dispozitivul', 'browser' => 'Browserul', 'os' => 'Sistemul',
                    'tara' => 'Țara'][$coloana], 'eticheta', 'text')], $coloane), 'randuri' => $randuri];
            case 'comenzi_cookie':
                $etichete = \App\Support\SursaComenzii::SURSE + ['necunoscuta' => 'Necunoscută (fără acord sau înainte de pornire)'];
                $randuri = array_map(static fn (array $r): array => ['valori' => ['eticheta' => $etichete[$r['sursa']] ?? $r['sursa']] + $r],
                    StatisticiRaport::surseComenziCookie($db, $deLa, $panaLa));
                return ['titlu' => $titlu, 'coloane' => [$col('Sursa (orders.sursa)', 'eticheta', 'text'), $col('Comenzi', 'comenzi'),
                    $col('Venituri', 'venit', 'lei')], 'randuri' => $randuri,
                    'nota' => 'Sursa salvată pe comandă din cookie-ul cu acord (Rapoarte → Sursa comenzilor), fără filtrele de mai sus.'];
        }
        return ['titlu' => $titlu, 'coloane' => [], 'randuri' => []];
    }
}
