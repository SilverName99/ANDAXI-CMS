<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use PDO;
use RuntimeException;
use Throwable;

/**
 * O automatizare și pașii ei (tabelele `automatizari`, `automatizari_pasi`, `automatizari_emailuri`; migrarea
 * automatizari/0078). În bază pașii sunt un graf: fiecare rând are pasul următor, iar „Dacă / altfel” are primul pas al
 * ramurii „Da” și al ramurii „Nu” (ramurile nu se mai unesc, ca în editor). Editorul lucrează cu arborele: o listă de pași,
 * în care ultimul poate fi un „Dacă / altfel” cu două liste (`da`, `nu`). `arbore()` și `randuriDinArbore()` trec dintr-o
 * formă în alta; la salvare arborele se verifică (tipuri, chei, adâncime, ramificarea doar la capăt).
 */
final class Flux
{
    public const STARI = ['ciorna' => 'Ciornă', 'activa' => 'Activă', 'oprita' => 'Oprită'];

    /** Tabelele există (o bază la zi cu migrarea automatizari/0078)? */
    public static function disponibil(PDO $db): bool
    {
        static $rezultat = [];
        $cheie = spl_object_id($db);
        if (isset($rezultat[$cheie])) {
            return $rezultat[$cheie];
        }
        try {
            foreach (['automatizari', 'automatizari_pasi', 'automatizari_emailuri', 'automatizari_rulari', 'automatizari_trimiteri',
                'automatizari_jurnal'] as $tabel) {
                $db->query('SELECT 1 FROM ' . $tabel . ' LIMIT 1');
            }
            return $rezultat[$cheie] = true;
        } catch (Throwable) {
            return $rezultat[$cheie] = false;
        }
    }

    /** @return array<string, mixed>|null rândul automatizării, cu setările decodate */
    public static function citeste(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM automatizari WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($rand) ? self::decodeaza($rand) : null;
    }

    private static function decodeaza(array $rand): array
    {
        $rand['id'] = (int) $rand['id'];
        $rand['permite_reintrarea'] = (int) ($rand['permite_reintrarea'] ?? 0) === 1;
        $setari = json_decode((string) ($rand['setari_json'] ?? ''), true);
        $rand['setari'] = Catalog::setariDeclansator((string) $rand['declansator'], is_array($setari) ? $setari : []);
        return $rand;
    }

    /** Automatizările active cu un anumit declanșator. @return list<array<string, mixed>> */
    public static function activeCu(PDO $db, string $declansator): array
    {
        $stmt = $db->prepare('SELECT * FROM automatizari WHERE stare = :s AND declansator = :d ORDER BY id');
        $stmt->execute(['s' => 'activa', 'd' => $declansator]);
        return array_map([self::class, 'decodeaza'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Toate automatizările, pentru listă, cu numărătorile. @return list<array<string, mixed>> */
    public static function lista(PDO $db): array
    {
        $randuri = array_map([self::class, 'decodeaza'], $db->query('SELECT * FROM automatizari ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $numere = [];
        foreach ($db->query(
            "SELECT automatizare_id,
                    COUNT(*) AS intrate,
                    SUM(stare IN ('activa', 'in_lucru')) AS in_procesare,
                    SUM(stare NOT IN ('activa', 'in_lucru')) AS iesite
             FROM automatizari_rulari GROUP BY automatizare_id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $numere[(int) $r['automatizare_id']] = $r;
        }
        $emailuri = [];
        foreach ($db->query(
            "SELECT automatizare_id, COUNT(*) AS trimise, SUM(deschis_la IS NOT NULL) AS deschise, SUM(clic_la IS NOT NULL) AS clicuri
             FROM automatizari_trimiteri WHERE stare = 'sent' AND abonat_id IS NOT NULL GROUP BY automatizare_id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $emailuri[(int) $r['automatizare_id']] = $r;
        }
        $pasi = [];
        foreach ($db->query('SELECT automatizare_id, COUNT(*) AS n FROM automatizari_pasi GROUP BY automatizare_id')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $pasi[(int) $r['automatizare_id']] = (int) $r['n'];
        }
        foreach ($randuri as &$rand) {
            $n = $numere[$rand['id']] ?? [];
            $e = $emailuri[$rand['id']] ?? [];
            $rand['numere'] = ['intrate' => (int) ($n['intrate'] ?? 0), 'in_procesare' => (int) ($n['in_procesare'] ?? 0),
                'iesite' => (int) ($n['iesite'] ?? 0), 'trimise' => (int) ($e['trimise'] ?? 0), 'deschise' => (int) ($e['deschise'] ?? 0),
                'clicuri' => (int) ($e['clicuri'] ?? 0)];
            $rand['nr_pasi'] = $pasi[$rand['id']] ?? 0;
        }
        unset($rand);
        return $randuri;
    }

    /** @return array<string, array<string, mixed>> pașii automatizării, după cheie */
    public static function pasi(PDO $db, int $id): array
    {
        $stmt = $db->prepare('SELECT cheie, tip, setari_json, urmator, da, nu, ordine FROM automatizari_pasi WHERE automatizare_id = :id ORDER BY ordine, id');
        $stmt->execute(['id' => $id]);
        $pasi = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $setari = json_decode((string) ($r['setari_json'] ?? ''), true);
            $tip = (string) $r['tip'];
            $pasi[(string) $r['cheie']] = [
                'cheie' => (string) $r['cheie'],
                'tip' => $tip,
                'setari' => Catalog::setariPas($tip, is_array($setari) ? $setari : []),
                'urmator' => $r['urmator'] !== null ? (string) $r['urmator'] : null,
                'da' => $r['da'] !== null ? (string) $r['da'] : null,
                'nu' => $r['nu'] !== null ? (string) $r['nu'] : null,
                'ordine' => (int) $r['ordine'],
            ];
        }
        return $pasi;
    }

    /** Primul pas al fluxului (cel cu ordinea cea mai mică), sau null pentru un flux fără pași. */
    public static function primul(array $pasi): ?string
    {
        $primul = null;
        foreach ($pasi as $cheie => $pas) {
            if ($primul === null || $pas['ordine'] < $pasi[$primul]['ordine']) {
                $primul = (string) $cheie;
            }
        }
        return $primul;
    }

    /**
     * Pașii ca arbore (forma editorului).
     *
     * @param array<string, array<string, mixed>> $pasi
     * @return list<array<string, mixed>>
     */
    public static function arbore(array $pasi): array
    {
        $vazute = [];
        $secventa = static function (?string $cheie, int $adancime) use (&$secventa, $pasi, &$vazute): array {
            $lista = [];
            while ($cheie !== null && isset($pasi[$cheie]) && !isset($vazute[$cheie]) && $adancime <= Catalog::MAX_ADANCIME) {
                $vazute[$cheie] = true;
                $pas = $pasi[$cheie];
                $nod = ['cheie' => $pas['cheie'], 'tip' => $pas['tip'], 'setari' => $pas['setari']];
                if ($pas['tip'] === 'daca') {
                    $nod['da'] = $secventa($pas['da'], $adancime + 1);
                    $nod['nu'] = $secventa($pas['nu'], $adancime + 1);
                    $lista[] = $nod;
                    break;
                }
                $lista[] = $nod;
                $cheie = $pas['urmator'];
            }
            return $lista;
        };
        return $secventa(self::primul($pasi), 0);
    }

    /**
     * Arborele din editor, verificat, ca rânduri pentru `automatizari_pasi`.
     *
     * @param list<mixed> $arbore
     * @return array{0: list<array<string, mixed>>, 1: list<string>} rândurile și erorile
     */
    public static function randuriDinArbore(array $arbore): array
    {
        $randuri = [];
        $erori = [];
        $chei = [];
        $ordine = 0;
        $adauga = static function (array $lista, int $adancime) use (&$adauga, &$randuri, &$erori, &$chei, &$ordine): ?string {
            if ($adancime > Catalog::MAX_ADANCIME) {
                $erori[] = 'Fluxul are prea multe ramuri una în alta (cel mult ' . Catalog::MAX_ADANCIME . ').';
                return null;
            }
            $primul = null;
            $anterior = null;
            $n = count($lista);
            foreach (array_values($lista) as $i => $nod) {
                if (!is_array($nod)) {
                    $erori[] = 'Un pas al fluxului nu e valid.';
                    continue;
                }
                $tip = (string) ($nod['tip'] ?? '');
                $cheie = (string) ($nod['cheie'] ?? '');
                if (!array_key_exists($tip, Catalog::PASI)) {
                    $erori[] = 'Tip de pas necunoscut.';
                    continue;
                }
                if (preg_match('/^[a-z0-9_]{1,20}$/', $cheie) !== 1 || isset($chei[$cheie])) {
                    $erori[] = 'Cheia unui pas lipsește sau se repetă.';
                    continue;
                }
                if ($tip === 'daca' && $i !== $n - 1) {
                    $erori[] = 'După „Dacă / altfel” fluxul continuă doar pe ramuri.';
                }
                if (count($randuri) >= Catalog::MAX_PASI) {
                    $erori[] = 'Fluxul are prea mulți pași (cel mult ' . Catalog::MAX_PASI . ').';
                    return $primul;
                }
                $chei[$cheie] = true;
                $index = count($randuri);
                $randuri[] = [
                    'cheie' => $cheie,
                    'tip' => $tip,
                    'setari' => Catalog::setariPas($tip, is_array($nod['setari'] ?? null) ? $nod['setari'] : []),
                    'urmator' => null, 'da' => null, 'nu' => null,
                    'ordine' => ++$ordine,
                ];
                if ($anterior !== null) {
                    $randuri[$anterior]['urmator'] = $cheie;
                }
                $primul ??= $cheie;
                $anterior = $index;
                if ($tip === 'daca') {
                    $randuri[$index]['da'] = $adauga(is_array($nod['da'] ?? null) ? $nod['da'] : [], $adancime + 1);
                    $randuri[$index]['nu'] = $adauga(is_array($nod['nu'] ?? null) ? $nod['nu'] : [], $adancime + 1);
                    break;
                }
            }
            return $primul;
        };
        $adauga($arbore, 0);
        return [$randuri, array_values(array_unique($erori))];
    }

    /** Ce lipsește ca fluxul să poată porni (gol = poate porni). @return list<string> */
    public static function problemePornire(PDO $db, array $automatizare, array $pasi): array
    {
        $probleme = [];
        if (!array_key_exists((string) $automatizare['declansator'], Catalog::declansatoareDisponibile())) {
            $probleme[] = 'Declanșatorul nu se poate folosi pe acest site.';
        }
        if ((string) $automatizare['declansator'] === 'eticheta_primita' && (int) ($automatizare['setari']['eticheta_id'] ?? 0) <= 0) {
            $probleme[] = 'Alege eticheta care pornește fluxul.';
        }
        if ($pasi === []) {
            $probleme[] = 'Fluxul nu are niciun pas.';
        }
        $emailuri = self::idEmailuri($db, (int) $automatizare['id']);
        foreach ($pasi as $pas) {
            $s = $pas['setari'];
            if ($pas['tip'] === 'email' && !in_array((int) ($s['email_id'] ?? 0), $emailuri, true)) {
                $probleme[] = 'Un pas „Trimite email” nu are emailul făcut.';
            }
            if (in_array($pas['tip'], ['eticheta_adauga', 'eticheta_scoate'], true) && (int) ($s['eticheta_id'] ?? 0) <= 0) {
                $probleme[] = 'Un pas cu etichetă nu are eticheta aleasă.';
            }
            if (in_array($pas['tip'], ['lista_adauga', 'lista_muta'], true) && (int) ($s['lista_id'] ?? 0) <= 0) {
                $probleme[] = 'Un pas cu listă nu are lista aleasă.';
            }
            if ($pas['tip'] === 'cod_reducere' && !\App\Support\Plugins::vizibil('cupoane')) {
                $probleme[] = 'Codul de reducere cere pluginul Cupoane.';
            }
        }
        return array_values(array_unique($probleme));
    }

    /** @return list<int> */
    public static function idEmailuri(PDO $db, int $automatizareId): array
    {
        $stmt = $db->prepare('SELECT id FROM automatizari_emailuri WHERE automatizare_id = :a');
        $stmt->execute(['a' => $automatizareId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array<string, mixed>|null */
    public static function email(PDO $db, int $emailId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM automatizari_emailuri WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $emailId]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($rand) ? $rand : null;
    }

    /** @return array<int, array<string, mixed>> emailurile automatizării, după id (fără HTML) */
    public static function emailuri(PDO $db, int $automatizareId): array
    {
        $stmt = $db->prepare('SELECT id, nume, subiect, blocks_json, updated_at FROM automatizari_emailuri WHERE automatizare_id = :a ORDER BY id');
        $stmt->execute(['a' => $automatizareId]);
        $lista = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $blocuri = json_decode((string) ($r['blocks_json'] ?? ''), true);
            $lista[(int) $r['id']] = ['id' => (int) $r['id'], 'nume' => (string) $r['nume'], 'subiect' => (string) $r['subiect'],
                'blocuri' => is_array($blocuri) ? count($blocuri) : 0, 'updated_at' => (string) ($r['updated_at'] ?? '')];
        }
        return $lista;
    }

    /** Un email nou al automatizării (blocurile builderului). */
    public static function creeazaEmail(PDO $db, int $automatizareId, string $nume, string $subiect, array $blocuri): int
    {
        $normalizate = \App\Support\NewsletterService::normalizeBlocks($blocuri);
        $db->prepare(
            'INSERT INTO automatizari_emailuri (automatizare_id, nume, subiect, blocks_json, html_content)
             VALUES (:a, :n, :s, :b, :h)'
        )->execute([
            'a' => $automatizareId,
            'n' => mb_substr($nume !== '' ? $nume : 'Email', 0, 190),
            's' => mb_substr($subiect, 0, 255),
            'b' => (string) json_encode($normalizate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'h' => \App\Support\NewsletterService::renderHtmlFromBlocks($normalizate, $subiect),
        ]);
        return (int) $db->lastInsertId();
    }

    /** Salvează conținutul unui email din builder. */
    public static function salveazaEmail(PDO $db, int $emailId, string $nume, string $subiect, array $blocuri): void
    {
        $normalizate = \App\Support\NewsletterService::normalizeBlocks($blocuri);
        $db->prepare(
            'UPDATE automatizari_emailuri SET nume = :n, subiect = :s, blocks_json = :b, html_content = :h WHERE id = :id'
        )->execute([
            'n' => mb_substr($nume !== '' ? $nume : 'Email', 0, 190),
            's' => mb_substr($subiect, 0, 255),
            'b' => (string) json_encode($normalizate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'h' => \App\Support\NewsletterService::renderHtmlFromBlocks($normalizate, $subiect),
            'id' => $emailId,
        ]);
    }

    /** O automatizare nouă (ciornă), cu pașii și emailurile date. Întoarce id-ul. */
    public static function creeaza(PDO $db, string $nume, string $declansator, array $setari, array $arbore, ?string $sablon = null,
        array $emailuri = []): int
    {
        if (!array_key_exists($declansator, Catalog::DECLANSATOARE)) {
            throw new RuntimeException('Declanșator necunoscut.');
        }
        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO automatizari (nume, stare, declansator, setari_json, permite_reintrarea, sablon)
                 VALUES (:n, :st, :d, :s, 0, :sab)'
            )->execute([
                'n' => mb_substr(trim($nume) !== '' ? trim($nume) : 'Automatizare nouă', 0, 190),
                'st' => 'ciorna',
                'd' => $declansator,
                's' => (string) json_encode(Catalog::setariDeclansator($declansator, $setari), JSON_UNESCAPED_UNICODE),
                'sab' => $sablon,
            ]);
            $id = (int) $db->lastInsertId();
            // Emailurile șablonului: cheia lor locală („e1”) devine id-ul adevărat în setările pașilor.
            $idEmail = [];
            foreach ($emailuri as $cheie => $email) {
                $idEmail[(string) $cheie] = self::creeazaEmail($db, $id, (string) $email['nume'], (string) $email['subiect'], (array) $email['blocuri']);
            }
            $arbore = self::legaEmailuri($arbore, $idEmail);
            [$randuri, $erori] = self::randuriDinArbore($arbore);
            if ($erori !== []) {
                throw new RuntimeException(implode(' ', $erori));
            }
            self::scriePasi($db, $id, $randuri);
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Pune id-urile emailurilor în pașii „Trimite email” care au `email` = cheia locală (șabloanele). */
    private static function legaEmailuri(array $arbore, array $idEmail): array
    {
        foreach ($arbore as &$nod) {
            if (($nod['tip'] ?? '') === 'email' && isset($nod['setari']['email']) && isset($idEmail[(string) $nod['setari']['email']])) {
                $nod['setari']['email_id'] = $idEmail[(string) $nod['setari']['email']];
                unset($nod['setari']['email']);
            }
            foreach (['da', 'nu'] as $ramura) {
                if (isset($nod[$ramura]) && is_array($nod[$ramura])) {
                    $nod[$ramura] = self::legaEmailuri($nod[$ramura], $idEmail);
                }
            }
        }
        unset($nod);
        return $arbore;
    }

    /** @param list<array<string, mixed>> $randuri */
    private static function scriePasi(PDO $db, int $id, array $randuri): void
    {
        $db->prepare('DELETE FROM automatizari_pasi WHERE automatizare_id = :id')->execute(['id' => $id]);
        $insert = $db->prepare(
            'INSERT INTO automatizari_pasi (automatizare_id, cheie, tip, setari_json, urmator, da, nu, ordine)
             VALUES (:a, :c, :t, :s, :u, :da, :nu, :o)'
        );
        foreach ($randuri as $r) {
            $insert->execute([
                'a' => $id, 'c' => $r['cheie'], 't' => $r['tip'],
                's' => (string) json_encode($r['setari'], JSON_UNESCAPED_UNICODE),
                'u' => $r['urmator'], 'da' => $r['da'], 'nu' => $r['nu'], 'o' => $r['ordine'],
            ]);
        }
    }

    /**
     * Salvarea din editor: numele, declanșatorul și setările lui, reintrarea, pașii. Un email care nu mai e folosit de niciun
     * pas rămâne (conținutul nu se pierde la o ștergere din greșeală; „Șterge” automatizarea le șterge pe toate). Rulările
     * care așteaptă la un pas șters ies din flux la următoarea trecere a cronului (motivul „pas_sters”).
     *
     * @return list<string> erorile (nimic salvat dacă nu e gol)
     */
    public static function salveaza(PDO $db, int $id, string $nume, string $declansator, array $setari, bool $permiteReintrarea, array $arbore): array
    {
        if (!array_key_exists($declansator, Catalog::DECLANSATOARE)) {
            return ['Declanșator necunoscut.'];
        }
        [$randuri, $erori] = self::randuriDinArbore($arbore);
        $emailuri = self::idEmailuri($db, $id);
        foreach ($randuri as $r) {
            if ($r['tip'] === 'email' && (int) ($r['setari']['email_id'] ?? 0) > 0 && !in_array((int) $r['setari']['email_id'], $emailuri, true)) {
                $erori[] = 'Un pas „Trimite email” arată spre un email al altei automatizări.';
            }
        }
        if ($erori !== []) {
            return array_values(array_unique($erori));
        }
        $db->beginTransaction();
        try {
            $db->prepare(
                'UPDATE automatizari SET nume = :n, declansator = :d, setari_json = :s, permite_reintrarea = :r WHERE id = :id'
            )->execute([
                'n' => mb_substr(trim($nume) !== '' ? trim($nume) : 'Automatizare fără nume', 0, 190),
                'd' => $declansator,
                's' => (string) json_encode(Catalog::setariDeclansator($declansator, $setari), JSON_UNESCAPED_UNICODE),
                'r' => $permiteReintrarea ? 1 : 0,
                'id' => $id,
            ]);
            self::scriePasi($db, $id, $randuri);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return [];
    }

    /** Pornește („activa”) sau oprește („oprita”) automatizarea. Pornirea cere un flux complet. @return list<string> */
    public static function schimbaStarea(PDO $db, int $id, string $stare): array
    {
        $automatizare = self::citeste($db, $id);
        if ($automatizare === null || !in_array($stare, ['activa', 'oprita'], true)) {
            return ['Automatizarea nu există.'];
        }
        if ($stare === 'activa') {
            $probleme = self::problemePornire($db, $automatizare, self::pasi($db, $id));
            if ($probleme !== []) {
                return $probleme;
            }
        }
        $db->prepare(
            'UPDATE automatizari SET stare = :s, pornita_la = CASE WHEN :s2 = \'activa\' AND pornita_la IS NULL THEN :acum ELSE pornita_la END WHERE id = :id'
        )->execute(['s' => $stare, 's2' => $stare, 'acum' => Ceas::sql(), 'id' => $id]);
        return [];
    }

    /** O copie (ciornă), cu emailurile copiate; fără rulări și fără numărători. */
    public static function duplica(PDO $db, int $id): int
    {
        $sursa = self::citeste($db, $id);
        if ($sursa === null) {
            throw new RuntimeException('Automatizarea nu există.');
        }
        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO automatizari (nume, stare, declansator, setari_json, permite_reintrarea, sablon)
                 VALUES (:n, :st, :d, :s, :r, :sab)'
            )->execute([
                'n' => mb_substr('Copie — ' . $sursa['nume'], 0, 190), 'st' => 'ciorna', 'd' => $sursa['declansator'],
                's' => (string) json_encode($sursa['setari'], JSON_UNESCAPED_UNICODE), 'r' => $sursa['permite_reintrarea'] ? 1 : 0,
                'sab' => $sursa['sablon'],
            ]);
            $nou = (int) $db->lastInsertId();
            $harta = [];
            $emailuri = $db->prepare('SELECT * FROM automatizari_emailuri WHERE automatizare_id = :a ORDER BY id');
            $emailuri->execute(['a' => $id]);
            $copie = $db->prepare(
                'INSERT INTO automatizari_emailuri (automatizare_id, nume, subiect, blocks_json, html_content) VALUES (:a, :n, :s, :b, :h)'
            );
            foreach ($emailuri->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
                $copie->execute(['a' => $nou, 'n' => $e['nume'], 's' => $e['subiect'], 'b' => $e['blocks_json'], 'h' => $e['html_content']]);
                $harta[(int) $e['id']] = (int) $db->lastInsertId();
            }
            $randuri = [];
            foreach (self::pasi($db, $id) as $pas) {
                if ($pas['tip'] === 'email') {
                    $pas['setari']['email_id'] = $harta[(int) ($pas['setari']['email_id'] ?? 0)] ?? 0;
                }
                $randuri[] = $pas;
            }
            self::scriePasi($db, $nou, $randuri);
            $db->commit();
            return $nou;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Șterge automatizarea cu tot ce ține de ea (pași, emailuri, rulări, trimiteri, jurnal). Codurile de reducere rămân. */
    public static function sterge(PDO $db, int $id): void
    {
        $db->beginTransaction();
        try {
            foreach (['automatizari_jurnal', 'automatizari_trimiteri', 'automatizari_rulari', 'automatizari_pasi', 'automatizari_emailuri'] as $tabel) {
                $db->prepare('DELETE FROM ' . $tabel . ' WHERE automatizare_id = :id')->execute(['id' => $id]);
            }
            $db->prepare('DELETE FROM automatizari WHERE id = :id')->execute(['id' => $id]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Numărătorile fiecărui pas (ca în MailPoet): câte rulări au ajuns la el („Intrate”), câte stau acum la el („În
     * procesare”), câte au trecut mai departe sau au ieșit („Ieșite”); la emailuri, și trimise / deschise / clicuri.
     *
     * @return array<string, array<string, int>>
     */
    public static function numerePasi(PDO $db, int $id): array
    {
        $numere = [];
        $intrate = $db->prepare("SELECT pas_cheie, COUNT(*) AS n FROM automatizari_jurnal WHERE automatizare_id = :a AND eveniment = 'pas' GROUP BY pas_cheie");
        $intrate->execute(['a' => $id]);
        foreach ($intrate->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $numere[(string) $r['pas_cheie']]['intrate'] = (int) $r['n'];
        }
        $acum = $db->prepare("SELECT pas_curent, COUNT(*) AS n FROM automatizari_rulari WHERE automatizare_id = :a AND stare IN ('activa', 'in_lucru') AND pas_curent IS NOT NULL GROUP BY pas_curent");
        $acum->execute(['a' => $id]);
        foreach ($acum->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $numere[(string) $r['pas_curent']]['in_procesare'] = (int) $r['n'];
        }
        $emailuri = $db->prepare("SELECT pas_cheie, COUNT(*) AS trimise, SUM(deschis_la IS NOT NULL) AS deschise, SUM(clic_la IS NOT NULL) AS clicuri
            FROM automatizari_trimiteri WHERE automatizare_id = :a AND stare = 'sent' GROUP BY pas_cheie");
        $emailuri->execute(['a' => $id]);
        foreach ($emailuri->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $numere[(string) $r['pas_cheie']] = ($numere[(string) $r['pas_cheie']] ?? []) + ['trimise' => (int) $r['trimise'],
                'deschise' => (int) $r['deschise'], 'clicuri' => (int) $r['clicuri']];
        }
        foreach ($numere as $cheie => $n) {
            $numere[$cheie] = ['intrate' => (int) ($n['intrate'] ?? 0), 'in_procesare' => (int) ($n['in_procesare'] ?? 0),
                'iesite' => max(0, (int) ($n['intrate'] ?? 0) - (int) ($n['in_procesare'] ?? 0)),
                'trimise' => (int) ($n['trimise'] ?? 0), 'deschise' => (int) ($n['deschise'] ?? 0), 'clicuri' => (int) ($n['clicuri'] ?? 0)];
        }
        return $numere;
    }

    /** Numărătorile automatizării (antetul editorului). @return array{intrate: int, in_procesare: int, iesite: int} */
    public static function numere(PDO $db, int $id): array
    {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS intrate, COALESCE(SUM(stare IN ('activa', 'in_lucru')), 0) AS in_procesare,
                    COALESCE(SUM(stare NOT IN ('activa', 'in_lucru')), 0) AS iesite
             FROM automatizari_rulari WHERE automatizare_id = :a"
        );
        $stmt->execute(['a' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['intrate' => (int) ($r['intrate'] ?? 0), 'in_procesare' => (int) ($r['in_procesare'] ?? 0), 'iesite' => (int) ($r['iesite'] ?? 0)];
    }
}
