<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Support\CadruCapturat\ContextRegiune;
use App\Support\CadruCapturat\Randator;
use App\Support\CadruCapturat\Regiuni;
use PDO;

/**
 * Randatorul regiunii `mailpoet:pagina` (pista E; Regiuni::CATALOG): paginile abonamentului MailPoet în documentul capturat al paginii
 * `?mailpoet_page=subscriptions` cu routerul (cadrul R din specificație, §3.4.1). Regulile pun două regiuni în document: n = 0 e
 * interiorul lui `h1.entry-title`, n = 1 al lui `div.entry-content`; titlul documentului (`{{ax:titlu}}`) e titlul paginii urmat de
 * sufixul titlului de pe site-ul vechi (setarea `mailpoet_sufix_titlu`, luată la import din captură).
 *
 * Starea paginii o pune routerul (StarePagina::curenta), după efectele cererii. Fără ea (dovada de la import, o cerere care n-a trecut
 * prin router), starea se calculează din adresa documentului sau a cererii, fără niciun abonat: previzualizarea (cadrul R e chiar
 * pagina de gestionare în previzualizare) sau „nu avem o înregistrare despre tine”.
 *
 * Id-urile blocurilor de eroare ale formularului de gestionare (MailPoet: aleatoare la fiecare randare) sunt, când regiunea are
 * octeții capturați (ContextRegiune::capturat(), contractul reparat al pistei A), cele din captură, în aceeași ordine: aceleași la
 * fiecare cerere și la dovadă. La dovadă, cererea simulată e a paginii capturate, deci originea din `mailpoet_redirect` e cea a
 * capturii (pe o cerere, APP_URL).
 *
 * Normalizarea dovezii, pe ambele contracte ale randatorilor:
 *  - `normalizari()` (contractul reparat al pistei A, lista închisă Regiuni::NORMALIZARI): doar `nonce:_wpnonce` (formularele opririi
 *    urmăririi și sondajului); acțiunea formularului de gestionare și câmpul lui `_ax` (VC-MP-03), butonul confirmării dezabonării
 *    (VC-MP-02) cer normalizările lor în lista închisă (dependența de pista A din raportul pistei E);
 *  - `normalizeaza()` (contractul de la 02c85ac): maschează câmpurile volatile sau declarate: id-urile blocurilor de eroare, acțiunea
 *    formularului de gestionare și nonce-ul lui `_ax` (VC-MP-03), originea din `mailpoet_redirect`, nonce-urile `_wpnonce`, comentariul
 *    HTML și legătura cu `onclick` a confirmării dezabonării (VC-MP-02).
 */
final class RandarePagina implements Randator
{
    public const CHEIE_SUFIX = 'mailpoet_sufix_titlu';

    private const COMENTARIU = "\n<!--  if updating this hidden field form value, remember to update the corresponding logic in "
        . 'mailpoet/lib/Router/Endpoints/Subscription.php::addTypeParamToUnsubscribeUrl -->';

    public function randeaza(ContextRegiune $ctx): string
    {
        $stare = StarePagina::curenta() ?? self::stareFaraRouter($ctx);
        $ctx->titlu($stare->titlu . ($ctx->db instanceof PDO ? (string) Pagini::setare($ctx->db, self::CHEIE_SUFIX) : ''));
        if ($ctx->n() === 0) {
            return Regiuni::html($stare->titlu);
        }
        $html = $stare->interiorContinut();
        // ContextRegiune::capturat() vine cu reparația pistei A (SPEC-A-09); pe contractul de la 02c85ac nu există.
        $capturat = method_exists($ctx, 'capturat') ? (string) $ctx->capturat() : '';
        if ($capturat !== '') {
            $html = self::iduriCapturate($html, $capturat);
            if ($ctx->laDovada()) {
                $html = self::origineCapturata($html, $capturat);
            }
        }
        return $html;
    }

    /** Normalizările dovezii din lista închisă a pistei A (contractul reparat). @return list<string> */
    public function normalizari(): array
    {
        return ['nonce:_wpnonce'];
    }

    /**
     * Normalizarea dovezii pe contractul de la 02c85ac (Randator::normalizeaza): id-urile aleatoare, acțiunea și `_ax` ale formularului
     * de administrare (VC-MP-03), originea din `mailpoet_redirect`, nonce-ul, butonul și comentariul confirmării dezabonării (VC-MP-02),
     * iar la sondajul cu „Altele” (VC-MP-02b) atributul `hidden` al câmpului de text și scriptul lui MailPoet de dinainte de `</form>`.
     */
    public function normalizeaza(string $html): string
    {
        $h = (string) preg_replace('/mailpoet_error_[0-9a-z]+/', 'mailpoet_error_{{volatil}}', $html);
        $h = str_replace('<p class="mailpoet-unsubscribe-reason-text" hidden>', '<p class="mailpoet-unsubscribe-reason-text">', $h);
        $h = (string) preg_replace('#\n      <script>\n.*?\n    </script>\n  </form>#s', "\n  </form>", $h);
        $h = (string) preg_replace('/(<form class="mailpoet-manage-subscription" method="post" action=")[^"]*(")/', '$1{{actiune_gestionare}}$2', $h);
        $h = (string) preg_replace('/\n  <input type="hidden" name="_ax" value="[^"]*" \/>/', '', $h);
        $h = (string) preg_replace('#(name="mailpoet_redirect" value=")https?://[^/"]*#', '$1{{origine}}', $h);
        $h = (string) preg_replace('/(name="_wpnonce" value=")[^"]*(")/', '$1{{nonce}}$2', $h);
        $h = str_replace(self::COMENTARIU, '', $h);
        return str_replace(['<a href="#" onclick="this.closest(\'form\').submit(); return false;" rel="nofollow">' . Texte::DA_DEZABONEAZA . '</a>',
            Pagini::butonDezabonare()], '{{da_dezaboneaza}}', $h);
    }

    /**
     * Id-urile `mailpoet_error_<id>` ale randării înlocuite cu cele ale capturii, în ordinea primei apariții, doar când sunt la fel de
     * multe (același formular); altfel cele noi rămân (o altă stare: pagina de dezabonare n-are niciunul).
     */
    private static function iduriCapturate(string $html, string $capturat): string
    {
        if (preg_match_all('/mailpoet_error_([0-9a-z]+)/', $capturat, $mc) < 1 || preg_match_all('/mailpoet_error_([0-9a-z]+)/', $html, $mh) < 1) {
            return $html;
        }
        $capturate = array_values(array_unique($mc[1]));
        $noi = array_values(array_unique($mh[1]));
        if (count($capturate) !== count($noi)) {
            return $html;
        }
        $harta = array_combine($noi, $capturate);
        return (string) preg_replace_callback('/mailpoet_error_([0-9a-z]+)/', static fn (array $m): string => 'mailpoet_error_' . ($harta[$m[1]] ?? $m[1]), $html);
    }

    /** La dovadă: originea din `mailpoet_redirect` e a paginii capturate (cererea simulată e a ei). */
    private static function origineCapturata(string $html, string $capturat): string
    {
        if (preg_match('#name="mailpoet_redirect" value="(https?://[^/"]+)#', $capturat, $c) !== 1) {
            return $html;
        }
        return (string) preg_replace('#(name="mailpoet_redirect" value=")https?://[^/"]+#', '${1}' . str_replace(['\\', '$'], ['\\\\', '\\$'], $c[1]), $html, 1);
    }

    /** Starea din adresa documentului (la dovadă) sau din query-ul cererii, fără abonat. */
    private static function stareFaraRouter(ContextRegiune $ctx): StarePagina
    {
        if (!$ctx->db instanceof PDO) {
            return Pagini::necunoscut();
        }
        $get = $ctx->get;
        $uri = $ctx->cale;
        if ($ctx->laDovada() || $get === []) {
            $q = (string) (parse_url($ctx->cale, PHP_URL_QUERY) ?? '');
            parse_str($q, $get);
        }
        if (!array_key_exists('mailpoet_router', $get)) {
            return Pagini::necunoscut();
        }
        $actiune = Router::actiune($get['action'] ?? null);
        $date = Date::decodeaza($get['data'] ?? null);
        return Pagini::stare($ctx->db, (string) $actiune, $date, $get, $uri, null, '', null);
    }
}
