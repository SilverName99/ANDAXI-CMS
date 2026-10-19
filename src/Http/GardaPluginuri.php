<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Controllers\SiteController;
use App\Support\Auth;
use App\Support\Env;
use App\Support\Plugins;
use App\Support\View;

/**
 * Garda routerului: o rută a unui plugin pe care site-ul nu-l are (sau l-a
 * oprit) nu ajunge la controller.
 *
 *  - admin: pagina „Modulul nu este inclus în abonament” (403), în aspectul
 *    adminului; fără login, la pagina de login, ca orice pagină de admin;
 *  - site: pagina 404 a site-ului (sau redirecționarea din licență, dacă duce
 *    la o pagină care se vede pe site);
 *    pentru /api/… un 404 scurt, în JSON;
 *  - adresele de confirmare (plăți, ERP, linkuri din emailuri trimise) răspund
 *    MEREU; dacă pluginul e oprit, prima cerere din zi la fiecare adresă se
 *    notează în jurnal.
 *
 * Proprietarul fiecărei rute vine din `config/plugins.php` (Plugins::proprietarRuta).
 */
final class GardaPluginuri
{
    public static function permite(string $metoda, string $tipar, array $parametri): bool
    {
        $proprietar = Plugins::proprietarRuta($metoda, $tipar, $parametri, $_GET, $_POST);
        if (Plugins::oricareVizibil($proprietar['chei'])) {
            return true;
        }
        $chei = implode('|', $proprietar['chei']);

        if ($proprietar['mereu_deschis']) {
            // O dată pe zi pentru fiecare adresă: pixelul unui newsletter vechi sau un
            // robot pe o adresă de confirmare nu are voie să umple jurnalul.
            Plugins::jurnalOdataPeZi('mereu-deschisa|' . $chei . '|' . $metoda . ' ' . $tipar, 'Cerere ' . $metoda . ' ' . self::cale()
                . ' primită și procesată, deși pluginul ' . $chei . ' e oprit (azi nu se mai notează altele la aceeași adresă).');
            return true;
        }

        // Doar în dezvoltare (tools/dev): spune testelor că a răspuns garda, nu controllerul.
        if ((string) Env::get('CMS_DEV_GARDA_ANTET', '') === '1') {
            header('X-Andaxi-Garda: ' . $chei);
        }

        if ($tipar === '/admin' || str_starts_with($tipar, '/admin/')) {
            if (!Auth::check()) {
                header('Location: /admin/login');
                return false;
            }
            http_response_code(403);
            View::render('admin/modul-indisponibil', ['title' => 'Modul indisponibil'], 'admin/layout');
            return false;
        }

        // Cererile scripturilor din pagină (/api/…) primesc un 404 scurt, nu o pagină întreagă.
        if (str_starts_with($tipar, '/api/')) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Pagina nu a fost găsită.'], JSON_UNESCAPED_UNICODE);
            return false;
        }

        // Adresa aleasă în CRM pentru pluginul oprit (de exemplu prima pagină); doar pentru GET
        // și doar spre o pagină care se vede pe site (altfel: buclă sau 302 spre un 404).
        if ($metoda === 'GET') {
            foreach ($proprietar['chei'] as $cheie) {
                $redirectionare = Plugins::redirectionare($cheie);
                if ($redirectionare !== null && $redirectionare !== self::cale() && Plugins::adresaVizibila($redirectionare)) {
                    header('Location: ' . $redirectionare, true, 302);
                    return false;
                }
            }
        }

        (new SiteController())->paginaNegasita();
        return false;
    }

    private static function cale(): string
    {
        return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    }
}
