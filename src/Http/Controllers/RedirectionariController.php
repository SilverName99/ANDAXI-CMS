<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\AdminCsrf;
use App\Support\Auth;
use App\Support\Flash;
use App\Support\Jurnal404;
use App\Support\Redirectionari;
use App\Support\ResponseCache;
use App\Support\View;
use PDO;
use Throwable;

/**
 * Setări → Redirecționări și 404 (WP-38, SEO partea B, B2; nucleu, doar administratorul general): lista regulilor (cu
 * sursa, accesările, starea), adăugarea, oprirea și ștergerea, importul și exportul CSV, reluarea regulilor din pachet, și
 * jurnalul paginilor negăsite cu „Redirecționează” / „410” / „Ignoră”. Toată logica regulilor (verificările: fără
 * redirecționare deschisă, fără buclă, fără lanț, nicio cale exclusă) e în App\Support\Redirectionari; aici doar
 * formularele. Fiecare POST cere jetonul formularului (AdminCsrf) și se notează în jurnalul adminilor.
 */
final class RedirectionariController
{
    use Comun;

    private const PE_PAGINA = 100;
    private const LISTA = '/admin/seo/redirectionari';
    private const JURNAL = '/admin/seo/404';

    public function lista(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $disponibil = Redirectionari::disponibil($db);
        $sursa = is_scalar($_GET['sursa'] ?? null) ? (string) $_GET['sursa'] : '';
        $stare = is_scalar($_GET['stare'] ?? null) ? (string) $_GET['stare'] : '';
        $q = mb_substr(trim(is_scalar($_GET['q'] ?? null) ? (string) $_GET['q'] : ''), 0, 200);
        $pagina = max(1, min(100000, (int) ($_GET['pagina'] ?? 1)));
        $reguli = [];
        $numere = ['toate' => 0, 'pornite' => 0, 'oprite' => 0, 'pachet' => 0, 'slug' => 0, 'adaugate' => 0];
        $total = 0;
        $lanturi = [];
        if ($disponibil && $db instanceof PDO) {
            // Copia hărții se reface doar dacă e veche (pachetul schimbat, o schimbare nescrisă), nu la fiecare deschidere.
            if (!\App\Support\SeoRedirects::copieLaZi()) {
                Redirectionari::reconstruieste($db, false);
            }
            foreach ($db->query("SELECT COUNT(*) toate, SUM(is_active = 1) pornite, SUM(is_active = 0) oprite, SUM(source = 'pachet') pachet,
                SUM(source = 'slug') slug, SUM(source NOT IN ('pachet', 'slug')) adaugate FROM seo_redirects")->fetch(PDO::FETCH_ASSOC) ?: [] as $k => $v) {
                $numere[$k] = (int) $v;
            }
            [$unde, $parametri] = $this->filtre($sursa, $stare, $q);
            $stmt = $db->prepare('SELECT COUNT(*) FROM seo_redirects' . $unde);
            $stmt->execute($parametri);
            $total = (int) $stmt->fetchColumn();
            $pagina = min($pagina, max(1, (int) ceil($total / self::PE_PAGINA)));
            $ordine = match ((string) ($_GET['sort'] ?? '')) {
                'hits' => 'hits', 'ultima' => 'last_hit_at', 'creata' => 'created_at', default => 'from_path',
            };
            $dir = strtolower((string) ($_GET['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
            $stmt = $db->prepare('SELECT * FROM seo_redirects' . $unde . ' ORDER BY ' . $ordine . ' ' . $dir . ', id ASC LIMIT '
                . self::PE_PAGINA . ' OFFSET ' . (($pagina - 1) * self::PE_PAGINA));
            $stmt->execute($parametri);
            $reguli = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $lanturi = Redirectionari::lanturi($db);
        }
        $editeaza = null;
        if ($disponibil && $db instanceof PDO && (int) ($_GET['editeaza'] ?? 0) > 0) {
            $editeaza = Redirectionari::dupaId($db, (int) $_GET['editeaza']);
        }
        View::render('admin/seo-redirectionari', [
            'title' => 'Redirecționări și 404',
            'disponibil' => $disponibil,
            'reguli' => $reguli,
            'numere' => $numere,
            'total' => $total,
            'pagina' => $pagina,
            'peLaPagina' => self::PE_PAGINA,
            'filtru' => ['sursa' => $sursa, 'stare' => $stare, 'q' => $q],
            'lanturi' => $lanturi,
            'editeaza' => $editeaza,
            'precompletat' => [
                'din' => is_scalar($_GET['din'] ?? null) ? mb_substr((string) $_GET['din'], 0, 500) : (string) ($editeaza['from_path'] ?? ''),
                'spre' => is_scalar($_GET['spre'] ?? null) ? mb_substr((string) $_GET['spre'], 0, 500) : (string) ($editeaza['to_url'] ?? ''),
                'cod' => (int) ($_GET['cod'] ?? ($editeaza['code'] ?? 301)) === 410 ? 410 : 301,
                'prefix' => (int) ($editeaza['is_prefix'] ?? 0) === 1,
                'nota' => (string) ($editeaza['note'] ?? ''),
                'sursa' => in_array((string) ($_GET['sursa_noua'] ?? ''), ['404', 'stergere'], true) ? (string) $_GET['sursa_noua'] : 'manual',
            ],
            'nr404' => Jurnal404::disponibil($db) && $db instanceof PDO ? (int) $db->query('SELECT COUNT(*) FROM seo_404_log')->fetchColumn() : 0,
            'stareCopie' => \App\Support\SeoRedirects::stareCopie(),
            'propuneri' => Redirectionari::propuneriInAsteptare(),
            'csrf' => AdminCsrf::token(),
        ], 'admin/layout');
    }

    /** @return array{0: string, 1: list<string>} */
    private function filtre(string $sursa, string $stare, string $q): array
    {
        $conditii = [];
        $parametri = [];
        if ($sursa === 'adaugate') {
            $conditii[] = "source NOT IN ('pachet', 'slug')";
        } elseif (isset(Redirectionari::SURSE[$sursa])) {
            $conditii[] = 'source = ?';
            $parametri[] = $sursa;
        }
        if ($stare === 'pornite' || $stare === 'oprite') {
            $conditii[] = 'is_active = ' . ($stare === 'pornite' ? '1' : '0');
        }
        if ($q !== '') {
            $conditii[] = '(from_path LIKE ? OR to_url LIKE ? OR note LIKE ?)';
            $model = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
            array_push($parametri, $model, $model, $model);
        }
        return [$conditii === [] ? '' : ' WHERE ' . implode(' AND ', $conditii), $parametri];
    }

    /** POST /admin/seo/redirectionari/adauga: o regulă nouă sau schimbată (din formular, din jurnalul 404, după o ștergere). */
    public function adauga(): void
    {
        if (!$this->gardaPost()) {
            return;
        }
        $db = $this->db();
        $din = mb_substr(trim((string) ($_POST['din'] ?? '')), 0, 600);
        $spre = mb_substr(trim((string) ($_POST['spre'] ?? '')), 0, 600);
        $cod = (int) ($_POST['cod'] ?? 301) === 410 ? 410 : ((int) ($_POST['cod'] ?? 301) === 301 ? 301 : 0);
        $sursa = in_array((string) ($_POST['sursa'] ?? ''), ['404', 'stergere'], true) ? (string) $_POST['sursa'] : 'manual';
        $inapoi = $this->inapoi();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea la baza de date nu este disponibilă.');
            header('Location: ' . $inapoi);
            return;
        }
        $rez = Redirectionari::adauga($db, $din, $spre, $cod, $sursa, (string) ($_POST['nota'] ?? ''), $this->autor($db),
            !empty($_POST['prefix']));
        if ($rez['ok']) {
            ResponseCache::purgePageCache();
            AdminActivityLog::log($db, 'Redirecționare salvată', ['din' => $din, 'spre' => $cod === 410 ? '410' : $spre, 'sursa' => $sursa]);
            Flash::set('success', 'Redirecționare salvată: ' . $rez['mesaj']);
            header('Location: ' . $inapoi);
            return;
        }
        Flash::set('error', $rez['mesaj']);
        // Formularul din nou, completat, ca omul să corecteze.
        header('Location: ' . self::LISTA . '?' . http_build_query(['din' => $din, 'spre' => $spre, 'cod' => $cod, 'sursa_noua' => $sursa]) . '#adauga');
    }

    /** POST /admin/seo/redirectionari/{id}/stare: pornește (pornita=1) sau oprește o regulă. */
    public function stare(array $params): void
    {
        if (!$this->gardaPost()) {
            return;
        }
        $db = $this->db();
        $id = (int) ($params['id'] ?? 0);
        $pornita = (string) ($_POST['pornita'] ?? '') === '1';
        if ($db instanceof PDO && Redirectionari::schimbaStarea($db, $id, $pornita)) {
            ResponseCache::purgePageCache();
            AdminActivityLog::log($db, $pornita ? 'Redirecționare pornită' : 'Redirecționare oprită', ['id' => $id]);
            Flash::set('success', $pornita ? 'Regula e pornită.' : 'Regula e oprită: adresa veche răspunde ca fără ea.');
        } else {
            Flash::set('error', $pornita ? 'Regula nu poate fi pornită: ținta ei e la rândul ei redirecționată sau nu mai e validă. Editează ținta.'
                : 'Regula nu a fost găsită.');
        }
        header('Location: ' . $this->inapoi());
    }

    /** POST /admin/seo/redirectionari/{id}/sterge (o regulă din pachet doar se oprește). */
    public function sterge(array $params): void
    {
        if (!$this->gardaPost()) {
            return;
        }
        $db = $this->db();
        $id = (int) ($params['id'] ?? 0);
        $rand = $db instanceof PDO ? Redirectionari::dupaId($db, $id) : null;
        if ($db instanceof PDO && $rand !== null && Redirectionari::sterge($db, $id)) {
            ResponseCache::purgePageCache();
            AdminActivityLog::log($db, 'Redirecționare ștearsă', ['din' => (string) $rand['from_path'], 'spre' => (string) ($rand['to_url'] ?? '410')]);
            Flash::set('success', (string) $rand['source'] === 'pachet'
                ? 'Regula vine din pachetul site-ului: a fost oprită (rămâne oprită și la reluarea pachetului).'
                : 'Regula a fost ștearsă.');
        } else {
            Flash::set('error', 'Regula nu a fost găsită.');
        }
        header('Location: ' . $this->inapoi());
    }

    /** POST /admin/seo/redirectionari/importa: un CSV (fișier sau text lipit). */
    public function importa(): void
    {
        if (!$this->gardaPost()) {
            return;
        }
        $db = $this->db();
        $text = (string) ($_POST['csv'] ?? '');
        if (strlen($text) > Redirectionari::MAX_CSV) {
            Flash::set('error', 'Textul lipit are peste 2 MB: împarte lista în mai multe importuri.');
            header('Location: ' . self::LISTA . '#importa');
            return;
        }
        $fisier = $_FILES['fisier'] ?? null;
        if (is_array($fisier) && (int) ($fisier['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $fisier['tmp_name'])) {
            if ((int) $fisier['size'] > Redirectionari::MAX_CSV) {
                Flash::set('error', 'Fișierul e prea mare (cel mult 2 MB).');
                header('Location: ' . self::LISTA . '#importa');
                return;
            }
            $text = (string) file_get_contents((string) $fisier['tmp_name']);
        }
        if (!$db instanceof PDO || trim($text) === '') {
            Flash::set('error', 'Alege un fișier CSV sau lipește rândurile.');
            header('Location: ' . self::LISTA . '#importa');
            return;
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1250');
        }
        $rez = Redirectionari::importaCsv($db, $text, $this->autor($db));
        ResponseCache::purgePageCache();
        if (isset($rez['eroare'])) {
            Flash::set('error', $rez['eroare']);
            header('Location: ' . self::LISTA . '#importa');
            return;
        }
        AdminActivityLog::log($db, 'Redirecționări importate din CSV', ['adaugate' => $rez['adaugate'], 'refuzate' => count($rez['refuzate'])]);
        $mesaj = $rez['adaugate'] . ' ' . ($rez['adaugate'] === 1 ? 'regulă adăugată' : 'reguli adăugate');
        if ($rez['refuzate'] !== []) {
            $mesaj .= '; ' . count($rez['refuzate']) . ' refuzate: ' . implode(' · ', array_map(
                static fn (array $r): string => 'rândul ' . $r['rand'] . ' (' . $r['motiv'] . ')', array_slice($rez['refuzate'], 0, 10)))
                . (count($rez['refuzate']) > 10 ? ' …' : '');
        }
        Flash::set($rez['refuzate'] === [] ? 'success' : 'error', $mesaj);
        header('Location: ' . self::LISTA);
    }

    /** POST /admin/seo/redirectionari/pachet: reia regulile din `redirecturi.json` al site-ului. */
    public function pachet(): void
    {
        if (!$this->gardaPost()) {
            return;
        }
        $db = $this->db();
        if ($db instanceof PDO && Redirectionari::disponibil($db)) {
            // Apăsat de admin: și când pachetul ar scoate multe reguli (o sincronizare automată s-ar opri).
            $rez = Redirectionari::importaPachet($db, true);
            Redirectionari::reconstruieste($db);
            ResponseCache::purgePageCache();
            AdminActivityLog::log($db, 'Redirecționări reluate din pachet', $rez);
            Flash::set('success', $rez['fisier']
                ? 'Pachetul: ' . $rez['noi'] . ' reguli noi, ' . $rez['schimbate'] . ' schimbate, ' . $rez['scoase'] . ' scoase (nu mai sunt în fișier).'
                : 'Site-ul nu are fișierul redirecturi.json în dosarul lui: nimic de reluat.');
        } else {
            Flash::set('error', 'Tabelul redirecționărilor lipsește: rulează migrările.');
        }
        header('Location: ' . self::LISTA);
    }

    /** GET /admin/seo/redirectionari/export: toate regulile, CSV. */
    public function export(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        if (!Redirectionari::disponibil($db) || !$db instanceof PDO) {
            Flash::set('error', 'Tabelul redirecționărilor lipsește: rulează migrările.');
            header('Location: ' . self::LISTA);
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="redirectionari-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF", Redirectionari::exportCsv($db);
    }

    /** GET /admin/seo/404: jurnalul paginilor negăsite. */
    public function jurnal(): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $disponibil = Jurnal404::disponibil($db);
        $q = mb_substr(trim(is_scalar($_GET['q'] ?? null) ? (string) $_GET['q'] : ''), 0, 200);
        $pagina = max(1, min(100000, (int) ($_GET['pagina'] ?? 1)));
        $randuri = [];
        $total = 0;
        if ($disponibil && $db instanceof PDO) {
            Jurnal404::curata($db);
            $unde = $q !== '' ? ' WHERE path LIKE ?' : '';
            $parametri = $q !== '' ? ['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%'] : [];
            $stmt = $db->prepare('SELECT COUNT(*) FROM seo_404_log' . $unde);
            $stmt->execute($parametri);
            $total = (int) $stmt->fetchColumn();
            $pagina = min($pagina, max(1, (int) ceil($total / self::PE_PAGINA)));
            $ordine = (string) ($_GET['sort'] ?? '') === 'ultima' ? 'last_seen_at' : ((string) ($_GET['sort'] ?? '') === 'cale' ? 'path' : 'hits');
            $dir = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $stmt = $db->prepare('SELECT * FROM seo_404_log' . $unde . ' ORDER BY ' . $ordine . ' ' . $dir . ', id DESC LIMIT ' . self::PE_PAGINA
                . ' OFFSET ' . (($pagina - 1) * self::PE_PAGINA));
            $stmt->execute($parametri);
            $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            // Adresele care au între timp o regulă (pornită): de arătat, nu de redirecționat din nou.
            if (Redirectionari::disponibil($db)) {
                foreach ($randuri as &$r) {
                    $regula = Redirectionari::dupaCheie($db, (string) $r['path'], false);
                    $r['regula'] = $regula !== null && (int) $regula['is_active'] === 1 ? $regula : null;
                }
                unset($r);
            }
        }
        View::render('admin/seo-404', [
            'title' => 'Jurnalul 404',
            'disponibil' => $disponibil,
            'randuri' => $randuri,
            'total' => $total,
            'pagina' => $pagina,
            'peLaPagina' => self::PE_PAGINA,
            'q' => $q,
            'csrf' => AdminCsrf::token(),
        ], 'admin/layout');
    }

    /** POST /admin/seo/404/{id}/sterge: „Ignoră” (adresa dispare din jurnal; revine dacă cineva o cere din nou). */
    public function sterge404(array $params): void
    {
        if (!$this->gardaPost(self::JURNAL)) {
            return;
        }
        $db = $this->db();
        if ($db instanceof PDO) {
            try {
                $db->prepare('DELETE FROM seo_404_log WHERE id = ?')->execute([(int) ($params['id'] ?? 0)]);
                Flash::set('success', 'Adresa a fost scoasă din jurnal.');
            } catch (Throwable) {
                Flash::set('error', 'Jurnalul 404 lipsește: rulează migrările.');
            }
        }
        header('Location: ' . self::JURNAL);
    }

    /** POST /admin/seo/404/goleste: tot jurnalul. */
    public function goleste404(): void
    {
        if (!$this->gardaPost(self::JURNAL)) {
            return;
        }
        $db = $this->db();
        if ($db instanceof PDO) {
            try {
                $db->exec('DELETE FROM seo_404_log');
                AdminActivityLog::log($db, 'Jurnalul 404 golit');
                Flash::set('success', 'Jurnalul 404 a fost golit.');
            } catch (Throwable) {
                Flash::set('error', 'Jurnalul 404 lipsește: rulează migrările.');
            }
        }
        header('Location: ' . self::JURNAL);
    }

    /** Garda unui POST: adminul cu drept pe adresă și jetonul formularului. */
    private function gardaPost(string $inapoi = self::LISTA): bool
    {
        if (!$this->guard()) {
            return false;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: ' . $inapoi);
            return false;
        }
        return true;
    }

    /** Unde se întoarce formularul: doar o adresă a adminului (`inapoi`), altfel lista. */
    private function inapoi(): string
    {
        $inapoi = (string) ($_POST['inapoi'] ?? '');
        return preg_match('#^/admin(/[A-Za-z0-9/_\-]*)?(\?[A-Za-z0-9=&_\-%.]*)?(\#[a-z0-9-]+)?$#', $inapoi) === 1 && !str_contains($inapoi, '//')
            ? $inapoi : self::LISTA;
    }

    private function autor(PDO $db): string
    {
        try {
            $stmt = $db->prepare('SELECT email FROM admins WHERE id = ? LIMIT 1');
            $stmt->execute([(int) Auth::id()]);
            return (string) ($stmt->fetchColumn() ?: '');
        } catch (Throwable) {
            return '';
        }
    }
}
