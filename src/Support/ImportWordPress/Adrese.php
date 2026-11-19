<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

/**
 * Adresele site-ului WordPress și unde ajung pe codul comun. Adresele rămân cele scrise de WordPress (corectura 1.1,
 * VC-URL-003, VC-MEDIA-001/002):
 *
 *  - o pagină importată păstrează calea WordPress, cu „/” final cum îl pune structura permalinkurilor (`/camere/`;
 *    prima pagină e `/`); slug-urile Unicode rămân în forma percent-encoded scrisă de WordPress (`post_name`);
 *  - fișierele din `wp-content/uploads/` rămân la `/wp-content/uploads/<cale>`: `rescrie()` nu le atinge deloc (nici
 *    domeniul), iar `media()` dă aceeași adresă;
 *  - `?page_id=` și `?p=` pe prima pagină devin calea paginii; adresele întregi ale site-ului (cu sau fără www, http
 *    sau https) devin căi relative. Adresele altor site-uri rămân cum sunt.
 *
 * `cheie()` e doar pentru comparații (decodată, cu litere mici, fără „/” final, ca SeoRedirects::cheie); calea scrisă
 * păstrează forma WordPress.
 */
final class Adrese
{
    /** Dosarul fișierelor WordPress în docroot (aceeași cale ca pe site-ul vechi). */
    public const UPLOADS = '/wp-content/uploads/';

    /** @var array<string, string> calea veche (cheie()) → calea nouă, în forma WordPress */
    private array $cai = [];
    /** @var array<int, string> id-ul postării → calea nouă */
    private array $postari = [];
    /** @var list<string> gazdele site-ului (exemplu.ro, www.exemplu.ro) */
    private array $gazde = [];

    public function __construct(string $urlSite)
    {
        $gazda = strtolower((string) parse_url($urlSite, PHP_URL_HOST));
        if ($gazda !== '') {
            $faraWww = preg_replace('/^www\./', '', $gazda);
            $this->gazde = array_values(array_unique([$gazda, (string) $faraWww, 'www.' . $faraWww]));
        }
    }

    /** Cheia unei căi, pentru comparații: decodată, cu litere mici, fără „/” la sfârșit (ca SeoRedirects::cheie). */
    public static function cheie(string $cale): string
    {
        $cale = rawurldecode((string) (parse_url($cale, PHP_URL_PATH) ?? $cale));
        $cale = '/' . trim($cale, '/');
        return mb_strtolower($cale);
    }

    /**
     * Calea unei pagini în forma WordPress: segmentele cum le scrie WordPress în `post_name` (percent-encoded, cu
     * litere mici, deci neatinse), cu „/” final dacă structura permalinkurilor are unul. Un segment Unicode decodat
     * se codează ca WordPress (utf8_uri_encode: hexa cu litere mici).
     *
     * @param list<string> $segmente
     */
    public static function caleWordPress(array $segmente, bool $slashFinal = true): string
    {
        $parti = array_values(array_filter(array_map(static fn (string $s): string => self::segmentWordPress($s), $segmente),
            static fn (string $s): bool => $s !== ''));
        if ($parti === []) {
            return '/';
        }
        return '/' . implode('/', $parti) . ($slashFinal ? '/' : '');
    }

    /** Un segment de slug în forma WordPress: neatins dacă e deja codat (sau ASCII), altfel codat cu hexa mic. */
    public static function segmentWordPress(string $segment): string
    {
        if (preg_match('/^[\x21-\x7e]*$/', $segment) === 1) {
            return $segment;
        }
        return (string) preg_replace_callback('/[^\x21-\x7e]/', static fn (array $m): string => strtolower(rawurlencode($m[0])), $segment);
    }

    public function adaugaPostare(int $id, string $caleVeche, string $caleNoua): void
    {
        $this->postari[$id] = $caleNoua;
        $this->cai[self::cheie($caleVeche)] = $caleNoua;
    }

    public function adaugaCale(string $caleVeche, string $caleNoua): void
    {
        $this->cai[self::cheie($caleVeche)] = $caleNoua;
    }

    public function postare(int $id): ?string
    {
        return $this->postari[$id] ?? null;
    }

    /** Calea nouă a unei căi vechi a site-ului, sau null dacă nu e una știută. */
    public function cale(string $caleVeche): ?string
    {
        return $this->cai[self::cheie($caleVeche)] ?? null;
    }

    /** Adresa e a site-ului (relativă sau pe una dintre gazdele lui)? */
    public function eASite(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || preg_match('/^(mailto|tel|sms|javascript|data):/i', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $this->gazde, true);
        }
        return str_starts_with($url, '/') || str_starts_with($url, '?');
    }

    /** Adresa (a site-ului) e a unui fișier din wp-content/uploads? */
    public function eMedia(string $url): bool
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$this->eASite($url)) {
            return false;
        }
        $cale = (string) (parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url, PHP_URL_PATH) ?? '');
        return str_starts_with($cale, self::UPLOADS);
    }

    /**
     * Adresa nouă pentru o adresă din conținutul vechi (un link, un src, un url() din CSS). Adresele altor site-uri,
     * ancorele, mailto:, tel: și fișierele din wp-content/uploads rămân exact cum sunt. Parametrii și ancora se păstrează
     * (afară de ?page_id=, ?p=, care devin calea paginii).
     */
    public function rescrie(string $url): string
    {
        $original = $url;
        if ($this->eMedia($url)) {
            return $original;
        }
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$this->eASite($url)) {
            return $original;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        $parti = parse_url($url);
        if (!is_array($parti)) {
            return $original;
        }
        $cale = (string) ($parti['path'] ?? '/');
        $cale = $cale === '' ? '/' : $cale;
        $query = (string) ($parti['query'] ?? '');
        $ancora = isset($parti['fragment']) ? '#' . $parti['fragment'] : '';
        // ?page_id=, ?p= pe prima pagină.
        if (rtrim($cale, '/') === '' && $query !== '') {
            parse_str($query, $q);
            foreach (['page_id', 'p'] as $k) {
                if (isset($q[$k]) && ctype_digit((string) $q[$k]) && ($nou = $this->postare((int) $q[$k])) !== null) {
                    return $nou . $ancora;
                }
            }
        }
        $nou = $this->cale($cale);
        if ($nou !== null) {
            return $nou . ($query !== '' ? '?' . $query : '') . $ancora;
        }
        // O cale a site-ului pe care n-o știm: doar fără domeniu.
        return $cale . ($query !== '' ? '?' . $query : '') . $ancora;
    }

    /** Calea relativă la uploads/ a unei adrese din wp-content/uploads (pentru lista fișierelor de copiat), sau null. */
    public function fisierUploads(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$this->eASite($url)) {
            return null;
        }
        $cale = (string) (parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url, PHP_URL_PATH) ?? '');
        if (preg_match('#^/wp-content/uploads/(.+)$#', $cale, $m) !== 1) {
            return null;
        }
        $rel = rawurldecode($m[1]);
        return str_contains($rel, '..') || str_contains($rel, "\0") ? null : $rel;
    }

    /** Adresa unui fișier din uploads/ al WordPress (relativ): aceeași ca pe site-ul vechi, `/wp-content/uploads/<rel>`. */
    public static function media(string $rel): string
    {
        return rtrim(self::UPLOADS, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($rel, '/'))));
    }

    /** @return array<string, string> toate căile vechi știute (cheie → calea nouă) */
    public function toate(): array
    {
        return $this->cai;
    }
}
