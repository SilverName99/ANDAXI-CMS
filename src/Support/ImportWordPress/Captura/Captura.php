<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

use App\Support\Paths;

/**
 * Captura site-ului WordPress live (adoptarea, pasul 4a; DECIZIE §2.3 #1–3, C2 §4a), fără browser, cum o face importatorul pe
 * server: fiecare adresă din ListaAdrese se cere prin Cereri (doar GET, ritm, buget, reluare la 429, fără trackere), pe șase
 * UA × 2 pentru documentele HTML; răspunsurile se normalizează (Normalizare, lista închisă) și se compară: orice altă diferență
 * între UA-uri sau între cereri înseamnă NU, cu diferența arătată. Apoi resursele paginilor (Resurse) intră în manifest, cu
 * sha256 de pe disc (`--wp`, pe server) sau din dosarul unei oglinzi (`--resurse-din`, local).
 *
 * Ce scrie, doar în dosarul de ieșire (implicit `site/capturat/`, mereu în afara repo-ului: un dosar din repo e refuzat):
 *  - `brut/<sha256 adresei>/<ua>-<n>.corp` și `.antete` (octeții exacți ai fiecărui răspuns), `index.json` pe adresă;
 *  - `index.json`: fiecare adresă cu felul, statusul, răspunsul de referință (primul, Chrome Windows), sha256 brut și normalizat;
 *  - `manifest.json`: paginile (cu sha256) și fișierele cerute de ele (adresa, calea, sha256, tipul, mărimea, sursa), plus
 *    gazdele externe (pentru revizia omului) și ce lipsește;
 *  - `raport-captura.json`: cererile (jurnalul), problemele (NU), avertismentele, sha256-ul indexului și al manifestului.
 */
final class Captura
{
    /** UA-urile și repetările pe fel (DECIZIE §2.3 #2: 6 UA × 2). `--raspunsuri-ua=2`: răspunsurile înghețate pe 2 UA × 2. */
    public const REPETARI = 2;

    /** Jurnalul cererilor capturii și al celor ale integrității, în dosarul de ieșire (Cereri: doar adăugat, fiecare cerere pe loc). */
    public const JURNAL = 'jurnal-cereri.jsonl';
    public const JURNAL_INTEGRITATE = 'jurnal-integritate.jsonl';

    /** Fișierele dosarului de ieșire acoperite de sha256-urile raportului (lanțul verificat de DosarCapturi::deschideVerificat). */
    public const FISIERE_LANT = ['index.json', 'manifest.json', self::JURNAL, 'integritate.json', self::JURNAL_INTEGRITATE];

    /** UA-urile răspunsurilor înghețate când bugetul e mic (`--raspunsuri-ua=2`): un desktop și un telefon. */
    public const UA_RASPUNSURI_PUTINE = ['chrome-win', 'safari-iphone'];

    /** @var list<string> */
    public array $probleme = [];
    /** @var list<string> */
    public array $avertismente = [];
    /** @var list<string> */
    public array $info = [];
    /** @var list<array<string, mixed>> */
    public array $adrese = [];
    /** @var array<string, array{url: string, fel: string, pagini: list<string>}> */
    public array $resurse = [];
    /** @var array<string, mixed>|null ultimul manifest scris (manifest()), completat de inregistreazaCopia() */
    private ?array $manifest = null;

    /** Dosarul de ieșire, calea reală (verificaIesirea): toate scrierile o folosesc pe ea, nu forma dată. */
    public readonly string $iesire;

    public function __construct(
        public readonly string $url,
        string $iesire,
        public readonly Normalizare $normalizare,
    ) {
        $this->iesire = self::verificaIesirea($iesire);
    }

    /**
     * Dosarul de ieșire nu poate fi în repo (capturile sunt ale clientului: DECIZIE §3). Întoarce calea reală, pe care se scrie
     * apoi (așa verificarea și scrierea văd același loc, chiar dacă forma dată are „..” după o legătură simbolică).
     */
    public static function verificaIesirea(string $iesire): string
    {
        $radacina = rtrim(realpath(Paths::radacina()) ?: Paths::radacina(), '/');
        $tinta = self::caleReala($iesire);
        if ($tinta === $radacina || str_starts_with($tinta . '/', $radacina . '/')) {
            throw new EroareCaptura('scriere', 'captura nu se scrie în repo (' . $tinta . '): dă un --iesire= în afara lui (implicit site/capturat/)');
        }
        return $tinta;
    }

    /**
     * Calea absolută cum o vede sistemul, chiar dacă o parte nu există încă: fiecare parte existentă se înlocuiește cu realpath-ul
     * ei ÎNAINTE de a aplica „..” care urmează (ca nucleul: `<legătură>/..` e părintele țintei legăturii, nu al legăturii); după
     * prima parte care lipsește, restul se aplică pe text. O legătură simbolică fără țintă pe drum e refuzată.
     */
    public static function caleReala(string $cale): string
    {
        if ($cale === '' || str_contains($cale, "\0")) {
            throw new EroareCaptura('folosire', 'cale goală sau cu octetul 0');
        }
        $cale = str_starts_with($cale, '/') ? $cale : (getcwd() ?: '/') . '/' . $cale;
        $curent = '/';
        $rest = [];
        foreach (explode('/', $cale) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($rest !== []) {
                if ($p === '..') {
                    array_pop($rest);
                } else {
                    $rest[] = $p;
                }
                continue;
            }
            if ($p === '..') {
                $curent = dirname($curent);
                continue;
            }
            $incercare = rtrim($curent, '/') . '/' . $p;
            $r = realpath($incercare);
            if ($r !== false) {
                $curent = $r;
            } elseif (is_link($incercare)) {
                throw new EroareCaptura('scriere', 'legătură simbolică fără țintă pe drum: ' . $incercare);
            } else {
                $rest[] = $p;
            }
        }
        return rtrim($curent, '/') . ($rest !== [] ? '/' . implode('/', $rest) : '') ?: '/';
    }

    /**
     * UA-urile cerute pentru un fel de adresă.
     *
     * @return list<string>
     */
    public static function ua(string $fel, int $uaRaspunsuri = 6): array
    {
        $toate = array_keys(Cereri::UA);
        if (in_array($fel, ListaAdrese::HTML, true) || $uaRaspunsuri >= 6) {
            return $toate;
        }
        return array_slice(self::UA_RASPUNSURI_PUTINE, 0, max(1, $uaRaspunsuri));
    }

    /**
     * Planul cererilor: câte pleacă pentru fiecare adresă (fără reluări) și în total.
     *
     * @param list<array{fel: string, cale: string, url: string}> $adrese
     * @return array{cereri: int, pe_fel: array<string, int>}
     */
    public static function plan(array $adrese, int $uaRaspunsuri = 6): array
    {
        $total = 0;
        $peFel = [];
        foreach ($adrese as $a) {
            $n = count(self::ua($a['fel'], $uaRaspunsuri)) * self::REPETARI;
            $total += $n;
            $peFel[$a['fel']] = ($peFel[$a['fel']] ?? 0) + $n;
        }
        return ['cereri' => $total, 'pe_fel' => $peFel];
    }

    /**
     * Captura prin HTTP. `$scrie(string $fel, string $text)` arată progresul (NU / ATENȚIE / ..). Se oprește politicos la buget
     * sau la limitarea gazdei (ce s-a luat rămâne scris, adresele rămase sunt NU „necapturată”).
     *
     * @param list<array{fel: string, cale: string, url: string}> $adrese
     */
    public function prinHttp(array $adrese, Cereri $cereri, int $uaRaspunsuri, callable $scrie, bool $descopera = true): void
    {
        $this->pregatesteIesirea();
        $coada = $adrese;
        $vazute = array_fill_keys(array_column($adrese, 'url'), true);
        $oprit = null;
        for ($i = 0; $i < count($coada); $i++) {
            $a = $coada[$i];
            if ($oprit !== null) {
                $this->probleme[] = $a['url'] . ': necapturată (' . $oprit . ')';
                continue;
            }
            $raspunsuri = [];
            try {
                foreach (self::ua($a['fel'], $uaRaspunsuri) as $ua) {
                    for ($n = 1; $n <= self::REPETARI; $n++) {
                        $raspunsuri[] = [$ua, $n, $cereri->get($a['url'], $ua, $this->accept($a['fel']))];
                    }
                }
            } catch (EroareCaptura $e) {
                if (in_array($e->fel, ['buget', 'limitare'], true)) {
                    $oprit = $e->getMessage();
                    $this->probleme[] = $a['url'] . ': necapturată (' . $oprit . ')';
                    $scrie('NU', $e->getMessage());
                    continue;
                }
                $this->probleme[] = $a['url'] . ': ' . $e->getMessage();
                $scrie('NU', $a['url'] . ': ' . $e->getMessage());
                continue;
            }
            $intrare = $this->pastreaza($a, $raspunsuri);
            $scrie($intrare['identice'] ? '  ..' : 'NU', sprintf('%-7s %s → %d, %d cereri, %s', $a['fel'], $a['cale'], $intrare['status'], count($raspunsuri),
                $intrare['identice'] ? 'identice după normalizare' : count($intrare['diferente']) . ' diferențe'));
            if ($descopera && $a['fel'] === 'raspuns' && preg_match('#/sitemap(_index)?\.xml$#', $a['cale']) === 1 && $intrare['status'] === 200) {
                foreach ($this->dinSitemap((string) file_get_contents($this->iesire . '/' . $intrare['referinta'])) as $cale) {
                    $url = rtrim($this->url, '/') . $cale;
                    if (!isset($vazute[$url])) {
                        $vazute[$url] = true;
                        $coada[] = ['fel' => 'raspuns', 'cale' => $cale, 'url' => $url];
                        $this->info[] = 'găsit în ' . $a['cale'] . ': ' . $cale;
                    }
                }
            }
        }
        $this->scrieRaport($cereri);
    }

    /**
     * Captura dintr-un dosar (capturi urcate sau o oglindă): fără rețea și fără comparația pe UA (o singură variantă pe adresă).
     *
     * @param list<array{fel: string, cale: string, url: string}> $adrese
     */
    public function dinDosar(array $adrese, DosarCapturi $dosar, callable $scrie): void
    {
        $this->pregatesteIesirea();
        $this->info[] = 'comparația pe UA: sărită (din dosar: o singură variantă pe adresă; se face doar pe o captură proaspătă)';
        foreach ($adrese as $a) {
            $r = $dosar->raspuns($a['url']);
            if ($r === null) {
                $this->probleme[] = $a['url'] . ': lipsește din dosarul ' . $dosar->dosar;
                $scrie('NU', $a['url'] . ': lipsește din dosar');
                continue;
            }
            $intrare = $this->pastreaza($a, [['din-dosar', 1, $r]]);
            $scrie('  ..', sprintf('%-7s %s → %d (din dosar)', $a['fel'], $a['cale'], $intrare['status']));
        }
        $this->scrieRaport(null);
    }

    /** @return list<string> căile proprii din sitemap: sub-sitemap-urile și foaia XSL */
    private function dinSitemap(string $xml): array
    {
        $rez = [];
        $gazda = strtolower((string) parse_url($this->url, PHP_URL_HOST));
        if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#', $xml, $m) > 0) {
            foreach ($m[1] as $u) {
                $u = html_entity_decode($u, ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (strtolower((string) parse_url($u, PHP_URL_HOST)) === $gazda && preg_match('#\.xml$#', (string) parse_url($u, PHP_URL_PATH)) === 1) {
                    $rez[] = (string) parse_url($u, PHP_URL_PATH);
                }
            }
        }
        if (preg_match('#<\?xml-stylesheet[^>]*href="([^"]+)"#', $xml, $m) === 1) {
            $u = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (strtolower((string) parse_url($u, PHP_URL_HOST)) === $gazda) {
                $rez[] = (string) parse_url($u, PHP_URL_PATH) . (parse_url($u, PHP_URL_QUERY) !== null ? '?' . parse_url($u, PHP_URL_QUERY) : '');
            }
        }
        return array_values(array_unique($rez));
    }

    private function accept(string $fel): string
    {
        return in_array($fel, ListaAdrese::DOCUMENTE, true) ? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' : '*/*';
    }

    /**
     * Scrie răspunsurile unei adrese în `brut/`, le compară după normalizare și ține intrarea în index.
     *
     * @param array{fel: string, cale: string, url: string} $a
     * @param list<array{0: string, 1: int, 2: Raspuns}> $raspunsuri
     * @return array<string, mixed>
     */
    private function pastreaza(array $a, array $raspunsuri): array
    {
        $dosar = 'brut/' . hash('sha256', $a['url']);
        self::dosar($this->iesire . '/' . $dosar);
        $html = in_array($a['fel'], ListaAdrese::DOCUMENTE, true);
        $cuResurse = in_array($a['fel'], ListaAdrese::HTML, true);
        $lista = [];
        $normalizate = [];
        $jetoane = [];
        foreach ($raspunsuri as [$ua, $n, $r]) {
            $nume = $ua . '-' . $n;
            // Corpul păstrat e cel decodat (ca în browser); antetele de fir (codarea, lungimea, legătura) nu se păstrează lângă el:
            // ar descrie alți octeți. Pasul 9a le recalculează; index.json le ține, ca rânduri scoase.
            $antete = $r->anteteDePastrat();
            self::scrie($this->iesire . '/' . $dosar . '/' . $nume . '.corp', $r->corp);
            self::scrie($this->iesire . '/' . $dosar . '/' . $nume . '.antete', $antete['bloc']);
            $norm = $html && ($r->tip() === 'text/html' || ($r->tip() === '' && preg_match('/^\s*<!doctype html/i', $r->corp) === 1))
                ? $this->normalizare->document($r->corp) : $this->normalizare->raspuns($r->corp, (string) $a['fel'], (string) $a['cale']);
            if (($j = Normalizare::jeton($r->corp)) !== null) {
                $jetoane[] = $nume . ' la octetul ' . $j;
            }
            $normalizate[$nume] = $norm;
            $lista[] = ['ua' => $ua, 'n' => $n, 'status' => $r->status, 'tip' => $r->tip(), 'location' => $r->antet('location'),
                'codare' => $r->codare, 'corp' => 'decodat', 'antete_scoase' => $antete['scoase'], 'sha256' => $r->sha256(),
                'sha256_antete' => hash('sha256', $antete['bloc']), 'sha256_normalizat' => hash('sha256', $norm), 'marime' => strlen($r->corp),
                'incercari' => $r->incercari, 'fisier' => $dosar . '/' . $nume . '.corp'];
        }
        $ref = $lista[0];
        $diferente = [];
        // Un UA ale cărui două cereri diferă are o variație la fiecare cerere; o diferență față de alt UA e „după UA” doar dacă
        // ambele UA-uri dau, fiecare, aceiași octeți la ambele cereri (altfel nu se poate pune pe seama UA-ului).
        $stabil = [];
        foreach ($lista as $x) {
            $stabil[$x['ua']] = ($stabil[$x['ua']] ?? true) && $x['sha256_normalizat'] === $lista[array_search($x['ua'], array_column($lista, 'ua'), true)]['sha256_normalizat'];
        }
        foreach ($lista as $k => $x) {
            if ($k === 0) {
                continue;
            }
            $cheie = $x['ua'] . '-' . $x['n'];
            $fel = $x['ua'] !== $ref['ua'] && $stabil[$x['ua']] && $stabil[$ref['ua']] ? 'dupa-ua' : 'la-fiecare-cerere';
            foreach (['status', 'tip', 'location'] as $camp) {
                if ($x[$camp] !== $ref[$camp]) {
                    $diferente[] = ['intre' => $ref['ua'] . '-' . $ref['n'] . ' / ' . $cheie, 'fel' => $fel, 'camp' => $camp,
                        'a' => (string) $ref[$camp], 'b' => (string) $x[$camp]];
                }
            }
            $d = Normalizare::primaDiferenta($normalizate[$ref['ua'] . '-' . $ref['n']], $normalizate[$cheie]);
            if ($d !== null) {
                $diferente[] = ['intre' => $ref['ua'] . '-' . $ref['n'] . ' / ' . $cheie, 'fel' => $fel, 'camp' => 'corp'] + $d;
            }
        }
        $intrare = ['fel' => $a['fel'], 'cale' => $a['cale'], 'url' => $a['url'], 'dosar' => $dosar, 'referinta' => $ref['fisier'],
            'status' => $ref['status'], 'tip' => $ref['tip'], 'location' => $ref['location'], 'sha256' => $ref['sha256'],
            'sha256_normalizat' => $ref['sha256_normalizat'], 'marime' => $ref['marime'], 'identice' => $diferente === [] && $jetoane === [],
            'diferente' => $diferente, 'raspunsuri' => $lista] + (isset($a['wp_id']) ? ['wp_id' => $a['wp_id']] : []);
        self::scrie($this->iesire . '/' . $dosar . '/index.json', self::json($intrare));
        foreach ($diferente as $d) {
            $this->probleme[] = $a['url'] . ': diferă ' . ($d['fel'] === 'dupa-ua' ? 'după UA' : 'la fiecare cerere') . ' (' . $d['intre'] . ', ' . $d['camp']
                . (isset($d['octet']) ? ' de la octetul ' . $d['octet'] . ': „' . $d['a'] . '” față de „' . $d['b'] . '”' : ': ' . $d['a'] . ' față de ' . $d['b'])
                . ') — în afara normalizărilor declarate (o normalizare nouă cere o revizie)';
        }
        if ($jetoane !== []) {
            // Fail-closed: un text de forma jetoanelor noastre în captură n-ar mai putea fi deosebit de un jeton (nici la comparație).
            $this->probleme[] = $a['url'] . ': captura conține deja textul „{{ax:” (forma jetoanelor): ' . implode(', ', $jetoane);
        }
        foreach ($lista as $x) {
            if ($x['status'] === 429) {
                $this->probleme[] = $a['url'] . ': 429 după toate reluările (' . $x['ua'] . '-' . $x['n'] . ')';
            }
        }
        $corpRef = (string) file_get_contents($this->iesire . '/' . $ref['fisier']);
        $esteHtml = $ref['tip'] === 'text/html' || ($ref['tip'] === '' && preg_match('/^\s*<!doctype html/i', $corpRef) === 1);
        if ($cuResurse && in_array($ref['status'], [200, 404], true) && $esteHtml) {
            foreach (Resurse::dinHtml($corpRef, $a['url']) as $r) {
                $this->resurse[$r['url']] ??= ['url' => $r['url'], 'fel' => $r['fel'], 'pagini' => []];
                if (!in_array($a['cale'], $this->resurse[$r['url']]['pagini'], true)) {
                    $this->resurse[$r['url']]['pagini'][] = $a['cale'];
                }
            }
        }
        $this->adrese[] = $intrare;
        return $intrare;
    }

    /**
     * Manifestul: paginile și fișierele cerute de ele. Sursa sha256-ului unui fișier propriu: discul instalației WordPress
     * (`$dosarWp`, pe server) sau o oglindă (`$oglinda`, local); unul negăsit intră la `lipsa`. Gazdele externe intră la
     * `externe`, pentru revizia omului (DECIZIE §2.9: spam SEO, trackere, fonturi).
     *
     * @return array<string, mixed>
     */
    public function manifest(?string $dosarWp = null, ?DosarCapturi $oglinda = null): array
    {
        $proprie = fn (string $url): bool => $this->esteProprie($url);
        $necitite = $this->adaugaDinFoi($dosarWp, $oglinda);
        $pagini = [];
        foreach ($this->adrese as $a) {
            $pagini[] = ['url' => $a['url'], 'cale' => $a['cale'], 'fel' => $a['fel'], 'status' => $a['status'], 'tip' => $a['tip'],
                'sha256' => $a['sha256'], 'sha256_normalizat' => $a['sha256_normalizat'], 'marime' => $a['marime'], 'fisier' => $a['referinta']];
        }
        $fisiere = [];
        $lipsa = [];
        $externe = [];
        $resurse = $this->resurse;
        ksort($resurse);
        foreach ($resurse as $url => $r) {
            if (!$proprie($url)) {
                $g = strtolower((string) parse_url($url, PHP_URL_HOST));
                $externe[$g] ??= ['gazda' => $g, 'tracker' => Cereri::tracker($g), 'adrese' => []];
                if (count($externe[$g]['adrese']) < 20) {
                    $externe[$g]['adrese'][] = ['url' => $url, 'fel' => $r['fel']];
                }
                continue;
            }
            $cale = rawurldecode((string) parse_url($url, PHP_URL_PATH));
            $sursa = null;
            $fisier = $dosarWp !== null ? self::fisierPeDisc($dosarWp, $cale) : null;
            if ($fisier !== null) {
                $sursa = 'disc';
            }
            $tip = null;
            if ($fisier === null && $oglinda !== null && ($i = $oglinda->cauta($url)) !== null) {
                $fisier = $i['fisier'];
                $sursa = 'oglinda';
                foreach ($i['antete'] as [$n, $v]) {
                    if ($n === 'content-type') {
                        $tip = strtolower(trim(explode(';', $v)[0]));
                    }
                }
            }
            if ($fisier === null) {
                $lipsa[] = ['url' => $url, 'fel' => $r['fel'], 'pagini' => $r['pagini']];
                continue;
            }
            $sha = (string) hash_file('sha256', $fisier);
            // sha256 (= sha256_sursa): octeții de pe disc sau din oglindă; sha256_publicat: ai fișierului scris în docroot-ul nou (o
            // foaie de stil e rescrisă: adresele proprii relative), completat la copiere. P2 verifică sha256_publicat.
            $fisiere[] = ['url' => $url, 'cale' => $cale, 'fel' => $r['fel'], 'sha256' => $sha, 'sha256_sursa' => $sha, 'marime' => (int) filesize($fisier),
                'tip' => $tip, 'sursa' => $sursa, 'pagini' => $r['pagini'], 'publicat' => CopiereStatica::tinta($cale), 'sha256_publicat' => null]
                + (($r['din_foi'] ?? []) !== [] ? ['din_foi' => $r['din_foi']] : []);
        }
        ksort($externe);
        $m = ['format' => 2, 'url' => $this->url, 'facut' => date('c'), 'pagini' => $pagini, 'fisiere' => $fisiere, 'lipsa' => $lipsa,
            'externe' => array_values($externe), 'brut' => $this->listaBrut()];
        $this->manifest = $m;
        self::scrie($this->iesire . '/manifest.json', self::json($m));
        foreach ($externe as $e) {
            if ($e['tracker']) {
                $this->info[] = 'tracker cerut de pagini (scriptul lui e aruncat, îl încarcă runtime-ul după acord): ' . $e['gazda'];
            } else {
                $this->avertismente[] = 'gazdă externă cerută de pagini (de revizuit de om): ' . $e['gazda'] . ' (' . count($e['adrese']) . ' adrese)';
            }
        }
        if ($necitite !== []) {
            $this->avertismente[] = count($necitite) . ' foi proprii nu s-au putut citi (nici disc, nici oglindă), deci fișierele cerute de ele nu sunt'
                . ' în manifest: ' . implode(', ', array_slice($necitite, 0, 5)) . (count($necitite) > 5 ? ' …' : '');
        }
        if ($lipsa !== []) {
            $this->avertismente[] = count($lipsa) . ' fișiere cerute de pagini nu s-au găsit ' . ($dosarWp !== null ? 'pe disc' : ($oglinda !== null ? 'în oglindă'
                : '(nici disc, nici oglindă)')) . ' (lista în manifest.json → lipsa)';
        }
        return $m;
    }

    /**
     * O copie făcută (CopiereStatica::copiaza: calea publicată și sha256-ul fișierului publicat) sau refuzată (`$motiv`), trecută
     * în manifest; manifest.json se scrie din nou (raportul îi ține sha256-ul final).
     *
     * @param array{publicat: string, sha256_sursa: string, sha256_publicat: string}|null $copie
     */
    public function inregistreazaCopia(string $url, ?array $copie, ?string $motiv = null): void
    {
        if ($this->manifest === null) {
            throw new EroareCaptura('folosire', 'manifestul nu e scris încă');
        }
        foreach ($this->manifest['fisiere'] as $k => $f) {
            if ($f['url'] !== $url) {
                continue;
            }
            if ($copie !== null) {
                if ($copie['sha256_sursa'] !== $f['sha256_sursa']) {
                    throw new EroareCaptura('scriere', 'fișierul copiat nu e cel din manifest (alt sha256 al sursei): ' . $f['cale']);
                }
                $this->manifest['fisiere'][$k]['publicat'] = $copie['publicat'];
                $this->manifest['fisiere'][$k]['sha256_publicat'] = $copie['sha256_publicat'];
                unset($this->manifest['fisiere'][$k]['necopiat']);
            } else {
                $this->manifest['fisiere'][$k]['sha256_publicat'] = null;
                $this->manifest['fisiere'][$k]['necopiat'] = (string) $motiv;
            }
        }
        self::scrie($this->iesire . '/manifest.json', self::json($this->manifest));
    }

    /**
     * Fonturile Google aduse (FonturiGoogle::aduce, pe adresa foii), trecute în manifest (`fonturi`): foaia (calea publicată,
     * fișierul, sha256) și fiecare font (adresa, calea publicată, fișierul, sha256). `$inCaptura`: fișierele stau în `fonturi/` al
     * capturii (publicate apoi de `--din-dosar`), altfel deja în docroot. manifest.json se scrie din nou.
     *
     * @param array<string, array{css: string, fisier_css: string, sha256_css: string, fonturi: list<array{url: string, cale: string, fisier: string, sha256: string}>}> $foi
     */
    public function inregistreazaFonturi(array $foi, bool $inCaptura): void
    {
        if ($this->manifest === null) {
            throw new EroareCaptura('folosire', 'manifestul nu e scris încă');
        }
        $lista = [];
        foreach ($foi as $url => $f) {
            $lista[] = ['url' => (string) $url] + $f;
        }
        $this->manifest['fonturi'] = $lista;
        $this->manifest['fonturi_dosar'] = $inCaptura ? FonturiGoogle::DOSAR_CAPTURA : 'publicat';
        self::scrie($this->iesire . '/manifest.json', self::json($this->manifest));
    }

    /** Adresa e a site-ului (gazda proprie, cu portul ei, sau perechea www)? */
    private function esteProprie(string $url): bool
    {
        $p = parse_url($url);
        $g = strtolower((string) ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
        return !isset($p['user']) && !isset($p['pass']) && in_array($g, $this->normalizare->gazde(), true);
    }

    /** Fișierul de pe disc al unei căi a site-ului, doar dacă realpath-ul lui e în instalația WordPress și e un fișier obișnuit; altfel null. */
    private static function fisierPeDisc(string $dosarWp, string $cale): ?string
    {
        if ($cale === '' || str_contains($cale, "\0") || str_contains($cale, '..')) {
            return null;
        }
        $f = rtrim($dosarWp, '/') . '/' . ltrim($cale, '/');
        $real = realpath($f);
        $rad = realpath($dosarWp);
        return $real !== false && $rad !== false && str_starts_with($real, rtrim($rad, '/') . '/') && is_file($real) && !is_link($f) ? $real : null;
    }

    /**
     * Analiza statică a foilor proprii (DECIZIE §2.3 #3: `url()` din foi, recursiv cu `@import`; plus `image-set` și `src` din
     * `@font-face`): fiecare foaie proprie din resurse se citește de pe disc sau din oglindă, iar adresele găsite intră în resurse
     * (deci în manifest, în lista copierii și în cea a fonturilor), cu paginile foii și foaia din care vin; foile proprii găsite
     * așa se citesc și ele, cel mult 8 niveluri. Întoarce foile care nu s-au putut citi.
     *
     * @return list<string>
     */
    private function adaugaDinFoi(?string $dosarWp, ?DosarCapturi $oglinda): array
    {
        $coada = [];
        foreach ($this->resurse as $url => $r) {
            if ($r['fel'] === 'foaie' && $this->esteProprie($url)) {
                $coada[] = [$url, 0];
            }
        }
        $vazute = [];
        $necitite = [];
        while ($coada !== []) {
            [$url, $nivel] = array_shift($coada);
            if (isset($vazute[$url])) {
                continue;
            }
            $vazute[$url] = true;
            $fisier = $dosarWp !== null ? self::fisierPeDisc($dosarWp, rawurldecode((string) parse_url($url, PHP_URL_PATH))) : null;
            if ($fisier === null && $oglinda !== null) {
                $fisier = $oglinda->cauta($url)['fisier'] ?? null;
            }
            if ($fisier === null || !is_file($fisier) || filesize($fisier) > 32 * 1024 * 1024) {
                $necitite[] = (string) parse_url($url, PHP_URL_PATH);
                continue;
            }
            foreach (Resurse::dinCss((string) file_get_contents($fisier), $url) as $gasit) {
                $u = $gasit['url'];
                $this->resurse[$u] ??= ['url' => $u, 'fel' => $gasit['fel'], 'pagini' => [], 'din_foi' => []];
                $this->resurse[$u]['pagini'] = array_values(array_unique(array_merge($this->resurse[$u]['pagini'], $this->resurse[$url]['pagini'])));
                $this->resurse[$u]['din_foi'] = array_values(array_unique(array_merge($this->resurse[$u]['din_foi'] ?? [], [(string) parse_url($url, PHP_URL_PATH)])));
                if ($gasit['fel'] === 'foaie' && $nivel < 8 && $this->esteProprie($u)) {
                    $coada[] = [$u, $nivel + 1];
                }
            }
        }
        return $necitite;
    }

    /** @return array<string, mixed>|null */
    public function manifestScris(): ?array
    {
        return $this->manifest;
    }

    private function scrieRaport(?Cereri $cereri): void
    {
        $index = ['format' => 1, 'url' => $this->url, 'facuta' => date('c'), 'mod' => $cereri !== null ? 'http' : 'din-dosar', 'adrese' => $this->adrese];
        self::scrie($this->iesire . '/index.json', self::json($index));
    }

    /** Raportul final, cu sha256-ul indexului și al manifestului (dacă există). */
    /**
     * Raportul (raport-captura.json): antetul (modul, ritmul, bugetul, gazdele permise, integritatea), cererile și jurnalul lor,
     * problemele, oprirea (dacă a fost), plus sha256-ul fiecărui fișier din FISIERE_LANT existent (index, manifest, jurnale,
     * integritate). Întoarce raportul și sha256-ul lui (`sha256_raport`, de dat la --din-dosar ca --sha-raport=).
     *
     * @param array<string, mixed> $antet
     * @return array<string, mixed>
     */
    public function raport(?Cereri $cereri, array $antet = [], ?string $oprit = null): array
    {
        $sha = [];
        foreach (self::FISIERE_LANT as $f) {
            $cale = $this->iesire . '/' . $f;
            if (is_file($cale) && !is_link($cale)) {
                $sha[$f] = (string) hash_file('sha256', $cale);
            }
        }
        $r = ['format' => 2, 'facut' => date('c'), 'url' => $this->url, 'antet' => $antet + ($cereri !== null ? ['cereri' => $cereri->configuratie()] : []),
            'cereri' => $cereri?->facute(), 'jurnal' => $cereri?->jurnal() ?? [], 'oprit' => $oprit,
            'probleme' => $this->probleme, 'avertismente' => $this->avertismente, 'info' => $this->info,
            'sha256_index' => $sha['index.json'] ?? null, 'sha256_manifest' => $sha['manifest.json'] ?? null, 'sha256_jurnal' => $sha[self::JURNAL] ?? null,
            'sha256_fisiere' => $sha];
        self::scrie($this->iesire . '/raport-captura.json', self::json($r));
        return $r + ['sha256_raport' => (string) hash_file('sha256', $this->iesire . '/raport-captura.json')];
    }

    /**
     * Fiecare fișier din brut/ (corpurile, antetele, index.json-ul fiecărei adrese), cu sha256-ul și mărimea lui: intră în
     * manifest, ca o captură urcată să poată fi verificată fișier cu fișier.
     *
     * @return list<array{fisier: string, sha256: string, marime: int}>
     */
    private function listaBrut(): array
    {
        $dir = $this->iesire . '/brut';
        if (!is_dir($dir)) {
            return [];
        }
        $rez = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isLink() || !$f->isFile()) {
                throw new EroareCaptura('scriere', 'în brut/ e altceva decât un fișier: ' . $f->getPathname());
            }
            $rez[] = ['fisier' => 'brut/' . substr($f->getPathname(), strlen($dir) + 1), 'sha256' => (string) hash_file('sha256', $f->getPathname()),
                'marime' => (int) $f->getSize()];
        }
        usort($rez, static fn (array $a, array $b): int => $a['fisier'] <=> $b['fisier']);
        return $rez;
    }

    public static function json(mixed $date): string
    {
        return json_encode($date, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /** Dosarul de ieșire, făcut acum și verificat din nou: calea lui reală e tot cea verificată (nicio legătură pusă între timp). */
    public function pregatesteIesirea(): void
    {
        self::dosar($this->iesire);
        if (realpath($this->iesire) !== $this->iesire) {
            throw new EroareCaptura('scriere', 'dosarul de ieșire s-a schimbat după verificare (o legătură simbolică?): ' . $this->iesire);
        }
    }

    private static function dosar(string $d): void
    {
        if (!is_dir($d) && !@mkdir($d, 0770, true) && !is_dir($d)) {
            throw new EroareCaptura('scriere', 'nu pot face dosarul ' . $d);
        }
    }

    private static function scrie(string $f, string $continut): void
    {
        self::dosar(dirname($f));
        if (is_link($f)) {
            throw new EroareCaptura('scriere', 'refuz să scriu printr-o legătură: ' . $f);
        }
        if (@file_put_contents($f, $continut) === false) {
            throw new EroareCaptura('scriere', 'nu pot scrie ' . $f);
        }
    }
}
