<?php

declare(strict_types=1);

/**
 * Setul vertij-tinitus (WP-10; întrebarea O18 a portului vertij din planul Etapei 1): are VT acorduri GDPR semnate
 * (numărul e în cele comune), donații, membri activi, verificări de cont? Doar numere: donațiile
 * pe stare și frecvență, membrii cu abonament activ și conturile pe stare, verificările și
 * activările pe stare, paginile și articolele doar pentru membri, câte fișiere sunt în dosarul
 * documentelor (fără să se deschidă vreunul). Răspunsul la O18 vine înaintea licenței VT.
 */

use Unelte\Verificare\Context;

$comun = require __DIR__ . '/comun.php';

return [
    'site' => 'vertij',
    'verificari' => array_merge($comun['grupuri'], [
        [
            'cheie' => 'vt_donatii',
            'titlu' => 'Donațiile (donatii): pe stare și frecvență',
            'ruleaza' => static function (Context $c): void {
                if (!$c->cere('vt.donatii', ['donations.status', 'donations.frequency'])) {
                    return;
                }
                $c->numar('vt.donatii.total', [], 'SELECT COUNT(*) FROM donations LIMIT 1');
                $c->grupuri('vt.donatii', [], "SELECT CONCAT(status, '.', frequency) AS g, COUNT(*) AS n FROM donations GROUP BY status, frequency LIMIT 100",
                    [], 'stare.frecvență');
                $c->numar('vt.donatii.365_zile', ['donations.created_at'], 'SELECT COUNT(*) FROM donations WHERE created_at >= NOW() - INTERVAL 365 DAY LIMIT 1');
            },
        ],
        [
            'cheie' => 'vt_membri',
            'titlu' => 'Membrii (membri): abonamente active, conturi, verificări, activări, conținut doar pentru membri',
            'ruleaza' => static function (Context $c): void {
                $c->numar('vt.membri.activi', ['users.account_active_until'], 'SELECT COUNT(*) FROM users WHERE account_active_until > NOW() LIMIT 1',
                    [], 'conturi cu abonamentul valabil azi');
                $c->grupuri('vt.conturi', ['users.account_status'], 'SELECT account_status AS g, COUNT(*) AS n FROM users GROUP BY account_status LIMIT 50');
                $c->numar('vt.profesii_active', ['professions.is_active'], 'SELECT COUNT(*) FROM professions WHERE is_active = 1 LIMIT 1');
                $c->grupuri('vt.verificari', ['account_verifications.status'], 'SELECT status AS g, COUNT(*) AS n FROM account_verifications GROUP BY status LIMIT 50');
                $c->numar('vt.verificari.documente', ['account_verification_documents'], 'SELECT COUNT(*) FROM account_verification_documents LIMIT 1',
                    [], 'rânduri, nu fișiere');
                $c->grupuri('vt.activari', ['account_activations.status'], 'SELECT status AS g, COUNT(*) AS n FROM account_activations GROUP BY status LIMIT 50');
                $p = $c->are(['pages.deleted_at']) ? 'deleted_at IS NULL AND ' : '';
                $c->numar('vt.pagini_doar_membri', ['pages.requires_active_account'], 'SELECT COUNT(*) FROM pages WHERE ' . $p . 'requires_active_account = 1 LIMIT 1');
                $b = $c->are(['blog_posts.deleted_at']) ? 'deleted_at IS NULL AND ' : '';
                $c->numar('vt.articole_doar_membri', ['blog_posts.requires_active_account'], 'SELECT COUNT(*) FROM blog_posts WHERE ' . $b . 'requires_active_account = 1 LIMIT 1');
                $n = $c->site->numaraFisiere('storage/account-documents');
                $n === null ? $c->raport->alegere('vt.fisiere_documente', 'dosarul lipsește', ['dosarul lipsește'])
                    : $c->raport->numar('vt.fisiere_documente', $n, 'storage/account-documents: doar câte, fără nume');
            },
        ],
    ], $comun['la_final']),
];
