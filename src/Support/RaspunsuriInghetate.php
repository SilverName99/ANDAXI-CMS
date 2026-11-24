<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\ImportWordPress\ScriereCapturata;
use PDO;

/**
 * Răspunsurile înghețate ale unui site mutat de pe WordPress (adoptarea WordPress, pasul 9a; INSTRUCTIUNI-BUILDER-C2 §9a):
 * robots.txt, sitemap-urile și XSL-ul lor, feed-urile, oEmbed, redirecționările capturate (tabelul `raspunsuri_inghetate`, scris de
 * import din captură). Pe cadrul capturat (`tema.json` → `cadru` = `capturat`), o cerere GET sau HEAD a cărei metodă, cale și
 * query sunt exact ale unui răspuns capturat primește acel răspuns, înaintea stratului de adrese WordPress, a ghicirii și a lui
 * 404 (public/index.php): statusul, corpul (octet cu octet) și doar antetele din lista albă a importului (tipul conținutului,
 * cache-ul, `expires`, `x-robots-tag`, `location` relativ). Codarea (`content-encoding`), lungimea și antetele hop-by-hop nu se
 * reiau niciodată: le face serverul nostru pentru corpul pe care îl trimite.
 *
 * Fără cadrul capturat sau fără tabel nu face nimic.
 */
final class RaspunsuriInghetate
{
    /** Antetele unui răspuns înghețat care se trimit (ca PlanCapturat::ANTETE, plus Location). */
    public const ANTETE = ['content-type', 'cache-control', 'expires', 'x-robots-tag', 'location'];

    /** Răspunsul înghețat al cererii, dacă e unul. Întoarce dacă a răspuns. */
    public static function intercepteaza(?PDO $db, string $metoda, string $uri): bool
    {
        $metoda = strtoupper($metoda);
        if (!$db instanceof PDO || Tema::cadru() !== 'capturat' || !in_array($metoda, ['GET', 'HEAD'], true)) {
            return false;
        }
        // N6 (docs/SECURITATE.md, „Adresele din care parse_url nu scoate o cale”): o adresă din care parse_url nu scoate o cale
        // („//cos?feed=…”, pe care stratul SEO n-o repară) nu e „/”: nu primește răspunsul înghețat al lui „/?feed=…”, ci 404-ul.
        $cale = \App\Http\Router::caleCerere($uri);
        if ($cale === null) {
            return false;
        }
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        try {
            $q = $db->prepare('SELECT status, antete, corp FROM raspunsuri_inghetate WHERE cheie = ?');
            $q->execute([ScriereCapturata::cheieRaspuns('GET', $cale, $query)]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($r) || self::coliziune($cale, $query) !== null) {
            return false; // nimic peste CMS, nici la servire (recenzia 2, R2-1)
        }
        // Niciun cookie, nicio sesiune (recenzia 2, R2-5): răspunsul e al capturii, nu al unui vizitator.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        header_remove('Set-Cookie');
        CspSite::trimite($uri, true);
        http_response_code((int) $r['status']);
        $antete = json_decode((string) $r['antete'], true);
        foreach (is_array($antete) ? $antete : [] as $a) {
            if (!is_array($a) || count($a) !== 2 || !is_string($a[0]) || !is_string($a[1]) || preg_match('/[\r\n\0]/', $a[1]) === 1) {
                continue;
            }
            $n = strtolower($a[0]);
            if (!in_array($n, self::ANTETE, true)) {
                continue;
            }
            // Location doar strict relativ la site (recenzia 2, R2-4); altceva nu se trimite.
            if ($n === 'location' && !self::locatieSigura($a[1])) {
                continue;
            }
            header(implode('-', array_map('ucfirst', explode('-', $n))) . ': ' . $a[1], $n !== 'x-robots-tag');
        }
        if ($metoda === 'GET') {
            echo (string) $r['corp'];
        }
        return true;
    }

    /**
     * Un Location strict relativ (recenzia 2, R2-4): începe cu un singur „/”, nu cu „//” sau „/\”, fără „\” nicăieri și fără
     * caractere de control sau spații (TAB, CR, LF: browserul scoate TAB-ul, deci `/<TAB>/alt.example` ar ieși din site).
     */
    public static function locatieSigura(string $v): bool
    {
        return preg_match('#^/(?![/\\\\])[^\\x00-\\x20\\x7f\\\\]*$#D', $v) === 1;
    }

    /**
     * Calea unui răspuns înghețat se ciocnește cu CMS-ul (o rută a lui, alta decât pagina generală, un fișier sau un dosar din
     * docroot)? Motivul, sau null (recenzia 2, R2-1). Răspunsurile pe care captura le pune intenționat în locul rutelor
     * SEO ale nucleului (robots.txt, sitemap-urile cu XSL-ul lor, fluxurile, oEmbed) și query-urile primei pagini nu sunt ciocniri.
     */
    public static function coliziune(string $cale, string $query = ''): ?string
    {
        if (preg_match('#^/(robots\.txt|[a-z0-9_-]*sitemap[a-z0-9_-]*\.(xml|xsl)|(.+/)?feed/?|(.+/)?(rss2?|atom|rdf)/?|wp-json/oembed/1\.0/embed)$#i', $cale) === 1) {
            return null;
        }
        if ($cale === '/' && $query !== '') {
            return null;
        }
        return \App\Support\ImportWordPress\Captura\CopiereStatica::coliziuneCms($cale);
    }
}
