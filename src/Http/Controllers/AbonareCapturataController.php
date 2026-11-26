<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AbonareCapturata;
use App\Support\Tema;
use App\Support\WpCompat;

/**
 * Abonarea din formularele Divi de pe paginile capturate (`et_pb_signup`, legate de listele MailPoet ale site-ului vechi) la listele
 * newsletterului (mutarea unui site WooCommerce, pista E; docs/MAGAZIN-WC.md, docs/DEPLOY.md, „Formularele de abonare”):
 * `POST /_andaxi/abonare/{n}`, `n` = numărul formularului în pagină. Serverul e App\Support\AbonareCapturata (cu apărările lui
 * FormularCapturat: originea, bugetul vizitatorului, nonce-ul zilei, lista din documentul capturat).
 *
 * Ruta (src/Http/rute.php, a pistei A) are condiția `mailpoet` (wp-compat.json → mailpoet); pe orice alt site nu se potrivește deloc.
 * Cererea ajunge de obicei la AbonareCapturata::intercepteaza din public/index.php (înaintea sesiunii, deci fără cookie de sesiune pentru
 * un vizitator, ca formularele de contact), iar ruta dă același răspuns dacă interceptarea lipsește.
 */
final class AbonareCapturataController
{
    use Comun;

    /** `POST /_andaxi/abonare/{n}`: o abonare. @param array<string, string> $params */
    public function trimite(array $params): void
    {
        $n = (string) ($params['n'] ?? '');
        $db = $this->db();
        if (preg_match('/^\d{1,3}$/D', $n) !== 1 || $db === null || Tema::cadru() !== 'capturat' || !WpCompat::mailpoet()) {
            // Ca orice 404 JSON al rutelor (antetele WordPress ale unui 404 le pune WpCompat pe un site cu `antete_404`).
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            echo 'false';
            return;
        }
        AbonareCapturata::raspunde($db, (int) $n);
    }
}
