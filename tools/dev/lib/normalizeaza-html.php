<?php

declare(strict_types=1);

/**
 * Normalizarea unei pagini înainte de comparare: scoate ce diferă la fiecare cerere, oricare ar fi codul care a
 * răspuns (tokenurile formularelor, momentul randării, versiunea fișierelor statice, identificatorii aleși la
 * întâmplare), ca două răspunsuri ale aceluiași cod să iasă identice și două coduri diferite să se poată compara
 * octet cu octet.
 *
 * O folosesc:
 * - `tools/dev/compara-cu-baza.php` (aceleași cereri spre codul de bază și spre codul curent, pe aceeași bază; cu
 *   portul și dosarul codului de bază înlocuite cu ale codului curent și, cu licența Bioscem, fără panoul uneltei
 *   interne „Import din backup WordPress”);
 * - `tools/verificare/amprenta-site.php` (amprenta paginilor unui site adevărat, live sau de verificare, WP-11; cu
 *   gazda site-ului înlocuită cu `{GAZDA}` și fără bannerul copiei de verificare, `id="verificare-banner"`).
 *
 * Mutată aici din `compara-cu-baza.php` în WP-11, fără nicio schimbare: aceleași înlocuiri, în aceeași ordine (poarta
 * G-cmp o dovedește, cu aceleași pagini identice ca înainte). O regulă nouă se adaugă aici, pentru ambele unelte.
 *
 * Rulează și pe servere (`amprenta-site.php` o încarcă pe site-ul de verificare sau pe orice calculator), deci doar
 * sintaxa PHP-ului minim (`php_min`, `tools/dev/verifica-php.sh` o verifică) și nicio clasă a aplicației.
 */

/** Id-ul bannerului copiei de verificare (`App\Support\Verificare::ID_BANNER`; aici fără clasa aplicației). */
const NORMALIZEAZA_ID_BANNER = 'verificare-banner';

/** Ce pune amprenta în locul gazdei site-ului (în adrese: `https://gazda`, `//gazda`, `https:\/\/gazda`, `https%3A%2F%2Fgazda`). */
const NORMALIZEAZA_GAZDA = '{GAZDA}';

/**
 * Pagina normalizată.
 *
 * Opțiunile (toate opționale), aplicate în ordinea de mai jos:
 * - `fara_banner` (bool): scoate bannerul copiei de verificare, `<div id="verificare-banner" …>…</div>`, primul în pagină
 *   pe copie (garda 14, docs/VERIFICARE.md): o comparație cu site-ul adevărat nu-l are;
 * - `fara_import_wordpress` (bool): panoul importului din backup WordPress (unealta internă `migrare_wordpress`) din
 *   fereastra de import a utilizatorilor, care lipsește intenționat cu licența Bioscem;
 * - `inlocuiri` (array<string, string>): texte înlocuite exact, în ordine (portul codului de bază cu al codului curent,
 *   dosarul copiei de bază cu rădăcina codului);
 * - `gazde` (list<string>): gazdele site-ului (`bioscem.ro`, `127.0.0.1:8716`), înlocuite cu `{GAZDA}` doar în adrese
 *   (cu schema, fără ea, scrise în JSON sau codate în alt link), cu și fără `www.`; adresa de email a magazinului
 *   (`contact@bioscem.ro`) rămâne;
 * - `aleatoare` (list<string>): texte alese la întâmplare de cine a cerut pagina (de exemplu partea adresei inexistente
 *   cerute de amprentă), înlocuite cu `{ALEATOR}`.
 *
 * Apoi, mereu: tokenurile formularelor și momentul randării lor (și în HTML-ul de previzualizare pus în JSON), versiunea
 * fișierelor statice (`?v=` = data fișierului pe disc), identificatorii aleși la întâmplare ai formularelor.
 *
 * @param array{fara_banner?: bool, fara_import_wordpress?: bool, inlocuiri?: array<string, string>, gazde?: list<string>,
 *              aleatoare?: list<string>} $optiuni
 */
function normalizeaza_html(string $html, array $optiuni = []): string
{
    if (!empty($optiuni['fara_banner'])) {
        $html = normalizeaza_inlocuieste('#<div id="' . NORMALIZEAZA_ID_BANNER . '"[^>]*>.*?</div>#s', '', $html);
    }
    if (!empty($optiuni['fara_import_wordpress'])) {
        // Panoul importului din backup WordPress (unealta internă), din fereastra de import a utilizatorilor.
        $html = (string) preg_replace('#(data-admin-simple-panel="import"[^>]*>).*?(<h3[^>]*>Import din fișier CSV/XLSX</h3>)#s',
            '$1<IMPORT-WORDPRESS>$2', $html);
    }
    foreach ((array) ($optiuni['inlocuiri'] ?? []) as $din => $cu) {
        if ((string) $din !== '') {
            $html = str_replace((string) $din, (string) $cu, $html);
        }
    }
    foreach ((array) ($optiuni['gazde'] ?? []) as $gazda) {
        $html = normalizeaza_gazda($html, (string) $gazda);
    }
    foreach ((array) ($optiuni['aleatoare'] ?? []) as $aleator) {
        if ((string) $aleator !== '') {
            $html = str_replace((string) $aleator, '{ALEATOR}', $html);
        }
    }
    $inlocuiri = [
        // Tokenurile formularelor și momentul randării lor.
        '/(name="(?:checkout_form_token|register_form_token|_csrf|csrf_token|admin_csrf)"\s+value=")[^"]*"/' => '$1<TOKEN>"',
        '/(name="(?:checkout_form_rendered_at|register_form_rendered_at)"\s+value=")[^"]*"/' => '$1<MOMENT>"',
        '/("(?:token|rendered_at)"\s*:\s*)("[^"]*"|\d+)/' => '$1<TOKEN>',
        // Aceleași câmpuri în HTML-ul de previzualizare pus în JSON (editorul de pagini: \u0022 în loc de ghilimele).
        '/(name=\\\\u0022(?:checkout_form_token|register_form_token|_csrf|csrf_token|admin_csrf)\\\\u0022\s+value=\\\\u0022)[^\\\\]*/' => '$1<TOKEN>',
        '/(name=\\\\u0022(?:checkout_form_rendered_at|register_form_rendered_at)\\\\u0022\s+value=\\\\u0022)\d+/' => '$1<MOMENT>',
        '/(String\()"[0-9a-f]{16,}"/' => '$1"<TOKEN>"',
        '/(Number\()\d{9,}/' => '$1<MOMENT>',
        '/(window\.BT_CSRF\s*=\s*)"[^"]*"/' => '$1"<TOKEN>"',
        // Versiunea fișierelor statice = data fișierului pe disc (și variantele WebP ale logo-ului din antet).
        '/(\.(?:css|js|webp)\?v=)\d+/' => '$1<V>',
        // Identificatori aleși la întâmplare (formularele de checkout/cont).
        '/\b(bv-checkout-[a-z0-9-]*?)[0-9a-f]{6,}\b/' => '$1<ID>',
        '/\b([a-z]+-)[0-9a-f]{12,}\b/' => '$1<ID>',
    ];
    foreach ($inlocuiri as $tipar => $cu) {
        $html = (string) preg_replace($tipar, $cu, $html);
    }
    return $html;
}

/**
 * Gazda site-ului (`bioscem.ro`, `127.0.0.1:8716`) înlocuită cu `{GAZDA}` în adrese: `https://gazda`, `http://www.gazda`,
 * `//gazda`, scrisă în JSON (`https:\/\/gazda`) sau codată într-un link (`https%3A%2F%2Fgazda`, de exemplu linkurile de
 * distribuire). Doar urmată de sfârșitul gazdei (`/`, `:`, ghilimele, spațiu, `?`, `#`, `<`, `\`, `%`), deci
 * `bioscem.ro.alt-site.ro` rămâne.
 */
function normalizeaza_gazda(string $text, string $gazda): string
{
    $gazda = strtolower(trim($gazda));
    if ($gazda === '') {
        return $text;
    }
    $faraWww = str_starts_with($gazda, 'www.') ? substr($gazda, 4) : $gazda;
    $g = '(?:www\.)?' . preg_quote($faraWww, '#');
    $sfarsit = '(?=[/:"\'\s?\#<>\\\\%&,;)]|$)';
    $text = normalizeaza_inlocuieste('#(?:https?:)?//' . $g . $sfarsit . '#i', NORMALIZEAZA_GAZDA, $text);
    $text = normalizeaza_inlocuieste('#(?:https?:)?\\\\/\\\\/' . $g . $sfarsit . '#i', NORMALIZEAZA_GAZDA, $text);
    return normalizeaza_inlocuieste('#(?:https?%3A)?%2F%2F' . $g . '(?=%2F|%3F|%23|%3A|["\'\s&<]|$)#i', NORMALIZEAZA_GAZDA, $text);
}

/** preg_replace care nu întoarce niciodată în tăcere un text gol (o eroare PCRE oprește comparația). */
function normalizeaza_inlocuieste(string $tipar, string $cu, string $text): string
{
    $rezultat = preg_replace($tipar, $cu, $text);
    if ($rezultat === null) {
        throw new RuntimeException('PCRE: ' . preg_last_error_msg() . ' la ' . $tipar);
    }
    return $rezultat;
}
