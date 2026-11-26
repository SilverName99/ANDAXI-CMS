<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

/**
 * Doar pentru teste (tools/dev/test-automatizari.php): motorul „cade” exact după ce a rezervat un email, înainte să-l trimită
 * sau să-l marcheze, ca un proces omorât. Rularea rămâne revendicată; trecerea următoare (după 15 minute) o reia fără
 * retrimitere. Pe un site opțiunea care o aruncă nu se dă niciodată.
 */
final class CadereSimulata extends \RuntimeException
{
}
