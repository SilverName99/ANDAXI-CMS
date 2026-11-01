<?php

declare(strict_types=1);

namespace Unelte\Verificare;

use DOMDocument;
use DOMXPath;

/** Argumente greșite: nicio cerere (cod 2). */
final class RefuzRedirecturi extends \RuntimeException
{
}

/**
 * tools/verificare/verifica-redirecturi.php (WP-39, G-M7): adresele vechi ale unui site față de site-ul nou. Capul
 * fișierului de intrare spune ce verifică; aici, cum.
 */
final class VerificaRedirecturi
{
    public const USER_AGENTS = [
        'googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html) ANDAXI-verifica-redirecturi/1.0',
        'browser' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 ANDAXI-verifica-redirecturi/1.0',
    ];
    private const MARIME_MAXIMA = 5242880;
    private const MAX_ADRESE = 20000;
    private const MAX_PROBLEME_AFISATE = 500;

    /** @var \CurlHandle */
    private $curl;
    private string $gazda;

    /**
     * @param list<string> $cai
     * @param array{exacte: array<string, true>, prefixe: list<string>} $intentionate
     */
    private function __construct(private string $baza, private string $ua, private float $pauza, private ?string $poarta,
        private ?string $parola, private array $cai, private array $intentionate)
    {
        $parti = parse_url($baza);
        $this->gazda = strtolower((string) ($parti['host'] ?? ''));
        $curl = curl_init();
        if (!$curl instanceof \CurlHandle) {
            throw new RefuzRedirecturi('curl nu pornește');
        }
        $this->curl = $curl;
    }

    /** @param list<string> $argv */
    public static function ruleaza(array $argv): int
    {
        try {
            $opt = [];
            foreach (array_slice($argv, 1) as $argument) {
                if (preg_match('/^--(lista|baza|ua|pauza|pachet|intentionate|poarta)=(.*)$/s', $argument, $m) === 1) {
                    $opt[$m[1]] = $m[2];
                } else {
                    throw new RefuzRedirecturi('argument necunoscut (vezi capul fișierului)'
                        . (preg_match('/^--[a-z-]{1,30}/', $argument, $m) === 1 ? ': ' . $m[0] : '')
                        . '; parola porții se dă doar în VERIFICARE_POARTA_PAROLA, niciodată în linia de comandă');
                }
            }
            return self::pregateste($opt)->verifica();
        } catch (RefuzRedirecturi $e) {
            fwrite(STDERR, 'REFUZAT: ' . $e->getMessage() . ". Nu am cerut nimic.\n");
            return 2;
        }
    }

    /** @param array<string, string> $opt */
    private static function pregateste(array $opt): self
    {
        $baza = rtrim((string) ($opt['baza'] ?? ''), '/');
        $parti = parse_url($baza);
        if ($baza === '' || !is_array($parti) || !in_array(strtolower((string) ($parti['scheme'] ?? '')), ['http', 'https'], true)
            || ($parti['host'] ?? '') === '' || isset($parti['user']) || isset($parti['pass']) || (isset($parti['path']) && $parti['path'] !== '')
            || isset($parti['query']) || isset($parti['fragment'])) {
            throw new RefuzRedirecturi('--baza trebuie să fie adresa site-ului nou, fără cale (de exemplu --baza=https://bioscem.ro)');
        }
        $local = in_array(strtolower((string) $parti['host']), ['127.0.0.1', 'localhost', '[::1]', '::1'], true);
        $ua = (string) ($opt['ua'] ?? 'googlebot');
        if (!isset(self::USER_AGENTS[$ua])) {
            throw new RefuzRedirecturi('--ua e googlebot sau browser');
        }
        $pauza = (string) ($opt['pauza'] ?? '1');
        if (preg_match('/^[0-9]{1,2}(\.[0-9]{1,3})?$/', $pauza) !== 1) {
            throw new RefuzRedirecturi('--pauza e în secunde (de exemplu --pauza=1.5)');
        }
        if (!$local && (float) $pauza < 1.0) {
            throw new RefuzRedirecturi('pe un site care nu e pe calculatorul tău pauza e cel puțin 1 secundă între cereri (--pauza=1)');
        }
        $poarta = null;
        $parola = null;
        if (isset($opt['poarta'])) {
            $poarta = (string) $opt['poarta'];
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $poarta) !== 1) {
                throw new RefuzRedirecturi('--poarta e utilizatorul porții (de exemplu --poarta=verificare)');
            }
            $parola = (string) getenv('VERIFICARE_POARTA_PAROLA');
            if ($parola === '') {
                throw new RefuzRedirecturi('cu --poarta, parola porții se dă în VERIFICARE_POARTA_PAROLA');
            }
            if (!$local && strtolower((string) $parti['scheme']) !== 'https') {
                throw new RefuzRedirecturi('parola porții se trimite doar pe https');
            }
        }
        $lista = (string) ($opt['lista'] ?? '');
        if ($lista === '' || !is_file($lista) || !is_readable($lista)) {
            throw new RefuzRedirecturi('lipsește --lista=<fișier> (o adresă veche pe rând) sau nu se poate citi');
        }
        $cai = self::caiDinLista((string) file_get_contents($lista));
        if ($cai === []) {
            throw new RefuzRedirecturi('lista nu are nicio adresă (rândurile încep cu „/” sau cu „http”)');
        }
        if (count($cai) > self::MAX_ADRESE) {
            throw new RefuzRedirecturi('lista are peste ' . self::MAX_ADRESE . ' de adrese: împarte-o');
        }
        $intentionate = ['exacte' => [], 'prefixe' => []];
        if (isset($opt['pachet'])) {
            $slug = (string) $opt['pachet'];
            $radacina = dirname(__DIR__, 3);
            $fisier = preg_match('/^[a-z0-9-]{1,40}$/', $slug) === 1
                ? ($slug === 'demo' ? $radacina . '/tools/dev/site-demo/redirecturi.json' : $radacina . '/site-uri/' . $slug . '/redirecturi.json') : '';
            if ($fisier === '' || !is_file($fisier)) {
                throw new RefuzRedirecturi('--pachet e numele pachetului site-ului (de exemplu --pachet=bioscem), cu redirecturi.json');
            }
            $date = json_decode((string) file_get_contents($fisier), true);
            foreach ((array) ($date['exacte'] ?? []) as $rand) {
                if (is_array($rand) && is_string($rand[0] ?? null) && (int) ($rand[2] ?? 301) === 410) {
                    $intentionate['exacte'][self::cheie($rand[0])] = true;
                }
            }
            foreach ((array) ($date['prefixe'] ?? []) as $rand) {
                if (is_array($rand) && is_string($rand[0] ?? null) && (int) ($rand[2] ?? 410) === 410 && $rand[0] !== '/') {
                    $intentionate['prefixe'][] = strtolower($rand[0]);
                }
            }
        }
        if (isset($opt['intentionate'])) {
            $fisier = (string) $opt['intentionate'];
            if (!is_file($fisier) || !is_readable($fisier)) {
                throw new RefuzRedirecturi('--intentionate=<fișier> nu se poate citi');
            }
            foreach (self::caiDinLista((string) file_get_contents($fisier)) as $cale) {
                $intentionate['exacte'][self::cheie($cale)] = true;
            }
        }
        return new self($baza, self::USER_AGENTS[$ua], (float) $pauza, $poarta, $parola, $cai, $intentionate);
    }

    /**
     * Căile (cu interogarea) dintr-o listă: o adresă pe rând sau prima coloană a unui CSV; fără duplicate, în ordine.
     *
     * @return list<string>
     */
    public static function caiDinLista(string $text): array
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $cai = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $linie) {
            $linie = trim($linie);
            if ($linie === '' || str_starts_with($linie, '#')) {
                continue;
            }
            $sep = str_contains($linie, "\t") ? "\t" : (str_contains($linie, ';') ? ';' : ',');
            $prima = trim((string) (str_getcsv($linie, $sep, '"', '')[0] ?? ''));
            if (preg_match('#^https?://#i', $prima) === 1) {
                $parti = parse_url($prima);
                if (!is_array($parti)) {
                    continue;
                }
                $prima = ($parti['path'] ?? '/') . (isset($parti['query']) ? '?' . $parti['query'] : '');
            }
            $prima = explode('#', $prima, 2)[0];
            if ($prima === '' || $prima[0] !== '/' || str_starts_with($prima, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $prima) === 1
                || strlen($prima) > 2000) {
                continue;
            }
            $cai[$prima] = true;
        }
        return array_keys($cai);
    }

    /** Cheia unei căi pentru lista regulilor 410: decodată, cu litere mici, fără „/” final și fără interogare. */
    public static function cheie(string $cale): string
    {
        $cale = rawurldecode((string) explode('?', $cale, 2)[0]);
        $cale = mb_strtolower($cale, 'UTF-8');
        return $cale === '/' ? '/' : rtrim($cale, '/');
    }

    private function verifica(): int
    {
        $rezultate = [];
        $probleme = [];
        $prima = true;
        foreach ($this->cai as $cale) {
            if (!$prima && $this->pauza > 0) {
                usleep((int) round($this->pauza * 1000000));
            }
            $prima = false;
            [$fel, $motiv] = $this->judeca($cale);
            $rezultate[$fel] = ($rezultate[$fel] ?? 0) + 1;
            if ($motiv !== null) {
                $probleme[] = $cale . '  →  ' . $motiv;
            }
        }
        $total = count($this->cai);
        echo 'verifica-redirecturi: ', $total, ' adrese față de ', $this->baza, "\n";
        ksort($rezultate);
        foreach ($rezultate as $fel => $numar) {
            echo '  ', str_pad((string) $numar, 6, ' ', STR_PAD_LEFT), '  ', $fel, "\n";
        }
        if ($probleme === []) {
            echo "OK: nicio problemă.\n";
            return 0;
        }
        echo 'PROBLEME (', count($probleme), "):\n";
        foreach (array_slice($probleme, 0, self::MAX_PROBLEME_AFISATE) as $rand) {
            echo '  ', $rand, "\n";
        }
        if (count($probleme) > self::MAX_PROBLEME_AFISATE) {
            echo '  … și încă ', count($probleme) - self::MAX_PROBLEME_AFISATE, "\n";
        }
        return 1;
    }

    /**
     * Rezultatul unei adrese: [felul, motivul problemei sau null].
     *
     * @return array{0: string, 1: ?string}
     */
    private function judeca(string $cale): array
    {
        $r = $this->cere($cale);
        if ($r['cod'] === 0) {
            return ['problemă: site-ul nu răspunde', 'nu răspunde (' . $r['eroare'] . ')'];
        }
        if ($r['cod'] === 200) {
            $motiv = $this->paginaBuna($cale, $r);
            return $motiv === null ? ['200 (pagină indexabilă, canonical spre ea)', null] : ['problemă: 200 neindexabil', $motiv];
        }
        if ($r['cod'] === 410) {
            return $this->eIntentionata($cale) ? ['410 (dispărută voit)', null] : ['problemă: 410 nedeclarat', '410, dar nu e o regulă 410 a site-ului'];
        }
        if (in_array($r['cod'], [301, 308], true)) {
            $tinta = $this->caleDeAici($r['location']);
            if ($tinta === null) {
                return ['problemă: redirecționare în afara site-ului', $r['cod'] . ' spre ' . self::scurt($r['location'])];
            }
            $r2 = $this->cere($tinta);
            if ($r2['cod'] === 200) {
                $motiv = $this->paginaBuna($tinta, $r2);
                return $motiv === null ? ['301 într-un salt spre o pagină bună', null] : ['problemă: 301 spre o pagină neindexabilă', $r['cod'] . ' → ' . $tinta . ': ' . $motiv];
            }
            if ($r2['cod'] >= 300 && $r2['cod'] < 400) {
                return ['problemă: lanț de redirecționări', $r['cod'] . ' → ' . $tinta . ' → ' . $r2['cod'] . ' (' . self::scurt($r2['location']) . ')'];
            }
            return ['problemă: 301 spre o pagină care nu răspunde 200', $r['cod'] . ' → ' . $tinta . ' → ' . $r2['cod']];
        }
        if ($r['cod'] >= 300 && $r['cod'] < 400) {
            return ['problemă: redirecționare temporară', $r['cod'] . ' (nu 301) spre ' . self::scurt($r['location'])];
        }
        return ['problemă: ' . $r['cod'], (string) $r['cod']];
    }

    /** O adresă „dispărută” voit (regula 410 a pachetului sau din --intentionate)? */
    private function eIntentionata(string $cale): bool
    {
        $cheie = self::cheie($cale);
        if (isset($this->intentionate['exacte'][$cheie])) {
            return true;
        }
        foreach ($this->intentionate['prefixe'] as $prefix) {
            if (str_starts_with($cheie, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * O pagină 200 bună: HTML, fără noindex (meta robots sau X-Robots-Tag), cu canonicalul spre ea însăși. Null = bună,
     * altfel motivul.
     *
     * @param array{cod: int, location: string, tip: string, x_robots: string, corp: string, eroare: string} $r
     */
    private function paginaBuna(string $cale, array $r): ?string
    {
        if (!str_starts_with($r['tip'], 'text/html')) {
            return 'nu e o pagină HTML (' . ($r['tip'] !== '' ? $r['tip'] : 'fără tip') . ')';
        }
        if (str_contains($r['x_robots'], 'noindex')) {
            return 'X-Robots-Tag: ' . $r['x_robots'];
        }
        $doc = new DOMDocument();
        $vechi = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $r['corp'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($vechi);
        $x = new DOMXPath($doc);
        foreach ($x->query('//meta[translate(@name, "ROBTS", "robts")="robots" or translate(@name, "GOLEBT", "golebt")="googlebot"]') ?: [] as $meta) {
            if (str_contains(strtolower((string) $meta->getAttribute('content')), 'noindex')) {
                return 'noindex (' . self::scurt((string) $meta->getAttribute('content')) . ')';
            }
        }
        $canonical = '';
        foreach ($x->query('//link[translate(@rel, "CANOIL", "canoil")="canonical"]') ?: [] as $link) {
            $canonical = trim((string) $link->getAttribute('href'));
            break;
        }
        if ($canonical === '') {
            return 'fără canonical';
        }
        $caleCanonical = $this->caleDeAici($canonical);
        if ($caleCanonical === null) {
            return 'canonicalul e pe alt site (' . self::scurt($canonical) . ')';
        }
        if (self::normalizeaza($caleCanonical) !== self::normalizeaza($cale)) {
            return 'canonicalul trimite la ' . self::scurt($caleCanonical);
        }
        return null;
    }

    /** Calea cu interogarea, cu codările unificate (%c8%99 = %C8%99 = ș), pentru comparație. */
    private static function normalizeaza(string $cale): string
    {
        [$drum, $interogare] = array_pad(explode('?', $cale, 2), 2, '');
        $drum = rawurldecode($drum);
        return $drum . ($interogare !== '' ? '?' . rawurldecode($interogare) : '');
    }

    /** Calea (cu interogarea) unei adrese de pe același site (cu sau fără www, http sau https), sau null. */
    private function caleDeAici(string $adresa): ?string
    {
        $adresa = trim(html_entity_decode($adresa, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($adresa === '') {
            return null;
        }
        if (str_starts_with($adresa, '/') && !str_starts_with($adresa, '//')) {
            return explode('#', $adresa, 2)[0];
        }
        $parti = parse_url(str_starts_with($adresa, '//') ? 'https:' . $adresa : $adresa);
        if (!is_array($parti) || !in_array(strtolower((string) ($parti['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }
        $faraWww = static fn (string $g): string => str_starts_with($g, 'www.') ? substr($g, 4) : $g;
        if ($faraWww(strtolower((string) ($parti['host'] ?? ''))) !== $faraWww($this->gazda)) {
            return null;
        }
        return ($parti['path'] ?? '/') . (isset($parti['query']) ? '?' . $parti['query'] : '');
    }

    /** @return array{cod: int, location: string, tip: string, x_robots: string, corp: string, eroare: string} */
    private function cere(string $cale): array
    {
        $corp = '';
        $prea = false;
        $antete = [];
        $optiuni = [
            CURLOPT_URL => $this->baza . $cale,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_USERAGENT => $this->ua,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,*/*;q=0.8', 'Cache-Control: no-cache'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $rand) use (&$antete): int {
                if (preg_match('/^HTTP\/\S+\s+\d{3}/', $rand) === 1) {
                    $antete = [];
                } elseif (str_contains($rand, ':')) {
                    [$nume, $valoare] = explode(':', $rand, 2);
                    $antete[strtolower(trim($nume))][] = trim($valoare);
                }
                return strlen($rand);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $bucata) use (&$corp, &$prea): int {
                if (strlen($corp) + strlen($bucata) > self::MARIME_MAXIMA) {
                    $prea = true;
                    return 0;
                }
                $corp .= $bucata;
                return strlen($bucata);
            },
        ];
        if ($this->poarta !== null) {
            $optiuni[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $optiuni[CURLOPT_USERPWD] = $this->poarta . ':' . (string) $this->parola;
        }
        curl_reset($this->curl);
        curl_setopt_array($this->curl, $optiuni);
        $ok = curl_exec($this->curl);
        $cod = (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        if ($ok === false && !$prea) {
            return ['cod' => 0, 'location' => '', 'tip' => '', 'x_robots' => '', 'corp' => '', 'eroare' => 'curl ' . curl_errno($this->curl)];
        }
        return [
            'cod' => $cod,
            'location' => (string) ($antete['location'][0] ?? ''),
            'tip' => strtolower(str_replace(' ', '', (string) ($antete['content-type'][0] ?? ''))),
            'x_robots' => strtolower(implode(', ', $antete['x-robots-tag'] ?? [])),
            'corp' => $corp,
            'eroare' => '',
        ];
    }

    private static function scurt(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text);
        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '…' : $text;
    }
}
