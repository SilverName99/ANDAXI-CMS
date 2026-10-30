<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ConfirmareEmail;
use App\Support\CustomerAuth;
use App\Support\Flash;
use App\Support\IpClient;
use App\Support\Settings;
use PDO;
use Throwable;

/**
 * Confirmarea adresei de email a contului de client (WP-25: VT-C9, VT-C8; App\Support\ConfirmareEmail): linkul primit
 * pe email (`GET /cont/confirmare/{token}`, mereu deschis, ca un link deja trimis să meargă în orice stare a pluginului)
 * și retrimiterea lui (`POST /contul-meu/confirmare-email`), din cont (rândul „Ai comenzi mai vechi…”) sau de pe pagina
 * de intrare (un cont care așteaptă confirmarea). Primul controller pe trăsătura `Comun` (VT-X4).
 */
final class ConfirmareEmailController
{
    use Comun;

    public function confirma(array $params): void
    {
        $db = $this->db();
        $idUtilizator = $db instanceof PDO ? ConfirmareEmail::folosesteLink($db, (string) ($params['token'] ?? '')) : null;
        $logat = $db instanceof PDO ? CustomerAuth::id() : null;
        if ($idUtilizator === null) {
            Flash::set('error', 'Linkul de confirmare a expirat sau a fost deja folosit. Cere unul nou din contul tău.');
            header('Location: ' . ($logat !== null && $logat > 0 ? '/contul-meu' : '/login'));
            return;
        }
        unset($_SESSION['confirmare_email_cont']);
        Flash::set('success', 'Adresa de email a fost confirmată.' . ($logat === $idUtilizator ? '' : ' Te poți autentifica acum.'));
        header('Location: ' . ($logat === $idUtilizator ? '/contul-meu?section=orders' : '/login'));
    }

    public function retrimite(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea la baza de date nu este disponibilă.');
            header('Location: /login');
            return;
        }
        $logat = (int) (CustomerAuth::id() ?? 0);
        $inAsteptare = (int) ($_SESSION['confirmare_email_cont'] ?? 0);
        $idUtilizator = $logat > 0 ? $logat : $inAsteptare;
        $inapoi = $logat > 0 ? '/contul-meu' : '/login';
        if ($idUtilizator <= 0) {
            header('Location: /login');
            return;
        }
        $ip = IpClient::adresa();
        if (ConfirmareEmail::limitat($db, $ip, 'resend')) {
            Flash::set('error', 'Ai cerut prea multe linkuri de confirmare. Încearcă din nou peste câteva minute.');
            header('Location: ' . $inapoi);
            return;
        }
        ConfirmareEmail::noteazaRetrimitere($db, $ip);
        $stmt = $db->prepare('SELECT id, email, first_name FROM users WHERE id = :id');
        $stmt->execute(['id' => $idUtilizator]);
        $utilizator = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($utilizator) || ConfirmareEmail::confirmat($db, $idUtilizator)) {
            header('Location: ' . $inapoi);
            return;
        }
        $token = ConfirmareEmail::linkNou($db, $idUtilizator, $logat > 0 ? ConfirmareEmail::SCOP_COMENZI : ConfirmareEmail::SCOP_INREGISTRARE);
        if ($token === null) {
            header('Location: ' . $inapoi);
            return;
        }
        try {
            ConfirmareEmail::trimiteEmail($db, Settings::all($db), $utilizator, $token);
            Flash::set('success', 'Ți-am trimis linkul de confirmare la ' . (string) $utilizator['email'] . '. Verifică și dosarul Spam.');
        } catch (Throwable) {
            Flash::set('error', 'Emailul nu a putut fi trimis acum. Încearcă din nou mai târziu.');
        }
        header('Location: ' . $inapoi);
    }
}
