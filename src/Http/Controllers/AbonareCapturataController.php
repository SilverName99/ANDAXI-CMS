<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Abonarea din formularele Divi de pe paginile capturate (`et_pb_signup`, legate de listele MailPoet ale site-ului vechi) la listele
 * newsletterului (mutarea unui site WooCommerce, pista E; docs/MAGAZIN-WC.md): `POST /_andaxi/abonare/{n}`, `n` = numărul
 * formularului în pagină. Serverul e App\Support\AbonareCapturata (pista E), cu apărările lui FormularCapturat (nonce-ul legat de
 * formular, capcana, originea, bugetul vizitatorului).
 *
 * Ruta (src/Http/rute.php) are condiția `mailpoet` (Router::CONDITII, wp-compat.json → mailpoet): pe orice alt site nu se
 * potrivește deloc. Pista A a înregistrat-o o dată; răspunde 404 în JSON până când pista E e construită (PistaNeconstruita).
 */
final class AbonareCapturataController
{
    use PistaNeconstruita;

    /** `POST /_andaxi/abonare/{n}`: o abonare (pista E). */
    public function trimite(array $params): void
    {
        $this->jsonNeconstruit();
    }
}
