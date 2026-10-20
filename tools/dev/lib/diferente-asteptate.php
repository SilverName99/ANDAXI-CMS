<?php

declare(strict_types=1);

/**
 * Diferențele așteptate față de codul de bază, folosite de tools/dev/compara-cu-baza.php.
 * Se scot doar din pagina codului curent; restul paginii se compară octet cu octet.
 *
 *  - texte declarate: [nume, cale, nou, vechi, motiv]. „nou” e textul exact scris de
 *    codul curent, „vechi” ce scrie codul de bază în locul lui; fiecare trebuie găsit
 *    exact o dată pe fiecare pagină a lui și niciodată pe altă pagină;
 *  - marcaje de port: `<!--port:<sursa>:<NUME>-->…<!--/port:<sursa>:<NUME>-->` (scrise de
 *    App\Support\Dev::marcaj); blocul întreg se scoate. Fiecare nume e declarat o
 *    singură dată, cu pagina lui, și trebuie găsit exact o dată pe ea; un marcaj
 *    nedeclarat, desperecheat sau găsit pe altă pagină face comparația să pice.
 *
 *  - coduri de stare declarate (diferente_coduri): o pagină care în codul de bază cade
 *    (500) și aici merge; se verifică ambele coduri și un text al paginii curente.
 *
 * „cale” e pagina comparată (cu parametrii ei, ca în lista de pagini) sau un prefix
 * terminat în „*” („/admin*” = fiecare pagină comparată care începe cu /admin).
 * Plus anteturile răspunsului, normalizate pentru comparare (diferente_antete).
 * Funcții pure (fără server și fără bază), verificate de diferente_autotest().
 */

/** Pagina comparată e una dintre paginile declarației? */
function diferenta_pe_pagina(string $declarata, string $cale): bool
{
    return str_ends_with($declarata, '*')
        ? str_starts_with($cale, substr($declarata, 0, -1))
        : $declarata === $cale;
}

/**
 * Pune la loc textele declarate. Problemele (text negăsit pe pagina lui, găsit de mai
 * multe ori sau pe altă pagină, declarație incompletă) se adaugă în $probleme.
 *
 * @param array<string, list<array{nume?: string, cale?: string, nou?: string, vechi?: string, motiv?: string}>> $liste
 * @param array<string, int> $gasite
 * @param list<string> $probleme
 */
function diferente_texte(array $liste, string $cale, string $html, array &$gasite, array &$probleme): string
{
    foreach ($liste as $numeLista => $lista) {
        foreach ($lista as $intrare) {
            $nume = (string) ($intrare['nume'] ?? '');
            $eticheta = $numeLista . ' „' . $nume . '”';
            foreach (['nume', 'cale', 'nou', 'motiv'] as $camp) {
                if (trim((string) ($intrare[$camp] ?? '')) === '') {
                    $probleme[] = $eticheta . ': lipsește „' . $camp . '” (fiecare diferență se declară cu pagina și motivul ei)';
                    continue 2;
                }
            }
            $html = str_replace((string) $intrare['nou'], (string) ($intrare['vechi'] ?? ''), $html, $n);
            $asteptat = diferenta_pe_pagina((string) $intrare['cale'], $cale) ? 1 : 0;
            if ($n !== $asteptat) {
                $probleme[] = $eticheta . ' găsit de ' . $n . ' ori, așteptat ' . $asteptat;
            }
            if ($n > 0) {
                $gasite[$eticheta] = ($gasite[$eticheta] ?? 0) + $n;
            }
        }
    }
    return $html;
}

/**
 * Scoate blocurile marcate. $marcaje: nume („bv:tab-spam”) => ['cale' => …, 'motiv' => …].
 *
 * @param array<string, array{cale?: string, motiv?: string}> $marcaje
 * @param array<string, int> $gasite
 * @param list<string> $probleme
 */
function diferente_marcaje(array $marcaje, string $cale, string $html, array &$gasite, array &$probleme): string
{
    foreach ($marcaje as $nume => $declaratie) {
        if (preg_match('/^[a-z]+:[A-Za-z0-9_.-]+$/', (string) $nume) !== 1) {
            $probleme[] = 'marcajul „' . $nume . '”: numele trebuie să fie <sursa>:<NUME> (de exemplu bv:tab-spam)';
        }
        if (trim((string) ($declaratie['cale'] ?? '')) === '' || trim((string) ($declaratie['motiv'] ?? '')) === '') {
            $probleme[] = 'marcajul „' . $nume . '”: lipsește pagina sau motivul';
        }
    }
    $numarate = [];
    $html = preg_replace_callback(
        '#<!--port:([a-z]+:[A-Za-z0-9_.-]+)-->.*?<!--/port:\1-->#s',
        static function (array $m) use (&$numarate): string {
            $numarate[$m[1]] = ($numarate[$m[1]] ?? 0) + 1;
            return '';
        },
        $html
    );
    if ($html === null) {
        throw new RuntimeException('PCRE la marcajele de port: ' . preg_last_error_msg());
    }
    foreach ($numarate as $nume => $n) {
        if (!isset($marcaje[$nume])) {
            $probleme[] = 'marcajul „' . $nume . '” nu e declarat în $marcajePort (găsit de ' . $n . ' ori)';
        }
    }
    foreach ($marcaje as $nume => $declaratie) {
        $n = $numarate[$nume] ?? 0;
        $asteptat = diferenta_pe_pagina((string) ($declaratie['cale'] ?? ''), $cale) ? 1 : 0;
        if ($n !== $asteptat) {
            $probleme[] = 'marcajul „' . $nume . '” găsit de ' . $n . ' ori, așteptat ' . $asteptat;
        }
        if ($n > 0) {
            $gasite['marcaj ' . $nume] = $n;
        }
    }
    // Ce a rămas: un marcaj fără pereche (început fără sfârșit, sfârșit fără început, nume diferite).
    if (preg_match_all('#<!--/?port:[^>]*-->#', $html, $ramase) > 0) {
        $probleme[] = 'marcaje fără pereche: ' . implode(' ', array_unique($ramase[0]));
    }
    return $html;
}

/**
 * Paginile la care codul de stare diferă voit: o eroare a codului de bază, reparată aici
 * (de exemplu o pagină care în bază cade cu 500). Declarația: [nume, cale, cod_baza,
 * cod_curent, text, motiv]. O astfel de pagină nu se poate compara octet cu octet (codul de
 * bază nu dă pagina), deci trece doar dacă ambele coduri sunt exact cele declarate, iar
 * „text” (scris de codul curent) apare pe pagina curentă și lipsește din răspunsul bazei.
 * Când și codul de bază se repară, declarația pică (alt cod) și trebuie scoasă.
 *
 * @param array<string, list<array{nume?: string, cale?: string, cod_baza?: int, cod_curent?: int, text?: string, motiv?: string}>> $liste
 * @param list<string> $probleme
 * @return string|null eticheta declarației care acoperă pagina, sau null dacă nicio declarație nu o acoperă
 */
function diferente_coduri(array $liste, string $cale, int $codBaza, string $corpBaza, int $codCurent, string $corpCurent, array &$probleme): ?string
{
    $acoperita = null;
    foreach ($liste as $numeLista => $lista) {
        foreach ($lista as $intrare) {
            $eticheta = $numeLista . ' „' . (string) ($intrare['nume'] ?? '') . '”';
            if (!diferenta_pe_pagina((string) ($intrare['cale'] ?? ''), $cale)) {
                continue;
            }
            $acoperita = $eticheta;
            foreach (['nume', 'cale', 'text', 'motiv'] as $camp) {
                if (trim((string) ($intrare[$camp] ?? '')) === '') {
                    $probleme[] = $eticheta . ': lipsește „' . $camp . '” (fiecare diferență se declară cu pagina și motivul ei)';
                    continue 2;
                }
            }
            if ($codBaza !== (int) ($intrare['cod_baza'] ?? 0) || $codCurent !== (int) ($intrare['cod_curent'] ?? 0)) {
                $probleme[] = $eticheta . ': coduri ' . $codBaza . '→' . $codCurent . ', declarat '
                    . (int) ($intrare['cod_baza'] ?? 0) . '→' . (int) ($intrare['cod_curent'] ?? 0)
                    . ' (dacă și codul de bază s-a reparat, declarația se scoate)';
            }
            if (!str_contains($corpCurent, (string) $intrare['text'])) {
                $probleme[] = $eticheta . ': textul declarat lipsește de pe pagina codului curent';
            }
            if (str_contains($corpBaza, (string) $intrare['text'])) {
                $probleme[] = $eticheta . ': textul declarat e și în răspunsul codului de bază';
            }
        }
    }
    return $acoperita;
}

/**
 * Anteturile unui răspuns, cum se compară (după cod, Location și corp): tot ce trimite
 * codul (Content-Type, Cache-Control, Expires, Pragma, X-Robots-Tag, Set-Cookie, Vary,
 * Link, Content-Disposition, Retry-After …), cu numele în litere mici, în ordinea în care
 * le trimite codul. Nu intră doar ce nu ține de cod:
 *  - Date (ora cererii) și Connection, Host (serverul `php -S`);
 *  - Content-Length: urmează corpul, care se compară oricum octet cu octet, după
 *    scoaterea tokenurilor (care pot avea altă lungime);
 *  - Location: se compară separat, cu portul serverului înlocuit;
 *  - X-Andaxi-Garda: îl scrie garda doar pe serverele de dezvoltare (CMS_DEV_GARDA_ANTET),
 *    nu pe site-uri; codul de stare al răspunsului se compară oricum.
 * Valorile care se schimbă la fiecare cerere se maschează, dar antetul și restul lui rămân:
 * Set-Cookie (valoarea cookie-ului și data expirării; numele și atributele se compară),
 * ETag și Last-Modified. Portul serverului de bază devine cel al codului curent.
 *
 * @return list<string>
 */
function diferente_antete(string $antet, string $portBaza, string $portCurent): array
{
    $linii = [];
    foreach (preg_split('/\r?\n/', $antet) ?: [] as $linie) {
        if (!str_contains($linie, ':') || preg_match('#^HTTP/\d#', $linie) === 1) {
            continue;
        }
        [$nume, $valoare] = array_map('trim', explode(':', $linie, 2));
        $nume = strtolower($nume);
        if (in_array($nume, ['date', 'connection', 'host', 'content-length', 'location', 'x-andaxi-garda'], true)) {
            continue;
        }
        $valoare = str_replace('127.0.0.1:' . $portBaza, '127.0.0.1:' . $portCurent, $valoare);
        if ($nume === 'set-cookie') {
            $valoare = (string) preg_replace('/^([^=;]+)=[^;]*/', '$1=<VALOARE>', $valoare);
            $valoare = (string) preg_replace('/(;\s*expires=)[^;]*/i', '$1<DATA>', $valoare);
        } elseif ($nume === 'etag' || $nume === 'last-modified') {
            $valoare = '<VALOARE>';
        }
        $linii[] = $nume . ': ' . $valoare;
    }
    return $linii;
}

/**
 * Verificarea mecanismului, fără server: un marcaj declarat dar lipsă, unul găsit de
 * două ori, unul nedeclarat, unul fără pereche, un text pe altă pagină, un cod de stare
 * declarat care nu mai e cel primit trebuie să pice; cazurile bune trebuie să treacă și
 * să lase exact pagina de bază.
 *
 * @return list<string> ce nu a mers (gol = totul în regulă)
 */
function diferente_autotest(): array
{
    $esecuri = [];
    $ruleaza = static function (string $caz, callable $f, bool $trebuiePica, ?string $paginaAsteptata = null) use (&$esecuri): void {
        $gasite = [];
        $probleme = [];
        $rezultat = $f($gasite, $probleme);
        $aPicat = $probleme !== [];
        if ($aPicat !== $trebuiePica) {
            $esecuri[] = $caz . ': ' . ($trebuiePica ? 'trebuia să pice și a trecut' : 'a picat: ' . implode('; ', $probleme));
        } elseif ($paginaAsteptata !== null && $rezultat !== $paginaAsteptata) {
            $esecuri[] = $caz . ': pagina rămasă diferă (' . json_encode($rezultat, JSON_UNESCAPED_UNICODE) . ')';
        }
    };
    $marcaje = ['bv:bloc' => ['cale' => '/admin/products', 'motiv' => 'test'], 'gr:peste-tot' => ['cale' => '/admin*', 'motiv' => 'test']];
    $baza = '<p>a</p><p>b</p>';
    $cuBloc = '<p>a</p><!--port:bv:bloc--><div>nou</div><!--/port:bv:bloc--><p>b</p>';
    $peAdmin = static fn (string $html): string => str_replace('<p>b</p>', '<!--port:gr:peste-tot-->x<!--/port:gr:peste-tot--><p>b</p>', $html);

    $ruleaza('marcaj declarat, găsit o dată, pe pagina lui', static fn (array &$g, array &$p): string
        => diferente_marcaje($marcaje, '/admin/products', $peAdmin($cuBloc), $g, $p), false, $baza);
    $ruleaza('marcaj declarat, dar lipsă de pe pagina lui', static fn (array &$g, array &$p): string
        => diferente_marcaje($marcaje, '/admin/products', $peAdmin($baza), $g, $p), true);
    $ruleaza('marcaj găsit de două ori', static fn (array &$g, array &$p): string
        => diferente_marcaje($marcaje, '/admin/products', $peAdmin(str_replace('<p>a</p>', '<p>a</p><!--port:bv:bloc-->y<!--/port:bv:bloc-->', $cuBloc)), $g, $p), true);
    $ruleaza('marcaj pe altă pagină decât a lui', static fn (array &$g, array &$p): string
        => diferente_marcaje($marcaje, '/admin/orders', $peAdmin($cuBloc), $g, $p), true);
    $ruleaza('marcaj nedeclarat', static fn (array &$g, array &$p): string
        => diferente_marcaje($marcaje, '/admin/products', $peAdmin($cuBloc) . '<!--port:vt:altul-->z<!--/port:vt:altul-->', $g, $p), true);
    $ruleaza('marcaj fără pereche', static fn (array &$g, array &$p): string
        => diferente_marcaje($marcaje, '/admin/products', $peAdmin($cuBloc) . '<!--port:bv:bloc-->', $g, $p), true);
    $ruleaza('marcaj cu „/admin*” lipsă de pe o pagină de admin', static fn (array &$g, array &$p): string
        => diferente_marcaje($marcaje, '/admin/orders', $baza, $g, $p), true);
    $ruleaza('marcaj cu „/admin*” pe o pagină a site-ului', static fn (array &$g, array &$p): string
        => diferente_marcaje(['gr:peste-tot' => $marcaje['gr:peste-tot']], '/cos', $peAdmin($baza), $g, $p), true);
    $ruleaza('marcaj declarat fără motiv', static fn (array &$g, array &$p): string
        => diferente_marcaje(['bv:bloc' => ['cale' => '/admin/products']], '/admin/products', $cuBloc, $g, $p), true);

    $texte = ['$deBioscem' => [['nume' => 't', 'cale' => '/magazin', 'nou' => 'Text nou', 'vechi' => 'Text vechi', 'motiv' => 'test']]];
    $ruleaza('text găsit o dată, pe pagina lui', static fn (array &$g, array &$p): string
        => diferente_texte($texte, '/magazin', '<b>Text nou</b>', $g, $p), false, '<b>Text vechi</b>');
    $ruleaza('text lipsă de pe pagina lui', static fn (array &$g, array &$p): string
        => diferente_texte($texte, '/magazin', '<b>Text vechi</b>', $g, $p), true);
    $ruleaza('text găsit de două ori', static fn (array &$g, array &$p): string
        => diferente_texte($texte, '/magazin', '<b>Text nou</b><i>Text nou</i>', $g, $p), true);
    $ruleaza('text pe altă pagină', static fn (array &$g, array &$p): string
        => diferente_texte($texte, '/cos', '<b>Text nou</b>', $g, $p), true);
    $ruleaza('text declarat fără motiv', static fn (array &$g, array &$p): string
        => diferente_texte(['$deBioscem' => [['nume' => 't', 'cale' => '/magazin', 'nou' => 'Text nou', 'vechi' => '']]],
            '/magazin', '<b>Text nou</b>', $g, $p), true);

    // Codurile de stare declarate: doar exact codurile declarate, cu textul doar pe pagina curentă.
    $coduri = ['$coduriDeBioscem' => [['nume' => 'c', 'cale' => '/checkout/succes', 'cod_baza' => 500, 'cod_curent' => 200,
        'text' => '<h1>Mulțumim</h1>', 'motiv' => 'test']]];
    $cazuriCoduri = [
        'cod declarat, coduri exacte, textul doar pe pagina curentă' => ['/checkout/succes', 500, '', 200, '<h1>Mulțumim</h1>', false],
        'codul de bază s-a reparat (200)' => ['/checkout/succes', 200, '<h1>Mulțumim</h1>', 200, '<h1>Mulțumim</h1>', true],
        'codul de bază alt cod de eroare (404)' => ['/checkout/succes', 404, '', 200, '<h1>Mulțumim</h1>', true],
        'codul curent cade și el (500)' => ['/checkout/succes', 500, '', 500, '', true],
        'pagina curentă fără textul declarat' => ['/checkout/succes', 500, '', 200, '<h1>Altceva</h1>', true],
        'textul declarat și în răspunsul bazei' => ['/checkout/succes', 500, '<h1>Mulțumim</h1>', 200, '<h1>Mulțumim</h1>', true],
    ];
    foreach ($cazuriCoduri as $caz => [$cale, $codBaza, $corpBaza, $codCurent, $corpCurent, $trebuiePica]) {
        $probleme = [];
        $acoperita = diferente_coduri($coduri, $cale, $codBaza, $corpBaza, $codCurent, $corpCurent, $probleme);
        if ($acoperita === null || ($probleme !== []) !== $trebuiePica) {
            $esecuri[] = 'coduri, ' . $caz . ': ' . ($trebuiePica ? 'trebuia să pice și a trecut' : 'a picat: ' . implode('; ', $probleme));
        }
    }
    $probleme = [];
    if (diferente_coduri($coduri, '/checkout', 200, '', 200, '', $probleme) !== null || $probleme !== []) {
        $esecuri[] = 'coduri, altă pagină: nu trebuia acoperită de declarație';
    }
    $probleme = [];
    diferente_coduri(['$coduriDeBioscem' => [['nume' => 'c', 'cale' => '/checkout/succes', 'cod_baza' => 500, 'cod_curent' => 200,
        'text' => '<h1>Mulțumim</h1>']]], '/checkout/succes', 500, '', 200, '<h1>Mulțumim</h1>', $probleme);
    if ($probleme === []) {
        $esecuri[] = 'coduri, declarație fără motiv: trebuia să pice și a trecut';
    }

    // Anteturile: ce nu ține de cod (ora, lungimea, valoarea cookie-ului) nu contează; un antet
    // pierdut, schimbat sau mutat, ori un atribut de cookie schimbat, contează.
    $antet = static fn (string $port, string $cc, string $extra = '', string $cookie = 'abc123; path=/; HttpOnly; SameSite=Lax'): string
        => "HTTP/1.1 200 OK\r\nHost: 127.0.0.1:" . $port . "\r\nDate: Mon, 19 Oct 2026 10:00:0" . strlen($cc) % 10 . " GMT\r\n"
        . 'Set-Cookie: PHPSESSID=' . $cookie . "\r\nContent-Type: application/xml; charset=utf-8\r\nCache-Control: " . $cc . "\r\n"
        . $extra . 'Content-Length: ' . strlen($cc) . "\r\nLink: <http://127.0.0.1:" . $port . "/>; rel=\"canonical\"\r\n\r\n";
    $baza = diferente_antete($antet('8702', 'public, max-age=3600', "X-Robots-Tag: noindex\r\n"), '8702', '8705');
    $cazuriAntete = [
        'aceleași anteturi, altă oră, alt cookie, alt port' => [$antet('8705', 'public, max-age=3600', "X-Robots-Tag: noindex\r\nX-Andaxi-Garda: 1\r\n", 'zz9; path=/; HttpOnly; SameSite=Lax'), true],
        'X-Robots-Tag pierdut' => [$antet('8705', 'public, max-age=3600'), false],
        'Cache-Control schimbat' => [$antet('8705', 'public, max-age=60', "X-Robots-Tag: noindex\r\n"), false],
        'atribut de cookie schimbat' => [$antet('8705', 'public, max-age=3600', "X-Robots-Tag: noindex\r\n", 'abc123; path=/; SameSite=Lax'), false],
        'antet în plus' => [$antet('8705', 'public, max-age=3600', "X-Robots-Tag: noindex\r\nVary: Cookie\r\n"), false],
    ];
    foreach ($cazuriAntete as $caz => [$curent, $trebuieIdentice]) {
        if ((diferente_antete($curent, '8702', '8705') === $baza) !== $trebuieIdentice) {
            $esecuri[] = 'anteturi, ' . $caz . ': ' . ($trebuieIdentice ? 'trebuiau să iasă identice' : 'trebuia să pice și a trecut');
        }
    }
    return $esecuri;
}
