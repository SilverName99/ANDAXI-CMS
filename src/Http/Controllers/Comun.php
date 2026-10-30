<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AppUrl;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Flash;
use PDO;

/**
 * Ce au în comun controllerele noi ale pluginurilor (WP-25: VT-X4, port-vertij §4.4): baza, răspunsul JSON, corpul
 * cererii, adresa site-ului și garda adminului. Codul nou al unui plugin stă într-un controller al lui (de exemplu
 * `CereriOfertaController`, `ConfirmareEmailController`), nu în cele două controllere mari, care nu se mai lungesc.
 * Metodele fac exact ce fac cele cu același nume din `SiteController` și `AdminController`.
 */
trait Comun
{
    protected function db(): ?PDO
    {
        $config = require __DIR__ . '/../../../config/app.php';
        return Database::connection((array) ($config['db'] ?? []));
    }

    protected function jsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Câmpurile formularului sau, fără ele, corpul JSON al cererii. */
    protected function requestPayload(): array
    {
        if ($_POST !== [] && is_array($_POST)) {
            return $_POST;
        }
        $brut = file_get_contents('php://input');
        if ($brut === false || trim($brut) === '') {
            return [];
        }
        $decodat = json_decode($brut, true);
        return is_array($decodat) ? $decodat : [];
    }

    protected function appUrl(): string
    {
        return AppUrl::base();
    }

    /**
     * Garda paginilor de admin: un admin logat, cu drept pe adresa cerută (rolurile din `Auth::canAccessPath`); altfel
     * redirecționarea de azi (logarea sau prima pagină a rolului).
     */
    protected function guard(): bool
    {
        if (!Auth::check()) {
            header('Location: /admin/login');
            return false;
        }
        $cale = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH) ?: '/admin';
        if (!Auth::canAccessPath($cale)) {
            Flash::set('error', 'Nu ai permisiunea necesară pentru această secțiune.');
            header('Location: ' . Auth::defaultPathForRole());
            return false;
        }
        return true;
    }
}
