<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\AdminCsrf;
use App\Support\Brand;
use App\Support\CereriOferta;
use App\Support\Flash;
use App\Support\IpClient;
use App\Support\OrderMailer;
use App\Support\Plugins;
use App\Support\Settings;
use App\Support\View;
use PDO;
use Throwable;

/**
 * Pluginul `cereri_oferta` (WP-26: GR-15, din grafoanaytis `Site::quoteRequestSubmit`, `trimiteEmailCerereOferta` și
 * `Admin::cereriOferta`, `cerereOfertaUpdate`, `cerereOfertaDelete`): cererea trimisă din sertarul de pe produs
 * (`POST /api/cerere-oferta`, JSON) și lista cererilor în admin (`/admin/cereri-oferta`, cu starea și ștergerea).
 *
 * Cererea se salvează întâi și se trimite pe email după: dacă emailul nu pleacă, clientul potențial rămâne în listă.
 * Față de grafoanaytis: limita de 5 cereri la 10 minute de la același vizitator, refuzul cererilor noi cât pluginul se
 * oprește, textul de mulțumire din setări, jetonul anti-CSRF și jurnalul adminilor pe acțiunile din admin.
 */
final class CereriOfertaController
{
    use Comun;

    public function trimite(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            $this->jsonResponse(['ok' => false, 'error' => 'Conexiunea la baza de date nu este disponibilă.'], 500);
            return;
        }
        // Cât pluginul se oprește: lista din admin rămâne, cereri noi nu se mai primesc.
        if (!Plugins::permiteInceput('cereri_oferta')) {
            $this->jsonResponse(['ok' => false, 'error' => 'Cererile de ofertă nu mai sunt primite.'], 403);
            return;
        }

        $payload = json_decode(trim((string) file_get_contents('php://input')), true);
        if (!is_array($payload)) {
            $this->jsonResponse(['ok' => false, 'error' => 'Date invalide.'], 400);
            return;
        }

        $ip = IpClient::adresa();
        if (CereriOferta::limitat($db, $ip)) {
            $this->jsonResponse(['ok' => false, 'error' => 'Prea multe cereri într-un timp scurt. Încercați din nou peste câteva minute.'], 429);
            return;
        }
        CereriOferta::noteazaTrimiterea($db, $ip);

        // Capcana pentru roboți: un câmp ascuns pe care un om nu-l vede. Răspundem „ok”, ca robotul să nu afle că a fost prins.
        if (trim((string) ($payload['website'] ?? '')) !== '') {
            $this->jsonResponse(['ok' => true, 'message' => 'Cererea a fost trimisă.']);
            return;
        }

        $nume = trim((string) ($payload['name'] ?? ''));
        $firma = trim((string) ($payload['company'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $telefon = trim((string) ($payload['phone'] ?? ''));
        $mesaj = trim((string) ($payload['message'] ?? ''));
        $acord = (bool) ($payload['consent'] ?? false);

        if ($nume === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $this->jsonResponse(['ok' => false, 'error' => 'Completați numele și o adresă de e-mail validă.'], 422);
            return;
        }
        if (!$acord) {
            $this->jsonResponse(['ok' => false, 'error' => 'Este nevoie de acordul pentru prelucrarea datelor.'], 422);
            return;
        }

        // Limitele de lungime: coloanele sunt mărginite, iar un mesaj de zece mii de caractere nu e o cerere de ofertă.
        $nume = mb_substr($nume, 0, 160);
        $firma = mb_substr($firma, 0, 160);
        $telefon = mb_substr($telefon, 0, 60);
        $mesaj = mb_substr($mesaj, 0, 4000);

        // Produsul se caută după slug, nu se ia din formular: un id sau un nume trimis din browser ar putea spune orice.
        // Un slug necunoscut nu strică cererea (poate veni de pe o pagină de serviciu).
        $produs = null;
        $slugProdus = mb_substr(trim((string) ($payload['product_slug'] ?? '')), 0, 255);
        if ($slugProdus !== '') {
            try {
                $cauta = $db->prepare('SELECT id, slug, name FROM products WHERE slug = :slug AND deleted_at IS NULL LIMIT 1');
                $cauta->execute(['slug' => $slugProdus]);
                $produs = $cauta->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable) {
                $produs = null;
            }
        }

        $id = CereriOferta::adauga($db, [
            'product_id' => is_array($produs) ? (int) ($produs['id'] ?? 0) : null,
            'product_name' => is_array($produs) ? (string) ($produs['name'] ?? '') : null,
            'product_slug' => $slugProdus !== '' ? $slugProdus : null,
            'name' => $nume,
            'company' => $firma !== '' ? $firma : null,
            'email' => $email,
            'phone' => $telefon !== '' ? $telefon : null,
            'message' => $mesaj !== '' ? $mesaj : null,
            'consent_at' => date('Y-m-d H:i:s'),
            'source_url' => mb_substr(trim((string) ($_SERVER['HTTP_REFERER'] ?? '')), 0, 500) ?: null,
        ]);
        if ($id === null) {
            $this->jsonResponse(['ok' => false, 'error' => 'Cererea nu a putut fi salvată.'], 500);
            return;
        }

        $setari = Settings::all($db);
        $this->trimiteEmail($db, $setari, [
            'nume' => $nume,
            'firma' => $firma,
            'email' => $email,
            'telefon' => $telefon,
            'mesaj' => $mesaj,
            'produs' => is_array($produs) ? (string) ($produs['name'] ?? '') : '',
        ]);

        $this->jsonResponse(['ok' => true, 'message' => CereriOferta::texte($setari)['multumire']]);
    }

    /**
     * Înștiințarea către site (destinatarii formularului de contact), cu Reply-To = vizitatorul (GR-01). Un email care nu
     * pleacă nu întoarce eroare vizitatorului: cererea e deja salvată.
     *
     * @param array<string, mixed> $setari
     * @param array<string, string> $date
     */
    private function trimiteEmail(PDO $db, array $setari, array $date): void
    {
        $sigur = static fn (string $v): string => htmlspecialchars($v !== '' ? $v : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subiect = $date['produs'] !== '' ? 'Cerere de ofertă: ' . $date['produs'] : 'Cerere de ofertă de pe site';
        $html = '<h2>' . $sigur($subiect) . '</h2>'
            . '<p><strong>Nume:</strong> ' . $sigur($date['nume']) . '<br>'
            . '<strong>Firmă:</strong> ' . $sigur($date['firma']) . '<br>'
            . '<strong>E-mail:</strong> ' . $sigur($date['email']) . '<br>'
            . '<strong>Telefon:</strong> ' . $sigur($date['telefon']) . '</p>'
            . '<p><strong>Mesaj:</strong><br>' . nl2br($sigur($date['mesaj'])) . '</p>'
            . '<p>Cererea a fost salvată și în admin, la Cereri de ofertă.</p>';
        foreach (Brand::destinatariContact($db, $setari) as $destinatar) {
            try {
                OrderMailer::sendCustom($destinatar, $subiect, $html, $setari, $db, [
                    'email_type' => 'quote_request_notification',
                    'source' => 'quote_request',
                    'trigger' => 'quote_request_submit',
                    'from_email' => $date['email'],
                    'from_name' => $date['nume'],
                ]);
            } catch (Throwable) {
                // Cererea e salvată; un email ratat nu o pierde.
            }
        }
    }

    // ── Admin ───────────────────────────────────────────────────────────────────

    /**
     * Lista cererilor, cu filtrul pe stare. Pe un site care nu vinde, aceasta e căsuța de intrare: fiecare rând e un client
     * potențial. Rămâne și cât pluginul se oprește.
     */
    public function lista(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $stare = trim((string) ($_GET['stare'] ?? ''));
        $cereri = [];
        $numarPeStari = array_fill_keys(CereriOferta::STARI, 0);
        $textButon = CereriOferta::TEXTE_IMPLICITE['cereri_oferta_buton'];
        if ($db instanceof PDO) {
            $cereri = CereriOferta::lista($db, $stare);
            $numarPeStari = CereriOferta::numarPeStari($db);
            $textButon = CereriOferta::texte(Settings::all($db))['buton'];
        }
        View::render('admin/cereri-oferta', [
            'title' => 'Cereri de ofertă',
            'cereri' => $cereri,
            'stareCurenta' => in_array($stare, CereriOferta::STARI, true) ? $stare : '',
            'numarPeStari' => $numarPeStari,
            'textButon' => $textButon,
        ], 'admin/layout');
    }

    /** @param array<string, string> $params */
    public function stare(array $params): void
    {
        if (!$this->guard() || !$this->csrfValid()) {
            return;
        }
        $db = $this->db();
        $id = (int) ($params['id'] ?? 0);
        $stare = trim((string) ($_POST['stare'] ?? ''));
        if ($db instanceof PDO && CereriOferta::schimbaStarea($db, $id, $stare, trim((string) ($_POST['nota'] ?? '')))) {
            AdminActivityLog::log($db, 'cerere_oferta_stare', ['id' => $id, 'stare' => $stare]);
            Flash::set('success', 'Cererea a fost salvată.');
        } else {
            Flash::set('error', 'Cererea nu a putut fi salvată.');
        }
        header('Location: /admin/cereri-oferta' . $this->intoarcereLaFiltru());
    }

    /** @param array<string, string> $params */
    public function sterge(array $params): void
    {
        if (!$this->guard() || !$this->csrfValid()) {
            return;
        }
        $db = $this->db();
        $id = (int) ($params['id'] ?? 0);
        if ($db instanceof PDO && CereriOferta::sterge($db, $id)) {
            AdminActivityLog::log($db, 'cerere_oferta_stearsa', ['id' => $id]);
            Flash::set('success', 'Cererea a fost ștearsă.');
        } else {
            Flash::set('error', 'Cererea nu a putut fi ștearsă.');
        }
        header('Location: /admin/cereri-oferta' . $this->intoarcereLaFiltru());
    }

    private function csrfValid(): bool
    {
        if (AdminCsrf::valid()) {
            return true;
        }
        Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
        header('Location: /admin/cereri-oferta' . $this->intoarcereLaFiltru());
        return false;
    }

    /** Păstrează filtrul deschis după o acțiune, ca lista să nu sară la început. */
    private function intoarcereLaFiltru(): string
    {
        $stare = trim((string) ($_POST['stare_filtru'] ?? ''));
        return in_array($stare, CereriOferta::STARI, true) ? '?stare=' . rawurlencode($stare) : '';
    }
}
