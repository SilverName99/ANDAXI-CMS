<?php

declare(strict_types=1);

/**
 * Contractul unei pagini de admin (tools/dev/contract-admin.php, poarta G-admin, WP-29): ce trimite și ce citește pagina,
 * nu cum arată. Refacerea adminului (faza D, „B — Compact”) schimbă așezarea și stilul; contractul trebuie să rămână același
 * ca la eticheta `admin-inainte-b`, în afară de diferențele declarate în tools/dev/lib/contract-admin-declarat.php.
 *
 * Un contract e o listă de rânduri (un multiset: ordinea nu contează, numărul de apariții da):
 *   stare <cod> [-> <Location>]                         codul răspunsului (și unde trimite un 302/303)
 *   form <METODĂ> <acțiune> [multipart]                 fiecare formular (acțiunea fără gazdă; goală = „(pagina)”)
 *   camp <formular> <tag>:<tip> <nume> [= <valoare>]   fiecare control cu nume: valoarea doar la hidden, checkbox, radio
 *   optiuni <formular> <nume>: <v1>|<v2>|…              valorile opțiunilor unui select (în ordinea lor)
 *   buton <formular> <nume> = <valoare>                 butoanele cu nume (button, input submit/image)
 *   link <href>                                         fiecare link
 *   data <atribut> = <valoare>                          fiecare atribut data-* (o valoare lungă: amprenta ei)
 *   celula <text>                                       textul fiecărei celule de tabel (td), spațiile strânse
 * „<formular>” = „<METODĂ> <acțiune>” al formularului din jurul controlului (sau al celui numit în atributul form=), „-” fără.
 */

/** Textul unui nod, cu spațiile strânse. */
function contract_text(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/** O valoare lungă (JSON-ul unei comenzi în data-order) intră în contract prin amprenta ei. */
function contract_valoare(string $valoare, int $max = 160): string
{
    $valoare = contract_text($valoare);
    return mb_strlen($valoare) > $max ? '#' . substr(sha1($valoare), 0, 16) . ' (' . strlen($valoare) . ' octeți)' : $valoare;
}

/**
 * Normalizarea dinaintea extragerii: gazda serverului (cele două servere au porturi diferite), jetoanele de sesiune și ce
 * diferă la fiecare cerere fără să țină de cod.
 *
 * @param list<string> $gazde „127.0.0.1:<port>” ale serverului care a dat pagina
 */
function contract_normalizeaza(string $html, array $gazde): string
{
    foreach ($gazde as $gazda) {
        $html = str_replace(['http://' . $gazda, $gazda], ['http://<GAZDA>', '<GAZDA>'], $html);
    }
    // Jetonul CSRF al sesiunii (formularele și meta-ul lui), 64 de cifre hexa.
    $html = (string) preg_replace('/\b[0-9a-f]{64}\b/', '<TOKEN>', $html);
    // Versiunea fișierelor statice (data lor pe disc) din linkuri.
    $html = (string) preg_replace('#(\.(?:css|js|png|svg|webp|jpg|ico))\?v=[0-9A-Za-z._-]+#', '$1?v=<V>', $html);
    return $html;
}

/** Acțiunea sau linkul fără gazdă. */
function contract_adresa(string $adresa): string
{
    $adresa = trim($adresa);
    $adresa = (string) preg_replace('#^https?://<GAZDA>#', '', $adresa);
    return $adresa;
}

/**
 * Rândurile contractului unei pagini.
 *
 * @return list<string>
 */
function contract_extrage(int $cod, string $location, string $html): array
{
    $randuri = ['stare ' . $cod . ($location !== '' ? ' -> ' . contract_adresa($location) : '')];
    if (trim($html) === '' || !preg_match('/<(?:html|body|form|div|main)\b/i', $html)) {
        return $randuri;
    }
    $dom = new DOMDocument();
    $vechi = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($vechi);
    $xp = new DOMXPath($dom);

    $etichetaForm = static function (?DOMElement $form): string {
        if (!$form instanceof DOMElement) {
            return '-';
        }
        $actiune = contract_adresa($form->getAttribute('action'));
        return strtoupper($form->getAttribute('method') ?: 'get') . ' ' . ($actiune === '' ? '(pagina)' : $actiune);
    };
    $formulareDupaId = [];
    foreach ($xp->query('//form') ?: [] as $form) {
        /** @var DOMElement $form */
        if ($form->getAttribute('id') !== '') {
            $formulareDupaId[$form->getAttribute('id')] = $form;
        }
        $randuri[] = 'form ' . $etichetaForm($form)
            . (stripos($form->getAttribute('enctype'), 'multipart') !== false ? ' multipart' : '');
    }
    $formularul = static function (DOMElement $control) use ($formulareDupaId, $etichetaForm): string {
        $id = $control->getAttribute('form');
        if ($id !== '') {
            return isset($formulareDupaId[$id]) ? $etichetaForm($formulareDupaId[$id]) : '? form=' . $id;
        }
        for ($nod = $control->parentNode; $nod instanceof DOMElement; $nod = $nod->parentNode) {
            if (strtolower($nod->nodeName) === 'form') {
                return $etichetaForm($nod);
            }
        }
        return '-';
    };

    foreach ($xp->query('//input[@name] | //select[@name] | //textarea[@name] | //button[@name]') ?: [] as $control) {
        /** @var DOMElement $control */
        $tag = strtolower($control->nodeName);
        $nume = $control->getAttribute('name');
        $form = $formularul($control);
        $tip = strtolower($control->getAttribute('type'));
        if ($tag === 'button' || ($tag === 'input' && in_array($tip, ['submit', 'image'], true))) {
            $randuri[] = 'buton ' . $form . ' ' . $nume . ' = ' . contract_valoare($control->getAttribute('value'));
            continue;
        }
        if ($tag === 'input') {
            $tip = $tip === '' ? 'text' : $tip;
            $cuValoare = in_array($tip, ['hidden', 'checkbox', 'radio'], true);
            $randuri[] = 'camp ' . $form . ' input:' . $tip . ' ' . $nume . ($cuValoare ? ' = ' . contract_valoare($control->getAttribute('value')) : '');
            continue;
        }
        if ($tag === 'select') {
            $randuri[] = 'camp ' . $form . ' select' . ($control->hasAttribute('multiple') ? ':multiple' : '') . ' ' . $nume;
            $valori = [];
            foreach ($xp->query('.//option', $control) ?: [] as $optiune) {
                /** @var DOMElement $optiune */
                $valori[] = $optiune->hasAttribute('value') ? $optiune->getAttribute('value') : contract_text($optiune->textContent);
            }
            $randuri[] = 'optiuni ' . $form . ' ' . $nume . ': ' . contract_valoare(implode('|', $valori), 400);
            continue;
        }
        $randuri[] = 'camp ' . $form . ' textarea ' . $nume;
    }
    foreach ($xp->query('//a[@href]') ?: [] as $link) {
        /** @var DOMElement $link */
        $randuri[] = 'link ' . contract_valoare(contract_adresa($link->getAttribute('href')), 300);
    }
    foreach ($xp->query('//@*[starts-with(name(), "data-")]') ?: [] as $atribut) {
        /** @var DOMAttr $atribut */
        $randuri[] = 'data ' . $atribut->name . ' = ' . contract_valoare((string) $atribut->value);
    }
    foreach ($xp->query('//td') ?: [] as $celula) {
        $text = contract_valoare((string) $celula->textContent, 200);
        if ($text !== '') {
            $randuri[] = 'celula ' . $text;
        }
    }
    return $randuri;
}

/**
 * Diferența a două contracte (multiseturi): [rând => +n adăugat în cel curent / -n scos față de bază].
 *
 * @param list<string> $baza
 * @param list<string> $curent
 * @return array<string, int>
 */
function contract_diferente(array $baza, array $curent): array
{
    $numar = [];
    foreach ($curent as $rand) {
        $numar[$rand] = ($numar[$rand] ?? 0) + 1;
    }
    foreach ($baza as $rand) {
        $numar[$rand] = ($numar[$rand] ?? 0) - 1;
    }
    $numar = array_filter($numar, static fn (int $n): bool => $n !== 0);
    ksort($numar, SORT_STRING);
    return $numar;
}

/**
 * Scoate din diferențele unei pagini ce e declarat ($contractAdminPorturi). O declarație:
 *   ['wp' => 'WP-30', 'ce' => 'adaugat'|'scos', 'rand' => '<regex pe rând>', 'cale' => '<regex pe cale>' (implicit orice),
 *    'licenta' => 'toate'|…|null, 'rol' => 'adm'|…|null, 'motiv' => '…']
 * O declarație acoperă toate aparițiile rândului ei pe paginile ei. Întoarce diferențele rămase; $folosite[cheie]++ pentru
 * fiecare declarație care a acoperit ceva (una nefolosită în toată rularea pică la final: n-ar dovedi nimic).
 *
 * @param array<string, int> $diferente
 * @param array<string, array<string, mixed>> $declaratii
 * @param array<string, int> $folosite
 * @return array<string, int>
 */
function contract_fara_declarate(array $diferente, array $declaratii, string $cale, string $licenta, string $rol, array &$folosite): array
{
    foreach ($diferente as $rand => $n) {
        foreach ($declaratii as $cheie => $d) {
            if (($d['ce'] ?? '') !== ($n > 0 ? 'adaugat' : 'scos')) {
                continue;
            }
            if (isset($d['licenta']) && !in_array($licenta, (array) $d['licenta'], true)) {
                continue;
            }
            if (isset($d['rol']) && !in_array($rol, (array) $d['rol'], true)) {
                continue;
            }
            if (isset($d['cale']) && preg_match('#' . $d['cale'] . '#', $cale) !== 1) {
                continue;
            }
            if (preg_match('#' . $d['rand'] . '#u', $rand) !== 1) {
                continue;
            }
            $folosite[$cheie] = ($folosite[$cheie] ?? 0) + abs($n);
            unset($diferente[$rand]);
            break;
        }
    }
    return $diferente;
}

/**
 * Autotestul mecanismului (fără servere): o pagină inventată și câte o schimbare pentru fiecare fel de rând trebuie să dea
 * exact diferența ei, iar o schimbare doar de aspect (clase, ordine, stiluri, texte în afara tabelelor) niciuna.
 *
 * @return list<string> eșecurile
 */
function contract_autotest(): array
{
    $pagina = '<!doctype html><html><body><nav><a href="/admin">Acasă</a><a href="/admin/orders">Comenzi</a></nav>'
        . '<form method="post" action="http://<GAZDA>/admin/orders/bulk" enctype="multipart/form-data">'
        . '<input type="hidden" name="_csrf" value="<TOKEN>"><input type="hidden" name="mod" value="awb">'
        . '<input name="q"><input type="checkbox" name="ids[]" value="7"><select name="status"><option value="noua">Nouă</option>'
        . '<option value="trimisa">Trimisă</option></select><textarea name="nota"></textarea>'
        . '<button name="actiune" value="confirma">Confirmă</button><button type="button">Închide</button></form>'
        . '<table><tr><td> CMD-1 </td><td data-order-id="7">Nouă</td></tr></table><p>Text liber</p></body></html>';
    $baza = contract_extrage(200, '', $pagina);
    $esecuri = [];
    $asteptat = [
        'stare 200', 'form POST /admin/orders/bulk multipart', 'camp POST /admin/orders/bulk input:hidden _csrf = <TOKEN>',
        'camp POST /admin/orders/bulk input:hidden mod = awb', 'camp POST /admin/orders/bulk input:text q',
        'camp POST /admin/orders/bulk input:checkbox ids[] = 7', 'camp POST /admin/orders/bulk select status',
        'optiuni POST /admin/orders/bulk status: noua|trimisa', 'camp POST /admin/orders/bulk textarea nota',
        'buton POST /admin/orders/bulk actiune = confirma', 'link /admin', 'link /admin/orders', 'data data-order-id = 7',
        'celula CMD-1', 'celula Nouă',
    ];
    if (contract_diferente($asteptat, $baza) !== []) {
        $esecuri[] = 'extragerea: ' . json_encode(contract_diferente($asteptat, $baza), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    // Doar aspectul: altă ordine a linkurilor, clase și stiluri noi, un div în plus, alt text liber, același contract.
    $aspect = str_replace(['<nav><a href="/admin">Acasă</a><a href="/admin/orders">Comenzi</a></nav>', '<p>Text liber</p>', '<form '],
        ['<aside class="ax-bara"><div><a class="activ" href="/admin/orders">Comenzi</a> <a style="color:red" href="/admin">Acasă</a></div></aside>',
            '<p class="ajutor">Alt text</p>', '<div class="card"></div><form class="dens" '], $pagina);
    if (($d = contract_diferente($baza, contract_extrage(200, '', $aspect))) !== []) {
        $esecuri[] = 'o schimbare doar de aspect a dat diferențe: ' . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $mutatii = [
        'numele unui câmp' => ['name="q"', 'name="cauta"'],
        'valoarea unui hidden' => ['value="awb"', 'value="confirma"'],
        'valoarea unei opțiuni' => ['value="trimisa"', 'value="expediata"'],
        'acțiunea formularului' => ['/admin/orders/bulk"', '/admin/orders/masa"'],
        'metoda formularului' => ['method="post"', 'method="get"'],
        'tipul unui câmp' => ['<input name="q">', '<input type="number" name="q">'],
        'valoarea unui buton' => ['value="confirma">Confirmă', 'value="sterge">Confirmă'],
        'un link scos' => ['<a href="/admin/orders">Comenzi</a>', ''],
        'un atribut data-' => ['data-order-id="7"', 'data-comanda="7"'],
        'o celulă' => ['<td> CMD-1 </td>', '<td>CMD-2</td>'],
        'un câmp mutat în afara formularului' => ['<textarea name="nota"></textarea>', ''],
        'enctype' => [' enctype="multipart/form-data"', ''],
    ];
    foreach ($mutatii as $ce => [$din, $in]) {
        $mutata = str_replace($din, $in, $pagina);
        if ($ce === 'un câmp mutat în afara formularului') {
            $mutata = str_replace('</form>', '</form><textarea name="nota"></textarea>', $mutata);
        }
        if ($mutata === $pagina || contract_diferente($baza, contract_extrage(200, '', $mutata)) === []) {
            $esecuri[] = 'mutația „' . $ce . '” n-a dat nicio diferență';
        }
    }
    if (contract_diferente($baza, contract_extrage(302, '/admin/login', $pagina)) === []) {
        $esecuri[] = 'alt cod de stare n-a dat nicio diferență';
    }
    // Declarațiile: una acoperă exact rândul ei, pe pagina ei; pe altă pagină sau alt rol nu.
    $d = contract_diferente($baza, contract_extrage(200, '', str_replace('<a href="/admin">Acasă</a>', '<a href="/admin">Acasă</a><a href="/">Vezi site-ul</a>', $pagina)));
    $declaratii = ['t' => ['ce' => 'adaugat', 'rand' => '^link /$', 'cale' => '^/admin$', 'rol' => 'adm', 'motiv' => 'test']];
    $folosite = [];
    if (contract_fara_declarate($d, $declaratii, '/admin', 'toate', 'adm', $folosite) !== [] || ($folosite['t'] ?? 0) !== 1) {
        $esecuri[] = 'declarația potrivită n-a acoperit rândul';
    }
    $folosite = [];
    if (contract_fara_declarate($d, $declaratii, '/admin/orders', 'toate', 'adm', $folosite) === []
        || contract_fara_declarate($d, $declaratii, '/admin', 'toate', 'blog', $folosite) === [] || $folosite !== []) {
        $esecuri[] = 'declarația a acoperit rândul pe altă pagină sau la alt rol';
    }
    $declaratii['t']['ce'] = 'scos';
    if (contract_fara_declarate($d, $declaratii, '/admin', 'toate', 'adm', $folosite) === []) {
        $esecuri[] = 'o declarație „scos” a acoperit un rând adăugat';
    }
    $normalizat = contract_normalizeaza('<a href="http://127.0.0.1:8733/admin?x=1">x</a><input name="_csrf" value="'
        . str_repeat('ab', 32) . '"><a href="/assets/css/app.css?v=123">c</a>', ['127.0.0.1:8733']);
    if ($normalizat !== '<a href="http://<GAZDA>/admin?x=1">x</a><input name="_csrf" value="<TOKEN>"><a href="/assets/css/app.css?v=<V>">c</a>') {
        $esecuri[] = 'normalizarea: ' . $normalizat;
    }
    return $esecuri;
}
