<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\AdminCsrf;
use App\Support\Auth;
use App\Support\Campanii;
use App\Support\Flash;
use App\Support\ResponseCache;
use App\Support\View;
use PDO;
use Throwable;

/**
 * Pluginul `campanii` (docs/PLUGINURI.md, „Campanii pe site”; App\Support\Campanii): pagina de deschidere a campaniei
 * (`GET /campanie/{slug}`, cu butonul „← Înapoi în site-ul principal”) și Marketing → Campanii pe site în admin (lista,
 * editorul cu previzualizarea bannerului, ștergerea; doar administratorul general).
 */
final class CampaniiController
{
    use Comun;

    /**
     * Pagina campaniei active: pagina aleasă din Conținut → Pagini, în aspectul site-ului, cu bara „Înapoi” deasupra
     * antetului. Fără indexare și fără cache. În afara ferestrei: 404, afară de administratorul logat, care o vede ca
     * previzualizare (cu o notă), ca s-o verifice înainte de pornire.
     *
     * @param array<string, string> $params
     */
    public function pagina(array $params): void
    {
        $db = $this->db();
        $slug = trim((string) ($params['slug'] ?? ''));
        $activa = Campanii::activa($db);
        $campanie = $activa !== null && (string) $activa['slug'] === $slug ? $activa : null;
        $previzualizare = false;
        if ($campanie === null && $db instanceof PDO && Auth::check()) {
            $campanie = Campanii::dupaSlug($db, $slug);
            $previzualizare = $campanie !== null;
        }
        $pagina = $campanie !== null ? Campanii::pagina($db, $campanie) : null;
        if ($campanie === null || $pagina === null) {
            (new SiteController())->paginaNegasita();
            return;
        }
        if (!$previzualizare) {
            Campanii::marcheazaVazuta((int) $campanie['id']);
        }
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, follow');
        $inapoi = Campanii::inapoiSigur(is_string($_GET['inapoi'] ?? null) ? (string) $_GET['inapoi'] : '/');
        Campanii::randeazaPagina($inapoi, (string) ($campanie['text_inapoi'] ?? ''), $previzualizare);
        View::render('site/custom-page', [
            'title' => (string) ($pagina['title'] ?? $campanie['nume']),
            'page' => $pagina,
            'mannequinSectionHtml' => '',
            'shopCatalogHtml' => '',
            'blogPostsHtml' => '',
            'cartFormHtml' => '',
            'checkoutFormHtml' => '',
            'accountSectionHtml' => '',
            'productReviewFormHtml' => '',
            'gdprAgreementsFormHtml' => '',
            'checkoutSuccessOrderInfoHtml' => '',
            'seo' => ['robots' => 'noindex, follow', 'fara_canonical' => true],
        ]);
    }

    // ── Admin ───────────────────────────────────────────────────────────────────

    public function lista(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $disponibil = $db instanceof PDO && Campanii::disponibil($db);
        View::render('admin/campanii', [
            'title' => 'Campanii pe site',
            'disponibil' => $disponibil,
            'campanii' => $disponibil ? Campanii::lista($db) : [],
        ], 'admin/layout');
    }

    public function noua(): void
    {
        if (!$this->guard()) {
            return;
        }
        $this->editor(Campanii::noua(), [], []);
    }

    /** @param array<string, string> $params */
    public function editeaza(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        $campanie = $db instanceof PDO && $id > 0 ? Campanii::citeste($db, $id) : null;
        if ($campanie === null) {
            Flash::set('error', 'Campania nu există.');
            header('Location: /admin/campanii');
            return;
        }
        $this->editor($campanie, Campanii::idProduse($db, $id), []);
    }

    public function salveaza(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $inapoi = $id > 0 ? '/admin/campanii/' . $id : '/admin/campanii/noua';
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: ' . $inapoi);
            return;
        }
        if (!$db instanceof PDO || !Campanii::disponibil($db)) {
            Flash::set('error', 'Tabelele campaniilor lipsesc: rulează migrările (scripts/migreaza.php --aplica).');
            header('Location: /admin/campanii');
            return;
        }
        if ($id > 0 && Campanii::citeste($db, $id) === null) {
            Flash::set('error', 'Campania nu există.');
            header('Location: /admin/campanii');
            return;
        }
        [$campanie, $erori] = Campanii::dinFormular($db, $_POST, $id);
        if ($erori !== []) {
            // Formularul rămâne completat, cu erorile deasupra (nimic salvat).
            http_response_code(422);
            $this->editor($campanie, (array) $campanie['produse'], $erori);
            return;
        }
        try {
            $id = Campanii::salveaza($db, $campanie);
        } catch (Throwable) {
            Flash::set('error', 'Campania nu a putut fi salvată.');
            header('Location: ' . $inapoi);
            return;
        }
        AdminActivityLog::log($db, 'campanie_salvata', ['id' => $id, 'pornita' => (int) $campanie['pornita'],
            'produse' => count((array) $campanie['produse'])]);
        // Bannerul și insignele sunt în paginile din cache: se refac.
        ResponseCache::purgePageCache();
        Flash::set('success', 'Campania a fost salvată.');
        header('Location: /admin/campanii/' . $id);
    }

    /** @param array<string, string> $params */
    public function sterge(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Sesiunea formularului a expirat. Reîncarcă pagina și încearcă din nou.');
        } elseif ($db instanceof PDO && Campanii::sterge($db, $id)) {
            AdminActivityLog::log($db, 'campanie_stearsa', ['id' => $id]);
            ResponseCache::purgePageCache();
            Flash::set('success', 'Campania a fost ștearsă.');
        } else {
            Flash::set('error', 'Campania nu a putut fi ștearsă.');
        }
        header('Location: /admin/campanii');
    }

    /**
     * @param array<string, mixed> $campanie
     * @param list<int> $produseAlese
     * @param list<string> $erori
     */
    private function editor(array $campanie, array $produseAlese, array $erori): void
    {
        $db = $this->db();
        $pagini = [];
        $produse = [];
        if ($db instanceof PDO) {
            try {
                $pagini = $db->query('SELECT id, title, slug, is_published FROM pages WHERE deleted_at IS NULL ORDER BY title, id')
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                $pagini = [];
            }
            if (\App\Support\Plugins::vizibil('catalog')) {
                try {
                    $produse = $db->query('SELECT id, name, sku, is_active FROM products WHERE deleted_at IS NULL ORDER BY name, id')
                        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                    $produse = [];
                }
            }
        }
        View::render('admin/campanie-editor', [
            'title' => (int) ($campanie['id'] ?? 0) > 0 ? 'Campanie: ' . (string) $campanie['nume'] : 'Campanie nouă',
            'campanie' => $campanie,
            'produseAlese' => array_map('intval', $produseAlese),
            'erori' => $erori,
            'pagini' => $pagini,
            'produse' => $produse,
            'disponibil' => $db instanceof PDO && Campanii::disponibil($db),
        ], 'admin/layout');
    }
}
