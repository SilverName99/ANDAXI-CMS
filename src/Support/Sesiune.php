<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sesiunea PHP la logare (N15, WP-12b).
 *
 * La fiecare logare (adminul: `Auth::attempt`; clientul: `CustomerAuth::login`, adică parola, contul nou și
 * Google) sesiunea primește un id nou, înainte să se scrie în ea cine s-a logat. Tot ce era în sesiune (coșul,
 * formularul de checkout, o altă logare din același browser) trece în sesiunea nouă. Cine are id-ul de dinainte
 * (un id „fixat” de altcineva: dat într-un link, pus ca cookie de pe alt subdomeniu, citit de pe un calculator
 * comun înainte de logare) rămâne cu sesiunea de dinainte de logare: același coș, fără logare.
 *
 * Fișierul sesiunii vechi nu se șterge (`session_regenerate_id(false)`): o cerere a aceluiași browser plecată
 * în același timp cu id-ul vechi (heartbeat-ul coșului) își găsește coșul și nu primește logarea. Un id inventat
 * nu e primit deloc (`session.use_strict_mode=1`, `bootstrap.php`), deci un atacator nu-și poate alege id-ul.
 */
final class Sesiune
{
    /**
     * Id nou pentru sesiunea de acum, cu tot conținutul ei. Fără sesiune pornită (linia de comandă) nu face nimic;
     * la fel dacă antetele au plecat deja (cookie-ul nou n-ar mai ajunge la browser, deci logarea rămâne pe id-ul
     * de acum, ca înainte de WP-12b). Întoarce true doar dacă id-ul s-a schimbat.
     */
    public static function idNouLaLogare(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        if ((bool) ini_get('session.use_cookies') && headers_sent()) {
            return false;
        }
        return session_regenerate_id(false);
    }
}
