<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Support\NewsletterService;
use PDO;
use Throwable;

/**
 * Abonații din adresele MailPoet (pista E): găsirea după `data.email` și jeton, coada verificată (`queueId`) și efectele paginilor
 * (dezabonarea, confirmarea, formularul de gestionare, oprirea urmăririi, motivul dezabonării), cu regulile lui MailPoet 5.45.2 și
 * diferențele declarate (site-uri/ateliere/ce-se-schimba.md, VC-MP-*).
 *
 * Starea pe listă (`newsletter_list_subscribers.status`) urmează MailPoet: o dezabonare pune fiecare listă pe `unsubscribed`, afară de
 * lista utilizatorilor WordPress (`tip` = `wp_users`) și de lista internă a CMS-ului („Toți abonații”, `is_default` = 1, care nu e o
 * listă MailPoet: starea globală o guvernează).
 */
final class Abonati
{
    /** Adresa demonstrativă a previzualizării (MailPoet). */
    public const DEMO_EMAIL = 'demo@mailpoet.com';
    public const DEMO_JETON = 'bfd0889dbc7f081e171fa0cee7401df2';

    /**
     * Rândul abonatului cu adresa din `data` (fără verificarea jetonului): `email` lipsă, gol sau care nu e text = nimeni. Adresa se
     * compară fără spațiile de la sfârșit și fără literele mari (cum o compara colația MySQL a lui MailPoet).
     *
     * @param array<mixed> $date
     * @return array<string, mixed>|null
     */
    public static function dupaAdresa(PDO $db, array $date): ?array
    {
        $email = $date['email'] ?? null;
        if (!is_string($email) || $email === '' || $email === '0') {
            return null;
        }
        $email = strtolower(rtrim($email, ' '));
        if ($email === '' || strlen($email) > 190) {
            return null;
        }
        try {
            $q = $db->prepare('SELECT * FROM newsletter_subscribers WHERE email = ? LIMIT 1');
            $q->execute([$email]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /** Abonatul după id. @return array<string, mixed>|null */
    public static function dupaId(PDO $db, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $q = $db->prepare('SELECT * FROM newsletter_subscribers WHERE id = ? LIMIT 1');
            $q->execute([$id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /**
     * Coada din `data.queueId`, doar dacă există și abonatul a fost procesat în ea (MailPoet: `processed = 1`): la o campanie, un rând
     * în `newsletter_campaign_sends` (campania cozii, abonatul); la o automatizare, abonatul cozii.
     *
     * @param array<mixed> $date
     * @param array<string, mixed> $abonat
     * @return array<string, mixed>|null
     */
    public static function coada(PDO $db, array $date, array $abonat): ?array
    {
        $id = isset($date['queueId']) && (is_int($date['queueId']) || (is_string($date['queueId']) && is_numeric($date['queueId'])))
            ? (int) $date['queueId'] : 0;
        if ($id <= 0) {
            return null;
        }
        $c = self::coadaDupaId($db, $id);
        return $c !== null && self::procesat($db, $c, (int) $abonat['id']) ? $c : null;
    }

    /** @return array<string, mixed>|null */
    public static function coadaDupaId(PDO $db, int $id): ?array
    {
        try {
            $q = $db->prepare('SELECT * FROM newsletter_mailpoet_cozi WHERE coada_id = ?');
            $q->execute([$id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /** Abonatul a fost procesat în coadă? @param array<string, mixed> $coada */
    public static function procesat(PDO $db, array $coada, int $abonatId): bool
    {
        if ($abonatId <= 0) {
            return false;
        }
        if ((string) ($coada['tip'] ?? '') === 'automatizare') {
            return (int) ($coada['abonat_id'] ?? 0) === $abonatId;
        }
        $campanie = (int) ($coada['campanie_id'] ?? 0);
        if ($campanie <= 0) {
            return false;
        }
        try {
            $q = $db->prepare('SELECT 1 FROM newsletter_campaign_sends WHERE campaign_id = ? AND subscriber_id = ? LIMIT 1');
            $q->execute([$campanie, $abonatId]);
            return $q->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Dezabonarea, ca MailPoet (§3.3.3 al specificației): starea `unsubscribed`, fiecare listă `unsubscribed` (afară de lista
     * utilizatorilor WordPress și de cea internă), un rând în `newsletter_dezabonari` (cu coada verificată: același rând pentru aceeași
     * coadă). Cererea de abonare în așteptare (`pending_at`) se șterge: retragerea acordului câștigă. Un abonat deja dezabonat: nimic
     * (nimic notat). Întoarce true dacă s-a schimbat ceva.
     *
     * @param array<string, mixed> $abonat
     * @param array<string, mixed>|null $coada
     */
    public static function dezaboneaza(PDO $db, array $abonat, string $sursa, string $metoda, ?array $coada = null): bool
    {
        $id = (int) ($abonat['id'] ?? 0);
        if ($id <= 0 || (string) ($abonat['status'] ?? '') === 'unsubscribed') {
            return false;
        }
        $faraTranzactie = !$db->inTransaction();
        try {
            if ($faraTranzactie) {
                $db->beginTransaction();
            }
            $q = $db->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', pending_at = NULL WHERE id = ? AND status <> 'unsubscribed'");
            $q->execute([$id]);
            if ($q->rowCount() === 0) {
                if ($faraTranzactie) {
                    $db->rollBack();
                }
                return false;
            }
            $db->prepare("UPDATE newsletter_list_subscribers ls INNER JOIN newsletter_lists l ON l.id = ls.list_id
                SET ls.status = 'unsubscribed', ls.updated_at = NOW()
                WHERE ls.subscriber_id = ? AND ls.status <> 'unsubscribed' AND l.tip <> 'wp_users' AND l.is_default = 0")->execute([$id]);
            self::noteazaDezabonare($db, $id, $sursa, $metoda, $coada);
            if ($faraTranzactie) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($faraTranzactie && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return true;
    }

    /**
     * Rândul dezabonării în `newsletter_dezabonari`: cu o coadă, același rând pentru (abonat, coadă), cu sursa și metoda de acum (ca
     * MailPoet); fără coadă, unul nou.
     *
     * @param array<string, mixed>|null $coada
     */
    public static function noteazaDezabonare(PDO $db, int $abonatId, string $sursa, string $metoda, ?array $coada = null): void
    {
        $sursa = mb_substr($sursa, 0, 20);
        $metoda = in_array($metoda, ['link', 'one_click', 'unknown'], true) ? $metoda : 'unknown';
        if ($coada !== null) {
            $q = $db->prepare('SELECT id FROM newsletter_dezabonari WHERE abonat_id = ? AND coada_mailpoet_id = ? ORDER BY id DESC LIMIT 1');
            $q->execute([$abonatId, (int) $coada['coada_id']]);
            $existent = (int) ($q->fetchColumn() ?: 0);
            if ($existent > 0) {
                $db->prepare('UPDATE newsletter_dezabonari SET sursa = ?, metoda = ? WHERE id = ?')->execute([$sursa, $metoda, $existent]);
                return;
            }
        }
        $db->prepare('INSERT INTO newsletter_dezabonari (abonat_id, campanie_id, email_automatizare_id, coada_mailpoet_id, sursa, metoda, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())')->execute([$abonatId, $coada !== null && (int) ($coada['campanie_id'] ?? 0) > 0 ? (int) $coada['campanie_id'] : null,
            $coada !== null && (int) ($coada['email_automatizare_id'] ?? 0) > 0 ? (int) $coada['email_automatizare_id'] : null,
            $coada !== null ? (int) $coada['coada_id'] : null, $sursa, $metoda]);
    }

    /**
     * Ținta sondajului: dezabonarea din coada verificată, altfel ultima a abonatului.
     *
     * @param array<string, mixed>|null $coada
     * @return array<string, mixed>|null
     */
    public static function tintaMotiv(PDO $db, int $abonatId, ?array $coada): ?array
    {
        try {
            if ($coada !== null) {
                $q = $db->prepare('SELECT * FROM newsletter_dezabonari WHERE abonat_id = ? AND coada_mailpoet_id = ? ORDER BY id DESC LIMIT 1');
                $q->execute([$abonatId, (int) $coada['coada_id']]);
            } else {
                $q = $db->prepare('SELECT * FROM newsletter_dezabonari WHERE abonat_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
                $q->execute([$abonatId]);
            }
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /**
     * Motivul dezabonării (sondajul): doar unul din cele cinci, pe ținta lui; textul liber doar cu `text_liber` pornit și motivul
     * `other`, fără taguri, cu spațiile strânse, cel mult 500 de caractere. Textul rămâne doar în bază.
     *
     * @param array<string, mixed>|null $coada
     */
    public static function salveazaMotiv(PDO $db, int $abonatId, ?array $coada, string $motiv, mixed $text, bool $textLiber): bool
    {
        if (!array_key_exists($motiv, Texte::MOTIVE)) {
            return false;
        }
        $tinta = self::tintaMotiv($db, $abonatId, $coada);
        if ($tinta === null) {
            return false;
        }
        $curat = null;
        if ($textLiber && $motiv === 'other' && is_string($text) && mb_check_encoding($text, 'UTF-8')) {
            $t = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', self::textSimplu($text));
            $curat = $t !== '' ? mb_substr($t, 0, 500) : null;
        }
        $db->prepare('UPDATE newsletter_dezabonari SET motiv = ?, motiv_text = ?, motiv_la = NOW() WHERE id = ?')->execute([$motiv, $curat, (int) $tinta['id']]);
        return true;
    }

    /**
     * Textul liber al sondajului, ca text simplu (regula lui MailPoet pentru acest câmp, descrisă în docs/PLUGINURI.md, „Sondajul de după
     * dezabonare”): întâi dispare fiecare bloc `<script…>…</script>` și `<style…>…</style>` cu tot conținutul lui (numele etichetei fără
     * deosebire între litere mari și mici, oricare atribute, până la prima etichetă de închidere scrisă exact `</script>` sau `</style>`;
     * un bloc neînchis rămâne, iar eticheta lui pleacă la pasul următor), apoi restul etichetelor (strip_tags), apoi fiecare șir de
     * spații, tab-uri și rânduri noi devine un singur spațiu, iar spațiile de la capete pleacă.
     */
    public static function textSimplu(string $text): string
    {
        $mic = strtolower($text);
        $n = strlen($text);
        $fara = '';
        $poz = 0;
        $cautat = 0;
        while ($cautat < $n) {
            $s = strpos($mic, '<script', $cautat);
            $t = strpos($mic, '<style', $cautat);
            if ($s === false && $t === false) {
                break;
            }
            [$i, $eticheta] = $t === false || ($s !== false && $s < $t) ? [$s, 'script'] : [$t, 'style'];
            $deschis = strpos($mic, '>', $i + strlen($eticheta) + 1);
            $inchis = $deschis === false ? false : strpos($mic, '</' . $eticheta . '>', $deschis + 1);
            if ($inchis === false) {
                $cautat = $i + 1;
                continue;
            }
            $fara .= substr($text, $poz, $i - $poz);
            $poz = $cautat = $inchis + strlen($eticheta) + 3;
        }
        $fara = strip_tags($fara . substr($text, $poz));
        $rez = '';
        $spatiu = false;
        for ($k = 0, $m = strlen($fara); $k < $m; $k++) {
            $c = $fara[$k];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $spatiu = true;
                continue;
            }
            if ($spatiu && $rez !== '') {
                $rez .= ' ';
            }
            $spatiu = false;
            $rez .= $c;
        }
        return trim($rez);
    }

    /** Oprirea urmăririi (`tracking_opt_out`): `urmarire_refuzata` = 1, o singură dată. @param array<string, mixed> $abonat */
    public static function opresteUrmarirea(PDO $db, array $abonat): void
    {
        $db->prepare('UPDATE newsletter_subscribers SET urmarire_refuzata = 1 WHERE id = ? AND urmarire_refuzata = 0')->execute([(int) $abonat['id']]);
    }

    /**
     * Listele arătate pe pagina de gestionare: cele cu `afisare_gestionare` = 1, statice, cu id MailPoet, în ordinea numelui (MailPoet).
     *
     * @return list<array{id: int, mailpoet_id: int, name: string}>
     */
    public static function listeVizibile(PDO $db): array
    {
        try {
            $q = $db->query("SELECT id, mailpoet_id, name FROM newsletter_lists WHERE afisare_gestionare = 1 AND tip = 'static' AND mailpoet_id IS NOT NULL
                ORDER BY name ASC, id ASC");
            $r = $q !== false ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            return [];
        }
        return array_map(static fn (array $x): array => ['id' => (int) $x['id'], 'mailpoet_id' => (int) $x['mailpoet_id'], 'name' => (string) $x['name']], $r);
    }

    /**
     * Listele CMS date, fără dubluri, în ordinea id-urilor MailPoet ale lor (crescător; o listă fără id MailPoet la urmă, după id-ul ei).
     * @param list<int> $liste @return list<int>
     */
    private static function ordineMailPoet(PDO $db, array $liste): array
    {
        $liste = array_values(array_unique(array_map('intval', $liste)));
        if (count($liste) < 2) {
            return $liste;
        }
        try {
            $q = $db->prepare('SELECT id, mailpoet_id FROM newsletter_lists WHERE id IN (' . implode(',', array_fill(0, count($liste), '?')) . ')');
            $q->execute($liste);
            $mp = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $mp[(int) $x['id']] = $x['mailpoet_id'] === null ? null : (int) $x['mailpoet_id'];
            }
        } catch (Throwable) {
            return $liste;
        }
        usort($liste, static fn (int $a, int $b): int => [($mp[$a] ?? null) === null ? 1 : 0, $mp[$a] ?? $a, $a] <=> [($mp[$b] ?? null) === null ? 1 : 0, $mp[$b] ?? $b, $b]);
        return $liste;
    }

    /** Starea abonatului pe fiecare listă (id CMS → `subscribed` / `unsubscribed`). @return array<int, string> */
    public static function stariListe(PDO $db, int $abonatId): array
    {
        try {
            $q = $db->prepare('SELECT list_id, status FROM newsletter_list_subscribers WHERE subscriber_id = ?');
            $q->execute([$abonatId]);
            $r = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $r[(int) $x['list_id']] = (string) $x['status'];
            }
            return $r;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Formularul de gestionare trimis (§3.3.4 al specificației). Câmpurile vin sub `data[…]`, cu numele ascunse ale lui MailPoet
     * (setarea `mailpoet_campuri_gestionare`), plus `data[email]` și `token`. Întoarce `succes`, `eroare` sau `nimic` (adresa
     * demonstrativă a previzualizării: fără nicio schimbare, succes, ca MailPoet când rândul ei există).
     *
     *  - o stare din afara lui {subscribed, unsubscribed}, un nume cu `<` sau `>`, id-uri de liste greșite: `eroare`, nimic schimbat;
     *  - numele și starea se salvează; abonat → dezabonat: starea globală și rândul dezabonării cu sursa `manage` (listele rămân, ca la
     *    MailPoet); altă stare → abonat: fără dubla confirmare (ca MailPoet, SubscriberSaveController::createOrUpdate, care nu cheamă
     *    evenimentele listelor la schimbarea stării globale);
     *  - cu starea finală `subscribed`, listele vizibile bifate devin `subscribed`, cele nebifate `unsubscribed`; listele ascunse nu se
     *    ating. Evenimentul abonare.confirmata (`mailpoet_segment_subscribed`) doar pentru listele vizibile bifate al căror rând nu era
     *    `subscribed` (regula pe rând), și la o reabonare globală;
     *  - emailul „Abonat nou la …” către administrator, dacă e pornit (Manage::sendNotificationsForNewSegments): la o reabonare globală
     *    cu toate listele abonate acum (și cele ascunse, fără lista internă a CMS-ului), altfel cu listele vizibile nou bifate.
     *
     * @param array<string, mixed> $abonat abonatul verificat (adresa și jetonul din POST)
     * @param array<mixed> $date `$_POST['data']`
     * @param array<string, string> $campuri numele ascunse (first_name, last_name, status, segments → `form_field_…`)
     */
    public static function actualizeaza(PDO $db, array $abonat, array $date, array $campuri): string
    {
        $ia = static function (string $camp) use ($date, $campuri): mixed {
            $nume = $campuri[$camp] ?? null;
            if (is_string($nume) && array_key_exists($nume, $date)) {
                return $date[$nume];
            }
            return $date[$camp] ?? null;
        };
        $stare = $ia('status');
        $prenume = $ia('first_name');
        $nume = $ia('last_name');
        if ($stare !== null && !in_array($stare, ['subscribed', 'unsubscribed'], true)) {
            return 'eroare';
        }
        foreach ([$prenume, $nume] as $v) {
            if ($v !== null && (!is_string($v) || strpbrk($v, '<>') !== false || !mb_check_encoding($v, 'UTF-8'))) {
                return 'eroare';
            }
        }
        // Listele: întâi câmpul ascuns `data[segments]` (gol), apoi cel cu numele ascuns, care îl înlocuiește (ordinea lui PHP).
        $liste = $date['segments'] ?? null;
        if (isset($campuri['segments']) && array_key_exists($campuri['segments'], $date)) {
            $liste = $date[$campuri['segments']];
        }
        $idListe = [];
        if ($liste !== null && $liste !== '') {
            if (!is_array($liste)) {
                return 'eroare';
            }
            foreach ($liste as $x) {
                if ($x === '') {
                    continue;
                }
                if (!is_string($x) || preg_match('/^[1-9][0-9]{0,9}$/D', $x) !== 1) {
                    return 'eroare';
                }
                $idListe[(int) $x] = true;
            }
        }
        if (strtolower((string) ($abonat['email'] ?? '')) === self::DEMO_EMAIL) {
            return 'nimic';
        }
        $id = (int) $abonat['id'];
        $inainte = (string) $abonat['status'];
        $curata = static fn (?string $v): ?string => $v === null ? null
            : mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/', '', strip_tags($v)))), 0, 255);
        $p = $curata(is_string($prenume) ? $prenume : null);
        $n = $curata(is_string($nume) ? $nume : null);
        $db->beginTransaction();
        try {
            if ($p !== null || $n !== null) {
                $p ??= (string) ($abonat['first_name'] ?? '');
                $n ??= (string) ($abonat['last_name'] ?? '');
                $db->prepare('UPDATE newsletter_subscribers SET first_name = ?, last_name = ?, name = ? WHERE id = ?')
                    ->execute([$p, $n, mb_substr(trim($p . ' ' . $n), 0, 190), $id]);
            }
            $final = $inainte;
            if ($stare === 'unsubscribed' && $inainte !== 'unsubscribed') {
                // Ca MailPoet 5.45.2 (Manage::onSave → SubscriberSaveController::createOrUpdate): doar starea globală; rândurile listelor
                // rămân cum sunt (o reabonare din formular le regăsește), iar dezabonarea (sursa `manage`) se notează doar când abonatul
                // era abonat.
                $db->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', pending_at = NULL WHERE id = ?")->execute([$id]);
                if ($inainte === 'active') {
                    self::noteazaDezabonare($db, $id, 'manage', 'unknown');
                }
                $final = 'unsubscribed';
            } elseif ($stare === 'subscribed' && $inainte !== 'active') {
                $db->prepare("UPDATE newsletter_subscribers SET status = 'active', pending_at = NULL, last_subscribed_at = NOW() WHERE id = ?")->execute([$id]);
                $final = 'active';
            }
            $evenimente = [];
            $notificare = [];
            if ($final === 'active') {
                $vizibile = [];
                foreach (self::listeVizibile($db) as $l) {
                    $vizibile[$l['mailpoet_id']] = $l['id'];
                }
                $alese = array_intersect_key($vizibile, $idListe);
                $schimba = $idListe === [] || $alese !== [];
                $stari = self::stariListe($db, $id);
                if ($schimba) {
                    $leaga = $db->prepare("INSERT INTO newsletter_list_subscribers (list_id, subscriber_id, status, updated_at) VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE updated_at = IF(status <> VALUES(status), NOW(), updated_at), status = VALUES(status)");
                    foreach ($vizibile as $listaId) {
                        $bifata = in_array($listaId, $alese, true);
                        if (!$bifata && ($stari[$listaId] ?? null) === null) {
                            continue;
                        }
                        $leaga->execute([$listaId, $id, $bifata ? 'subscribed' : 'unsubscribed']);
                        if ($bifata && ($stari[$listaId] ?? '') !== 'subscribed') {
                            $evenimente[] = $listaId;
                        }
                    }
                }
                $notificare = $evenimente;
                // Id-uri trimise, dar niciunul al unei liste vizibile: nimic schimbat pe liste și niciun email (Manage::updateSubscriptions
                // iese înaintea lui sendNotificationsForNewSegments).
                if ($inainte !== 'active' && $schimba) {
                    // Reabonarea globală: emailul către administrator numește toate listele abonate acum (și cele ascunse), fără lista
                    // internă a CMS-ului; evenimentele rămân doar ale rândurilor vizibile nou abonate.
                    $implicita = NewsletterService::defaultListId($db);
                    $notificare = array_values(array_filter(array_keys(array_filter(self::stariListe($db, $id), static fn (string $s): bool => $s === 'subscribed')),
                        static fn (int $l): bool => $l !== $implicita));
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('ANDAXI-CMS: MailPoet: formularul de gestionare nu s-a putut salva (' . get_class($e) . ')');
            return 'eroare';
        }
        // Ordinea lui MailPoet: id-urile listelor lui, crescător (findByIds / interogarea listelor vizibile, fără ORDER BY, pe cheia primară).
        $evenimente = self::ordineMailPoet($db, $evenimente);
        $notificare = self::ordineMailPoet($db, $notificare);
        if ($notificare !== []) {
            NewsletterService::notificareAbonatNou($db, $id, $notificare);
        }
        foreach ($evenimente as $listaId) {
            NewsletterService::abonareConfirmata($db, $id, $listaId, 'manage');
        }
        return 'succes';
    }
}
