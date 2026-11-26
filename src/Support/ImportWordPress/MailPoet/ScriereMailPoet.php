<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\MailPoet;

use App\Support\BugetTrimitere;
use App\Support\ImportWordPress\EroareImport;
use App\Support\NewsletterService;
use App\Support\Settings;
use PDO;
use PDOException;

/**
 * Scrierea planului MailPoet (PlanMailPoet) în baza site-ului nou (pista E; migrarea newsletter/0077), în tranzacția importului
 * (Importator::aplica: o eroare anulează tot), ÎNAINTEA documentelor capturate: dovada regiunii `mailpoet:pagina` (cadrul R) are nevoie
 * de liste și de setările MailPoet. Fiecare rând se scrie peste cel de dinainte după cheia lui MailPoet (`mailpoet_id`, apoi adresa
 * abonatului): o a doua aplicare fără nicio schimbare pe site-ul nou dă aceleași rânduri și aceleași numere.
 *
 * Deciziile luate pe site-ul nou nu se scriu peste (E-LEG-03), la nicio aplicare de după: un abonat „păstrat” (pastrati(), regulile
 * comparând rândul site-ului cu datele lui de acum din MailPoet, fără un moment al ultimului import) — abonat direct pe site-ul nou cu
 * aceeași adresă, dezabonat sau retras acolo, cu un acord dat acolo, ori cu rândul lui sau al listelor lui schimbat acolo după ultima
 * schimbare din MailPoet — primește din MailPoet doar legătura cu adresele vechi (`mailpoet_id`, amprentele jetonului, contul
 * WordPress), nu și starea, dovada acordului, numele sau listele. Singura excepție e o retragere din MailPoet mai nouă decât acordul de
 * pe site-ul nou (dezabonat sau sărit, cu `updated_at`-ul MailPoet după ultimul `confirmed_at` / `last_subscribed_at` al CMS-ului; la o
 * listă, rândul MailPoet dezabonat mai nou decât rândul CMS-ului): ea se aplică, fiindcă o retragere nu se pierde niciodată. Protecția
 * greșește doar spre păstrare: o schimbare oarecare a rândului pe site (o deschidere sau un clic urmărit, legarea de un cont de client)
 * păstrează și ea abonatul, care din MailPoet mai primește atunci doar retragerile. Cei păstrați se raportează doar după id (MailPoet și
 * CMS).
 *
 * Lista implicită a CMS-ului („Toți abonații”) nu primește pe nimeni pe un site cu MailPoet (E-LEG-02): fiecare abonare e pe lista ei,
 * cu acordul ei; rândurile puse acolo de un import mai vechi se scot. Nimic personal în ce întoarce: doar numerele și id-urile.
 */
final class ScriereMailPoet
{
    /** Stările CMS ale unei retrageri (planul le ia din MailPoet: unsubscribed, bounced). */
    private const RETRAGERI = ['unsubscribed', 'bounced'];

    /** @return array<string, mixed> numerele (și `pastrati_id`: perechile „id MailPoet→id CMS” ale abonaților păstrați) */
    public static function aplica(PDO $db, PlanMailPoet $p): array
    {
        $rez = ['liste' => 0, 'abonati' => 0, 'abonati_noi' => 0, 'sariti_suprimati' => 0, 'suprimate' => 0, 'apartenente' => 0, 'campanii' => 0,
            'trimiteri' => 0, 'deschideri' => 0, 'clicuri' => 0, 'cozi' => 0, 'linkuri' => 0, 'dezabonari' => 0, 'setari' => 0, 'pastrati' => 0,
            'pastrati_id' => [], 'retrageri_mailpoet' => 0];
        $liste = self::liste($db, $p, $rez);
        $implicit = NewsletterService::defaultListId($db);
        foreach ($p->suprimate as $h) {
            $q = $db->prepare("INSERT IGNORE INTO newsletter_suprimate (email_hash, motiv) VALUES (?, 'mailpoet_cos')");
            $q->execute([$h]);
            $rez['suprimate'] += $q->rowCount();
        }
        $pastrati = self::pastrati($db, $p);
        $abonati = self::abonati($db, $p, $pastrati, $rez);
        // Apartenența: rândurile listelor importate ale fiecărui abonat importat, cu starea lor. Lista implicită a CMS-ului nu primește
        // pe nimeni (E-LEG-02): rândurile ei de la un import mai vechi, ale abonaților importați, se scot.
        $peAbonat = [];
        foreach ($p->apartenente as $a) {
            if (isset($abonati[$a['abonat']], $liste[$a['lista']])) {
                $peAbonat[$abonati[$a['abonat']]][$liste[$a['lista']]] = $a;
            }
        }
        $scoateImplicit = $db->prepare('DELETE FROM newsletter_list_subscribers WHERE list_id = ? AND subscriber_id = ?');
        // Coloanele TIMESTAMP (created_at) primesc clipa MailPoet prin FROM_UNIXTIME (aceeași oricare ar fi fusul sesiunii), cele DATETIME
        // (updated_at) ora locală a site-ului (SursaMailPoet::utc, cu ora de vară a fiecărei valori).
        $scrie = $db->prepare('INSERT INTO newsletter_list_subscribers (list_id, subscriber_id, status, created_at, updated_at) VALUES (?, ?, ?,
            COALESCE(FROM_UNIXTIME(?), NOW()), ?) ON DUPLICATE KEY UPDATE status = VALUES(status), created_at = VALUES(created_at), updated_at = VALUES(updated_at)');
        $randCms = $db->prepare('SELECT status, UNIX_TIMESTAMP(created_at) AS creat, updated_at FROM newsletter_list_subscribers WHERE list_id = ? AND subscriber_id = ?');
        $retrage = $db->prepare("UPDATE newsletter_list_subscribers SET status = 'unsubscribed', updated_at = NOW() WHERE list_id = ? AND subscriber_id = ?
            AND status = 'subscribed'");
        $listeImportate = array_values($liste);
        foreach ($abonati as $mp => $cms) {
            $scoateImplicit->execute([$implicit, $cms]);
            if (isset($pastrati[$mp])) {
                // Un abonat păstrat: listele lui rămân ale site-ului nou; doar o dezabonare MailPoet de pe o listă, mai nouă decât rândul
                // CMS-ului (o retragere), se aplică.
                foreach ($peAbonat[$cms] ?? [] as $listaCms => $a) {
                    if ($a['status'] !== 'unsubscribed' || $a['updated_at'] === null) {
                        continue;
                    }
                    $randCms->execute([$listaCms, $cms]);
                    $r = $randCms->fetch(PDO::FETCH_ASSOC);
                    $laCms = is_array($r) ? max((int) $r['creat'], $r['updated_at'] !== null ? (int) strtotime((string) $r['updated_at']) : 0) : 0;
                    if (is_array($r) && (string) $r['status'] === 'subscribed' && (int) strtotime((string) $a['updated_at']) > $laCms) {
                        $retrage->execute([$listaCms, $cms]);
                        $rez['retrageri_mailpoet'] += $retrage->rowCount();
                    }
                }
                continue;
            }
            $ale = $peAbonat[$cms] ?? [];
            foreach ($ale as $listaCms => $a) {
                $scrie->execute([$listaCms, $cms, $a['status'], $a['created_at'], $a['updated_at']]);
                $rez['apartenente']++;
            }
            // O apartenență care nu mai e în MailPoet (pe o listă importată) nu rămâne.
            $altele = array_values(array_diff($listeImportate, array_keys($ale)));
            if ($altele !== []) {
                $db->prepare('DELETE FROM newsletter_list_subscribers WHERE subscriber_id = ? AND list_id IN (' . implode(',', array_fill(0, count($altele), '?')) . ')')
                    ->execute(array_merge([$cms], $altele));
            }
        }
        $campanii = self::campanii($db, $p, $liste, $abonati, $rez);
        self::cozi($db, $p, $campanii, $abonati, $rez);
        self::dezabonari($db, $p, $campanii, $abonati, $rez);
        $setari = $p->setari;
        if ($p->optin !== null) {
            $setari['mailpoet_optin_checkout'] = (string) json_encode(['activ' => $p->optin['activ'], 'eticheta' => $p->optin['eticheta'],
                'liste' => array_values(array_filter(array_map(static fn (int $l): int => $liste[$l] ?? 0, $p->optin['liste'])))], JSON_UNESCAPED_UNICODE);
        }
        Settings::save($db, $setari);
        $rez['setari'] = count($setari);
        if ($p->buget !== null) {
            BugetTrimitere::configureaza($db, $p->buget['capacitate'], $p->buget['perioada_s']);
        }
        return $rez;
    }

    /** @param array<string, int> $rez @return array<int, int> mailpoet_id → id-ul listei CMS */
    private static function liste(PDO $db, PlanMailPoet $p, array &$rez): array
    {
        $harta = [];
        $dupaMp = $db->prepare('SELECT id FROM newsletter_lists WHERE mailpoet_id = ?');
        $dupaNume = $db->prepare('SELECT id FROM newsletter_lists WHERE name = ?');
        foreach ($p->liste as $mp => $l) {
            $dupaMp->execute([$mp]);
            $id = (int) ($dupaMp->fetchColumn() ?: 0);
            if ($id === 0) {
                $dupaNume->execute([$l['name']]);
                if ($dupaNume->fetchColumn() !== false) {
                    throw new EroareImport('o listă a site-ului are deja numele listei MailPoet ' . $mp . ' (și nu e din import); nimic scris');
                }
                $db->prepare('INSERT INTO newsletter_lists (name, description, is_default, created_at, mailpoet_id, tip, afisare_gestionare) VALUES (?, ?, 0,
                    COALESCE(FROM_UNIXTIME(?), NOW()), ?, ?, ?)')
                    ->execute([$l['name'], $l['description'], $l['created_at'], $mp, $l['tip'], $l['afisare_gestionare']]);
                $id = (int) $db->lastInsertId();
            } else {
                $db->prepare('UPDATE newsletter_lists SET name = ?, description = ?, tip = ?, afisare_gestionare = ?, is_default = 0 WHERE id = ?')
                    ->execute([$l['name'], $l['description'], $l['tip'], $l['afisare_gestionare'], $id]);
            }
            $harta[(int) $mp] = $id;
            $rez['liste']++;
        }
        return $harta;
    }

    /**
     * Abonații planului pe care site-ul nou îi păstrează (docblock-ul clasei, E-LEG-03), după datele lor de acum din MailPoet, nu după
     * momentul unui import anume (deci și la a treia aplicare, și după o schimbare oarecare a rândului MailPoet):
     *  - abonat direct pe site-ul nou, cu aceeași adresă (un rând fără `mailpoet_id`);
     *  - dezabonat pe site-ul nou (un rând în `newsletter_dezabonari` fără `mailpoet_id`);
     *  - retras pe site-ul nou: starea lui e o retragere (dezabonat, sărit), cea din MailPoet nu, iar acordul din MailPoet nu e mai nou
     *    decât ultima schimbare a rândului de pe site;
     *  - un acord dat pe site-ul nou: ultimul `confirmed_at` / `last_subscribed_at` după cel din MailPoet;
     *  - rândul lui schimbat pe site-ul nou după ultima schimbare din MailPoet (`updated_at`: aplicarea scrie exact momentul MailPoet,
     *    orice schimbare de pe site îl mută înainte);
     *  - o listă schimbată pe site-ul nou: un rând al listelor lui importate cu altă stare decât rândul MailPoet (sau fără rând în MailPoet),
     *    scris după rândul MailPoet (după abonatul MailPoet, când rândul lipsește).
     * Doar citește (îl folosesc și planul, cu baza site-ului, și aplicarea).
     *
     * @return array<int, int> mailpoet_id → id-ul abonatului CMS
     */
    public static function pastrati(PDO $db, PlanMailPoet $p): array
    {
        $dupaMp = $db->prepare('SELECT id, status, UNIX_TIMESTAMP(updated_at) AS schimbat, confirmed_at, last_subscribed_at FROM newsletter_subscribers WHERE mailpoet_id = ?');
        $dupaEmail = $db->prepare('SELECT id, mailpoet_id FROM newsletter_subscribers WHERE email = ?');
        $dezabonatPeSite = $db->prepare('SELECT 1 FROM newsletter_dezabonari WHERE abonat_id = ? AND mailpoet_id IS NULL LIMIT 1');
        $listeCms = $db->prepare('SELECT l.mailpoet_id, ls.status, UNIX_TIMESTAMP(ls.created_at) AS creat, ls.updated_at FROM newsletter_list_subscribers ls
            INNER JOIN newsletter_lists l ON l.id = ls.list_id WHERE ls.subscriber_id = ? AND l.mailpoet_id IS NOT NULL');
        $listeMp = [];
        foreach ($p->apartenente as $a) {
            $listeMp[(int) $a['abonat']][(int) $a['lista']] = $a;
        }
        // Datele DATETIME (ora site-ului) ale CMS-ului și ale planului (SursaMailPoet::utc), ca momente.
        $moment = static fn (mixed $data): int => is_string($data) && $data !== '' ? (int) strtotime($data) : 0;
        $rez = [];
        foreach ($p->abonati as $mp => $a) {
            $dupaMp->execute([$mp]);
            $r = $dupaMp->fetch(PDO::FETCH_ASSOC);
            if (!is_array($r)) {
                $dupaEmail->execute([$a['email']]);
                $e = $dupaEmail->fetch(PDO::FETCH_ASSOC);
                if (is_array($e) && $e['mailpoet_id'] === null) {
                    $rez[(int) $mp] = (int) $e['id'];
                }
                continue;
            }
            $id = (int) $r['id'];
            $schimbatCms = (int) $r['schimbat'];
            $acordCms = max($moment($r['confirmed_at']), $moment($r['last_subscribed_at']));
            $acordMp = max($moment($a['confirmed_at']), $moment($a['last_subscribed_at']));
            $schimbatMp = (int) ($a['updated_at'] ?? 0);
            $dezabonatPeSite->execute([$id]);
            $pastrat = $dezabonatPeSite->fetchColumn() !== false
                || (in_array((string) $r['status'], self::RETRAGERI, true) && !in_array($a['status'], self::RETRAGERI, true) && $acordMp <= max($acordCms, $schimbatCms))
                || $acordCms > $acordMp
                || ($schimbatMp > 0 && $schimbatCms > $schimbatMp);
            if (!$pastrat) {
                $listeCms->execute([$id]);
                foreach ($listeCms->fetchAll(PDO::FETCH_ASSOC) as $l) {
                    $rand = $listeMp[(int) $mp][(int) $l['mailpoet_id']] ?? null;
                    if ($rand !== null && $rand['status'] === (string) $l['status']) {
                        continue;
                    }
                    $scrisMp = $rand !== null ? max((int) ($rand['created_at'] ?? 0), $moment($rand['updated_at'])) : (int) ($a['updated_at'] ?? $a['created_at'] ?? 0);
                    if (max((int) $l['creat'], $moment($l['updated_at'])) > $scrisMp) {
                        $pastrat = true;
                        break;
                    }
                }
            }
            if ($pastrat) {
                $rez[(int) $mp] = $id;
            }
        }
        return $rez;
    }

    /**
     * Abonații a căror adresă din MailPoet e deja a altui abonat al site-ului (cheia unică `email`): textul NU-ului, doar cu id-uri
     * (E-LEG-06). Doar citește.
     *
     * @return list<string>
     */
    public static function coliziuni(PDO $db, PlanMailPoet $p): array
    {
        $pastrati = self::pastrati($db, $p);
        $dupaMp = $db->prepare('SELECT id FROM newsletter_subscribers WHERE mailpoet_id = ?');
        $dupaEmail = $db->prepare('SELECT id, mailpoet_id FROM newsletter_subscribers WHERE email = ?');
        $rez = [];
        foreach ($p->abonati as $mp => $a) {
            if (isset($pastrati[$mp])) {
                continue; // adresa lui nu se scrie
            }
            $dupaMp->execute([$mp]);
            $id = (int) ($dupaMp->fetchColumn() ?: 0);
            $dupaEmail->execute([$a['email']]);
            $e = $dupaEmail->fetch(PDO::FETCH_ASSOC);
            if (is_array($e) && $id > 0 && (int) $e['id'] !== $id) {
                $rez[] = 'abonatul MailPoet ' . (int) $mp . ' (abonatul ' . $id . ' al site-ului) are în MailPoet adresa abonatului ' . (int) $e['id'] . ' al site-ului';
            } elseif (is_array($e) && $id === 0 && $e['mailpoet_id'] !== null && (int) $e['mailpoet_id'] !== (int) $mp) {
                $rez[] = 'adresa abonatului MailPoet ' . (int) $mp . ' e deja a abonatului ' . (int) $e['id'] . ' al site-ului (abonatul MailPoet ' . (int) $e['mailpoet_id'] . ')';
            }
        }
        return $rez;
    }

    /**
     * @param array<int, int> $pastrati
     * @param array<string, mixed> $rez
     * @return array<int, int> mailpoet_id → id-ul abonatului CMS
     */
    private static function abonati(PDO $db, PlanMailPoet $p, array $pastrati, array &$rez): array
    {
        $harta = [];
        $coloane = ['email', 'name', 'first_name', 'last_name', 'status', 'subscribed_ip', 'confirmed_ip', 'confirmed_at', 'last_subscribed_at', 'pending_at', 'source',
            'wp_user_id', 'is_woocommerce_user', 'mailpoet_token_hash', 'mailpoet_token_len', 'mailpoet_token6_hash', 'urmarire_refuzata', 'last_engagement_at',
            'last_sending_at', 'email_count'];
        // Ale unui abonat păstrat: doar legătura cu adresele vechi (id-ul MailPoet, amprentele jetonului) și cu contul WordPress.
        $tehnice = ['wp_user_id', 'is_woocommerce_user', 'mailpoet_token_hash', 'mailpoet_token_len', 'mailpoet_token6_hash'];
        $dupaMp = $db->prepare('SELECT id FROM newsletter_subscribers WHERE mailpoet_id = ?');
        $dupaEmail = $db->prepare('SELECT id, mailpoet_id FROM newsletter_subscribers WHERE email = ?');
        $suprimat = $db->prepare("SELECT 1 FROM newsletter_suprimate WHERE email_hash = ? AND motiv <> 'mailpoet_cos'");
        $actualizeaza = $db->prepare('UPDATE newsletter_subscribers SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $coloane))
            . ', mailpoet_id = ?, created_at = COALESCE(FROM_UNIXTIME(?), created_at), updated_at = COALESCE(FROM_UNIXTIME(?), updated_at) WHERE id = ?');
        $legatura = $db->prepare('UPDATE newsletter_subscribers SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $tehnice))
            . ', mailpoet_id = ? WHERE id = ?');
        $acord = $db->prepare('SELECT status, confirmed_at, last_subscribed_at FROM newsletter_subscribers WHERE id = ?');
        $retrage = $db->prepare("UPDATE newsletter_subscribers SET status = ?, pending_at = NULL WHERE id = ? AND status IN ('active', 'pending', 'inactive')");
        $adauga = $db->prepare('INSERT INTO newsletter_subscribers (' . implode(', ', $coloane) . ', mailpoet_id, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($coloane), '?')) . ', ?, COALESCE(FROM_UNIXTIME(?), NOW()), COALESCE(FROM_UNIXTIME(?), NOW()))');
        foreach ($p->abonati as $mp => $a) {
            $suprimat->execute([NewsletterService::amprentaAdresa($a['email'])]);
            if ($suprimat->fetchColumn() !== false) {
                $rez['sariti_suprimati']++; // o adresă ștearsă la cererea omului pe site-ul nou nu revine din import
                continue;
            }
            if (isset($pastrati[$mp])) {
                $id = $pastrati[$mp];
                self::scrie($legatura, array_merge(array_map(static fn (string $c): mixed => $a[$c], $tehnice), [$mp, $id]), (int) $mp, $id);
                // O retragere din MailPoet mai nouă decât acordul de pe site-ul nou se aplică (o retragere nu se pierde).
                $acord->execute([$id]);
                $c = $acord->fetch(PDO::FETCH_ASSOC);
                $acordCms = is_array($c) ? max((int) strtotime((string) ($c['confirmed_at'] ?? '')), (int) strtotime((string) ($c['last_subscribed_at'] ?? ''))) : 0;
                if (in_array($a['status'], self::RETRAGERI, true) && $a['updated_at'] !== null && (int) $a['updated_at'] > $acordCms) {
                    $retrage->execute([$a['status'], $id]);
                    $rez['retrageri_mailpoet'] += $retrage->rowCount();
                }
                $harta[(int) $mp] = $id;
                $rez['abonati']++;
                $rez['pastrati']++;
                $rez['pastrati_id'][] = (int) $mp . '→' . $id;
                continue;
            }
            $valori = array_map(static fn (string $c): mixed => $a[$c], $coloane);
            $dupaMp->execute([$mp]);
            $id = (int) ($dupaMp->fetchColumn() ?: 0);
            if ($id === 0) {
                $dupaEmail->execute([$a['email']]);
                $r = $dupaEmail->fetch(PDO::FETCH_ASSOC);
                if (is_array($r) && $r['mailpoet_id'] !== null && (int) $r['mailpoet_id'] !== (int) $mp) {
                    throw new EroareImport('adresa abonatului MailPoet ' . $mp . ' e deja a abonatului ' . (int) $r['id'] . ' al site-ului (alt abonat MailPoet); nimic scris');
                }
                $id = is_array($r) ? (int) $r['id'] : 0;
            }
            if ($id > 0) {
                self::scrie($actualizeaza, array_merge($valori, [$mp, $a['created_at'], $a['updated_at'], $id]), (int) $mp, $id);
            } else {
                self::scrie($adauga, array_merge($valori, [$mp, $a['created_at'], $a['updated_at']]), (int) $mp, 0);
                $id = (int) $db->lastInsertId();
                $rez['abonati_noi']++;
            }
            $harta[(int) $mp] = $id;
            $rez['abonati']++;
        }
        return $harta;
    }

    /**
     * Un rând al abonatului; o cheie unică încălcată (SQLSTATE 23000: adresa e deja a altui abonat) devine NU cu id-urile, fără adresa
     * din mesajul serverului (E-LEG-06).
     *
     * @param list<mixed> $valori
     */
    private static function scrie(\PDOStatement $q, array $valori, int $mp, int $id): void
    {
        try {
            $q->execute($valori);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new EroareImport('abonatul MailPoet ' . $mp . ($id > 0 ? ' (abonatul ' . $id . ' al site-ului)' : '') . ' nu se poate scrie: adresa lui e deja a '
                    . 'altui abonat al site-ului (cheia unică a adresei); nimic scris');
            }
            throw $e;
        }
    }

    /**
     * @param array<int, int> $liste
     * @param array<int, int> $abonati
     * @param array<string, int> $rez
     * @return array<int, int> mailpoet_id → id-ul campaniei CMS
     */
    private static function campanii(PDO $db, PlanMailPoet $p, array $liste, array $abonati, array &$rez): array
    {
        $harta = [];
        $dupaMp = $db->prepare('SELECT id FROM newsletter_campaigns WHERE mailpoet_id = ?');
        $coloane = ['name', 'template_type', 'subscriber_list_id', 'subscriber_list_ids', 'subject', 'blocks_json', 'html_content', 'text_content', 'status', 'sent_at',
            'total_recipients', 'total_sent', 'total_failed', 'total_opens', 'total_clicks', 'sursa', 'mailpoet_hash', 'mailpoet_partajare'];
        $trimitere = $db->prepare('INSERT INTO newsletter_campaign_sends (campaign_id, subscriber_id, email, status, error_message, sent_at, opened_at, open_count,
            clicked_at, click_count, attempts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE email = VALUES(email), status = VALUES(status),
            error_message = VALUES(error_message), sent_at = VALUES(sent_at), opened_at = VALUES(opened_at), open_count = GREATEST(open_count, VALUES(open_count)),
            clicked_at = VALUES(clicked_at), click_count = GREATEST(click_count, VALUES(click_count)), attempts = GREATEST(attempts, 1)');
        $idTrimitere = $db->prepare('SELECT id FROM newsletter_campaign_sends WHERE campaign_id = ? AND subscriber_id = ?');
        $emailAbonat = $db->prepare('SELECT email FROM newsletter_subscribers WHERE id = ?');
        $areDeschidere = $db->prepare('SELECT 1 FROM newsletter_campaign_opens WHERE campaign_id = ? AND send_id = ? AND opened_at = ?');
        $deschidere = $db->prepare('INSERT INTO newsletter_campaign_opens (campaign_id, send_id, opened_at, ip) VALUES (?, ?, ?, NULL)');
        $areClic = $db->prepare('SELECT 1 FROM newsletter_campaign_clicks WHERE campaign_id = ? AND subscriber_id = ? AND url = ? AND clicked_at = ?');
        $clic = $db->prepare('INSERT INTO newsletter_campaign_clicks (campaign_id, subscriber_id, email, url, clicked_at, ip) VALUES (?, ?, ?, ?, ?, NULL)');
        foreach ($p->campanii as $mp => $c) {
            $idsListe = array_values(array_filter(array_map(static fn (int $l): int => $liste[$l] ?? 0, $c['liste'])));
            $valori = [$c['name'], 'mailpoet', $idsListe[0] ?? null, (string) json_encode($idsListe), $c['subject'], null, $c['html'], $c['text'], 'sent', $c['sent_at'],
                $c['total_recipients'], $c['total_sent'], $c['total_failed'], $c['total_opens'], $c['total_clicks'], 'mailpoet', $c['mailpoet_hash'], $c['partajare']];
            $dupaMp->execute([$mp]);
            $id = (int) ($dupaMp->fetchColumn() ?: 0);
            if ($id > 0) {
                $db->prepare('UPDATE newsletter_campaigns SET ' . implode(', ', array_map(static fn (string $k): string => $k . ' = ?', $coloane)) . ' WHERE id = ?')
                    ->execute(array_merge($valori, [$id]));
            } else {
                $db->prepare('INSERT INTO newsletter_campaigns (' . implode(', ', $coloane) . ', mailpoet_id, created_at) VALUES (' . implode(', ', array_fill(0, count($coloane), '?'))
                    . ', ?, COALESCE(FROM_UNIXTIME(?), NOW()))')->execute(array_merge($valori, [$mp, $c['creat_la']]));
                $id = (int) $db->lastInsertId();
            }
            $harta[(int) $mp] = $id;
            $rez['campanii']++;
            foreach ($c['trimiteri'] as $t) {
                $ab = $abonati[$t['abonat']] ?? null;
                if ($ab === null) {
                    continue;
                }
                $emailAbonat->execute([$ab]);
                $email = (string) $emailAbonat->fetchColumn();
                $deschideri = $t['deschideri'];
                $trimitere->execute([$id, $ab, $email, $t['status'], $t['error_message'], $t['sent_at'], $deschideri !== [] ? min($deschideri) : null, count($deschideri),
                    $t['clicked_at'], $t['click_count']]);
                $idTrimitere->execute([$id, $ab]);
                $sid = (int) $idTrimitere->fetchColumn();
                $rez['trimiteri']++;
                foreach ($deschideri as $la) {
                    $areDeschidere->execute([$id, $sid, $la]);
                    if ($areDeschidere->fetchColumn() === false) {
                        $deschidere->execute([$id, $sid, $la]);
                    }
                    $rez['deschideri']++;
                }
                foreach ($t['clicuri'] as $k) {
                    $url = mb_substr($k['url'], 0, 2048);
                    $la = $k['la'] ?? $t['sent_at'];
                    if ($la === null) {
                        continue;
                    }
                    $areClic->execute([$id, $ab, $url, $la]);
                    if ($areClic->fetchColumn() === false) {
                        $clic->execute([$id, $ab, $email, $url, $la]);
                    }
                    $rez['clicuri']++;
                }
            }
        }
        return $harta;
    }

    /**
     * @param array<int, int> $campanii
     * @param array<int, int> $abonati
     * @param array<string, int> $rez
     */
    private static function cozi(PDO $db, PlanMailPoet $p, array $campanii, array $abonati, array &$rez): void
    {
        // email_automatizare_id rămâne cum l-a pus pista J (legătura cu emailul ei importat), la orice reimport.
        $coada = $db->prepare('INSERT INTO newsletter_mailpoet_cozi (coada_id, task_id, newsletter_mailpoet_id, tip, campanie_id, abonat_id, trimis_la) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE task_id = VALUES(task_id), newsletter_mailpoet_id = VALUES(newsletter_mailpoet_id), tip = VALUES(tip), campanie_id = VALUES(campanie_id),
            abonat_id = VALUES(abonat_id), trimis_la = VALUES(trimis_la)');
        foreach ($p->cozi as $id => $q) {
            $coada->execute([$id, $q['task_id'], $q['newsletter_mailpoet_id'], $q['tip'], $q['campanie'] !== null ? ($campanii[$q['campanie']] ?? null) : null,
                $q['abonat'] !== null ? ($abonati[$q['abonat']] ?? null) : null, $q['trimis_la']]);
            $rez['cozi']++;
        }
        $link = $db->prepare('INSERT INTO newsletter_mailpoet_linkuri (coada_id, hash, url) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE url = VALUES(url)');
        foreach ($p->linkuri as $l) {
            $link->execute([$l['coada'], $l['hash'], $l['url']]);
            $rez['linkuri']++;
        }
    }

    /**
     * @param array<int, int> $campanii
     * @param array<int, int> $abonati
     * @param array<string, int> $rez
     */
    private static function dezabonari(PDO $db, PlanMailPoet $p, array $campanii, array $abonati, array &$rez): void
    {
        // După id-ul rândului MailPoet (cheia unică `mailpoet_id`): a doua aplicare rescrie același rând, nu-l dublează (și când MailPoet
        // n-are momentul dezabonării: atunci rămâne cel scris prima dată).
        $scrie = $db->prepare('INSERT INTO newsletter_dezabonari (mailpoet_id, abonat_id, campanie_id, coada_mailpoet_id, sursa, metoda, motiv, motiv_text, motiv_la,
            created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW())) ON DUPLICATE KEY UPDATE abonat_id = VALUES(abonat_id), campanie_id = VALUES(campanie_id),
            coada_mailpoet_id = VALUES(coada_mailpoet_id), sursa = VALUES(sursa), metoda = VALUES(metoda), motiv = VALUES(motiv), motiv_text = VALUES(motiv_text),
            motiv_la = VALUES(motiv_la), created_at = COALESCE(?, created_at)');
        foreach ($p->dezabonari as $d) {
            $ab = $abonati[$d['abonat']] ?? null;
            if ($ab === null || $d['mailpoet_id'] <= 0) {
                continue;
            }
            $scrie->execute([$d['mailpoet_id'], $ab, $d['campanie'] !== null ? ($campanii[$d['campanie']] ?? null) : null, $d['coada'], $d['sursa'], $d['metoda'],
                $d['motiv'], $d['motiv_text'], $d['motiv_la'], $d['created_at'], $d['created_at']]);
            $rez['dezabonari']++;
        }
    }
}
