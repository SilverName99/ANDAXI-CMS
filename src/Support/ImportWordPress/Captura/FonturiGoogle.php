<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

/**
 * Fonturile Google servite de pe site (pasul 4a; DECIZIE §2.3 #4, §6.7 #3): CSS-ul fiecărei legături `fonts.googleapis.com`
 * se cere cu UA-ul real de Chrome (Google trimite `.ttf` altor UA), fișierele woff2 se salvează în
 * `<docroot>/capturat/fonturi/` (cu `--public`) sau în dosarul `fonturi/` al capturii (cu `--lista`, publicate apoi de
 * `--din-dosar`), fiecare adresă cerută o singură dată pe rulare, pe un buget al gazdelor fonturilor (BUGET), iar CSS-ul se rescrie spre ele, neatins în rest (același `unicode-range`, același
 * `font-display`). Legătura din pagină devine `/capturat/fonturi/<cheie>.css` (dialectul o rescrie; cheia e aceeași în ambele
 * locuri: sha256 al adresei decodate, 16 caractere). Gazdele se pot da (o probă locală le pune pe un server al ei).
 *
 * Doar HTTPS: foaia se cere prin https (o legătură http a paginii e cerută tot prin https), iar un fișier de font din foaie
 * care nu e https e refuzat înaintea oricărei cereri. Tipurile se verifică: foaia trebuie să vină `text/css`, fontul cu tipul
 * extensiei lui (TIPURI_FONT); altfel nimic nu se scrie.
 */
final class FonturiGoogle
{
    public const GAZDA_CSS = 'fonts.googleapis.com';
    public const GAZDA_FONTURI = 'fonts.gstatic.com';
    public const DOSAR = '/capturat/fonturi';

    /** Tipurile acceptate pentru fiecare extensie de font (Google trimite font/woff2; celelalte, cum le scriu serverele). */
    public const TIPURI_FONT = [
        'woff2' => ['font/woff2', 'application/font-woff2'],
        'woff' => ['font/woff', 'application/font-woff', 'application/x-font-woff'],
        'ttf' => ['font/ttf', 'font/sfnt', 'application/x-font-ttf', 'application/font-sfnt'],
        'otf' => ['font/otf', 'font/sfnt', 'application/x-font-opentype', 'application/font-sfnt'],
    ];

    public function __construct(
        private readonly Cereri $cereri,
        private readonly string $gazdaCss = self::GAZDA_CSS,
        private readonly string $gazdaFonturi = self::GAZDA_FONTURI,
        private readonly bool $doarHttps = true,
    ) {
    }

    /** Adresa (din HTML, cu entități) e o foaie de fonturi Google? */
    public static function esteFoaie(string $adresa, string $gazdaCss = self::GAZDA_CSS): bool
    {
        $u = html_entity_decode(trim($adresa), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $u = str_starts_with($u, '//') ? 'https:' . $u : $u;
        $p = parse_url($u);
        $g = strtolower((string) ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
        return $g === strtolower($gazdaCss) && str_starts_with((string) ($p['path'] ?? ''), '/css');
    }

    /** De ce nu se cere un fișier de font din foaie (null: se cere): doar https, doar gazda fonturilor, doar tipurile de font. */
    public static function problemaFont(string $u, string $gazdaFonturi = self::GAZDA_FONTURI, bool $doarHttps = true): ?string
    {
        $p = parse_url($u);
        if (!is_array($p) || isset($p['user']) || isset($p['pass'])) {
            return 'adresă de font nevalidă: ' . $u;
        }
        if ($doarHttps && strtolower((string) ($p['scheme'] ?? '')) !== 'https') {
            return 'foaia de fonturi cere un fișier care nu e https: ' . $u;
        }
        $g = strtolower((string) ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($g !== strtolower($gazdaFonturi)) {
            return 'foaia de fonturi cere un fișier de pe altă gazdă: ' . $u;
        }
        $ext = strtolower(pathinfo((string) ($p['path'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::TIPURI_FONT[$ext])) {
            return 'fișier de font cu tip nepermis: ' . $u;
        }
        return null;
    }

    /** Cheia locală a unei foi Google (din adresa ei decodată). */
    public static function cheie(string $adresa): string
    {
        // Aceeași foaie oricum ar fi scrisă schema (http, https, //): cheia e a adresei cu https (recenzia 2, R1-7), ca aduce().
        $u = html_entity_decode(trim($adresa), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $u = (string) preg_replace('#^(?:https?:)?//#i', 'https://', $u);
        return substr(hash('sha256', $u), 0, 16);
    }

    /** Calea locală a foii (relativă la docroot), cum o scrie dialectul în pagină. */
    public static function cale(string $adresa): string
    {
        return self::DOSAR . '/' . self::cheie($adresa) . '.css';
    }

    /** Cererile spre gazdele fonturilor, într-o captură: un buget al lor, separat de al site-ului (plafonul tare). */
    public const BUGET = 120;

    /** Dosarul fonturilor în dosarul capturii (`--fonturi` fără `--public`; `--din-dosar` le publică apoi în DOSAR). */
    public const DOSAR_CAPTURA = 'fonturi';

    /** @var array<string, array{nume: string, sha256: string, corp: string}> fonturile deja aduse în această rulare (adresa → fișierul) */
    private array $aduse = [];

    /** @var array<string, array{css: string, fisier_css: string, sha256_css: string, fonturi: list<array{url: string, cale: string, fisier: string, sha256: string}>}> */
    private array $foi = [];

    /**
     * Aduce foaia și fonturile ei în `$dosar` (fișiere puse alături: `<cheie>.css` și `<sha16>.<ext>`): `<docroot>/capturat/fonturi`
     * sau dosarul `fonturi/` al capturii. Fiecare adresă (foaie sau font) se cere o singură dată pe rulare, oricâte foi sau
     * `@font-face` o repetă. CSS-ul rescris trimite mereu la calea publicată (`/capturat/fonturi/<nume>`). Întoarce calea
     * publicată a foii, fișierul ei cu sha256-ul și fonturile (adresa, calea publicată, fișierul, sha256-ul).
     *
     * @return array{css: string, fisier_css: string, sha256_css: string, fonturi: list<array{url: string, cale: string, fisier: string, sha256: string}>}
     */
    public function aduce(string $adresa, string $dosar): array
    {
        $url = html_entity_decode(trim($adresa), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = str_starts_with($url, '//') ? 'https:' . $url : $url;
        if ($this->doarHttps) {
            $url = (string) preg_replace('#^http://#i', 'https://', $url);
        }
        if ($this->doarHttps && !str_starts_with(strtolower($url), 'https://')) {
            throw new EroareCaptura('gazda', 'foaia de fonturi se cere doar prin https: ' . $url);
        }
        if (isset($this->foi[$url])) {
            return $this->foi[$url];
        }
        $r = $this->cereri->get($url, 'chrome-win', 'text/css,*/*;q=0.1');
        if ($r->status !== 200) {
            throw new EroareCaptura('retea', 'foaia de fonturi ' . $url . ' a răspuns ' . $r->status);
        }
        if ($r->tip() !== 'text/css') {
            throw new EroareCaptura('retea', 'foaia de fonturi ' . $url . ' nu e text/css (' . ($r->tip() !== '' ? $r->tip() : 'fără tip') . ')');
        }
        $dosar = rtrim($dosar, '/');
        if (!is_dir($dosar) && !@mkdir($dosar, 0755, true) && !is_dir($dosar)) {
            throw new EroareCaptura('scriere', 'nu pot face ' . $dosar);
        }
        $fonturi = [];
        $vazute = [];
        $nou = (string) preg_replace_callback('/url\(\s*([\'"]?)([^)\'"]+)\1\s*\)/i', function (array $m) use (&$fonturi, &$vazute, $dosar): string {
            $u = trim($m[2]);
            $problema = self::problemaFont($u, $this->gazdaFonturi, $this->doarHttps);
            if ($problema !== null) {
                throw new EroareCaptura('gazda', $problema);
            }
            if (!isset($this->aduse[$u])) {
                $ext = strtolower(pathinfo((string) parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION));
                $f = $this->cereri->get($u, 'chrome-win', '*/*');
                if ($f->status !== 200) {
                    throw new EroareCaptura('retea', 'fontul ' . $u . ' a răspuns ' . $f->status);
                }
                if (!in_array($f->tip(), self::TIPURI_FONT[$ext] ?? [], true)) {
                    throw new EroareCaptura('retea', 'fontul ' . $u . ' are tipul ' . ($f->tip() !== '' ? $f->tip() : '(niciunul)') . ', nu ' . implode(' sau ', self::TIPURI_FONT[$ext] ?? []));
                }
                $sha = hash('sha256', $f->corp);
                $this->aduse[$u] = ['nume' => substr($sha, 0, 16) . '.' . $ext, 'sha256' => $sha, 'corp' => $f->corp];
            }
            $a = $this->aduse[$u];
            if (!is_file($dosar . '/' . $a['nume']) && @file_put_contents($dosar . '/' . $a['nume'], $a['corp']) === false) {
                throw new EroareCaptura('scriere', 'nu pot scrie ' . $dosar . '/' . $a['nume']);
            }
            if (!isset($vazute[$u])) {
                $vazute[$u] = true;
                $fonturi[] = ['url' => $u, 'cale' => self::DOSAR . '/' . $a['nume'], 'fisier' => $a['nume'], 'sha256' => $a['sha256']];
            }
            return 'url(' . $m[1] . self::DOSAR . '/' . $a['nume'] . $m[1] . ')';
        }, $r->corp);
        $numeCss = self::cheie($adresa) . '.css';
        if (@file_put_contents($dosar . '/' . $numeCss, $nou) === false) {
            throw new EroareCaptura('scriere', 'nu pot scrie ' . $dosar . '/' . $numeCss);
        }
        return $this->foi[$url] = ['css' => self::cale($adresa), 'fisier_css' => $numeCss, 'sha256_css' => hash('sha256', $nou), 'fonturi' => $fonturi];
    }

    /** Câte fonturi distincte s-au cerut în această rulare (după de-duplicare). */
    public function fonturiAduse(): int
    {
        return count($this->aduse);
    }
}
