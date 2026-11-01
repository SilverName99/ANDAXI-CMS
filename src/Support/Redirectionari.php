<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Managerul redirecționărilor (WP-38, SEO partea B, B2): harta adreselor vechi ale site-ului stă în baza de date
 * (`seo_redirects`, migrarea nucleu/0046), se schimbă din admin (Setări → Redirecționări și 404) și se aplică de
 * App\Support\SeoRedirects exact după regulile de până acum: doar unde aplicația ar răspunde 404/410 (sau ar ghici), un
 * singur salt, parametrii păstrați, fără `Set-Cookie`, niciodată pe căile excluse (coș, plată, cont, admin, API,
 * confirmările de plată, `rute_mereu_deschise`, `seo.json → cai_excluse`). `SEO_REDIRECTURI=0` oprește tot, ca înainte.
 *
 * **Pachetul rămâne sămânța.** Regulile din `redirecturi.json` al site-ului intră în tabel ca rânduri „pachet”, cum sunt
 * (aceleași ținte, aceleași coduri, și lanțurile lor, pe care harta le urmează într-un salt), la prima cerere după ce
 * fișierul s-a schimbat (semnătura lui: mărimea și momentul) și din admin („Reia regulile din pachet”). Un rând „pachet”
 * schimbat de om devine „manual” (pachetul nu-l mai atinge); unul
 * șters din admin rămâne oprit (pachetul nu-l readuce); o regulă scoasă din fișier dispare și din tabel.
 *
 * **Ce aplică SeoRedirects:** o copie a regulilor pornite în `storage/cache/redirecturi-<site>.json` (citită la fiecare
 * cerere înaintea sesiunii, fără bază), refăcută la fiecare schimbare (`reconstruieste`). Fără tabel (migrarea n-a rulat),
 * copia e făcută doar din pachet și se reverifică la 5 minute.
 *
 * **Reguli noi** (adăugate de om, din CSV, din jurnalul 404, la schimbarea unui slug): adresa veche doar o cale a
 * site-ului, niciodată una exclusă; ținta doar o cale a site-ului (o adresă întreagă doar pe gazda site-ului, scrisă
 * apoi ca o cale; niciodată alt site: fără redirecționare deschisă), nu una exclusă (admin, API, coș…); fără regulă spre
 * ea însăși, fără buclă și fără lanț: o țintă care e la rândul ei redirecționată se refuză (cu ținta finală în mesaj),
 * iar regulile care trimiteau la adresa veche a unei reguli noi trimit direct la ținta ei (un singur salt). Doar 301 și
 * 410.
 */
final class Redirectionari
{
    /** Sursele unui rând și cum le vede omul. */
    public const SURSE = [
        'pachet' => 'Din pachetul site-ului',
        'manual' => 'Adăugată de mână',
        'import' => 'Import CSV',
        'slug' => 'Automată (adresă schimbată)',
        '404' => 'Din jurnalul 404',
        'stergere' => 'La ștergerea unei pagini',
    ];

    public const CODURI = [301 => '301 — mutată definitiv', 410 => '410 — dispărută definitiv'];

    /** Cât ține copia făcută doar din pachet (tabelul lipsește) până se reverifică. */
    public const VALABIL_FARA_TABEL = 300;

    private const MAX_CALE = 500;

    /** Tabelul există (migrarea nucleu/0046 a rulat)? */
    public static function disponibil(?PDO $db): bool
    {
        if (!$db instanceof PDO) {
            return false;
        }
        try {
            $db->query('SELECT 1 FROM seo_redirects LIMIT 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Amprenta unui rând: cheia hărții (SeoRedirects::cheie) sau prefixul, după fel. */
    public static function amprenta(string $cheie, bool $prefix): string
    {
        return sha1(($prefix ? 'p:' : 'e:') . $cheie);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Validarea unei reguli noi
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Adresa veche, curățată: o cale a site-ului (o adresă întreagă pe gazda site-ului devine cale), decodată, cu litere
     * mici, fără „/” final (cheia hărții); la prefix, doar litere mici (prefixul se potrivește la început). Null = invalidă.
     */
    public static function curataDin(string $din, bool $prefix): ?string
    {
        $din = trim($din);
        $din = self::faraGazdaSite($din) ?? $din;
        if ($din === '' || $din[0] !== '/' || str_starts_with($din, '//') || str_contains($din, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $din) === 1 || mb_strlen($din) > self::MAX_CALE) {
            return null;
        }
        $din = explode('#', explode('?', $din, 2)[0], 2)[0];
        if ($prefix) {
            $cale = mb_strtolower(rawurldecode($din), 'UTF-8');
            return $cale === '/' || preg_match('/\s/u', $cale) === 1 ? null : $cale;
        }
        $cheie = SeoRedirects::cheie($din);
        return $cheie === '/' || preg_match('/\s/u', $cheie) === 1 ? null : $cheie;
    }

    /**
     * Ținta, curățată: o cale a site-ului cu parametrii ei („/x?y=1”); o adresă întreagă doar pe gazda site-ului (cu sau
     * fără www), scrisă ca o cale. Null = invalidă (alt site, „//gazdă”, „\”, spații, caractere de control, javascript:).
     */
    public static function curataSpre(string $spre): ?string
    {
        $spre = trim($spre);
        if ($spre === '') {
            return null;
        }
        $spre = self::faraGazdaSite($spre) ?? $spre;
        $spre = explode('#', $spre, 2)[0];
        if (!SeoRedirects::tintaValida($spre) || mb_strlen($spre) > self::MAX_CALE) {
            return null;
        }
        return $spre;
    }

    /**
     * O adresă întreagă pe gazda site-ului (APP_URL, cu sau fără www, http sau https) → calea ei cu parametrii; altă
     * adresă întreagă → '' (refuzată); o cale → null (neschimbată).
     */
    private static function faraGazdaSite(string $adresa): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $adresa) !== 1) {
            return null;
        }
        $parti = parse_url($adresa);
        $gazdaSite = strtolower((string) parse_url(AppUrl::base(), PHP_URL_HOST));
        if (!is_array($parti) || !in_array(strtolower((string) ($parti['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parti['user']) || isset($parti['pass']) || $gazdaSite === '') {
            return '';
        }
        $gazda = strtolower(rtrim((string) ($parti['host'] ?? ''), '.'));
        $faraWww = static fn (string $g): string => str_starts_with($g, 'www.') ? substr($g, 4) : $g;
        if ($faraWww($gazda) !== $faraWww($gazdaSite)) {
            return '';
        }
        $cale = (string) ($parti['path'] ?? '/');
        return ($cale === '' ? '/' : $cale) . (isset($parti['query']) ? '?' . $parti['query'] : '');
    }

    /**
     * Verifică o regulă nouă (sau schimbată: $idIgnorat = rândul ei). Întoarce ['din' => cheia, 'spre' => ținta sau null]
     * sau ['eroare' => motivul, pentru om].
     *
     * @return array{din?: string, spre?: ?string, eroare?: string}
     */
    public static function valideaza(PDO $db, string $din, string $spre, int $cod, bool $prefix = false, int $idIgnorat = 0): array
    {
        if (!isset(self::CODURI[$cod])) {
            return ['eroare' => 'Codul poate fi doar 301 (mutată) sau 410 (dispărută).'];
        }
        $cheie = self::curataDin($din, $prefix);
        if ($cheie === null) {
            return ['eroare' => 'Adresa veche trebuie să fie o cale a site-ului (de exemplu /blog/articol-vechi), nu prima pagină și nu alt site.'];
        }
        if (SeoRedirects::eExclusa($cheie) || SeoRedirects::eExclusa(rawurldecode($din))) {
            return ['eroare' => 'Adresa ' . $cheie . ' e a coșului, plății, contului, adminului, API-ului sau a unei confirmări: nu se redirecționează niciodată.'];
        }
        if ($cod === 410) {
            return ['din' => $cheie, 'spre' => null];
        }
        $tinta = self::curataSpre($spre);
        if ($tinta === null) {
            return ['eroare' => 'Ținta trebuie să fie o pagină a acestui site (de exemplu /blog/articol-nou). Adresele altor site-uri nu se acceptă.'];
        }
        $caleTinta = (string) explode('?', $tinta, 2)[0];
        if (SeoRedirects::eExclusa($caleTinta)) {
            return ['eroare' => 'Ținta ' . $caleTinta . ' e o adresă a coșului, plății, contului, adminului sau API-ului: alege o pagină publică.'];
        }
        $cheieTinta = SeoRedirects::cheie($tinta);
        if (!$prefix && $cheieTinta === $cheie) {
            return ['eroare' => 'Ținta e chiar adresa veche: regula ar trimite pagina la ea însăși.'];
        }
        if ($prefix && str_starts_with($cheieTinta, $cheie)) {
            return ['eroare' => 'Ținta începe cu prefixul regulii: ar trimite la nesfârșit în același loc.'];
        }
        // Lanț: ținta e la rândul ei redirecționată (o regulă pornită pe ea, exactă sau prefix).
        $regulaTinta = self::regulaPornita($db, $cheieTinta, $idIgnorat);
        if ($regulaTinta !== null && $regulaTinta['to_url'] !== null && !$prefix && SeoRedirects::cheie($regulaTinta['to_url']) === $cheie) {
            return ['eroare' => 'Ar face o buclă: ' . $cheieTinta . ' trimite deja la ' . $cheie . '.'];
        }
        if ($regulaTinta !== null) {
            $finala = $regulaTinta['code'] === 410 ? '410 (dispărută)' : (string) $regulaTinta['to_url'];
            return ['eroare' => 'Ținta ' . $cheieTinta . ' e la rândul ei redirecționată (spre ' . $finala . '): pune direct ținta finală, '
                . 'ca vizitatorul să ajungă dintr-un singur salt.'];
        }
        // Buclă: o regulă care trimite la adresa veche pornește chiar de la țintă (A → B există, B → A ar închide cercul).
        if (!$prefix) {
            foreach (self::careTrimitLa($db, $cheie, $idIgnorat) as $r) {
                if ((string) $r['from_path'] === $cheieTinta) {
                    return ['eroare' => 'Ar face o buclă: ' . $cheieTinta . ' trimite deja la ' . $cheie . '.'];
                }
            }
        }
        return ['din' => $cheie, 'spre' => $tinta];
    }

    /** Regula pornită care s-ar aplica unei căi (exactă, apoi prefix, ca harta), sau null. */
    private static function regulaPornita(PDO $db, string $cheie, int $idIgnorat = 0): ?array
    {
        $stmt = $db->prepare('SELECT id, from_path, to_url, code FROM seo_redirects WHERE from_hash = ? AND is_active = 1 AND id <> ? LIMIT 1');
        $stmt->execute([self::amprenta($cheie, false), $idIgnorat]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rand)) {
            $rand = null;
            $stmt = $db->prepare('SELECT id, from_path, to_url, code FROM seo_redirects WHERE is_prefix = 1 AND is_active = 1 AND id <> ? ORDER BY id');
            $stmt->execute([$idIgnorat]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                if (str_starts_with($cheie, (string) $r['from_path'])) {
                    $rand = $r;
                    break;
                }
            }
        }
        return is_array($rand) ? ['id' => (int) $rand['id'], 'from_path' => (string) $rand['from_path'],
            'to_url' => $rand['to_url'] === null ? null : (string) $rand['to_url'], 'code' => (int) $rand['code']] : null;
    }

    /**
     * Regulile exacte pornite care trimit la o cheie (ținta lor, fără parametri, are aceeași cheie: decodată, litere
     * mici, fără „/” final). Toate regulile 301 pornite, comparate aici (câteva sute pe un site).
     *
     * @return list<array<string, mixed>>
     */
    private static function careTrimitLa(PDO $db, string $cheie, int $idIgnorat = 0): array
    {
        $rez = [];
        $stmt = $db->prepare('SELECT id, from_path, to_url, source FROM seo_redirects WHERE is_active = 1 AND is_prefix = 0 AND code = 301 AND id <> ?');
        $stmt->execute([$idIgnorat]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if ($r['to_url'] !== null && SeoRedirects::cheie((string) $r['to_url']) === $cheie) {
                $rez[] = $r;
            }
        }
        return $rez;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Scrierea
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Adaugă (sau înlocuiește) regula unei adrese vechi. Regulile care trimiteau la adresa veche trimit apoi direct la
     * ținta ei (fără lanț). Întoarce ['ok' => bool, 'mesaj' => text pentru om, 'id' => rândul].
     *
     * @return array{ok: bool, mesaj: string, id?: int, aplatizate?: int}
     */
    public static function adauga(PDO $db, string $din, string $spre, int $cod, string $sursa = 'manual', string $nota = '', string $autor = '',
        bool $prefix = false, bool $refaCopia = true): array
    {
        if (!self::disponibil($db)) {
            return ['ok' => false, 'mesaj' => 'Tabelul redirecționărilor lipsește: rulează migrările (scripts/migreaza.php).'];
        }
        $sursa = isset(self::SURSE[$sursa]) ? $sursa : 'manual';
        $existent = self::dupaCheie($db, (string) (self::curataDin($din, $prefix) ?? ''), $prefix);
        $v = self::valideaza($db, $din, $spre, $cod, $prefix, (int) ($existent['id'] ?? 0));
        if (isset($v['eroare'])) {
            return ['ok' => false, 'mesaj' => $v['eroare']];
        }
        $cheie = (string) $v['din'];
        $tinta = $v['spre'] ?? null;
        $nota = mb_substr(trim($nota), 0, 255);
        $autor = mb_substr(trim($autor), 0, 190);
        try {
            $db->beginTransaction();
            if ($existent !== null) {
                $db->prepare('UPDATE seo_redirects SET to_url = ?, code = ?, is_active = 1, source = ?, note = ?, created_by = ? WHERE id = ?')
                    ->execute([$tinta, $cod, $sursa, $nota !== '' ? $nota : $existent['note'], $autor !== '' ? $autor : $existent['created_by'], $existent['id']]);
                $id = (int) $existent['id'];
            } else {
                $db->prepare('INSERT INTO seo_redirects (from_hash, from_path, is_prefix, to_url, code, is_active, source, note, created_by)
                    VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)')
                    ->execute([self::amprenta($cheie, $prefix), $cheie, $prefix ? 1 : 0, $tinta, $cod, $sursa, $nota !== '' ? $nota : null,
                        $autor !== '' ? $autor : null]);
                $id = (int) $db->lastInsertId();
            }
            // Fără lanț: cine trimitea la adresa veche trimite direct la ținta nouă (sau, la 410, rămâne cum e:
            // harta nu urmează un lanț spre 410, deci adresa aceea dă 404, ca înainte).
            $aplatizate = 0;
            if (!$prefix && $tinta !== null) {
                $muta = $db->prepare("UPDATE seo_redirects SET to_url = ?, source = CASE WHEN source = 'pachet' THEN 'manual' ELSE source END WHERE id = ?");
                foreach (self::careTrimitLa($db, $cheie, $id) as $r) {
                    $muta->execute([$tinta, (int) $r['id']]);
                    $aplatizate++;
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'mesaj' => 'Regula nu s-a putut salva (' . $e->getMessage() . ').'];
        }
        if ($refaCopia) {
            self::reconstruieste($db);
        }
        return ['ok' => true, 'id' => $id, 'aplatizate' => $aplatizate, 'mesaj' => ($cod === 410 ? $cheie . ' → 410 (dispărută)' : $cheie . ' → ' . $tinta)
            . ($aplatizate > 0 ? '; ' . $aplatizate . ' ' . ($aplatizate === 1 ? 'regulă care trimitea' : 'reguli care trimiteau') . ' la ' . $cheie
                . ' trimit acum direct la ' . $tinta : '') . '.'];
    }

    /** Pornește sau oprește o regulă (una oprită nu se aplică; o regulă din pachet rămâne oprită și după o reluare). */
    public static function schimbaStarea(PDO $db, int $id, bool $pornita): bool
    {
        $rand = self::dupaId($db, $id);
        if ($rand === null) {
            return false;
        }
        if ($pornita && (int) $rand['code'] === 301) {
            // O regulă pornită din nou trece prin aceleași verificări (lanț, buclă, ținta).
            $v = self::valideaza($db, (int) $rand['is_prefix'] === 1 ? (string) $rand['from_path'] : (string) $rand['from_path'],
                (string) $rand['to_url'], 301, (int) $rand['is_prefix'] === 1, $id);
            if (isset($v['eroare']) && (string) $rand['source'] !== 'pachet') {
                return false;
            }
        }
        $db->prepare('UPDATE seo_redirects SET is_active = ? WHERE id = ?')->execute([$pornita ? 1 : 0, $id]);
        self::reconstruieste($db);
        return true;
    }

    /** Șterge o regulă; una din pachet doar se oprește (altfel reluarea pachetului ar readuce-o). */
    public static function sterge(PDO $db, int $id): bool
    {
        $rand = self::dupaId($db, $id);
        if ($rand === null) {
            return false;
        }
        if ((string) $rand['source'] === 'pachet') {
            $db->prepare('UPDATE seo_redirects SET is_active = 0 WHERE id = ?')->execute([$id]);
        } else {
            $db->prepare('DELETE FROM seo_redirects WHERE id = ?')->execute([$id]);
        }
        self::reconstruieste($db);
        return true;
    }

    /**
     * La schimbarea adresei unei pagini (slug-ul unui produs, al unei categorii, pagini, articol): 301 de la adresa veche
     * la cea nouă (sursa „slug”), regulile care trimiteau la adresa veche trimit la cea nouă, iar o regulă pusă chiar pe
     * adresa nouă (acum o pagină) se scoate (o regulă din pachet doar se oprește). Nu face nimic pe căi excluse, fără
     * tabel sau când adresa nu s-a schimbat. Întoarce mesajul pentru om sau ''.
     */
    public static function laSchimbareaAdresei(?PDO $db, string $vechi, string $nou, string $autor = ''): string
    {
        if (!self::disponibil($db) || trim($vechi) === '' || trim($nou) === '') {
            return '';
        }
        $cheieVeche = self::curataDin($vechi, false);
        $cheieNoua = SeoRedirects::cheie($nou);
        if ($cheieVeche === null || $cheieVeche === $cheieNoua) {
            return '';
        }
        try {
            // Adresa nouă e acum o pagină: o regulă rămasă pe ea s-ar urma prin lanțuri, deci pleacă.
            foreach ([false, true] as $prefixPeNou) {
                $peNou = self::dupaCheie($db, $cheieNoua, $prefixPeNou);
                if ($peNou !== null && !$prefixPeNou) {
                    if ((string) $peNou['source'] === 'pachet') {
                        $db->prepare('UPDATE seo_redirects SET is_active = 0 WHERE id = ?')->execute([(int) $peNou['id']]);
                    } else {
                        $db->prepare('DELETE FROM seo_redirects WHERE id = ?')->execute([(int) $peNou['id']]);
                    }
                }
            }
        } catch (Throwable) {
            return '';
        }
        $rezultat = self::adauga($db, $vechi, $nou, 301, 'slug', 'adresa paginii s-a schimbat', $autor);
        return $rezultat['ok'] ? 'Adresa veche trimite acum la cea nouă (' . $rezultat['mesaj'] . ')' : '';
    }

    /**
     * Propunerea de după ștergerea unei pagini (în admin, deasupra listei): adresa veche și o țintă propusă (categoria
     * produsului, lista magazinului, blogul), sau 410. Se ține în sesiune până la următoarea pagină a adminului.
     */
    public static function propuneLaStergere(string $ce, string $caleVeche, string $propunere): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || trim($caleVeche) === '' || SeoRedirects::eExclusa($caleVeche)) {
            return;
        }
        $_SESSION['seo_redirect_propunere'] = ['ce' => $ce, 'din' => $caleVeche, 'spre' => $propunere];
    }

    /** @return array{ce: string, din: string, spre: string}|null propunerea (o singură dată) */
    public static function propunereDinSesiune(): ?array
    {
        $p = $_SESSION['seo_redirect_propunere'] ?? null;
        unset($_SESSION['seo_redirect_propunere']);
        return is_array($p) && isset($p['din'], $p['spre'], $p['ce']) ? ['ce' => (string) $p['ce'], 'din' => (string) $p['din'], 'spre' => (string) $p['spre']] : null;
    }

    /** O accesare a unei reguli (din SeoRedirects, după răspuns): numărul și momentul, fără să schimbe updated_at. */
    public static function numaraAccesare(?PDO $db, int $id): void
    {
        if (!$db instanceof PDO || $id <= 0) {
            return;
        }
        try {
            $db->prepare('UPDATE seo_redirects SET hits = hits + 1, last_hit_at = NOW(), updated_at = updated_at WHERE id = ?')->execute([$id]);
        } catch (Throwable) {
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Pachetul, CSV-ul
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Regulile din `redirecturi.json` al site-ului în tabel, ca rânduri „pachet”, exact cum sunt (aceleași filtre ca harta
     * de până acum: o țintă pe alt site se ignoră). Nu atinge rândurile schimbate de om (altă sursă) și nu pornește la loc
     * unul oprit; scoate rândurile „pachet” care nu mai sunt în fișier, doar dacă fișierul există și se citește.
     *
     * @return array{noi: int, schimbate: int, scoase: int, fisier: bool}
     */
    public static function importaPachet(PDO $db): array
    {
        $rez = ['noi' => 0, 'schimbate' => 0, 'scoase' => 0, 'fisier' => false];
        $fisier = DateSite::dosar() . '/redirecturi.json';
        $date = is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
        $citit = is_array($date);
        $rez['fisier'] = $citit;
        $reguli = [];
        foreach (self::reguliPachet(is_array($date) ? $date : []) as $r) {
            $reguli[self::amprenta($r['din'], $r['prefix'])] = $r;
        }
        $existente = [];
        foreach ($db->query("SELECT id, from_hash, to_url, code, source, note FROM seo_redirects")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $existente[(string) $r['from_hash']] = $r;
        }
        $pune = $db->prepare("INSERT IGNORE INTO seo_redirects (from_hash, from_path, is_prefix, to_url, code, is_active, source, note)
            VALUES (?, ?, ?, ?, ?, 1, 'pachet', ?)");
        $schimba = $db->prepare('UPDATE seo_redirects SET to_url = ?, code = ?, note = ? WHERE id = ?');
        foreach ($reguli as $hash => $r) {
            $e = $existente[$hash] ?? null;
            if ($e === null) {
                $pune->execute([$hash, $r['din'], $r['prefix'] ? 1 : 0, $r['spre'], $r['cod'], $r['nota'] !== '' ? mb_substr($r['nota'], 0, 255) : null]);
                $rez['noi'] += $pune->rowCount();
            } elseif ((string) $e['source'] === 'pachet' && ((string) $e['to_url'] !== (string) $r['spre'] || (int) $e['code'] !== $r['cod'])) {
                $schimba->execute([$r['spre'], $r['cod'], $r['nota'] !== '' ? mb_substr($r['nota'], 0, 255) : null, (int) $e['id']]);
                $rez['schimbate']++;
            }
        }
        if ($citit) {
            $scoate = $db->prepare('DELETE FROM seo_redirects WHERE id = ?');
            foreach ($existente as $hash => $e) {
                if ((string) $e['source'] === 'pachet' && !isset($reguli[$hash])) {
                    $scoate->execute([(int) $e['id']]);
                    $rez['scoase']++;
                }
            }
        }
        return $rez;
    }

    /**
     * Regulile pachetului, cu filtrele hărții de până acum (SeoRedirects): adresa veche o cale; la 301 ținta o cale
     * validă; un prefix nu e „/”.
     *
     * @return list<array{din: string, spre: ?string, cod: int, prefix: bool, nota: string}>
     */
    public static function reguliPachet(array $date): array
    {
        $rez = [];
        foreach (['exacte' => false, 'prefixe' => true] as $lista => $prefix) {
            foreach ((array) ($date[$lista] ?? []) as $rand) {
                if (!is_array($rand) || !isset($rand[0]) || !is_string($rand[0]) || !str_starts_with($rand[0], '/') || ($prefix && $rand[0] === '/')) {
                    continue;
                }
                $cod = (int) ($rand[2] ?? ($prefix ? 410 : 301));
                $tinta = isset($rand[1]) && is_string($rand[1]) && $rand[1] !== '' ? $rand[1] : null;
                if ($cod !== 410 && ($tinta === null || !SeoRedirects::tintaValida($tinta))) {
                    continue;
                }
                $rez[] = ['din' => $prefix ? strtolower($rand[0]) : SeoRedirects::cheie($rand[0]), 'spre' => $cod === 410 ? null : $tinta,
                    'cod' => $cod === 410 ? 410 : 301, 'prefix' => $prefix, 'nota' => is_string($rand[3] ?? null) ? (string) $rand[3] : ''];
            }
        }
        return $rez;
    }

    /**
     * Importul unui CSV: pe fiecare rând „adresa veche, ținta[, cod][, notă]” (separat prin virgulă, punct și virgulă
     * sau tab; un antet „din/adresa_veche…” se sare; ținta goală sau codul 410 = dispărută). Fiecare rând trece prin
     * aceleași verificări ca unul adăugat de mână; cele refuzate se spun, cu motivul.
     *
     * @return array{adaugate: int, refuzate: list<array{rand: int, text: string, motiv: string}>}
     */
    public static function importaCsv(PDO $db, string $csv, string $autor = ''): array
    {
        $rez = ['adaugate' => 0, 'refuzate' => []];
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $randuri = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        foreach ($randuri as $n => $linie) {
            if (trim($linie) === '' || str_starts_with(ltrim($linie), '#')) {
                continue;
            }
            $sep = str_contains($linie, "\t") ? "\t" : (substr_count($linie, ';') > substr_count($linie, ',') ? ';' : ',');
            $campuri = array_map('trim', str_getcsv($linie, $sep, '"', ''));
            $din = (string) ($campuri[0] ?? '');
            if ($n === 0 && preg_match('/^(din|de la|adresa|adresa[ _]veche|from|old|sursa)$/iu', $din) === 1) {
                continue;
            }
            $spre = (string) ($campuri[1] ?? '');
            $cod = (int) ($campuri[2] ?? 0);
            if ($cod === 0) {
                $cod = $spre === '' ? 410 : 301;
            }
            $r = self::adauga($db, $din, $spre, $cod, 'import', (string) ($campuri[3] ?? ''), $autor, false, false);
            if ($r['ok']) {
                $rez['adaugate']++;
            } else {
                $rez['refuzate'][] = ['rand' => $n + 1, 'text' => mb_substr($linie, 0, 200), 'motiv' => $r['mesaj']];
            }
        }
        self::reconstruieste($db);
        return $rez;
    }

    /** Toate regulile, ca CSV (adresa veche, ținta, cod, prefix, pornită, sursa, accesări, ultima accesare, nota). */
    public static function exportCsv(PDO $db): string
    {
        $f = fopen('php://temp', 'w+');
        fputcsv($f, ['adresa_veche', 'tinta', 'cod', 'prefix', 'pornita', 'sursa', 'accesari', 'ultima_accesare', 'nota'], ',', '"', '');
        foreach ($db->query('SELECT from_path, to_url, code, is_prefix, is_active, source, hits, last_hit_at, note FROM seo_redirects ORDER BY from_path')
            ->fetchAll(PDO::FETCH_NUM) ?: [] as $r) {
            fputcsv($f, array_map(static fn ($v): string => (string) $v, $r), ',', '"', '');
        }
        rewind($f);
        return (string) stream_get_contents($f);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Copia pentru SeoRedirects
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Reface copia hărții (`SeoRedirects::fisierCopie()`): pachetul sincronizat în tabel, apoi regulile pornite. Fără
     * tabel: doar regulile pachetului, valabile `VALABIL_FARA_TABEL` secunde. Scrisă atomic (fișier temporar + rename).
     */
    public static function reconstruieste(?PDO $db): bool
    {
        $exacte = [];
        $prefixe = [];
        $sursa = 'pachet';
        if (self::disponibil($db)) {
            try {
                self::importaPachet($db);
                foreach ($db->query('SELECT id, from_path, is_prefix, to_url, code FROM seo_redirects WHERE is_active = 1 ORDER BY is_prefix, id')
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $regula = [(int) $r['code'] === 410 ? null : (string) $r['to_url'], (int) $r['code'] === 410 ? 410 : 301, (int) $r['id']];
                    if ((int) $r['is_prefix'] === 1) {
                        $prefixe[] = [(string) $r['from_path'], $regula[0], $regula[1], $regula[2]];
                    } else {
                        $exacte[(string) $r['from_path']] = $regula;
                    }
                }
                $sursa = 'baza';
            } catch (Throwable) {
                $sursa = 'pachet';
            }
        }
        if ($sursa === 'pachet') {
            $exacte = [];
            $prefixe = [];
            $fisier = DateSite::dosar() . '/redirecturi.json';
            $date = is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
            foreach (self::reguliPachet(is_array($date) ? $date : []) as $r) {
                if ($r['prefix']) {
                    $prefixe[] = [$r['din'], $r['spre'], $r['cod'], 0];
                } else {
                    $exacte[$r['din']] = [$r['spre'], $r['cod'], 0];
                }
            }
        }
        return SeoRedirects::scrieCopia(['sursa' => $sursa, 'facuta' => time(), 'exacte' => $exacte, 'prefixe' => $prefixe]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Citirea, pentru admin
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    public static function dupaId(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM seo_redirects WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    /** @return array<string, mixed>|null */
    public static function dupaCheie(PDO $db, string $cheie, bool $prefix): ?array
    {
        if ($cheie === '') {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM seo_redirects WHERE from_hash = ?');
        $stmt->execute([self::amprenta($cheie, $prefix)]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    /**
     * Lanțurile și buclele din regulile pornite (de exemplu două reguli din pachet A → B, B → C): fiecare cu ce face harta
     * cu ele (un singur salt la ținta finală, sau 404 la buclă / lanț spre 410).
     *
     * @return list<array{din: string, spre: string, finala: ?string}>
     */
    public static function lanturi(PDO $db): array
    {
        $rez = [];
        $harta = [];
        foreach ($db->query('SELECT from_path, to_url, code FROM seo_redirects WHERE is_active = 1 AND is_prefix = 0')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $harta[(string) $r['from_path']] = [(int) $r['code'] === 410 ? null : (string) $r['to_url'], (int) $r['code']];
        }
        foreach ($harta as $din => [$spre, $cod]) {
            if ($cod !== 301 || $spre === null || !isset($harta[SeoRedirects::cheie($spre)])) {
                continue;
            }
            $vazute = [$din => true];
            $tinta = $spre;
            $finala = null;
            for ($pas = 0; $pas < 10; $pas++) {
                $k = SeoRedirects::cheie($tinta);
                if (isset($vazute[$k])) {
                    break;
                }
                $vazute[$k] = true;
                if (!isset($harta[$k])) {
                    $finala = $tinta;
                    break;
                }
                if ($harta[$k][0] === null) {
                    break;
                }
                $tinta = $harta[$k][0];
            }
            $rez[] = ['din' => $din, 'spre' => $spre, 'finala' => $finala];
        }
        return $rez;
    }
}
