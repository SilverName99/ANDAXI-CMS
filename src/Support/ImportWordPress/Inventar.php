<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

/**
 * Ce are un site WordPress, citit din baza lui (SursaWordPress, doar citire) într-o formă simplă, fără nimic de
 * WordPress mai departe: datele site-ului, pluginurile, paginile (cu adresa lor), articolele, pozele (fișierul și
 * mărimile lui), meniurile, SEO-ul fiecărei pagini și ce altceva are site-ul (produse, comenzi, abonați), ca planul
 * să spună ce NU se importă.
 *
 * Tot ce urmează (planul, conversia conținutului, scrierea în baza CMS-ului) lucrează doar pe acest inventar.
 */
final class Inventar
{
    /**
     * Pluginurile pe care le știe importul și ce se întâmplă cu ele (planul le arată; necunoscutele sunt ATENȚIE).
     *
     * Pe drumul capturat (`import-wordpress.json` → `"continut": "capturat"`) ce se vede din pluginurile de front-end
     * (bannerul, meniurile, caruselele, galeria) vine din captură, cu datele lor; cine le face să se miște în pagină e
     * încă o alegere a proprietarului, deci descrierile spun doar „capturat”. Trackerele rămân doar ID-uri: nucleul nu
     * le pornește singur.
     */
    public const PLUGINURI = [
        'all-in-one-seo-pack' => ['ce' => 'SEO (titluri, descrieri, robots)', 'cum' => 'valorile exacte în SEO-ul paginilor (seo_pages); pe drumul capturat head-ul vine din captură'],
        'all-in-one-seo-pack-pro' => ['ce' => 'SEO (titluri, descrieri, robots)', 'cum' => 'valorile exacte în SEO-ul paginilor (seo_pages); pe drumul capturat head-ul vine din captură'],
        'wordpress-seo' => ['ce' => 'Yoast SEO', 'cum' => 'titlurile și descrierile importate în SEO-ul paginilor'],
        'seo-by-rank-math' => ['ce' => 'Rank Math SEO', 'cum' => 'titlurile și descrierile importate în SEO-ul paginilor'],
        'complianz-gdpr' => ['ce' => 'bannerul de cookie-uri', 'cum' => 'capturat: bannerul, categoriile și cookie-urile cmplz_* (bannerul nucleului oprit)'],
        'complianz-gdpr-premium' => ['ce' => 'bannerul de cookie-uri', 'cum' => 'capturat: bannerul, categoriile și cookie-urile cmplz_* (bannerul nucleului oprit)'],
        'cookie-law-info' => ['ce' => 'bannerul de cookie-uri', 'cum' => 'înlocuit de bannerul nucleului (CookieConsent)'],
        'google-site-kit' => ['ce' => 'Google Analytics / Search Console', 'cum' => 'ID-urile (Google tag, GTM) păstrate ca date; nucleul nu le pornește singur, doar după acord'],
        'microsoft-clarity' => ['ce' => 'Microsoft Clarity', 'cum' => 'ID-ul proiectului păstrat ca date; nucleul nu-l pornește singur, doar după acord'],
        'duracelltomi-google-tag-manager' => ['ce' => 'Google Tag Manager', 'cum' => 'ID-ul containerului păstrat ca date; nucleul nu-l pornește singur, doar după acord'],
        'advanced-google-recaptcha' => ['ce' => 'reCAPTCHA pe formulare', 'cum' => 'nu trece: formularele au protecția nucleului'],
        'divi-gallery-extended' => ['ce' => 'galerie Divi', 'cum' => 'capturat (grila, filtrele, categoriile pozelor)'],
        'dg-carousel' => ['ce' => 'carusel Divi', 'cum' => 'capturat (DOM-ul și datele caruselelor)'],
        'divimenus' => ['ce' => 'meniuri Divi', 'cum' => 'capturat (meniurile, popup-urile și pinii)'],
        'litespeed-cache' => ['ce' => 'cache', 'cum' => 'nu trece: codul comun are cache-ul lui'],
        'wp-mail-smtp' => ['ce' => 'trimiterea emailurilor', 'cum' => 'nu trece: setările de email ale nucleului'],
        'contact-form-7' => ['ce' => 'formulare de contact', 'cum' => 'la pasul 17 (formularele CF7 compatibile); azi doar inventariate'],
        'polylang' => ['ce' => 'site multilingv', 'cum' => 'la pasul 17 (multilingvul); azi doar limbile și traducerile inventariate'],
        'akismet' => ['ce' => 'antispam la comentarii', 'cum' => 'nu trece: fără comentarii'],
        'classic-editor' => ['ce' => 'editorul clasic', 'cum' => 'nu contează'],
        'updraftplus' => ['ce' => 'copii de siguranță', 'cum' => 'nu trece'],
        'wordfence' => ['ce' => 'securitate', 'cum' => 'nu trece'],
        'hostinger' => ['ce' => 'pluginul Hostinger', 'cum' => 'nu trece'],
        'hostinger-ai-assistant' => ['ce' => 'pluginul Hostinger', 'cum' => 'nu trece'],
        'hostinger-easy-onboarding' => ['ce' => 'pluginul Hostinger', 'cum' => 'nu trece'],
        // Ce cere importul unui magazin, al unui blog și al unui newsletter: încă neimportate, deci
        // planul spune NU cât site-ul are date în ele (Inventar::$altele), afară de --fara-<parte>.
        'woocommerce' => ['ce' => 'magazinul (produse, comenzi, clienți)', 'cum' => 'NEIMPORTAT încă (pluginul magazin)', 'parte' => 'magazin'],
        'woocommerce-gateway-stripe' => ['ce' => 'plăți Stripe', 'cum' => 'setările plăților se fac în admin (pluginul plata_stripe)', 'parte' => 'magazin'],
        'mailpoet' => ['ce' => 'newsletter', 'cum' => 'NEIMPORTAT încă (pluginul newsletter)', 'parte' => 'newsletter'],
    ];

    /** Temele știute și ce se întâmplă cu ele (Flatsome, cu UX Builder, la pasul 17). */
    public const TEME = [
        'flatsome' => 'Flatsome (UX Builder): la pasul 17 (dialectul flatsome3)',
    ];

    /** Tipurile de postări ale Theme Builder-ului Divi (șabloanele și layout-urile lor). */
    public const TIPURI_THEME_BUILDER = ['et_theme_builder', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout'];

    /** Atributele modulului de contact Divi citite pentru formularele de pe server (pasul 9). */
    public const ATRIBUTE_FORMULAR_DIVI = ['email', 'title', 'success_message', 'custom_message', 'use_redirect', 'redirect_url', 'captcha',
        'use_spam_service', 'submit_button_text'];

    /** Taxonomiile nucleului WordPress și ale Polylang (restul legate de atașamente sunt categoriile mediilor, ca la DGE). */
    public const TAXONOMII_NUCLEU = ['category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area',
        'wp_pattern_category', 'language', 'term_language', 'post_translations', 'term_translations', 'project_category', 'project_tag'];

    /** @var array<string, mixed> */
    public array $site = [];
    /** @var list<string> pluginurile active (dosarul lor) */
    public array $pluginuri = [];
    /** @var array<int, array<string, mixed>> */
    public array $pagini = [];
    /** @var array<int, array<string, mixed>> */
    public array $articole = [];
    /** @var array<int, array<string, mixed>> */
    public array $atasamente = [];
    /** @var array<int, array<string, mixed>> */
    public array $meniuri = [];
    /** @var array<int, array<string, mixed>> SEO-ul fiecărei postări (id → titlu, descriere, canonical, robots, imagine) */
    public array $seo = [];
    /** @var array<int, array<string, mixed>> categoriile articolelor */
    public array $categorii = [];
    /** @var array<string, int> ce altceva are site-ul și nu se importă încă */
    public array $altele = [];
    /** @var array<string, mixed> setările unor pluginuri (analiză, verificări), pentru setările nucleului */
    public array $urmarire = [];
    /**
     * Theme Builder-ul Divi: șabloanele (unde se folosesc: `_et_use_on`/`_et_exclude_from`, antetul, corpul și subsolul
     * lor), layout-urile (conținutul lor, în shortcoduri) și intrarea et_theme_builder (lista șabloanelor).
     *
     * @var array{constructor: array<int, array<string, mixed>>, sabloane: array<int, array<string, mixed>>, layouturi: array<int, array<string, mixed>>}
     */
    public array $themeBuilder = ['constructor' => [], 'sabloane' => [], 'layouturi' => []];
    /** @var list<array{postare: int, ordine: int, atribute: array<string, string>}> modulele et_pb_contact_form (destinatarul, mesajele) */
    public array $formulareDivi = [];
    /** @var list<array{postare: int, continut: string, dinamic: bool, setari: array<string, mixed>}> Divi Dynamic Content (`@ET-DC@…@`) */
    public array $continutDinamic = [];
    /** @var array<string, array<int, array{nume: string, slug: string, atasamente: list<int>}>> categoriile mediilor (taxonomie → termeni) */
    public array $categoriiMedia = [];
    /** @var array{proiecte: array<int, array<string, mixed>>, categorii: array<int, array<string, mixed>>} proiectele Divi (`project`, `project_category`) */
    public array $proiecte = ['proiecte' => [], 'categorii' => []];
    /** @var array<int, array{id: int, titlu: string, slug: string, stare: string}> formularele Contact Form 7 (pasul 17) */
    public array $formulareCf7 = [];
    /** @var array{limbi: array<int, array<string, mixed>>, traduceri: list<array<string, int>>} Polylang (pasul 17) */
    public array $polylang = ['limbi' => [], 'traduceri' => []];
    /** @var array<int, array{id: int, slug: string}> autorii conținutului publicat: doar id-ul și user_nicename (fără email) */
    public array $autori = [];
    /**
     * Harta WordPress (HartaWp): fiecare postare publicată (pagini, articole, proiecte), fiecare atașament, categoriile
     * și autorii, cu id-ul WordPress și adresa lor; pentru ghicire, `?page_id=`, `?p=`, `?cat=`, `?author=` și atașamente.
     *
     * @var list<array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string}>
     */
    public array $harta = [];

    public static function citeste(SursaWordPress $s): self
    {
        $i = new self();
        $i->citesteSite($s);
        $i->citestePluginuri($s);
        $i->citestePostari($s);
        $i->citesteAtasamente($s);
        $i->citesteMeniuri($s);
        $i->seo = SeoWordPress::citeste($s, $i);
        $i->citesteAltele($s);
        $i->citesteUrmarire($s);
        $i->citesteThemeBuilder($s);
        $i->citesteProiecte($s);
        $i->citesteFormulareCf7($s);
        $i->citestePolylang($s);
        $i->citesteCategoriiMedia($s);
        $i->citesteAutori($s);
        $i->citesteFormulareSiContinutDinamic();
        $i->harta = HartaWp::dinInventar($i);
        return $i;
    }

    private function citesteSite(SursaWordPress $s): void
    {
        $home = rtrim((string) $s->optiune('home', ''), '/');
        $siteurl = rtrim((string) $s->optiune('siteurl', ''), '/');
        $config = $s->config;
        if ($config->constanta('WP_HOME') !== '') {
            $home = rtrim($config->constanta('WP_HOME'), '/');
        }
        $stylesheet = (string) $s->optiune('stylesheet', '');
        $modTema = $s->optiune('theme_mods_' . $stylesheet, []);
        $divi = $s->optiune('et_divi', []);
        $logo = '';
        if (is_array($divi) && is_string($divi['divi_logo'] ?? null)) {
            $logo = (string) $divi['divi_logo'];
        }
        $this->site = [
            'nume' => html_entity_decode((string) $s->optiune('blogname', ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'descriere' => html_entity_decode((string) $s->optiune('blogdescription', ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'url' => $home !== '' ? $home : $siteurl,
            'siteurl' => $siteurl,
            'email_admin' => (string) $s->optiune('admin_email', ''),
            'permalinkuri' => (string) $s->optiune('permalink_structure', ''),
            'arata_pe_prima' => (string) $s->optiune('show_on_front', 'posts'),
            'prima_pagina' => (int) $s->optiune('page_on_front', 0),
            'pagina_articole' => (int) $s->optiune('page_for_posts', 0),
            // Câte articole pe pagina unei arhive (paginarea pe care o cere captura: /page/2/).
            'articole_pe_pagina' => max(1, (int) $s->optiune('posts_per_page', 10)),
            'iconita' => (int) $s->optiune('site_icon', 0),
            'logo_atasament' => is_array($modTema) ? (int) ($modTema['custom_logo'] ?? 0) : 0,
            'logo_url' => $logo,
            'locatii_meniuri' => is_array($modTema) && is_array($modTema['nav_menu_locations'] ?? null) ? array_map('intval', $modTema['nav_menu_locations']) : [],
            'tema' => ['template' => (string) $s->optiune('template', ''), 'stylesheet' => $stylesheet],
            'pagini_atasamente' => (string) $s->optiune('wp_attachment_pages_enabled', '1') !== '0',
            'limba' => (string) $s->optiune('WPLANG', ''),
            'pagina_confidentialitate' => (int) $s->optiune('wp_page_for_privacy_policy', 0),
            'baza_categorii' => trim((string) $s->optiune('category_base', ''), '/'),
            'versiune_wp' => self::versiuneWp($config->dosar),
        ];
    }

    /** Versiunea WordPress din wp-includes/version.php (citită ca text, fără să fie rulată). */
    public static function versiuneWp(string $dosarWp): string
    {
        $text = (string) @file_get_contents($dosarWp . '/wp-includes/version.php');
        return preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $text, $m) === 1 ? $m[1] : '';
    }

    private function citestePluginuri(SursaWordPress $s): void
    {
        $active = $s->optiune('active_plugins', []);
        $rez = [];
        foreach (is_array($active) ? $active : [] as $p) {
            $p = (string) $p;
            $rez[] = str_contains($p, '/') ? strstr($p, '/', true) : preg_replace('/\.php$/', '', $p);
        }
        sort($rez);
        $this->pluginuri = array_values(array_unique(array_map('strval', $rez)));
    }

    private function citestePostari(SursaWordPress $s): void
    {
        $randuri = $s->postari(['page', 'post'], ['publish', 'private', 'draft', 'pending', 'future']);
        $iduri = array_map(static fn (array $r): int => (int) $r['ID'], $randuri);
        $meta = $s->meta($iduri, ['_wp_page_template', '_et_pb_use_builder', '_et_pb_page_layout', '_thumbnail_id', '_elementor_edit_mode',
            '_wpb_vc_js_status', 'fl-builder-enabled']);
        $sluguriVechi = $s->metaMultipla($iduri, '_wp_old_slug');
        $toate = [];
        foreach ($randuri as $r) {
            $id = (int) $r['ID'];
            $m = $meta[$id] ?? [];
            $continut = (string) $r['post_content'];
            $toate[$id] = [
                'id' => $id,
                'tip' => (string) $r['post_type'],
                'titlu' => html_entity_decode((string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'slug' => (string) $r['post_name'],
                'stare' => (string) $r['post_status'],
                'parola' => (string) $r['post_password'] !== '',
                'parinte' => (int) $r['post_parent'],
                'ordine' => (int) $r['menu_order'],
                'data' => (string) $r['post_date'],
                'data_gmt' => (string) $r['post_date_gmt'],
                'modificat' => (string) $r['post_modified'],
                'continut' => $continut,
                'rezumat' => (string) $r['post_excerpt'],
                'sablon' => is_string($m['_wp_page_template'] ?? null) ? (string) $m['_wp_page_template'] : '',
                'constructor' => self::constructor($continut, $m),
                'poza' => (int) ($m['_thumbnail_id'] ?? 0),
                'sluguri_vechi' => array_values(array_unique($sluguriVechi[$id] ?? [])),
            ];
        }
        // Adresa fiecărei pagini: sluguri părinților, în ordine (ca get_page_uri).
        foreach ($toate as $id => $p) {
            if ($p['tip'] !== 'page') {
                continue;
            }
            $parti = [rawurldecode($p['slug'])];
            $partiBrute = [$p['slug']];
            $parinte = $p['parinte'];
            $vazute = [$id => true];
            while ($parinte > 0 && isset($toate[$parinte]) && !isset($vazute[$parinte])) {
                $vazute[$parinte] = true;
                array_unshift($parti, rawurldecode($toate[$parinte]['slug']));
                array_unshift($partiBrute, $toate[$parinte]['slug']);
                $parinte = $toate[$parinte]['parinte'];
            }
            $toate[$id]['cale'] = '/' . implode('/', $parti) . '/';
            // Forma WordPress (post_name neatins, percent-encoded; „/” final după structura permalinkurilor).
            $toate[$id]['cale_wp'] = Adrese::caleWordPress($partiBrute, $this->slashFinal());
            $this->pagini[$id] = $toate[$id];
        }
        foreach ($toate as $id => $p) {
            if ($p['tip'] === 'post') {
                $p['cale'] = self::caleArticol($p, $this->site['permalinkuri'] ?? '');
                $p['cale_wp'] = self::caleArticol($p, $this->site['permalinkuri'] ?? '', true);
                $this->articole[$id] = $p;
            }
        }
        $this->categorii = $s->termeni('category');
    }

    /** Constructorul de pagini al unei postări: divi, elementor, wpbakery, beaver, gutenberg sau clasic. */
    public static function constructor(string $continut, array $meta): string
    {
        if (str_contains($continut, '[et_pb_') || ($meta['_et_pb_use_builder'] ?? '') === 'on') {
            return 'divi';
        }
        if (($meta['_elementor_edit_mode'] ?? '') === 'builder') {
            return 'elementor';
        }
        if (str_contains($continut, '[vc_row') || ($meta['_wpb_vc_js_status'] ?? '') === 'true') {
            return 'wpbakery';
        }
        if (($meta['fl-builder-enabled'] ?? '') === '1') {
            return 'beaver';
        }
        if (str_contains($continut, '<!-- wp:')) {
            return 'gutenberg';
        }
        return 'clasic';
    }

    /** Structura permalinkurilor se termină în „/”: WordPress pune „/” final pe pagini, articole și arhive (user_trailingslashit). */
    public function slashFinal(): bool
    {
        return str_ends_with((string) ($this->site['permalinkuri'] ?? ''), '/');
    }

    /**
     * Adresa unui articol după structura permalinkurilor (%postname%, %year%, %monthnum%, %day%, %post_id%, %category%):
     * cu slug-ul decodat, sau (`$formaWordPress`) cu `post_name` neatins, cum îl scrie WordPress în adrese.
     */
    public static function caleArticol(array $p, string $structura, bool $formaWordPress = false): string
    {
        if ($structura === '') {
            return '/?p=' . $p['id'];
        }
        $t = strtotime($p['data']) ?: 0;
        $cale = strtr($structura, [
            '%postname%' => $formaWordPress ? Adrese::segmentWordPress((string) $p['slug']) : rawurldecode((string) $p['slug']),
            '%post_id%' => (string) $p['id'], '%year%' => date('Y', $t), '%monthnum%' => date('m', $t),
            '%day%' => date('d', $t), '%hour%' => date('H', $t), '%minute%' => date('i', $t), '%second%' => date('s', $t), '%category%' => 'uncategorized',
            '%author%' => 'autor',
        ]);
        return '/' . trim($cale, '/') . (str_ends_with($structura, '/') ? '/' : '');
    }

    private function citesteAtasamente(SursaWordPress $s): void
    {
        $randuri = $s->postari(['attachment']);
        $iduri = array_map(static fn (array $r): int => (int) $r['ID'], $randuri);
        $meta = $s->meta($iduri, ['_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt']);
        foreach ($randuri as $r) {
            $id = (int) $r['ID'];
            $m = $meta[$id] ?? [];
            $fisier = ltrim(str_replace('\\', '/', (string) ($m['_wp_attached_file'] ?? '')), '/');
            $md = is_array($m['_wp_attachment_metadata'] ?? null) ? $m['_wp_attachment_metadata'] : [];
            $dosar = str_contains($fisier, '/') ? substr($fisier, 0, (int) strrpos($fisier, '/') + 1) : '';
            $marimi = [];
            foreach ((array) ($md['sizes'] ?? []) as $nume => $marime) {
                if (is_array($marime) && is_string($marime['file'] ?? null) && $marime['file'] !== '') {
                    $marimi[(string) $nume] = ['fisier' => $dosar . basename((string) $marime['file']), 'latime' => (int) ($marime['width'] ?? 0),
                        'inaltime' => (int) ($marime['height'] ?? 0)];
                }
            }
            $this->atasamente[$id] = [
                'id' => $id,
                'fisier' => $fisier,
                'mime' => (string) $r['post_mime_type'],
                'titlu' => html_entity_decode((string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'alt' => is_string($m['_wp_attachment_image_alt'] ?? null) ? trim((string) $m['_wp_attachment_image_alt']) : '',
                'legenda' => (string) $r['post_excerpt'],
                'parinte' => (int) $r['post_parent'],
                'slug' => (string) $r['post_name'],
                'latime' => (int) ($md['width'] ?? 0),
                'inaltime' => (int) ($md['height'] ?? 0),
                'marimi' => $marimi,
                // Poza mare micșorată de WordPress (-scaled): originalul stă alături, cu numele din original_image.
                'original' => is_string($md['original_image'] ?? null) && $md['original_image'] !== '' ? $dosar . basename((string) $md['original_image']) : '',
                'guid' => (string) $r['guid'],
            ];
        }
    }

    private function citesteMeniuri(SursaWordPress $s): void
    {
        $locatii = [];
        foreach ((array) ($this->site['locatii_meniuri'] ?? []) as $loc => $termen) {
            $locatii[(int) $termen][] = (string) $loc;
        }
        foreach ($s->termeni('nav_menu') as $id => $t) {
            $iduri = $s->postariTermen($t['tt_id']);
            $elemente = [];
            if ($iduri !== []) {
                $postari = [];
                foreach (array_chunk($iduri, 500) as $bucata) {
                    foreach ($s->randuri('SELECT ID, post_title, post_status, menu_order, post_type FROM ' . $s->t('posts') . ' WHERE ID IN ('
                        . implode(',', array_map('intval', $bucata)) . ') AND post_type = \'nav_menu_item\'') as $r) {
                        $postari[(int) $r['ID']] = $r;
                    }
                }
                $meta = $s->meta(array_keys($postari), ['_menu_item_type', '_menu_item_object', '_menu_item_object_id', '_menu_item_url',
                    '_menu_item_menu_item_parent', '_menu_item_target', '_menu_item_classes']);
                foreach ($postari as $pid => $r) {
                    if ((string) $r['post_status'] !== 'publish') {
                        continue;
                    }
                    $m = $meta[$pid] ?? [];
                    $clase = $m['_menu_item_classes'] ?? [];
                    $elemente[] = [
                        'id' => $pid,
                        'titlu' => html_entity_decode((string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'ordine' => (int) $r['menu_order'],
                        'tip' => (string) ($m['_menu_item_type'] ?? 'custom'),
                        'obiect' => (string) ($m['_menu_item_object'] ?? ''),
                        'obiect_id' => (int) ($m['_menu_item_object_id'] ?? 0),
                        'url' => (string) ($m['_menu_item_url'] ?? ''),
                        'parinte' => (int) ($m['_menu_item_menu_item_parent'] ?? 0),
                        'tinta' => (string) ($m['_menu_item_target'] ?? ''),
                        'clase' => is_array($clase) ? array_values(array_filter(array_map('strval', $clase))) : [],
                    ];
                }
                usort($elemente, static fn (array $a, array $b): int => [$a['ordine'], $a['id']] <=> [$b['ordine'], $b['id']]);
            }
            $this->meniuri[$id] = ['id' => $id, 'nume' => $t['nume'], 'slug' => $t['slug'], 'locatii' => $locatii[$id] ?? [], 'elemente' => $elemente];
        }
    }

    private function citesteAltele(SursaWordPress $s): void
    {
        $tipuri = [];
        foreach ($s->randuri('SELECT post_type, COUNT(*) AS n FROM ' . $s->t('posts')
            . " WHERE post_status NOT IN ('auto-draft', 'trash', 'inherit') GROUP BY post_type") as $r) {
            $tipuri[(string) $r['post_type']] = (int) $r['n'];
        }
        $this->altele = [
            'produse' => ($tipuri['product'] ?? 0),
            'variante_produse' => ($tipuri['product_variation'] ?? 0),
            'comenzi' => ($tipuri['shop_order'] ?? 0) + $s->numar('wc_orders'),
            'cupoane' => ($tipuri['shop_coupon'] ?? 0),
            'abonati_newsletter' => $s->numar('mailpoet_subscribers'),
            'comentarii' => $s->numar('comments', "comment_approved = '1' AND comment_type IN ('', 'comment')"),
            'articole_publicate' => count(array_filter($this->articole, static fn (array $a): bool => $a['stare'] === 'publish')),
        ];
        // Tipurile de postări pe care importul nu le știe (proiecte Divi, formulare, șabloane): doar spuse în plan.
        $stiute = ['page', 'post', 'attachment', 'nav_menu_item', 'revision', 'customize_changeset', 'oembed_cache', 'custom_css', 'wp_global_styles',
            'wp_navigation', 'wp_template', 'wp_template_part', 'user_request', 'et_pb_layout', 'et_template', 'et_header_layout', 'et_body_layout',
            'et_footer_layout', 'et_theme_builder', 'et_theme_options', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon',
            'shop_order_placehold', 'wp_block', 'cmplz-dataleak', 'cmplz-processing', 'wpcf7_contact_form', 'mailpoet_page', 'scheduled-action'];
        foreach ($tipuri as $tip => $n) {
            if (!in_array($tip, $stiute, true) && $n > 0) {
                $this->altele['tip:' . $tip] = $n;
            }
        }
    }

    /** ID-urile de analiză și de verificare din pluginurile știute (Site Kit, Clarity, AIOSEO). */
    private function citesteUrmarire(SursaWordPress $s): void
    {
        $ga = $s->optiune('googlesitekit_analytics-4_settings', []);
        $id = '';
        if (is_array($ga)) {
            foreach (['measurementID', 'googleTagID'] as $k) {
                if ($id === '' && is_string($ga[$k] ?? null) && preg_match('/^(G|GT|AW)-[A-Z0-9]+$/', (string) $ga[$k]) === 1) {
                    $id = (string) $ga[$k];
                }
            }
        }
        $clarity = (string) $s->optiune('clarity_project_id', '');
        $aioseo = json_decode((string) $s->optiune('aioseo_options', ''), true);
        $verificareGoogle = is_array($aioseo) ? (string) ($aioseo['webmasterTools']['google'] ?? '') : '';
        // Containerul GTM: din Site Kit, din GTM4WP sau din codul pus în Divi (Theme Options → Integration), doar ID-ul.
        $gtm = '';
        $tagManager = $s->optiune('googlesitekit_tagmanager_settings', []);
        $gtm4wp = $s->optiune('gtm4wp-options', []);
        $divi = $s->optiune('et_divi', []);
        foreach ([is_array($tagManager) ? (string) ($tagManager['containerID'] ?? '') : '', is_array($gtm4wp) ? (string) ($gtm4wp['gtm-code'] ?? '') : '',
            is_array($divi) ? (string) ($divi['divi_integration_head'] ?? '') . ' ' . (string) ($divi['divi_integration_body'] ?? '') : ''] as $text) {
            if ($gtm === '' && preg_match('/\bGTM-[A-Z0-9]{4,12}\b/', $text, $m) === 1) {
                $gtm = $m[0];
            }
        }
        $this->urmarire = [
            'google_analytics_id' => $id,
            'google_tag_manager_id' => $gtm,
            'microsoft_clarity_project_id' => preg_match('/^[a-z0-9]{6,20}$/i', $clarity) === 1 ? $clarity : '',
            'google_site_verification' => preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $verificareGoogle) === 1 ? $verificareGoogle : '',
        ];
    }

    /** Șabloanele și layout-urile Theme Builder-ului Divi (corectura 1.8). */
    private function citesteThemeBuilder(SursaWordPress $s): void
    {
        $randuri = $s->postari(self::TIPURI_THEME_BUILDER, ['publish', 'private', 'draft']);
        if ($randuri === []) {
            return;
        }
        $iduri = array_map(static fn (array $r): int => (int) $r['ID'], $randuri);
        $meta = $s->meta($iduri, ['_et_header_layout_id', '_et_body_layout_id', '_et_footer_layout_id', '_et_header_layout_enabled',
            '_et_body_layout_enabled', '_et_footer_layout_enabled', '_et_default', '_et_enabled']);
        $folosire = $s->metaMultipla($iduri, '_et_use_on');
        $excluse = $s->metaMultipla($iduri, '_et_exclude_from');
        $sabloane = $s->metaMultipla($iduri, '_et_template');
        foreach ($randuri as $r) {
            $id = (int) $r['ID'];
            $m = $meta[$id] ?? [];
            $baza = ['id' => $id, 'titlu' => html_entity_decode((string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'stare' => (string) $r['post_status']];
            if ($r['post_type'] === 'et_template') {
                $parte = static fn (string $p): array => ['layout' => (int) ($m['_et_' . $p . '_layout_id'] ?? 0),
                    'pornit' => (string) ($m['_et_' . $p . '_layout_enabled'] ?? '1') !== '0'];
                $this->themeBuilder['sabloane'][$id] = $baza + ['implicit' => (string) ($m['_et_default'] ?? '0') === '1',
                    'pornit' => (string) ($m['_et_enabled'] ?? '1') !== '0', 'foloseste' => array_values($folosire[$id] ?? []),
                    'exclude' => array_values($excluse[$id] ?? []), 'antet' => $parte('header'), 'corp' => $parte('body'), 'subsol' => $parte('footer')];
            } elseif ($r['post_type'] === 'et_theme_builder') {
                $this->themeBuilder['constructor'][$id] = $baza + ['sabloane' => array_map('intval', $sabloane[$id] ?? [])];
            } else {
                $this->themeBuilder['layouturi'][$id] = $baza + ['parte' => substr((string) $r['post_type'], 3, -7), 'continut' => (string) $r['post_content']];
            }
        }
    }

    /** Proiectele Divi (`project`) și categoriile lor (`project_category`). */
    private function citesteProiecte(SursaWordPress $s): void
    {
        foreach ($s->postari(['project'], ['publish', 'private', 'draft']) as $r) {
            $this->proiecte['proiecte'][(int) $r['ID']] = ['id' => (int) $r['ID'], 'titlu' => html_entity_decode((string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'slug' => (string) $r['post_name'], 'stare' => (string) $r['post_status'], 'data' => (string) $r['post_date'], 'continut' => (string) $r['post_content']];
        }
        $this->proiecte['categorii'] = $s->termeni('project_category');
    }

    /** Formularele Contact Form 7 (doar inventariate; formularele compatibile vin la pasul 17). */
    private function citesteFormulareCf7(SursaWordPress $s): void
    {
        foreach ($s->postari(['wpcf7_contact_form']) as $r) {
            $this->formulareCf7[(int) $r['ID']] = ['id' => (int) $r['ID'], 'titlu' => html_entity_decode((string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'slug' => (string) $r['post_name'], 'stare' => (string) $r['post_status']];
        }
    }

    /** Limbile Polylang (`language`, cu locale-ul din descriere) și grupurile de traduceri (`post_translations`). */
    private function citestePolylang(SursaWordPress $s): void
    {
        foreach ($s->termeni('language') as $id => $t) {
            $d = SursaWordPress::desface((string) $t['descriere']);
            $this->polylang['limbi'][$id] = ['id' => $id, 'nume' => (string) $t['nume'], 'slug' => (string) $t['slug'],
                'locale' => is_array($d) ? (string) ($d['locale'] ?? '') : ''];
        }
        foreach ($s->termeni('post_translations') as $t) {
            $d = SursaWordPress::desface((string) $t['descriere']);
            if (is_array($d)) {
                $this->polylang['traduceri'][] = array_map('intval', array_filter($d, 'is_numeric'));
            }
        }
    }

    /** Categoriile mediilor (ca la Divi Gallery Extended): orice taxonomie din afara nucleului legată de atașamente. */
    private function citesteCategoriiMedia(SursaWordPress $s): void
    {
        if (!$s->areTabel('terms') || !$s->areTabel('term_taxonomy') || !$s->areTabel('term_relationships')) {
            return;
        }
        $semne = implode(',', array_fill(0, count(self::TAXONOMII_NUCLEU), '?'));
        foreach ($s->randuri('SELECT tt.taxonomy, t.term_id, t.name, t.slug, tr.object_id FROM ' . $s->t('term_relationships') . ' tr JOIN '
            . $s->t('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN ' . $s->t('terms') . ' t ON t.term_id = tt.term_id JOIN '
            . $s->t('posts') . " p ON p.ID = tr.object_id WHERE p.post_type = 'attachment' AND tt.taxonomy NOT IN (" . $semne . ')'
            . ' ORDER BY tt.taxonomy, t.term_id, tr.object_id', self::TAXONOMII_NUCLEU) as $r) {
            $tax = (string) $r['taxonomy'];
            $id = (int) $r['term_id'];
            $this->categoriiMedia[$tax][$id] ??= ['nume' => html_entity_decode((string) $r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'slug' => (string) $r['slug'],
                'atasamente' => []];
            $this->categoriiMedia[$tax][$id]['atasamente'][] = (int) $r['object_id'];
        }
    }

    /** Autorii conținutului publicat: id-ul și user_nicename (adresa arhivei lor), nimic altceva din `users`. */
    private function citesteAutori(SursaWordPress $s): void
    {
        if (!$s->areTabel('users')) {
            return;
        }
        $iduri = array_values(array_filter(array_map('intval', array_column($s->randuri('SELECT DISTINCT post_author FROM ' . $s->t('posts')
            . " WHERE post_status = 'publish' AND post_type IN ('post', 'page', 'project')"), 'post_author'))));
        if ($iduri === []) {
            return;
        }
        foreach ($s->randuri('SELECT ID, user_nicename FROM ' . $s->t('users') . ' WHERE ID IN (' . implode(',', $iduri) . ') ORDER BY ID') as $r) {
            $this->autori[(int) $r['ID']] = ['id' => (int) $r['ID'], 'slug' => (string) $r['user_nicename']];
        }
    }

    /**
     * Din conținutul paginilor, articolelor, proiectelor și layout-urilor Theme Builder: atributele modulelor
     * `et_pb_contact_form` (pentru formularele de pe server) și Divi Dynamic Content (`@ET-DC@<base64 JSON>@`).
     */
    private function citesteFormulareSiContinutDinamic(): void
    {
        $surse = [];
        foreach ($this->pagini + $this->articole as $id => $p) {
            $surse[$id] = (string) $p['continut'];
        }
        foreach ($this->proiecte['proiecte'] as $id => $p) {
            $surse[$id] = (string) $p['continut'];
        }
        foreach ($this->themeBuilder['layouturi'] as $id => $l) {
            $surse[$id] = (string) $l['continut'];
        }
        ksort($surse);
        foreach ($surse as $id => $text) {
            if (str_contains($text, '[et_pb_contact_form')) {
                $ordine = 0;
                foreach (Continut\Shortcoduri::cauta(Continut\Shortcoduri::desface($text), 'et_pb_contact_form') as $f) {
                    $this->formulareDivi[] = ['postare' => (int) $id, 'ordine' => $ordine++,
                        'atribute' => array_intersect_key((array) $f['atribute'], array_flip(self::ATRIBUTE_FORMULAR_DIVI))];
                }
            }
            if (str_contains($text, '@ET-DC@') && preg_match_all('/@ET-DC@([A-Za-z0-9+\/=]+)@/', $text, $mm) > 0) {
                foreach ($mm[1] as $b64) {
                    $d = json_decode((string) base64_decode($b64, true), true);
                    $this->continutDinamic[] = is_array($d)
                        ? ['postare' => (int) $id, 'continut' => (string) ($d['content'] ?? ''), 'dinamic' => (bool) ($d['dynamic'] ?? false),
                            'setari' => is_array($d['settings'] ?? null) ? $d['settings'] : []]
                        : ['postare' => (int) $id, 'continut' => '', 'dinamic' => false, 'setari' => []];
                }
            }
        }
    }

    /** Un atașament după adresa fișierului lui (relativă la uploads/), sau null. */
    public function atasamentDupaFisier(string $rel): ?array
    {
        static $index = null;
        static $pentru = null;
        if ($pentru !== spl_object_id($this)) {
            $index = [];
            foreach ($this->atasamente as $id => $a) {
                foreach (array_merge([$a['fisier'], $a['original']], array_column($a['marimi'], 'fisier')) as $f) {
                    if ($f !== '') {
                        $index[$f] = $id;
                    }
                }
            }
            $pentru = spl_object_id($this);
        }
        $id = $index[$rel] ?? null;
        return $id !== null ? $this->atasamente[$id] : null;
    }
}
