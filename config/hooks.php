<?php

declare(strict_types=1);

use App\Support\AcasaAdmin;

/**
 * Agățările pluginurilor de punctele nucleului (App\Support\Hooks; lista punctelor și cine le cheamă e acolo).
 * Fiecare rând: ['punct' => '<punct>', 'plugin' => '<cheia din config/plugins.php>', 'fn' => <funcție>,
 * opțional 'stare' => 'activ' (doar cu pluginul pornit; implicit „vizibil”: pornit sau se oprește)].
 * O agățare a unui plugin pe care site-ul nu-l are nu rulează. Cel mult o agățare pe plugin și punct (Hooks::ruleaza
 * ține rezultatele după cheia pluginului).
 *
 * Pluginurile care se agață de punctele contului și ale conținutului (`membri`, `donatii`) se portează în Etapa 4
 * (port-vertij §5, §6). Din WP-32 (faza D), pagina Acasă a adminului: lista „De făcut” și rândul de sănătate, fiecare
 * bucată a pluginului ei (App\Support\AcasaAdmin).
 */
return [
    // WP-32: „De făcut” pe Acasă.
    ['punct' => 'acasa.de_facut', 'plugin' => 'magazin', 'fn' => [AcasaAdmin::class, 'deFacutMagazin']],
    ['punct' => 'acasa.de_facut', 'plugin' => 'livrare_fan', 'fn' => [AcasaAdmin::class, 'deFacutFan']],
    ['punct' => 'acasa.de_facut', 'plugin' => 'erp', 'fn' => [AcasaAdmin::class, 'deFacutErp']],
    ['punct' => 'acasa.de_facut', 'plugin' => 'recenzii', 'fn' => [AcasaAdmin::class, 'deFacutRecenzii']],
    ['punct' => 'acasa.de_facut', 'plugin' => 'precomenzi', 'fn' => [AcasaAdmin::class, 'deFacutPrecomenzi']],
    ['punct' => 'acasa.de_facut', 'plugin' => 'link_plata', 'fn' => [AcasaAdmin::class, 'deFacutLinkPlata']],
    // WP-32: rândul de sănătate de pe Acasă.
    ['punct' => 'acasa.sanatate', 'plugin' => 'erp', 'fn' => [AcasaAdmin::class, 'sanatateErp']],
    ['punct' => 'acasa.sanatate', 'plugin' => 'livrare_fan', 'fn' => [AcasaAdmin::class, 'sanatateFan']],
];
