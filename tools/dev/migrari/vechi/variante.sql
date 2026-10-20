-- Variantele vechi ale schemei (testul T2, „baza veche”, din tools/dev/test-migrari.php): se încarcă peste
-- cms.sql și fac din ea baza unui site folosit de ani de zile, cu obiectele pe care codul de azi nu le mai
-- creează, dar pe care o bază live le poate avea (migrari.md §2.6), și câteva rânduri pentru migrările de date:
--   - coloanele vechi ale catalogului: products.image_gallery_json, label_popular/label_best_seller/label_seasonal
--     (lângă gallery_json, pe care schema codului o are), product_extra_fields.label/input_type,
--     product_templates.html_template/css_template/js_template, product_extra_field_values.value_text;
--   - coloanele vechi ale cupoanelor min_items/max_items;
--   - recenzii în tabelul vechi reviews (una deja copiată în product_reviews, una nu) și una fără sursă;
--   - fan_streets de dinainte de row_key, cu indexul unic vechi uniq_fan_streets;
--   - linkuri de plată de dinainte de coloana procesator (codul Biovitality și grafoanaytis);
--   - rămășițele unei sincronizări FAN întrerupte (fan_streets__sync, fan_localities__vechi), pe care
--     amprenta și migrările nu le ating;
--   - tabelele moarte ale manechinului în cealaltă formă (cea din install.php: pos_x/pos_y, cheie pe două coloane);
--   - admini cu roluri vechi, articole fără legătura la categoria lor.
-- Ce obiecte adaugă față de cms.sql: extra-variante.txt. Doar date inventate (@exemplu.ro, „Demo”), scrise de mână;
-- nimic dintr-un site real.
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE products ADD COLUMN image_gallery_json LONGTEXT NULL, ADD COLUMN label_popular TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN label_best_seller TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN label_seasonal TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE product_extra_fields ADD COLUMN label VARCHAR(190) NULL, ADD COLUMN input_type VARCHAR(30) NULL;
ALTER TABLE product_templates ADD COLUMN html_template LONGTEXT NULL, ADD COLUMN css_template LONGTEXT NULL, ADD COLUMN js_template LONGTEXT NULL;
ALTER TABLE product_extra_field_values ADD COLUMN value_text LONGTEXT NULL;
ALTER TABLE coupons ADD COLUMN min_items INT NULL, ADD COLUMN max_items INT NULL;
ALTER TABLE fan_streets DROP INDEX uniq_fan_streets_row, DROP COLUMN row_key,
    ADD UNIQUE KEY uniq_fan_streets (county_norm, locality_norm, street_norm, id);
ALTER TABLE order_payment_links DROP COLUMN procesator;
CREATE TABLE fan_streets__sync LIKE fan_streets;
CREATE TABLE fan_localities__vechi LIKE fan_localities;
DROP TABLE mannequin_point_products;
DROP TABLE mannequin_points;
CREATE TABLE mannequin_points (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(190) NOT NULL DEFAULT '',
  pos_x DECIMAL(6,2) NOT NULL DEFAULT 0,
  pos_y DECIMAL(6,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE mannequin_point_products (
  point_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (point_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES ('store_name', 'Firma Demo');

INSERT INTO admins (id, email, password_hash, role, roles_json) VALUES
    (2, 'vechi-gol@exemplu.ro', 'x', '', NULL),
    (3, 'vechi-blog@exemplu.ro', 'x', 'administrator_blog', NULL),
    (4, 'vechi-amestec@exemplu.ro', 'x', 'necunoscut', '["administrator_magazin","administrator_emailuri","x","administrator_magazin"]'),
    (5, 'vechi-general@exemplu.ro', 'x', 'administrator_general', '["administrator_general"]');

-- Produsele au id-uri rare (1, 2, 3, 1200), deci migrarea 0021 le ia în mai multe bucăți de câte 500.
INSERT INTO products (id, name, slug, gallery_json, image_gallery_json, gallery_images_json, label_popular, label_seasonal, badge_seasonal) VALUES
    (1, 'Produs Demo 1', 'produs-demo-1', '["/uploads/demo/a.png"]', NULL, NULL, 1, 0, 0),
    (2, 'Produs Demo 2', 'produs-demo-2', NULL, '["/uploads/demo/b.png"]', '', 0, 1, 0),
    (3, 'Produs Demo 3', 'produs-demo-3', '["/uploads/demo/c.png"]', '["/uploads/demo/d.png"]', '["/uploads/demo/pastrat.png"]', 0, 0, 0),
    (1200, 'Produs Demo 4', 'produs-demo-4', '["/uploads/demo/e.png"]', NULL, NULL, 1, 1, 1);
INSERT INTO product_extra_fields (id, name, field_key, field_type, label, input_type) VALUES
    (1, '', 'camp_demo_1', '', 'Eticheta veche', 'text'), (2, 'Nume nou', 'camp_demo_2', 'textarea', 'Alta veche', 'select');
INSERT INTO product_templates (id, name, slug, html_content, css_content, js_content, html_template, css_template, js_template) VALUES
    (1, 'Sablon demo', 'sablon-demo', '', NULL, 'pastrat()', '<p>vechi</p>', 'a{}', 'vechi()');
INSERT INTO product_extra_field_values (id, product_id, field_id, `value`, value_text) VALUES
    (1, 1, 1, NULL, 'valoare veche 1'), (2, 2, 1, 'pastrat', 'valoare veche 2'), (700, 3, 1, '', 'valoare veche 3');
INSERT INTO coupons (id, name, code, min_items, max_items, min_items_count, max_items_count) VALUES
    (1, '', 'DEMO-VECHI-1', 2, 5, 0, NULL), (2, 'Cupon demo', 'DEMO-VECHI-2', 3, NULL, 1, 9);
INSERT INTO reviews (id, product_id, user_name, rating, review_text, is_approved, created_at) VALUES
    (1, 1, 'Client Demo', 5, 'bun', 1, '2025-01-01 10:00:00'), (2, 2, 'Alt Client Demo', 4, NULL, 0, '2025-01-02 10:00:00');
INSERT INTO product_reviews (product_id, user_name, rating, review_text, is_approved, created_at, source) VALUES
    (1, 'Client Demo', 5, 'bun', 1, '2025-01-01 10:00:00', NULL);
INSERT INTO blog_categories (id, name, slug) VALUES (3, 'Categorie demo', 'categorie-demo'), (4, 'Alta demo', 'alta-demo');
INSERT INTO blog_posts (id, title, slug, content, published_at, category_id) VALUES
    (1, 'Articol demo A', 'articol-demo-a', 'x', '2025-02-01 10:00:00', 3),
    (2, 'Articol demo B', 'articol-demo-b', 'x', '2025-02-01 10:00:00', NULL),
    (3, 'Articol demo C', 'articol-demo-c', 'x', '2025-02-01 10:00:00', 4);
INSERT INTO blog_post_categories (post_id, category_id) VALUES (3, 4);
-- Străzile au id-uri rare (1, 2, 2500): pasul vechi al lui 0005 le ia pe bucăți de câte 1000.
INSERT INTO fan_streets (id, county, locality, street, county_norm, locality_norm, street_norm, created_at, updated_at) VALUES
    (1, 'Judet Demo', 'Localitate Demo', 'Strada Demo 1', 'judet demo', 'localitate demo', 'strada demo 1', '2025-03-01 10:00:00', '2025-03-01 10:00:00'),
    (2, 'Judet Demo', 'Localitate Demo', 'Strada Demo 2', 'judet demo', 'localitate demo', 'strada demo 2', '2025-03-01 10:00:00', '2025-03-01 10:00:00'),
    (2500, 'Judet Demo', 'Localitate Demo', 'Strada Demo 3', 'judet demo', 'localitate demo', 'strada demo 3', '2025-03-01 10:00:00', '2025-03-01 10:00:00');
INSERT INTO orders (id, order_number, billing_first_name, billing_last_name, billing_phone, billing_email, billing_address_line1,
        billing_city, billing_county, billing_postcode) VALUES
    (1, 'DEMO-1', 'Client', 'Demo', '0700000000', 'client.demo@exemplu.ro', 'Strada Demo 1', 'Localitate Demo', 'Judet Demo', '000000');
INSERT INTO order_payment_links (id, order_id, referinta, token, amount) VALUES
    (1, 1, 'DEMO-REF-1', 'demo-token-1', 10), (2, 1, 'DEMO-REF-2', 'demo-token-2', 20);

SET FOREIGN_KEY_CHECKS = 1;
