<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Support\NewsletterService;
use App\Support\Settings;
use App\Support\WpCompat;
use PDO;
use Throwable;

/**
 * Listele automate ale lui MailPoet pe un site mutat (pista E; specificația §4.5.3–4.5.5), un singur serviciu pentru pistele magazinului
 * (D, I, L), care îl cheamă la momentele lor:
 *
 *  - `contCreat()`: un cont nou de client → lista utilizatorilor WordPress (`tip` = `wp_users`), ca sincronizarea WP a lui MailPoet;
 *  - `comanda()`: o comandă cu adresa de facturare (plătită sau nu, ca MailPoet) → abonatul e client WooCommerce, cu lista clienților
 *    (`tip` = `woocommerce_users`);
 *  - `optinCheckout()`: bifa „Aș dori să primesc e-mailuri…” din checkout (câmpul `mailpoet_woocommerce_checkout_optin`, pista L) → dubla
 *    confirmare pe lista clienților și pe listele din setările MailPoet (`mailpoet_optin_checkout`), sursa `woocommerce_checkout`.
 *
 * Doar pe un site cu `wp-compat.json` → `mailpoet` (altfel nu face nimic) și doar cu listele importate. Lista utilizatorilor nu se
 * dezabonează niciodată la o dezabonare globală (Abonati::dezaboneaza). O adresă de pe lista „nu trimite” (un abonat șters la cererea
 * lui) nu revine ca rând nou din comenzi. Lista internă „Toți abonații” nu primește pe nimeni de aici (E-LEG-02): pe un site cu MailPoet
 * acordul e pe fiecare listă, iar lista internă nu e o țintă a campaniilor.
 */
final class Sincronizare
{
    /** Setarea MailPoet „abonează clienții vechi” (importul): o comandă a unui abonat neconfirmat îl pune abonat pe lista clienților. */
    public const CHEIE_CLIENTI_VECHI = 'mailpoet_abonati_clienti_vechi';

    /** Cont nou: adresa contului, id-ul lui (`wp_user_id` al abonatului), numele. */
    public static function contCreat(PDO $db, int $contId, string $email, string $prenume = '', string $nume = '', string $ip = ''): void
    {
        $lista = self::lista($db, 'wp_users');
        $email = strtolower(trim($email));
        if ($lista === null || !WpCompat::mailpoet() || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            $abonat = Abonati::dupaAdresa($db, ['email' => $email]);
            if ($abonat === null) {
                $setari = Settings::all($db);
                if (in_array((string) ($setari['mailpoet_abonare_la_cont'] ?? ''), ['1', 'true'], true)) {
                    // Cu bifa MailPoet „abonare la înregistrare”: dubla confirmare, cu emailul ei.
                    NewsletterService::cerereAbonare($db, $lista, $email, trim($prenume . ' ' . $nume), $ip, $setari,
                        ['sursa' => 'wordpress_user', 'prenume' => $prenume, 'nume' => $nume]);
                } elseif (!NewsletterService::esteSuprimat($db, $email)) {
                    $db->prepare("INSERT IGNORE INTO newsletter_subscribers (email, name, status, pending_at, source, first_name, last_name)
                        VALUES (?, ?, 'pending', NOW(), 'wordpress_user', ?, ?)")->execute([$email, mb_substr(trim($prenume . ' ' . $nume), 0, 190),
                        mb_substr($prenume, 0, 255), mb_substr($nume, 0, 255)]);
                }
                $abonat = Abonati::dupaAdresa($db, ['email' => $email]);
                if ($abonat === null) {
                    return;
                }
            }
            $db->prepare('UPDATE newsletter_subscribers SET wp_user_id = ? WHERE id = ? AND wp_user_id IS NULL')->execute([$contId > 0 ? $contId : null, (int) $abonat['id']]);
            $nou = self::leaga($db, (int) $abonat['id'], $lista, 'subscribed', true);
            if ($nou && (string) $abonat['status'] === 'active') {
                NewsletterService::abonareConfirmata($db, (int) $abonat['id'], $lista, 'cont');
            }
        } catch (Throwable $e) {
            error_log('ANDAXI-CMS: MailPoet: lista utilizatorilor nu s-a putut actualiza (' . get_class($e) . ')');
        }
    }

    /**
     * O comandă cu adresa de facturare: abonatul (făcut dacă lipsește, `unsubscribed`, sursa `woocommerce_user`) devine client
     * WooCommerce (`is_woocommerce_user` = 1), cu prenumele și numele din facturare (fiecare când nu e gol și n-are `<` sau `>`, și peste
     * cele de dinainte), și are rândul listei clienților: `unsubscribed` cât nu a confirmat niciodată (fără `confirmed_at` și
     * `confirmed_ip`, afară de setarea MailPoet „abonează clienții vechi”), altfel `subscribed`; un rând existent rămâne cum e. Un abonat
     * doar al acestei liste, cu rândul ei `unsubscribed`, are starea globală `unsubscribed` (ca MailPoet).
     */
    public static function comanda(PDO $db, string $email, string $prenume = '', string $nume = '', ?int $contId = null): void
    {
        $lista = self::lista($db, 'woocommerce_users');
        $email = strtolower(trim($email));
        if ($lista === null || !WpCompat::mailpoet() || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            if (Abonati::dupaAdresa($db, ['email' => $email]) === null && !NewsletterService::esteSuprimat($db, $email)) {
                $db->prepare("INSERT IGNORE INTO newsletter_subscribers (email, name, status, source, is_woocommerce_user, last_subscribed_at)
                    VALUES (?, '', 'unsubscribed', 'woocommerce_user', 1, NOW())")->execute([$email]);
            }
            $abonat = Abonati::dupaAdresa($db, ['email' => $email]);
            if ($abonat === null) {
                return;
            }
            $id = (int) $abonat['id'];
            // Numele din facturare, la fiecare comandă, peste cele de dinainte (ca WooCommerce::synchronizeGuestCustomer al lui MailPoet):
            // fiecare doar când nu e gol și n-are `<` sau `>` (verificarea formularului de abonare).
            $prenume = trim($prenume);
            $nume = trim($nume);
            $bun = static fn (string $v): bool => $v !== '' && $v !== '0' && strpbrk($v, '<>') === false;
            if ($bun($prenume) || $bun($nume)) {
                $p = $bun($prenume) ? mb_substr($prenume, 0, 255) : (string) ($abonat['first_name'] ?? '');
                $n = $bun($nume) ? mb_substr($nume, 0, 255) : (string) ($abonat['last_name'] ?? '');
                $db->prepare('UPDATE newsletter_subscribers SET first_name = ?, last_name = ?, name = ? WHERE id = ?')
                    ->execute([$p, $n, mb_substr(trim($p . ' ' . $n), 0, 190), $id]);
            }
            $db->prepare('UPDATE newsletter_subscribers SET is_woocommerce_user = 1' . ($contId !== null && $contId > 0 ? ', wp_user_id = COALESCE(wp_user_id, ?)' : '')
                . ' WHERE id = ?')->execute($contId !== null && $contId > 0 ? [$contId, $id] : [$id]);
            $vechi = in_array((string) (Settings::all($db)[self::CHEIE_CLIENTI_VECHI] ?? ''), ['1', 'true'], true);
            $confirmat = ($abonat['confirmed_at'] ?? null) !== null || ($abonat['confirmed_ip'] ?? null) !== null;
            self::leaga($db, $id, $lista, $confirmat || $vechi ? 'subscribed' : 'unsubscribed', false);
            // Doar clientul WooCommerce (nicio altă listă MailPoet), cu lista clienților dezabonată: dezabonat global.
            $q = $db->prepare("SELECT ls.list_id, ls.status FROM newsletter_list_subscribers ls INNER JOIN newsletter_lists l ON l.id = ls.list_id
                WHERE ls.subscriber_id = ? AND l.is_default = 0");
            $q->execute([$id]);
            $randuri = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            if (array_keys($randuri) === [$lista] && (string) $randuri[$lista] === 'unsubscribed' && (string) $abonat['status'] !== 'unsubscribed') {
                $db->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', pending_at = NULL WHERE id = ?")->execute([$id]);
            }
        } catch (Throwable $e) {
            error_log('ANDAXI-CMS: MailPoet: lista clienților nu s-a putut actualiza (' . get_class($e) . ')');
        }
    }

    /**
     * Bifa din checkout, la plasarea comenzii: întâi comanda (clientul), apoi, doar cu bifa, sursa `woocommerce_checkout` și listele
     * (lista clienților și cele din `mailpoet_optin_checkout`): un abonat activ le primește pe loc (cu evenimentul pentru cele noi și, dacă
     * n-avea nicio dovadă a confirmării, IP-ul și momentul de acum, ca Subscription::subscribe), oricare altă stare (în așteptare,
     * dezabonat, inactiv, sărit) dubla confirmare (NewsletterService::cerereAbonare, cu limitele formularelor publice). Fără bifă nu se
     * schimbă nimic altceva (o alegere de dinainte nu se suprascrie).
     *
     * @param array<string, mixed> $setari setările site-ului (Settings::all)
     * @return string|null starea cererii de abonare (`trimis`, `deja_activ`, o limită) sau null (fără bifă, fără liste)
     */
    public static function optinCheckout(PDO $db, string $email, string $prenume, string $nume, bool $bifata, string $ip, array $setari, ?int $contId = null): ?string
    {
        self::comanda($db, $email, $prenume, $nume, $contId);
        $lista = self::lista($db, 'woocommerce_users');
        if (!$bifata || $lista === null || !WpCompat::mailpoet()) {
            return null;
        }
        $config = json_decode((string) ($setari['mailpoet_optin_checkout'] ?? ''), true);
        // Bifa oprită în setările MailPoet (`activ` = false, importul): checkout-ul nu o arată, deci nicio abonare, nicio rezervare și
        // niciun email, oricare ar fi câmpul trimis (E-LEG-09).
        if (!is_array($config) || ($config['activ'] ?? false) !== true) {
            return null;
        }
        $liste = [$lista];
        foreach (is_array($config['liste'] ?? null) ? $config['liste'] : [] as $l) {
            if (is_int($l) && $l > 0) {
                $liste[] = $l;
            }
        }
        $liste = array_values(array_unique($liste));
        try {
            $abonat = Abonati::dupaAdresa($db, ['email' => strtolower(trim($email))]);
            if ($abonat === null) {
                return null;
            }
            $db->prepare("UPDATE newsletter_subscribers SET source = 'woocommerce_checkout' WHERE id = ?")->execute([(int) $abonat['id']]);
            if ((string) $abonat['status'] === 'active') {
                // Ca Subscription::subscribe al lui MailPoet: dovada acordului (IP-ul și momentul) doar când lipsesc amândouă.
                $db->prepare("UPDATE newsletter_subscribers SET confirmed_ip = ?, confirmed_at = NOW() WHERE id = ? AND (confirmed_ip IS NULL OR confirmed_ip = '')
                    AND confirmed_at IS NULL")->execute([$ip !== '' ? mb_substr($ip, 0, 45) : null, (int) $abonat['id']]);
                foreach ($liste as $l) {
                    if (self::leaga($db, (int) $abonat['id'], $l, 'subscribed', true)) {
                        NewsletterService::abonareConfirmata($db, (int) $abonat['id'], $l, 'woocommerce_checkout');
                    }
                }
                return 'deja_activ';
            }
            $rez = NewsletterService::cerereAbonare($db, $liste[0], (string) $abonat['email'], trim($prenume . ' ' . $nume), $ip, $setari,
                ['sursa' => 'woocommerce_checkout', 'prenume' => $prenume, 'nume' => $nume, 'liste' => array_slice($liste, 1)]);
            return (string) $rez['stare'];
        } catch (Throwable $e) {
            error_log('ANDAXI-CMS: MailPoet: bifa din checkout nu s-a putut procesa (' . get_class($e) . ')');
            return null;
        }
    }

    /** Lista importată de tipul dat (`wp_users`, `woocommerce_users`), sau null. */
    public static function lista(PDO $db, string $tip): ?int
    {
        try {
            $q = $db->prepare('SELECT id FROM newsletter_lists WHERE tip = ? ORDER BY id LIMIT 1');
            $q->execute([$tip]);
            $id = (int) ($q->fetchColumn() ?: 0);
        } catch (Throwable) {
            return null;
        }
        return $id > 0 ? $id : null;
    }

    /**
     * Rândul abonatului pe listă: nou cu starea dată; unul existent se schimbă doar cu `$suprascrie` (atunci la starea dată). Întoarce
     * true dacă rândul a devenit `subscribed` acum (nu era înainte).
     */
    private static function leaga(PDO $db, int $abonatId, int $listaId, string $stare, bool $suprascrie): bool
    {
        if ($listaId <= 0) {
            return false;
        }
        $q = $db->prepare('SELECT status FROM newsletter_list_subscribers WHERE list_id = ? AND subscriber_id = ?');
        $q->execute([$listaId, $abonatId]);
        $inainte = $q->fetchColumn();
        if ($inainte === false) {
            $db->prepare('INSERT IGNORE INTO newsletter_list_subscribers (list_id, subscriber_id, status, updated_at) VALUES (?, ?, ?, NOW())')
                ->execute([$listaId, $abonatId, $stare]);
            return $stare === 'subscribed';
        }
        if ($suprascrie && (string) $inainte !== $stare) {
            $db->prepare('UPDATE newsletter_list_subscribers SET status = ?, updated_at = NOW() WHERE list_id = ? AND subscriber_id = ?')
                ->execute([$stare, $listaId, $abonatId]);
            return $stare === 'subscribed';
        }
        return false;
    }
}
