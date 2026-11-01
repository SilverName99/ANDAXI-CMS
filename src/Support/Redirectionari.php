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
 * schimbat de om devine „manual” (pachetul nu-l mai atinge); unul oprit de om rămâne oprit (o piatră de mormânt: nici o
 * schimbare a pachetului, nici o întoarcere la un pachet vechi nu-l pornesc); o regulă pornită scoasă din fișier dispare și
 * din tabel, dar o sincronizare automată care ar scoate mai mult de o cincime din regulile pachetului se oprește (doar
 * „Reia regulile din pachet”, apăsat de admin, o face).
 *
 * **Ce aplică SeoRedirects:** o copie a regulilor pornite în `storage/cache/redirecturi-<site>.json` (citită la fiecare
 * cerere înaintea sesiunii, fără bază), refăcută la fiecare schimbare (`reconstruieste`), sub un lacăt (citirea bazei și
 * scrierea copiei împreună, deci o copie mai veche nu o poate acoperi pe una mai nouă) și cu o generație (crește la fiecare
 * schimbare; o copie cu o generație mai mică e veche). Fără tabel (migrarea n-a rulat), copia e făcută doar din pachet și
 * se reverifică la 5 minute. Dacă copia nu se poate scrie, cererea folosește harta refăcută din memorie, iar următoarele
 * încercări așteaptă (SeoRedirects::PAUZA_ESEC); pagina din admin arată problema.
 *
 * **Reguli noi** (adăugate de om, din CSV, din jurnalul 404, la schimbarea unui slug): adresa veche doar o cale a
 * site-ului, niciodată una exclusă (și niciun prefix care ar prinde una: `/c` ar prinde `/cos`); ținta doar o cale a
 * site-ului (o adresă întreagă doar pe gazda site-ului, scrisă apoi ca o cale; niciodată alt site: fără redirecționare
 * deschisă), nu una exclusă; fără segmentele `.` și `..` (nici codate, `%2e`) și fără caractere de control (nici codate,
 * `%00`); fără regulă spre ea însăși, fără buclă și fără lanț: o țintă care e la rândul ei redirecționată se refuză (cu
 * ținta finală în mesaj), iar regulile care trimiteau la adresa veche a unei reguli noi trimit direct la ținta ei (un
 * singur salt). Doar 301 și 410. Verificările se fac pe o imagine a regulilor din memorie (`index`), deci un import CSV mare
 * citește tabelul o dată și scrie totul într-o singură tranzacție.
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

    /** Sursele „scrise de om” (o regulă automată nu le înlocuiește). */
    private const SURSE_OM = ['manual', 'import', '404', 'stergere', 'pachet'];

    public const CODURI = [301 => '301 — mutată definitiv', 410 => '410 — dispărută definitiv'];

    /** Cât ține copia făcută doar din pachet (tabelul lipsește) până se reverifică. */
    public const VALABIL_FARA_TABEL = 300;

    /** Cel mult atât text CSV (fișier sau lipit). */
    public const MAX_CSV = 2 * 1024 * 1024;

    /** Peste această parte din regulile pornite ale pachetului, o sincronizare automată nu scoate nimic. */
    public const PRAG_SCOATERE_PACHET = 0.2;

    private const MAX_CALE = 500;
    private const MAX_PROPUNERI = 20;

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
    // Adresele
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Calea decodată de câte ori e nevoie (cel mult 3: „%252e” → „%2e” → „.”), ca verificările să vadă ce vede serverul. */
    private static function decodatCompet(string $cale): string
    {
        for ($i = 0; $i < 3; $i++) {
            $decodat = rawurldecode($cale);
            if ($decodat === $cale) {
                break;
            }
            $cale = $decodat;
        }
        return $cale;
    }

    /** O cale (fără parametri) bună pentru o regulă: fără `.`/`..`, fără caractere de control sau spații, nici după decodare. */
    private static function caleSigura(string $cale): bool
    {
        $decodata = self::decodatCompet($cale);
        if (preg_match('/[\x00-\x1f\x7f]/', $decodata) === 1 || preg_match('/\s/u', $decodata) === 1 || str_contains($decodata, '\\')) {
            return false;
        }
        foreach (explode('/', $decodata) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

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
        if (!self::caleSigura($din)) {
            return null;
        }
        if ($prefix) {
            $cale = mb_strtolower(rawurldecode($din), 'UTF-8');
            return $cale === '/' ? null : $cale;
        }
        $cheie = SeoRedirects::cheie($din);
        return $cheie === '/' ? null : $cheie;
    }

    /**
     * Ținta, curățată: o cale a site-ului cu parametrii ei („/x?y=1”); o adresă întreagă doar pe gazda site-ului (cu sau
     * fără www), scrisă ca o cale. Null = invalidă (alt site, „//gazdă”, „\”, spații, caractere de control, `.`/`..`,
     * javascript:).
     */
    public static function curataSpre(string $spre): ?string
    {
        $spre = trim($spre);
        if ($spre === '') {
            return null;
        }
        $spre = self::faraGazdaSite($spre) ?? $spre;
        $spre = explode('#', $spre, 2)[0];
        if (!SeoRedirects::tintaValida($spre) || mb_strlen($spre) > self::MAX_CALE || !self::caleSigura((string) explode('?', $spre, 2)[0])) {
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

    /** Un prefix care ar prinde o cale exclusă (`/c` prinde `/cos`) sau care e în interiorul uneia. */
    private static function prefixPesteExcluse(string $prefix): ?string
    {
        foreach (SeoRedirects::caiExcluse() as $exclusa) {
            $exclusa = strtolower((string) $exclusa);
            if (str_starts_with($exclusa, $prefix) || str_starts_with($prefix, rtrim($exclusa, '/'))) {
                return $exclusa;
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Imaginea regulilor din memorie
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Toate regulile, citite o dată: rândurile după id, cheile exacte și prefixele (cu sau fără stare), țintele regulilor
     * exacte 301 pornite (cheia țintei → id-urile lor), plus ce s-a schimbat (de scris).
     *
     * @return array{randuri: array<int, array<string, mixed>>, exacte: array<string, int>, prefixe: array<string, int>,
     *               spre: array<string, array<int, true>>, schimbate: array<int, true>, sterse: array<int, true>, noi: int}
     */
    private static function index(PDO $db): array
    {
        $idx = ['randuri' => [], 'exacte' => [], 'prefixe' => [], 'spre' => [], 'schimbate' => [], 'sterse' => [], 'noi' => 0];
        foreach ($db->query('SELECT id, from_path, is_prefix, to_url, code, is_active, source, note, created_by FROM seo_redirects')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $r['id'] = (int) $r['id'];
            $r['is_prefix'] = (int) $r['is_prefix'];
            $r['code'] = (int) $r['code'];
            $r['is_active'] = (int) $r['is_active'];
            self::puneInIndex($idx, $r);
        }
        return $idx;
    }

    private static function puneInIndex(array &$idx, array $r): void
    {
        $idx['randuri'][$r['id']] = $r;
        $idx[$r['is_prefix'] === 1 ? 'prefixe' : 'exacte'][(string) $r['from_path']] = $r['id'];
        if ($r['is_prefix'] === 0 && $r['is_active'] === 1 && $r['code'] === 301 && $r['to_url'] !== null) {
            $idx['spre'][SeoRedirects::cheie((string) $r['to_url'])][$r['id']] = true;
        }
    }

    private static function scoateDinIndex(array &$idx, int $id): void
    {
        $r = $idx['randuri'][$id] ?? null;
        if ($r === null) {
            return;
        }
        unset($idx['randuri'][$id], $idx[$r['is_prefix'] === 1 ? 'prefixe' : 'exacte'][(string) $r['from_path']]);
        if ($r['to_url'] !== null) {
            unset($idx['spre'][SeoRedirects::cheie((string) $r['to_url'])][$id]);
        }
    }

    /** Un rând schimbat în imagine (scos și pus la loc, ca hărțile ajutătoare să rămână corecte). */
    private static function schimbaInIndex(array &$idx, int $id, array $campuri): void
    {
        $r = array_merge($idx['randuri'][$id], $campuri);
        self::scoateDinIndex($idx, $id);
        self::puneInIndex($idx, $r);
        $idx['schimbate'][$id] = true;
    }

    /** Regula pornită care s-ar aplica unei căi (exactă, apoi prefix, ca harta), sau null. */
    private static function regulaPornitaIn(array $idx, string $cheie, int $idIgnorat = 0): ?array
    {
        $id = $idx['exacte'][$cheie] ?? null;
        if ($id !== null && $id !== $idIgnorat && $idx['randuri'][$id]['is_active'] === 1) {
            return $idx['randuri'][$id];
        }
        foreach ($idx['prefixe'] as $prefix => $idPrefix) {
            if ($idPrefix !== $idIgnorat && $idx['randuri'][$idPrefix]['is_active'] === 1 && str_starts_with($cheie, (string) $prefix)) {
                return $idx['randuri'][$idPrefix];
            }
        }
        return null;
    }

    /** @return list<int> regulile exacte 301 pornite care trimit la o cheie */
    private static function careTrimitLaIn(array $idx, string $cheie, int $idIgnorat = 0): array
    {
        return array_values(array_filter(array_keys($idx['spre'][$cheie] ?? []), static fn (int $id): bool => $id !== $idIgnorat));
    }

    /** Scrie ce s-a schimbat în imagine, într-o singură tranzacție (rândurile noi au id-uri negative în imagine). */
    private static function scrieIndex(PDO $db, array &$idx): void
    {
        $db->beginTransaction();
        try {
            $sterge = $db->prepare('DELETE FROM seo_redirects WHERE id = ?');
            foreach (array_keys($idx['sterse']) as $id) {
                if ($id > 0) {
                    $sterge->execute([$id]);
                }
            }
            $pune = $db->prepare('INSERT INTO seo_redirects (from_hash, from_path, is_prefix, to_url, code, is_active, source, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $schimba = $db->prepare('UPDATE seo_redirects SET to_url = ?, code = ?, is_active = ?, source = ?, note = ?, created_by = ? WHERE id = ?');
            foreach (array_keys($idx['schimbate']) as $id) {
                $r = $idx['randuri'][$id] ?? null;
                if ($r === null) {
                    continue;
                }
                if ($id < 0) {
                    $pune->execute([self::amprenta((string) $r['from_path'], $r['is_prefix'] === 1), $r['from_path'], $r['is_prefix'], $r['to_url'],
                        $r['code'], $r['is_active'], $r['source'], $r['note'], $r['created_by']]);
                } else {
                    $schimba->execute([$r['to_url'], $r['code'], $r['is_active'], $r['source'], $r['note'], $r['created_by'], $id]);
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $idx['schimbate'] = [];
        $idx['sterse'] = [];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Validarea unei reguli noi
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Verifică o regulă nouă (sau schimbată: $idIgnorat = rândul ei). Întoarce ['din' => cheia, 'spre' => ținta sau null]
     * sau ['eroare' => motivul, pentru om].
     *
     * @return array{din?: string, spre?: ?string, eroare?: string}
     */
    public static function valideaza(PDO $db, string $din, string $spre, int $cod, bool $prefix = false, int $idIgnorat = 0): array
    {
        return self::valideazaIn(self::index($db), $din, $spre, $cod, $prefix, $idIgnorat);
    }

    /** @return array{din?: string, spre?: ?string, eroare?: string} */
    private static function valideazaIn(array $idx, string $din, string $spre, int $cod, bool $prefix, int $idIgnorat): array
    {
        if (!isset(self::CODURI[$cod])) {
            return ['eroare' => 'Codul poate fi doar 301 (mutată) sau 410 (dispărută).'];
        }
        $cheie = self::curataDin($din, $prefix);
        if ($cheie === null) {
            return ['eroare' => 'Adresa veche trebuie să fie o cale a site-ului (de exemplu /blog/articol-vechi), nu prima pagină, nu alt site, '
                . 'fără „.”, „..” sau caractere de control.'];
        }
        if (SeoRedirects::eExclusa($cheie) || SeoRedirects::eExclusa(self::decodatCompet($din))) {
            return ['eroare' => 'Adresa ' . $cheie . ' e a coșului, plății, contului, adminului, API-ului sau a unei confirmări: nu se redirecționează niciodată.'];
        }
        if ($prefix && ($exclusa = self::prefixPesteExcluse($cheie)) !== null) {
            return ['eroare' => 'Prefixul ' . $cheie . ' ar prinde și ' . $exclusa . ' (coș, plată, cont, admin, API sau confirmări): scrie un prefix mai lung.'];
        }
        if ($cod === 410) {
            return ['din' => $cheie, 'spre' => null];
        }
        $tinta = self::curataSpre($spre);
        if ($tinta === null) {
            return ['eroare' => 'Ținta trebuie să fie o pagină a acestui site (de exemplu /blog/articol-nou). Adresele altor site-uri nu se acceptă.'];
        }
        $caleTinta = (string) explode('?', $tinta, 2)[0];
        if (SeoRedirects::eExclusa($caleTinta) || SeoRedirects::eExclusa(self::decodatCompet($caleTinta))) {
            return ['eroare' => 'Ținta ' . $caleTinta . ' e o adresă a coșului, plății, contului, adminului sau API-ului: alege o pagină publică.'];
        }
        $cheieTinta = SeoRedirects::cheie($tinta);
        if (!$prefix && $cheieTinta === $cheie) {
            return ['eroare' => 'Ținta e chiar adresa veche: regula ar trimite pagina la ea însăși.'];
        }
        if ($prefix && str_starts_with($cheieTinta, $cheie)) {
            return ['eroare' => 'Ținta începe cu prefixul regulii: ar trimite la nesfârșit în același loc.'];
        }
        $regulaTinta = self::regulaPornitaIn($idx, $cheieTinta, $idIgnorat);
        if ($regulaTinta !== null && $regulaTinta['to_url'] !== null && !$prefix && SeoRedirects::cheie((string) $regulaTinta['to_url']) === $cheie) {
            return ['eroare' => 'Ar face o buclă: ' . $cheieTinta . ' trimite deja la ' . $cheie . '.'];
        }
        if ($regulaTinta !== null) {
            $finala = $regulaTinta['code'] === 410 ? '410 (dispărută)' : (string) $regulaTinta['to_url'];
            return ['eroare' => 'Ținta ' . $cheieTinta . ' e la rândul ei redirecționată (spre ' . $finala . '): pune direct ținta finală, '
                . 'ca vizitatorul să ajungă dintr-un singur salt.'];
        }
        return ['din' => $cheie, 'spre' => $tinta];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Scrierea
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * O regulă verificată, pusă în imagine: o regulă existentă pe aceeași adresă se înlocuiește; cine trimitea la adresa
     * veche trimite apoi direct la ținta ei. Întoarce [id, câte reguli au fost aplatizate].
     *
     * @param array{din: string, spre: ?string} $v
     * @return array{0: int, 1: int}
     */
    private static function aplicaIn(array &$idx, array $v, int $cod, bool $prefix, string $sursa, string $nota, string $autor): array
    {
        $cheie = $v['din'];
        $tinta = $v['spre'];
        $existent = $idx[$prefix ? 'prefixe' : 'exacte'][$cheie] ?? null;
        $campuri = ['to_url' => $tinta, 'code' => $cod, 'is_active' => 1, 'source' => $sursa];
        if ($existent !== null) {
            $vechi = $idx['randuri'][$existent];
            $campuri['note'] = $nota !== '' ? $nota : $vechi['note'];
            $campuri['created_by'] = $autor !== '' ? $autor : $vechi['created_by'];
            self::schimbaInIndex($idx, $existent, $campuri);
            $id = $existent;
        } else {
            $id = --$idx['noi'];
            self::puneInIndex($idx, ['id' => $id, 'from_path' => $cheie, 'is_prefix' => $prefix ? 1 : 0, 'note' => $nota !== '' ? $nota : null,
                'created_by' => $autor !== '' ? $autor : null] + $campuri);
            $idx['schimbate'][$id] = true;
        }
        $aplatizate = 0;
        if (!$prefix && $tinta !== null) {
            foreach (self::careTrimitLaIn($idx, $cheie, $id) as $altul) {
                $sursaAltul = (string) $idx['randuri'][$altul]['source'];
                self::schimbaInIndex($idx, $altul, ['to_url' => $tinta, 'source' => $sursaAltul === 'pachet' ? 'manual' : $sursaAltul]);
                $aplatizate++;
            }
        }
        return [$id, $aplatizate];
    }

    /**
     * Adaugă (sau înlocuiește) regula unei adrese vechi. Regulile care trimiteau la adresa veche trimit apoi direct la
     * ținta ei (fără lanț). Întoarce ['ok' => bool, 'mesaj' => text pentru om, 'id' => rândul].
     *
     * @return array{ok: bool, mesaj: string, id?: int, aplatizate?: int}
     */
    public static function adauga(PDO $db, string $din, string $spre, int $cod, string $sursa = 'manual', string $nota = '', string $autor = '',
        bool $prefix = false): array
    {
        if (!self::disponibil($db)) {
            return ['ok' => false, 'mesaj' => 'Tabelul redirecționărilor lipsește: rulează migrările (scripts/migreaza.php).'];
        }
        $sursa = isset(self::SURSE[$sursa]) ? $sursa : 'manual';
        $idx = self::index($db);
        $cheieDin = (string) (self::curataDin($din, $prefix) ?? '');
        $existent = $idx[$prefix ? 'prefixe' : 'exacte'][$cheieDin] ?? 0;
        $v = self::valideazaIn($idx, $din, $spre, $cod, $prefix, (int) $existent);
        if (isset($v['eroare'])) {
            return ['ok' => false, 'mesaj' => $v['eroare']];
        }
        [$id, $aplatizate] = self::aplicaIn($idx, $v, $cod, $prefix, $sursa, mb_substr(trim($nota), 0, 255), mb_substr(trim($autor), 0, 190));
        try {
            self::scrieIndex($db, $idx);
        } catch (Throwable $e) {
            return ['ok' => false, 'mesaj' => 'Regula nu s-a putut salva (' . $e->getMessage() . ').'];
        } finally {
            self::reconstruieste($db);
        }
        $dupa = self::dupaCheie($db, (string) $v['din'], $prefix);
        $tinta = $v['spre'];
        $cheie = (string) $v['din'];
        return ['ok' => true, 'id' => (int) ($dupa['id'] ?? $id), 'aplatizate' => $aplatizate, 'mesaj' => ($cod === 410 ? $cheie . ' → 410 (dispărută)' : $cheie . ' → ' . $tinta)
            . ($aplatizate > 0 ? '; ' . $aplatizate . ' ' . ($aplatizate === 1 ? 'regulă care trimitea' : 'reguli care trimiteau') . ' la ' . $cheie
                . ' trimit acum direct la ' . $tinta : '') . '.'];
    }

    /** Pornește sau oprește o regulă (una oprită nu se aplică; o regulă din pachet rămâne oprită și după o reluare). */
    public static function schimbaStarea(PDO $db, int $id, bool $pornita): bool
    {
        $idx = self::index($db);
        $rand = $idx['randuri'][$id] ?? null;
        if ($rand === null) {
            return false;
        }
        if ($pornita && $rand['code'] === 301 && $rand['source'] !== 'pachet') {
            // O regulă pornită din nou trece prin aceleași verificări (lanț, buclă, ținta).
            $v = self::valideazaIn($idx, (string) $rand['from_path'], (string) $rand['to_url'], 301, $rand['is_prefix'] === 1, $id);
            if (isset($v['eroare'])) {
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
     * la cea nouă (sursa „slug”), regulile care trimiteau la adresa veche trimit la cea nouă, iar o regulă exactă pusă chiar
     * pe adresa nouă (acum o pagină) se scoate (o regulă din pachet doar se oprește). Totul se verifică întâi și se scrie
     * într-o singură tranzacție. Nu înlocuiește o regulă scrisă de om (sau din pachet) pe adresa veche și nu face nimic când
     * un prefix acoperă adresa nouă: în ambele cazuri spune de ce. Fără tabel, pe căi excluse sau fără schimbare: ''.
     *
     * @return array{ok: bool, mesaj: string}
     */
    public static function laSchimbareaAdresei(?PDO $db, string $vechi, string $nou, string $autor = ''): array
    {
        if (!self::disponibil($db) || trim($vechi) === '' || trim($nou) === '') {
            return ['ok' => false, 'mesaj' => ''];
        }
        $cheieVeche = self::curataDin($vechi, false);
        $cheieNoua = SeoRedirects::cheie($nou);
        if ($cheieVeche === null || $cheieVeche === $cheieNoua || SeoRedirects::eExclusa($cheieVeche)) {
            return ['ok' => false, 'mesaj' => ''];
        }
        try {
            $idx = self::index($db);
            $peVechi = $idx['exacte'][$cheieVeche] ?? null;
            if ($peVechi !== null && $idx['randuri'][$peVechi]['is_active'] === 1 && in_array((string) $idx['randuri'][$peVechi]['source'], self::SURSE_OM, true)) {
                $r = $idx['randuri'][$peVechi];
                return ['ok' => false, 'mesaj' => 'Adresa veche ' . $cheieVeche . ' avea deja o regulă (' . (self::SURSE[(string) $r['source']] ?? $r['source'])
                    . ', spre ' . ($r['code'] === 410 ? '410' : (string) $r['to_url']) . '): a rămas cum era. Schimb-o din Setări → Redirecționări și 404 dacă '
                    . 'trebuie să ducă la adresa nouă.'];
            }
            // Adresa nouă e acum o pagină: o regulă exactă rămasă pe ea s-ar urma prin lanțuri, deci pleacă (din pachet: oprită).
            $peNou = $idx['exacte'][$cheieNoua] ?? null;
            if ($peNou !== null) {
                if ((string) $idx['randuri'][$peNou]['source'] === 'pachet') {
                    self::schimbaInIndex($idx, $peNou, ['is_active' => 0]);
                } else {
                    self::scoateDinIndex($idx, $peNou);
                    $idx['sterse'][$peNou] = true;
                }
            }
            $prefixPeNou = self::regulaPornitaIn($idx, $cheieNoua);
            if ($prefixPeNou !== null && $prefixPeNou['is_prefix'] === 1) {
                return ['ok' => false, 'mesaj' => 'Adresa nouă ' . $cheieNoua . ' e acoperită de regula pentru tot ce începe cu ' . $prefixPeNou['from_path']
                    . ': redirecționarea automată de la ' . $cheieVeche . ' nu s-a făcut (ar fi un lanț). Schimbă sau oprește regula aceea din Setări → '
                    . 'Redirecționări și 404, apoi adaugă redirecționarea de mână.'];
            }
            $v = self::valideazaIn($idx, $vechi, $nou, 301, false, (int) ($peVechi ?? 0));
            if (isset($v['eroare'])) {
                return ['ok' => false, 'mesaj' => 'Redirecționarea automată de la ' . $cheieVeche . ' nu s-a făcut: ' . $v['eroare']];
            }
            [, $aplatizate] = self::aplicaIn($idx, $v, 301, false, 'slug', 'adresa paginii s-a schimbat', mb_substr(trim($autor), 0, 190));
            self::scrieIndex($db, $idx);
        } catch (Throwable) {
            return ['ok' => false, 'mesaj' => ''];
        } finally {
            self::reconstruieste($db);
        }
        return ['ok' => true, 'mesaj' => 'Adresa veche trimite acum la cea nouă (' . $cheieVeche . ' → ' . $v['spre']
            . ($aplatizate > 0 ? '; și ' . $aplatizate . ' ' . ($aplatizate === 1 ? 'adresă mai veche' : 'adrese mai vechi') : '') . ')'];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Propunerile de după o ștergere (pentru administratorul general, pe site)
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private static function fisierPropuneri(): string
    {
        return Paths::stocareSite('seo') . '/propuneri-redirectionare.json';
    }

    /**
     * Propunerea de după ștergerea unei pagini (adresa veche, o țintă propusă: categoria produsului, lista produselor,
     * blogul; sau 410). Se ține pe site (nu în sesiunea celui care a șters: un admin cu rol parțial n-are voie la
     * Redirecționări), cel mult 20, până o vede administratorul general.
     */
    public static function propuneLaStergere(string $ce, string $caleVeche, string $propunere): void
    {
        if (trim($caleVeche) === '' || SeoRedirects::eExclusa($caleVeche)) {
            return;
        }
        self::cuPropuneri(static function (array $lista) use ($ce, $caleVeche, $propunere): array {
            $lista = array_values(array_filter($lista, static fn (array $p): bool => ($p['din'] ?? '') !== $caleVeche));
            $lista[] = ['ce' => $ce, 'din' => $caleVeche, 'spre' => $propunere, 'la' => time()];
            return array_slice($lista, -self::MAX_PROPUNERI);
        });
    }

    /**
     * Prima propunere în așteptare, scoasă din listă: se cheamă doar acolo unde se și arată (cadrul adminului, pentru cine are
     * voie la Redirecționări).
     *
     * @return array{ce: string, din: string, spre: string}|null
     */
    public static function propunereDeAratat(): ?array
    {
        $gasita = null;
        self::cuPropuneri(static function (array $lista) use (&$gasita): array {
            $gasita = array_shift($lista);
            return $lista;
        });
        return is_array($gasita) && isset($gasita['din'], $gasita['spre'], $gasita['ce'])
            ? ['ce' => (string) $gasita['ce'], 'din' => (string) $gasita['din'], 'spre' => (string) $gasita['spre']] : null;
    }

    /** Câte propuneri așteaptă (fără să le scoată). */
    public static function propuneriInAsteptare(): int
    {
        $text = @file_get_contents(self::fisierPropuneri());
        $lista = is_string($text) ? json_decode($text, true) : null;
        return is_array($lista) ? count($lista) : 0;
    }

    /** @param callable(array): array $schimba */
    private static function cuPropuneri(callable $schimba): void
    {
        $fisier = self::fisierPropuneri();
        $f = @fopen($fisier, 'c+');
        if ($f === false) {
            return;
        }
        try {
            if (!flock($f, LOCK_EX)) {
                return;
            }
            $text = stream_get_contents($f);
            $lista = is_string($text) && $text !== '' ? json_decode($text, true) : [];
            $lista = $schimba(is_array($lista) ? array_values(array_filter($lista, 'is_array')) : []);
            ftruncate($f, 0);
            rewind($f);
            fwrite($f, (string) json_encode(array_values($lista), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($f);
            flock($f, LOCK_UN);
        } finally {
            fclose($f);
        }
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
     * de până acum: o țintă pe alt site se ignoră). Atinge doar rândurile „pachet”: nu schimbă un rând scris sau editat de
     * om și nu pornește la loc unul oprit (piatră de mormânt, rămâne și când regula iese din fișier). Scoate rândurile
     * „pachet” pornite care nu mai sunt în fișier, doar dacă fișierul se citește și, la o sincronizare automată, doar dacă
     * nu sunt mai mult de o cincime din cele pornite (atunci nu scoate nimic și spune; „Reia regulile din pachet”, $fortat,
     * le scoate).
     *
     * @return array{noi: int, schimbate: int, scoase: int, fisier: bool, nescoase: int}
     */
    public static function importaPachet(PDO $db, bool $fortat = false): array
    {
        $rez = ['noi' => 0, 'schimbate' => 0, 'scoase' => 0, 'fisier' => false, 'nescoase' => 0];
        $fisier = DateSite::dosar() . '/redirecturi.json';
        $date = is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
        $citit = is_array($date);
        $rez['fisier'] = $citit;
        $reguli = [];
        foreach (self::reguliPachet(is_array($date) ? $date : []) as $r) {
            $reguli[self::amprenta($r['din'], $r['prefix'])] = $r;
        }
        $existente = [];
        foreach ($db->query('SELECT id, from_hash, to_url, code, source, is_active FROM seo_redirects')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $existente[(string) $r['from_hash']] = $r;
        }
        $pune = $db->prepare("INSERT IGNORE INTO seo_redirects (from_hash, from_path, is_prefix, to_url, code, is_active, source, note)
            VALUES (?, ?, ?, ?, ?, 1, 'pachet', ?)");
        $schimba = $db->prepare("UPDATE seo_redirects SET to_url = ?, code = ?, note = ? WHERE id = ? AND source = 'pachet'");
        foreach ($reguli as $hash => $r) {
            $e = $existente[$hash] ?? null;
            if ($e === null) {
                $pune->execute([$hash, $r['din'], $r['prefix'] ? 1 : 0, $r['spre'], $r['cod'], $r['nota'] !== '' ? mb_substr($r['nota'], 0, 255) : null]);
                $rez['noi'] += $pune->rowCount();
            } elseif ((string) $e['source'] === 'pachet' && ((string) $e['to_url'] !== (string) $r['spre'] || (int) $e['code'] !== $r['cod'])) {
                $schimba->execute([$r['spre'], $r['cod'], $r['nota'] !== '' ? mb_substr($r['nota'], 0, 255) : null, (int) $e['id']]);
                $rez['schimbate'] += $schimba->rowCount();
            }
        }
        if ($citit) {
            $deScos = [];
            $pornitePachet = 0;
            foreach ($existente as $hash => $e) {
                if ((string) $e['source'] !== 'pachet' || (int) $e['is_active'] !== 1) {
                    continue;
                }
                $pornitePachet++;
                if (!isset($reguli[$hash])) {
                    $deScos[] = (int) $e['id'];
                }
            }
            if (!$fortat && count($deScos) > max(3, (int) floor($pornitePachet * self::PRAG_SCOATERE_PACHET))) {
                $rez['nescoase'] = count($deScos);
                Plugins::jurnalOdataPeZi('redirecturi-pachet-scoatere', 'Redirecționări: pachetul site-ului ar scoate ' . count($deScos) . ' din '
                    . $pornitePachet . ' reguli pornite; sincronizarea automată nu le-a scos (Setări → Redirecționări și 404 → Reia regulile din pachet).');
            } else {
                $scoate = $db->prepare("DELETE FROM seo_redirects WHERE id = ? AND source = 'pachet' AND is_active = 1");
                foreach ($deScos as $id) {
                    $scoate->execute([$id]);
                    $rez['scoase'] += $scoate->rowCount();
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
     * Importul unui CSV (cel mult MAX_CSV): pe fiecare rând „adresa veche, ținta[, cod][, notă]” (separat prin virgulă,
     * punct și virgulă sau tab; un antet „din/adresa_veche…” se sare; ținta goală sau codul 410 = dispărută). Tabelul se
     * citește o dată; fiecare rând trece prin aceleași verificări ca unul adăugat de mână, pe imaginea din memorie (cu
     * rândurile de mai sus deja puse), iar la final totul se scrie într-o singură tranzacție (fie toate, fie niciunul);
     * copia hărții se reface oricum.
     *
     * @return array{adaugate: int, refuzate: list<array{rand: int, text: string, motiv: string}>, eroare?: string}
     */
    public static function importaCsv(PDO $db, string $csv, string $autor = ''): array
    {
        $rez = ['adaugate' => 0, 'refuzate' => []];
        if (strlen($csv) > self::MAX_CSV) {
            return $rez + ['eroare' => 'Textul are peste 2 MB: împarte lista în mai multe importuri.'];
        }
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $randuri = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $autor = mb_substr(trim($autor), 0, 190);
        try {
            $idx = self::index($db);
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
                $existent = $idx['exacte'][(string) (self::curataDin($din, false) ?? '')] ?? 0;
                $v = self::valideazaIn($idx, $din, $spre, $cod, false, (int) $existent);
                if (isset($v['eroare'])) {
                    $rez['refuzate'][] = ['rand' => $n + 1, 'text' => mb_substr($linie, 0, 200), 'motiv' => $v['eroare']];
                    continue;
                }
                self::aplicaIn($idx, $v, $cod, false, 'import', mb_substr((string) ($campuri[3] ?? ''), 0, 255), $autor);
                $rez['adaugate']++;
            }
            self::scrieIndex($db, $idx);
        } catch (Throwable $e) {
            $rez['adaugate'] = 0;
            $rez['eroare'] = 'Importul nu s-a salvat (nimic scris): ' . $e->getMessage();
        } finally {
            self::reconstruieste($db);
        }
        return $rez;
    }

    /**
     * Toate regulile, ca CSV (adresa veche, ținta, cod, prefix, pornită, sursa, accesări, ultima accesare, nota). O celulă
     * care începe cu = + - @ (sau tab, CR) primește un apostrof în față, ca un program de calcul să n-o ia drept formulă.
     */
    public static function exportCsv(PDO $db): string
    {
        $f = fopen('php://temp', 'w+');
        $sigur = static fn ($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . (string) $v : (string) $v;
        fputcsv($f, ['adresa_veche', 'tinta', 'cod', 'prefix', 'pornita', 'sursa', 'accesari', 'ultima_accesare', 'nota'], ',', '"', '');
        foreach ($db->query('SELECT from_path, to_url, code, is_prefix, is_active, source, hits, last_hit_at, note FROM seo_redirects ORDER BY from_path')
            ->fetchAll(PDO::FETCH_NUM) ?: [] as $r) {
            fputcsv($f, array_map($sigur, $r), ',', '"', '');
        }
        rewind($f);
        return (string) stream_get_contents($f);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Copia pentru SeoRedirects
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Reface copia hărții (`SeoRedirects::fisierCopie()`): pachetul sincronizat în tabel, apoi regulile pornite. Totul sub
     * lacătul copiei (citirea bazei și scrierea copiei împreună: două refaceri nu se pot încrucișa, deci una care a citit
     * baza mai devreme nu scrie peste una mai nouă). $schimbare (implicit): o schimbare a regulilor, deci generația crește
     * și orice copie de dinainte e veche. Fără tabel: doar regulile pachetului, valabile `VALABIL_FARA_TABEL` secunde. Dacă
     * fișierul nu se poate scrie, harta rămâne în memorie pentru cererea de acum (SeoRedirects).
     */
    public static function reconstruieste(?PDO $db, bool $schimbare = true): bool
    {
        $lacat = SeoRedirects::lacat();
        try {
            $generatie = SeoRedirects::generatie() + ($schimbare ? 1 : 0);
            if ($schimbare) {
                SeoRedirects::scrieGeneratia($generatie);
            }
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
            return SeoRedirects::scrieCopia(['sursa' => $sursa, 'facuta' => time(), 'generatie' => $generatie, 'exacte' => $exacte, 'prefixe' => $prefixe]);
        } finally {
            SeoRedirects::elibereaza($lacat);
        }
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
