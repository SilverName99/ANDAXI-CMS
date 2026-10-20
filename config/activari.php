<?php

declare(strict_types=1);

/**
 * Pașii de activare ai pluginurilor (docs/MIGRARI.md, „Pașii de activare”; planul Etapei 1, WP-05,
 * G-m1). Nu sunt migrări: schema e aceeași pe orice site (fiecare migrare rulează peste tot), iar
 * conținutul pe care îl cere un plugin (o pagină, o setare de pornire) vine de aici, o singură dată
 * pe bază, când pluginul devine „pornit” (absent sau „oprit” înainte). Rulează
 * `App\Support\Activari::ruleaza()`: la prima cerere de după schimbare (public/index.php), la
 * instalare (scripts/install.php), în cronul ERP-ului (scripts/erp-sync.php) și în
 * tools/dev/licenta.php, doar pe o bază la zi cu migrările (pe una neadoptată sau în urma codului
 * așteaptă `scripts/migreaza.php --aplica`). Fiecare pas e idempotent: a doua rulare nu schimbă nimic.
 *
 * Pluginul => [numele pasului => [clasa, metoda]]. Metoda primește PDO-ul și întoarce un text scurt
 * (ce a făcut: „creată”, „exista”…), care intră în jurnalul de activitate al adminului.
 */

use App\Support\Activari;

return [
    // Pagina formularului de acorduri (tokenul {{gdpr_agreements_form}}), doar dacă nu există deloc:
    // una ștearsă de admin rămâne ștearsă (până acum o readucea fiecare cerere din admin).
    'acorduri_gdpr' => [
        'pagina_acorduri_gdpr' => [Activari::class, 'paginaAcorduriGdpr'],
    ],
    // Pragul comenzilor trimise în ERP (G-M8, WP-12 E1): pornit pe un site care nu l-a avut, ERP-ul ar
    // primi tot istoricul de comenzi „pending”. Doar dacă cheia lipsește cu totul din settings: o valoare
    // goală (înghețarea Bioscem: fără prag) rămâne neatinsă.
    'erp' => [
        'prag_trimitere_erp' => [Activari::class, 'pragTrimitereErp'],
    ],
];
