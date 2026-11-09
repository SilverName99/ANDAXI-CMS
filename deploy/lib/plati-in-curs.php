<?php

declare(strict_types=1);

/**
 * deploy/plati-in-curs.sh: ce e „pe drum” în baza unui site, înainte și după comutare (docs/DEPLOY.md, „Comutarea
 * Bioscem”): plățile Banca Transilvania începute sau autorizate și neîncasate, linkurile de plată neplătite, comenzile
 * încă netrimise în ERP. Doar numere și ore (nicio dată a unui client, niciun număr de comandă, nicio sumă): textul se
 * poate trimite așa cum e. Doar citește (o tranzacție READ ONLY), deci merge oricând, și cu site-ul deschis.
 *
 *   deploy/plati-in-curs.sh --domeniu=bioscem.ro
 *
 * Baza o ia din .env-ul codului care servește acum site-ul: public_html/.env (codul vechi, înainte de comutare), altfel
 * site/.env (după comutare). Un tabel sau o coloană care lipsește se spune pe rândul ei („lipsește”), fără oprire.
 * Alte opțiuni: --acasa=<dosar> (teste). Cod de ieșire 0; 1 dacă baza nu răspunde; 2 la o opțiune greșită.
 */

namespace Platforma\Deploy;

use PDO;
use Throwable;

require __DIR__ . '/deploy.php';

exit(porneste(static function () use ($argv): int {
    $o = Optiuni::citeste($argv, ['domeniu=', 'acasa=']);
    $s = new Site(Site::acasa($o), $o->cere('domeniu', '<domeniu>'));
    $env = is_file($s->docroot . '/.env') ? $s->docroot . '/.env' : $s->site . '/.env';
    if (!is_file($env)) {
        throw new Oprire('nu găsesc .env-ul site-ului (nici ' . $s->docroot . '/.env, nici ' . $s->site . '/.env)', 2);
    }
    $baza = new Baza(Env::citeste($env));
    $pdo = $baza->pdo();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    Iesire::titlu('Ce e pe drum în baza lui ' . $s->domeniu . ' (' . date('Y-m-d H:i:s') . ', ' . ($env === $s->docroot . '/.env' ? 'codul vechi' : 'codul comun') . ')');

    // Un rând: eticheta și rezultatul interogării (un număr sau o listă „cheie n”); un tabel lipsă nu oprește nimic.
    $rand = static function (string $eticheta, string $sql) use ($pdo): void {
        try {
            $randuri = $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
            if (count($randuri) === 1 && count($randuri[0]) === 1) {
                $text = (string) ($randuri[0][0] ?? '—');
            } else {
                $text = implode(', ', array_map(static fn (array $r): string => implode(' ', array_map('strval', $r)), $randuri)) ?: '—';
            }
        } catch (Throwable) {
            $text = 'lipsește (tabelul sau coloana nu există)';
        }
        Iesire::scrie(mb_str_pad($eticheta, 56) . ': ' . $text);
    };

    Iesire::scrie('Banca Transilvania (bt_ipay_transactions):');
    $rand('  pe mod și stare (mod stare număr)', 'SELECT mode, state, COUNT(*) FROM bt_ipay_transactions GROUP BY mode, state ORDER BY mode, state');
    $rand('  live: autorizate, neîncasate', "SELECT COUNT(*) FROM bt_ipay_transactions WHERE mode = 'live' AND state = 'authorized'");
    $rand('  live: cea mai veche autorizare neîncasată (ore)',
        "SELECT COALESCE(TIMESTAMPDIFF(HOUR, MIN(COALESCE(authorized_at, created_at)), NOW()), '—') FROM bt_ipay_transactions WHERE mode = 'live' AND state = 'authorized'");
    $rand('  live: autorizate cu reamintirea de 72 h trimisă', "SELECT COUNT(*) FROM bt_ipay_transactions WHERE mode = 'live' AND state = 'authorized' AND reminder_sent_at IS NOT NULL");
    $rand('  live: încasare cerută și neterminată', "SELECT COUNT(*) FROM bt_ipay_transactions WHERE mode = 'live' AND deposit_requested_at IS NOT NULL AND state = 'authorized'");
    $rand('  live: începute și neterminate (înregistrate)', "SELECT COUNT(*) FROM bt_ipay_transactions WHERE mode = 'live' AND state IN ('registered', 'pending_3ds')");
    $rand('  live: începute în ultimele 2 ore', "SELECT COUNT(*) FROM bt_ipay_transactions WHERE mode = 'live' AND created_at >= NOW() - INTERVAL 2 HOUR");
    $rand('  ultima verificare la bancă (minute în urmă)', "SELECT COALESCE(TIMESTAMPDIFF(MINUTE, MAX(last_checked_at), NOW()), '—') FROM bt_ipay_transactions");
    $rand('  cronul BT, ultima rulare încheiată (minute în urmă)',
        "SELECT COALESCE(TIMESTAMPDIFF(MINUTE, MAX(`value`), NOW()), '—') FROM settings WHERE `key` = 'bt_ipay_cron_heartbeat'");
    Iesire::scrie('Linkurile de plată (order_payment_links):');
    $rand('  pe procesator și stare (procesator stare număr)', 'SELECT procesator, status, COUNT(*) FROM order_payment_links GROUP BY procesator, status ORDER BY procesator, status');
    $rand('  neplătite și încă valabile', "SELECT COUNT(*) FROM order_payment_links WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())");
    Iesire::scrie('ERP (orders.erp_status), comenzile din ultimele 30 de zile:');
    $rand('  pe stare (stare număr)', 'SELECT erp_status, COUNT(*) FROM orders WHERE deleted_at IS NULL AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY erp_status ORDER BY erp_status');
    Iesire::scrie('Comenzile:');
    $rand('  în ultima oră', 'SELECT COUNT(*) FROM orders WHERE created_at >= NOW() - INTERVAL 1 HOUR');
    $rand('  ultima comandă (minute în urmă)', 'SELECT COALESCE(TIMESTAMPDIFF(MINUTE, MAX(created_at), NOW()), \'—\') FROM orders');
    $pdo->exec('COMMIT');
    Iesire::scrie('');
    Iesire::scrie('Gata (doar citit). Ne trimiți tot textul.');
    return 0;
}));
