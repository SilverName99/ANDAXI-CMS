<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ConfirmareEmail;
use App\Support\CustomerAuth;
use App\Support\Flash;
use App\Support\IpClient;
use App\Support\Settings;
use App\Support\View;
use PDO;
use Throwable;

/**
 * Confirmarea adresei de email a contului de client (WP-25: VT-C9, VT-C8; App\Support\ConfirmareEmail): linkul primit
 * pe email (`GET /cont/confirmare/{token}` arată butonul, `POST` confirmă; mereu deschise, ca un link deja trimis să
 * meargă în orice stare a pluginului)
 * și retrimiterea lui (`POST /contul-meu/confirmare-email`), din cont (rândul „Ai comenzi mai vechi…”) sau de pe pagina
 * de intrare (un cont care așteaptă confirmarea). Primul controller pe trăsătura `Comun` (VT-X4).
 */
final class ConfirmareEmailController
{
    use Comun;

    /**
     * `GET /cont/confirmare/{token}`: pagina cu butonul „Confirmă adresa” (revizuirea WP-25: deschiderea linkului nu
     * confirmă nimic, ca un program care verifică linkurile din emailuri să nu-l folosească în locul clientului). Un link
     * „comenzi” deschis fără logare în contul lui trimite întâi la intrarea în cont.
     */
    public function confirma(array $params): void
    {
        $db = $this->db();
        $token = (string) ($params['token'] ?? '');
        $link = $db instanceof PDO ? ConfirmareEmail::linkValid($db, $token) : null;
        if ($link === null) {
            Flash::set('error', 'Linkul de confirmare a expirat sau a fost deja folosit. Cere unul nou din contul tău.');
            header('Location: ' . ((int) (CustomerAuth::id() ?? 0) > 0 ? '/contul-meu' : '/login'));
            return;
        }
        if ($link['scop'] === ConfirmareEmail::SCOP_COMENZI && (int) (CustomerAuth::id() ?? 0) !== $link['user_id']) {
            Flash::set('error', 'Intră în contul pentru care ai cerut linkul, apoi apasă „Confirmă adresa”.');
            header('Location: /login?next=' . rawurlencode('/cont/confirmare/' . $token));
            return;
        }
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        View::render('site/confirmare-email', [
            'title' => 'Confirmă adresa de email',
            'metaRobots' => 'noindex,nofollow',
            'token' => $token,
            'pentruComenzi' => $link['scop'] === ConfirmareEmail::SCOP_COMENZI,
        ]);
    }

    /** `POST /cont/confirmare/{token}`: apăsarea butonului; abia acum se folosește linkul. */
    public function confirmaPost(array $params): void
    {
        $db = $this->db();
        $logat = CustomerAuth::id();
        $logat = $logat !== null && $logat > 0 ? $logat : null;
        $token = (string) ($params['token'] ?? '');
        $rezultat = $db instanceof PDO ? ConfirmareEmail::folosesteLink($db, $token, $logat, (string) ($_POST['password'] ?? ''))
            : ['rezultat' => ConfirmareEmail::LINK_INVALID, 'user_id' => null, 'comenzi' => 0, 'puncte' => 0];
        switch ($rezultat['rezultat']) {
            case ConfirmareEmail::LINK_LOGARE:
                Flash::set('error', 'Intră în contul pentru care ai cerut linkul, apoi apasă „Confirmă adresa”.');
                header('Location: /login?next=' . rawurlencode('/cont/confirmare/' . $token));
                return;
            case ConfirmareEmail::LINK_PAROLA:
                Flash::set('error', 'Parola nu este corectă. Scrie parola contului pe care l-ai creat, apoi apasă „Confirmă adresa”.');
                header('Location: /cont/confirmare/' . rawurlencode($token));
                return;
            case ConfirmareEmail::LINK_CONFIRMAT:
                unset($_SESSION['confirmare_email_cont']);
                if ($logat === null && $db instanceof PDO) {
                    // Linkul de înregistrare cu parola contului: clientul intră direct.
                    CustomerAuth::login((int) $rezultat['user_id']);
                }
                Flash::set('success', 'Adresa de email a fost confirmată.' . ((int) $rezultat['puncte'] > 0
                    ? ' Am adăugat ' . (int) $rezultat['puncte'] . ' puncte de fidelitate din comenzile anterioare.' : ''));
                header('Location: /contul-meu?section=orders');
                return;
            default:
                Flash::set('error', 'Linkul de confirmare a expirat sau a fost deja folosit. Cere unul nou din contul tău.');
                header('Location: ' . ($logat !== null ? '/contul-meu' : '/login'));
        }
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
