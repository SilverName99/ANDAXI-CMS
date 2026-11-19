<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

/**
 * Un dosar cu răspunsuri deja luate (`--din-dosar=`, DECIZIE §2.3 #2: „HTML-ul brut se urcă împreună cu sha256-ul lui”):
 *  - o captură a noastră (are `index.json`, scris de Captura): fiecare adresă cu răspunsul de referință din `brut/`;
 *  - sau o oglindă: fiecare fișier `F` descris de un fișier alăturat `F.meta.json` sau `F.json` cu `{"url": …, "status": …,
 *    "headers": {…}}`; fișierele fără descriere se dau printr-o hartă (`--harta=<fișier.json>`: `{"<adresă>": "<cale relativă>"}`,
 *    scrisă în afara repo-ului), cu antetele din `F.headers.txt` / `F.headers` (blocul brut), dacă există.
 * Nu face nicio cerere. Adresele se caută după o cheie (schema și gazda cu litere mici, calea decodată, fără fragment).
 *
 * O captură URCATĂ (DECIZIE §2.3 #2: „HTML-ul brut se urcă împreună cu sha256-ul lui”) se deschide doar cu deschideVerificat():
 * sha256-ul lui raport-captura.json e cel dat (tipărit la finalul capturii), sha256-urile raportului acoperă index.json,
 * manifest.json și jurnalele, iar manifestul (`brut`) dă sha256-ul fiecărui fișier din brut/: orice fișier schimbat, lipsă sau
 * în plus e o neconcordanță (EroareCaptura('lant')). `referinta` din index.json e doar o cale relativă din dosar (fără „..”,
 * fără legături, cu realpath-ul înăuntru), la fel și la deschide().
 */
final class DosarCapturi
{
    /** @var array<string, array{fisier: string, url: string, status: int, antete: list<array{0: string, 1: string}>}> */
    private array $intrari = [];

    /** Adresa site-ului capturat (din `index.json` al unei capturi a noastre), sau ''. */
    public string $url = '';

    /** @var array<string, string> la o captură verificată: sha256-ul fiecărui fișier (calea reală), verificat din nou la citire */
    private array $sume = [];

    /** @var array<string, mixed> manifestul unei capturi verificate (sha256-ul lui e în lanțul raportului) */
    private array $manifestVerificat = [];

    /** Manifestul capturii urcate, verificat prin lanț ([] la o captură neverificată sau o oglindă). @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifestVerificat;
    }

    private function __construct(public readonly string $dosar, public readonly bool $alNostru)
    {
    }

    public static function deschide(string $dosar, ?string $harta = null): self
    {
        $dosar = rtrim($dosar, '/');
        if (!is_dir($dosar)) {
            throw new EroareCaptura('folosire', 'dosarul capturilor nu există: ' . $dosar);
        }
        if (is_file($dosar . '/index.json')) {
            $d = new self($dosar, true);
            $index = json_decode((string) file_get_contents($dosar . '/index.json'), true);
            $d->url = rtrim((string) ($index['url'] ?? ''), '/');
            foreach ((array) ($index['adrese'] ?? []) as $a) {
                if (!is_array($a) || !is_string($a['url'] ?? null) || !is_string($a['referinta'] ?? null)) {
                    continue;
                }
                $f = self::caleInDosar($dosar, $a['referinta']);
                $antete = self::caleInDosar($dosar, substr($a['referinta'], 0, -5) . '.antete', false);
                $d->adauga((string) $a['url'], $f, (int) ($a['status'] ?? 200), self::anteteBrute($antete !== null ? (string) file_get_contents($antete) : ''));
            }
            return $d;
        }
        $d = new self($dosar, false);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dosar, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isLink() || !$f->isFile()) {
                continue;
            }
            $cale = $f->getPathname();
            foreach (['.meta.json', '.json'] as $suf) {
                if (!str_ends_with($cale, $suf)) {
                    continue;
                }
                $tinta = substr($cale, 0, -strlen($suf));
                if (!is_file($tinta) || is_link($tinta)) {
                    continue;
                }
                $m = json_decode((string) @file_get_contents($cale), true);
                if (!is_array($m) || !is_string($m['url'] ?? null)) {
                    continue;
                }
                $antete = [];
                foreach ((array) ($m['headers'] ?? []) as $n => $v) {
                    foreach ((array) $v as $x) {
                        $antete[] = [strtolower((string) $n), (string) $x];
                    }
                }
                $d->adauga((string) $m['url'], $tinta, (int) ($m['status'] ?? 200), $antete);
                break;
            }
        }
        if ($harta !== null) {
            $h = json_decode((string) @file_get_contents($harta), true);
            if (!is_array($h)) {
                throw new EroareCaptura('folosire', 'harta ' . $harta . ' nu e un obiect JSON {"<adresă>": "<cale relativă>"}');
            }
            foreach ($h as $url => $rel) {
                $tinta = $dosar . '/' . ltrim((string) $rel, '/');
                if (str_contains((string) $rel, '..') || !is_file($tinta) || is_link($tinta)) {
                    throw new EroareCaptura('folosire', 'harta: ' . $rel . ' nu e un fișier din dosar');
                }
                $antete = [];
                $status = 200;
                foreach ([$tinta . '.headers.txt', $tinta . '.headers', (string) preg_replace('/\.(html|body)$/', '.headers.txt', $tinta)] as $fa) {
                    if ($fa !== $tinta && is_file($fa)) {
                        [$status, $antete] = self::anteteCurl((string) file_get_contents($fa));
                        break;
                    }
                }
                $d->adauga((string) $url, $tinta, $status, $antete);
            }
        }
        return $d;
    }

    /**
     * O captură a noastră URCATĂ, verificată întreagă: `$shaRaport` e sha256-ul lui raport-captura.json (tipărit la finalul
     * capturii), raportul e al site-ului `$url`, iar sha256-urile lui acoperă index.json, manifest.json și jurnalele; manifestul
     * (`brut`) dă fiecare fișier din brut/ cu sha256-ul lui (niciunul schimbat, lipsă sau în plus); fiecare `referinta` din index e
     * în această listă. Orice neconcordanță e EroareCaptura('lant').
     */
    public static function deschideVerificat(string $dosar, string $shaRaport, string $url): self
    {
        $nu = static fn (string $motiv): EroareCaptura => new EroareCaptura('lant', 'captura urcată nu trece verificarea: ' . $motiv);
        if (preg_match('/^[0-9a-f]{64}$/', $shaRaport) !== 1) {
            throw new EroareCaptura('folosire', '--sha-raport trebuie să fie sha256-ul lui raport-captura.json (64 de cifre hexazecimale)');
        }
        $real = realpath($dosar);
        if ($real === false || !is_dir($real)) {
            throw new EroareCaptura('folosire', 'dosarul capturilor nu există: ' . $dosar);
        }
        $fisier = static function (string $rel) use ($real, $nu): string {
            $f = self::caleInDosar($real, $rel, false);
            if ($f === null) {
                throw $nu($rel . ' lipsește sau nu e un fișier obișnuit din dosar');
            }
            return $f;
        };
        // Fiecare fișier al lanțului e citit o singură dată: sha256-ul și decodarea se fac pe aceiași octeți (recenzia 2, R1-11).
        $citeste = static function (string $rel) use ($fisier, $nu): string {
            $c = @file_get_contents($fisier($rel));
            if (!is_string($c)) {
                throw $nu($rel . ' nu se poate citi');
            }
            return $c;
        };
        $rBrut = $citeste('raport-captura.json');
        if (!hash_equals($shaRaport, hash('sha256', $rBrut))) {
            throw $nu('raport-captura.json are alt sha256 decât cel dat');
        }
        $r = json_decode($rBrut, true);
        if (!is_array($r) || rtrim((string) ($r['url'] ?? ''), '/') !== rtrim($url, '/')) {
            throw $nu('raportul e al altui site (' . (is_array($r) ? (string) ($r['url'] ?? '') : '?') . ')');
        }
        // O captură care a ieșit cu NU nu se reia și nu se importă (recenzia 2, R1-2): probleme, o oprire, o adresă neidentică.
        if ((array) ($r['probleme'] ?? []) !== [] || ($r['oprit'] ?? null) !== null) {
            throw $nu('captura a ieșit cu NU (' . count((array) ($r['probleme'] ?? [])) . ' probleme' . (($r['oprit'] ?? null) !== null ? ', oprită' : '')
                . '): se reface, nu se reia');
        }
        $sume = is_array($r['sha256_fisiere'] ?? null) ? $r['sha256_fisiere'] : [];
        foreach (['index.json', 'manifest.json'] as $obligatoriu) {
            if (!is_string($sume[$obligatoriu] ?? null)) {
                throw $nu('raportul nu are sha256-ul lui ' . $obligatoriu);
            }
        }
        $continut = [];
        foreach ($sume as $rel => $suma) {
            if (!in_array($rel, Captura::FISIERE_LANT, true) || !is_string($suma)) {
                throw $nu($rel . ' nu are sha256-ul din raport');
            }
            $continut[$rel] = $citeste((string) $rel);
            if (!hash_equals($suma, hash('sha256', $continut[$rel]))) {
                throw $nu($rel . ' nu are sha256-ul din raport');
            }
        }
        $m = json_decode($continut['manifest.json'], true);
        $brut = [];
        foreach ((array) (is_array($m) ? ($m['brut'] ?? []) : []) as $b) {
            if (!is_array($b) || !is_string($b['fisier'] ?? null) || !is_string($b['sha256'] ?? null) || !str_starts_with($b['fisier'], 'brut/')) {
                throw $nu('manifestul are o intrare brut/ greșită');
            }
            if (!hash_equals($b['sha256'], (string) hash_file('sha256', $fisier($b['fisier'])))) {
                throw $nu($b['fisier'] . ' nu are sha256-ul din manifest');
            }
            $brut[$b['fisier']] = $b['sha256'];
        }
        if (is_dir($real . '/brut')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real . '/brut', \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST) as $f) {
                /** @var \SplFileInfo $f */
                $rel = 'brut/' . substr($f->getPathname(), strlen($real . '/brut') + 1);
                if ($f->isLink() || (!$f->isFile() && !$f->isDir())) {
                    throw $nu($rel . ' e o legătură sau un fișier special');
                }
                if ($f->isFile() && !isset($brut[$rel])) {
                    throw $nu($rel . ' nu e în manifest (fișier în plus)');
                }
            }
        }
        $index = json_decode($continut['index.json'], true);
        if (!is_array($index) || rtrim((string) ($index['url'] ?? ''), '/') !== rtrim($url, '/')) {
            throw $nu('index.json e al altui site');
        }
        foreach ((array) ($index['adrese'] ?? []) as $a) {
            if (!is_array($a) || !is_string($a['referinta'] ?? null) || !isset($brut[$a['referinta']])) {
                throw $nu('o adresă din index.json are o referință în afara fișierelor din manifest');
            }
            if (($a['identice'] ?? true) === false) {
                throw $nu('adresa ' . (string) ($a['cale'] ?? '?') . ' n-a fost identică între cereri: captura a ieșit cu NU');
            }
        }
        $d = self::deschide($real);
        $d->manifestVerificat = is_array($m) ? $m : [];
        foreach ($brut as $rel => $suma) {
            $d->sume[(string) realpath($real . '/' . $rel)] = $suma;
        }
        return $d;
    }

    /**
     * Calea unui fișier din dosar, după o cale relativă dată de un fișier de neîncredere (index.json): relativă, fără „..”, fără
     * octetul 0, fără legături simbolice pe drum, cu realpath-ul în dosar și un fișier obișnuit. Altfel null (`$obligatoriu`:
     * EroareCaptura).
     */
    public static function caleInDosar(string $dosar, string $rel, bool $obligatoriu = true): ?string
    {
        $rad = realpath($dosar);
        $ok = $rad !== false && $rel !== '' && !str_starts_with($rel, '/') && !str_contains($rel, "\0") && !str_contains($rel, '\\')
            && preg_match('#(^|/)\.\.?(/|$)#', $rel) !== 1;
        if ($ok) {
            $drum = $rad;
            foreach (explode('/', $rel) as $seg) {
                $drum .= '/' . $seg;
                if ($seg === '' || is_link($drum)) {
                    $ok = false;
                    break;
                }
            }
        }
        $real = $ok ? realpath($rad . '/' . $rel) : false;
        if ($real === false || !str_starts_with($real, rtrim((string) $rad, '/') . '/') || !is_file($real)) {
            if ($obligatoriu) {
                throw new EroareCaptura('folosire', 'referința ' . $rel . ' nu e un fișier din dosarul capturilor');
            }
            return null;
        }
        return $real;
    }

    /** Cheia unei adrese: schema și gazda cu litere mici, calea decodată, query-ul cu entitățile decodate, fără fragment. */
    public static function cheie(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = (string) preg_replace('/#.*$/s', '', $url);
        $p = parse_url($url);
        if ($p === false || !isset($p['host'])) {
            return $url;
        }
        return strtolower((string) ($p['scheme'] ?? 'https')) . '://' . strtolower((string) $p['host']) . (isset($p['port']) ? ':' . $p['port'] : '')
            . rawurldecode((string) ($p['path'] ?? '/')) . (isset($p['query']) ? '?' . rawurldecode((string) $p['query']) : '');
    }

    /** @return array{fisier: string, url: string, status: int, antete: list<array{0: string, 1: string}>}|null */
    public function cauta(string $url): ?array
    {
        return $this->intrari[self::cheie($url)] ?? null;
    }

    /** Răspunsul unei căi a site-ului capturat (`/camere/`), după adresa din `index.json`. */
    public function dupaCale(string $cale): ?Raspuns
    {
        return $this->url === '' ? null : $this->raspuns($this->url . '/' . ltrim($cale, '/'));
    }

    /** @return array<string, array{fisier: string, url: string, status: int, antete: list<array{0: string, 1: string}>}> */
    public function toate(): array
    {
        return $this->intrari;
    }

    /** Răspunsul de pe disc, ca Raspuns (corpul exact, antetele câte sunt). */
    public function raspuns(string $url): ?Raspuns
    {
        $i = $this->cauta($url);
        if ($i === null) {
            return null;
        }
        $corp = (string) file_get_contents($i['fisier']);
        if (isset($this->sume[$i['fisier']]) && !hash_equals($this->sume[$i['fisier']], hash('sha256', $corp))) {
            throw new EroareCaptura('lant', 'captura urcată s-a schimbat după verificare: ' . $i['fisier']);
        }
        $brute = '';
        foreach ($i['antete'] as [$n, $v]) {
            $brute .= $n . ': ' . $v . "\r\n";
        }
        return new Raspuns($url, 'din-dosar', $i['status'], $i['antete'], $brute, $corp);
    }

    /** @param list<array{0: string, 1: string}> $antete */
    private function adauga(string $url, string $fisier, int $status, array $antete): void
    {
        $this->intrari[self::cheie($url)] = ['fisier' => $fisier, 'url' => $url, 'status' => $status, 'antete' => $antete];
    }

    /** @return list<array{0: string, 1: string}> */
    private static function anteteBrute(string $bloc): array
    {
        $rez = [];
        foreach (preg_split('/\r?\n/', $bloc) ?: [] as $linie) {
            if (str_contains($linie, ':') && preg_match('#^HTTP/#', $linie) !== 1) {
                [$n, $v] = explode(':', $linie, 2);
                $rez[] = [strtolower(trim($n)), trim($v)];
            }
        }
        return $rez;
    }

    /** Antetele dintr-un bloc scris de `curl -D` (poate avea „HTTP/1.1 200 Connection Established” al proxy-ului înainte). */
    private static function anteteCurl(string $bloc): array
    {
        $blocuri = preg_split('/\r?\n\r?\n/', trim($bloc)) ?: [];
        $ultim = (string) end($blocuri);
        $status = 200;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $ultim, $m) === 1) {
            $status = (int) $m[1];
        }
        return [$status, self::anteteBrute($ultim)];
    }
}
