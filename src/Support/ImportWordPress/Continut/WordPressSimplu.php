<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

use App\Support\ImportWordPress\Adrese;
use App\Support\ImportWordPress\Context;

/**
 * Conținutul WordPress fără constructor de pagini: editorul de blocuri (Gutenberg) și cel clasic. Comentariile blocurilor
 * se scot, textul clasic primește paragrafele (ca wpautop), shortcodurile nucleului WordPress devin HTML ([caption],
 * [gallery], [embed], [video], [audio]), adresele se rescriu (Rescriere), iar scripturile se scot (spuse ca ATENȚIE).
 * Un shortcode necunoscut (al unui plugin) e o problemă: ar apărea ca text în pagină.
 */
final class WordPressSimplu implements Convertor
{
    public function nume(): string
    {
        return 'WordPress (blocuri sau editorul clasic)';
    }

    public function stie(string $constructor): bool
    {
        return in_array($constructor, ['gutenberg', 'clasic'], true);
    }

    public function design(Context $ctx): array
    {
        return [];
    }

    public function converteste(array $pagina, Context $ctx): array
    {
        $probleme = [];
        $avertismente = [];
        $html = (string) $pagina['continut'];
        if ($pagina['constructor'] === 'gutenberg') {
            $html = (string) preg_replace('/<!--\s*\/?wp:[^>]*?-->\s*/s', '', $html);
        } else {
            $html = self::autop($html);
        }
        $html = $this->shortcoduri($html, $ctx, $probleme);
        if (preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $html, $scripturi) > 0) {
            $avertismente[] = count($scripturi[0]) . ' script(uri) din conținut scoase (codul comun nu rulează scripturi din textul paginilor)';
            $html = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        }
        if (preg_match('/<iframe\b/i', $html) === 1) {
            $avertismente[] = 'pagina are un iframe (o hartă, un film): se încarcă la fel ca înainte';
        }
        return ['html' => trim(Rescriere::html($html, $ctx)), 'css' => '', 'js' => '', 'probleme' => $probleme, 'avertismente' => $avertismente];
    }

    /** @param list<string> $probleme */
    private function shortcoduri(string $html, Context $ctx, array &$probleme): string
    {
        $rez = '';
        foreach (Shortcoduri::desface($html) as $nod) {
            if (is_string($nod)) {
                $rez .= $nod;
                continue;
            }
            $a = $nod['atribute'];
            switch ($nod['nume']) {
                case 'caption':
                case 'wp_caption':
                    $interior = (string) $nod['continut'];
                    $legenda = '';
                    if (preg_match('/^(.*?<\/a>|.*?<img[^>]*>)(.*)$/is', trim($interior), $m) === 1) {
                        $interior = $m[1];
                        $legenda = trim($m[2]);
                    }
                    $legenda = $legenda !== '' ? $legenda : (string) ($a['caption'] ?? '');
                    $rez .= '<figure class="wp-caption ' . htmlspecialchars((string) ($a['align'] ?? ''), ENT_QUOTES) . '"'
                        . (isset($a['width']) && ctype_digit((string) $a['width']) ? ' style="max-width:' . (int) $a['width'] . 'px"' : '') . '>'
                        . $interior . ($legenda !== '' ? '<figcaption>' . $legenda . '</figcaption>' : '') . '</figure>';
                    break;
                case 'gallery':
                    $rez .= $this->galerie($a, $ctx, $probleme);
                    break;
                case 'embed':
                    $url = trim((string) ($nod['continut'] ?? ''));
                    $rez .= '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($url, ENT_QUOTES) . '</a></p>';
                    break;
                case 'video':
                case 'audio':
                    $src = '';
                    foreach (['src', 'mp4', 'webm', 'mp3', 'm4a', 'ogg', 'wav'] as $k) {
                        if ($src === '' && trim((string) ($a[$k] ?? '')) !== '') {
                            $src = trim((string) $a[$k]);
                        }
                    }
                    $rez .= '<' . $nod['nume'] . ' controls preload="metadata" src="' . htmlspecialchars($src, ENT_QUOTES) . '"'
                        . ($nod['nume'] === 'video' && trim((string) ($a['poster'] ?? '')) !== '' ? ' poster="' . htmlspecialchars((string) $a['poster'], ENT_QUOTES) . '"' : '')
                        . '></' . $nod['nume'] . '>';
                    break;
                default:
                    $probleme[] = 'shortcode necunoscut [' . $nod['nume'] . '] (al unui plugin): ar apărea ca text în pagină';
                    $rez .= $nod['brut'];
            }
        }
        return $rez;
    }

    /** [gallery ids="1,2,3"]: o grilă de poze, fiecare cu linkul spre poza mare. @param list<string> $probleme */
    private function galerie(array $a, Context $ctx, array &$probleme): string
    {
        $iduri = array_values(array_filter(array_map('intval', explode(',', (string) ($a['ids'] ?? $a['include'] ?? '')))));
        if ($iduri === []) {
            $probleme[] = '[gallery] fără ids (galeria pozelor atașate paginii) nu se poate reface';
            return '';
        }
        $coloane = max(1, min(9, (int) ($a['columns'] ?? 3)));
        $html = '<div class="wp-galerie wp-galerie-' . $coloane . '">';
        foreach ($iduri as $id) {
            $at = $ctx->inventar->atasamente[$id] ?? null;
            if ($at === null || $at['fisier'] === '') {
                continue;
            }
            $ctx->fisier($at['fisier']);
            $mica = $at['marimi']['medium']['fisier'] ?? ($at['marimi']['thumbnail']['fisier'] ?? $at['fisier']);
            $ctx->fisier($mica);
            $html .= '<figure><a href="' . Adrese::media($at['fisier']) . '"><img src="' . Adrese::media($mica) . '" alt="'
                . htmlspecialchars($at['alt'], ENT_QUOTES) . '" loading="lazy"></a>'
                . (trim($at['legenda']) !== '' ? '<figcaption>' . htmlspecialchars(trim($at['legenda']), ENT_QUOTES) . '</figcaption>' : '') . '</figure>';
        }
        return $html . '</div>';
    }

    /** Paragrafele textului clasic (wpautop, simplificat): două rânduri noi = paragraf, unul = <br>, fără să atingă blocurile. */
    public static function autop(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (trim($text) === '') {
            return '';
        }
        $blocuri = 'table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr'
            . '|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary|iframe|video|audio|script';
        // Păstrăm <pre> neatins.
        $pre = [];
        $text = (string) preg_replace_callback('/<pre\b.*?<\/pre>/is', static function (array $m) use (&$pre): string {
            $pre[] = $m[0];
            return '<pre wp-pre-tag-' . (count($pre) - 1) . '></pre>';
        }, $text);
        // Shortcodurile-bloc ([caption], [gallery], [embed], [video], [audio]) stau singure, ca la shortcode_unautop.
        $text = (string) preg_replace('/(\[(caption|wp_caption|embed|video|audio)\b.*?\[\/\2\]|\[(gallery|video|audio)\b[^\]]*\])/s', "\n\n$1\n\n", $text);
        $text = (string) preg_replace('!(<(?:' . $blocuri . ')[\s/>])!', "\n\n$1", $text);
        $text = (string) preg_replace('!(</(?:' . $blocuri . ')>)!', "$1\n\n", $text);
        $paragrafe = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rez = '';
        foreach ($paragrafe as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (preg_match('!^</?(?:' . $blocuri . ')[\s/>]!', $p) === 1 || preg_match('/^\[(caption|wp_caption|embed|video|audio|gallery)\b.*\]$/s', $p) === 1) {
                $rez .= $p . "\n";
            } else {
                $rez .= '<p>' . str_replace("\n", "<br>\n", $p) . "</p>\n";
            }
        }
        foreach ($pre as $i => $bloc) {
            $rez = str_replace('<pre wp-pre-tag-' . $i . '></pre>', $bloc, $rez);
        }
        return $rez;
    }
}
