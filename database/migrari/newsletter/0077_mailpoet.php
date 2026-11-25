<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Newsletterul cu datele MailPoet ale unui site mutat de pe WordPress (pista E a mutării unui site WooCommerce;
 * docs/MAGAZIN-WC.md, docs/PLUGINURI.md, „Newsletter”). Le scrie importul (App\Support\ImportWordPress\MailPoet\*), le citesc
 * adresele vechi (App\Support\MailPoetCompat\Router) și abonarea din formularele capturate (App\Support\AbonareCapturata):
 *
 *  - `newsletter_subscribers`: dovada abonării (IP-ul și momentele abonării și ale confirmării, sursa), numele pe părți, legătura
 *    cu contul WordPress și cu clienții WooCommerce, id-ul MailPoet, amprentele jetonului MailPoet (`mailpoet_token_hash` =
 *    sha256(jetonul întreg), `mailpoet_token6_hash` = sha256(primele 6 caractere), doar pentru jetoanele trecute de la 6 la 32 de
 *    caractere; jetonul însuși nu se scrie nicăieri), refuzul urmăririi și datele regulii de inactivitate;
 *  - `newsletter_lists`: id-ul MailPoet, tipul listei (`static`, `wp_users`, `woocommerce_users`) și dacă se vede pe pagina de
 *    gestionare a abonării;
 *  - `newsletter_list_subscribers`: starea pe listă (`subscribed` / `unsubscribed`, ca MailPoet) și când s-a schimbat;
 *  - `newsletter_campaigns`: arhiva campaniilor trimise de MailPoet (`sursa` = `mailpoet`, doar de citit), cu id-ul și hash-ul
 *    lor MailPoet (adresele „vezi în browser”), textul simplu și numele adresei publice de partajare;
 *  - `newsletter_mailpoet_cozi` și `newsletter_mailpoet_linkuri`: cozile trimise de MailPoet și linkurile urmărite din emailurile
 *    lor (clicul dintr-un email vechi duce doar la adresa importată pentru acel hash);
 *  - `newsletter_dezabonari`: fiecare dezabonare (din orice drum), cu motivul din sondajul paginii de dezabonare; cele importate au
 *    și id-ul rândului MailPoet (`mailpoet_id`, unic: a doua aplicare a importului nu le dublează);
 *  - `newsletter_mailpoet_nepotriviri`: câte jetoane greșite a primit un abonat în ultimele 24 de ore (cel mult 10): cât timp
 *    MailPoet mai primea jetoanele vechi de 6 caractere (o lună de la 5.45.0) și pentru un jeton păstrat mai scurt de 32;
 *  - `buget_trimitere`: bugetul comun al trimiterilor (limita de trimitere importată din MailPoet), o „găleată cu jetoane”.
 *
 * Adoptivă și inertă: fiecare coloană are o valoare implicită care păstrează comportamentul de azi (starea pe listă
 * `subscribed`, sursa campaniei `cms`), iar tabelele noi pornesc goale și rămân goale pe site-urile fără MailPoet (toate cele
 * de azi). Fără rândul `buget_trimitere` trimiterile nu au limită (ca azi). Doar ALTER-uri instant (coloane la sfârșit, cu
 * implicite constante) și indecși online, fără copierea vreunui tabel.
 *
 * `newsletter_list_subscribers.updated_at` e `DATETIME NULL` (schița din specificație avea TIMESTAMP cu CURRENT_TIMESTAMP):
 * pe rândurile de azi nu știm când s-au schimbat, deci NULL e adevărul; codul îl scrie la fiecare schimbare a stării. Față de
 * schiță mai sunt `newsletter_dezabonari.mailpoet_id` (cheia importului) și tabelul `newsletter_mailpoet_nepotriviri` (tranzientul
 * MailPoet al jetoanelor greșite, pe abonat).
 */
return [
    'descriere' => 'Newsletterul cu datele MailPoet: dovada abonării, amprentele jetoanelor, starea pe listă, tipul listelor, '
        . 'arhiva campaniilor MailPoet, cozile și linkurile vechi, dezabonările, jetoanele greșite, bugetul comun de trimitere',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        foreach ([
            'first_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'last_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'subscribed_ip' => 'VARCHAR(45) NULL',
            'confirmed_ip' => 'VARCHAR(45) NULL',
            'last_subscribed_at' => 'DATETIME NULL',
            'source' => 'VARCHAR(30) NULL',
            'wp_user_id' => 'BIGINT UNSIGNED NULL',
            'is_woocommerce_user' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'mailpoet_id' => 'INT UNSIGNED NULL',
            'mailpoet_token_hash' => 'CHAR(64) NULL',
            'mailpoet_token_len' => 'TINYINT UNSIGNED NULL',
            'mailpoet_token6_hash' => 'CHAR(64) NULL',
            'urmarire_refuzata' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'last_engagement_at' => 'DATETIME NULL',
            'last_sending_at' => 'DATETIME NULL',
            'email_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ] as $coloana => $definitie) {
            $s->coloana('newsletter_subscribers', $coloana, $definitie);
        }
        $s->unic('newsletter_subscribers', 'uniq_mailpoet_id', ['mailpoet_id']);
        $s->index('newsletter_subscribers', 'idx_wp_user_id', ['wp_user_id']);

        $s->coloana('newsletter_lists', 'mailpoet_id', 'INT UNSIGNED NULL');
        $s->coloana('newsletter_lists', 'tip', "VARCHAR(20) NOT NULL DEFAULT 'static'");
        $s->coloana('newsletter_lists', 'afisare_gestionare', 'TINYINT(1) NOT NULL DEFAULT 0');
        $s->unic('newsletter_lists', 'uniq_mailpoet_id', ['mailpoet_id']);

        $s->coloana('newsletter_list_subscribers', 'status', "VARCHAR(12) NOT NULL DEFAULT 'subscribed'");
        $s->coloana('newsletter_list_subscribers', 'updated_at', 'DATETIME NULL');
        $s->index('newsletter_list_subscribers', 'idx_abonat_stare', ['subscriber_id', 'status']);

        $s->coloana('newsletter_campaigns', 'sursa', "VARCHAR(20) NOT NULL DEFAULT 'cms'");
        $s->coloana('newsletter_campaigns', 'mailpoet_id', 'INT UNSIGNED NULL');
        $s->coloana('newsletter_campaigns', 'mailpoet_hash', 'VARCHAR(150) NULL');
        $s->coloana('newsletter_campaigns', 'text_content', 'LONGTEXT NULL');
        // „<hash>-<slug>” când campania se poate partaja public (/mailpoet-email/<hash>-<slug>/; identificatorul dat de exportatorul
        // MailPoet), altfel NULL.
        $s->coloana('newsletter_campaigns', 'mailpoet_partajare', 'VARCHAR(255) NULL');
        $s->unic('newsletter_campaigns', 'uniq_mailpoet_id', ['mailpoet_id']);

        $s->tabelComplet('newsletter_mailpoet_cozi', [
            // sending_queues.id din MailPoet (queue_id / queueId din adresele vechi)
            'coada_id' => 'INT UNSIGNED NOT NULL',
            'task_id' => 'INT UNSIGNED NOT NULL',
            'newsletter_mailpoet_id' => 'INT UNSIGNED NOT NULL',
            // campanie | automatizare
            'tip' => "VARCHAR(20) NOT NULL DEFAULT 'campanie'",
            'campanie_id' => 'INT UNSIGNED NULL',
            // emailul automatizării (pista J)
            'email_automatizare_id' => 'INT UNSIGNED NULL',
            // cozile automatizărilor: singurul destinatar procesat
            'abonat_id' => 'INT UNSIGNED NULL',
            'trimis_la' => 'DATETIME NULL',
        ], [
            'PRIMARY KEY (coada_id)',
            'KEY idx_campanie (campanie_id)',
            'KEY idx_abonat (abonat_id)',
        ]);

        $s->tabelComplet('newsletter_mailpoet_linkuri', [
            'coada_id' => 'INT UNSIGNED NOT NULL',
            'hash' => 'VARCHAR(20) NOT NULL',
            // până la 2083 de caractere în MailPoet
            'url' => 'TEXT NOT NULL',
        ], [
            'PRIMARY KEY (coada_id, hash)',
        ]);

        $s->tabelComplet('newsletter_dezabonari', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
            'abonat_id' => 'INT UNSIGNED NOT NULL',
            'campanie_id' => 'INT UNSIGNED NULL',
            'email_automatizare_id' => 'INT UNSIGNED NULL',
            'coada_mailpoet_id' => 'INT UNSIGNED NULL',
            // newsletter | manage | admin | order_checkout | automation | mp_api | cms
            'sursa' => "VARCHAR(20) NOT NULL DEFAULT 'newsletter'",
            // link | one_click | unknown
            'metoda' => "VARCHAR(12) NOT NULL DEFAULT 'unknown'",
            // normal | nosignup | inappropriate | spam | other | unspecified
            'motiv' => 'VARCHAR(20) NULL',
            // textul omului (doar în bază, niciodată în jurnale sau exporturi)
            'motiv_text' => 'VARCHAR(500) NULL',
            'motiv_la' => 'DATETIME NULL',
            'created_at' => 'DATETIME NOT NULL',
            // id-ul rândului din `statistics_unsubscribes` (doar la cele importate; NULL la cele făcute pe site-ul nou)
            'mailpoet_id' => 'INT UNSIGNED NULL',
        ], [
            'PRIMARY KEY (id)',
            'KEY idx_abonat_creat (abonat_id, created_at)',
            'KEY idx_campanie (campanie_id)',
            'UNIQUE KEY uniq_mailpoet_id (mailpoet_id)',
        ]);

        $s->tabelComplet('newsletter_mailpoet_nepotriviri', [
            'abonat_id' => 'INT UNSIGNED NOT NULL',
            'numar' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'ultima_la' => 'DATETIME NOT NULL',
        ], [
            'PRIMARY KEY (abonat_id)',
        ]);

        $s->tabelComplet('buget_trimitere', [
            // „smtp”
            'nume' => 'VARCHAR(40) NOT NULL',
            'capacitate' => 'INT UNSIGNED NOT NULL',
            'perioada_s' => 'INT UNSIGNED NOT NULL',
            // poate coborî sub 0 (confirmările nu se amână)
            'jetoane' => 'DECIMAL(12,4) NOT NULL',
            'actualizat_la' => 'DATETIME(6) NOT NULL',
        ], [
            'PRIMARY KEY (nume)',
        ]);
    },
];
