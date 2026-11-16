<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\EmailContinut;
use App\Support\View;
use PDO;

/**
 * „Vezi” din Admin → Rapoarte → Emailuri trimise (16 nov 2026; App\Support\EmailContinut, migrarea nucleu/0067):
 *
 *  - `GET /admin/emails/history/vezi?id=<rând>`: corpul sertarului (fragment, layout-ul admin/fragment): datele rândului,
 *    starea conținutului și emailul într-un iframe `sandbox=""` (fără scripturi, fără aceeași origine, fără formulare,
 *    fără ferestre noi), plus varianta text;
 *  - `GET /admin/emails/history/continut?id=<rând>`: doar emailul, cu `Content-Security-Policy: sandbox` (fără
 *    `allow-scripts`), deci și deschis direct, într-un tab, HTML-ul emailului rulează într-o origine opacă, fără scripturi,
 *    fără formulare și fără acces la cookie-urile adminului; imaginile și stilurile emailului se încarcă (fără Referer).
 *
 * Amândouă: doar citire (GET, fără jeton), aceeași gardă ca pagina istoricului (`/admin/emails…`: administratorul general
 * și cel de emailuri; blogul, magazinul și cloudul nu), `no-store`, `noindex`. Conținutul nu se scrie în niciun jurnal.
 */
final class IstoricEmailController
{
    use Comun;

    public function vezi(): void
    {
        if (!$this->guard()) {
            return;
        }
        $this->antetePrivate();
        $db = $this->db();
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $date = $db instanceof PDO ? EmailContinut::previzualizare($db, $id) : ['rand' => null, 'stare' => 'lipsa', 'html' => null,
            'mesaj' => 'Conexiunea DB nu este disponibilă.', 'marime' => 0, 'campanie' => null];
        if ($date['rand'] === null) {
            http_response_code(404);
        }
        header('Content-Type: text/html; charset=utf-8');
        View::render('admin/emails/istoric-vezi', [
            'previzualizare' => $date,
            'idRand' => $id,
            'textSimplu' => is_string($date['html']) ? \App\Support\OrderMailer::htmlInText($date['html']) : '',
        ], 'admin/fragment');
    }

    public function continut(): void
    {
        if (!$this->guard()) {
            return;
        }
        $this->antetePrivate();
        // Emailul rulează izolat oriunde ar fi deschis: origine opacă, fără scripturi, fără formulare, fără ferestre;
        // în cadru doar în paginile adminului. Imaginile și stilurile de pe alte site-uri (logo, produse) se încarcă.
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src * data: cid:; style-src 'unsafe-inline' *; "
            . "font-src * data:; frame-ancestors 'self'; form-action 'none'; base-uri 'none'");
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=utf-8');

        $db = $this->db();
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $date = $db instanceof PDO ? EmailContinut::previzualizare($db, $id) : ['rand' => null, 'html' => null,
            'mesaj' => 'Conexiunea DB nu este disponibilă.'];
        if ($date['rand'] === null) {
            http_response_code(404);
        }
        if (is_string($date['html'])) {
            echo $date['html'];
            return;
        }
        echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><title>Email</title></head>'
            . '<body style="margin:0;padding:24px;font-family:Arial,sans-serif;color:#374151;background:#f9fafb;">'
            . '<p style="margin:0;">' . htmlspecialchars((string) $date['mesaj'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '</body></html>';
    }

    private function antetePrivate(): void
    {
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
    }
}
