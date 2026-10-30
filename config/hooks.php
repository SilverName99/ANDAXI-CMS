<?php

declare(strict_types=1);

/**
 * Agățările pluginurilor de punctele nucleului (App\Support\Hooks; lista punctelor și cine le cheamă e acolo).
 * Fiecare rând: ['punct' => '<punct>', 'plugin' => '<cheia din config/plugins.php>', 'fn' => <funcție>,
 * opțional 'stare' => 'activ' (doar cu pluginul pornit; implicit „vizibil”: pornit sau se oprește)].
 * O agățare a unui plugin pe care site-ul nu-l are nu rulează.
 *
 * Gol în Etapa 1: pluginurile care se agață (`membri`, `donatii`) se portează în Etapa 4 (port-vertij §5, §6).
 */
return [];
