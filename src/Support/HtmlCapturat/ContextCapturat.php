<?php

declare(strict_types=1);

namespace App\Support\HtmlCapturat;

/**
 * Contextul în care politica HTML-ului capturat (App\Support\HtmlCapturat) verifică un document: ce i se permite din
 * pachetul site-ului și din captură, fără date de client în cod.
 *  - `iframeAlbe`: prefixele de adresă din care un `<iframe>` (sau un `data-src-cmplz` validat) are voie să se încarce.
 *    Implicit doar harta Google Maps (`https://www.google.com/maps/embed`), ca în DECIZIE §2.9; lista se lărgește doar
 *    după o revizie de om, din pachet.
 *  - `manifest`: căile locale (relative, sub docroot) ale foilor de stil pe care un `<link rel=stylesheet>` dintr-un
 *    `<noscript>` are voie să le ceară (pasul 4, manifestul capturii). Gol = niciun `<link>` în `<noscript>` (doar `<img>`
 *    local și text inert).
 *  - `schema`: schema dialectului (SchemaDivi4), adică `data-*`-urile permise și forma lor.
 */
final class ContextCapturat
{
    public const MAPS = 'https://www.google.com/maps/embed';

    /** @var list<string> */
    public readonly array $iframeAlbe;
    /** @var array<string, true> */
    private array $manifest;

    /**
     * @param list<string> $iframeAlbe prefixele de adresă permise pentru `<iframe>` (implicit harta Google Maps)
     * @param list<string> $manifest căile locale permise pentru `<link rel=stylesheet>` în `<noscript>`
     */
    public function __construct(array $iframeAlbe = [self::MAPS], array $manifest = [])
    {
        $this->iframeAlbe = $iframeAlbe;
        $this->manifest = [];
        foreach ($manifest as $cale) {
            $this->manifest['/' . ltrim($cale, '/')] = true;
        }
    }

    /**
     * Contextul unei capturi (drumul capturat, recenzia m7): foile de stil din manifestul ei (`manifest.json` → `fisiere`: căile
     * locale ale celor de fel `foaie` ori `.css`), cu lista albă dată a iframe-urilor. Un manifest lipsă sau stricat dă un context
     * fără foi (niciun `<link>` în `<noscript>`).
     *
     * @param list<string> $iframeAlbe
     */
    public static function dinManifest(?string $fisier, array $iframeAlbe = [self::MAPS]): self
    {
        $foi = [];
        $m = $fisier !== null && is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
        foreach (is_array($m) && is_array($m['fisiere'] ?? null) ? $m['fisiere'] : [] as $f) {
            $cale = is_array($f) && is_string($f['cale'] ?? null) ? $f['cale'] : '';
            if (str_starts_with($cale, '/') && !str_starts_with($cale, '//') && !str_contains($cale, '\\') && !str_contains('/' . $cale . '/', '/../')
                && (($f['fel'] ?? null) === 'foaie' || str_ends_with(strtolower($cale), '.css'))) {
                $foi[] = $cale;
            }
        }
        return new self($iframeAlbe, $foi);
    }

    /** Adresa unui `<iframe>` (ori `data-src-cmplz`) e dintr-o sursă din lista albă? */
    public function iframePermis(string $adresa): bool
    {
        $adresa = trim($adresa);
        foreach ($this->iframeAlbe as $prefix) {
            if ($adresa === $prefix || str_starts_with($adresa, $prefix . '?') || str_starts_with($adresa, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /** Calea unei foi de stil (relativă la docroot) e în manifestul capturii? */
    public function foaieInManifest(string $cale): bool
    {
        $cale = '/' . ltrim(trim($cale), '/');
        $cale = preg_replace('/[?#].*$/', '', $cale) ?? $cale;
        return isset($this->manifest[$cale]);
    }

    public function areManifest(): bool
    {
        return $this->manifest !== [];
    }
}
