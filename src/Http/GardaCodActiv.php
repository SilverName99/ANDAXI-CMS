<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\AdminActivityLog;
use App\Support\Auth;
use App\Support\CodActiv;
use App\Support\Database;
use App\Support\Flash;
use PDO;
use Throwable;

/**
 * Codul activ salvat de un rol parțial (revizuirea de securitate din 18 noiembrie 2026, N1b; docs/SECURITATE.md, „Codul
 * activ”). Garda routerului, după GardaPluginuri: la orice POST din admin al unui admin logat care nu e administratorul
 * general (magazin, blog, emailuri, Cloud user), pe o adresă la care rolul lui are voie, se citesc părțile care pot rula
 * din tot ce trimite formularul (`App\Support\CodActiv`: `<script>`, atribute `on…`, adrese `javascript:`, `srcdoc`,
 * `<base>`, câmpurile de JavaScript). Fără părți, cererea merge mai departe, neatinsă (aproape orice salvare).
 *
 * Cu părți, ele trebuie să fie deja în ce e salvat acum pentru ce se editează (rândul șablonului, al produsului, al
 * articolului, al newsletterului…, `valoriSalvate`): un conținut cu cod scris de administratorul general se poate salva
 * neschimbat de un rol parțial (de exemplu un text corectat într-un șablon cu JavaScript), cu codul exact cum era (cel mult
 * de câte ori era) sau cu mai puțin cod. Altfel salvarea e refuzată întreagă, cu mesajul din `MESAJ` și primul cod găsit;
 * nimic nu se scoate pe tăcute și nimic nu se salvează. Refuzul se notează în Jurnal activitate („cod_activ_refuzat”).
 *
 * Importurile din fișiere (articole, recenzii) nu trec prin formular: le verifică AdminController la citirea fișierului, cu
 * aceeași regulă (`CodActiv::noi`).
 */
final class GardaCodActiv
{
    public const MESAJ = 'Salvarea a fost refuzată: conținutul are cod care poate rula în browser (un <script>, un atribut on…, '
        . 'o adresă javascript: sau JavaScript), nou sau schimbat față de ce era salvat. Doar Administratorul General poate '
        . 'salva cod. Poți salva conținutul fără acest cod (sau cu codul de dinainte, neschimbat), ori îi poți cere '
        . 'Administratorului General să-l salveze.';

    /** Acțiunea din Jurnal activitate. */
    public const JURNAL = 'cod_activ_refuzat';

    public static function permite(string $metoda, string $tipar, array $parametri): bool
    {
        if (strtoupper($metoda) !== 'POST' || !($tipar === '/admin' || str_starts_with($tipar, '/admin/'))) {
            return true;
        }
        // Fără un admin logat (logarea însăși, o sesiune expirată) sau pentru administratorul general: nimic de verificat.
        if (!Auth::esteLogatFaraReimprospatare() || Auth::isGeneralAdmin()) {
            return true;
        }
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH) ?: '/admin');
        // O adresă la care rolul n-are voie o refuză controllerul (redirecționarea lui de azi).
        if (!Auth::canAccessPath($cale)) {
            return true;
        }
        $noi = CodActiv::partiDin(self::valoriTrimise());
        if ($noi === []) {
            return true;
        }
        $db = self::db();
        $vechi = $db instanceof PDO ? CodActiv::partiDin(self::valoriSalvate($db, $tipar, $parametri, $_POST)) : [];
        $neacoperite = CodActiv::noi($noi, $vechi);
        if ($neacoperite === []) {
            return true;
        }
        self::refuza($db, $tipar, $cale, $neacoperite);
        return false;
    }

    /**
     * Mesajul refuzului, cu primul cod nou găsit (scurtat).
     *
     * @param list<string> $neacoperite
     */
    public static function mesaj(array $neacoperite): string
    {
        $primul = $neacoperite !== [] ? CodActiv::descrie($neacoperite[0]) : '';
        return self::MESAJ . ($primul !== '' ? ' Primul cod găsit: „' . $primul . '”.' : '');
    }

    /**
     * Ce trimite cererea: câmpurile formularului și, pentru cererile JSON (fetch), corpul decodat.
     *
     * @return array<array-key, mixed>
     */
    private static function valoriTrimise(): array
    {
        $valori = is_array($_POST) ? $_POST : [];
        $tip = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (str_contains($tip, 'json')) {
            $corp = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($corp)) {
                $valori['__corp_json'] = $corp;
            }
        }
        return $valori;
    }

    /**
     * Ce e salvat acum pentru ce editează cererea (rândurile din bază, ca text): codul din ele poate rămâne.
     * O adresă care nu e aici (sau un rând nou) nu are nimic salvat: orice cod trimis e nou.
     *
     * @param array<string, string> $parametri
     * @param array<array-key, mixed> $post
     * @return array<array-key, mixed>
     */
    public static function valoriSalvate(PDO $db, string $tipar, array $parametri, array $post): array
    {
        $idPost = static fn (string $camp): int => (int) ($post[$camp] ?? 0);
        $idRuta = (int) ($parametri['id'] ?? 0);
        try {
            switch ($tipar) {
                case '/admin/products/templates':
                case '/admin/products/templates/create-basic':
                    return self::rand($db, 'product_templates', $idPost('id'));
                case '/admin/products/{id}/update':
                    return [
                        self::rand($db, 'products', $idRuta),
                        self::randuri($db, 'SELECT value FROM product_extra_field_values WHERE product_id = :id', ['id' => $idRuta]),
                        self::seo($db, 'product', $idRuta),
                    ];
                case '/admin/products/fields':
                    return self::randuri($db, 'SELECT * FROM product_extra_fields', []);
                case '/admin/categories/{id}/update':
                    return [self::rand($db, 'product_categories', $idRuta), self::seo($db, 'category', $idRuta)];
                case '/admin/products/reviews':
                    return self::rand($db, 'product_reviews', $idPost('id'));
                case '/admin/promo-products':
                    return self::rand($db, 'promotional_products', $idPost('id'));
                case '/admin/coupons':
                    return self::rand($db, 'coupons', $idPost('id'));
                case '/admin/blog/posts':
                    return [self::rand($db, 'blog_posts', $idPost('id')), self::seo($db, 'blog_post', $idPost('id'))];
                case '/admin/blog/templates':
                    return self::rand($db, 'blog_templates', $idPost('id'));
                case '/admin/blog/authors':
                    return self::rand($db, 'blog_authors', $idPost('id'));
                case '/admin/gallery/{id}/update':
                    return self::rand($db, 'gallery_images', $idRuta);
                case '/admin/settings/floating-cart':
                case '/admin/statistici/setari':
                    return self::setari($db, array_keys($post));
                case '/admin/emails/builder':
                    $tip = (string) ($post['builder_type'] ?? 'template');
                    if ($tip === 'campaign') {
                        return self::rand($db, 'newsletter_campaigns', $idPost('builder_id'));
                    }
                    if ($tip === 'ecommerce') {
                        return self::setariSablonEmail($db, (string) ($post['builder_ref'] ?? ''));
                    }
                    return self::rand($db, 'newsletter_templates', $idPost('builder_id'));
                case '/admin/emails':
                    return [
                        self::rand($db, 'newsletter_templates', $idPost('newsletter_id')),
                        self::rand($db, 'newsletter_campaigns', $idPost('campaign_id')),
                        self::rand($db, 'newsletter_optin_forms', $idPost('optin_form_id')),
                        self::rand($db, 'newsletter_lists', $idPost('list_id')),
                        self::rand($db, 'newsletter_subscribers', $idPost('subscriber_id')),
                        self::setariSablonEmail($db, (string) ($post['template_type'] ?? '')),
                        self::setariSablonEmail($db, (string) ($post['ecommerce_template_type'] ?? '')),
                        self::setari($db, array_keys($post)),
                    ];
            }
        } catch (Throwable) {
            // O bază care nu răspunde: nimic salvat de luat în seamă (codul trimis rămâne nou).
        }
        return [];
    }

    /** @return array<string, mixed> */
    private static function rand(PDO $db, string $tabel, int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        $stmt = $db->prepare('SELECT * FROM ' . $tabel . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($rand) ? $rand : [];
    }

    /**
     * @param array<string, mixed> $parametri
     * @return list<array<string, mixed>>
     */
    private static function randuri(PDO $db, string $sql, array $parametri): array
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($parametri);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    private static function seo(PDO $db, string $tip, int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        return self::randuri($db, 'SELECT title, description, canonical_url, image_url, intro_html FROM seo_pages
            WHERE page_type = :tip AND page_ref = :ref', ['tip' => $tip, 'ref' => (string) $id]);
    }

    /**
     * Setările cu numele câmpurilor trimise (formularele de setări își trimit câmpurile cu numele setării).
     *
     * @param list<array-key> $chei
     * @return array<string, string>
     */
    private static function setari(PDO $db, array $chei): array
    {
        $chei = array_values(array_unique(array_filter(array_map('strval', $chei), static fn (string $c): bool => $c !== '')));
        if ($chei === []) {
            return [];
        }
        $locuri = implode(',', array_fill(0, count($chei), '?'));
        $stmt = $db->prepare('SELECT `key`, value FROM settings WHERE `key` IN (' . $locuri . ')');
        $stmt->execute($chei);
        $setari = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rand) {
            $setari[(string) $rand['key']] = (string) ($rand['value'] ?? '');
        }
        return $setari;
    }

    /**
     * Subiectul, corpul și blocurile unui șablon de email de comandă (OrderMailer::templateDefinitions).
     *
     * @return array<string, string>
     */
    private static function setariSablonEmail(PDO $db, string $tip): array
    {
        $meta = $tip !== '' ? (\App\Support\OrderMailer::templateDefinitions()[$tip] ?? null) : null;
        if (!is_array($meta)) {
            return [];
        }
        return self::setari($db, [(string) ($meta['subject_key'] ?? ''), (string) ($meta['body_key'] ?? ''),
            'email_template_' . $tip . '_blocks_json']);
    }

    /** @param list<string> $neacoperite */
    private static function refuza(?PDO $db, string $tipar, string $cale, array $neacoperite): void
    {
        $mesaj = self::mesaj($neacoperite);
        if ($db instanceof PDO) {
            AdminActivityLog::log($db, self::JURNAL, [
                'adresa' => $cale,
                'cod' => CodActiv::descrie($neacoperite[0]),
                'parti_noi' => count($neacoperite),
            ]);
        }
        if (self::vreaJson()) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $mesaj, 'error' => $mesaj], JSON_UNESCAPED_UNICODE);
            return;
        }
        Flash::set('error', $mesaj);
        header('Location: ' . self::intoarcere());
    }

    /** Cererile făcute de scripturile adminului (fetch) primesc JSON, nu o redirecționare. */
    private static function vreaJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $tip = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        return (string) ($_POST['ajax'] ?? '') === '1'
            || str_contains($accept, 'application/json')
            || str_contains($tip, 'json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /** Înapoi la pagina formularului (Referer de pe același site, în admin), altfel prima pagină a rolului. */
    private static function intoarcere(): string
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $parti = $referer !== '' ? parse_url($referer) : false;
        if (is_array($parti)) {
            $gazda = strtolower((string) ($parti['host'] ?? ''));
            $gazdaCerere = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
            $caleReferer = (string) ($parti['path'] ?? '');
            if ($gazda !== '' && $gazda === $gazdaCerere && str_starts_with($caleReferer, '/admin')
                && preg_match('/[\x00-\x1f\x7f\\\\]/', $caleReferer . ($parti['query'] ?? '')) !== 1) {
                return $caleReferer . (isset($parti['query']) && $parti['query'] !== '' ? '?' . $parti['query'] : '');
            }
        }
        return Auth::defaultPathForRole();
    }

    private static function db(): ?PDO
    {
        $config = require __DIR__ . '/../../config/app.php';
        return Database::connection((array) ($config['db'] ?? []));
    }
}
