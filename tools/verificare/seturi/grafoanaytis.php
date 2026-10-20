<?php

declare(strict_types=1);

/**
 * Setul grafoanaytis (WP-10; GR-24 din planul Etapei 1): G1 (codul) și G3, G4, G6 sunt
 * în cele comune (seturi/comun.php), la fel numerele din G2 care privesc orice site
 * (conturi, comenzi, recenzii, newsletter, blog, acorduri GDPR, produse, galerie, paginile
 * publicate și tokenurile lor). Aici: cererile de ofertă, ordinea manuală a produselor (G2)
 * și potrivirea conținutului din bază cu pachetul din codul site-ului (G5): paginile din
 * database/pagini/ și CSS-ul antetului din database/design/antet.css, comparate prin
 * amprentă (DB = SHA2 calculat de server, fișierul = sha256 local), fără să se citească
 * vreun conținut; celelalte chei design_* doar ca amprentă scurtă.
 *
 * OD-44 (19 oct): site-ul nou e deja pe grafoanaytis.ro, deci G1 nu mai întreabă de WordPress.
 */

use Unelte\Verificare\Context;

$comun = require __DIR__ . '/comun.php';

return [
    'site' => 'grafoanaytis',
    'verificari' => array_merge($comun['grupuri'], [
        [
            'cheie' => 'g2',
            'titlu' => 'G2: cererile de ofertă și ordinea manuală a produselor',
            'ruleaza' => static function (Context $c): void {
                if ($c->cere('g2.cereri_oferta', ['quote_requests'])) {
                    $sters = $c->are(['quote_requests.deleted_at']);
                    $c->numar('g2.cereri_oferta.total', [], 'SELECT COUNT(*) FROM quote_requests' . ($sters ? ' WHERE deleted_at IS NULL' : '') . ' LIMIT 1');
                    if ($sters) {
                        $c->numar('g2.cereri_oferta.sterse', [], 'SELECT COUNT(*) FROM quote_requests WHERE deleted_at IS NOT NULL LIMIT 1');
                    }
                    $c->grupuri('g2.cereri_oferta.stare', ['quote_requests.status'], 'SELECT status AS g, COUNT(*) AS n FROM quote_requests'
                        . ($sters ? ' WHERE deleted_at IS NULL' : '') . ' GROUP BY status LIMIT 50');
                    $c->numar('g2.cereri_oferta.365_zile', ['quote_requests.created_at'], 'SELECT COUNT(*) FROM quote_requests WHERE created_at >= NOW() - INTERVAL 365 DAY LIMIT 1');
                }
                $c->numar('g2.produse_cu_ordine_manuala', ['products.sort_order'], 'SELECT COUNT(*) FROM products WHERE sort_order > 0'
                    . ($c->are(['products.deleted_at']) ? ' AND deleted_at IS NULL' : '') . ' LIMIT 1', [], 'products.sort_order > 0 (OD-10)');
            },
        ],
        [
            'cheie' => 'g5',
            'titlu' => 'G5: conținutul din bază față de pachetul din codul site-ului (egal / diferit / lipsă, prin amprentă)',
            'ruleaza' => static function (Context $c): void {
                $fisiere = $c->site->numeFisiere('database/pagini', '/^[a-z0-9_-]{1,80}\.html$/');
                if ($fisiere === null) {
                    $c->raport->alegere('g5.pagini', 'dosarul lipsește', ['dosarul lipsește'], 'database/pagini în codul site-ului');
                } elseif ($c->cere('g5.pagini', ['pages.slug', 'pages.html_content', 'pages.css_content', 'pages.js_content'])) {
                    $n = $c->are(['pages.deleted_at']) ? 'deleted_at IS NULL AND ' : '';
                    $gol = hash('sha256', '');
                    foreach ($fisiere as $fisier) {
                        $nume = substr($fisier, 0, -5);
                        $slug = str_replace('__', '/', $nume);
                        // Pagina de start: aceleași rânduri ca scripts/seed-pagini.php din GR.
                        $randuri = $nume === 'acasa'
                            ? $c->citire->randuri('SELECT SHA2(COALESCE(html_content, \'\'), 256) AS h, SHA2(COALESCE(css_content, \'\'), 256) AS c,
                                SHA2(COALESCE(js_content, \'\'), 256) AS j FROM pages WHERE ' . $n . "(slug = '' OR LOWER(slug) IN ('acasa', 'home')) LIMIT 10")
                            : $c->citire->randuri('SELECT SHA2(COALESCE(html_content, \'\'), 256) AS h, SHA2(COALESCE(css_content, \'\'), 256) AS c,
                                SHA2(COALESCE(js_content, \'\'), 256) AS j FROM pages WHERE ' . $n . 'slug = ? LIMIT 10', [$slug]);
                        foreach (['h' => 'html', 'c' => 'css', 'j' => 'js'] as $coloana => $ext) {
                            $amprenta = $c->site->amprentaFisier('database/pagini/' . $nume . '.' . $ext) ?? $gol;
                            $stare = $randuri === [] ? 'lipsă din bază' : 'egal';
                            foreach ($randuri as $r) {
                                if (strtolower((string) $r[$coloana]) !== $amprenta) {
                                    $stare = 'diferit';
                                }
                            }
                            $c->raport->alegere('g5.pagina.' . $nume . '.' . $ext, $stare, ['egal', 'diferit', 'lipsă din bază']);
                        }
                    }
                }
                if ($c->cere('g5.design', ['settings.key', 'settings.value'])) {
                    $antet = $c->site->amprentaFisier('database/design/antet.css');
                    $baza = $c->citire->valoare("SELECT SHA2(COALESCE(`value`, ''), 256) FROM settings WHERE `key` = 'design_header_css' LIMIT 1");
                    $c->raport->alegere('g5.design.design_header_css', $antet === null ? 'fișierul lipsește' : ($baza === null ? 'lipsă din bază'
                        : (strtolower((string) $baza) === $antet ? 'egal' : 'diferit')), ['egal', 'diferit', 'lipsă din bază', 'fișierul lipsește'],
                        'față de database/design/antet.css');
                    $chei = ['design_header_html', 'design_menu_html', 'design_footer_html', 'design_header_js', 'design_menu_css', 'design_footer_css'];
                    foreach ($c->citire->randuri('SELECT `key` AS k, LEFT(SHA2(COALESCE(`value`, \'\'), 256), 16) AS h FROM settings WHERE `key` IN ('
                        . implode(', ', array_fill(0, count($chei), '?')) . ') ORDER BY `key` LIMIT 20', $chei) as $r) {
                        $c->raport->nume('g5.design.' . (string) $r['k'], strtolower((string) $r['h']), 'hex', 'amprentă scurtă (o comparăm cu pachetul, Etapa 4)');
                    }
                }
            },
        ],
    ], $comun['la_final']),
];
