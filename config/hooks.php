<?php

declare(strict_types=1);

use App\Http\Controllers\DonatiiController;
use App\Http\Controllers\MembriController;
use App\Support\AcasaAdmin;
use App\Support\Campanii;
use App\Support\Statistici;

/**
 * Agățările pluginurilor de punctele nucleului (App\Support\Hooks; lista punctelor și cine le cheamă e acolo).
 * Fiecare rând: ['punct' => '<punct>', 'plugin' => '<cheia din config/plugins.php>', 'fn' => <funcție>,
 * opțional 'stare' => 'activ' (doar cu pluginul pornit; implicit „vizibil”: pornit sau se oprește)].
 * O agățare a unui plugin pe care site-ul nu-l are nu rulează. Cel mult o agățare pe plugin și punct (Hooks::ruleaza
 * ține rezultatele după cheia pluginului).
 *
 * Pluginurile care se agață de punctele contului și ale conținutului (`membri`, `donatii`, din vertij, Etapa 4; port-vertij
 * §5, §6): înscrierea (doar cu pluginul pornit: o înscriere nouă e „ceva nou”), linkul de confirmare, contul, conținutul pentru
 * membri, editoarele, ștergerea unui cont, lista de utilizatori, sertarul donațiilor. Din WP-32 (faza D), pagina Acasă a
 * adminului: lista „De făcut” și rândul de sănătate, fiecare bucată a pluginului ei (App\Support\AcasaAdmin).
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
    // Campaniile (5 nov 2026): bannerul campaniei active și, pe pagina campaniei, butonul „Înapoi în site-ul principal”.
    ['punct' => 'layout.inceput_body', 'plugin' => 'campanii', 'fn' => [Campanii::class, 'inceputBody']],
    // Statisticile (8 nov 2026): scriptul care trimite pagina văzută (fără cookie), înainte de </body> pe paginile publice.
    ['punct' => 'layout.final_body', 'plugin' => 'statistici', 'fn' => [Statistici::class, 'scriptPagina']],
    // Etapa 4 (vertij): membrii.
    ['punct' => 'inregistrare.inlocuieste_campurile', 'plugin' => 'membri', 'fn' => [MembriController::class, 'inlocuiesteCampurile'], 'stare' => 'activ'],
    ['punct' => 'inregistrare.formular', 'plugin' => 'membri', 'fn' => [MembriController::class, 'formular'], 'stare' => 'activ'],
    ['punct' => 'inregistrare.campuri', 'plugin' => 'membri', 'fn' => [MembriController::class, 'campuri'], 'stare' => 'activ'],
    ['punct' => 'inregistrare.valideaza', 'plugin' => 'membri', 'fn' => [MembriController::class, 'valideaza'], 'stare' => 'activ'],
    ['punct' => 'inregistrare.cont_existent', 'plugin' => 'membri', 'fn' => [MembriController::class, 'contExistent'], 'stare' => 'activ'],
    ['punct' => 'inregistrare.dupa_creare', 'plugin' => 'membri', 'fn' => [MembriController::class, 'dupaCreare'], 'stare' => 'activ'],
    ['punct' => 'confirmare.link_plugin', 'plugin' => 'membri', 'fn' => [MembriController::class, 'linkConfirmare']],
    ['punct' => 'confirmare.foloseste_plugin', 'plugin' => 'membri', 'fn' => [MembriController::class, 'folosesteLink']],
    ['punct' => 'confirmare.dupa', 'plugin' => 'membri', 'fn' => [MembriController::class, 'dupaConfirmareNucleu']],
    ['punct' => 'logare.blocata', 'plugin' => 'membri', 'fn' => [MembriController::class, 'logareBlocata']],
    ['punct' => 'cont.taburi', 'plugin' => 'membri', 'fn' => [MembriController::class, 'tabCont']],
    ['punct' => 'cont.antet', 'plugin' => 'membri', 'fn' => [MembriController::class, 'antetCont']],
    ['punct' => 'cont.profil', 'plugin' => 'membri', 'fn' => [MembriController::class, 'blocPoza']],
    ['punct' => 'continut.membru_activ', 'plugin' => 'membri', 'fn' => [MembriController::class, 'membruActiv']],
    ['punct' => 'continut.nota', 'plugin' => 'membri', 'fn' => [MembriController::class, 'nota']],
    ['punct' => 'continut.nota_titlu', 'plugin' => 'membri', 'fn' => [MembriController::class, 'titluNota']],
    ['punct' => 'editor.pagina.campuri', 'plugin' => 'membri', 'fn' => [MembriController::class, 'bifaEditorPagina']],
    ['punct' => 'editor.articol.campuri', 'plugin' => 'membri', 'fn' => [MembriController::class, 'bifaEditorArticol']],
    ['punct' => 'utilizator.sters', 'plugin' => 'membri', 'fn' => [MembriController::class, 'utilizatorSters']],
    ['punct' => 'admin.utilizatori.coloane', 'plugin' => 'membri', 'fn' => [MembriController::class, 'coloaneUtilizatori']],
    // Etapa 4 (vertij): donațiile.
    ['punct' => 'cont.taburi', 'plugin' => 'donatii', 'fn' => [DonatiiController::class, 'tabCont']],
    ['punct' => 'layout.final_body', 'plugin' => 'donatii', 'fn' => [DonatiiController::class, 'sertar']],
    // Ștergerea unui cont oprește donațiile lui lunare în Stripe (decizia proprietarului, 9 nov 2026).
    ['punct' => 'utilizator.sters', 'plugin' => 'donatii', 'fn' => [DonatiiController::class, 'utilizatorSters']],
];
