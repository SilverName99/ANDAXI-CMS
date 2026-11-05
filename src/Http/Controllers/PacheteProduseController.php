<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Cart;
use App\Support\Flash;
use App\Support\PacheteProduse;
use App\Support\Plugins;
use PDO;

/**
 * Pluginul `pachete_produse` (din Biovitality 7391226, `SiteController::schimbaProdusulCuPachetul`, `cartSwapBundle`,
 * `cartSwapBundleApi`): „Schimbă în pachet” din coș. Produsul liniei iese din coș și pachetul intră în locul lui, o singură
 * bucată (pachetul conține deja produsul; cantitatea nu se transferă). Înainte, perechea produs–pachet se verifică în bază
 * (fără asta, cererea ar putea înlocui ce e în coș cu orice produs), apoi pachetul ca la „Adaugă în coș” (găsit, activ, nu
 * epuizat, în limita stocului). Două intrări, aceeași logică: formularul paginii coșului (`POST /cos/schimba-pachet`, butonul își
 * duce cheia liniei și pachetul lipite, „cheie:idPachet”) și coșul flotant (`POST /api/cart/swap-bundle`, JSON, răspunsul e coșul
 * flotant cu mesajul). Cât magazinul se oprește, nimic nou în coș (ca la adăugare).
 */
final class PacheteProduseController
{
    use Comun;

    public function schimbaInCos(): void
    {
        // În pagina coșului toate rândurile sunt în același formular, deci butonul își duce singur ambele valori: „cheie:idPachet”.
        $cheie = (string) ($_POST['item_key'] ?? '');
        $idPachet = (int) ($_POST['bundle_id'] ?? 0);
        $impreuna = trim((string) ($_POST['swap_bundle'] ?? ''));
        $ultimul = strrpos($impreuna, ':');
        if ($ultimul !== false) {
            // De la ultimul „:”, nu de la primul: id-ul pachetului e la coadă, cheia din coș rămâne întreagă.
            $cheie = substr($impreuna, 0, $ultimul);
            $idPachet = (int) substr($impreuna, $ultimul + 1);
        }
        $rezultat = $this->schimba($cheie, $idPachet);
        Flash::set($rezultat['ok'] ? 'success' : 'error', $rezultat['message']);
        header('Location: /cos');
    }

    public function schimbaInCosApi(): void
    {
        $date = $this->requestPayload();
        $rezultat = $this->schimba((string) ($date['item_key'] ?? $_POST['item_key'] ?? ''), (int) ($date['bundle_id'] ?? $_POST['bundle_id'] ?? 0));
        if (!$rezultat['ok']) {
            $this->jsonResponse(['ok' => false, 'message' => $rezultat['message']], $rezultat['status']);
            return;
        }
        $raspuns = (new SiteController())->raspunsCosFlotant($this->db());
        $raspuns['message'] = $rezultat['message'];
        $this->jsonResponse($raspuns);
    }

    /** @return array{ok: bool, message: string, status: int} */
    private function schimba(string $cheie, int $idPachet): array
    {
        if (!Plugins::permiteInceput('magazin')) {
            return ['ok' => false, 'message' => 'Momentan nu se mai pot plasa comenzi online.', 'status' => 409];
        }
        $cheie = trim($cheie);
        $idProdus = (int) (Cart::parseItemKey($cheie)['product_id'] ?? 0);
        if ($idProdus <= 0 || $idPachet <= 0) {
            return ['ok' => false, 'message' => 'Produsul nu a fost găsit.', 'status' => 404];
        }
        if (!isset(Cart::items()[$cheie])) {
            return ['ok' => false, 'message' => 'Produsul nu mai este în coș.', 'status' => 404];
        }
        $db = $this->db();
        if (!PacheteProduse::esteComponenta($db instanceof PDO ? $db : null, $idProdus, $idPachet)) {
            return ['ok' => false, 'message' => 'Pachetul ales nu conține acest produs.', 'status' => 422];
        }
        $verificare = (new SiteController())->verificaPachetPentruCos($idPachet, 1);
        if (!$verificare['ok']) {
            return ['ok' => false, 'message' => $verificare['message'], 'status' => $verificare['status']];
        }
        Cart::remove($cheie);
        Cart::add($idPachet, 1);

        return ['ok' => true, 'message' => 'Am înlocuit produsul cu pachetul „' . $verificare['nume'] . '".', 'status' => 200];
    }
}
