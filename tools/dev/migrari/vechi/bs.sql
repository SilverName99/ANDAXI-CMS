-- Fixture-ul migrărilor pentru Bioscem (cod bs): schema pe care o face codul vechi pe o bază goală:
-- scripts/install.php, apoi funcțiile ensure…/asigura… până nu se mai schimbă (MariaDB 10.11.14-MariaDB-0ubuntu0.24.04.1).
-- Doar structură, niciun rând; nimic din datele unui site. Îl încarcă tools/dev/test-migrari.php (T2).
-- sursa: Nou-site-bioscem@070e1af
-- Generat de tools/dev/migrari/genereaza-fixture.php --fixture-uri; nu se editează de mână.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'administrator_general',
  `roles_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_activity_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `admin_email` varchar(190) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `context_json` longtext DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blog_authors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `bio` text DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blog_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `slug` varchar(170) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blog_posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `excerpt` text DEFAULT NULL,
  `category` varchar(190) DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `event_start_date` date DEFAULT NULL,
  `event_end_date` date DEFAULT NULL,
  `event_price` varchar(120) DEFAULT NULL,
  `event_ticket_url` varchar(500) DEFAULT NULL,
  `event_location` varchar(190) DEFAULT NULL,
  `content` longtext NOT NULL,
  `reading_minutes` int(10) unsigned NOT NULL DEFAULT 1,
  `published_at` datetime NOT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `template_id` int(10) unsigned DEFAULT NULL,
  `author_id` int(10) unsigned DEFAULT NULL,
  `featured_image_url` varchar(255) DEFAULT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_blog_posts_published` (`is_published`,`published_at`),
  KEY `idx_blog_posts_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blog_post_categories` (
  `post_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`post_id`,`category_id`),
  KEY `idx_bpc_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blog_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `html_content` longtext NOT NULL,
  `css_content` longtext DEFAULT NULL,
  `js_content` longtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bt_ipay_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tx_id` int(10) unsigned DEFAULT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `mode` varchar(4) NOT NULL DEFAULT '',
  `action` varchar(40) NOT NULL,
  `source` varchar(30) NOT NULL DEFAULT '',
  `http_code` smallint(6) DEFAULT NULL,
  `error_code` varchar(20) DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `request_json` mediumtext DEFAULT NULL,
  `response_json` mediumtext DEFAULT NULL,
  `message` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bt_ipay_log_created` (`created_at`),
  KEY `idx_bt_ipay_log_tx` (`tx_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bt_ipay_transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned DEFAULT NULL,
  `link_id` int(10) unsigned DEFAULT NULL,
  `kind` varchar(10) NOT NULL DEFAULT 'order',
  `mode` varchar(4) NOT NULL DEFAULT 'test',
  `bt_order_number` varchar(40) NOT NULL,
  `bt_order_id` varchar(64) DEFAULT NULL,
  `form_url` varchar(1000) DEFAULT NULL,
  `loy_order_id` varchar(64) DEFAULT NULL,
  `amount_minor` int(10) unsigned NOT NULL DEFAULT 0,
  `currency` smallint(5) unsigned NOT NULL DEFAULT 946,
  `approved_minor` int(10) unsigned NOT NULL DEFAULT 0,
  `loy_minor` int(10) unsigned NOT NULL DEFAULT 0,
  `deposited_minor` int(10) unsigned NOT NULL DEFAULT 0,
  `loy_deposited_minor` int(10) unsigned NOT NULL DEFAULT 0,
  `refunded_minor` int(10) unsigned NOT NULL DEFAULT 0,
  `loy_refunded_minor` int(10) unsigned NOT NULL DEFAULT 0,
  `state` varchar(20) NOT NULL DEFAULT 'registered',
  `bt_status` tinyint(4) DEFAULT NULL,
  `loy_status` tinyint(4) DEFAULT NULL,
  `in_comanda` tinyint(1) NOT NULL DEFAULT 0,
  `action_code` int(11) DEFAULT NULL,
  `action_desc` varchar(255) DEFAULT NULL,
  `approval_code` varchar(20) DEFAULT NULL,
  `refund_reference` varchar(64) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `op_pending` text DEFAULT NULL,
  `alerts_json` text DEFAULT NULL,
  `deposit_requested_at` datetime DEFAULT NULL,
  `deposit_requested_minor` int(10) unsigned DEFAULT NULL,
  `deposit_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `reminder_sent_at` datetime DEFAULT NULL,
  `authorized_at` datetime DEFAULT NULL,
  `deposited_at` datetime DEFAULT NULL,
  `reversed_at` datetime DEFAULT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `public_checked_at` datetime DEFAULT NULL,
  `callback_checked_at` datetime DEFAULT NULL,
  `last_status_json` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bt_ipay_tx_number` (`bt_order_number`),
  UNIQUE KEY `uq_bt_ipay_tx_bt_id` (`bt_order_id`),
  KEY `idx_bt_ipay_tx_order` (`order_id`),
  KEY `idx_bt_ipay_tx_link` (`link_id`),
  KEY `idx_bt_ipay_tx_state` (`state`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cart_abandonments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(190) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `customer_name` varchar(190) DEFAULT NULL,
  `cart_snapshot` longtext DEFAULT NULL,
  `last_seen_at` datetime NOT NULL,
  `converted_at` datetime DEFAULT NULL,
  `abandoned_email_sent_at` datetime DEFAULT NULL,
  `abandoned_email_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `checkout_submit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'allowed',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_checkout_submit_logs_ip_created` (`ip_address`,`created_at`),
  KEY `idx_checkout_submit_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contact_form_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `message` longtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `source_url` varchar(500) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contact_form_messages_created` (`created_at`),
  KEY `idx_contact_form_messages_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `coupons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `code` varchar(100) NOT NULL,
  `type` enum('fixed','percent') NOT NULL DEFAULT 'fixed',
  `value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `product_ids_json` longtext DEFAULT NULL,
  `category_ids_json` longtext DEFAULT NULL,
  `min_items_count` int(10) unsigned NOT NULL DEFAULT 0,
  `max_items_count` int(10) unsigned DEFAULT NULL,
  `max_uses_total` int(10) unsigned DEFAULT NULL,
  `allowed_user_ids_json` longtext DEFAULT NULL,
  `apply_only_selected_products` tinyint(1) NOT NULL DEFAULT 0,
  `is_unique` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `import_batch` varchar(190) DEFAULT NULL,
  `stacks_with_discounts` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `token_hash` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned DEFAULT NULL,
  `email_type` varchar(80) NOT NULL,
  `recipient` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_email_type` (`order_id`,`email_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_send_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned DEFAULT NULL,
  `email_type` varchar(80) NOT NULL,
  `source` varchar(80) NOT NULL DEFAULT 'system',
  `recipient` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `provider` varchar(30) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_send_history_created` (`created_at`),
  KEY `idx_email_send_history_type` (`email_type`),
  KEY `idx_email_send_history_status` (`status`),
  KEY `idx_email_send_history_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fan_localities` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `county` varchar(120) NOT NULL,
  `locality` varchar(190) NOT NULL,
  `county_norm` varchar(120) NOT NULL,
  `locality_norm` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fan_localities` (`county_norm`,`locality_norm`),
  KEY `idx_fan_localities_county` (`county_norm`),
  KEY `idx_fan_localities_locality` (`locality_norm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fan_localities_extra_km` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `county` varchar(120) NOT NULL,
  `locality` varchar(190) NOT NULL,
  `county_norm` varchar(120) NOT NULL,
  `locality_norm` varchar(190) NOT NULL,
  `exterior_km` decimal(8,2) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fan_localities_extra_km` (`county_norm`,`locality_norm`),
  KEY `idx_fan_localities_extra_km_county` (`county_norm`),
  KEY `idx_fan_localities_extra_km_locality` (`locality_norm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fan_lockers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `fan_id` varchar(60) NOT NULL DEFAULT '',
  `name` varchar(190) NOT NULL,
  `county` varchar(120) NOT NULL,
  `locality` varchar(190) NOT NULL,
  `address` varchar(255) NOT NULL DEFAULT '',
  `postcode` varchar(20) NOT NULL DEFAULT '',
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `county_norm` varchar(120) NOT NULL,
  `locality_norm` varchar(190) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fan_lockers_code` (`code`),
  KEY `idx_fan_lockers_county` (`county_norm`),
  KEY `idx_fan_lockers_locality` (`county_norm`,`locality_norm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fan_nomenclator_sync` (
  `lista` varchar(20) NOT NULL,
  `stare` varchar(20) NOT NULL DEFAULT '',
  `sursa` varchar(20) NOT NULL DEFAULT '',
  `cursor_json` mediumtext DEFAULT NULL,
  `pas` int(10) unsigned NOT NULL DEFAULT 0,
  `pasi_total` int(10) unsigned NOT NULL DEFAULT 0,
  `randuri` int(10) unsigned NOT NULL DEFAULT 0,
  `pornit_la` datetime DEFAULT NULL,
  `activ_la` datetime DEFAULT NULL,
  `reusit_la` datetime DEFAULT NULL,
  `reusit_sursa` varchar(20) NOT NULL DEFAULT '',
  `reusit_randuri` int(10) unsigned NOT NULL DEFAULT 0,
  `reusit_detalii` varchar(255) NOT NULL DEFAULT '',
  `eroare` text DEFAULT NULL,
  `eroare_la` datetime DEFAULT NULL,
  PRIMARY KEY (`lista`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fan_streets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `county` varchar(120) NOT NULL,
  `locality` varchar(190) NOT NULL,
  `street` varchar(255) NOT NULL,
  `street_id` varchar(64) NOT NULL DEFAULT '',
  `range_from` varchar(40) NOT NULL DEFAULT '',
  `range_to` varchar(40) NOT NULL DEFAULT '',
  `parity` varchar(32) NOT NULL DEFAULT '',
  `postal_code` varchar(32) NOT NULL DEFAULT '',
  `street_type` varchar(80) NOT NULL DEFAULT '',
  `agency` varchar(160) NOT NULL DEFAULT '',
  `county_norm` varchar(120) NOT NULL,
  `locality_norm` varchar(190) NOT NULL,
  `street_norm` varchar(255) NOT NULL,
  `row_key` char(40) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fan_streets_row` (`row_key`),
  KEY `idx_fan_streets_county` (`county_norm`),
  KEY `idx_fan_streets_locality` (`locality_norm`),
  KEY `idx_fan_streets_street` (`street_norm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gallery_folders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `slug` varchar(140) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gallery_images` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(190) NOT NULL,
  `media_type` varchar(20) NOT NULL DEFAULT 'image',
  `image_url` text NOT NULL,
  `folder_id` int(10) unsigned DEFAULT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gdpr_agreements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subiect_nume_complet` varchar(190) NOT NULL,
  `ci_serie` varchar(30) NOT NULL,
  `ci_numar` varchar(40) NOT NULL,
  `ci_emitent` varchar(190) NOT NULL,
  `ci_data_eliberare` date DEFAULT NULL,
  `nume` varchar(120) NOT NULL,
  `prenume` varchar(120) NOT NULL,
  `cnp` varchar(20) NOT NULL,
  `cuim` varchar(50) NOT NULL,
  `telefon` varchar(60) NOT NULL,
  `email` varchar(190) NOT NULL,
  `adresa_corespondenta` varchar(255) NOT NULL,
  `institutie_medicala` varchar(255) NOT NULL,
  `institutie_activitate` varchar(255) NOT NULL,
  `institutie_adresa` varchar(255) NOT NULL,
  `institutie_activitate_adresa` varchar(255) NOT NULL,
  `tip_medic` varchar(120) NOT NULL,
  `specializare` varchar(190) NOT NULL,
  `data_semnare` date DEFAULT NULL,
  `nume_semnatura` varchar(190) NOT NULL,
  `signature_data_url` longtext NOT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_gdpr_agreements_created` (`created_at`),
  KEY `idx_gdpr_agreements_name` (`nume`,`prenume`),
  KEY `idx_gdpr_agreements_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `loyalty_points_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `multiplier` decimal(6,2) NOT NULL DEFAULT 1.00,
  `min_order_total` decimal(10,2) DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rules_active_window` (`is_active`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `loyalty_points_transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `tx_type` varchar(50) NOT NULL,
  `points_delta` int(11) NOT NULL,
  `balance_after` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_order_type` (`user_id`,`order_id`,`tx_type`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_order_type` (`order_id`,`tx_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mannequin_points` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `point_key` varchar(120) NOT NULL,
  `label` varchar(190) NOT NULL,
  `x_percent` decimal(6,2) NOT NULL DEFAULT 50.00,
  `y_percent` decimal(6,2) NOT NULL DEFAULT 50.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `point_key` (`point_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mannequin_point_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `point_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mannequin_point_product` (`point_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `newsletter_campaigns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `template_type` varchar(20) NOT NULL DEFAULT 'newsletter',
  `template_ref` varchar(190) DEFAULT NULL,
  `subscriber_list_id` int(10) unsigned DEFAULT NULL,
  `subscriber_list_ids` text DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `blocks_json` longtext DEFAULT NULL,
  `html_content` longtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `total_recipients` int(11) NOT NULL DEFAULT 0,
  `total_sent` int(11) NOT NULL DEFAULT 0,
  `total_failed` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_opens` int(10) unsigned DEFAULT 0,
  `total_clicks` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `newsletter_campaign_clicks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int(10) unsigned NOT NULL,
  `subscriber_id` int(10) unsigned DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `url` varchar(2048) NOT NULL,
  `clicked_at` datetime NOT NULL,
  `ip` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `newsletter_campaign_opens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int(10) unsigned NOT NULL,
  `send_id` int(10) unsigned DEFAULT NULL,
  `opened_at` datetime NOT NULL,
  `ip` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `newsletter_campaign_sends` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int(10) unsigned NOT NULL,
  `subscriber_id` int(10) unsigned DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `opened_at` datetime DEFAULT NULL,
  `open_count` int(10) unsigned DEFAULT 0,
  `clicked_at` datetime DEFAULT NULL,
  `click_count` int(10) unsigned DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_campaign_subscriber` (`campaign_id`,`subscriber_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `newsletter_lists` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `newsletter_list_subscribers` (
  `list_id` int(10) unsigned NOT NULL,
  `subscriber_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`list_id`,`subscriber_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `newsletter_optin_forms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `list_id` int(10) unsigned NOT NULL,
  `button_label` varchar(120) NOT NULL DEFAULT 'Ma abonez',
  `success_message` varchar(255) NOT NULL DEFAULT 'Te-ai abonat cu succes.',
  `fields_json` longtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `newsletter_subscribers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `name` varchar(190) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `newsletter_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `blocks_json` longtext NOT NULL,
  `html_content` longtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `preorder_status` varchar(12) DEFAULT NULL,
  `preorder_released_at` datetime DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'euplatesc',
  `payment_status` varchar(50) NOT NULL DEFAULT 'unpaid',
  `stripe_session_id` varchar(255) DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
  `euplatesc_ep_id` varchar(64) DEFAULT NULL,
  `euplatesc_approval` varchar(64) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_error` text DEFAULT NULL,
  `shipping_method` varchar(50) NOT NULL DEFAULT 'fan_courier',
  `shipping_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `shipping_manual` tinyint(1) NOT NULL DEFAULT 0,
  `discount_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `loyalty_points_used` int(11) NOT NULL DEFAULT 0,
  `loyalty_points_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `loyalty_points_awarded` int(11) NOT NULL DEFAULT 0,
  `loyalty_points_pending_claim` int(11) NOT NULL DEFAULT 0,
  `loyalty_points_awarded_at` datetime DEFAULT NULL,
  `coupon_code` varchar(100) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_first_name` varchar(100) NOT NULL,
  `billing_last_name` varchar(100) NOT NULL,
  `billing_phone` varchar(30) NOT NULL,
  `billing_email` varchar(190) NOT NULL,
  `billing_address_line1` varchar(255) NOT NULL,
  `billing_address_line2` varchar(255) DEFAULT NULL,
  `billing_city` varchar(120) NOT NULL,
  `billing_county` varchar(120) NOT NULL,
  `billing_postcode` varchar(20) NOT NULL,
  `billing_is_company` tinyint(1) NOT NULL DEFAULT 0,
  `billing_company_name` varchar(255) DEFAULT NULL,
  `billing_company_tax_id` varchar(120) DEFAULT NULL,
  `billing_company_registration_no` varchar(120) DEFAULT NULL,
  `shipping_same_as_billing` tinyint(1) NOT NULL DEFAULT 1,
  `shipping_first_name` varchar(190) DEFAULT NULL,
  `shipping_last_name` varchar(190) DEFAULT NULL,
  `shipping_phone` varchar(50) DEFAULT NULL,
  `shipping_address_line1` varchar(255) DEFAULT NULL,
  `shipping_city` varchar(190) DEFAULT NULL,
  `shipping_county` varchar(190) DEFAULT NULL,
  `shipping_postcode` varchar(20) DEFAULT NULL,
  `fan_locker_id` int(10) unsigned DEFAULT NULL,
  `fan_locker_name` varchar(190) DEFAULT NULL,
  `fan_locker_address` varchar(255) DEFAULT NULL,
  `fan_locker_city` varchar(190) DEFAULT NULL,
  `fan_locker_county` varchar(190) DEFAULT NULL,
  `fan_locker_postcode` varchar(20) DEFAULT NULL,
  `shipping_address_line2` varchar(255) DEFAULT NULL,
  `fan_awb` varchar(100) DEFAULT NULL,
  `fan_tracking_url` text DEFAULT NULL,
  `fan_tracking_status` varchar(190) DEFAULT NULL,
  `fan_tracking_last_event_at` datetime DEFAULT NULL,
  `fan_tracking_synced_at` datetime DEFAULT NULL,
  `completed_awb_email_sent_at` datetime DEFAULT NULL,
  `completed_awb_email_error` text DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ad_source` varchar(50) DEFAULT NULL,
  `ad_click_id` varchar(255) DEFAULT NULL,
  `nl_campaign_id` int(10) unsigned DEFAULT NULL,
  `erp_status` varchar(20) NOT NULL DEFAULT 'pending',
  `erp_order_id` varchar(32) DEFAULT NULL,
  `erp_attempts` int(11) NOT NULL DEFAULT 0,
  `erp_last_error` text DEFAULT NULL,
  `erp_last_attempt_at` datetime DEFAULT NULL,
  `erp_next_retry_at` datetime DEFAULT NULL,
  `erp_synced_at` datetime DEFAULT NULL,
  `erp_problems` text DEFAULT NULL,
  `erp_factura_numar` varchar(40) DEFAULT NULL,
  `fan_awb_list` text DEFAULT NULL,
  `paid_amount` decimal(10,2) DEFAULT NULL,
  `edit_unlocked_at` datetime DEFAULT NULL,
  `edit_unlock_reason` varchar(500) DEFAULT NULL,
  `edit_unlocked_by` varchar(190) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `erp_client_agresiv` tinyint(1) DEFAULT NULL,
  `erp_client_agresiv_versiune` bigint(20) DEFAULT NULL,
  `fan_awb_inlocuite` text DEFAULT NULL,
  `manual_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `manual_discount_percent` decimal(5,2) DEFAULT NULL,
  `manual_discount_reason` varchar(190) DEFAULT NULL,
  `terms_accepted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `fk_orders_user` (`user_id`),
  KEY `idx_nl_campaign_id` (`nl_campaign_id`),
  KEY `idx_orders_erp_status` (`erp_status`,`erp_next_retry_at`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned DEFAULT NULL,
  `product_name` varchar(190) NOT NULL,
  `bbd_key` varchar(64) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `erp_discount_percent` decimal(5,2) DEFAULT NULL,
  `erp_price_before` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_order_items_order` (`order_id`),
  KEY `fk_order_items_product` (`product_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_payment_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `referinta` varchar(60) NOT NULL,
  `token` varchar(64) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `procesator` varchar(20) NOT NULL DEFAULT 'euplatesc',
  `ep_id` varchar(64) DEFAULT NULL,
  `approval` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_payment_links_referinta` (`referinta`),
  UNIQUE KEY `uq_order_payment_links_token` (`token`),
  KEY `idx_order_payment_links_order` (`order_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_product_warnings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `body` text NOT NULL,
  `checkbox_label` text NOT NULL,
  `checkbox_ticked` tinyint(1) NOT NULL DEFAULT 1,
  `product_ids_json` text NOT NULL,
  `product_names_json` text NOT NULL,
  `confirmed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_product_warnings_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `order_promo_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `promo_product_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(190) NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_opi_order` (`order_id`),
  KEY `idx_opi_promo` (`promo_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(190) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `html_content` longtext NOT NULL,
  `css_content` longtext DEFAULT NULL,
  `js_content` longtext DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `category` varchar(120) DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `product_template_id` int(10) unsigned DEFAULT NULL,
  `slug` varchar(190) NOT NULL,
  `short_description` text DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `product_highlights` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_percent` decimal(5,2) NOT NULL DEFAULT 19.00,
  `vat_included` tinyint(1) NOT NULL DEFAULT 1,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `sale_price_periods_json` longtext DEFAULT NULL,
  `discount_badge_mode` varchar(10) NOT NULL DEFAULT 'percent',
  `bbd_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `bbd_entries_json` longtext DEFAULT NULL,
  `post_cart_note_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `post_cart_note_text` text DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `out_of_stock` tinyint(1) NOT NULL DEFAULT 0,
  `preorder_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `preorder_max_per_order` int(10) unsigned DEFAULT NULL,
  `preorder_max_total` int(10) unsigned DEFAULT NULL,
  `weight_grams` int(11) DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `gallery_json` longtext DEFAULT NULL,
  `gallery_images_json` longtext DEFAULT NULL,
  `similar_products_json` longtext DEFAULT NULL,
  `badge_popular` tinyint(1) NOT NULL DEFAULT 0,
  `badge_best_seller` tinyint(1) NOT NULL DEFAULT 0,
  `badge_seasonal` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `brand` varchar(120) DEFAULT NULL,
  `tags_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `slug` varchar(140) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_category_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_category` (`product_id`,`category_id`),
  KEY `idx_pcl_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_extra_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `field_key` varchar(120) NOT NULL,
  `field_type` varchar(30) NOT NULL DEFAULT 'textarea',
  `placeholder` varchar(255) DEFAULT NULL,
  `default_value` longtext DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `field_key` (`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_extra_field_values` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `field_id` int(10) unsigned NOT NULL,
  `value` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_field` (`product_id`,`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_reviews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `user_name` varchar(120) NOT NULL,
  `user_email` varchar(190) DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `review_text` text DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `source` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_product_reviews_product` (`product_id`),
  KEY `idx_product_reviews_approved` (`is_approved`),
  CONSTRAINT `fk_product_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `html_content` longtext NOT NULL,
  `css_content` longtext DEFAULT NULL,
  `js_content` longtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_template_fields` (
  `template_id` int(10) unsigned NOT NULL,
  `field_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`template_id`,`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_warnings` (
  `product_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `body` text DEFAULT NULL,
  `checkbox_label` varchar(500) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `promotional_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `register_submit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'allowed',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_register_submit_logs_ip_created` (`ip_address`,`created_at`),
  KEY `idx_register_submit_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reviews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `user_name` varchar(120) NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `review_text` text DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_reviews_product` (`product_id`),
  CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `seo_pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `page_type` varchar(50) NOT NULL,
  `page_ref` varchar(190) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `canonical_url` varchar(255) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page_ref` (`page_type`,`page_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `key` varchar(120) NOT NULL,
  `value` longtext NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `google_id` varchar(120) DEFAULT NULL,
  `loyalty_points` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uniq_users_google_id` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_addresses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `full_name` varchar(190) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `street` varchar(190) DEFAULT NULL,
  `street_no` varchar(40) DEFAULT NULL,
  `address_line1` varchar(255) NOT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(120) NOT NULL,
  `county` varchar(120) NOT NULL,
  `postcode` varchar(20) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_user_addresses_user` (`user_id`),
  CONSTRAINT `fk_user_addresses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
