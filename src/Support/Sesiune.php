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
 * comun înainte de logare) rămâne cu sesiunea de dinainte de logare: același coș, **fără nicio logare**, nici
 * cea nouă, nici una de dinainte (`CHEI_LOGARE` se scot din ea), deci ieșirea din cont în sesiunea nouă închide
 * tot ce era logat în browser.
 *
 * Fișierul sesiunii vechi nu se șterge (`session_regenerate_id(false)`): o cerere a aceluiași browser plecată
 * în același timp cu id-ul vechi (heartbeat-ul coșului) își găsește coșul și nu primește logarea; o sesiune
 * ștearsă i-ar fi dat un id nou, iar cookie-ul lui ar fi înlocuit în browser pe cel al logării. Un id inventat
 * nu e primit deloc (`session.use_strict_mode=1`, `bootstrap.php`), deci un atacator nu-și poate alege id-ul.
 *
 * Coșul abandonat (`cart_abandonments`, emailul „Ai uitat produse în coș”) are cheia `cheieCosAbandonat()`, nu
 * id-ul de acum: id-ul sesiunii de dinainte de prima logare, păstrat în sesiune. Codul de dinainte nu schimba
 * niciodată id-ul, deci un browser avea un singur rând; așa rămâne și acum (un rând pe cheia id-ului nou ar fi
 * trimis două emailuri, iar o comandă după logare ar fi lăsat rândul de dinainte „abandonat”). Sesiunea veche e
 * însemnată ca înlocuită (`CHEIE_INLOCUITA`) și nu mai atinge rândul: nici heartbeat-ul care așteaptă lacătul în
 * timpul logării, nici cine ține id-ul fixat. Un heartbeat care citise sesiunea chiar înainte de logare scrie tot
 * în același rând, pe aceeași cheie.
 */
final class Sesiune
{
    /** Cheile sesiunii care spun cine e logat (`Auth::attempt`, `CustomerAuth::login`). */
    public const CHEI_LOGARE = ['admin_id', 'admin_roles', 'admin_role', 'admin_last_seen', CustomerAuth::SESSION_KEY,
        CustomerAuth::SESSION_VERSION_KEY];

    /** Cheia rândului din `cart_abandonments`, scrisă la prima logare (id-ul de dinainte de ea). */
    public const CHEIE_COS_ABANDONAT = 'cos_abandonat_cheie';

    /** Însemnul sesiunii vechi, înlocuite de una nouă la logare (rămâne doar în fișierul id-ului de dinainte). */
    public const CHEIE_INLOCUITA = 'sesiune_inlocuita_la_logare';

    /**
     * Id nou pentru sesiunea de acum, cu tot conținutul ei; sesiunea de dinainte rămâne fără logări și însemnată
     * ca înlocuită. Fără sesiune pornită (linia de comandă) nu face nimic; la fel dacă antetele au plecat deja
     * (cookie-ul nou n-ar mai ajunge la browser, iar PHP refuză oricum, cu un avertisment, și fără cookie-uri),
     * deci logarea rămâne pe id-ul de acum, ca înainte de WP-12b. Întoarce true doar dacă id-ul s-a schimbat.
     */
    public static function idNouLaLogare(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return false;
        }
        // O sesiune deja înlocuită (cine ține un id fixat și se loghează pe el) nu duce mai departe cheia coșului
        // abandonat al celui care s-a logat întâi: primește cheia id-ului ei nou, mai jos.
        $inlocuita = isset($_SESSION[self::CHEIE_INLOCUITA]);
        if (!is_string($_SESSION[self::CHEIE_COS_ABANDONAT] ?? null) || $_SESSION[self::CHEIE_COS_ABANDONAT] === '') {
            $_SESSION[self::CHEIE_COS_ABANDONAT] = (string) session_id();
        }
        // Ce rămâne în fișierul id-ului de dinainte (session_regenerate_id(false) îl scrie cu $_SESSION de acum):
        // fără logări, cu însemnul. În sesiunea nouă logările de dinainte se pun la loc, fără însemn.
        $logari = [];
        foreach (self::CHEI_LOGARE as $cheie) {
            if (array_key_exists($cheie, $_SESSION)) {
                $logari[$cheie] = $_SESSION[$cheie];
                unset($_SESSION[$cheie]);
            }
        }
        $_SESSION[self::CHEIE_INLOCUITA] = time();
        $schimbat = session_regenerate_id(false);
        if ($schimbat) {
            unset($_SESSION[self::CHEIE_INLOCUITA]);
            if ($inlocuita) {
                $_SESSION[self::CHEIE_COS_ABANDONAT] = (string) session_id();
            }
        } elseif (!$inlocuita) {
            // Id-ul n-a putut fi schimbat: sesiunea merge mai departe pe id-ul de acum, ca înainte de WP-12b.
            unset($_SESSION[self::CHEIE_INLOCUITA]);
        }
        foreach ($logari as $cheie => $valoare) {
            $_SESSION[$cheie] = $valoare;
        }
        return $schimbat;
    }

    /**
     * Cheia coșului abandonat al sesiunii (`EmailAutomation::touchCartAbandonment`, `markCartConverted`): id-ul de
     * dinainte de prima logare sau, fără logare, id-ul de acum (ca în codul de dinainte). Gol într-o sesiune
     * înlocuită la logare, deci acolo coșul abandonat nu se mai atinge.
     */
    public static function cheieCosAbandonat(): string
    {
        if (isset($_SESSION[self::CHEIE_INLOCUITA])) {
            return '';
        }
        $cheie = $_SESSION[self::CHEIE_COS_ABANDONAT] ?? null;
        return is_string($cheie) && $cheie !== '' ? $cheie : (string) session_id();
    }
}
