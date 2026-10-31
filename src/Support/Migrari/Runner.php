<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use ArrayObject;
use PDO;
use Throwable;

/**
 * Runner-ul migrărilor (docs/MIGRARI.md): planul, aplicarea, verificarea și starea unei baze.
 * Îl folosește `scripts/migreaza.php`; aici nu se scrie nimic pe ecran, doar se întoarce un
 * raport (cod de ieșire, mesaj, migrările, avertismentele) pe care scriptul îl afișează.
 *
 * Aplicarea, pe scurt:
 *  1. lacătul `platforma_migrari:<MD5(baza)>` (un singur runner pe bază; altfel codul 3);
 *  2. baza e a unui site ANDAXI (fără tabele, sau cu _platforma_migrari, sau cu settings +
 *     admins + pages), altfel codul 4 și nu se atinge nimic;
 *  3. registrul: numerele, proprietarii, migrari.lock; o migrare aplicată care s-a schimbat
 *     sau a dispărut → codul 12;
 *  4. simularea tuturor migrărilor de aplicat: planul, verificările de dinainte (valori
 *     repetate sub un index unic, NOT NULL fără DEFAULT, parametrii site-ului: codul 15) și
 *     refuzul celor blocante sau de tip contract fără voie (codul 11), toate înainte să se
 *     schimbe ceva;
 *  5. tabelele de urmărire _platforma_migrari și _platforma_stare (singurul DDL în afara
 *     migrărilor);
 *  6. fiecare migrare, în ordine (întâi cele întrerupte): rândul ei „in_curs”, apoi „aplicata”
 *     (a schimbat ceva) sau „detectata” (totul exista deja); la capătul bugetului de timp,
 *     „partiala” și codul 13 (rularea următoare continuă);
 *  7. verificarea față de asteptat.json (un obiect lipsă → codul 14), apoi starea (ultima_ord,
 *     amprenta codului, serverul, ultima rulare cu codul ei final; `pasi_site` cât pașii site-ului,
 *     de exemplu înghețarea setărilor, n-au terminat: {site, pasi}, scrisă înaintea primei migrări,
 *     ștearsă abia când fiecare pas din ea e aplicat sau detectat).
 *
 * Pachetul site-ului (SITE_DATE_DOSAR) și site-ul rulării se potrivesc, altfel codul 2, înainte de orice
 * scriere: un pachet cu înghețare cere rularea cu acel site (--site sau SITE_SLUG), iar cu --site dosarul
 * pachetului trebuie să existe, să aibă înghețarea pe care o are codul în site-uri/<site>/ și fiecare pas
 * pe care baza îl așteaptă (`pasi_site`). Altfel înghețarea n-ar rula, iar baza ar ajunge la zi fără ea.
 *
 * Starea (_platforma_stare) o scrie doar un runner care a început să aplice: ține lacătul, iar
 * codul lui se potrivește cu baza (pasul 3) și a trecut de simulare. Unul refuzat (lacătul ținut
 * de altul: 3, cod mai vechi decât baza sau o migrare schimbată: 12, bază străină: 4, simularea
 * oprită: 1/11/15, configurarea: 2) n-o atinge: ultima_ord e citită de gardă (/health) și de CRM.
 */
final class Runner
{
    public const TABEL_MIGRARI = '_platforma_migrari';
    public const TABEL_STARE = '_platforma_stare';
    /**
     * Cheia din _platforma_stare care spune că pașii site-ului (înghețarea setărilor) n-au terminat:
     * `{"site": "<site>", "pasi": [id-urile pașilor așteptați]}`, scrisă de un --aplica cu --site înaintea
     * primei migrări și ștearsă abia când fiecare pas din listă e aplicat sau detectat (nu doar când rularea
     * de acum nu mai vede niciunul: un pachet lipsă sau mutat nu are voie s-o șteargă). Cât există, garda
     * spune `in_urma`, deci pașii de activare ai pluginurilor așteaptă (altfel pasul ERP-ului ar scrie
     * `erp_trimite_din` înaintea înghețării, care scrie doar o cheie lipsă).
     */
    public const CHEIE_PASI_SITE = 'pasi_site';
    private const LACAT = "CONCAT('platforma_migrari:', MD5(DATABASE()))";
    private const TERMINATE = ['aplicata', 'detectata'];

    private float $start;
    private int $cuEfectRulare = 0;
    private bool $jurnalScris = true;

    /** @var array<string, int> de câte ori s-a trecut prin fiecare punct de test (opreste_in) */
    private array $puncteAtinse = [];

    /** @var array<string, mixed> */
    private array $optiuni;

    /**
     * Opțiunile: permite_blocante, permite_contract, permite_provizoriu (bool); copie_verificare (bool: baza e a
     * copiei de verificare, SITE_COPIE_VERIFICARE=1; doar acolo merge permite_provizoriu); buget (secunde); pana_la (id);
     * site (slug); dosar_site (pachetul site-ului: migrari.json, setari-inghetate.json); dosar_pachete (pachetele din
     * cod, site-uri/: o înghețare de acolo trebuie să fie și în dosar_site); opreste_dupa (test: oprire ca un proces
     * omorât după atâtea operații); opreste_in (test: „punct:k”, oprire la a k-a trecere prin
     * punctul acela din Schema, de exemplu în tranzacția unei bucăți); pauze (secundele
     * reîncercărilor); pauza_bucati_ms; jurnal (fișierul cu câte un rând pe operație); cod_versiune.
     *
     * @param array<string, mixed> $optiuni
     */
    public function __construct(
        private PDO $db,
        private Server $server,
        private Registru $registru,
        array $optiuni = [],
    ) {
        $this->start = microtime(true);
        $this->optiuni = $optiuni + [
            'permite_blocante' => false,
            'permite_contract' => false,
            'permite_provizoriu' => false,
            'copie_verificare' => false,
            'buget' => 300.0,
            'pana_la' => null,
            'site' => null,
            'dosar_site' => '',
            'dosar_pachete' => '',
            'opreste_dupa' => null,
            'opreste_in' => null,
            'pauze' => [2.0, 4.0, 8.0, 16.0, 30.0],
            'pauza_bucati_ms' => 50,
            'jurnal' => null,
            'cod_versiune' => null,
        ];
    }

    // ------------------------------------------------------------- comenzile

    /** --plan: ce s-ar face pe această bază. Nu schimbă nimic (nici tabelele de urmărire). */
    public function plan(): array
    {
        $this->paznic();
        $this->verificaPachetul();
        $randuri = $this->randuri();
        $this->verificaSchimbari($randuri);
        $this->verificaMarcajul($randuri);
        $deAplicat = $this->deAplicat($randuri);
        $raport = $this->raport('plan');
        $ramase = array_merge($this->tabeleRamase(), $this->avertismentMarcaj());
        if ($deAplicat === []) {
            return $raport + ['cod' => 0, 'mesaj' => 'Nimic de făcut: baza e la zi cu migrările din cod.', 'migrari' => [], 'avertismente' => $ramase];
        }
        [$simulate, $avertismente] = $this->simuleaza($deAplicat, $randuri);
        $avertismente = array_merge($ramase, $avertismente);
        $nepermise = array_filter($simulate, static fn (array $m): bool => $m['blocanta_efectiv'] || $m['tip'] === 'contract');
        $cod = $nepermise !== [] ? Eroare::NEPERMISA : Eroare::DE_APLICAT;
        $mesaj = count($simulate) . ' ' . (count($simulate) === 1 ? 'migrare' : 'migrări') . ' de aplicat';
        $mesaj .= $nepermise !== [] ? ', dintre care ' . count($nepermise) . ' cer --permite-blocante sau --permite-contract.' : ', toate fără blocare.';
        return $raport + ['cod' => $cod, 'mesaj' => $mesaj, 'migrari' => $simulate, 'avertismente' => $avertismente];
    }

    /** --aplica: aplică migrările care lipsesc, apoi verifică baza. Întoarce mereu un raport. */
    public function aplica(): array
    {
        // Raportul citește și pachetul site-ului (care poate fi greșit: codul 2), deci se face în try; până
        // atunci un refuz are doar comanda și site-ul (install.php îl scrie pe stderr, ca pe orice refuz).
        $raport = ['comanda' => 'aplica', 'site' => $this->optiuni['site']];
        $aplicate = [];
        $avertismente = [];
        $areLacat = false;
        // Starea se scrie doar după ce rularea a trecut de toate verificările și a început să aplice
        // (vezi capul clasei): un runner refuzat nu-i schimbă ultima_ord, amprenta sau ultima rulare.
        $scrieStarea = false;
        $modulServerului = null;
        try {
            $raport = $this->raport('aplica');
            $this->paznic();
            // Migrările rulează cu STRICT_TRANS_TABLES în sesiunea lor (WP-14): pe un server non-strict (Hostinger:
            // NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION) un ALTER care îngustează sau schimbă tipul unei coloane ar tăia
            // datele cu un simplu avertisment (1265) și ar merge mai departe; strict, se oprește cu eroare și datele rămân.
            // Testele migrărilor rulează oricum pe servere stricte (MariaDB 10.11 și MySQL 8 implicit), deci pe site
            // migrările merg exact ca în teste. Modul serverului se pune la loc la final (install.php continuă pe aceeași
            // conexiune, cu codul site-ului, care rămâne în modul serverului).
            $modulServerului = $raport['sql_mode'];
            if ($raport['sql_mode_migrari'] !== $modulServerului) {
                $this->db->prepare('SET SESSION sql_mode = ?')->execute([$raport['sql_mode_migrari']]);
            }
            $this->verificaPachetul();
            $areLacat = (int) $this->db->query('SELECT GET_LOCK(' . self::LACAT . ', 0)')->fetchColumn() === 1;
            if (!$areLacat) {
                throw new Eroare('alt runner aplică acum migrări pe această bază (lacătul platforma_migrari e ținut); încearcă mai târziu', Eroare::LACAT);
            }
            $randuri = $this->randuri();
            $this->verificaSchimbari($randuri);
            $this->verificaMarcajul($randuri);
            $deAplicat = $this->deAplicat($randuri);
            $avertismente = array_merge($this->tabeleRamase(), $this->avertismentMarcaj());
            if ($deAplicat !== []) {
                [$simulate, $deLaSimulare] = $this->simuleaza($deAplicat, $randuri);
                $avertismente = array_merge($avertismente, $deLaSimulare);
                $refuzate = [];
                foreach ($simulate as $m) {
                    if ($m['blocanta_efectiv'] && !$this->optiuni['permite_blocante']) {
                        $refuzate[] = $m['id'] . ' (blocantă)';
                    } elseif ($m['tip'] === 'contract' && !$this->optiuni['permite_contract']) {
                        $refuzate[] = $m['id'] . ' (contract)';
                    }
                }
                if ($refuzate !== []) {
                    return $raport + ['cod' => Eroare::NEPERMISA, 'migrari' => $simulate, 'avertismente' => $avertismente,
                        'mesaj' => 'Nu aplic nimic: ' . implode(', ', $refuzate) . ' cer --permite-blocante (cu site-ul în MENTENANTA) '
                            . 'sau --permite-contract. Planul e mai sus; baza n-a fost atinsă.'];
                }
            }
            $scrieStarea = true;
            $this->creeazaTabeleleDeUrmarire();
            // Pașii site-ului vin după toate migrările: până termină, garda spune `in_urma` (chiar dacă rularea
            // e oprită de buget sau omorâtă între ultima migrare și înghețare), deci nimic nu scrie setări înaintea ei.
            $this->actualizeazaMarcajul($randuri, false);
            if ($deAplicat !== []) {
                $this->scrieRandJurnal('--aplica: ' . count($deAplicat) . ' de aplicat, ' . $this->server->nume()
                    . ', sql_mode ' . ($raport['sql_mode'] !== '' ? $raport['sql_mode'] : '(gol)')
                    . ($raport['sql_mode_migrari'] !== $raport['sql_mode'] ? ' (migrările: + STRICT_TRANS_TABLES)' : ''));
            }
            foreach ($deAplicat as $m) {
                if ($this->timpScurs() >= (float) $this->optiuni['buget']) {
                    throw new Eroare('bugetul de timp (' . $this->optiuni['buget'] . ' s) s-a terminat înainte de ' . $m['id']
                        . '; starea e consecventă, rulează din nou', Eroare::BUGET);
                }
                $aplicate[] = $this->aplicaUna($m, $randuri[$m['id']] ?? null, $avertismente);
            }
            $randuri = $this->randuri();
            // Cu --pana-la oprit înainte de capăt, schema e voit incompletă: verificarea ar pica degeaba.
            $verificare = $this->optiuni['pana_la'] !== null && $this->deAplicat($randuri, false) !== []
                ? ['lipsa' => [], 'avertismente' => [], 'in_plus' => [], 'fara_asteptat' => false,
                    'mesaj' => 'sărită: --pana-la a oprit înainte de ultima migrare.']
                : $this->verificaDupaAplicare();
            $cod = $verificare['lipsa'] !== [] || $verificare['fara_asteptat'] ? Eroare::VERIFICARE : 0;
            $this->inregistreazaStarea($randuri, $cod);
            $mesaj = $aplicate === [] ? 'Nimic de făcut: baza era la zi.' : $this->rezumat($aplicate);
            $mesaj .= $cod === 0 ? ' Verificarea: ' . $verificare['mesaj'] : ' VERIFICAREA A PICAT: ' . $verificare['mesaj'];
            return $raport + ['cod' => $cod, 'mesaj' => $mesaj, 'migrari' => $aplicate, 'avertismente' => $avertismente,
                'verificare' => $verificare, 'ultima_ord' => $this->ultimaOrd($randuri)];
        } catch (Eroare $e) {
            if ($scrieStarea) {
                $this->inregistreazaStareaDacaSe($e->getCode());
            }
            return $raport + ['cod' => $e->getCode(), 'mesaj' => $e->getMessage(), 'migrari' => $aplicate, 'avertismente' => $avertismente];
        } catch (Throwable $e) {
            if ($scrieStarea) {
                $this->inregistreazaStareaDacaSe(Eroare::MIGRARE);
            }
            return $raport + ['cod' => Eroare::MIGRARE, 'mesaj' => 'eroare neașteptată: ' . Schema::faraValori($e->getMessage()),
                'migrari' => $aplicate, 'avertismente' => $avertismente];
        } finally {
            if ($areLacat) {
                try {
                    $this->db->query('SELECT RELEASE_LOCK(' . self::LACAT . ')');
                } catch (Throwable) {
                    // Conexiunea închisă eliberează oricum lacătul.
                }
            }
            if ($modulServerului !== null && ($raport['sql_mode_migrari'] ?? $modulServerului) !== $modulServerului) {
                try {
                    $this->db->prepare('SET SESSION sql_mode = ?')->execute([$modulServerului]);
                } catch (Throwable) {
                    // O conexiune pierdută nu mai are sesiune de pus la loc.
                }
            }
        }
    }

    /** --verifica: baza față de asteptat.json (doar nume de obiecte). */
    public function verifica(): array
    {
        $this->paznic();
        $raport = $this->raport('verifica');
        $verificare = $this->verificaDupaAplicare();
        $cod = $verificare['lipsa'] !== [] || $verificare['fara_asteptat'] ? Eroare::VERIFICARE : 0;
        return $raport + ['cod' => $cod, 'mesaj' => ($cod === 0 ? 'Verificarea: ' : 'VERIFICAREA A PICAT: ') . $verificare['mesaj'],
            'verificare' => $verificare, 'migrari' => [], 'avertismente' => []];
    }

    /** --stare: ce s-a aplicat, ce lipsește, ce e în curs. Nu schimbă nimic. */
    public function stare(): array
    {
        $this->paznic();
        $this->verificaPachetul();
        $raport = $this->raport('stare');
        $randuri = $this->randuri();
        $stare = [];
        if ($this->tabelExista(self::TABEL_STARE)) {
            foreach ($this->db->query('SELECT cheie, valoare, actualizata_la FROM ' . self::TABEL_STARE)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $stare[(string) $r['cheie']] = (string) $r['valoare'];
            }
        }
        $terminate = array_filter($randuri, static fn (array $r): bool => in_array($r['rezultat'], self::TERMINATE, true));
        $inCurs = array_values(array_map(static fn (array $r): string => $r['id'] . ' (' . $r['rezultat'] . ')',
            array_filter($randuri, static fn (array $r): bool => !in_array($r['rezultat'], self::TERMINATE, true))));
        $lipsa = [];
        $blocante = [];
        $toate = $this->registru->migrari();
        if ($this->optiuni['site'] !== null) {
            $toate = array_merge($toate, $this->registru->pasiSite((string) $this->optiuni['site'], (string) $this->optiuni['dosar_site']));
        }
        foreach ($toate as $m) {
            if (!isset($terminate[$m['id']])) {
                $lipsa[] = $m['id'];
                if ($m['blocanta']) {
                    $blocante[] = $m['id'];
                }
            }
        }
        $ultima = null;
        foreach ($terminate as $r) {
            if ($ultima === null || [(string) $r['terminata_la'], (int) $r['ord']] > [(string) $ultima['terminata_la'], (int) $ultima['ord']]) {
                $ultima = $r;
            }
        }
        $ocupat = $this->db->query('SELECT IS_USED_LOCK(' . self::LACAT . ')')->fetchColumn();
        $mesaj = $randuri === [] && !$this->tabelExista(self::TABEL_MIGRARI)
            ? 'Baza n-a văzut încă runner-ul (fără _platforma_migrari).'
            : count($terminate) . ' migrări terminate, ultima_ord ' . $this->ultimaOrd($randuri) . '.';
        $mesaj .= ' ' . ($lipsa === [] ? 'Nimic de aplicat.' : count($lipsa) . ' de aplicat' . ($blocante !== [] ? ' (' . count($blocante) . ' blocante)' : '') . '.');
        return $raport + [
            'cod' => 0,
            'mesaj' => $mesaj,
            'lock_wait_timeout' => (int) $this->db->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn(),
            'ultima_ord' => $this->ultimaOrd($randuri),
            'ultima_migrare' => $ultima['id'] ?? null,
            'terminate' => count($terminate),
            'de_aplicat' => $lipsa,
            'blocante_de_aplicat' => $blocante,
            'in_curs' => $inCurs,
            'runner_activ' => $ocupat !== null && $ocupat !== false,
            'stare' => $stare,
            'migrari' => [],
            'avertismente' => [],
        ];
    }

    /** --amprenta: amprenta schemei acestei baze (doar structură), ca JSON. */
    public function amprenta(): string
    {
        $this->paznic();
        return Amprenta::json(Amprenta::citeste($this->db, $this->server));
    }

    // -------------------------------------------------------------- aplicarea

    /** @return array<string, mixed> */
    private function aplicaUna(array $m, ?array $rand, array &$avertismente): array
    {
        $cursor = [];
        if ($rand !== null) {
            if ($rand['sha256'] === $m['sha256']) {
                $cursor = json_decode((string) ($rand['cursor_json'] ?? ''), true) ?: [];
                $stmt = $this->db->prepare('UPDATE ' . self::TABEL_MIGRARI . " SET rezultat = 'in_curs', terminata_la = NULL,
                    cod_versiune = ?, server_versiune = ? WHERE id = ?");
                $stmt->execute([$this->optiuni['cod_versiune'], $this->server->text(), $m['id']]);
            } else {
                $avertismente[] = $m['id'] . ': fișierul s-a schimbat de la rularea întreruptă; o iau de la capăt (detectarea sare peste ce e făcut)';
                $stmt = $this->db->prepare('UPDATE ' . self::TABEL_MIGRARI . " SET rezultat = 'in_curs', terminata_la = NULL, cursor_json = NULL,
                    sha256 = ?, ord = ?, proprietar = ?, tip = ?, blocanta = ?, cod_versiune = ?, server_versiune = ? WHERE id = ?");
                $stmt->execute([$m['sha256'], $m['ord'], $m['proprietar'], $m['tip'], $m['blocanta'] ? 1 : 0,
                    $this->optiuni['cod_versiune'], $this->server->text(), $m['id']]);
            }
        } else {
            $stmt = $this->db->prepare('INSERT INTO ' . self::TABEL_MIGRARI . " (id, ord, proprietar, tip, sha256, blocanta, rezultat,
                operatii, cursor_json, cod_versiune, server_versiune, inceputa_la)
                VALUES (?, ?, ?, ?, ?, ?, 'in_curs', 0, NULL, ?, ?, NOW())");
            $stmt->execute([$m['id'], $m['ord'], $m['proprietar'], $m['tip'], $m['sha256'], $m['blocanta'] ? 1 : 0,
                $this->optiuni['cod_versiune'], $this->server->text()]);
        }

        $id = $m['id'];
        $progres = function (int $delta, ?array $cursorNou) use ($id): void {
            if ($cursorNou === null) {
                if ($delta === 0) {
                    return;
                }
                $stmt = $this->db->prepare('UPDATE ' . self::TABEL_MIGRARI . ' SET operatii = operatii + ? WHERE id = ?');
                $stmt->execute([$delta, $id]);
                return;
            }
            $stmt = $this->db->prepare('UPDATE ' . self::TABEL_MIGRARI . ' SET operatii = operatii + ?, cursor_json = ? WHERE id = ?');
            $stmt->execute([$delta, json_encode($cursorNou, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        };
        $executie = new Executie(
            $id,
            (string) $m['tip'],
            false,
            (bool) $this->optiuni['permite_blocante'],
            (string) $this->optiuni['dosar_site'],
            fn () => $this->verificaBugetul($id),
            $progres,
            fn () => $this->dupaOperatie(),
            fn (string $fel, string $obiect, string $clasa, float $ms) => $this->scrieJurnal($id, $fel, $obiect, $clasa, $ms),
            array_values(array_map('floatval', (array) $this->optiuni['pauze'])),
            (int) $this->optiuni['pauza_bucati_ms'],
        );
        $executie->cursor = is_array($cursor) ? $cursor : [];
        $executie->punctTest = $this->punctTest();
        $executie->permiteProvizoriu = $this->provizoriuPermis();
        $executie->provizoriuCerut = (bool) $this->optiuni['permite_provizoriu'];
        $schema = new Schema($this->db, $this->server, $executie);
        $start = microtime(true);
        $durata = static fn (): int => (int) round((microtime(true) - $start) * 1000);
        try {
            ($m['sus'])($schema);
        } catch (Throwable $e) {
            $partiala = $e instanceof Eroare && $e->getCode() === Eroare::BUGET;
            $stmt = $this->db->prepare('UPDATE ' . self::TABEL_MIGRARI . ' SET rezultat = ?, durata_ms = COALESCE(durata_ms, 0) + ? WHERE id = ?');
            $stmt->execute([$partiala ? 'partiala' : 'in_curs', $durata(), $id]);
            $avertismente = array_merge($avertismente, $executie->avertismente);
            if ($e instanceof Eroare) {
                throw $e;
            }
            throw new Eroare($id . ': ' . Schema::faraValori($e->getMessage()), Eroare::MIGRARE);
        } finally {
            $schema->elibereazaLacatele();
        }
        $stmt = $this->db->prepare('SELECT operatii FROM ' . self::TABEL_MIGRARI . ' WHERE id = ?');
        $stmt->execute([$id]);
        $total = (int) $stmt->fetchColumn();
        $rezultat = $total > 0 ? 'aplicata' : 'detectata';
        $ms = $durata();
        $stmt = $this->db->prepare('UPDATE ' . self::TABEL_MIGRARI . ' SET rezultat = ?, cursor_json = NULL, terminata_la = NOW(),
            durata_ms = COALESCE(durata_ms, 0) + ? WHERE id = ?');
        $stmt->execute([$rezultat, $ms, $id]);
        $avertismente = array_merge($avertismente, $executie->avertismente);
        $faraEfect = count(array_filter($executie->operatii, static fn (array $o): bool => $o['clasa'] === 'fara_efect'));
        return [
            'id' => $id,
            'ord' => $m['ord'],
            'proprietar' => $m['proprietar'],
            'tip' => $m['tip'],
            'blocanta' => $m['blocanta'],
            'descriere' => $m['descriere'],
            'stare' => $rezultat,
            'operatii_acum' => $executie->cuEfect,
            'operatii_total' => $total,
            'fara_efect' => $faraEfect,
            'durata_ms' => $ms,
            'operatii' => array_values(array_filter($executie->operatii, static fn (array $o): bool => $o['clasa'] !== 'fara_efect')),
        ];
    }

    private function verificaBugetul(string $id): void
    {
        if ($this->timpScurs() >= (float) $this->optiuni['buget']) {
            throw new Eroare('bugetul de timp (' . $this->optiuni['buget'] . ' s) s-a terminat în ' . $id
                . '; migrarea rămâne „partiala”, rularea următoare continuă de unde a rămas', Eroare::BUGET);
        }
    }

    private function dupaOperatie(): void
    {
        $this->cuEfectRulare++;
        $oprire = $this->optiuni['opreste_dupa'];
        if ($oprire !== null && $this->cuEfectRulare >= (int) $oprire) {
            // Doar pentru teste (MIGRARI_TEST_OPRESTE_DUPA): ca un proces omorât, fără nicio scriere în plus.
            fwrite(STDERR, 'oprire de test după ' . $this->cuEfectRulare . " operații (MIGRARI_TEST_OPRESTE_DUPA)\n");
            exit(1);
        }
    }

    /**
     * Doar pentru teste (opreste_in = „punct:k”): la a k-a trecere prin punctul dat din Schema,
     * procesul se oprește ca unul omorât (exit, fără finally și fără nicio scriere în plus;
     * serverul anulează tranzacția deschisă).
     *
     * @return (\Closure(string): void)|null
     */
    private function punctTest(): ?\Closure
    {
        $oprire = $this->optiuni['opreste_in'];
        if (!is_string($oprire) || preg_match('/^([a-z_]+):([1-9]\d*)$/', $oprire, $m) !== 1) {
            return null;
        }
        [, $punct, $k] = $m;
        return function (string $atins) use ($punct, $k): void {
            if ($atins !== $punct) {
                return;
            }
            $this->puncteAtinse[$punct] = ($this->puncteAtinse[$punct] ?? 0) + 1;
            if ($this->puncteAtinse[$punct] >= (int) $k) {
                fwrite(STDERR, 'oprire de test la ' . $punct . ' #' . $this->puncteAtinse[$punct] . " (MIGRARI_TEST_OPRESTE_IN)\n");
                exit(1);
            }
        };
    }

    private function scrieJurnal(string $id, string $fel, string $obiect, string $clasa, float $ms): void
    {
        $this->scrieRandJurnal($id . ' ' . $fel . ' ' . $obiect . ' ' . $clasa . ' ' . (int) round($ms) . 'ms');
    }

    /** Un rând în storage/logs/migrari.log, cu data în față (doar nume de obiecte și numere). */
    private function scrieRandJurnal(string $text): void
    {
        $fisier = $this->optiuni['jurnal'];
        if (!is_string($fisier) || $fisier === '' || !$this->jurnalScris) {
            return;
        }
        $linie = date('Y-m-d H:i:s') . ' ' . $text . "\n";
        $dosar = dirname($fisier);
        if ((!is_dir($dosar) && !@mkdir($dosar, 0770, true)) || @file_put_contents($fisier, $linie, FILE_APPEND | LOCK_EX) === false) {
            $this->jurnalScris = false;
        }
    }

    /**
     * Simularea migrărilor de aplicat, în ordine, pe un model comun (a doua vede ce creează
     * prima). Aruncă la un parametru lipsă (15), la o verificare care pică (1) sau la un
     * fișier care nu merge.
     *
     * @param list<array<string, mixed>> $deAplicat
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function simuleaza(array $deAplicat, array $randuri): array
    {
        $model = new ArrayObject();
        $simulate = [];
        $avertismente = [];
        $nimic = static function (): void {
        };
        foreach ($deAplicat as $m) {
            $rand = $randuri[$m['id']] ?? null;
            $executie = new Executie(
                $m['id'],
                (string) $m['tip'],
                true,
                (bool) $this->optiuni['permite_blocante'],
                (string) $this->optiuni['dosar_site'],
                $nimic,
                static function (int $delta, ?array $cursor): void {
                },
                $nimic,
                static function (string $fel, string $obiect, string $clasa, float $ms): void {
                },
            );
            $executie->model = $model;
            $executie->permiteProvizoriu = $this->provizoriuPermis();
            $executie->provizoriuCerut = (bool) $this->optiuni['permite_provizoriu'];
            if ($rand !== null && $rand['sha256'] === $m['sha256']) {
                $executie->cursor = json_decode((string) ($rand['cursor_json'] ?? ''), true) ?: [];
            }
            $schema = new Schema($this->db, $this->server, $executie);
            try {
                ($m['sus'])($schema);
            } catch (Eroare $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new Eroare($m['id'] . ' (în simulare): ' . Schema::faraValori($e->getMessage()), Eroare::MIGRARE);
            }
            $clase = array_count_values(array_column($executie->operatii, 'clasa'));
            $simulate[] = [
                'id' => $m['id'],
                'ord' => $m['ord'],
                'proprietar' => $m['proprietar'],
                'tip' => $m['tip'],
                'blocanta' => $m['blocanta'],
                'blocanta_efectiv' => $m['blocanta'] || isset($clase['copie']),
                'descriere' => $m['descriere'],
                'stare' => $rand === null ? 'noua' : 'reluata (' . $rand['rezultat'] . ')',
                'clase' => $clase,
                'operatii' => $executie->operatii,
            ];
            $avertismente = array_merge($avertismente, $executie->avertismente);
        }
        return [$simulate, $avertismente];
    }

    // ---------------------------------------------------------- verificările

    /**
     * Codul 4: baza are tabele, dar nu e a unui site ANDAXI (de exemplu un WordPress). `_verificare`, marcajul copiei de
     * verificare, nu contează: încărcarea copiei (tools/verificare/incarca.php) îl scrie primul, deci copia demonstrativă
     * rulează migrările pe o bază care are doar marcajul (docs/VERIFICARE.md, „Încărcarea copiei”).
     */
    private function paznic(): void
    {
        $tabele = array_values(array_diff($this->db->query("SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN), ['_verificare']));
        if ($tabele === [] || in_array(self::TABEL_MIGRARI, $tabele, true)
            || array_diff(['settings', 'admins', 'pages'], $tabele) === []) {
            return;
        }
        throw new Eroare('baza are ' . count($tabele) . ' tabele, dar nu e a unui site ANDAXI (lipsesc settings, admins sau pages '
            . 'și nu are _platforma_migrari); nu o ating', Eroare::BAZA_STRAINA);
    }

    /**
     * Codul 2, înainte de orice scriere: pachetul site-ului (SITE_DATE_DOSAR) nu se potrivește cu rularea, deci
     * înghețarea n-ar rula, iar baza ar ajunge `la_zi` fără ea:
     *  - pachetul are înghețarea unui site, dar rularea e fără site (fără --site și fără SITE_SLUG);
     *  - rularea e cu un site, dar dosarul pachetului nu există (o cale greșită, un pachet mutat);
     *  - codul are înghețarea acelui site (`site-uri/<site>/setari-inghetate.json`), dar pachetul din
     *    SITE_DATE_DOSAR n-o are (alt dosar, un pachet incomplet).
     * (Un pachet al altui site decât --site îl oprește Registru::pasiSite, tot cu 2.)
     */
    private function verificaPachetul(): void
    {
        $dosar = (string) $this->optiuni['dosar_site'];
        $site = $this->optiuni['site'];
        $sitePachet = $this->registru->sitePachet($dosar);
        if ($sitePachet !== null && $site === null) {
            throw new Eroare('dosarul site-ului (SITE_DATE_DOSAR) are înghețarea setărilor site-ului „' . $sitePachet . '”, dar rularea e fără '
                . 'site: rulează cu --site=' . $sitePachet . ' (sau SITE_SLUG=' . $sitePachet . ' în .env), altfel înghețarea n-ar rula', Eroare::CONFIGURARE);
        }
        if ($site === null) {
            return;
        }
        if ($dosar === '' || !is_dir($dosar)) {
            throw new Eroare('--site=' . $site . ', dar dosarul site-ului (SITE_DATE_DOSAR) nu există: ' . ($dosar !== '' ? $dosar : '(gol)')
                . '; pachetul site-ului (setari-inghetate.json, migrari.json) trebuie să fie acolo, altfel pașii lui n-ar rula', Eroare::CONFIGURARE);
        }
        $dinCod = rtrim((string) $this->optiuni['dosar_pachete'], '/') . '/' . $site . '/' . Inghetare::FISIER;
        if ($sitePachet === null && $this->optiuni['dosar_pachete'] !== '' && is_file($dinCod)) {
            throw new Eroare('--site=' . $site . ': codul are înghețarea setărilor acestui site (site-uri/' . $site . '/' . Inghetare::FISIER
                . '), dar dosarul site-ului (SITE_DATE_DOSAR: ' . $dosar . ') n-o are; pune acolo pachetul site-ului, altfel înghețarea n-ar rula',
                Eroare::CONFIGURARE);
        }
    }

    /**
     * Codul 2, înainte de orice scriere: baza așteaptă pașii unui site (`pasi_site`), iar rularea cu --site nu-i
     * poate face: e alt site, sau pachetul (SITE_DATE_DOSAR) nu mai are un pas din listă (un dosar greșit, un
     * fișier scos). Fără refuz, rularea ar ajunge la capăt fără ei și ar șterge cheia, deci garda ar spune
     * `la_zi` fără înghețare. Fără --site nu se refuză nimic (cheia rămâne; avertismentul o spune).
     */
    private function verificaMarcajul(array $randuri): void
    {
        $marcaj = $this->citesteMarcajul();
        $site = $this->optiuni['site'];
        if ($marcaj === null || $site === null) {
            return;
        }
        if ($marcaj['site'] !== $site) {
            throw new Eroare('baza așteaptă pașii site-ului „' . $marcaj['site'] . '” (_platforma_stare.pasi_site), nu ai lui „' . $site
                . '”; refuz să rulez pașii altui site', Eroare::CONFIGURARE);
        }
        $vazute = array_column($this->registru->pasiSite((string) $site, (string) $this->optiuni['dosar_site']), 'id');
        $lipsa = [];
        foreach ($marcaj['pasi'] ?? [] as $id) {
            if (!in_array($randuri[$id]['rezultat'] ?? '', self::TERMINATE, true) && !in_array($id, $vazute, true)) {
                $lipsa[] = $id;
            }
        }
        // O cheie fără listă (forma de dinainte, doar numele site-ului): rularea trebuie să vadă măcar un pas al site-ului.
        if ($marcaj['pasi'] === null && $vazute === []) {
            $lipsa[] = 'pașii site-ului „' . $site . '”';
        }
        if ($lipsa !== []) {
            throw new Eroare('baza așteaptă ' . implode(', ', $lipsa) . ' (_platforma_stare.pasi_site), dar pachetul site-ului (SITE_DATE_DOSAR: '
                . $this->optiuni['dosar_site'] . ') ' . (count($lipsa) === 1 ? 'nu-l are' : 'nu-i are') . '; pune pachetul la loc (un pas '
                . 'așteptat nu se scoate din pachet), altfel baza ar ajunge la zi fără înghețare', Eroare::CONFIGURARE);
        }
    }

    /** @return list<string> avertismentul unei rulări fără --site pe o bază care așteaptă pașii unui site */
    private function avertismentMarcaj(): array
    {
        $marcaj = $this->optiuni['site'] === null ? $this->citesteMarcajul() : null;
        return $marcaj === null ? [] : ['baza așteaptă pașii site-ului „' . $marcaj['site'] . '” (' . implode(', ', $marcaj['pasi'] ?? ['?'])
            . '): garda spune in_urma până la --aplica --site=' . $marcaj['site']];
    }

    /** Codul 12: o migrare aplicată s-a schimbat sau a dispărut din cod (o înghețare: din pachetul site-ului). */
    private function verificaSchimbari(array $randuri): void
    {
        $this->registru->verificaLacatul();
        $site = $this->optiuni['site'] !== null ? (string) $this->optiuni['site'] : null;
        $fisiere = $this->registru->fisiere($site, (string) $this->optiuni['dosar_site']);
        foreach ($randuri as $id => $rand) {
            if (!in_array($rand['rezultat'], self::TERMINATE, true)) {
                continue;
            }
            // Înghețarea unui site stă în pachetul lui, nu în cod: fără --site al acelui site nu se poate verifica.
            $siteInghetare = Registru::pasInghetare((string) $id);
            if ($siteInghetare !== null && $siteInghetare !== $site) {
                continue;
            }
            if ($siteInghetare !== null && !isset($fisiere[$id])) {
                throw new Eroare('pasul ' . $id . ' e aplicat pe această bază, dar fișierul lui lipsește din pachetul site-ului '
                    . '(SITE_DATE_DOSAR); un fișier de înghețare aplicat rămâne în pachet', Eroare::SCHIMBATA);
            }
            if (!isset($fisiere[$id])) {
                if ($this->registru->retrasa($id)) {
                    continue;
                }
                throw new Eroare('migrarea ' . $id . ' e aplicată pe această bază, dar nu mai există în cod (o migrare retrasă se trece '
                    . 'în migrari.lock la „retrase”); cod mai vechi decât baza?', Eroare::SCHIMBATA);
            }
            if ($fisiere[$id] !== $rand['sha256']) {
                throw new Eroare('migrarea ' . $id . ' s-a schimbat după ce a fost aplicată pe această bază (sha256 diferit); '
                    . ($siteInghetare !== null
                        ? 'o înghețare aplicată nu se mai schimbă: o corectură e un fișier nou, setari-inghetate-2.json (docs/MIGRARI.md)'
                        : 'o schimbare se face într-o migrare nouă'), Eroare::SCHIMBATA);
            }
        }
    }

    /**
     * Ce e de aplicat, în ordine: întâi cele întrerupte (in_curs, partiala), apoi cele noi,
     * după număr; pașii site-ului la urmă. --pana-la oprește după id-ul dat ($doarPanaLa = false:
     * tot ce lipsește, oricare ar fi --pana-la).
     *
     * @return list<array<string, mixed>>
     */
    private function deAplicat(array $randuri, bool $doarPanaLa = true): array
    {
        $toate = $this->registru->migrari();
        $pasi = $this->optiuni['site'] !== null ? $this->registru->pasiSite((string) $this->optiuni['site'], (string) $this->optiuni['dosar_site']) : [];
        $permise = null;
        if ($doarPanaLa && $this->optiuni['pana_la'] !== null) {
            $permise = [];
            $gasit = false;
            foreach (array_merge($toate, $pasi) as $m) {
                $permise[$m['id']] = true;
                if ($m['id'] === $this->optiuni['pana_la']) {
                    $gasit = true;
                    break;
                }
            }
            if (!$gasit) {
                throw new Eroare('--pana-la=' . $this->optiuni['pana_la'] . ': nu există o migrare cu acest id', Eroare::CONFIGURARE);
            }
        }
        $grupe = [[], [], [], []];
        foreach ([$toate, $pasi] as $k => $lista) {
            foreach ($lista as $m) {
                $rand = $randuri[$m['id']] ?? null;
                if (($rand !== null && in_array($rand['rezultat'], self::TERMINATE, true)) || ($permise !== null && !isset($permise[$m['id']]))) {
                    continue;
                }
                $grupe[$k * 2 + ($rand !== null ? 0 : 1)][] = $m;
            }
        }
        return array_merge(...$grupe);
    }

    /**
     * @return array{lipsa: list<string>, avertismente: list<string>, in_plus: list<string>, fara_asteptat: bool, mesaj: string}
     */
    private function verificaDupaAplicare(): array
    {
        $asteptat = $this->registru->asteptat();
        if ($asteptat === null) {
            if ($this->registru->migrari() === []) {
                return ['lipsa' => [], 'avertismente' => [], 'in_plus' => [], 'fara_asteptat' => false,
                    'mesaj' => 'nimic de verificat (nicio migrare în cod).'];
            }
            return ['lipsa' => [], 'avertismente' => [], 'in_plus' => [], 'fara_asteptat' => true,
                'mesaj' => 'lipsește ' . $this->registru->dosar() . '/asteptat.json, deci nu pot spune dacă baza e completă '
                    . '(se generează după ce se schimbă migrările; docs/MIGRARI.md).'];
        }
        $diferente = Amprenta::compara($asteptat, Amprenta::citeste($this->db, $this->server));
        $diferente['fara_asteptat'] = false;
        $diferente['mesaj'] = $diferente['lipsa'] === []
            ? 'baza are tot ce cer migrările (' . count($asteptat['tabele']) . ' tabele)'
                . ($diferente['avertismente'] !== [] ? ', cu ' . count($diferente['avertismente']) . ' avertismente' : '')
                . ($diferente['in_plus'] !== [] ? '; ' . count($diferente['in_plus']) . ' obiecte în plus (doar informație)' : '') . '.'
            : count($diferente['lipsa']) . ' obiecte lipsă: ' . implode(', ', array_slice($diferente['lipsa'], 0, 20))
                . (count($diferente['lipsa']) > 20 ? ' …' : '') . '.';
        return $diferente;
    }

    // ------------------------------------------------- tabelele de urmărire

    private function creeazaTabeleleDeUrmarire(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::TABEL_MIGRARI . ' (
            id              VARCHAR(190) NOT NULL PRIMARY KEY,
            ord             INT UNSIGNED NOT NULL,
            proprietar      VARCHAR(40)  NOT NULL,
            tip             VARCHAR(10)  NOT NULL,
            sha256          CHAR(64)     NOT NULL,
            blocanta        TINYINT(1)   NOT NULL DEFAULT 0,
            rezultat        VARCHAR(12)  NOT NULL,
            operatii        INT UNSIGNED NOT NULL DEFAULT 0,
            cursor_json     TEXT NULL,
            cod_versiune    VARCHAR(40)  NULL,
            server_versiune VARCHAR(80)  NULL,
            inceputa_la     DATETIME     NOT NULL,
            terminata_la    DATETIME     NULL,
            durata_ms       INT UNSIGNED NULL,
            KEY idx_pm_ord (ord)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::TABEL_STARE . ' (
            cheie           VARCHAR(64) NOT NULL PRIMARY KEY,
            valoare         TEXT        NOT NULL,
            actualizata_la  DATETIME    NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return array<string, array<string, mixed>> rândurile din _platforma_migrari, după id (gol dacă tabelul lipsește) */
    private function randuri(): array
    {
        if (!$this->tabelExista(self::TABEL_MIGRARI)) {
            return [];
        }
        $randuri = [];
        foreach ($this->db->query('SELECT * FROM ' . self::TABEL_MIGRARI . ' ORDER BY ord, id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $randuri[(string) $r['id']] = $r;
        }
        return $randuri;
    }

    /** Cea mai mare ord până la care toate migrările din cod sunt aplicate sau detectate. */
    private function ultimaOrd(array $randuri): int
    {
        $ultima = 0;
        foreach ($this->registru->migrari() as $m) {
            $rand = $randuri[$m['id']] ?? null;
            if ($rand === null || !in_array($rand['rezultat'], self::TERMINATE, true)) {
                break;
            }
            $ultima = (int) $m['ord'];
        }
        return $ultima;
    }

    private function inregistreazaStarea(array $randuri, int $cod): void
    {
        $valori = [
            'ultima_ord' => (string) $this->ultimaOrd($randuri),
            'amprenta' => $this->registru->amprenta(),
            'server' => $this->server->text(),
            'ultima_rulare' => (string) json_encode([
                'la' => date('Y-m-d H:i:s'),
                'cod' => $cod,
                'cod_versiune' => $this->optiuni['cod_versiune'],
                'site' => $this->optiuni['site'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        foreach ($valori as $cheie => $valoare) {
            $this->scrieCheiaStarii($cheie, $valoare);
        }
        $this->actualizeazaMarcajul($randuri, true);
    }

    /**
     * `pasi_site`: lista pașilor așteptați = cei din cheia de până acum (același site) plus cei pe care rularea îi vede
     * în pachet, fără cei aplicați sau detectați. Nevidă → scrisă; goală → ștearsă (doar la final, $sterge), deci
     * cheia dispare abia când fiecare pas din ea e gata. Fără site (rularea fără --site) nu se atinge.
     */
    private function actualizeazaMarcajul(array $randuri, bool $sterge): void
    {
        $site = $this->optiuni['site'];
        if ($site === null) {
            return;
        }
        $marcaj = $this->citesteMarcajul();
        $ids = $marcaj !== null && $marcaj['site'] === $site ? ($marcaj['pasi'] ?? []) : [];
        foreach ($this->registru->pasiSite((string) $site, (string) $this->optiuni['dosar_site']) as $pas) {
            $ids[] = $pas['id'];
        }
        $asteptate = array_values(array_unique(array_filter($ids,
            static fn (string $id): bool => !in_array($randuri[$id]['rezultat'] ?? '', self::TERMINATE, true))));
        if ($asteptate !== []) {
            $this->scrieCheiaStarii(self::CHEIE_PASI_SITE, (string) json_encode(['site' => $site, 'pasi' => $asteptate],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($sterge && $marcaj !== null) {
            $this->db->prepare('DELETE FROM ' . self::TABEL_STARE . ' WHERE cheie = ?')->execute([self::CHEIE_PASI_SITE]);
        }
    }

    /** @return array{site: string, pasi: list<string>|null}|null `pasi_site` de pe această bază (null: nu există) */
    private function citesteMarcajul(): ?array
    {
        if (!$this->tabelExista(self::TABEL_STARE)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT valoare FROM ' . self::TABEL_STARE . ' WHERE cheie = ?');
        $stmt->execute([self::CHEIE_PASI_SITE]);
        $valoare = $stmt->fetchColumn();
        return $valoare === false ? null : self::marcaj((string) $valoare);
    }

    /**
     * Valoarea lui `pasi_site`, citită: {site, pasi}. Un text care nu e în forma aceasta (forma de dinainte: doar
     * numele site-ului) dă site-ul, cu lista neștiută (`pasi` = null).
     *
     * @return array{site: string, pasi: list<string>|null}
     */
    public static function marcaj(string $valoare): array
    {
        $date = json_decode($valoare, true);
        if (is_array($date) && is_string($date['site'] ?? null) && is_array($date['pasi'] ?? null)) {
            return ['site' => $date['site'], 'pasi' => array_values(array_map('strval', $date['pasi']))];
        }
        return ['site' => trim($valoare), 'pasi' => null];
    }

    /** --permite-provizoriu are efect doar pe copia de verificare (SITE_COPIE_VERIFICARE=1); altfel provizoriul rămâne codul 15. */
    private function provizoriuPermis(): bool
    {
        return (bool) $this->optiuni['permite_provizoriu'] && (bool) $this->optiuni['copie_verificare'];
    }

    private function scrieCheiaStarii(string $cheie, string $valoare): void
    {
        $stmt = $this->db->prepare('INSERT INTO ' . self::TABEL_STARE . ' (cheie, valoare, actualizata_la) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE valoare = ?, actualizata_la = NOW()');
        $stmt->execute([$cheie, $valoare, $valoare]);
    }

    private function inregistreazaStareaDacaSe(int $cod): void
    {
        try {
            if ($this->tabelExista(self::TABEL_STARE) && $this->tabelExista(self::TABEL_MIGRARI)) {
                $this->inregistreazaStarea($this->randuri(), $cod);
            }
        } catch (Throwable) {
            // Starea e doar informație; eroarea adevărată e cea raportată.
        }
    }

    /**
     * Tabelele `#sql-*` rămase de la un ALTER omorât la jumătate (pe serverele fără DDL atomic:
     * MariaDB < 10.6). Nu intră în amprentă; planul le arată ca să fie șterse de mână, după o
     * copie a bazei. Doar numele lor (information_schema.TABLES).
     *
     * @return list<string>
     */
    private function tabeleRamase(): array
    {
        $nume = $this->db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND (TABLE_NAME LIKE '#sql%' OR TABLE_NAME LIKE '#mysql50##sql%') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        return array_map(static fn (string $tabel): string => 'tabelul ' . $tabel . ' a rămas de la un ALTER întrerupt (nu intră în amprentă; '
            . 'se șterge de mână, după o copie a bazei)', array_map('strval', $nume));
    }

    // ------------------------------------------------------------- mărunțișuri

    private function tabelExista(string $tabel): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$tabel]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function timpScurs(): float
    {
        return microtime(true) - $this->start;
    }

    /** @return array<string, mixed> */
    private function raport(string $comanda): array
    {
        $pasi = $this->optiuni['site'] !== null ? count($this->registru->pasiSite((string) $this->optiuni['site'], (string) $this->optiuni['dosar_site'])) : 0;
        $mod = (string) $this->db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        return [
            'comanda' => $comanda,
            'server' => ['familie' => $this->server->familie(), 'versiune' => $this->server->versiune(), 'text' => $this->server->text()],
            'baza' => (string) $this->db->query('SELECT DATABASE()')->fetchColumn(),
            // Modul serverului (al sesiunii, la pornire) și cel cu care rulează migrările la --aplica: același, plus
            // STRICT_TRANS_TABLES dacă serverul nu e strict (vezi aplica()).
            'sql_mode' => $mod,
            'sql_mode_migrari' => self::modStrict($mod),
            'site' => $this->optiuni['site'],
            'registru' => [
                'migrari' => count($this->registru->migrari()),
                'ultima_ord' => $this->registru->ultimaOrd(),
                'pasi_site' => $pasi,
            ],
        ];
    }

    /** sql_mode-ul dat, cu STRICT_TRANS_TABLES adăugat dacă nu e deja strict (STRICT_TRANS_TABLES sau STRICT_ALL_TABLES). */
    public static function modStrict(string $mod): string
    {
        $parti = array_values(array_filter(array_map('trim', explode(',', $mod)), static fn (string $p): bool => $p !== ''));
        if (array_intersect(array_map('strtoupper', $parti), ['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES']) !== []) {
            return $mod;
        }
        return implode(',', array_merge(['STRICT_TRANS_TABLES'], $parti));
    }

    /** @param list<array<string, mixed>> $aplicate */
    private function rezumat(array $aplicate): string
    {
        $stari = array_count_values(array_column($aplicate, 'stare'));
        $parti = [];
        if (isset($stari['aplicata'])) {
            $parti[] = $stari['aplicata'] . ' ' . ($stari['aplicata'] === 1 ? 'aplicată' : 'aplicate');
        }
        if (isset($stari['detectata'])) {
            $parti[] = $stari['detectata'] . ' ' . ($stari['detectata'] === 1 ? 'detectată (exista deja)' : 'detectate (existau deja)');
        }
        return 'Gata: ' . implode(', ', $parti) . ', ' . array_sum(array_column($aplicate, 'operatii_acum')) . ' operații.';
    }
}
