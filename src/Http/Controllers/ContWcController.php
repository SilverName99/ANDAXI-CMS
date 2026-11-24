<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Contul clientului în forma WooCommerce (`/my-account/…`; docs/MAGAZIN-WC.md, pista L): logarea, înregistrarea, parola uitată,
 * panoul, comenzile, descărcările, adresele, detaliile contului, metodele de plată și ieșirea, în documentul capturat al paginii
 * contului (regiunea `wc:cont`, `{{ax:clase_body}}`, `{{ax:titlu}}`).
 *
 * Rutele lui sunt condiționate de `wp-compat.json` → `magazin_wc` (Router::CONDITII); până la pista L răspund 404.
 */
final class ContWcController
{
    use PistaNeconstruita;

    /** `GET /my-account` și `GET /my-account/{endpoint*}`. */
    public function cont(array $params = []): void
    {
        $this->paginaNeconstruita();
    }

    /** `POST /my-account` și `POST /my-account/{endpoint*}`: formularele contului. */
    public function contTrimis(array $params = []): void
    {
        $this->paginaNeconstruita();
    }
}
