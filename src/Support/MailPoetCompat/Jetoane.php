<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Support\CadruCapturat;
use App\Support\NewsletterService;
use PDO;
use Throwable;

/**
 * Jetoanele abonaților din adresele MailPoet (pista E; docs/PLUGINURI.md, „Newsletter”, adresele vechi MailPoet).
 *
 * Jetonul MailPoet (`link_token`) nu se păstrează nicăieri: importul scrie doar amprentele lui (`mailpoet_token_hash` =
 * sha256(jetonul), `mailpoet_token_len`, iar pentru jetoanele trecute în 5.41 de la 6 la 32 de caractere și `mailpoet_token6_hash` =
 * sha256(primele 6)). Regula de verificare e a lui MailPoet 5.45.2:
 *
 *  1. jetonul cererii trebuie să fie text; un abonat fără jeton importat nu trece pe ramurile MailPoet (fail-closed: MailPoet ar fi
 *     făcut atunci unul nou, dar niciun link spre el nu există);
 *  2. primele `mailpoet_token_len` caractere ale cererii au amprenta `mailpoet_token_hash` (un jeton mai lung, cu începutul bun, trece);
 *  3. jetonul CMS-ului (`ax1.` + 22 de caractere base64url, HMAC pe id și adresă, cu o cheie derivată din cheia secretă a
 *     newsletterului: ax1()), pus de CMS în linkurile pe care le construiește după mutare; nu expiră și nu se stochează;
 *  4. perioada de grație a jetoanelor vechi de 6 caractere (setarea `mailpoet_jetoane_vechi_pana_la`, din MailPoet): doar pentru un
 *     jeton trecut la 32 de caractere, cât abonatul are sub MAX_NEPOTRIVIRI jetoane greșite în ultimele 24 de ore, primele 6 caractere
 *     ale cererii cu amprenta `mailpoet_token6_hash`; altfel nepotrivirea se numără (o dată pe jeton și cerere; nu se mai numără peste
 *     limită, deci limita ține 24 de ore de la ultima numărată, ca tranzientul lui MailPoet).
 *
 * Un jeton păstrat mai scurt de LUNGIME_SIGURA caractere (un abonat al cărui jeton MailPoet nu a fost niciodată lungit) se ghicește
 * ușor: pentru el, regula 2 trece doar cât abonatul are sub MAX_NEPOTRIVIRI nepotriviri în 24 de ore (verificat înaintea comparației),
 * iar o cerere care nu se potrivește nicăieri se numără la fel (SEC-E-02). Peste limită nici jetonul bun nu mai trece, 24 de ore de la
 * ultima nepotrivire numărată (ca la perioada de grație), pe drumurile al căror răspuns arată dacă jetonul e bun (paginile, clicurile,
 * „vezi în browser”): altfel ele ar fi un oracol care ocolește limita. Retragerea „cu un clic” (`$retragere`: POST-ul RFC 8058, care
 * răspunde mereu același 200 gol, deci nu arată nimic) primește jetonul întreg și peste limită, ca MailPoet 5.45.2, care îl primește
 * mereu (SEC-E-02-R1): oricine poate număra nepotriviri pentru un abonat, dar nimeni nu-l poate opri să se dezaboneze. Jetonul
 * CMS-ului (`ax1.`, din emailurile trimise de site) trece mereu.
 *
 * Varianta de confirmare (doar `action=confirm`): HMAC pe id, adresă și momentul cererii `t` (din `data`), valabilă
 * NewsletterService::ZILE_CONFIRMARE zile, ca linkurile de confirmare ale CMS-ului.
 *
 * Nimic din jurnale nu conține un jeton, o amprentă sau o adresă.
 */
final class Jetoane
{
    public const PREFIX = 'ax1.';
    public const MAX_NEPOTRIVIRI = 10;
    /** Jetoanele păstrate mai scurte de atât se apără cu numărul de nepotriviri (SEC-E-02; jetonul MailPoet de azi are 32). */
    public const LUNGIME_SIGURA = 32;
    public const CHEIE_GRATIE = 'mailpoet_jetoane_vechi_pana_la';
    private const SCOP_CHEIE = 'mailpoet-jeton';

    /** Nepotrivirile numărate în cererea curentă (un jeton verificat de două ori se numără o dată). @var array<string, true> */
    private static array $numarate = [];

    /** Momentul de acum (testele îl îngheață). */
    public static ?int $acum = null;

    public static function acum(): int
    {
        return self::$acum ?? time();
    }

    /**
     * Jetonul cererii e al abonatului? Întoarce forma lui de pus în linkurile paginii (VC-MP-10: jetonul cu care a venit omul, partea
     * verificată; după o potrivire pe cele 6 caractere vechi, jetonul CMS-ului, care nu expiră odată cu perioada de grație), sau null.
     * `$retragere`: o dezabonare „cu un clic” (un răspuns care nu depinde de rezultat): jetonul păstrat întreg trece și peste limita
     * nepotrivirilor unui jeton scurt (SEC-E-02-R1); o nepotrivire se numără ca oricare alta. Grația celor 6 caractere vechi rămâne sub
     * limita ei (regula lui MailPoet).
     *
     * @param array<string, mixed> $abonat rândul din `newsletter_subscribers`
     */
    public static function verifica(PDO $db, array $abonat, mixed $cerere, bool $retragere = false): ?string
    {
        $id = (int) ($abonat['id'] ?? 0);
        if (!is_string($cerere) || $cerere === '' || $id <= 0) {
            return null;
        }
        $lungime = isset($abonat['mailpoet_token_len']) ? (int) $abonat['mailpoet_token_len'] : 0;
        $amprenta = is_string($abonat['mailpoet_token_hash'] ?? null) ? strtolower((string) $abonat['mailpoet_token_hash']) : '';
        $areJeton = $lungime > 0 && strlen($amprenta) === 64;
        $scurt = $areJeton && $lungime < self::LUNGIME_SIGURA;
        $blocat = $scurt && self::nepotriviri($db, $id) >= self::MAX_NEPOTRIVIRI;
        if ($areJeton && (!$blocat || $retragere) && strlen($cerere) >= $lungime && hash_equals($amprenta, hash('sha256', substr($cerere, 0, $lungime)))) {
            return substr($cerere, 0, $lungime);
        }
        if (str_starts_with($cerere, self::PREFIX)) {
            $bun = self::ax1($id, (string) ($abonat['email'] ?? ''));
            foreach (self::variante($id, (string) ($abonat['email'] ?? '')) as $v) {
                if (hash_equals($v, $cerere)) {
                    return $bun;
                }
            }
            return null;
        }
        $vechi = is_string($abonat['mailpoet_token6_hash'] ?? null) ? strtolower((string) $abonat['mailpoet_token6_hash']) : '';
        if (strlen($vechi) === 64 && self::acum() < self::termenGratie($db) && self::nepotriviri($db, $id) < self::MAX_NEPOTRIVIRI) {
            if (hash_equals($vechi, hash('sha256', substr($cerere, 0, 6)))) {
                return self::ax1($id, (string) ($abonat['email'] ?? ''));
            }
            self::noteazaNepotrivire($db, $id, $cerere);
            return null;
        }
        if ($scurt && !$blocat) {
            self::noteazaNepotrivire($db, $id, $cerere);
        }
        return null;
    }

    /** Jetonul CMS-ului al abonatului (`ax1.` + 22 de caractere), cu cheia curentă; '' fără cheie (linkul nu se poate face). */
    public static function ax1(int $id, string $email): string
    {
        $chei = NewsletterService::cheiScop(self::SCOP_CHEIE);
        return $chei === [] ? '' : self::semneaza('mp|' . $id . '|' . strtolower(trim($email)), $chei[0]);
    }

    /** Jetonul variantei de confirmare (HMAC și pe momentul `t`), cu cheia curentă. */
    public static function ax1Confirmare(int $id, string $email, int $t): string
    {
        $chei = NewsletterService::cheiScop(self::SCOP_CHEIE);
        return $chei === [] ? '' : self::semneaza('mpc|' . $id . '|' . strtolower(trim($email)) . '|' . $t, $chei[0]);
    }

    /**
     * Varianta de confirmare: jetonul e cel al momentului `t` din `data` și `t` e de cel mult ZILE_CONFIRMARE zile (și nu din viitor).
     *
     * @param array<string, mixed> $abonat
     */
    public static function verificaConfirmare(array $abonat, mixed $cerere, mixed $t): bool
    {
        if (!is_string($cerere) || !str_starts_with($cerere, self::PREFIX) || !is_int($t) || (int) ($abonat['id'] ?? 0) <= 0) {
            return false;
        }
        $acum = self::acum();
        if ($t > $acum + 300 || $acum - $t > NewsletterService::ZILE_CONFIRMARE * 86400) {
            return false;
        }
        $mesaj = 'mpc|' . (int) $abonat['id'] . '|' . strtolower(trim((string) ($abonat['email'] ?? ''))) . '|' . $t;
        foreach (NewsletterService::cheiScop(self::SCOP_CHEIE) as $cheie) {
            if (hash_equals(self::semneaza($mesaj, $cheie), $cerere)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nonce-ul unui formular al paginilor (câmpul `_wpnonce` al sondajului și al opririi urmăririi, `_ax` al formularului de gestionare):
     * HMAC pe zi, scop și abonat, cu cheia cadrului capturat (ca nonce-urile formularelor capturate). 10 caractere hex, forma unui nonce
     * WordPress (normalizarea `nonce:<câmp>` a dovezii regiunilor îl recunoaște doar așa); ghicitul lui e oprit de bugetul routerului.
     */
    public static function nonce(string $scop, int $abonatId, ?string $zi = null): string
    {
        $cheie = CadruCapturat::cheie();
        if ($cheie === '') {
            return '';
        }
        return substr(hash_hmac('sha256', 'mailpoet-formular|' . ($zi ?? date('Y-m-d', self::acum())) . '|' . $scop . '|' . $abonatId, $cheie), 0, 10);
    }

    /** Nonce-ul trimis e al zilei de azi sau de ieri (hash_equals). */
    public static function nonceBun(string $scop, int $abonatId, mixed $valoare): bool
    {
        if (!is_string($valoare) || $valoare === '') {
            return false;
        }
        foreach ([date('Y-m-d', self::acum()), date('Y-m-d', self::acum() - 86400)] as $zi) {
            $asteptat = self::nonce($scop, $abonatId, $zi);
            if ($asteptat !== '' && hash_equals($asteptat, $valoare)) {
                return true;
            }
        }
        return false;
    }

    /** Sfârșitul perioadei de grație (unix), 0 = niciuna. */
    public static function termenGratie(PDO $db): int
    {
        try {
            $q = $db->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
            $q->execute([self::CHEIE_GRATIE]);
            $v = $q->fetchColumn();
            return is_string($v) && preg_match('/^\d{1,12}$/D', trim($v)) === 1 ? (int) trim($v) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * După perioada de grație, amprentele primelor 6 caractere se șterg (o dată; cronul newsletterului): fără ele nu mai e posibilă
     * nicio potrivire pe jetoanele vechi. Întoarce câte rânduri s-au golit. Fără setare (orice site fără MailPoet): nimic.
     */
    public static function curataJetoaneVechi(PDO $db): int
    {
        $termen = self::termenGratie($db);
        if ($termen <= 0 || self::acum() < $termen) {
            return 0;
        }
        try {
            $n = $db->exec('UPDATE newsletter_subscribers SET mailpoet_token6_hash = NULL, updated_at = updated_at WHERE mailpoet_token6_hash IS NOT NULL');
            $db->exec('DELETE FROM newsletter_mailpoet_nepotriviri');
            return (int) $n;
        } catch (Throwable) {
            return 0;
        }
    }

    /** Câte nepotriviri are abonatul în ultimele 24 de ore (de la ultima numărată). */
    private static function nepotriviri(PDO $db, int $abonatId): int
    {
        try {
            $q = $db->prepare('SELECT numar, ultima_la FROM newsletter_mailpoet_nepotriviri WHERE abonat_id = ?');
            $q->execute([$abonatId]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return self::MAX_NEPOTRIVIRI;
        }
        if (!is_array($r) || strtotime((string) $r['ultima_la']) <= self::acum() - 86400) {
            return 0;
        }
        return (int) $r['numar'];
    }

    private static function noteazaNepotrivire(PDO $db, int $abonatId, string $jeton): void
    {
        $cheie = $abonatId . '|' . hash('sha256', $jeton);
        if (isset(self::$numarate[$cheie])) {
            return;
        }
        self::$numarate[$cheie] = true;
        $acum = date('Y-m-d H:i:s', self::acum());
        $prag = date('Y-m-d H:i:s', self::acum() - 86400);
        try {
            $db->prepare('INSERT INTO newsletter_mailpoet_nepotriviri (abonat_id, numar, ultima_la) VALUES (?, 1, ?)
                ON DUPLICATE KEY UPDATE numar = IF(ultima_la <= ?, 1, LEAST(numar + 1, 255)), ultima_la = ?')
                ->execute([$abonatId, $acum, $prag, $acum]);
        } catch (Throwable $e) {
            error_log('ANDAXI-CMS: MailPoet: o nepotrivire de jeton nu s-a putut nota (' . get_class($e) . ')');
        }
    }

    /** Jetoanele CMS-ului acceptate (câte unul pe cheie acceptată). @return list<string> */
    private static function variante(int $id, string $email): array
    {
        $mesaj = 'mp|' . $id . '|' . strtolower(trim($email));
        return array_map(static fn (string $c): string => self::semneaza($mesaj, $c), NewsletterService::cheiScop(self::SCOP_CHEIE));
    }

    private static function semneaza(string $mesaj, string $cheie): string
    {
        return self::PREFIX . substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $mesaj, $cheie, true)), '+/', '-_'), '='), 0, 22);
    }

    /** Pentru teste: uită nepotrivirile numărate în „cererea” curentă. */
    public static function uitaCererea(): void
    {
        self::$numarate = [];
    }
}
