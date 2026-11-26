<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

use App\Support\CadruCapturat\Regiuni;
use App\Support\NewsletterService;
use PDO;
use Throwable;

/**
 * Paginile abonamentului MailPoet (pista E; specificația §3.4): titlul și corpul fiecărei stări, octet cu octet ca pe site-ul
 * WordPress (MailPoet 5.45.2, stilul clasic al formularului de gestionare, textele ro_RO ale catalogului live), cu diferențele
 * declarate: butonul „Da, dezabonează-mă” în locul legăturii cu `onclick` (VC-MP-02), formularul de gestionare trimis la adresa
 * paginii, cu nonce-ul `_ax` (VC-MP-03), adresele relative în pagină (ca restul documentului capturat), jetonul cererii în linkuri
 * (VC-MP-10), legătura spre cont `/my-account/` (VC-MP-06), caseta „Spune-ne mai multe” mereu vizibilă și fără script (VC-MP-02b).
 *
 * Stările (litera din specificație): A confirmat, B reangajare, C dezabonat, D confirmarea dezabonării, E oprirea urmăririi, E2
 * urmărirea oprită, F necunoscut, G gestionarea. `stare()` alege starea unei cereri GET (fără efecte: le face routerul înainte).
 */
final class Pagini
{
    public const CHEIE_NUME_SITE = 'mailpoet_nume_site';
    public const CHEIE_CAMPURI = 'mailpoet_campuri_gestionare';
    public const CHEIE_SONDAJ = 'mailpoet_sondaj_dezabonare';

    /**
     * Starea paginii unei cereri GET (sau a dovezii de la import): acțiunea (după conversia MailPoet: `confirmUnsubscribe`), `data`,
     * query-ul, adresa cererii, abonatul verificat (null = niciunul) cu jetonul lui canonic și coada verificată.
     *
     * @param array<mixed> $date
     * @param array<string, mixed> $get
     * @param array<string, mixed>|null $abonat
     * @param array<string, mixed>|null $coada
     */
    public static function stare(PDO $db, string $actiune, array $date, array $get, string $uri, ?array $abonat, string $jeton, ?array $coada): StarePagina
    {
        // Previzualizarea paginilor (MailPoet Pages::isPreview): o cheie `preview` în adresă sau în `data`, oricare ar fi valoarea.
        $previzualizare = array_key_exists('preview', $get) || array_key_exists('preview', $date);
        if (!$previzualizare && $abonat === null) {
            return self::necunoscut();
        }
        if ($previzualizare) {
            // În previzualizare MailPoet arată conținutul demonstrativ; abonatul găsit contează doar unde îl folosește și MailPoet.
            $abonat = $actiune === 'unsubscribe' || $actiune === 'confirmUnsubscribe' || $actiune === 'trackingOptOut' ? $abonat : null;
        }
        switch ($actiune) {
            case 'confirm':
                return self::confirmat(self::numeSite($db));
            case 'reEngagement':
                return self::reangajare();
            case 'manage':
                return self::gestionare($db, $previzualizare ? null : $abonat, $jeton, $get, $uri);
            case 'trackingOptOut':
                if ($abonat !== null && (int) ($abonat['urmarire_refuzata'] ?? 0) === 1) {
                    return self::urmarireOprita(Url::abonare($db, 'manage', $abonat, $jeton, null, false));
                }
                return self::urmarire(Url::abonare($db, 'tracking_opt_out', $abonat, $jeton, null, false), Jetoane::nonce('urmarire', (int) ($abonat['id'] ?? 0)));
            case 'unsubscribe':
                // Regula lui MailPoet (Subscription::unsubscribe), doar pe `data`: `preview` adevărat și niciun `token` (un `token` null
                // contează ca lipsă, ca `isset`) → pagina dezabonării în previzualizare; `&preview=1` în adresă, `{"preview":0}` sau un
                // jeton → pagina de confirmare (E-PAR-05).
                if (!empty($date['preview']) && !isset($date['token'])) {
                    return self::dezabonat(null, '');
                }
                if ($abonat !== null && (string) $abonat['status'] === 'unsubscribed') {
                    return self::dezabonat(Url::abonare($db, 'manage', $abonat, $jeton, null, false),
                        $previzualizare ? '' : self::sondaj($db, $abonat, $jeton, $coada, $get));
                }
                return self::confirmaDezabonare($db, $abonat, $jeton, $coada);
            case 'confirmUnsubscribe':
                return self::confirmaDezabonare($db, $abonat, $jeton, $coada);
        }
        return self::necunoscut();
    }

    // ───────────────────────────────────────────────────────────
    // Stările
    // ───────────────────────────────────────────────────────────

    /** A. Confirmat: „Te-ai abonat la <site>” (fără numele site-ului: „Acum ești abonat!”). */
    public static function confirmat(string $numeSite): StarePagina
    {
        $titlu = $numeSite !== '' ? sprintf(Texte::CONFIRMAT_TITLU, $numeSite) : Texte::CONFIRMAT_TITLU_FARA_NUME;
        return new StarePagina($titlu, '<p>' . Texte::CONFIRMAT_CORP . "</p>\n");
    }

    /** B. Reangajarea. */
    public static function reangajare(): StarePagina
    {
        return new StarePagina(Texte::REANGAJARE_TITLU, '<p><p>' . Texte::REANGAJARE_CORP . "</p></p>\n");
    }

    /**
     * C. Dezabonat: cu legătura de gestionare (adresa ei, necodată HTML, ca MailPoet) și sondajul, ori, fără abonat (previzualizarea),
     * nota „Legătura … doar abonaților”.
     */
    public static function dezabonat(?string $urlGestionare, string $sondaj): StarePagina
    {
        $legatura = $urlGestionare === null ? Texte::LEGATURA_DOAR_ABONATI : '<a href="' . $urlGestionare . '">' . Texte::ADMINISTREAZA . '</a>';
        return new StarePagina(Texte::DEZABONAT_TITLU, '<p><p class="mailpoet_unsubscribed_content">' . Texte::DEZABONAT_DIN_GRESEALA . ' <strong>'
            . $legatura . '</strong></p>' . $sondaj . "</p>\n");
    }

    /**
     * D. Confirmarea dezabonării: formularul trimis la adresa dezabonării (cu `request_method=POST`, ca MailPoet), cu adresa abonatului
     * când e găsit; legătura cu `onclick` devine un buton cu înfățișarea ei (VC-MP-02), iar comentariul HTML de deasupra nu mai apare.
     *
     * @param array<string, mixed>|null $abonat
     * @param array<string, mixed>|null $coada
     */
    public static function confirmaDezabonare(PDO $db, ?array $abonat, string $jeton, ?array $coada): StarePagina
    {
        $url = Url::abonare($db, 'unsubscribe', $abonat, $jeton, $coada !== null ? (int) $coada['coada_id'] : null, false);
        $url .= (str_contains($url, '?') ? '&' : '?') . 'request_method=POST';
        $bloc = $abonat === null ? '' : "    <p class=\"mailpoet_confirm_unsubscribe_email\">\n      " . Texte::CONFIRMA_DEZABONARE_ADRESA . "\n      <br>\n      <strong>"
            . Regiuni::html((string) $abonat['email']) . "</strong>\n    </p>\n  ";
        return new StarePagina(Texte::CONFIRMA_DEZABONARE_TITLU, '<p><form action="' . Regiuni::attr($url) . "\" method=\"post\">\n"
            . "  <input type=\"hidden\" name=\"type\" value=\"confirmation\">\n  " . $bloc . "  <p class=\"mailpoet_confirm_unsubscribe\">\n    "
            . Texte::CONFIRMA_DEZABONARE_CORP . "\n    <br>\n    " . self::butonDezabonare() . "\n</p>\n</form></p>\n");
    }

    /**
     * Butonul „Da, dezabonează-mă” (VC-MP-02): un buton fără înfățișarea lui (stilul inline, permis de CSP-ul site-ului), cu textul
     * într-un `<a>` fără `href` (nu e conținut interactiv, deci e valid într-un buton): culoarea și restul înfățișării unei legături le
     * dau regulile `a` ale temei capturate, oricare ar fi ele. Clicul pe text ajunge la buton și trimite formularul, și fără JavaScript.
     */
    public static function butonDezabonare(): string
    {
        return '<button type="submit" class="ax-mp-legatura" style="background:none;border:0;padding:0;margin:0;font:inherit;line-height:inherit;'
            . 'cursor:pointer"><a>' . Texte::DA_DEZABONEAZA . '</a></button>';
    }

    /** E. Oprirea urmăririi: textul, apoi formularul cu nonce-ul în `_wpnonce`. */
    public static function urmarire(string $url, string $nonce): StarePagina
    {
        return new StarePagina(Texte::URMARIRE_TITLU, '<p><p>' . Texte::URMARIRE_CORP . '</p><form method="post" action="' . Regiuni::attr($url)
            . '" class="mailpoet_tracking_opt_out_form"><input type="hidden" name="_wpnonce" value="' . Regiuni::attr($nonce) . '" /><input type="submit" value="'
            . Texte::URMARIRE_BUTON . "\" /></form></p>\n");
    }

    /** E2. Urmărirea oprită: textul și legătura de gestionare. */
    public static function urmarireOprita(string $urlGestionare): StarePagina
    {
        return new StarePagina(Texte::URMARIRE_OPRITA_TITLU, '<p><p class="mailpoet_tracking_opt_out_content">' . Texte::URMARIRE_OPRITA_CORP . ' <strong><a href="'
            . $urlGestionare . '">' . Texte::ADMINISTREAZA . "</a></strong></p></p>\n");
    }

    /** F. Necunoscut (jeton greșit, adresă necunoscută, `data` lipsă): textul gol, fără paragraf; statusul 404 (VC-MP-11). */
    public static function necunoscut(): StarePagina
    {
        return new StarePagina(Texte::NECUNOSCUT_TITLU, Texte::NECUNOSCUT_CORP, 404);
    }

    /**
     * G. Gestionarea: formularul clasic al lui MailPoet. Fără abonat: previzualizarea (adresa și jetonul demonstrative, „John Doe”).
     *
     * @param array<string, mixed>|null $abonat
     * @param array<string, mixed> $get
     */
    public static function gestionare(PDO $db, ?array $abonat, string $jeton, array $get, string $uri): StarePagina
    {
        $campuri = self::campuri($db);
        $email = $abonat === null ? Abonati::DEMO_EMAIL : (string) $abonat['email'];
        $jetonForm = $abonat === null ? Abonati::DEMO_JETON : $jeton;
        $prenume = $abonat === null ? 'John' : (string) ($abonat['first_name'] ?? '');
        $nume = $abonat === null ? 'Doe' : (string) ($abonat['last_name'] ?? '');
        $contWp = $abonat !== null && ((int) ($abonat['is_woocommerce_user'] ?? 0) === 1 || (int) ($abonat['wp_user_id'] ?? 0) > 0);
        $info = $contWp ? str_replace(['[link]', '[/link]'], ['<a target="_blank" href="/my-account/">', '</a>'], Texte::AUTENTIFICA_TE) : Texte::SCHIMBA_ADRESA;
        $dis = $contWp ? 'disabled' : '';
        [$v1, $v2, $v3, $v4] = [self::idAleator(), self::idAleator(), self::idAleator(), self::idAleator()];
        $numeParsley = Regiuni::attr((string) json_encode([Texte::NUME_INVALID, Texte::ADRESA_IN_NUME], JSON_UNESCAPED_UNICODE));
        $stareMp = $abonat === null ? null : (['active' => 'subscribed', 'pending' => 'unconfirmed'][(string) $abonat['status']] ?? (string) $abonat['status']);
        $optiuni = '';
        foreach (['subscribed', 'unsubscribed'] as $s) {
            $optiuni .= '<option value="' . $s . '"' . ($stareMp === $s ? ' selected="selected"' : '') . '>' . Texte::STARI[$s] . '</option>';
        }
        if ($stareMp === 'bounced') {
            $optiuni .= '<option value="bounced" selected="selected" disabled="disabled">' . Texte::STARI['bounced'] . '</option>';
        }
        if ($stareMp === 'inactive') {
            $optiuni .= '<option value="inactive" selected="selected">' . Texte::STARI['inactive'] . '</option>';
        }
        $stariListe = $abonat === null ? [] : Abonati::stariListe($db, (int) $abonat['id']);
        $bife = '';
        $k = 0;
        foreach (Abonati::listeVizibile($db) as $l) {
            $k++;
            $bifata = ($stariListe[$l['id']] ?? '') === 'subscribed';
            $bife .= '<label class="mailpoet_checkbox_label" for="mailpoet_segment_' . $k . '" ><input type="checkbox" class="mailpoet_checkbox" id="mailpoet_segment_'
                . $k . '" name="data[' . Regiuni::attr($campuri['segments']) . '][]" value="' . $l['mailpoet_id'] . '" ' . ($bifata ? 'checked="checked"' : '')
                . ' data-parsley-errors-container=".mailpoet_error_' . $v4 . '" /> ' . Regiuni::html($l['name']) . '</label>';
        }
        $succes = '';
        $s = $get['success'] ?? null;
        if (is_scalar($s) && abs((int) $s) > 0) {
            $succes = "    <p class=\"mailpoet-submit-success\">\n    " . Texte::PREFERINTE_SALVATE . "\n  </p>\n";
        }
        $actiune = Url::abonare($db, 'manage', $abonat, $abonat === null ? '' : $jeton, null, false);
        $form = '<form class="mailpoet-manage-subscription" method="post" action="' . Regiuni::attr($actiune) . "\" novalidate>\n"
            . "  <input type=\"hidden\" name=\"action\" value=\"mailpoet_subscription_update\" />\n"
            . "  <input type=\"hidden\" name=\"data[segments]\" value=\"\" />\n"
            . '  <input type="hidden" name="mailpoet_redirect" value="' . Regiuni::attr(NewsletterService::appBaseUrl() . $uri) . "\"/>\n"
            . '  <input type="hidden" name="data[email]" value="' . Regiuni::attr($email) . "\" />\n"
            . '  <input type="hidden" name="token" value="' . Regiuni::attr($jetonForm) . "\" />\n"
            . '  <input type="hidden" name="_ax" value="' . Regiuni::attr(Jetoane::nonce('gestionare', (int) ($abonat['id'] ?? 0))) . "\" />\n"
            . "    <p class=\"mailpoet_paragraph\">\n"
            . '    <label> Email<br /><strong>' . Regiuni::html($email) . "</strong></label>\n"
            . "    <br />\n"
            . "    <span class=\"mailpoet-change-email-info\">\n"
            . '            ' . $info . "\n"
            . "    </span>\n"
            . "  </p>\n"
            . '  <div class="mailpoet_paragraph"><label class="mailpoet_text_label"  data-automation-id="form_first_name_label" >' . Texte::PRENUME
            . '</label><input type="text" autocomplete="given-name" class="mailpoet_text" name="data[' . Regiuni::attr($campuri['first_name']) . ']" title="'
            . Texte::PRENUME . '" value="' . Regiuni::attr($prenume) . '" data-automation-id="form_first_name" data-parsley-errors-container=".mailpoet_error_' . $v1
            . '" data-parsley-names="' . $numeParsley . '"' . $dis . '/><span class="mailpoet_error_' . $v1 . "\"></span></div>\n"
            . '<div class="mailpoet_paragraph"><label class="mailpoet_text_label"  data-automation-id="form_last_name_label" >' . Texte::NUME
            . '</label><input type="text" autocomplete="family-name" class="mailpoet_text" name="data[' . Regiuni::attr($campuri['last_name']) . ']" title="'
            . Texte::NUME . '" value="' . Regiuni::attr($nume) . '" data-automation-id="form_last_name" data-parsley-errors-container=".mailpoet_error_' . $v2
            . '" data-parsley-names="' . $numeParsley . '"' . $dis . '/><span class="mailpoet_error_' . $v2 . "\"></span></div>\n"
            . '<div class="mailpoet_paragraph"><label class="mailpoet_select_label" >' . Texte::STARE
            . "&nbsp;<span class=\"mailpoet_required\" aria-hidden=\"true\">*</span></label><select\n"
            . "      class=\"mailpoet_select\"\n"
            . "      \n"
            . '      name="data[' . Regiuni::attr($campuri['status']) . ']" data-automation-id="form_status" style="">' . $optiuni . '</select><span class="mailpoet_error_'
            . $v3 . "\"></span></div>\n"
            . '<div class="mailpoet_paragraph mailpoet-manage-subscription-list-fields"><fieldset><legend class="mailpoet_segment_label" >' . Texte::LISTELE_TALE
            . '</legend>' . $bife . '<span class="mailpoet_error_' . $v4 . "\"></span></fieldset></div>\n"
            . '<div class="mailpoet_paragraph"><input type="submit" class="mailpoet_submit" value="' . Texte::SALVEAZA
            . '" data-automation-id="subscribe-submit-button" style="border-color:transparent;" /><span class="mailpoet_form_loading"><span class="mailpoet_bounce1">'
            . "</span><span class=\"mailpoet_bounce2\"></span><span class=\"mailpoet_bounce3\"></span></span></div>\n"
            . "\n"
            . $succes . '    </form>';
        return new StarePagina(Texte::ADMINISTREAZA, '<p>' . $form . "</p>\n");
    }

    /**
     * Sondajul de după dezabonare (§3.4.3): doar cu setarea pornită, pentru un abonat dezabonat, cu o dezabonare-țintă; după
     * `unsubscribe_reason_saved=1`, mulțumirea. Fără script (VC-MP-02b).
     *
     * @param array<string, mixed> $abonat
     * @param array<string, mixed>|null $coada
     * @param array<string, mixed> $get
     */
    public static function sondaj(PDO $db, array $abonat, string $jeton, ?array $coada, array $get): string
    {
        $setare = self::setareSondaj($db);
        if (!$setare['activ'] || (string) $abonat['status'] !== 'unsubscribed' || Abonati::tintaMotiv($db, (int) $abonat['id'], $coada) === null) {
            return '';
        }
        $salvat = $get['unsubscribe_reason_saved'] ?? null;
        if (is_scalar($salvat) && abs((int) $salvat) === 1) {
            return '<p class="mailpoet_unsubscribe_reason_success">' . Texte::SONDAJ_MULTUMIM . '</p>';
        }
        $url = Url::abonare($db, 'unsubscribe_reason', $abonat, $jeton, $coada !== null ? (int) $coada['coada_id'] : null, false);
        $h = '<form class="mailpoet-unsubscribe-reason" action="' . Regiuni::html($url) . "\" method=\"post\">\n"
            . '  <input type="hidden" name="_wpnonce" value="' . Regiuni::attr(Jetoane::nonce('motiv', (int) $abonat['id'])) . "\">\n"
            . "  <fieldset>\n"
            . '    <legend>' . Texte::SONDAJ_INTREBARE . "</legend>\n"
            . '    ';
        foreach (Texte::MOTIVE as $cheie => $eticheta) {
            $h .= "      <p>\n        <label>\n          <input type=\"radio\" name=\"reason\" value=\"" . $cheie . "\" required>\n          " . Regiuni::html($eticheta)
                . "\n        </label>\n      </p>\n      ";
            if ($setare['text_liber'] && $cheie === 'other') {
                $h .= "        <p class=\"mailpoet-unsubscribe-reason-text\">\n          <label>\n            " . Texte::SONDAJ_MAI_MULTE
                    . "<br>\n            <textarea name=\"reason_text\" maxlength=\"500\" rows=\"4\"></textarea>\n          </label>\n        </p>\n      ";
            }
            $h .= '    ';
        }
        return $h . "  </fieldset>\n  <p>\n    <button type=\"submit\">" . Texte::SONDAJ_TRIMITE . "</button>\n  </p>\n  </form>";
    }

    // ───────────────────────────────────────────────────────────
    // Setările
    // ───────────────────────────────────────────────────────────

    /** Numele site-ului din titlul paginii de confirmare (setarea scrisă de import, din WordPress), altfel numele site-ului din setări. */
    public static function numeSite(PDO $db): string
    {
        $v = self::setare($db, self::CHEIE_NUME_SITE);
        if ($v !== null) {
            return $v;
        }
        return \App\Support\Brand::numeSite(\App\Support\Settings::all($db));
    }

    /**
     * Numele ascunse ale câmpurilor formularului de gestionare (setarea `mailpoet_campuri_gestionare`, luată la import din captura
     * paginii): `first_name`, `last_name`, `status`, `segments` → `form_field_…`. Fără setare: numele simple (MailPoet le primește și așa).
     *
     * @return array{first_name: string, last_name: string, status: string, segments: string}
     */
    public static function campuri(PDO $db): array
    {
        $implicit = ['first_name' => 'first_name', 'last_name' => 'last_name', 'status' => 'status', 'segments' => 'segments'];
        $v = json_decode((string) self::setare($db, self::CHEIE_CAMPURI), true);
        if (!is_array($v)) {
            return $implicit;
        }
        foreach ($implicit as $k => $x) {
            if (is_string($v[$k] ?? null) && preg_match('/^form_field_[A-Za-z0-9+\/=]{4,120}$/D', $v[$k]) === 1) {
                $implicit[$k] = $v[$k];
            }
        }
        return $implicit;
    }

    /** @return array{activ: bool, text_liber: bool} sondajul de după dezabonare (implicit al lui MailPoet: pornit, fără text liber) */
    public static function setareSondaj(PDO $db): array
    {
        $v = json_decode((string) self::setare($db, self::CHEIE_SONDAJ), true);
        return ['activ' => is_array($v) ? ($v['activ'] ?? true) === true : true, 'text_liber' => is_array($v) && ($v['text_liber'] ?? false) === true];
    }

    public static function setare(PDO $db, string $cheie): ?string
    {
        try {
            $q = $db->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
            $q->execute([$cheie]);
            $v = $q->fetchColumn();
            return is_string($v) ? $v : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Id-ul aleator al blocurilor de eroare ale formularului (5 caractere [0-9a-z], ca MailPoet). */
    private static function idAleator(): string
    {
        $a = '0123456789abcdefghijklmnopqrstuvwxyz';
        $s = '';
        for ($i = 0; $i < 5; $i++) {
            $s .= $a[random_int(0, 35)];
        }
        return $s;
    }
}
