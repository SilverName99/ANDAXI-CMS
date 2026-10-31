<?php
/**
 * Componentele de admin (faza D, WP-31; docs/ui/README.md): antet-pagina (cu ajutorul din spatele lui „?”),
 * tab-uri-cu-numar, bara-filtre, tabel (sortare, antet fix, bife), bara-actiuni-masa, panou-lateral (coloană sau sertar),
 * sectiune-formular, card, mesaj, stare-goala, bara-pasi. Primele au venit cu newsletterul (portat în 77759ef, docs/de-portat.md); WP-31 le-a extins, fără să schimbe ce primeau.
 *
 * Fiecare componentă e un fișier mic din acest dosar și primește doar ce i se dă explicit (fără variabile din pagina
 * care o cheamă). Stilul: public/assets/css/admin.css (clasele „adm-…”, variabilele „--ax-…”); comportamentul:
 * public/assets/js/admin/{componente,tabel,panou,filtre}.js, încărcate de views/admin/layout.php pe fiecare pagină.
 * Toate se văd împreună pe pagina de probă /admin/componente (views/admin/proba-componente.php).
 *
 * Folosire:  admin_componenta('mesaj', ['tip' => 'eroare', 'titlu' => '…']);
 */
if (!function_exists('admin_componenta')) {
    function admin_componenta(string $nume, array $date = []): void
    {
        if (!preg_match('/^[a-z-]+$/', $nume)) {
            return;
        }
        $fisier = __DIR__ . '/' . $nume . '.php';
        if (!is_file($fisier)) {
            return;
        }
        (static function (string $__fisier, array $date): void {
            extract($date, EXTR_SKIP);
            include $__fisier;
        })($fisier, $date);
    }

    /** Escapare scurtă pentru componente. */
    function adm_e(mixed $valoare): string
    {
        return htmlspecialchars((string) $valoare, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
