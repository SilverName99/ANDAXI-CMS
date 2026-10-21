<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Transformă un link de video în HTML gata de afișat.
 *
 * Acceptă linkuri YouTube și Vimeo (cele pe care le folosește toată lumea),
 * dar și fișiere .mp4/.webm/.ogg urcate în Galerie. Un link pe care nu îl
 * recunoaște nu produce nimic — mai bine lipsește videoul decât să rămână
 * o casetă neagră pe pagină.
 *
 * Playerul YouTube sau Vimeo e conținut al altcuiva, care poate urmări vizitatorul
 * (Vimeo pune cookie-uri fără `dnt=1`). Ca analiza și chatul (CookieConsent), el se
 * încarcă singur doar după ce vizitatorul a acceptat toate cookie-urile; altfel, în
 * locul lui stă o casetă de aceeași mărime cu „Pornește videoclipul”: playerul se
 * încarcă abia la apăsare (fără JavaScript, linkul deschide videoul pe YouTube sau
 * Vimeo). Playerul Vimeo are mereu `dnt=1` (fără cookie-uri de urmărire).
 *
 * Din vertij `0fc2b2c` (20 oct 2026), plus acordul pentru cookie-uri și `dnt=1`
 * (site-uri/vertij/ce-se-schimba.md). Pluginul blog (postările și evenimentul
 * următor, NextEvent).
 */
final class VideoEmbed
{
    /** Extensiile pe care browserul le poate reda direct cu <video>. */
    private const FILE_TYPES = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'ogg' => 'video/ogg',
    ];

    /**
     * @param bool|null $cuAcord playerul YouTube/Vimeo se încarcă singur? null = vizitatorul a acceptat toate
     *                           cookie-urile (CookieConsent::permiteUrmarire); altfel caseta „Pornește videoclipul”
     */
    public static function render(string $url, ?bool $cuAcord = null): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $youtubeId = self::youtubeId($url);
        if ($youtubeId !== '') {
            $start = self::startSeconds($url);
            $src = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($youtubeId)
                . ($start > 0 ? '?start=' . $start : '');
            $link = 'https://www.youtube.com/watch?v=' . rawurlencode($youtubeId) . ($start > 0 ? '&t=' . $start . 's' : '');

            return ($cuAcord ?? CookieConsent::permiteUrmarire())
                ? self::iframe($src, 'Videoclip YouTube')
                : self::faraAcord($src, $link, 'YouTube', 'Videoclip YouTube');
        }

        $vimeoId = self::vimeoId($url);
        if ($vimeoId !== '') {
            $src = 'https://player.vimeo.com/video/' . rawurlencode($vimeoId) . '?dnt=1';

            return ($cuAcord ?? CookieConsent::permiteUrmarire())
                ? self::iframe($src, 'Videoclip Vimeo')
                : self::faraAcord($src, 'https://vimeo.com/' . rawurlencode($vimeoId), 'Vimeo', 'Videoclip Vimeo');
        }

        $mime = self::fileMime($url);
        if ($mime !== '') {
            return '<video controls preload="metadata"'
                . ' style="width:100%;height:auto;border-radius:14px;display:block;">'
                . '<source src="' . htmlspecialchars($url, ENT_QUOTES) . '" type="' . $mime . '">'
                . 'Browserul tău nu suportă redarea video.'
                . '</video>';
        }

        return '';
    }

    /** Are linkul o formă pe care o putem afișa? Folosit pentru mesajele din admin. */
    public static function isSupported(string $url): bool
    {
        return self::render($url) !== '';
    }

    /** Raport pe 16:9, fără CSS extern, ca să meargă și în template-uri scrise de mână. */
    private static function iframe(string $src, string $title): string
    {
        return '<div style="position:relative;width:100%;padding-top:56.25%;border-radius:14px;overflow:hidden;background:#15202b;">'
            . '<iframe src="' . htmlspecialchars($src, ENT_QUOTES) . '"'
            . ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"'
            . ' style="position:absolute;inset:0;width:100%;height:100%;border:0;"'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"'
            . ' referrerpolicy="strict-origin-when-cross-origin"'
            . ' allowfullscreen loading="lazy"></iframe>'
            . '</div>';
    }

    /**
     * Fără acordul vizitatorului: o casetă de mărimea playerului, cu un link spre video. La apăsare (cu
     * JavaScript) linkul se înlocuiește cu playerul, pornit; fără JavaScript deschide videoul la furnizor.
     */
    private static function faraAcord(string $src, string $link, string $furnizor, string $title): string
    {
        $porneste = "var f=document.createElement('iframe');f.src=this.getAttribute('data-video-player');"
            . "f.title=this.getAttribute('data-video-titlu');"
            . "f.setAttribute('style','position:absolute;inset:0;width:100%;height:100%;border:0;');"
            . "f.setAttribute('allow','accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');"
            . "f.setAttribute('referrerpolicy','strict-origin-when-cross-origin');f.setAttribute('allowfullscreen','');"
            . 'this.parentNode.replaceChild(f,this);return false;';

        return '<div style="position:relative;width:100%;padding-top:56.25%;border-radius:14px;overflow:hidden;background:#15202b;">'
            . '<a href="' . htmlspecialchars($link, ENT_QUOTES) . '" target="_blank" rel="noopener"'
            . ' data-video-player="' . htmlspecialchars($src . (str_contains($src, '?') ? '&' : '?') . 'autoplay=1', ENT_QUOTES) . '"'
            . ' data-video-titlu="' . htmlspecialchars($title, ENT_QUOTES) . '"'
            . ' onclick="' . htmlspecialchars($porneste, ENT_QUOTES) . '"'
            . ' style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;'
            . 'padding:18px;color:#ffffff;text-decoration:none;text-align:center;font-size:15px;line-height:1.5;">'
            . '<span aria-hidden="true" style="display:flex;align-items:center;justify-content:center;width:64px;height:64px;'
            . 'border-radius:50%;background:rgba(255,255,255,.18);font-size:26px;">&#9654;</span>'
            . '<span><strong>Pornește videoclipul</strong><br>Playerul ' . $furnizor . ' se încarcă doar când apeși; '
            . $furnizor . ' poate folosi cookie-uri.</span>'
            . '</a>'
            . '</div>';
    }

    private static function youtubeId(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host === 'youtu.be') {
            $id = trim((string) parse_url($url, PHP_URL_PATH), '/');
            return self::cleanId($id);
        }

        if ($host !== 'youtube.com' && $host !== 'youtube-nocookie.com' && $host !== 'm.youtube.com') {
            return '';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach (['/embed/', '/shorts/', '/v/', '/live/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return self::cleanId(substr($path, strlen($prefix)));
            }
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return self::cleanId((string) ($query['v'] ?? ''));
    }

    private static function vimeoId(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host !== 'vimeo.com' && $host !== 'player.vimeo.com') {
            return '';
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = preg_replace('#^video/#', '', $path) ?? $path;
        // Linkurile de tip vimeo.com/123456789/abcdef1234 au și un hash după id.
        $first = explode('/', $path)[0] ?? '';

        return preg_match('/^\d+$/', $first) === 1 ? $first : '';
    }

    /** Momentul de start, din ?t=90, ?t=1m30s sau ?start=90. */
    private static function startSeconds(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $raw = trim((string) ($query['start'] ?? $query['t'] ?? ''));
        if ($raw === '') {
            return 0;
        }

        if (preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i', $raw, $m) === 1) {
            return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + ((int) ($m[3] ?? 0));
        }

        return 0;
    }

    private static function fileMime(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return self::FILE_TYPES[$extension] ?? '';
    }

    private static function cleanId(string $id): string
    {
        $id = explode('?', $id)[0] ?? '';
        $id = explode('/', $id)[0] ?? '';
        $id = trim($id);

        return preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) === 1 ? $id : '';
    }
}
