<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\AdminCsrf;
use App\Support\Automatizari\Catalog;
use App\Support\Automatizari\Declansatoare;
use App\Support\Automatizari\Flux;
use App\Support\Automatizari\Sabloane;
use App\Support\Automatizari\Trimitere;
use App\Support\Flash;
use App\Support\NewsletterEtichete;
use App\Support\NewsletterService;
use App\Support\OrderMailer;
use App\Support\Plugins;
use App\Support\Settings;
use App\Support\View;
use PDO;
use Throwable;

/**
 * Pluginul `automatizari` (docs/PLUGINURI.md, „Automatizări newsletter”): Marketing → Automatizări newsletter în admin (lista,
 * șabloanele, editorul vizual al fluxului, pornirea și oprirea, copia, ștergerea, emailul de test al unui pas) și adresele
 * mereu deschise ale emailurilor trimise (pixelul de deschidere și linkurile de clic, semnate).
 *
 * Toate acțiunile de scriere sunt POST cu jetonul formularului (AdminCsrf: câmpul `_csrf` sau antetul `X-CSRF-Token`), după
 * garda adminului (rolurile din Auth::canAccessPath: administratorul general și cel de emailuri) și garda pluginului.
 */
final class AutomatizariController
{
    use Comun;

    // ── Lista ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function lista(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $disponibil = $db instanceof PDO && Flux::disponibil($db) && NewsletterEtichete::disponibil($db);
        $setari = $db instanceof PDO ? Settings::all($db) : [];
        View::render('admin/automatizari/lista', [
            'title' => 'Automatizări newsletter',
            'disponibil' => $disponibil,
            'automatizari' => $disponibil ? Flux::lista($db) : [],
            'sabloane' => Sabloane::lista(),
            'preluareCos' => $disponibil && Declansatoare::preiaCosurileAbonatilor($db),
            'emailClasicCos' => Plugins::vizibil('magazin') && OrderMailer::isTemplateActive('abandoned_cart', $setari),
            'cronUltima' => (string) ($setari['newsletter_cron_ultima_rulare'] ?? ''),
            'csrf' => AdminCsrf::token(),
        ], 'admin/layout');
    }

    public function noua(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/automatizari');
            return;
        }
        if (!$db instanceof PDO || !Flux::disponibil($db)) {
            Flash::set('error', 'Tabelele automatizărilor lipsesc: rulează migrările (scripts/migreaza.php --aplica).');
            header('Location: /admin/automatizari');
            return;
        }
        $cheie = (string) ($_POST['sablon'] ?? 'gol');
        $sablon = Sabloane::sablon($cheie, $db, Settings::all($db));
        if ($sablon === null || !array_key_exists($sablon['declansator'], Catalog::declansatoareDisponibile())) {
            Flash::set('error', 'Șablonul nu se poate folosi pe acest site.');
            header('Location: /admin/automatizari');
            return;
        }
        try {
            $id = Flux::creeaza($db, $sablon['nume'], $sablon['declansator'], $sablon['setari'], $sablon['pasi'], $cheie, $sablon['emailuri']);
        } catch (Throwable) {
            Flash::set('error', 'Automatizarea nu a putut fi creată.');
            header('Location: /admin/automatizari');
            return;
        }
        AdminActivityLog::log($db, 'automatizare_creata', ['id' => $id, 'sablon' => $cheie]);
        header('Location: /admin/automatizari/' . $id);
    }

    // ── Editorul ───────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $params */
    public function editor(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $automatizare = $db instanceof PDO && $id > 0 && Flux::disponibil($db) ? Flux::citeste($db, $id) : null;
        if ($automatizare === null) {
            Flash::set('error', 'Automatizarea nu există.');
            header('Location: /admin/automatizari');
            return;
        }
        $pasi = Flux::pasi($db, $id);
        $setari = Settings::all($db);
        View::render('admin/automatizari/editor', [
            'title' => 'Automatizare — ' . (string) $automatizare['nume'],
            'date' => $this->dateEditor($db, $automatizare, $pasi, $setari),
        ], 'admin/layout');
    }

    /** Tot ce are nevoie editorul (JSON în pagină). */
    private function dateEditor(PDO $db, array $automatizare, array $pasi, array $setari): array
    {
        $id = (int) $automatizare['id'];
        $liste = [];
        foreach ($db->query('SELECT id, name, is_default FROM newsletter_lists ORDER BY is_default DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
            $liste[] = ['id' => (int) $l['id'], 'nume' => (string) $l['name'], 'implicita' => (int) $l['is_default'] === 1];
        }
        $categorii = [];
        if (Plugins::vizibil('catalog')) {
            try {
                foreach ($db->query('SELECT id, name FROM product_categories ORDER BY name ASC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                    $categorii[] = ['id' => (int) $c['id'], 'nume' => (string) $c['name']];
                }
            } catch (Throwable) {
                $categorii = [];
            }
        }
        // Numele produselor deja alese (declanșatorul și condițiile „A cumpărat”), ca să apară în editor fără o căutare.
        $produseAlese = array_merge((array) ($automatizare['setari']['produse'] ?? []));
        foreach ($pasi as $p) {
            $produseAlese = array_merge($produseAlese, (array) ($p['setari']['produse'] ?? []));
        }
        $produse = [];
        $produseAlese = array_values(array_unique(array_map('intval', $produseAlese)));
        if ($produseAlese !== [] && Plugins::vizibil('catalog')) {
            $semne = implode(',', array_fill(0, count($produseAlese), '?'));
            $stmt = $db->prepare('SELECT id, name FROM products WHERE id IN (' . $semne . ')');
            $stmt->execute($produseAlese);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
                $produse[] = ['id' => (int) $p['id'], 'nume' => (string) $p['name']];
            }
        }
        $emailuri = Flux::emailuri($db, $id);
        $previzualizari = [];
        foreach ($emailuri as $emailId => $e) {
            $previzualizari[$emailId] = '/admin/automatizari/' . $id . '/previzualizare?email=' . $emailId;
        }
        return [
            'id' => $id,
            'nume' => (string) $automatizare['nume'],
            'stare' => (string) $automatizare['stare'],
            'declansator' => (string) $automatizare['declansator'],
            'setari' => $automatizare['setari'],
            'permite_reintrarea' => (bool) $automatizare['permite_reintrarea'],
            'pasi' => Flux::arbore($pasi),
            'numere' => Flux::numere($db, $id),
            'numere_pasi' => Flux::numerePasi($db, $id),
            'emailuri' => $emailuri,
            'previzualizari' => $previzualizari,
            'declansatoare' => array_map(static fn (array $d): array => ['eticheta' => $d['eticheta'], 'lung' => $d['lung']],
                Catalog::declansatoareDisponibile()),
            'pasi_tipuri' => array_map(static fn (array $p): array => ['eticheta' => $p['eticheta'], 'grup' => $p['grup']], Catalog::pasiDisponibili()),
            'grupuri' => Catalog::GRUPURI,
            'conditii' => Catalog::CONDITII,
            'coduri' => Catalog::CODURI_EMAIL,
            'liste' => $liste,
            'etichete' => NewsletterEtichete::lista($db),
            'categorii' => $categorii,
            'produse' => $produse,
            'cu_catalog' => Plugins::vizibil('catalog'),
            'cu_cupoane' => Plugins::vizibil('cupoane'),
            // Codurile de reducere sunt cupoane: fără acces la Cupoane („Administrator email-uri”) pasul se vede, dar nu se schimbă.
            'poate_cupoane' => \App\Support\Auth::canAccessPath('/admin/coupons'),
            'preluare_cos' => Declansatoare::preiaCosurileAbonatilor($db),
            'email_clasic_cos' => Plugins::vizibil('magazin') && OrderMailer::isTemplateActive('abandoned_cart', $setari),
            'email_test' => $this->emailAdmin($db),
            'email_contact' => \App\Support\Brand::emailContact($db, $setari),
            'csrf' => AdminCsrf::token(),
            'pas_ales' => preg_match('/^[a-z0-9_]{1,20}$/', (string) ($_GET['pas'] ?? '')) === 1 ? (string) $_GET['pas'] : '',
        ];
    }

    private function emailAdmin(PDO $db): string
    {
        $id = \App\Support\Auth::id();
        if ($id === null) {
            return '';
        }
        $stmt = $db->prepare('SELECT email FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** Răspunsul JSON al unei acțiuni din editor; refuză fără jeton sau fără tabele. */
    private function pregatesteJson(): ?PDO
    {
        if (!\App\Support\Auth::check()) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Sesiunea a expirat. Intră din nou în admin.'], 401);
            return null;
        }
        $cale = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH) ?: '/admin';
        if (!\App\Support\Auth::canAccessPath($cale)) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Nu ai permisiunea necesară pentru această secțiune.'], 403);
            return null;
        }
        if (!AdminCsrf::valid()) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Sesiunea formularului a expirat. Reîncarcă pagina.'], 419);
            return null;
        }
        $db = $this->db();
        if (!$db instanceof PDO || !Flux::disponibil($db)) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Tabelele automatizărilor lipsesc: rulează migrările.'], 503);
            return null;
        }
        return $db;
    }

    private function idDin(array $params): int
    {
        return ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    }

    /** POST /admin/automatizari/{id}/salveaza (JSON: nume, declansator, setari, permite_reintrarea, pasi). */
    public function salveaza(array $params): void
    {
        $db = $this->pregatesteJson();
        if ($db === null) {
            return;
        }
        $id = $this->idDin($params);
        $automatizare = Flux::citeste($db, $id);
        if ($automatizare === null) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Automatizarea nu există.'], 404);
            return;
        }
        $date = json_decode((string) ($_POST['flux'] ?? ''), true);
        if (!is_array($date)) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Datele fluxului lipsesc.'], 422);
            return;
        }
        $declansator = (string) ($date['declansator'] ?? '');
        if (!array_key_exists($declansator, Catalog::declansatoareDisponibile())) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Declanșatorul nu se poate folosi pe acest site.'], 422);
            return;
        }
        $arbore = is_array($date['pasi'] ?? null) ? $date['pasi'] : [];
        // Pasul „Cod de reducere” face cupoane adevărate ale magazinului: doar cine are acces la Cupoane îl poate adăuga sau
        // schimba („Administrator email-uri” nu are; poate doar păstra, muta sau șterge un pas pus de altcineva).
        if (!\App\Support\Auth::canAccessPath('/admin/coupons') && self::coduriSchimbate($db, $id, $arbore)) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Codurile de reducere le poate adăuga sau schimba doar cine are acces la Cupoane.'], 403);
            return;
        }
        $erori = Flux::salveaza($db, $id, (string) ($date['nume'] ?? ''), $declansator, is_array($date['setari'] ?? null) ? $date['setari'] : [],
            !empty($date['permite_reintrarea']), $arbore);
        if ($erori !== []) {
            $this->jsonResponse(['ok' => false, 'mesaj' => implode(' ', $erori)], 422);
            return;
        }
        // O automatizare activă rămâne activă doar cât fluxul e complet.
        $probleme = Flux::problemePornire($db, (array) Flux::citeste($db, $id), Flux::pasi($db, $id));
        $oprita = false;
        if ($automatizare['stare'] === 'activa' && $probleme !== []) {
            Flux::schimbaStarea($db, $id, 'oprita');
            $oprita = true;
        }
        AdminActivityLog::log($db, 'automatizare_salvata', ['id' => $id]);
        $this->jsonResponse(['ok' => true, 'probleme' => $probleme, 'oprita' => $oprita,
            'stare' => (string) (Flux::citeste($db, $id)['stare'] ?? 'ciorna')]);
    }

    /** Arborele salvat are un pas „Cod de reducere” nou sau cu alte setări decât cel cu aceeași cheie din bază? */
    private static function coduriSchimbate(PDO $db, int $id, array $arbore): bool
    {
        $vechi = [];
        foreach (Flux::pasi($db, $id) as $pas) {
            if ($pas['tip'] === 'cod_reducere') {
                $vechi[$pas['cheie']] = $pas['setari'];
            }
        }
        foreach (Flux::randuriDinArbore($arbore)[0] as $rand) {
            if ($rand['tip'] === 'cod_reducere' && (!array_key_exists($rand['cheie'], $vechi) || $vechi[$rand['cheie']] !== $rand['setari'])) {
                return true;
            }
        }
        return false;
    }

    /** POST /admin/automatizari/{id}/stare (`stare` = activa | oprita); din listă (formular) sau din editor (JSON). */
    public function stare(array $params): void
    {
        $json = (string) ($_POST['ajax'] ?? '') === '1';
        if (!$json && !$this->guard()) {
            return;
        }
        $db = $json ? $this->pregatesteJson() : $this->db();
        if ($db === null) {
            return;
        }
        if (!$json && (!AdminCsrf::valid() || !$db instanceof PDO || !Flux::disponibil($db))) {
            Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/automatizari');
            return;
        }
        $id = $this->idDin($params);
        $stare = (string) ($_POST['stare'] ?? '');
        $probleme = Flux::schimbaStarea($db, $id, $stare);
        if ($probleme === []) {
            AdminActivityLog::log($db, $stare === 'activa' ? 'automatizare_pornita' : 'automatizare_oprita', ['id' => $id]);
        }
        if ($json) {
            $this->jsonResponse(['ok' => $probleme === [], 'mesaj' => implode(' ', $probleme),
                'stare' => (string) (Flux::citeste($db, $id)['stare'] ?? '')], $probleme === [] ? 200 : 422);
            return;
        }
        Flash::set($probleme === [] ? 'success' : 'error', $probleme === []
            ? ($stare === 'activa' ? 'Automatizarea a pornit.' : 'Automatizarea e oprită. Cine e în flux rămâne unde a ajuns.')
            : 'Automatizarea nu poate porni: ' . implode(' ', $probleme));
        header('Location: /admin/automatizari');
    }

    public function duplica(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!AdminCsrf::valid() || !$db instanceof PDO || !Flux::disponibil($db)) {
            Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/automatizari');
            return;
        }
        try {
            $nou = Flux::duplica($db, $this->idDin($params));
        } catch (Throwable) {
            Flash::set('error', 'Automatizarea nu există.');
            header('Location: /admin/automatizari');
            return;
        }
        AdminActivityLog::log($db, 'automatizare_duplicata', ['id' => $this->idDin($params), 'copie' => $nou]);
        Flash::set('success', 'Am făcut o copie (ciornă).');
        header('Location: /admin/automatizari/' . $nou);
    }

    public function sterge(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!AdminCsrf::valid() || !$db instanceof PDO || !Flux::disponibil($db)) {
            Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/automatizari');
            return;
        }
        $id = $this->idDin($params);
        if (Flux::citeste($db, $id) !== null) {
            Flux::sterge($db, $id);
            AdminActivityLog::log($db, 'automatizare_stearsa', ['id' => $id]);
            Flash::set('success', 'Automatizarea a fost ștearsă.');
        }
        header('Location: /admin/automatizari');
    }

    /** POST /admin/automatizari/{id}/email-nou (JSON): emailul unui pas „Trimite email”, cu un conținut de pornire. */
    public function emailNou(array $params): void
    {
        $db = $this->pregatesteJson();
        if ($db === null) {
            return;
        }
        $id = $this->idDin($params);
        $automatizare = Flux::citeste($db, $id);
        if ($automatizare === null) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Automatizarea nu există.'], 404);
            return;
        }
        $subiect = mb_substr(trim((string) ($_POST['subiect'] ?? '')), 0, 190);
        $emailId = Flux::creeazaEmail($db, $id, $subiect !== '' ? $subiect : 'Email nou', $subiect !== '' ? $subiect : 'Salut, {{prenume}}!',
            Sabloane::blocuriEmail($db, Settings::all($db), 'Salut, {{prenume}}!', 'Scrie aici mesajul emailului.', 'Vezi magazinul', '/'));
        $this->jsonResponse(['ok' => true, 'email' => Flux::emailuri($db, $id)[$emailId] ?? ['id' => $emailId],
            'previzualizare' => '/admin/automatizari/' . $id . '/previzualizare?email=' . $emailId]);
    }

    /** POST /admin/automatizari/{id}/email-test (JSON: email_id, adresa). */
    public function emailTest(array $params): void
    {
        $db = $this->pregatesteJson();
        if ($db === null) {
            return;
        }
        $id = $this->idDin($params);
        $email = Flux::email($db, (int) ($_POST['email_id'] ?? 0));
        if ($email === null || (int) $email['automatizare_id'] !== $id) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Emailul nu există.'], 404);
            return;
        }
        $adresa = trim((string) ($_POST['adresa'] ?? ''));
        try {
            Trimitere::trimiteTest($db, $email, $adresa, Settings::all($db));
        } catch (Throwable $e) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Testul nu a plecat: ' . $e->getMessage()], 422);
            return;
        }
        AdminActivityLog::log($db, 'automatizare_email_test', ['id' => $id, 'email' => (int) $email['id']]);
        $this->jsonResponse(['ok' => true, 'mesaj' => 'Am trimis testul la ' . $adresa . '.']);
    }

    /** POST /admin/automatizari/{id}/eticheta (JSON: nume): o etichetă nouă (sau cea existentă cu același nume). */
    public function eticheta(array $params): void
    {
        $db = $this->pregatesteJson();
        if ($db === null) {
            return;
        }
        $nume = NewsletterEtichete::curataNume((string) ($_POST['nume'] ?? ''));
        if ($nume === '' || !NewsletterEtichete::disponibil($db)) {
            $this->jsonResponse(['ok' => false, 'mesaj' => 'Scrie numele etichetei.'], 422);
            return;
        }
        $idEticheta = NewsletterEtichete::idDupaNume($db, $nume);
        $this->jsonResponse(['ok' => true, 'eticheta' => ['id' => $idEticheta, 'nume' => $nume], 'etichete' => NewsletterEtichete::lista($db)]);
    }

    /**
     * GET /admin/automatizari/{id}/previzualizare?email=N: emailul randat (ca la un abonat de test), izolat ca „Vezi” din
     * Emailuri trimise (CSP sandbox, fără scripturi), pentru miniatura din panoul pasului.
     */
    public function previzualizare(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $id = $this->idDin($params);
        $email = $db instanceof PDO && Flux::disponibil($db) ? Flux::email($db, (int) ($_GET['email'] ?? 0)) : null;
        if ($email === null || (int) $email['automatizare_id'] !== $id) {
            http_response_code(404);
            echo 'Emailul nu există.';
            return;
        }
        $context = ['prenume' => 'Ana', 'numar_comanda' => '100001', 'total_comanda' => 149.9,
            'cod_reducere' => ['cod' => 'EXEMPLU10', 'valoare_text' => '10%', 'expira_text' => date('d.m.Y', time() + 14 * 86400)]];
        $compus = Trimitere::compune($email, ['id' => 0, 'email' => 'abonat@exemplu.ro'], $context, Settings::all($db), true);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline' *; font-src * data:; frame-ancestors 'self'");
        echo $compus['html'];
    }

    // ── Builderul (type=automatizare): emailul unui pas, cu același builder ca newsletterul ───────────────────────────

    /**
     * Datele builderului pentru `GET /admin/emails/builder?type=automatizare&id=<email>` (AdminController::emailsBuilder),
     * sau null dacă emailul nu există.
     *
     * @return array<string, mixed>|null
     */
    public static function datePentruBuilder(PDO $db, int $emailId): ?array
    {
        if (!Flux::disponibil($db)) {
            return null;
        }
        $email = Flux::email($db, $emailId);
        if ($email === null) {
            return null;
        }
        $automatizare = Flux::citeste($db, (int) $email['automatizare_id']);
        $blocuri = json_decode((string) ($email['blocks_json'] ?? '[]'), true);
        $pas = '';
        foreach (Flux::pasi($db, (int) $email['automatizare_id']) as $p) {
            if ($p['tip'] === 'email' && (int) ($p['setari']['email_id'] ?? 0) === $emailId) {
                $pas = $p['cheie'];
            }
        }
        return [
            'type' => 'automatizare',
            'id' => $emailId,
            'ref' => '',
            'name' => (string) $email['nume'],
            'subject' => (string) $email['subiect'],
            'blocks' => is_array($blocuri) ? $blocuri : [],
            'is_active' => true,
            'back_url' => '/admin/automatizari/' . (int) $email['automatizare_id'] . ($pas !== '' ? '?pas=' . rawurlencode($pas) : ''),
            'available_tokens' => array_keys(Catalog::CODURI_EMAIL),
            'automatizare_nume' => (string) ($automatizare['nume'] ?? ''),
        ];
    }

    /** Salvarea din builder (`POST /admin/emails/builder`, builder_type=automatizare). Întoarce id-ul automatizării sau 0. */
    public static function salveazaDinBuilder(PDO $db, int $emailId, string $nume, string $subiect, array $blocuri): int
    {
        if (!Flux::disponibil($db)) {
            return 0;
        }
        $email = Flux::email($db, $emailId);
        if ($email === null) {
            return 0;
        }
        Flux::salveazaEmail($db, $emailId, $nume, $subiect, $blocuri);
        AdminActivityLog::log($db, 'automatizare_email_salvat', ['id' => (int) $email['automatizare_id'], 'email' => $emailId]);
        return (int) $email['automatizare_id'];
    }

    // ── Adresele mereu deschise: pixelul și clicurile ──────────────────────────────────────────────────────────────────

    /** GET /automatizari/d/{trimitere}/{token}: pixelul de deschidere (mereu un GIF; numără doar cu pluginul pe site). */
    public function deschidere(array $params): void
    {
        $db = $this->db();
        if ($db instanceof PDO && Flux::disponibil($db)) {
            try {
                Trimitere::laDeschidere($db, (int) ($params['trimitere'] ?? 0), (string) ($params['token'] ?? ''), Plugins::vizibil('automatizari'));
            } catch (Throwable) {
            }
        }
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    /**
     * GET /automatizari/c/{trimitere}/{token}?u=<adresa>: un link dintr-un email trimis. Doar o adresă semnată de noi pentru
     * trimiterea asta duce mai departe; altfel pagina 404 a site-ului (nicio trambulină spre alt site). Nu pune niciun cookie.
     */
    public function clic(array $params): void
    {
        $db = $this->db();
        $url = (string) ($_GET['u'] ?? '');
        $destinatie = null;
        if ($db instanceof PDO && Flux::disponibil($db)) {
            try {
                $destinatie = Trimitere::laClic($db, (int) ($params['trimitere'] ?? 0), (string) ($params['token'] ?? ''), $url,
                    Plugins::vizibil('automatizari'));
            } catch (Throwable) {
                $destinatie = null;
            }
        }
        if ($destinatie === null) {
            (new SiteController())->paginaNegasita();
            return;
        }
        $destinatie = trim($destinatie);
        if (preg_match('#^/(?![/\\\\])#', $destinatie) === 1) {
            $destinatie = \App\Support\AppUrl::absolut($destinatie);
        } elseif (!in_array(parse_url($destinatie, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $destinatie = \App\Support\AppUrl::absolut('/') ?: '/';
        }
        header('Cache-Control: no-store');
        header('Location: ' . $destinatie);
    }
}
