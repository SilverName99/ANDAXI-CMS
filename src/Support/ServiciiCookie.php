<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Serviciile care pun cookie-uri (sau încarcă scripturi și conținut de la alții) pe site, fiecare într-o singură categorie
 * de acord (CookieConsent; docs/PLUGINURI.md, „Bannerul de cookie-uri”). Din ele:
 *  - categoriile arătate de banner: doar cele cu cel puțin un serviciu activ acum (o categorie fără nimic nu apare);
 *  - pagina „Politica de cookie-uri” generată din admin (Setări → Cookie și popup), cu fiecare cookie (nume, furnizor,
 *    scop, durată, pe categorii) și datele firmei din setări;
 *  - amprenta serviciilor, ținută la generare (`cookie_politica_amprenta`): alta acum = politica e veche (avertisment în admin).
 *
 * Un serviciu e activ după setări și pluginuri, nu după acord: ce s-ar putea încărca pe site. Statisticile proprii ale
 * CMS-ului (pluginul statistici) nu pun cookie-uri și nu cer acord, deci nu sunt aici (doar o notă în politică).
 */
final class ServiciiCookie
{
    /** Categoriile, în ordinea din banner și din politică: eticheta și descrierea pentru vizitator. */
    public const CATEGORII = [
        'necesare' => [
            'eticheta' => 'Necesare',
            'descriere' => 'Fac site-ul să funcționeze: coșul, contul, formularele și alegerea ta despre cookie-uri. Nu se pot refuza.',
        ],
        'statistici' => [
            'eticheta' => 'Statistici',
            'descriere' => 'Ne arată cum este folosit site-ul și de unde vin comenzile, ca să-l putem îmbunătăți.',
        ],
        'marketing' => [
            'eticheta' => 'Marketing',
            'descriere' => 'Măsoară reclamele noastre și ne ajută să arătăm reclame potrivite pe alte site-uri.',
        ],
        'extern' => [
            'eticheta' => 'Conținut extern',
            'descriere' => 'Conținut găzduit de alte site-uri, afișat în pagină: videoclipuri YouTube sau Vimeo, chatul cu noi.',
        ],
    ];

    /** Adresa paginii generate, dacă slug-ul e liber. */
    public const SLUG_POLITICA = 'politica-de-cookie-uri';

    /** Textul bannerului când cel din setări e gol (bannerul nu se ascunde pe tăcute). */
    public const TEXT_IMPLICIT = 'Folosim cookie-uri pentru funcționarea site-ului și, doar cu acordul tău, pentru statistici, '
        . 'reclame și conținut de pe alte site-uri. Poți accepta toate, le poți refuza sau poți alege din „Setări”. '
        . 'Îți poți schimba alegerea oricând, din butonul din colțul paginii.';

    /** @var array{youtube: bool, vimeo: bool}|null */
    private static ?array $video = null;

    /**
     * Serviciile active acum (cele necesare mereu). Fiecare: id, nume, furnizor, categorie, scop, cookie-uri
     * [[nume, scop, durată]].
     *
     * @param array<string, mixed> $setari Settings::all
     * @return list<array{id: string, nume: string, furnizor: string, categorie: string, scop: string, cookie: list<array{0: string, 1: string, 2: string}>}>
     */
    public static function servicii(array $setari, ?PDO $db): array
    {
        $da = static fn (string $cheie, string $implicit = '0'): bool => (string) ($setari[$cheie] ?? $implicit) === '1';
        $text = static fn (string $cheie): string => trim((string) ($setari[$cheie] ?? ''));
        $site = 'acest site';
        $rez = [];
        $adauga = static function (string $id, string $nume, string $furnizor, string $categorie, string $scop, array $cookie) use (&$rez): void {
            $rez[] = ['id' => $id, 'nume' => $nume, 'furnizor' => $furnizor, 'categorie' => $categorie, 'scop' => $scop, 'cookie' => $cookie];
        };

        $numeSesiune = (string) (ini_get('session.name') ?: 'PHPSESSID');
        $adauga('sesiune', 'Sesiunea site-ului', $site, 'necesare', 'Ține minte coșul, contul în care ești logat și formularele.',
            [[$numeSesiune, 'identificatorul sesiunii (coșul, contul, formularele)', '30 de zile']]);
        $adauga('consimtamant', 'Alegerea despre cookie-uri', $site, 'necesare', 'Ține minte ce ai ales în bannerul de cookie-uri.',
            [[CookieConsent::nume(), 'categoriile acceptate și cele arătate când ai ales', '6 luni']]);
        if ($da('welcome_popup_enabled')) {
            $adauga('anunt', 'Anunțul de bun venit', $site, 'necesare', 'Ține minte că ai închis anunțul, ca să nu-l mai vezi.',
                [[Brand::cheiePopupInchis(), 'stocare locală în browser: anunțul închis', 'până ștergi datele browserului']]);
        }
        $chatConfigurat = Plugins::permiteInceput('chat_live') && ChatLive::configurat($setari);
        $chatFaraAcord = (string) ($setari['tawk_requires_consent'] ?? '1') === ChatLive::CONSIMTAMANT_OPTIONAL;
        $cookieChat = [
            ['__tawkuuid, twk_uuid_*', 'recunoaște conversația ta cu noi', '6 luni'],
            ['TawkConnectionTime, twk_idm_key', 'păstrează legătura chatului deschisă', 'sesiunea'],
        ];
        if ($chatConfigurat && $chatFaraAcord) {
            $adauga('tawk', 'Chatul live (tawk.to)', 'tawk.to inc. (SUA)', 'necesare',
                'Chatul cu noi, pornit pe toate paginile (setat de site ca strict necesar).', $cookieChat);
        }

        if ($da('google_analytics_enabled') && ($text('google_analytics_id') !== '' || $text('google_analytics_code') !== '')) {
            $adauga('google_analytics', 'Google Analytics', 'Google Ireland Limited (Google LLC, SUA)', 'statistici',
                'Numără vizitele și paginile văzute, fără să afle cine ești.', [
                    ['_ga', 'deosebește vizitatorii (un identificator aleator)', '2 ani'],
                    ['_ga_*', 'păstrează starea vizitei', '2 ani'],
                ]);
        }
        if ($da('google_tag_manager_enabled') && ($text('google_tag_manager_id') !== '' || $text('google_tag_manager_head_code') !== '')) {
            $adauga('google_tag_manager', 'Google Tag Manager', 'Google Ireland Limited (Google LLC, SUA)', 'statistici',
                'Încarcă etichetele de măsurare ale site-ului; nu pune cookie-uri singur (le pun etichetele din el, care primesc acordul tău).',
                [['—', 'fără cookie al lui; etichetele din container respectă acordul (modul de consimțământ Google)', '—']]);
        }
        if ($da('microsoft_clarity_enabled') && $text('microsoft_clarity_project_id') !== '') {
            $adauga('clarity', 'Microsoft Clarity', 'Microsoft Ireland Operations Limited (Microsoft Corporation, SUA)', 'statistici',
                'Arată cum se folosesc paginile (clicuri, derulare), fără date pe care le scrii în formulare.', [
                    ['_clck', 'deosebește vizitatorii', '1 an'],
                    ['_clsk', 'leagă paginile aceleiași vizite', '1 zi'],
                    ['CLID, MUID', 'identificatori ai Microsoft pentru măsurare', '1 an'],
                ]);
        }
        if (Plugins::vizibil('magazin') && SursaComenzii::pornita($setari)) {
            $adauga('sursa_comenzii', 'Sursa comenzii', $site, 'statistici',
                'Ține minte de unde ai venit (căutare, reclamă, newsletter, alt site), ca să știm ce aduce comenzi.', [
                    [SursaComenzii::COOKIE, 'felul vizitei (fără identificator, fără adresă)', '30 de zile'],
                    ['bv_gclid', 'codul clicului pe o reclamă Google, pentru comanda plasată după el', '30 de zile'],
                ]);
        }
        if (Plugins::vizibil('newsletter')) {
            $adauga('newsletter_campanie', 'Campania de newsletter', $site, 'statistici',
                'Ține minte newsletterul din care ai deschis site-ul, ca să știm ce comenzi aduce fiecare campanie.',
                [['nl_cid', 'numărul campaniei de newsletter', '7 zile']]);
        }

        if ($da('google_ads_enabled') && $text('google_ads_conversion_id') !== '') {
            $adauga('google_ads', 'Google Ads', 'Google Ireland Limited (Google LLC, SUA)', 'marketing',
                'Măsoară ce comenzi vin din reclamele noastre Google și ajută Google să arate reclame potrivite.', [
                    ['_gcl_au, _gcl_aw', 'leagă un clic pe reclamă de o comandă', '90 de zile'],
                ]);
        }

        $video = self::videoExtern($db);
        if ($video['youtube']) {
            $adauga('youtube', 'Videoclipuri YouTube', 'Google Ireland Limited (YouTube; Google LLC, SUA)', 'extern',
                'Playerul YouTube (youtube-nocookie.com) din articole; fără acord se încarcă doar când apeși pe videoclip.', [
                    ['VISITOR_INFO1_LIVE, VISITOR_PRIVACY_METADATA', 'setările playerului și estimarea vitezei', '6 luni'],
                    ['YSC', 'deosebește vizionările', 'sesiunea'],
                ]);
        }
        if ($video['vimeo']) {
            $adauga('vimeo', 'Videoclipuri Vimeo', 'Vimeo.com, Inc. (SUA)', 'extern',
                'Playerul Vimeo din articole, cu „nu urmări” (dnt=1); fără acord se încarcă doar când apeși pe videoclip.',
                [['—', 'cu dnt=1 playerul nu pune cookie-uri de urmărire', '—']]);
        }
        if ($chatConfigurat && !$chatFaraAcord) {
            $adauga('tawk', 'Chatul live (tawk.to)', 'tawk.to inc. (SUA)', 'extern', 'Chatul cu noi, din colțul paginii.', $cookieChat);
        }
        return $rez;
    }

    /**
     * Categoriile de acord cu cel puțin un serviciu activ (fără „necesare”), în ordinea bannerului.
     *
     * @param list<array{categorie: string}>|null $servicii
     * @return list<string>
     */
    public static function categoriiActive(array $setari, ?PDO $db, ?array $servicii = null): array
    {
        $folosite = array_column($servicii ?? self::servicii($setari, $db), 'categorie');
        return array_values(array_filter(array_keys(CookieConsent::LITERE), static fn (string $c): bool => in_array($c, $folosite, true)));
    }

    /**
     * Amprenta serviciilor (ce și ce cookie-uri): se ține la generarea politicii, iar alta acum înseamnă politică veche.
     *
     * @param list<array{id: string, categorie: string, cookie: list<array{0: string}>}> $servicii
     */
    public static function amprenta(array $servicii): string
    {
        $parti = [];
        foreach ($servicii as $s) {
            $parti[] = $s['id'] . ':' . $s['categorie'] . ':' . implode(',', array_column($s['cookie'], 0));
        }
        sort($parti);
        return substr(hash('sha256', implode('|', $parti)), 0, 16);
    }

    /** Culoarea celor două butoane mari: Setări → Cookies (`cookie_banner_culoare`, #rrggbb), altfel culoarea principală a temei. */
    public static function culoareButoane(array $setari): string
    {
        $culoare = strtolower(trim((string) ($setari['cookie_banner_culoare'] ?? '')));
        return preg_match('/^#[0-9a-f]{6}$/D', $culoare) === 1 ? $culoare : Tema::culoarePrincipala();
    }

    /** Textul de pe un buton plin: alb sau aproape negru, după luminozitatea culorii (contrastul cerut de WCAG). */
    public static function culoareTextPe(string $culoare): string
    {
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/iD', $culoare, $m) !== 1) {
            return '#ffffff';
        }
        $canal = static function (string $hex): float {
            $v = hexdec($hex) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $canal($m[1]) + 0.7152 * $canal($m[2]) + 0.0722 * $canal($m[3]);
        // Contrastul cu albul (1.05 / (l + 0.05)) față de cel cu #111827 (l ≈ 0.012): albul doar unde câștigă.
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.0623) ? '#ffffff' : '#111827';
    }

    /**
     * Videoclipurile din articolele publicate (pluginul blog): YouTube, Vimeo. O interogare pe cerere, doar cu blogul pe site.
     *
     * @return array{youtube: bool, vimeo: bool}
     */
    public static function videoExtern(?PDO $db): array
    {
        if (self::$video !== null) {
            return self::$video;
        }
        $rez = ['youtube' => false, 'vimeo' => false];
        if ($db instanceof PDO && Plugins::vizibil('blog')) {
            try {
                $rand = $db->query("SELECT
                        EXISTS(SELECT 1 FROM blog_posts WHERE deleted_at IS NULL AND is_published = 1 AND video_url LIKE '%youtu%') AS yt,
                        EXISTS(SELECT 1 FROM blog_posts WHERE deleted_at IS NULL AND is_published = 1 AND video_url LIKE '%vimeo%') AS vm")
                    ->fetch(PDO::FETCH_ASSOC);
                $rez = ['youtube' => (int) ($rand['yt'] ?? 0) === 1, 'vimeo' => (int) ($rand['vm'] ?? 0) === 1];
            } catch (Throwable) {
                // Fără tabelul blogului încă: nimic de arătat.
            }
        }
        return self::$video = $rez;
    }

    /** Doar pentru teste: uită ce s-a citit din baza de date în cererea de acum. */
    public static function uitaCitirile(): void
    {
        self::$video = null;
    }

    /**
     * Pagina „Politica de cookie-uri”: HTML-ul paginii din serviciile active și datele firmei din setări (operatorul din
     * Acorduri GDPR, altfel numele magazinului și contactul). Text simplu, în română: un punct de plecare, de verificat.
     *
     * @param list<array{id: string, nume: string, furnizor: string, categorie: string, scop: string, cookie: list<array{0: string, 1: string, 2: string}>}> $servicii
     */
    public static function htmlPolitica(array $setari, array $servicii, string $data): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES);
        $val = static fn (string $cheie): string => trim((string) ($setari[$cheie] ?? ''));
        $operator = $val('gdpr_operator_nume') !== '' ? $val('gdpr_operator_nume') : Brand::numeSite($setari);
        $date = array_values(array_filter([
            $val('gdpr_operator_sediu') !== '' ? 'sediul: ' . $val('gdpr_operator_sediu') : '',
            $val('gdpr_operator_cui') !== '' ? 'CUI ' . $val('gdpr_operator_cui') : '',
            $val('gdpr_operator_regcom') !== '' ? 'nr. Reg. Com. ' . $val('gdpr_operator_regcom') : '',
        ]));
        $email = $val('gdpr_operator_email') !== '' ? $val('gdpr_operator_email') : $val('contact_email');
        $telefon = $val('gdpr_operator_telefon') !== '' ? $val('gdpr_operator_telefon') : $val('contact_phone');
        $contact = array_values(array_filter([
            $email !== '' ? 'email <a href="mailto:' . $e($email) . '">' . $e($email) . '</a>' : '',
            $telefon !== '' ? 'telefon ' . $e($telefon) : '',
        ]));

        $h = [];
        $h[] = '<section class="politica-cookie">';
        $h[] = '<h1>Politica de cookie-uri</h1>';
        $h[] = '<p class="politica-cookie__data">Ultima actualizare: ' . $e($data) . '.</p>';
        $h[] = '<h2>Cine suntem</h2>';
        $h[] = '<p>Site-ul este administrat de <strong>' . $e($operator) . '</strong>' . ($date !== [] ? ', ' . $e(implode(', ', $date)) : '') . '.'
            . ($contact !== [] ? ' Ne poți scrie oricând despre datele tale: ' . implode(', ', $contact) . '.' : '') . '</p>';
        $h[] = '<h2>Ce sunt cookie-urile</h2>';
        $h[] = '<p>Cookie-urile sunt fișiere mici pe care un site le păstrează în browserul tău. Unele sunt necesare ca site-ul să '
            . 'funcționeze (de exemplu coșul). Altele, pentru statistici, reclame sau conținut de pe alte site-uri, le folosim '
            . 'doar dacă ești de acord. Fără acordul tău, codurile acestor servicii nici nu sunt trimise în pagină.</p>';
        $h[] = '<h2>Cum îți dai sau îți retragi acordul</h2>';
        $h[] = '<p>La prima vizită alegi din banner: „Accept toate”, „Refuz” sau „Setări”, unde alegi pe categorii. Alegerea se '
            . 'păstrează 6 luni. O poți schimba oricând din butonul „Cookie-uri” din colțul paginii sau de aici: '
            . '<a href="#setari-cookie" data-cookie-setari>schimbă setările cookie-urilor</a>. Când retragi acordul, pagina se '
            . 'reîncarcă fără serviciile refuzate.</p>';
        $h[] = '<h2>Cookie-urile pe care le folosim</h2>';
        foreach (self::CATEGORII as $categorie => $info) {
            $dinCategorie = array_values(array_filter($servicii, static fn (array $s): bool => $s['categorie'] === $categorie));
            if ($dinCategorie === []) {
                continue;
            }
            $h[] = '<h3>' . $e($info['eticheta']) . '</h3>';
            $h[] = '<p>' . $e($info['descriere']) . '</p>';
            $h[] = '<div class="politica-cookie__tabel"><table>';
            $h[] = '<thead><tr><th scope="col">Cookie</th><th scope="col">Serviciul și furnizorul</th><th scope="col">Scop</th>'
                . '<th scope="col">Durată</th><th scope="col">Categorie</th></tr></thead><tbody>';
            foreach ($dinCategorie as $s) {
                foreach ($s['cookie'] as [$nume, $scop, $durata]) {
                    $h[] = '<tr><td><code>' . $e($nume) . '</code></td><td>' . $e($s['nume']) . '<br><small>' . $e($s['furnizor'])
                        . '</small></td><td>' . $e($scop) . '. ' . $e($s['scop']) . '</td><td>' . $e($durata) . '</td><td>'
                        . $e($info['eticheta']) . '</td></tr>';
                }
            }
            $h[] = '</tbody></table></div>';
        }
        if (Plugins::vizibil('statistici')) {
            $h[] = '<h2>Statisticile noastre, fără cookie-uri</h2>';
            $h[] = '<p>Numărăm vizitele și cu o analiză proprie, care nu pune cookie-uri și nu păstrează adresa IP: pentru a număra '
                . 'vizitele unei zile folosim un cod care se schimbă zilnic. De aceea ea nu cere acordul tău.</p>';
        }
        $furnizoriSua = array_values(array_unique(array_map(static fn (array $s): string => $s['nume'],
            array_filter($servicii, static fn (array $s): bool => str_contains($s['furnizor'], 'SUA')))));
        if ($furnizoriSua !== []) {
            $h[] = '<h2>Date trimise în afara Uniunii Europene</h2>';
            $h[] = '<p>Unele servicii (' . $e(implode(', ', $furnizoriSua)) . ') aparțin unor firme din Statele Unite, unde datele pot '
                . 'ajunge. Ele le trimit doar după acordul tău pentru categoria lor (sau, la cele necesare, doar cât e nevoie).</p>';
        }
        $h[] = '<h2>Cum ștergi cookie-urile</h2>';
        $h[] = '<p>Poți șterge sau bloca oricând cookie-urile din setările browserului (de obicei la „Confidențialitate” sau '
            . '„Date de navigare”). Dacă blochezi cookie-urile necesare, unele părți ale site-ului (coșul, contul) nu vor merge.</p>';
        $h[] = '</section>';
        return implode("\n", $h);
    }

    /** Stilul paginii generate (doar ea, în css_content): text lizibil, tabelul cu derulare pe telefon. */
    public static function cssPolitica(): string
    {
        return '.politica-cookie{max-width:880px;margin:0 auto;padding:32px 16px 48px;line-height:1.6;color:#1f2937;}'
            . '.politica-cookie h1{margin:0 0 6px;font-size:2rem;}'
            . '.politica-cookie h2{margin:28px 0 8px;font-size:1.35rem;}'
            . '.politica-cookie h3{margin:20px 0 6px;font-size:1.1rem;}'
            . '.politica-cookie__data{color:#64748b;margin:0 0 16px;}'
            . '.politica-cookie__tabel{overflow-x:auto;margin:8px 0 12px;}'
            . '.politica-cookie table{width:100%;border-collapse:collapse;font-size:.92rem;}'
            . '.politica-cookie th,.politica-cookie td{border:1px solid #e2e8f0;padding:8px 10px;text-align:left;vertical-align:top;}'
            . '.politica-cookie th{background:#f8fafc;}'
            . '.politica-cookie code{white-space:nowrap;}';
    }

    /**
     * Face sau rescrie pagina politicii (pagina ținută în `cookie_politica_pagina_id`; altfel una nouă, la SLUG_POLITICA sau,
     * dacă slug-ul e luat de altă pagină, cu un număr după el) și ține minte adresa și amprenta serviciilor.
     *
     * @return array{id: int, url: string, creata: bool}
     */
    public static function genereazaPagina(PDO $db, array $setari, ?string $data = null): array
    {
        $servicii = self::servicii($setari, $db);
        $html = self::htmlPolitica($setari, $servicii, $data ?? date('d.m.Y'));
        $css = self::cssPolitica();
        $id = (int) ($setari['cookie_politica_pagina_id'] ?? 0);
        $slug = '';
        if ($id > 0) {
            $stmt = $db->prepare('SELECT slug FROM pages WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([$id]);
            $gasit = $stmt->fetchColumn();
            $slug = is_string($gasit) ? $gasit : '';
        }
        $creata = false;
        if ($slug !== '') {
            $db->prepare('UPDATE pages SET html_content = ?, css_content = ? WHERE id = ?')->execute([$html, $css, $id]);
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM pages WHERE slug = ?');
            for ($n = 1; $n <= 50; $n++) {
                $slug = self::SLUG_POLITICA . ($n > 1 ? '-' . $n : '');
                $stmt->execute([$slug]);
                if ((int) $stmt->fetchColumn() === 0) {
                    break;
                }
            }
            $db->prepare('INSERT INTO pages (title, slug, html_content, css_content, js_content, is_published) VALUES (?, ?, ?, ?, ?, 1)')
                ->execute(['Politica de cookie-uri', $slug, $html, $css, '']);
            $id = (int) $db->lastInsertId();
            $creata = true;
        }
        $url = '/' . $slug;
        Settings::save($db, [
            'cookie_politica_pagina_id' => (string) $id,
            'cookie_politica_url' => $url,
            'cookie_politica_amprenta' => self::amprenta($servicii),
        ]);
        return ['id' => $id, 'url' => $url, 'creata' => $creata];
    }
}
