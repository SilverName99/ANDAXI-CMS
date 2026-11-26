-- Fixtura MailPoet 5.45.2 (tools/dev/fixturi-wp/mailpoet.sql): tabelele MailPoet cu prefixul wp_, în forma versiunii 5.45.2
-- (migrarea de bază Migration_20221028_105818 și coloanele de după ea), cu date INVENTATE: adrese @example.invalid, IP-uri din
-- 192.0.2.0/24 și 2001:db8::/32, id-uri inventate. Se încarcă DUPĂ tabelele nucleului WordPress (tools/dev/lib/wordpress-proba.php,
-- prefixul wp_): adaugă și pagina MailPoet „subscriptions” în wp_posts. Datele sunt ora UTC, ca MailPoet (PHP în UTC).
-- Ce acoperă: specificația mailpoet §8.1 (tools/dev/test-import-mailpoet.php, poarta G-mp-import; tools/dev/test-mailpoet-compat.php,
-- G-mailpoet-compat). Scrisă de mână: o schimbare păstrează forma tabelelor MailPoet 5.45.2 și numerele pe care le cer cele două porți.

CREATE TABLE wp_mailpoet_settings (id int(11) unsigned NOT NULL AUTO_INCREMENT, name varchar(50) NOT NULL, value longtext, created_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_segments (id int(11) unsigned NOT NULL AUTO_INCREMENT, name varchar(90) NOT NULL, type varchar(90) NOT NULL DEFAULT 'default',
        description varchar(250) NOT NULL DEFAULT '', public_description text NULL, created_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at timestamp NULL,
        average_engagement_score float unsigned NULL, average_engagement_score_updated_at timestamp NULL,
        display_in_manage_subscription_page tinyint(1) NOT NULL DEFAULT 0, confirmation_email_id int(11) unsigned NULL DEFAULT NULL,
        confirmation_page_id int(11) unsigned NULL DEFAULT NULL, PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_subscribers (id int(11) unsigned NOT NULL AUTO_INCREMENT, wp_user_id bigint(20) NULL, is_woocommerce_user int(1) NOT NULL DEFAULT 0,
        first_name varchar(255) NOT NULL DEFAULT '', last_name varchar(255) NOT NULL DEFAULT '', email varchar(150) NOT NULL,
        status varchar(12) NOT NULL DEFAULT 'unconfirmed', subscribed_ip varchar(45) NULL, confirmed_ip varchar(45) NULL,
        confirmed_at timestamp NULL, last_subscribed_at timestamp NULL, created_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at timestamp NULL, unconfirmed_data longtext,
        source enum('form','imported','administrator','api','wordpress_user','wordpress_user_deleted','woocommerce_user','woocommerce_checkout','unknown') DEFAULT 'unknown',
        count_confirmations int(11) unsigned NOT NULL DEFAULT 0, unsubscribe_token char(15) NULL, link_token char(32) NULL,
        engagement_score float unsigned NULL, engagement_score_updated_at timestamp NULL, last_engagement_at timestamp NULL,
        woocommerce_synced_at timestamp NULL, email_count int(11) unsigned NOT NULL DEFAULT 0, last_sending_at timestamp NULL DEFAULT NULL,
        last_open_at timestamp NULL DEFAULT NULL, last_click_at timestamp NULL DEFAULT NULL, last_purchase_at timestamp NULL DEFAULT NULL,
        last_page_view_at timestamp NULL DEFAULT NULL, last_confirmation_email_sent_at timestamp NULL DEFAULT NULL,
        time_zone varchar(64) NULL DEFAULT NULL, time_zone_source varchar(32) NULL DEFAULT NULL, time_zone_confidence int NULL DEFAULT NULL,
        time_zone_updated_at timestamp NULL DEFAULT NULL, segments_count int unsigned NOT NULL DEFAULT 0,
        tracking_consent varchar(20) NOT NULL DEFAULT 'unknown', tracking_consent_updated_at timestamp NULL DEFAULT NULL,
        tracking_consent_method varchar(40) NULL DEFAULT NULL, tracking_consent_copy text NULL DEFAULT NULL, source_plugin varchar(191) NULL DEFAULT NULL,
        source_url text NULL, PRIMARY KEY (id), UNIQUE KEY email (email), UNIQUE KEY unsubscribe_token (unsubscribe_token), KEY wp_user_id (wp_user_id),
        KEY link_token (link_token)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_subscriber_segment (id int(11) unsigned NOT NULL AUTO_INCREMENT, subscriber_id int(11) unsigned NOT NULL, segment_id int(11) unsigned NOT NULL,
        status varchar(12) NOT NULL DEFAULT 'subscribed', created_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id),
        UNIQUE KEY subscriber_segment (subscriber_id,segment_id), KEY segment_id (segment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_custom_fields (id int(11) unsigned NOT NULL AUTO_INCREMENT, name varchar(90) NOT NULL, type varchar(90) NOT NULL, params longtext NOT NULL,
        created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_subscriber_custom_field (id int(11) unsigned NOT NULL AUTO_INCREMENT, subscriber_id int(11) unsigned NOT NULL, custom_field_id int(11) unsigned NOT NULL,
        value text NOT NULL, created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id),
        UNIQUE KEY subscriber_id_custom_field_id (subscriber_id,custom_field_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_newsletters (id int(11) unsigned NOT NULL AUTO_INCREMENT, hash varchar(150) NULL DEFAULT NULL, parent_id int(11) unsigned NULL,
        subject varchar(250) NOT NULL DEFAULT '', type varchar(150) NOT NULL DEFAULT 'standard', sender_address varchar(150) NOT NULL DEFAULT '',
        sender_name varchar(150) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'draft', reply_to_address varchar(150) NOT NULL DEFAULT '',
        reply_to_name varchar(150) NOT NULL DEFAULT '', preheader varchar(250) NOT NULL DEFAULT '', body longtext, sent_at timestamp NULL,
        created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at timestamp NULL,
        unsubscribe_token char(15) NULL, ga_campaign varchar(250) NOT NULL DEFAULT '', wp_post_id int NULL, PRIMARY KEY (id),
        UNIQUE KEY unsubscribe_token (unsubscribe_token), KEY type_status (type,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_newsletter_option_fields (id int(11) unsigned NOT NULL AUTO_INCREMENT, name varchar(90) NOT NULL, newsletter_type varchar(90) NOT NULL,
        created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id),
        UNIQUE KEY name_newsletter_type (newsletter_type,name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_newsletter_option (id int(11) unsigned NOT NULL AUTO_INCREMENT, newsletter_id int(11) unsigned NOT NULL, option_field_id int(11) unsigned NOT NULL,
        value longtext, created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id),
        UNIQUE KEY newsletter_id_option_field_id (newsletter_id,option_field_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_newsletter_segment (id int(11) unsigned NOT NULL AUTO_INCREMENT, newsletter_id int(11) unsigned NOT NULL, segment_id int(11) unsigned NOT NULL,
        created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id),
        UNIQUE KEY newsletter_segment (newsletter_id,segment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_scheduled_tasks (id int(11) unsigned NOT NULL AUTO_INCREMENT, type varchar(90) NULL DEFAULT NULL, status varchar(12) NULL DEFAULT NULL,
        priority mediumint(9) NOT NULL DEFAULT 0, scheduled_at timestamp NULL, processed_at timestamp NULL, created_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at timestamp NULL, in_progress int(1),
        reschedule_count int(11) NOT NULL DEFAULT 0, meta longtext, cancelled_at timestamp NULL DEFAULT NULL, PRIMARY KEY (id), KEY type (type), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_scheduled_task_subscribers (task_id int(11) unsigned NOT NULL, subscriber_id int(11) unsigned NOT NULL, processed int(1) NOT NULL,
        failed smallint(1) NOT NULL DEFAULT 0, error text NULL, created_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (task_id, subscriber_id), KEY subscriber_id (subscriber_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_sending_queues (id int(11) unsigned NOT NULL AUTO_INCREMENT, task_id int(11) unsigned NOT NULL, newsletter_id int(11) unsigned NULL,
        newsletter_rendered_body longtext, newsletter_rendered_subject varchar(250) NULL DEFAULT NULL, subscribers longtext,
        count_total int(11) unsigned NOT NULL DEFAULT 0, count_processed int(11) unsigned NOT NULL DEFAULT 0, count_to_process int(11) unsigned NOT NULL DEFAULT 0,
        meta longtext, created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, deleted_at timestamp NULL,
        PRIMARY KEY (id), KEY task_id (task_id), KEY newsletter_id (newsletter_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_newsletter_links (id int(11) unsigned NOT NULL AUTO_INCREMENT, newsletter_id int(11) unsigned NOT NULL, queue_id int(11) unsigned NOT NULL,
        url varchar(2083) NOT NULL, hash varchar(20) NOT NULL, created_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY newsletter_id (newsletter_id),
        KEY queue_id (queue_id), KEY url (url(100))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_statistics_newsletters (id int(11) unsigned NOT NULL AUTO_INCREMENT, newsletter_id int(11) unsigned NOT NULL, subscriber_id int(11) unsigned NOT NULL,
        queue_id int(11) unsigned NOT NULL, sent_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        sent_with_tracking tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), KEY newsletter_id (newsletter_id), KEY subscriber_id (subscriber_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_statistics_opens (id int(11) unsigned NOT NULL AUTO_INCREMENT, newsletter_id int(11) unsigned NOT NULL, subscriber_id int(11) unsigned NOT NULL,
        queue_id int(11) unsigned NOT NULL, user_agent_id int(11) unsigned NULL, user_agent_type tinyint(1) NOT NULL DEFAULT 0,
        created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY queue_id (queue_id), KEY subscriber_id (subscriber_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_statistics_clicks (id int(11) unsigned NOT NULL AUTO_INCREMENT, newsletter_id int(11) unsigned NOT NULL, subscriber_id int(11) unsigned NOT NULL,
        queue_id int(11) unsigned NOT NULL, link_id int(11) unsigned NOT NULL, user_agent_id int(11) unsigned NULL, user_agent_type tinyint(1) NOT NULL DEFAULT 0,
        count int(11) unsigned NOT NULL, created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY queue_id (queue_id), KEY subscriber_id (subscriber_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_statistics_unsubscribes (id int(11) unsigned NOT NULL AUTO_INCREMENT, newsletter_id int(11) unsigned NULL, subscriber_id int(11) unsigned NOT NULL,
        queue_id int(11) unsigned NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, source varchar(255) DEFAULT 'unknown', meta varchar(255) NULL,
        method varchar(40) NOT NULL DEFAULT 'unknown', reason varchar(80) NULL DEFAULT NULL, reason_text text NULL DEFAULT NULL,
        reason_submitted_at timestamp NULL DEFAULT NULL, PRIMARY KEY (id), KEY queue_id (queue_id), KEY subscriber_id (subscriber_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_forms (id int(11) unsigned NOT NULL AUTO_INCREMENT, name varchar(90) NOT NULL, status varchar(20) NOT NULL DEFAULT 'enabled', body longtext,
        settings longtext, styles longtext, created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at timestamp NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_automations (id int(11) unsigned NOT NULL AUTO_INCREMENT, name varchar(191) NOT NULL, author bigint NOT NULL, status varchar(191) NOT NULL,
        meta longtext DEFAULT NULL, created_at timestamp NULL, updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        activated_at timestamp NULL, deleted_at timestamp NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_automation_versions (id int(11) unsigned NOT NULL AUTO_INCREMENT, automation_id int(11) unsigned NOT NULL, steps longtext, created_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), INDEX (automation_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_automation_triggers (automation_id int(11) unsigned NOT NULL, trigger_key varchar(191), PRIMARY KEY (automation_id, trigger_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_automation_runs (id int(11) unsigned NOT NULL AUTO_INCREMENT, automation_id int(11) unsigned NOT NULL, version_id int(11) unsigned NOT NULL,
        trigger_key varchar(191) NOT NULL, status varchar(191) NOT NULL, created_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, next_step_id varchar(191), PRIMARY KEY (id),
        INDEX (automation_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_automation_run_logs (id int(11) unsigned NOT NULL AUTO_INCREMENT, automation_run_id int(11) unsigned NOT NULL, step_id varchar(191) NOT NULL,
        step_type varchar(255) NOT NULL, step_key varchar(255) NOT NULL, status varchar(191) NOT NULL, started_at timestamp NULL,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, run_number int NOT NULL, error longtext, data longtext NOT NULL,
        PRIMARY KEY (id), UNIQUE KEY automation_run_id_step_id (automation_run_id, step_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_mailpoet_automation_run_subjects (id int(11) unsigned NOT NULL AUTO_INCREMENT, automation_run_id int(11) unsigned NOT NULL, `key` varchar(191),
        args longtext, hash varchar(191), PRIMARY KEY (id), INDEX (automation_run_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_actionscheduler_groups (group_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, slug varchar(255) NOT NULL, PRIMARY KEY (group_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE wp_actionscheduler_actions (action_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, hook varchar(191) NOT NULL, status varchar(20) NOT NULL, scheduled_date_gmt datetime NULL, scheduled_date_local datetime NULL, priority tinyint unsigned NOT NULL DEFAULT 10, args varchar(191), schedule longtext, group_id bigint(20) unsigned NOT NULL DEFAULT 0, attempts int(11) NOT NULL DEFAULT 0, last_attempt_gmt datetime NULL, last_attempt_local datetime NULL, claim_id bigint(20) unsigned NOT NULL DEFAULT 0, extended_args varchar(8000) DEFAULT NULL, PRIMARY KEY (action_id), KEY hook (hook)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type, guid) VALUES
  (9001, 1, '2024-07-02 10:00:00', '2024-07-02 10:00:00', '[mailpoet_page]', 'MailPoet Page', '', 'publish', 'subscriptions', '', '', '2024-07-02 10:00:00', '2024-07-02 10:00:00', '', 'mailpoet_page', 'https://pensiune-proba.test/?mailpoet_page=subscriptions');
INSERT INTO wp_mailpoet_settings (id, name, value, created_at) VALUES
  (1, 'signup_confirmation', 'a:5:{s:7:"enabled";s:1:"1";s:19:"use_mailpoet_editor";b:1;s:22:"transactional_email_id";i:1;s:7:"subject";s:41:"Confirmă-ți abonamentul la [site:title]";s:4:"body";s:105:"Salut [subscriber:firstname | default:prieten],

[activation_link]Confirmă abonamentul[/activation_link]";}', '2024-07-02 10:00:00'),
  (2, 'subscription', 'a:3:{s:5:"pages";a:5:{s:6:"manage";i:9001;s:11:"unsubscribe";i:9001;s:12:"confirmation";i:9001;s:19:"confirm_unsubscribe";i:9001;s:7:"captcha";i:9001;}s:30:"manage_subscription_page_style";s:7:"classic";s:18:"unsubscribe_survey";a:2:{s:7:"enabled";s:1:"1";s:16:"allow_other_text";s:0:"";}}', '2024-07-02 10:00:00'),
  (3, 'reEngagement', 'a:1:{s:4:"page";i:9001;}', '2024-07-02 10:00:00'),
  (4, 'tracking', 'a:2:{s:5:"level";s:4:"full";s:7:"consent";a:1:{s:17:"subscriber_choice";s:9:"track_all";}}', '2024-07-02 10:00:00'),
  (5, 'woocommerce', 'a:3:{s:17:"optin_on_checkout";a:4:{s:7:"enabled";s:1:"1";s:7:"message";s:36:"Vreau să primesc emailuri de probă";s:8:"position";s:11:"after_terms";s:8:"segments";a:1:{i:0;s:3:"104";}}s:19:"use_mailpoet_editor";s:0:"";s:22:"transactional_email_id";N;}', '2024-07-02 10:00:00'),
  (6, 'subscribe', 'a:1:{s:11:"on_register";a:2:{s:7:"enabled";s:0:"";s:5:"label";s:17:"Da, abonează-mă";}}', '2024-07-02 10:00:00'),
  (7, 'subscriber_email_notification', 'a:3:{s:7:"enabled";s:1:"1";s:9:"automated";s:1:"1";s:7:"address";s:26:"notificari@example.invalid";}', '2024-07-02 10:00:00'),
  (8, 'sender', 'a:2:{s:4:"name";s:16:"Pensiunea Probă";s:7:"address";s:23:"trimite@example.invalid";}', '2024-07-02 10:00:00'),
  (9, 'reply_to', 'a:2:{s:4:"name";s:0:"";s:7:"address";s:0:"";}', '2024-07-02 10:00:00'),
  (10, 'mta', 'a:5:{s:6:"method";s:4:"SMTP";s:9:"frequency";a:2:{s:6:"emails";s:2:"40";s:8:"interval";s:2:"10";}s:4:"host";s:20:"smtp.example.invalid";s:4:"port";s:4:"2525";s:10:"encryption";s:3:"ssl";}', '2024-07-02 10:00:00'),
  (11, 'deactivate_subscriber_after_inactive_days', '365', '2024-07-02 10:00:00'),
  (12, 'delete_unconfirmed_subscribers_after_days', '', '2024-07-02 10:00:00'),
  (13, 'sending_queue_body_retention_days', '30', '2024-07-02 10:00:00'),
  (14, 'sending_status_retention_days', '', '2024-07-02 10:00:00'),
  (15, 'sharing', 'a:1:{s:18:"default_visibility";s:6:"public";}', '2024-07-02 10:00:00'),
  (16, 'mailpoet_subscribe_old_woocommerce_customers', 'a:2:{s:7:"enabled";s:0:"";s:9:"dismissed";s:1:"1";}', '2024-07-02 10:00:00'),
  (17, 'obsolete_link_tokens_accepted_until', '1797724800', '2024-07-02 10:00:00'),
  (18, 'installed_at', '2024-07-02 10:00:00', '2024-07-02 10:00:00');
INSERT INTO wp_mailpoet_segments (id, name, type, description, created_at, deleted_at, display_in_manage_subscription_page) VALUES
  (101, 'WordPress Users', 'wp_users', 'Utilizatorii WordPress', '2024-07-02 10:00:00', NULL, 0),
  (102, 'WooCommerce Customers', 'woocommerce_users', 'Clienții magazinului', '2024-07-02 10:00:00', NULL, 0),
  (103, 'Lista principală de probă', 'default', 'Abonații din subsol', '2024-07-02 10:00:00', NULL, 0),
  (104, 'Lista vizibilă de probă', 'default', '', '2024-07-03 10:00:00', NULL, 1),
  (105, 'Lista ascunsă de probă', 'default', '', '2024-07-04 10:00:00', NULL, 0),
  (106, 'Lista din coș', 'default', '', '2024-07-05 10:00:00', '2025-01-10 10:00:00', 0);
INSERT INTO wp_mailpoet_subscribers (id, wp_user_id, is_woocommerce_user, first_name, last_name, email, status, subscribed_ip, confirmed_ip, confirmed_at, last_subscribed_at, created_at, deleted_at, source, count_confirmations, unsubscribe_token, link_token, last_engagement_at, email_count, last_sending_at, last_confirmation_email_sent_at, tracking_consent) VALUES
  (11, NULL, 0, 'Ana', 'Probă', 'abonat@example.invalid', 'subscribed', '192.0.2.11', '192.0.2.12', '2025-03-01 09:00:00', '2025-03-01 09:00:00', '2025-03-01 08:55:00', NULL, 'api', 1, 'aaaaaaaaaaaaa11', '0123456789abcdef0123456789abcdef', '2026-10-01 12:00:00', 12, '2026-10-15 08:00:00', NULL, 'unknown'),
  (12, NULL, 0, 'Bogdan', '', 'neconfirmat@example.invalid', 'unconfirmed', '192.0.2.13', NULL, NULL, NULL, '2026-09-01 10:00:00', NULL, 'api', 1, 'aaaaaaaaaaaaa12', 'a1b2c3-0123456789abcdef012345678', NULL, 0, NULL, '2026-09-02 10:00:00', 'unknown'),
  (13, NULL, 0, 'Carmen', 'Dezabonată', 'dezabonat@example.invalid', 'unsubscribed', '2001:db8::13', '2001:db8::14', '2025-04-01 09:00:00', '2025-04-01 09:00:00', '2025-04-01 08:00:00', NULL, 'api', 0, 'aaaaaaaaaaaaa13', 'a1b2c3', NULL, 3, '2026-10-15 08:00:00', NULL, 'unknown'),
  (14, NULL, 0, 'Dan', '', 'inactiv@example.invalid', 'inactive', '192.0.2.14', '192.0.2.14', '2024-08-01 09:00:00', '2024-08-01 09:00:00', '2024-08-01 08:00:00', NULL, 'api', 1, 'aaaaaaaaaaaaa14', NULL, '2024-09-01 12:00:00', 20, '2026-10-15 08:00:00', NULL, 'unknown'),
  (15, NULL, 0, 'Elena', '', 'sarit@example.invalid', 'bounced', '192.0.2.15', '192.0.2.15', '2024-08-01 09:00:00', '2024-08-01 09:00:00', '2024-08-01 08:00:00', NULL, 'api', 1, 'aaaaaaaaaaaaa15', '', NULL, 2, NULL, NULL, 'unknown'),
  (16, NULL, 0, 'Florin', '', 'cos@example.invalid', 'unsubscribed', '192.0.2.16', NULL, NULL, NULL, '2024-09-01 08:00:00', '2025-02-01 08:00:00', 'api', 0, 'aaaaaaaaaaaaa16', 'fedcba9876543210fedcba9876543216', NULL, 0, NULL, NULL, 'unknown'),
  (17, 5, 0, 'Gabriela', 'Cont', 'cont@example.invalid', 'subscribed', '192.0.2.17', '192.0.2.17', '2025-05-01 09:00:00', '2025-05-01 09:00:00', '2025-05-01 08:00:00', NULL, 'wordpress_user', 1, 'aaaaaaaaaaaaa17', 'fedcba9876543210fedcba9876543217', NULL, 1, NULL, NULL, 'unknown'),
  (18, NULL, 1, 'Horia', 'Client', 'client@example.invalid', 'unsubscribed', NULL, NULL, NULL, '2025-06-01 09:00:00', '2025-06-01 09:00:00', NULL, 'woocommerce_user', 0, 'aaaaaaaaaaaaa18', 'fedcba9876543210fedcba9876543218', NULL, 0, NULL, NULL, 'unknown'),
  (19, NULL, 0, 'Ioana', '', 'refuz@example.invalid', 'subscribed', '192.0.2.19', '192.0.2.19', '2025-07-01 09:00:00', '2025-07-01 09:00:00', '2025-07-01 08:00:00', NULL, 'woocommerce_checkout', 1, 'aaaaaaaaaaaaa19', 'fedcba9876543210fedcba9876543219', NULL, 1, NULL, NULL, 'denied'),
  (20, NULL, 0, 'Liviu', '', 'Majuscule.Proba@Example.Invalid', 'subscribed', '192.0.2.20', '192.0.2.20', '2025-08-01 09:00:00', '2025-08-01 09:00:00', '2025-08-01 08:00:00', NULL, 'form', 1, 'aaaaaaaaaaaaa20', 'fedcba9876543210fedcba9876543220', NULL, 1, NULL, NULL, 'unknown'),
  (21, NULL, 0, 'Mara', '', 'cos-sarit@example.invalid', 'bounced', '192.0.2.21', '192.0.2.21', '2024-08-01 09:00:00', '2024-08-01 09:00:00', '2024-08-01 08:00:00', '2025-02-01 08:00:00', 'api', 1, 'aaaaaaaaaaaaa21', 'fedcba9876543210fedcba9876543221', NULL, 0, NULL, NULL, 'unknown'),
  (22, NULL, 0, 'Nicu', '', 'cos-abonat@example.invalid', 'subscribed', '192.0.2.22', '192.0.2.22', '2024-08-01 09:00:00', '2024-08-01 09:00:00', '2024-08-01 08:00:00', '2025-02-01 08:00:00', 'api', 1, 'aaaaaaaaaaaaa22', 'fedcba9876543210fedcba9876543222', NULL, 0, NULL, NULL, 'unknown');
INSERT INTO wp_mailpoet_subscriber_segment (subscriber_id, segment_id, status, created_at) VALUES
  (11, 103, 'subscribed', '2025-03-01 08:55:00'),
  (11, 104, 'subscribed', '2025-03-01 08:55:00'),
  (11, 106, 'subscribed', '2025-03-01 08:55:00'),
  (12, 103, 'subscribed', '2026-09-01 10:00:00'),
  (13, 103, 'unsubscribed', '2025-04-01 08:00:00'),
  (13, 104, 'unsubscribed', '2025-04-01 08:00:00'),
  (14, 103, 'subscribed', '2024-08-01 08:00:00'),
  (15, 103, 'subscribed', '2024-08-01 08:00:00'),
  (16, 103, 'unsubscribed', '2024-09-01 08:00:00'),
  (17, 101, 'subscribed', '2025-05-01 08:00:00'),
  (17, 103, 'subscribed', '2025-05-01 08:00:00'),
  (18, 102, 'unsubscribed', '2025-06-01 09:00:00'),
  (19, 102, 'subscribed', '2025-07-01 08:00:00'),
  (19, 104, 'subscribed', '2025-07-01 08:00:00'),
  (20, 105, 'subscribed', '2025-08-01 08:00:00');
INSERT INTO wp_mailpoet_newsletters (id, hash, subject, type, status, sent_at, created_at, deleted_at, body, wp_post_id) VALUES
  (1, '0f0e0d0c0b0a', 'Ultimul pas pentru abonarea la [site:title]', 'confirmation_email', 'active', NULL, '2024-07-02 10:00:00', NULL, '{}', NULL),
  (31, 'aa11bb22cc33', 'Scrisoarea de probă unu', 'standard', 'sent', '2026-10-15 08:00:00', '2026-10-14 10:00:00', NULL, '{}', NULL),
  (32, 'dd44ee55ff66', 'Scrisoarea de probă doi', 'standard', 'sent', '2026-08-15 08:00:00', '2026-08-14 10:00:00', NULL, '{}', NULL),
  (33, 'cc00cc00cc00', 'O ciornă de probă', 'standard', 'draft', NULL, '2026-11-01 10:00:00', NULL, '{}', NULL),
  (35, 'ab12cd34ef56', 'Bun venit de probă', 'automation', 'active', NULL, '2025-01-01 10:00:00', NULL, '{}', NULL),
  (36, 'bc23de45fa67', 'A doua scrisoare de probă', 'automation', 'active', NULL, '2025-01-01 10:00:00', NULL, '{}', NULL);
INSERT INTO wp_mailpoet_newsletter_option_fields (id, name, newsletter_type) VALUES
  (1, 'shareVisibility', 'standard'),
  (2, 'automationId', 'automation'),
  (3, 'automationStepId', 'automation');
INSERT INTO wp_mailpoet_newsletter_option (newsletter_id, option_field_id, value) VALUES
  (31, 1, 'public'),
  (32, 1, 'private'),
  (35, 2, '1'),
  (35, 3, 'e1'),
  (36, 2, '1'),
  (36, 3, 'e2');
INSERT INTO wp_mailpoet_newsletter_segment (newsletter_id, segment_id) VALUES
  (31, 103),
  (31, 104),
  (32, 103),
  (32, 106);
INSERT INTO wp_mailpoet_scheduled_tasks (id, type, status, priority, scheduled_at, processed_at, created_at, meta) VALUES
  (51, 'sending', 'completed', 0, '2026-10-15 08:00:00', '2026-10-15 08:05:00', '2026-10-15 07:59:00', NULL),
  (52, 'sending', 'completed', 0, '2026-08-15 08:00:00', '2026-08-15 08:03:00', '2026-08-15 07:59:00', NULL),
  (53, 'sending', 'completed', 0, '2026-06-01 09:01:00', '2026-06-01 09:02:00', '2026-06-01 09:00:00', '{"automation":{"id":1,"run_id":71,"step_id":"e1","run_number":1}}');
INSERT INTO wp_mailpoet_sending_queues (id, task_id, newsletter_id, newsletter_rendered_body, newsletter_rendered_subject, count_total, count_processed, created_at) VALUES
  (41, 51, 31, '{"html":"<!DOCTYPE html><html><head><title>Scrisoarea unu</title><style>p{color:#333}</style></head><body><p>Salut [subscriber:firstname | default:prieten],</p><p><a href=\\"[mailpoet_click_data]-0a1b2c3d4e5f\\">Oferta de probă</a></p><p><a href=\\"[mailpoet_click_data]-1b2c3d4e5f60\\">Dezabonare</a> | <a href=\\"[mailpoet_click_data]-3d4e5f607182\\">Administrează</a> | <a href=\\"[mailpoet_click_data]-4e5f60718293\\">Vezi în browser</a> | <a href=\\"[mailpoet_click_data]-5f60718293a4\\">Altceva</a> | <a href=\\"[mailpoet_click_data]-a0b1c2d3e4f5\\">Fără adresă</a></p><img alt=\\"\\" src=\\"[mailpoet_open_data]\\" width=\\"1\\" height=\\"1\\"></body></html>","text":"Salut [subscriber:firstname | default:prieten],\\nOferta de probă: [mailpoet_click_data]-0a1b2c3d4e5f\\n"}', 'Scrisoarea de probă unu', 5, 5, '2026-10-15 07:59:00'),
  (42, 52, 32, NULL, 'Scrisoarea de probă doi', 2, 2, '2026-08-15 07:59:00'),
  (43, 53, 35, 'a:2:{s:4:"html";s:212:"<p>Bun venit, [subscriber:firstname | default:prieten]! <a href="[mailpoet_click_data]-8293a4b5c6d7">Dezabonare</a> <a href="[mailpoet_click_data]-93a4b5c6d7e8">Site</a></p><img src="[mailpoet_open_data]" alt="">";s:4:"text";s:10:"Bun venit!";}', 'Bun venit de probă', 1, 1, '2026-06-01 09:00:00');
INSERT INTO wp_mailpoet_scheduled_task_subscribers (task_id, subscriber_id, processed, failed, error, created_at) VALUES
  (51, 11, 1, 0, NULL, '2026-10-15 08:00:00'),
  (51, 13, 1, 0, NULL, '2026-10-15 08:00:00'),
  (51, 19, 1, 0, NULL, '2026-10-15 08:00:00'),
  (51, 14, 1, 1, 'Eroare de probă a serverului de email', '2026-10-15 08:00:00'),
  (51, 12, 0, 0, NULL, '2026-10-15 08:00:00'),
  (52, 11, 1, 0, NULL, '2026-08-15 08:00:00'),
  (52, 20, 1, 0, NULL, '2026-08-15 08:00:00'),
  (53, 11, 1, 0, NULL, '2026-06-01 09:01:00');
INSERT INTO wp_mailpoet_newsletter_links (id, newsletter_id, queue_id, url, hash, created_at) VALUES
  (61, 31, 41, 'https://pensiune-proba.test/oferta/?utm_source=mailpoet&utm_medium=email', '0a1b2c3d4e5f', '2026-10-15 07:59:00'),
  (62, 31, 41, '[link:subscription_unsubscribe_url]', '1b2c3d4e5f60', '2026-10-15 07:59:00'),
  (63, 31, 41, '[link:subscription_instant_unsubscribe_url]', '2c3d4e5f6071', '2026-10-15 07:59:00'),
  (64, 31, 41, '[link:subscription_manage_url]', '3d4e5f607182', '2026-10-15 07:59:00'),
  (65, 31, 41, '[link:newsletter_view_in_browser_url]', '4e5f60718293', '2026-10-15 07:59:00'),
  (66, 31, 41, '[link:foo]', '5f60718293a4', '2026-10-15 07:59:00'),
  (67, 32, 42, 'https://pensiune-proba.test/despre/', '6071829304b5', '2026-08-15 07:59:00'),
  (68, 32, 42, '[link:subscription_unsubscribe_url]', '718293a4b5c6', '2026-08-15 07:59:00'),
  (69, 35, 43, '[link:subscription_unsubscribe_url]', '8293a4b5c6d7', '2026-06-01 09:00:00'),
  (70, 35, 43, 'https://pensiune-proba.test/', '93a4b5c6d7e8', '2026-06-01 09:00:00');
INSERT INTO wp_mailpoet_statistics_newsletters (newsletter_id, subscriber_id, queue_id, sent_at) VALUES
  (31, 11, 41, '2026-10-15 08:01:00'),
  (31, 13, 41, '2026-10-15 08:01:10'),
  (31, 19, 41, '2026-10-15 08:01:20'),
  (32, 11, 42, '2026-08-15 08:01:00'),
  (32, 20, 42, '2026-08-15 08:01:30'),
  (35, 11, 43, '2026-06-01 09:01:30');
INSERT INTO wp_mailpoet_statistics_opens (newsletter_id, subscriber_id, queue_id, user_agent_type, created_at) VALUES
  (31, 11, 41, 0, '2026-10-15 09:00:00'),
  (32, 20, 42, 0, '2026-08-16 10:00:00');
INSERT INTO wp_mailpoet_statistics_clicks (newsletter_id, subscriber_id, queue_id, link_id, user_agent_type, count, created_at) VALUES
  (31, 11, 41, 61, 0, 3, '2026-10-15 09:01:00'),
  (31, 13, 41, 62, 0, 1, '2026-10-16 10:00:00');
INSERT INTO wp_mailpoet_statistics_unsubscribes (newsletter_id, subscriber_id, queue_id, created_at, source, meta, method, reason, reason_text, reason_submitted_at) VALUES
  (31, 13, 41, '2026-10-16 10:00:05', 'newsletter', NULL, 'link', 'spam', NULL, '2026-10-16 10:01:00'),
  (NULL, 18, NULL, '2025-06-01 09:00:00', 'order_checkout', NULL, 'unknown', NULL, NULL, NULL);
INSERT INTO wp_mailpoet_automations (id, name, author, status, meta, created_at, activated_at) VALUES
  (1, 'Bun venit de probă', 1, 'active', '{"mailpoet:run-once-per-subscriber":true}', '2025-01-01 10:00:00', '2025-01-01 10:00:00'),
  (2, 'Cont nou de probă', 1, 'active', '{"mailpoet:run-once-per-subscriber":false}', '2025-01-02 10:00:00', '2025-01-02 10:00:00'),
  (3, 'Cumpărare de probă', 1, 'draft', '{"mailpoet:run-once-per-subscriber":true}', '2025-01-03 10:00:00', NULL);
INSERT INTO wp_mailpoet_automation_versions (id, automation_id, steps, created_at) VALUES
  (81, 1, '{"root":{"id":"root","type":"root","key":"core:root","args":{},"next_steps":[{"id":"t1"}],"filters":null},"t1":{"key":"mailpoet:someone-subscribes","args":{"segment_ids":[104]},"next_steps":[{"id":"d1"}],"id":"t1","type":"trigger","filters":null},"d1":{"id":"d1","type":"action","key":"core:delay","args":{"delay":7,"delay_type":"HOURS"},"next_steps":[{"id":"e1"}],"filters":null},"e1":{"id":"e1","type":"action","key":"mailpoet:send-email","args":{"email_id":35,"name":"Email de probă","subject":"Subiect de probă","preheader":"","sender_name":"Pensiunea Probă","sender_address":"trimite@example.invalid","reply_to_name":"","reply_to_address":"","ga_campaign":""},"next_steps":[{"id":"e2"}],"filters":null},"e2":{"id":"e2","type":"action","key":"mailpoet:send-email","args":{"email_id":36,"name":"Email de probă","subject":"Subiect de probă","preheader":"","sender_name":"Pensiunea Probă","sender_address":"trimite@example.invalid","reply_to_name":"","reply_to_address":"","ga_campaign":""},"next_steps":[],"filters":null}}', '2025-01-01 10:00:00'),
  (82, 2, '{"root":{"id":"root","type":"root","key":"core:root","args":{},"next_steps":[{"id":"t1"}],"filters":null},"t1":{"key":"mailpoet:wp-user-registered","args":{"roles":["customer"]},"next_steps":[{"id":"e1"}],"id":"t1","type":"trigger","filters":null},"e1":{"id":"e1","type":"action","key":"mailpoet:send-email","args":{"email_id":36,"name":"Email de probă","subject":"Subiect de probă","preheader":"","sender_name":"Pensiunea Probă","sender_address":"trimite@example.invalid","reply_to_name":"","reply_to_address":"","ga_campaign":""},"next_steps":[],"filters":null}}', '2025-01-02 10:00:00'),
  (83, 3, '{"root":{"id":"root","type":"root","key":"core:root","args":{},"next_steps":[{"id":"t1"}],"filters":null},"t1":{"key":"woocommerce:buys-a-product","args":{"product_ids":[501],"to":"wc-completed"},"next_steps":[{"id":"e1"}],"id":"t1","type":"trigger","filters":null},"e1":{"id":"e1","type":"action","key":"mailpoet:send-email","args":{"email_id":36,"name":"Email de probă","subject":"Subiect de probă","preheader":"","sender_name":"Pensiunea Probă","sender_address":"trimite@example.invalid","reply_to_name":"","reply_to_address":"","ga_campaign":""},"next_steps":[],"filters":null}}', '2025-01-03 10:00:00');
INSERT INTO wp_mailpoet_automation_triggers (automation_id, trigger_key) VALUES
  (1, 'mailpoet:someone-subscribes'),
  (2, 'mailpoet:wp-user-registered'),
  (3, 'woocommerce:buys-a-product');
INSERT INTO wp_mailpoet_automation_runs (id, automation_id, version_id, trigger_key, status, created_at, next_step_id) VALUES
  (71, 1, 81, 'mailpoet:someone-subscribes', 'complete', '2026-06-01 09:00:00', NULL),
  (72, 1, 81, 'mailpoet:someone-subscribes', 'running', '2026-11-19 11:58:00', 'e1');
INSERT INTO wp_mailpoet_automation_run_subjects (automation_run_id, `key`, args, hash) VALUES
  (71, 'mailpoet:subscriber', '{"subscriber_id":11}', '75a8a9dd7476f54f34b53fd3559b420f'),
  (71, 'mailpoet:segment', '{"segment_id":104}', 'd891e45a0c26f75279bdd74c4bd697b7'),
  (72, 'mailpoet:subscriber', '{"subscriber_id":19}', '0c3cf9eef20e54fa28c2eab9737064fd'),
  (72, 'mailpoet:segment', '{"segment_id":104}', 'd891e45a0c26f75279bdd74c4bd697b7');
INSERT INTO wp_mailpoet_automation_run_logs (automation_run_id, step_id, step_type, step_key, status, started_at, run_number, error, data) VALUES
  (71, 't1', 'trigger', 'mailpoet:someone-subscribes', 'complete', '2026-06-01 09:00:00', 1, NULL, '{}'),
  (71, 'd1', 'action', 'core:delay', 'complete', '2026-06-01 09:00:00', 1, NULL, '{}'),
  (71, 'e1', 'action', 'mailpoet:send-email', 'complete', '2026-06-01 09:01:00', 1, NULL, '{}'),
  (72, 't1', 'trigger', 'mailpoet:someone-subscribes', 'complete', '2026-11-19 11:58:00', 1, NULL, '{}'),
  (72, 'd1', 'action', 'core:delay', 'complete', '2026-11-19 11:58:00', 1, NULL, '{}');
INSERT INTO wp_actionscheduler_groups (group_id, slug) VALUES
  (1, 'mailpoet-automation');
INSERT INTO wp_actionscheduler_actions (action_id, hook, status, scheduled_date_gmt, scheduled_date_local, args, group_id) VALUES
  (91, 'mailpoet/automation/step', 'pending', '2026-11-19 18:58:00', '2026-11-19 20:58:00', '[{"automation_run_id":72,"step_id":"e1","run_number":1}]', 1);
