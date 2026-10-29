<?php

declare(strict_types=1);

/**
 * Diagnostic pentru trimiterile de newsletter.
 *
 * Răspunde la întrebarea „de ce campania asta a plecat la mai puțini abonați
 * decât mă așteptam?" fără să ghicească: reface exact selecția pe care o face
 * trimiterea (aceleași liste, același filtru de status) și arată unde se pierd
 * destinatarii.
 *
 * Folosire (pe server, din rădăcina codului; unealtă, nu se instalează pe site-uri):
 *   php tools/newsletter/diagnostic-campanie.php                 -> lista campaniilor
 *   php tools/newsletter/diagnostic-campanie.php 12              -> analiza campaniei 12
 *   php tools/newsletter/diagnostic-campanie.php 12 --fata-de=7  -> câți au primit campania 7,
 *                                                                   dar nu și campania 12
 *
 * WP-21 (C11b, din Biovitality 95f904f, `scripts/newsletter-debug.php`): doar numere, fără adrese. Varianta BV avea și
 * `--emailuri`, care tipărea adresele abonaților săriți; aici nu există (datele personale nu au ce căuta în terminal
 * și în istoricul lui). Adresele concrete se văd în admin, în statisticile campaniei.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../bootstrap.php';

use App\Support\Database;

$config = require __DIR__ . '/../../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibila.\n");
    exit(1);
}

$campaignId = 0;
$referinta = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--fata-de=')) {
        $referinta = (int) substr($arg, 10);
        continue;
    }
    if (ctype_digit($arg)) {
        $campaignId = (int) $arg;
    }
}

function linie(string $text = ''): void
{
    fwrite(STDOUT, $text . "\n");
}

function titlu(string $text): void
{
    linie('');
    linie($text);
    linie(str_repeat('-', max(8, mb_strlen($text))));
}

/** Listele campaniei, exact cum le citește NewsletterService::sendCampaignNow(). */
function listeCampanie(array $campanie): array
{
    $brut = trim((string) ($campanie['subscriber_list_ids'] ?? ''));
    $ids = [];
    if ($brut !== '') {
        $decodat = json_decode($brut, true);
        if (is_array($decodat)) {
            $ids = array_map('intval', $decodat);
        }
    }
    $ids = array_values(array_filter($ids, static fn ($v) => $v > 0));
    if ($ids === []) {
        $single = (int) ($campanie['subscriber_list_id'] ?? 0);
        if ($single > 0) {
            $ids = [$single];
        }
    }

    return $ids;
}

if ($campaignId <= 0) {
    titlu('Campanii');
    $randuri = $db->query(
        'SELECT id, name, status, scheduled_at, sent_at, total_recipients, total_sent, total_failed
         FROM newsletter_campaigns
         ORDER BY id DESC
         LIMIT 40'
    )->fetchAll();
    foreach ($randuri as $r) {
        linie(sprintf(
            '#%-4d %-45s %-10s trimise=%-6d esuate=%-5d destinatari=%-6d %s',
            (int) $r['id'],
            mb_substr((string) $r['name'], 0, 45),
            (string) $r['status'],
            (int) $r['total_sent'],
            (int) $r['total_failed'],
            (int) $r['total_recipients'],
            (string) ($r['sent_at'] ?? $r['scheduled_at'] ?? '')
        ));
    }
    linie('');
    linie('Ruleaza din nou cu ID-ul campaniei: php scripts/newsletter-debug.php <id>');
    exit(0);
}

$stmt = $db->prepare('SELECT * FROM newsletter_campaigns WHERE id = :id');
$stmt->execute(['id' => $campaignId]);
$campanie = $stmt->fetch();
if (!is_array($campanie)) {
    fwrite(STDERR, "Campania {$campaignId} nu exista.\n");
    exit(1);
}

$listIds = listeCampanie($campanie);

titlu('Campania #' . $campaignId . ' — ' . (string) $campanie['name']);
linie('Status              : ' . (string) $campanie['status']);
linie('Programata la       : ' . (string) ($campanie['scheduled_at'] ?? '-'));
linie('Prima trimitere     : ' . (string) ($campanie['sent_at'] ?? '-'));
linie('Salvat: destinatari : ' . (int) $campanie['total_recipients']);
linie('Salvat: trimise     : ' . (int) $campanie['total_sent']);
linie('Salvat: esuate      : ' . (int) $campanie['total_failed']);

if ($listIds === []) {
    linie('');
    linie('ATENTIE: campania nu are nicio lista selectata.');
    exit(0);
}

$ph = implode(',', array_fill(0, count($listIds), '?'));

titlu('Listele selectate (' . count($listIds) . ')');
$stmt = $db->prepare(
    "SELECT l.id, l.name,
            COUNT(ls.subscriber_id) AS membri,
            SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) AS activi
     FROM newsletter_lists l
     LEFT JOIN newsletter_list_subscribers ls ON ls.list_id = l.id
     LEFT JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
     WHERE l.id IN ($ph)
     GROUP BY l.id, l.name
     ORDER BY l.id ASC"
);
$stmt->execute($listIds);
$sumaMembri = 0;
foreach ($stmt->fetchAll() as $r) {
    $sumaMembri += (int) $r['membri'];
    linie(sprintf(
        '#%-4d %-40s membri=%-6d activi=%-6d inactivi=%d',
        (int) $r['id'],
        mb_substr((string) $r['name'], 0, 40),
        (int) $r['membri'],
        (int) $r['activi'],
        (int) $r['membri'] - (int) $r['activi']
    ));
}
linie(sprintf('SUMA membrilor (cu dublurile intre liste): %d', $sumaMembri));

titlu('Grupul unic de destinatari (dupa eliminarea dublurilor)');
$stmt = $db->prepare(
    "SELECT COUNT(DISTINCT s.id) FROM newsletter_list_subscribers ls
     INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
     WHERE ls.list_id IN ($ph)"
);
$stmt->execute($listIds);
$unici = (int) $stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT s.status, COUNT(DISTINCT s.id) AS nr
     FROM newsletter_list_subscribers ls
     INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
     WHERE ls.list_id IN ($ph)
     GROUP BY s.status
     ORDER BY nr DESC"
);
$stmt->execute($listIds);
$peStatus = $stmt->fetchAll();

linie('Abonati unici in listele campaniei : ' . $unici);
foreach ($peStatus as $r) {
    linie(sprintf('  - status "%s": %d', (string) $r['status'], (int) $r['nr']));
}
linie('');
linie('Trimiterea foloseste DOAR abonatii cu status "active".');

titlu('Ce s-a inregistrat efectiv la trimitere');
$stmt = $db->prepare(
    'SELECT status, COUNT(*) AS nr, MIN(sent_at) AS prima, MAX(sent_at) AS ultima
     FROM newsletter_campaign_sends
     WHERE campaign_id = :id
     GROUP BY status'
);
$stmt->execute(['id' => $campaignId]);
foreach ($stmt->fetchAll() as $r) {
    linie(sprintf(
        '%-8s %-6d intre %s si %s',
        (string) $r['status'],
        (int) $r['nr'],
        (string) ($r['prima'] ?? '-'),
        (string) ($r['ultima'] ?? '-')
    ));
}
$stmt = $db->prepare(
    'SELECT COUNT(*) FROM newsletter_campaign_sends WHERE campaign_id = :id AND subscriber_id IS NULL'
);
$stmt->execute(['id' => $campaignId]);
$teste = (int) $stmt->fetchColumn();
if ($teste > 0) {
    linie('Din care emailuri de test (fara abonat): ' . $teste);
}

titlu('Cine era eligibil si nu a primit (in acest moment)');
$stmt = $db->prepare(
    "SELECT COUNT(DISTINCT s.id)
     FROM newsletter_list_subscribers ls
     INNER JOIN newsletter_subscribers s ON s.id = ls.subscriber_id
     LEFT JOIN newsletter_campaign_sends cs
            ON cs.campaign_id = ? AND cs.subscriber_id = s.id AND cs.status = 'sent'
     WHERE ls.list_id IN ($ph) AND s.status = 'active' AND cs.subscriber_id IS NULL"
);
$stmt->execute(array_merge([$campaignId], $listIds));
$ramasi = (int) $stmt->fetchColumn();
linie('Activi din liste care NU au o trimitere reusita: ' . $ramasi);
if ($ramasi > 0) {
    linie('(daca numarul e > 0, campania nu e de fapt terminata)');
}

if ($referinta > 0) {
    titlu('Comparatie cu campania #' . $referinta);
    $stmt = $db->prepare(
        'SELECT s.status, COUNT(*) AS nr
         FROM newsletter_campaign_sends a
         INNER JOIN newsletter_subscribers s ON s.id = a.subscriber_id
         LEFT JOIN newsletter_campaign_sends b
                ON b.campaign_id = :noua AND b.subscriber_id = a.subscriber_id AND b.status = "sent"
         WHERE a.campaign_id = :veche AND a.status = "sent" AND a.subscriber_id IS NOT NULL
           AND b.subscriber_id IS NULL
         GROUP BY s.status
         ORDER BY nr DESC'
    );
    $stmt->execute(['noua' => $campaignId, 'veche' => $referinta]);
    $randuri = $stmt->fetchAll();
    $total = 0;
    foreach ($randuri as $r) {
        $total += (int) $r['nr'];
    }
    linie('Au primit #' . $referinta . ' dar NU si #' . $campaignId . ': ' . $total);
    foreach ($randuri as $r) {
        linie(sprintf('  - au acum status "%s": %d', (string) $r['status'], (int) $r['nr']));
    }

    // Cei care sunt inca activi si totusi au fost sariti: ori nu mai sunt in
    // listele campaniei noi, ori campania s-a oprit inainte sa ajunga la ei.
    $stmt = $db->prepare(
        "SELECT COUNT(DISTINCT a.subscriber_id)
         FROM newsletter_campaign_sends a
         INNER JOIN newsletter_subscribers s ON s.id = a.subscriber_id
         LEFT JOIN newsletter_campaign_sends b
                ON b.campaign_id = ? AND b.subscriber_id = a.subscriber_id AND b.status = 'sent'
         LEFT JOIN newsletter_list_subscribers ls
                ON ls.subscriber_id = a.subscriber_id AND ls.list_id IN ($ph)
         WHERE a.campaign_id = ? AND a.status = 'sent' AND a.subscriber_id IS NOT NULL
           AND b.subscriber_id IS NULL
           AND s.status = 'active'
           AND ls.subscriber_id IS NULL"
    );
    $stmt->execute(array_merge([$campaignId], $listIds, [$referinta]));
    linie('Din care activi, dar care NU mai sunt in listele campaniei noi: ' . (int) $stmt->fetchColumn());
}

titlu('Dezabonari pe zile (ultimele 60 de zile)');
$randuri = $db->query(
    "SELECT DATE(updated_at) AS zi, COUNT(*) AS nr
     FROM newsletter_subscribers
     WHERE status <> 'active' AND updated_at >= (NOW() - INTERVAL 60 DAY)
     GROUP BY DATE(updated_at)
     ORDER BY zi ASC"
)->fetchAll();
if ($randuri === []) {
    linie('(nicio dezabonare in ultimele 60 de zile)');
}
foreach ($randuri as $r) {
    linie(sprintf('%s  %d', (string) $r['zi'], (int) $r['nr']));
}

linie('');
