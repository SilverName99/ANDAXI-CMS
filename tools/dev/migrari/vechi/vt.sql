-- Fixture-ul migrărilor pentru vertij (cod vt): schema pe care o face codul vechi pe o bază goală:
-- scripts/install.php, apoi funcțiile ensure…/asigura… până nu se mai schimbă (MariaDB 10.11.14-MariaDB-0ubuntu0.24.04.1).
-- Doar structură, niciun rând; nimic din datele unui site. Îl încarcă tools/dev/test-migrari.php (T2).
-- sursa: vertij-tinitus-refacut@0fc2b2c
-- Generat de tools/dev/migrari/genereaza-fixture.php --fixture-uri; nu se editează de mână.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `account_activations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `profession_id` int(10) unsigned DEFAULT NULL,
  `profession_name` varchar(190) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'ron',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `months` int(10) unsigned NOT NULL DEFAULT 12,
  `stripe_session_id` varchar(255) DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
  `period_start` datetime DEFAULT NULL,
  `period_end` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `receipt_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_activations_user` (`user_id`,`status`),
  KEY `idx_account_activations_session` (`stripe_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_verifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `profession_id` int(10) unsigned DEFAULT NULL,
  `profession_name` varchar(190) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'awaiting_email',
  `answers_json` longtext DEFAULT NULL,
  `gdpr_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `gdpr_accepted_at` datetime DEFAULT NULL,
  `submitted_ip` varchar(64) DEFAULT NULL,
  `submitted_user_agent` varchar(255) DEFAULT NULL,
  `confirm_token_hash` varchar(128) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_account_verifications_user` (`user_id`),
  KEY `idx_account_verifications_status` (`status`,`created_at`),
  KEY `idx_account_verifications_token` (`confirm_token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_verification_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `verification_id` int(10) unsigned NOT NULL,
  `field_key` varchar(100) DEFAULT NULL,
  `field_label` varchar(190) DEFAULT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_account_documents_verification` (`verification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `requires_active_account` tinyint(1) NOT NULL DEFAULT 0,
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

CREATE TABLE `donations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(40) NOT NULL,
  `donor_name` varchar(190) DEFAULT NULL,
  `donor_email` varchar(190) DEFAULT NULL,
  `amount_cents` int(10) unsigned NOT NULL DEFAULT 0,
  `fee_cents` int(10) unsigned NOT NULL DEFAULT 0,
  `currency` varchar(10) NOT NULL DEFAULT 'ron',
  `frequency` enum('once','monthly') NOT NULL DEFAULT 'once',
  `designation` varchar(190) DEFAULT NULL,
  `cover_fees` tinyint(1) NOT NULL DEFAULT 0,
  `newsletter_optin` tinyint(1) NOT NULL DEFAULT 0,
  `consent` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
  `stripe_subscription_id` varchar(255) DEFAULT NULL,
  `stripe_customer_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `stripe_invoice_id` varchar(255) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `receipt_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  UNIQUE KEY `idx_donations_invoice` (`stripe_invoice_id`),
  KEY `idx_donations_pi` (`stripe_payment_intent_id`),
  KEY `idx_donations_status` (`status`),
  KEY `idx_donations_user` (`user_id`),
  KEY `idx_donations_sub` (`stripe_subscription_id`),
  KEY `idx_donations_email` (`donor_email`)
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
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fan_localities_extra_km` (`county_norm`,`locality_norm`),
  KEY `idx_fan_localities_extra_km_county` (`county_norm`),
  KEY `idx_fan_localities_extra_km_locality` (`locality_norm`)
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
  `payment_method` varchar(50) NOT NULL DEFAULT 'stripe',
  `payment_status` varchar(50) NOT NULL DEFAULT 'unpaid',
  `stripe_session_id` varchar(255) DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_error` text DEFAULT NULL,
  `shipping_method` varchar(50) NOT NULL DEFAULT 'fan_courier',
  `shipping_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
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
  `nl_campaign_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `fk_orders_user` (`user_id`),
  KEY `idx_nl_campaign_id` (`nl_campaign_id`),
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
  PRIMARY KEY (`id`),
  KEY `fk_order_items_order` (`order_id`),
  KEY `fk_order_items_product` (`product_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(190) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `html_content` longtext NOT NULL,
  `css_content` longtext DEFAULT NULL,
  `js_content` longtext DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `requires_active_account` tinyint(1) NOT NULL DEFAULT 0,
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
  `bbd_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `bbd_entries_json` longtext DEFAULT NULL,
  `post_cart_note_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `post_cart_note_text` text DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `out_of_stock` tinyint(1) NOT NULL DEFAULT 0,
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

CREATE TABLE `professions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `activation_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `activation_months` int(10) unsigned NOT NULL DEFAULT 12,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `profession_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `profession_id` int(10) unsigned NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `label` varchar(190) NOT NULL,
  `field_type` varchar(20) NOT NULL DEFAULT 'text',
  `options_json` longtext DEFAULT NULL,
  `help_text` varchar(255) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_profession_field` (`profession_id`,`field_key`),
  KEY `idx_profession_fields_profession` (`profession_id`,`sort_order`)
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
  `profession_id` int(10) unsigned DEFAULT NULL,
  `account_status` varchar(20) NOT NULL DEFAULT 'none',
  `account_active_until` datetime DEFAULT NULL,
  `member_code` varchar(32) DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `stripe_customer_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `member_code` (`member_code`),
  UNIQUE KEY `uniq_users_member_code` (`member_code`),
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
