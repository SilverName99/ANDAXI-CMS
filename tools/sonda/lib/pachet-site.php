<?php

declare(strict_types=1);

/**
 * Verificările pachetului unui site pe baza lui (DB-24; planul de-Bioscem §7.5): tools/sonda/pachet-site.php --site=<site>.
 * Doar citire (garanțiile lui tools/verificare/lib/live.php), doar numere, DA/NU și stări: nicio valoare de setare, niciun
 * HTML de pagină, niciun rând. Egalitatea cu o valoare înghețată se calculează pe server (în SQL, cu valoarea ca parametru)
 * și se scrie egal / diferit / lipsa / gol; secretele (*_password, *_secret*, *_api_key, smtp_*) și valorile private
 * fără fișierul lor (privat/ nu stă în git, deci nici pe clona de verificare) doar lipsa / gol / nevid.
 *
 * Fișierul întoarce o funcție: numele site-ului → definiția setului (ca seturile din tools/verificare/seturi/), sau null
 * pentru un site fără pachet.
 *
 *  V1  fiecare cheie din setari-inghetate.json: lipsa / gol / egal / diferit (ce rânduri va scrie înghețarea)
 *  V2  pagini publicate, neșterse, cu slug gol, „acasa” sau „home” (home.php mort sau nu; C5)
 *  V3  pagini publicate „checkout/succes” (vederea lui C4)
 *  V4  antetul din bază: nevid, cu {{mobile_menu}}; logo-ul public/uploads/gallery/<site>-logo.{webp,png,jpg,svg} (A2, C1)
 *  V5  pagini publicate „404” (not-found.php)
 *  V6  articole publicate fără categorie, fără autor (D5/D6)
 *  V7  produse active fără descriere scurtă (E1)
 *  V8  produse, articole, autori cu poza de rezervă sau fără poză (O6)
 *  V9  campanii de newsletter trimise în ultimele 365 de zile (F3)
 *  V10 order_number_mode = sequential; order_number_next există (F4)
 *  V11 contact_form_recipients are cel puțin o adresă validă; order_email_from_name nevid (A4/A6)
 *  V12 pachetul site-uri/<site>/ se verifică față de pachet.json (§5.2)
 */

use App\Support\Migrari\Inghetare;
use App\Support\PachetSite;
use Unelte\Verificare\Context;

$radacinaCod = dirname(__DIR__, 3);
require_once $radacinaCod . '/src/Support/Migrari/Eroare.php';
require_once $radacinaCod . '/src/Support/Migrari/ParametruLipsa.php';
require_once $radacinaCod . '/src/Support/Migrari/Inghetare.php';
require_once $radacinaCod . '/src/Support/PachetSite.php';

/** Cheile care nu se compară niciodată (secrete): doar lipsa / gol / nevid. */
$tiparSecret = '/(_password$|_secret|_api_key$|^smtp_)/';

return static function (string $site) use ($radacinaCod, $tiparSecret): ?array {
    $dosarPachet = $radacinaCod . '/site-uri/' . $site;
    if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $site) !== 1 || !is_file($dosarPachet . '/pachet.json')) {
        return null;
    }
    $placeholder = '%product-placeholder%';
    return [
        'site' => $site,
        'verificari' => [
            [
                'cheie' => 'v1',
                'titlu' => 'V1: setările înghețate (' . Inghetare::FISIER . ') față de bază: lipsa / gol / egal / diferit',
                'ruleaza' => static function (Context $c) use ($dosarPachet, $tiparSecret): void {
                    if (!$c->cere('v1', ['settings.key', 'settings.value'])) {
                        return;
                    }
                    $fisier = $dosarPachet . '/' . Inghetare::FISIER;
                    $inghetare = Inghetare::dinFisier($fisier);
                    $private = [];
                    if ($inghetare->cheiPrivate() !== [] && is_file(Inghetare::caleaPrivata($fisier))) {
                        $private = $inghetare->valoriPrivate('v1');
                    }
                    $stari = ['lipsa' => 0, 'gol' => 0, 'egal' => 0, 'diferit' => 0, 'nevid' => 0];
                    foreach ($inghetare->setari() as $cheie => $intrare) {
                        $comparabila = preg_match($tiparSecret, (string) $cheie) !== 1 && (!$intrare['privat'] || isset($private[$cheie]));
                        $valoare = $intrare['privat'] ? ($private[$cheie] ?? '') : (string) $intrare['valoare'];
                        $rand = $c->citire->randuri('SELECT (TRIM(COALESCE(`value`, \'\')) = \'\') AS gol, (BINARY COALESCE(`value`, \'\') = BINARY ?) AS egal
                            FROM settings WHERE `key` = ? LIMIT 1', [$valoare, (string) $cheie]);
                        if ($rand === []) {
                            $stare = 'lipsa';
                        } elseif ((int) $rand[0]['gol'] === 1) {
                            $stare = 'gol';
                        } elseif (!$comparabila) {
                            $stare = 'nevid';
                        } else {
                            $stare = (int) $rand[0]['egal'] === 1 ? 'egal' : 'diferit';
                        }
                        $stari[$stare]++;
                        $c->raport->alegere('v1.' . $cheie, $stare, array_keys($stari),
                            $intrare['mod'] === 'lipsa_sau_gol' ? 'lipsa_sau_gol' : '');
                    }
                    foreach ($stari as $stare => $n) {
                        $c->raport->numar('v1.total.' . $stare, $n, $stare === 'nevid' ? 'secrete sau private fără fișierul lor: necomparate' : '');
                    }
                },
            ],
            [
                'cheie' => 'v2',
                'titlu' => 'V2: pagini publicate cu slug gol, „acasa” sau „home” (C5: home.php)',
                'ruleaza' => static function (Context $c): void {
                    $c->numar('v2.pagini_acasa', ['pages.slug', 'pages.is_published', 'pages.deleted_at'],
                        "SELECT COUNT(*) FROM pages WHERE is_published = 1 AND deleted_at IS NULL AND (slug = '' OR LOWER(slug) IN ('acasa', 'home')) LIMIT 1");
                },
            ],
            [
                'cheie' => 'v3',
                'titlu' => 'V3: pagini publicate „checkout/succes” (C4)',
                'ruleaza' => static function (Context $c): void {
                    $c->numar('v3.pagini_succes', ['pages.slug', 'pages.is_published', 'pages.deleted_at'],
                        "SELECT COUNT(*) FROM pages WHERE is_published = 1 AND deleted_at IS NULL AND slug = 'checkout/succes' LIMIT 1");
                },
            ],
            [
                'cheie' => 'v4',
                'titlu' => 'V4: antetul din bază și logo-ul sertarului (A2, C1)',
                'ruleaza' => static function (Context $c) use ($site): void {
                    if (!$c->cere('v4', ['settings.key', 'settings.value'])) {
                        return;
                    }
                    $rand = $c->citire->randuri("SELECT (TRIM(COALESCE(`value`, '')) <> '') AS nevid, (LOCATE('{{mobile_menu}}', COALESCE(`value`, '')) > 0) AS sertar
                        FROM settings WHERE `key` = 'design_header_html' LIMIT 1");
                    $c->raport->daNu('v4.antet_nevid', $rand !== [] && (int) $rand[0]['nevid'] === 1);
                    $c->raport->daNu('v4.antet_cu_mobile_menu', $rand !== [] && (int) $rand[0]['sertar'] === 1);
                    $nume = $c->site->numeFisiere('public/uploads/gallery', '/^' . preg_quote($site, '/') . '-logo\.(webp|png|jpg|svg)$/');
                    foreach (['webp', 'png', 'jpg', 'svg'] as $ext) {
                        $c->raport->daNu('v4.logo_' . $ext, in_array($site . '-logo.' . $ext, $nume ?? [], true));
                    }
                },
            ],
            [
                'cheie' => 'v5',
                'titlu' => 'V5: pagini publicate „404” (not-found.php)',
                'ruleaza' => static function (Context $c): void {
                    $c->numar('v5.pagini_404', ['pages.slug', 'pages.is_published', 'pages.deleted_at'],
                        "SELECT COUNT(*) FROM pages WHERE is_published = 1 AND deleted_at IS NULL AND slug = '404' LIMIT 1");
                },
            ],
            [
                'cheie' => 'v6',
                'titlu' => 'V6: articole publicate fără categorie, fără autor (D5/D6)',
                'ruleaza' => static function (Context $c): void {
                    $c->numar('v6.fara_categorie', ['blog_posts.category', 'blog_posts.is_published', 'blog_posts.deleted_at'],
                        "SELECT COUNT(*) FROM blog_posts WHERE is_published = 1 AND deleted_at IS NULL AND (category IS NULL OR category = '') LIMIT 1");
                    $c->numar('v6.fara_autor', ['blog_posts.author_id', 'blog_posts.is_published', 'blog_posts.deleted_at'],
                        'SELECT COUNT(*) FROM blog_posts WHERE is_published = 1 AND deleted_at IS NULL AND author_id IS NULL LIMIT 1');
                },
            ],
            [
                'cheie' => 'v7',
                'titlu' => 'V7: produse active fără descriere scurtă (E1)',
                'ruleaza' => static function (Context $c): void {
                    $c->numar('v7.fara_descriere_scurta', ['products.short_description', 'products.is_active', 'products.deleted_at'],
                        "SELECT COUNT(*) FROM products WHERE is_active = 1 AND deleted_at IS NULL AND TRIM(COALESCE(short_description, '')) = '' LIMIT 1");
                },
            ],
            [
                'cheie' => 'v8',
                'titlu' => 'V8: poza de rezervă (sau nicio poză) la produse, articole, autori (O6)',
                'ruleaza' => static function (Context $c) use ($placeholder): void {
                    foreach ([['produse', 'products', 'image_url'], ['articole', 'blog_posts', 'featured_image_url'], ['autori', 'blog_authors', 'avatar_url']]
                        as [$ce, $tabel, $coloana]) {
                        $c->numar('v8.' . $ce . '_poza_rezerva', [$tabel . '.' . $coloana],
                            'SELECT COUNT(*) FROM ' . $tabel . ' WHERE ' . $coloana . ' LIKE ? LIMIT 1', [$placeholder]);
                        $c->numar('v8.' . $ce . '_fara_poza', [$tabel . '.' . $coloana],
                            'SELECT COUNT(*) FROM ' . $tabel . ' WHERE TRIM(COALESCE(' . $coloana . ', \'\')) = \'\' LIMIT 1');
                    }
                },
            ],
            [
                'cheie' => 'v9',
                'titlu' => 'V9: campanii de newsletter trimise în ultimele 365 de zile (F3)',
                'ruleaza' => static function (Context $c): void {
                    $c->numar('v9.campanii_trimise_365z', ['newsletter_campaigns.status', 'newsletter_campaigns.sent_at'],
                        "SELECT COUNT(*) FROM newsletter_campaigns WHERE status = 'sent' AND sent_at >= NOW() - INTERVAL 365 DAY LIMIT 1");
                },
            ],
            [
                'cheie' => 'v10',
                'titlu' => 'V10: numerotarea comenzilor (F4)',
                'ruleaza' => static function (Context $c): void {
                    if (!$c->cere('v10', ['settings.key', 'settings.value'])) {
                        return;
                    }
                    $rand = $c->citire->randuri("SELECT
                        SUM(`key` = 'order_number_mode' AND TRIM(COALESCE(`value`, '')) = 'sequential') AS secvential,
                        SUM(`key` = 'order_number_next') AS urmator
                        FROM settings WHERE `key` IN ('order_number_mode', 'order_number_next') LIMIT 1");
                    $c->raport->daNu('v10.order_number_mode_sequential', (int) ($rand[0]['secvential'] ?? 0) > 0);
                    $c->raport->daNu('v10.order_number_next_exista', (int) ($rand[0]['urmator'] ?? 0) > 0);
                },
            ],
            [
                'cheie' => 'v11',
                'titlu' => 'V11: destinatarii formularului de contact și numele expeditorului (A4/A6)',
                'ruleaza' => static function (Context $c): void {
                    if (!$c->cere('v11', ['settings.key', 'settings.value'])) {
                        return;
                    }
                    // O adresă validă, în SQL (valoarea nu se citește): ceva@ceva.tld, oriunde în listă.
                    $rand = $c->citire->randuri("SELECT
                        SUM(`key` = 'contact_form_recipients' AND `value` REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\\\\.[A-Za-z0-9-]+)+') AS destinatar_valid,
                        SUM(`key` = 'order_email_from_name' AND TRIM(COALESCE(`value`, '')) <> '') AS expeditor_nevid
                        FROM settings WHERE `key` IN ('contact_form_recipients', 'order_email_from_name') LIMIT 1");
                    $c->raport->daNu('v11.contact_form_recipients_valid', (int) ($rand[0]['destinatar_valid'] ?? 0) > 0);
                    $c->raport->daNu('v11.order_email_from_name_nevid', (int) ($rand[0]['expeditor_nevid'] ?? 0) > 0);
                },
            ],
            [
                'cheie' => 'v12',
                'titlu' => 'V12: pachetul site-uri/' . $site . '/ față de pachet.json (§5.2)',
                'ruleaza' => static function (Context $c) use ($dosarPachet, $site): void {
                    $probleme = PachetSite::verifica($dosarPachet, $site);
                    $c->raport->daNu('v12.pachet_verificat', $probleme === [], '', true);
                    $c->raport->numar('v12.probleme', count($probleme));
                },
            ],
        ],
    ];
};
