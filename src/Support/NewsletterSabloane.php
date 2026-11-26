<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Șabloanele de pornire pentru un newsletter nou, cu înfățișarea magazinului:
 * logo, culorile site-ului și carduri de produs. Sunt făcute DOAR din blocurile
 * builderului (din 26 nov 2026 și blocurile „Logo” și „Produse”), deci după
 * alegere se modifică exact ca orice alt conținut.
 *
 * Produsele din carduri sunt cele mai noi produse active, cu poză, alese în
 * blocul „Produse” („Aleg eu”); omul le poate schimba sau trece pe „Automat”.
 * Subsolul (datele firmei, de ce primește omul emailul, linkul de dezabonare)
 * NU e în șablon: se adaugă automat, personal, la trimitere
 * (NewsletterService::withNewsletterFooter); înainte de 26 nov șabloanele aveau
 * și un bloc de text cu numele magazinului, acum dublura lui.
 *
 * În codul comun (portat din codul vechi `42984c1`, `7123076`): logo-ul, textul
 * lui alternativ și culorile vin din tema pachetului site-ului (`tema/tema.json`
 * → `newsletter`, Tema::newsletter()); fără ele, fără logo și cu paleta neutră.
 * Numele magazinului e Brand::numeExpeditor (numele expeditorului emailurilor),
 * adresa site-ului din APP_URL, altfel Brand::gazda().
 */
final class NewsletterSabloane
{
    /** O culoare a temei (Tema::CULORI_NEWSLETTER: principala, inchisa, fundal_deschis, text, text_secundar, linie). */
    private static function culoare(string $nume): string
    {
        return (string) (Tema::newsletter()['culori'][$nume] ?? Tema::CULORI_NEWSLETTER[$nume]);
    }

    /**
     * @return array<string, array{titlu:string, descriere:string, blocks:list<array>}>
     */
    public static function lista(PDO $db, array $settings): array
    {
        $produse = self::produse($db, 4);
        $numeMagazin = Brand::numeExpeditor($settings);
        // În codul comun: fără catalog pe site, șabloanele n-au carduri de produs, iar butoanele duc la prima pagină.
        $cuCatalog = Plugins::vizibil('catalog');
        $spreMagazin = $cuCatalog ? '/magazin' : '/';

        return [
            'noutati' => [
                'titlu' => 'Noutăți și produse',
                'descriere' => 'Logo, un mesaj scurt, 4 produse și buton spre magazin.',
                'blocks' => array_merge(
                    [self::logo()],
                    [
                        self::titlu('Noutățile lunii de la ' . $numeMagazin),
                        self::text("Bună,\n\nÎți scriem câteva rânduri despre ce e nou la noi luna aceasta. Scrie aici mesajul tău: o veste, un sfat, o recomandare.", 'left'),
                    ],
                    $cuCatalog ? self::carduriProduse($produse, 'Produse recomandate') : [],
                    [self::buton($cuCatalog ? 'Vezi tot magazinul' : 'Vezi site-ul', $spreMagazin)]
                ),
            ],
            'oferta' => [
                'titlu' => 'Ofertă / reducere',
                'descriere' => 'Titlu mare cu reducerea, codul de cupon, produse în ofertă.',
                'blocks' => array_merge(
                    [self::logo()],
                    [
                        self::titlu('-20% la produsele selectate', 34, 26),
                        self::text("Doar până duminică: folosește codul de mai jos în coș și primești reducerea pe loc.", 'center'),
                        [
                            'type' => 'coupon', 'code' => 'TOAMNA20', 'content' => '', 'note' => '', 'align' => 'center',
                            'background' => '#ffffff', 'text_color' => self::culoare('text'), 'font_size' => 24,
                            'border_color' => self::culoare('principala'), 'code_background' => self::culoare('fundal_deschis'),
                            'code_color' => self::culoare('inchisa'), 'padding_top' => 8, 'padding_bottom' => 18,
                        ],
                    ],
                    $cuCatalog ? self::carduriProduse(array_slice($produse, 0, 2), 'În ofertă') : [],
                    [self::buton($cuCatalog ? 'Cumpără acum' : 'Vezi site-ul', $spreMagazin)]
                ),
            ],
            'sfat' => [
                'titlu' => 'Sfat / articol',
                'descriere' => 'Un sfat util, un citat și link spre blog sau spre un produs.',
                'blocks' => array_merge(
                    [self::logo()],
                    [
                        self::titlu('Un sfat pentru săptămâna aceasta'),
                        self::text("Scrie aici sfatul tău, în 3–4 rânduri. Oamenii citesc pe telefon: fraze scurte, un singur mesaj.", 'left'),
                        [
                            'type' => 'quote', 'content' => 'Un citat scurt sau părerea unui client.', 'author' => 'Numele clientului',
                            'align' => 'left', 'background' => self::culoare('fundal_deschis'), 'text_color' => self::culoare('inchisa'),
                            'border_color' => self::culoare('principala'), 'font_size' => 16,
                            'padding_top' => 14, 'padding_bottom' => 14, 'padding_left' => 20, 'padding_right' => 24,
                        ],
                        self::buton('Citește tot articolul', '/blog'),
                    ]
                ),
            ],
            'anunt' => [
                'titlu' => 'Anunț scurt',
                'descriere' => 'Logo, titlu, două rânduri și un buton. Pentru vești rapide.',
                'blocks' => array_merge(
                    [self::logo()],
                    [
                        self::titlu('Titlul anunțului'),
                        self::text("Scrie aici anunțul (program de sărbători, produs nou, livrare gratuită).", 'center'),
                        self::buton('Află mai multe', '/'),
                    ]
                ),
            ],
        ];
    }

    /**
     * Emailul de confirmare a abonării (dubla confirmare): logo, culorile
     * magazinului, un singur buton. Tranzacțional: fără subsol de dezabonare.
     */
    public static function emailConfirmare(string $url, string $numeMagazin): array
    {
        return array_merge(
            self::antet(),
            [
                self::titlu('Mai e un singur pas', 26, 22),
                self::text(
                    "Bună,\n\nCineva (sperăm că tu) a cerut să primească newsletterul " . $numeMagazin
                    . " pe această adresă. Apasă butonul de mai jos ca să confirmi abonarea.\n\n"
                    . "Dacă nu ai cerut tu, ignoră acest email: nu te abonăm și nu-ți mai scriem.",
                    'left'
                ),
                self::buton('Confirm abonarea', $url),
                [
                    'type' => 'text',
                    'content' => 'Linkul e valabil ' . NewsletterService::ZILE_CONFIRMARE . ' de zile.',
                    'align' => 'center', 'background' => '#ffffff', 'text_color' => self::culoare('text_secundar'),
                    'font_size' => 13, 'font_size_mobile' => 13, 'padding_bottom' => 20,
                ],
            ],
            self::subsolSimplu($numeMagazin)
        );
    }

    /**
     * Emailul cu linkul personal de dezabonare, cerut de pe pagina unui link
     * vechi. Tranzacțional: fără subsol de newsletter.
     */
    public static function emailLinkDezabonare(string $url, string $numeMagazin): array
    {
        return array_merge(
            self::antet(),
            [
                self::titlu('Linkul tău de dezabonare', 26, 22),
                self::text(
                    "Bună,\n\nCineva (probabil tu) a cerut, de pe site, linkul de dezabonare de la newsletterul "
                    . $numeMagazin . " pentru această adresă. Apasă butonul de mai jos și apoi „Confirm dezabonarea”.\n\n"
                    . "Dacă nu ai cerut tu, ignoră acest email: rămâi abonat și nu se schimbă nimic.",
                    'left'
                ),
                self::buton('Mergi la dezabonare', $url),
            ],
            self::subsolSimplu($numeMagazin)
        );
    }

    private static function subsolSimplu(string $numeMagazin): array
    {
        $site = preg_replace('#^https?://#', '', NewsletterService::appBaseUrl()) ?: Brand::gazda();
        return [[
            'type' => 'text', 'content' => '<b>' . $numeMagazin . '</b> · ' . $site,
            'align' => 'center', 'background' => self::culoare('fundal_deschis'), 'text_color' => self::culoare('text_secundar'),
            'font_size' => 13, 'font_size_mobile' => 13, 'padding_top' => 16, 'padding_bottom' => 16,
        ]];
    }

    /** Cele mai noi produse active, cu poză și preț. */
    private static function produse(PDO $db, int $cate): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT id, name, slug, price, sale_price, image_url
                 FROM products
                 WHERE is_active = 1 AND deleted_at IS NULL AND image_url IS NOT NULL AND image_url <> ""
                 ORDER BY badge_best_seller DESC, id DESC
                 LIMIT ' . max(1, min(8, $cate))
            );
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Logo-ul din temă (pe un site fără logo în temă, niciun bloc: titlul colorat deschide emailul). */
    private static function antet(): array
    {
        $tema = Tema::newsletter();
        if ($tema['logo'] === null) {
            return [];
        }
        return [[
            'type' => 'image', 'image_url' => $tema['logo'], 'alt' => $tema['logo_alt'] !== '' ? $tema['logo_alt'] : Brand::numeSite([]),
            'width' => 40, 'align' => 'center',
            'link_url' => '/', 'background' => '#ffffff', 'padding_top' => 20, 'padding_bottom' => 16,
        ]];
    }

    /**
     * Blocul „Logo” al șabloanelor: logo-ul site-ului (din temă sau din setări, ales la randare; fără logo, numele
     * magazinului) pe fundal alb, cu link spre prima pagină.
     */
    private static function logo(): array
    {
        return [
            'type' => 'logo', 'image_url' => '', 'width' => 180, 'align' => 'center', 'link_url' => '/',
            'background' => '#ffffff', 'text_color' => self::culoare('principala'), 'font_size' => 26,
            'padding_top' => 20, 'padding_bottom' => 16,
        ];
    }

    private static function titlu(string $text, int $marime = 28, int $marimeMobil = 22): array
    {
        return [
            'type' => 'header', 'content' => $text, 'align' => 'center',
            'background' => self::culoare('principala'), 'text_color' => '#ffffff',
            'font_size' => $marime, 'font_size_mobile' => $marimeMobil,
            'padding_top' => 26, 'padding_bottom' => 26, 'padding_left' => 20, 'padding_right' => 20,
        ];
    }

    private static function text(string $continut, string $aliniere): array
    {
        return [
            'type' => 'text', 'content' => $continut, 'align' => $aliniere,
            'background' => '#ffffff', 'text_color' => self::culoare('text'),
            'font_size' => 16, 'font_size_mobile' => 15,
            'padding_top' => 20, 'padding_bottom' => 12, 'padding_left' => 28, 'padding_right' => 28,
        ];
    }

    private static function buton(string $eticheta, string $adresa): array
    {
        return [
            'type' => 'button', 'label' => $eticheta, 'url' => $adresa, 'align' => 'center',
            'background' => '#ffffff', 'button_color' => self::culoare('principala'), 'text_color' => '#ffffff',
            'border_radius' => 6, 'font_size' => 16, 'btn_pad_v' => 13, 'btn_pad_h' => 28,
            'padding_top' => 16, 'padding_bottom' => 26,
        ];
    }

    /**
     * Blocul „Produse” („Aleg eu”, câte două pe rând, pe telefon unul sub altul) cu produsele date; fără produse cu poză,
     * blocul „Automat” cu cele mai noi produse (se umple singur când apar produse).
     */
    private static function carduriProduse(array $produse, string $titluSectiune): array
    {
        $alese = [];
        foreach ($produse as $produs) {
            $slug = trim((string) ($produs['slug'] ?? ''));
            $pret = (float) ($produs['price'] ?? 0);
            $redus = $produs['sale_price'] !== null && (float) $produs['sale_price'] > 0 && (float) $produs['sale_price'] < $pret
                ? (float) $produs['sale_price'] : null;
            $alese[] = [
                'id' => (int) ($produs['id'] ?? 0), 'name' => (string) ($produs['name'] ?? ''),
                'image_url' => (string) ($produs['image_url'] ?? ''), 'url' => $slug !== '' ? '/produs/' . rawurlencode($slug) : '',
                'price' => $redus ?? $pret, 'old_price' => $redus !== null ? $pret : null,
            ];
        }
        $alese = array_values(array_filter($alese, static fn (array $p): bool => $p['id'] > 0));
        return [[
            'type' => 'products', 'mod' => $alese !== [] ? 'manual' : 'auto', 'sursa' => 'noi', 'numar' => max(2, count($alese)),
            'pe_rand' => 2, 'produse' => $alese, 'title' => $titluSectiune, 'title_color' => self::culoare('inchisa'),
            'background' => '#ffffff', 'card_background' => '#ffffff', 'text_color' => self::culoare('text'),
            'price_color' => self::culoare('inchisa'), 'button_color' => self::culoare('principala'), 'button_text_color' => '#ffffff',
            'button_label' => 'Vezi produsul', 'show_price' => 1, 'show_button' => 1,
            'padding_top' => 18, 'padding_bottom' => 8, 'padding_left' => 12, 'padding_right' => 12,
        ]];
    }
}
