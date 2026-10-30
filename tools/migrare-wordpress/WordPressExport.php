<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;

/**
 * Unealta internă `migrare_wordpress` (WP-23: B7, din Biovitality 14d19dc; o încarcă doar
 * tools/migrare-wordpress/import-wordpress-posts.php, nu e în src/, deci nu ajunge în aplicație).
 *
 * Citește un export WordPress (WXR) și îl aduce în forma pe care o folosește
 * blogul nostru. Nu atinge baza de date și nu descarcă nimic: doar citește
 * fișierul, ca partea de import să poată fi verificată separat de scriere.
 */
final class WordPressExport
{
    /** @var array<int, array<string, mixed>> */
    private array $posts = [];

    /** @var array<int, string> id atașament -> adresa fișierului */
    private array $attachments = [];

    private function __construct()
    {
    }

    /**
     * @param string $postType tipul de postare din WordPress („post”, sau unul propriu al temei, de exemplu „project” la
     *                         Biovitality)
     */
    public static function fromFile(string $path, string $postType = 'post'): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Fișierul nu există sau nu poate fi citit: {$path}");
        }

        $xmlRaw = file_get_contents($path);
        if ($xmlRaw === false || trim($xmlRaw) === '') {
            throw new RuntimeException('Fișierul de export este gol.');
        }

        $anterior = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlRaw, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($anterior);
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Fișierul nu este un export WordPress valid (XML stricat).');
        }

        $export = new self();
        foreach ($xml->channel->item ?? [] as $item) {
            $wp = $item->children('wp', true);
            $tip = trim((string) ($wp->post_type ?? ''));

            if ($tip === 'attachment') {
                $id = (int) ($wp->post_id ?? 0);
                $url = trim((string) ($wp->attachment_url ?? ''));
                if ($id > 0 && $url !== '') {
                    $export->attachments[$id] = $url;
                }
                continue;
            }

            if ($tip !== $postType) {
                continue;
            }

            $export->posts[] = $export->citestePostare($item);
        }

        return $export;
    }

    /** @return array<int, array<string, mixed>> */
    public function posts(): array
    {
        return $this->posts;
    }

    /** Adresa fișierului pentru un id de atașament din WordPress. */
    public function attachmentUrl(int $id): string
    {
        return $this->attachments[$id] ?? '';
    }

    /** @return array<int, string> */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /** @return array<string, mixed> */
    private function citestePostare(SimpleXMLElement $item): array
    {
        $wp = $item->children('wp', true);
        $dc = $item->children('dc', true);
        $content = $item->children('content', true);
        $excerpt = $item->children('excerpt', true);

        $meta = [];
        foreach ($wp->postmeta ?? [] as $postmeta) {
            $cheie = trim((string) ($postmeta->meta_key ?? ''));
            if ($cheie !== '') {
                $meta[$cheie] = (string) ($postmeta->meta_value ?? '');
            }
        }

        $categorii = [];
        $etichete = [];
        foreach ($item->category ?? [] as $termen) {
            $domeniu = trim((string) ($termen['domain'] ?? ''));
            $nume = trim((string) $termen);
            if ($nume === '') {
                continue;
            }
            // Exportul poate veni de la orice temă, cu taxonomii proprii;
            // ne uităm la sufix, nu la numele exact al taxonomiei.
            if (str_ends_with($domeniu, '_tag') || $domeniu === 'post_tag') {
                $etichete[] = $nume;
            } elseif (str_ends_with($domeniu, 'category')) {
                $categorii[] = $nume;
            }
        }

        $status = trim((string) ($wp->status ?? 'draft'));
        $dataPostare = trim((string) ($wp->post_date ?? ''));
        if ($dataPostare === '' || str_starts_with($dataPostare, '0000')) {
            $dataPostare = trim((string) ($wp->post_date_gmt ?? ''));
        }
        if ($dataPostare === '' || str_starts_with($dataPostare, '0000')) {
            $dataPostare = date('Y-m-d H:i:s');
        }

        return [
            'wp_id' => (int) ($wp->post_id ?? 0),
            'title' => trim((string) ($item->title ?? '')),
            'slug' => trim((string) ($wp->post_name ?? '')),
            'content' => (string) ($content->encoded ?? ''),
            'excerpt' => trim((string) ($excerpt->encoded ?? '')),
            'published_at' => $dataPostare,
            'is_published' => $status === 'publish',
            'status' => $status,
            'author' => trim((string) ($dc->creator ?? '')),
            'categories' => array_values(array_unique($categorii)),
            'tags' => array_values(array_unique($etichete)),
            // Bibliografia din tema veche. Postările o aveau într-un câmp
            // separat, exact ca la noi acum.
            'references_html' => trim((string) ($meta['surse'] ?? '')),
            'thumbnail_id' => (int) ($meta['_thumbnail_id'] ?? 0),
            'seo_title' => self::curataSeo((string) ($meta['_aioseo_title'] ?? ''), (string) ($item->title ?? '')),
            'seo_description' => self::curataSeo((string) ($meta['_aioseo_description'] ?? ''), ''),
        ];
    }

    /**
     * Câmpurile SEO din AIOSEO pot fi doar șabloane („#post_title
     * #separator_sa"), nu text scris de om. Șablonul se completează cu titlul
     * postării; dacă iese chiar titlul, nu-l mai salvăm — site-ul îl folosește
     * oricum ca variantă implicită.
     */
    private static function curataSeo(string $valoare, string $titluPostare): string
    {
        $valoare = trim($valoare);
        if ($valoare === '' || !str_contains($valoare, '#')) {
            return $valoare;
        }

        $valoare = str_replace(['#post_title', '#page_title', '#taxonomy_title'], $titluPostare, $valoare);
        // Restul șabloanelor (nume de site, separator, dată) n-au echivalent
        // aici; le scoatem în loc să lăsăm „#separator_sa" pe pagină.
        $valoare = (string) preg_replace('/#[a-z_]+/i', '', $valoare);
        $valoare = trim((string) preg_replace('/\s{2,}/', ' ', $valoare), " \t\n\r\0\x0B-|·—–");

        return $valoare !== '' && $valoare !== trim($titluPostare) ? $valoare : '';
    }
}
