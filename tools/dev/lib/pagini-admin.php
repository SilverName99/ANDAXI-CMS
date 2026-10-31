<?php

declare(strict_types=1);

/**
 * Paginile adminului pe care le verifică contractul adminului (tools/dev/contract-admin.php, poarta G-admin, WP-29)
 * și, din WP-31, plimbarea cu tastatura (tools/dev/plimbare-tastatura.cjs): fiecare pagină de admin din lista
 * comparației cu Bioscem (compara-cu-baza.php, rolurile adm*), plus restul paginilor la care se ajunge din meniu sau din
 * butoane (inventarul din docs/ARHITECTURA-ADMIN.md), ca refacerea adminului (faza D) să nu schimbe pe nicio pagină ce
 * trimite adminul. Adresele cu id-uri le iau din datele demo (tools/dev/date-demo.php).
 *
 * Întoarce [['cale' => '/admin/…', 'nume' => 'fișierul contractului', 'plugin' => proprietarul paginii], …].
 * Pagina de login (fără sesiune) e separată: pagini_admin_anonim().
 */

/** @return list<array{cale: string, nume: string, plugin: string}> */
function pagini_admin(PDO $db): array
{
    $unu = static function (string $sql) use ($db): string {
        $valoare = $db->query($sql)->fetchColumn();
        return $valoare === false || $valoare === null ? '' : (string) $valoare;
    };
    $nrComanda = $unu('SELECT order_number FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
    $idClient = $unu("SELECT id FROM users WHERE email = 'andrei.exemplu@exemplu.ro'");
    $idPagina = $unu("SELECT id FROM pages WHERE slug = '' LIMIT 1");
    $idCupon = $unu('SELECT id FROM coupons ORDER BY id LIMIT 1');
    $idSablonProdus = $unu('SELECT id FROM product_templates ORDER BY id LIMIT 1');
    $idPostare = $unu('SELECT id FROM blog_posts WHERE deleted_at IS NULL ORDER BY id LIMIT 1');

    // [cale, proprietar]: în ordinea grupurilor din meniul de dinainte (Dashboard, Utilizatori, Magazin, Configurare,
    // Jurnal, Pagini, Galerie, Design, Blog, Setări email, Automatizări, Email-uri), apoi paginile din butoane.
    $lista = [
        ['/admin', 'nucleu'],
        ['/admin/users', 'conturi_clienti'],
        ['/admin/users?panel=import', 'conturi_clienti'],
        ['/admin/users/' . $idClient, 'conturi_clienti'],
        ['/admin/users/settings', 'conturi_clienti'],
        ['/admin/users/points', 'fidelitate'],
        ['/admin/users/security', 'nucleu'],
        ['/admin/users/gdpr-agreements', 'acorduri_gdpr'],
        ['/admin/products', 'catalog'],
        ['/admin/products/new', 'catalog'],
        ['/admin/products/trash', 'catalog'],
        ['/admin/categories', 'catalog'],
        ['/admin/products/fields', 'catalog'],
        ['/admin/products/templates', 'catalog'],
        ['/admin/products/templates/new', 'catalog'],
        ['/admin/products/templates/' . $idSablonProdus . '/builder', 'catalog'],
        ['/admin/orders', 'magazin'],
        ['/admin/orders?search=' . rawurlencode($nrComanda), 'magazin'],
        ['/admin/orders/trash', 'magazin'],
        ['/admin/cereri-oferta', 'cereri_oferta'],
        ['/admin/coupons', 'cupoane'],
        ['/admin/coupons?coupon=' . $idCupon, 'cupoane'],
        ['/admin/coupons/unique', 'cupoane'],
        ['/admin/products/reviews', 'recenzii'],
        ['/admin/promo-products', 'produse_promo'],
        ['/admin/settings/floating-cart', 'magazin'],
        ['/admin/settings/store', 'nucleu'],
        ['/admin/settings/shipping', 'livrare_fan'],
        ['/admin/settings/payments', 'magazin'],
        ['/admin/settings/payments?tab=bt', 'plata_bt'],
        ['/admin/settings/erp', 'erp'],
        ['/admin/settings/mannequin', 'manechin'],
        ['/admin/settings/google', 'nucleu'],
        ['/admin/settings/admins', 'nucleu'],
        ['/admin/activity-log', 'nucleu'],
        ['/admin/pages', 'nucleu'],
        ['/admin/pages/new', 'nucleu'],
        ['/admin/pages/' . $idPagina . '/edit', 'nucleu'],
        ['/admin/pages/trash', 'nucleu'],
        ['/admin/gallery', 'nucleu'],
        ['/admin/design', 'nucleu'],
        ['/admin/blog/posts', 'blog'],
        ['/admin/blog/posts/new', 'blog'],
        ['/admin/blog/posts/editor?post=' . $idPostare, 'blog'],
        ['/admin/blog/posts/trash', 'blog'],
        ['/admin/blog/categories', 'blog'],
        ['/admin/blog/templates', 'blog'],
        ['/admin/blog/authors', 'blog'],
        ['/admin/emails/sender', 'nucleu'],
        ['/admin/emails/test', 'nucleu'],
        ['/admin/emails/automation', 'magazin'],
        ['/admin/emails/newsletters?tab=templates', 'newsletter'],
        ['/admin/emails/newsletters?tab=ecommerce', 'magazin'],
        ['/admin/emails/newsletters?tab=campaigns', 'newsletter'],
        ['/admin/emails/newsletters?tab=subscribers', 'newsletter'],
        ['/admin/emails/newsletters?tab=optin', 'newsletter'],
        ['/admin/emails/newsletters?tab=contact_forms', 'nucleu'],
        ['/admin/emails/newsletters?tab=history', 'nucleu'],
        ['/admin/emails/newsletters?tab=stats', 'newsletter'],
        ['/admin/emails/builder', 'newsletter'],
        ['/admin/emails/builder?type=newsletter', 'newsletter'],
        ['/admin/emails/builder?type=campaign&nou=1', 'newsletter'],
    ];
    $pagini = [];
    foreach ($lista as [$cale, $plugin]) {
        $nume = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(substr($cale, 1))), '-');
        $pagini[] = ['cale' => $cale, 'nume' => $nume, 'plugin' => $plugin];
    }
    return $pagini;
}

/** Paginile adminului fără sesiune (pagina de login). @return list<array{cale: string, nume: string, plugin: string}> */
function pagini_admin_anonim(): array
{
    return [['cale' => '/admin/login', 'nume' => 'admin-login', 'plugin' => 'nucleu']];
}
