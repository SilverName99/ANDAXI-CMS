<?php

declare(strict_types=1);

/**
 * Diferențele așteptate față de codul de bază, folosite de tools/dev/compara-cu-baza.php.
 * Se scot doar din pagina codului curent; restul paginii se compară octet cu octet.
 *
 *  - texte declarate: [nume, cale, nou, vechi, motiv, ori]. „nou” e textul exact scris de
 *    codul curent, „vechi” ce scrie codul de bază în locul lui; fiecare trebuie găsit
 *    exact o dată (sau exact „ori” ori, de exemplu titlul unei pagini, care e și în
 *    og:title și în twitter:title) pe fiecare pagină a lui și niciodată pe altă pagină;
 *  - marcaje de port: `<!--port:<sursa>:<NUME>-->…<!--/port:<sursa>:<NUME>-->` (scrise de
 *    App\Support\Dev::marcaj); blocul întreg se scoate. Fiecare nume e declarat o
 *    singură dată, cu pagina lui, și trebuie găsit exact o dată pe ea; un marcaj
 *    nedeclarat, desperecheat sau găsit pe altă pagină face comparația să pice.
 *
 *  - coduri de stare declarate (diferente_coduri): o pagină care în codul de bază cade
 *    (500) și aici merge; se verifică ambele coduri și un text al paginii curente.
 *
 * „cale” e pagina comparată (cu parametrii ei, ca în lista de pagini) sau un prefix
 * terminat în „*” („/admin*” = fiecare pagină comparată care începe cu /admin); la un
 * text declarat poate fi și o listă de astfel de pagini (aceeași schimbare pe mai multe
 * pagini, de exemplu un rând nou în <head>): textul trebuie găsit pe fiecare dintre ele.
 * Un text declarat poate avea și „fara_pagini” (WP-12): paginile (sau prefixele) acoperite
 * de „cale” pe care textul NU trebuie să apară deloc, de exemplu „/admin*” fără cele patru
 * editoare de cod, unde schimbarea nu se face; acolo un text găsit e o problemă. Și
 * „ori_pe_pagina” (WP-12): pentru unele dintre paginile din „cale” (cheile, scrise exact ca
 * acolo), alt număr exact de apariții decât „ori” (de exemplu pagina cu două selectoare de
 * imagini, unde aceeași schimbare apare de două ori mai des).
 * Plus anteturile răspunsului, normalizate pentru comparare (diferente_antete).
 * Funcții pure (fără server și fără bază), verificate de diferente_autotest().
 */

/** Pagina comparată e una dintre paginile declarației (o pagină, un prefix cu „*” sau o listă de ele)? */
function diferenta_pe_pagina(string|array $declarata, string $cale): bool
{
    if (is_array($declarata)) {
        foreach ($declarata as $una) {
            if (is_string($una) && $una !== '' && diferenta_pe_pagina($una, $cale)) {
                return true;
            }
        }
        return false;
    }
    return str_ends_with($declarata, '*')
        ? str_starts_with($cale, substr($declarata, 0, -1))
        : $declarata === $cale;
}

/**
 * Ce lipsește dintr-o declarație de text (gol = completă): nume, cale (o pagină sau o listă nevidă
 * de pagini, fiecare un text nevid), nou, motiv; „ori”, dacă e dat, un număr întreg de cel puțin 1;
 * „fara_pagini”, dacă e dat, o listă nevidă de pagini, fiecare un text nevid; „ori_pe_pagina”, dacă
 * e dat, o listă nevidă pagină => număr întreg de cel puțin 1, fiecare pagină una din „cale”.
 */
function diferenta_declaratie_incompleta(array $intrare): string
{
    foreach (['nume', 'nou', 'motiv'] as $camp) {
        if (!is_string($intrare[$camp] ?? null) || trim($intrare[$camp]) === '') {
            return $camp;
        }
    }
    $cale = $intrare['cale'] ?? null;
    $pagini = is_array($cale) ? $cale : [$cale];
    if ($pagini === [] || array_filter($pagini, static fn ($p): bool => !is_string($p) || trim($p) === '') !== []) {
        return 'cale';
    }
    if (array_key_exists('ori', $intrare) && (!is_int($intrare['ori']) || $intrare['ori'] < 1)) {
        return 'ori';
    }
    if (array_key_exists('fara_pagini', $intrare)) {
        $excluse = $intrare['fara_pagini'];
        if (!is_array($excluse) || $excluse === []
            || array_filter($excluse, static fn ($p): bool => !is_string($p) || trim($p) === '') !== []) {
            return 'fara_pagini';
        }
    }
    if (array_key_exists('ori_pe_pagina', $intrare)) {
        $peFiecare = $intrare['ori_pe_pagina'];
        if (!is_array($peFiecare) || $peFiecare === []) {
            return 'ori_pe_pagina';
        }
        foreach ($peFiecare as $paginaOri => $numarOri) {
            if (!is_string($paginaOri) || !in_array($paginaOri, $pagini, true) || !is_int($numarOri) || $numarOri < 1) {
                return 'ori_pe_pagina';
            }
        }
    }
    return '';
}

/**
 * Pune la loc textele declarate. Problemele (text negăsit pe pagina lui, găsit de mai
 * multe ori sau pe altă pagină, declarație incompletă) se adaugă în $probleme.
 *
 * @param array<string, list<array{nume?: string, cale?: string|list<string>, nou?: string, vechi?: string, motiv?: string, ori?: int, fara_pagini?: list<string>, ori_pe_pagina?: array<string, int>}>> $liste
 * @param array<string, int> $gasite
 * @param list<string> $probleme
 */
function diferente_texte(array $liste, string $cale, string $html, array &$gasite, array &$probleme): string
{
    foreach ($liste as $numeLista => $lista) {
        foreach ($lista as $intrare) {
            $nume = is_string($intrare['nume'] ?? null) ? $intrare['nume'] : '';
            $eticheta = $numeLista . ' „' . $nume . '”';
            $lipsa = diferenta_declaratie_incompleta($intrare);
            if ($lipsa !== '') {
                $probleme[] = $eticheta . ': „' . $lipsa . '” lipsește sau e greșit (fiecare diferență se declară cu pagina și motivul ei; '
                    . '„ori”, dacă e dat, e un număr de cel puțin 1)';
                continue;
            }
            $html = str_replace((string) $intrare['nou'], (string) ($intrare['vechi'] ?? ''), $html, $n);
            $asteptat = 0;
            if (diferenta_pe_pagina($intrare['cale'], $cale)
                && !(isset($intrare['fara_pagini']) && diferenta_pe_pagina($intrare['fara_pagini'], $cale))) {
                $asteptat = (int) ($intrare['ori'] ?? 1);
                foreach ((array) ($intrare['ori_pe_pagina'] ?? []) as $paginaOri => $numarOri) {
                    if (diferenta_pe_pagina((string) $paginaOri, $cale)) {
                        $asteptat = (int) $numarOri;
                        break;
                    }
                }
            }
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
 * declarat care nu mai e cel primit trebuie să pice; o declarație greșită (fără motiv, cu
 * o listă de pagini goală, cu „ori” care nu e un întreg de cel puțin 1) trebuie să pice cu
 * numele câmpului, pe o pagină care altfel s-ar potrivi cu numărătoarea; cazurile bune
 * trebuie să treacă și să lase exact pagina de bază.
 *
 * @return list<string> ce nu a mers (gol = totul în regulă)
 */
function diferente_autotest(): array
{
    $esecuri = [];
    // $motivAsteptat: la un caz care trebuie să pice, textul pe care îl are cel puțin una dintre probleme
    // (de exemplu: „ori” lipsește sau e greșit), ca să pice din motivul testat, nu din altul (o numărătoare greșită).
    $ruleaza = static function (string $caz, callable $f, bool $trebuiePica, ?string $paginaAsteptata = null, ?string $motivAsteptat = null) use (&$esecuri): void {
        $gasite = [];
        $probleme = [];
        $rezultat = $f($gasite, $probleme);
        $aPicat = $probleme !== [];
        if ($aPicat !== $trebuiePica) {
            $esecuri[] = $caz . ': ' . ($trebuiePica ? 'trebuia să pice și a trecut' : 'a picat: ' . implode('; ', $probleme));
        } elseif ($motivAsteptat !== null && array_filter($probleme, static fn (string $p): bool => str_contains($p, $motivAsteptat)) === []) {
            $esecuri[] = $caz . ': a picat, dar nu din motivul testat (' . $motivAsteptat . '): ' . implode('; ', $probleme);
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
            '/magazin', '<b>Text nou</b>', $g, $p), true, null, '„motiv” lipsește sau e greșit');
    // O listă de pagini: textul pe fiecare dintre ele (o dată), pe nicio altă pagină.
    $peMaiMulte = ['$porturiBioscem' => [['nume' => 'l', 'cale' => ['/', '/magazin', '/produs/*'], 'nou' => 'Text nou', 'vechi' => 'Text vechi',
        'motiv' => 'test']]];
    foreach (['/', '/magazin', '/produs/ceai'] as $paginaListei) {
        $ruleaza('text cu o listă de pagini, găsit o dată pe ' . $paginaListei, static fn (array &$g, array &$p): string
            => diferente_texte($peMaiMulte, $paginaListei, '<b>Text nou</b>', $g, $p), false, '<b>Text vechi</b>');
    }
    $ruleaza('text cu o listă de pagini, lipsă de pe una dintre ele', static fn (array &$g, array &$p): string
        => diferente_texte($peMaiMulte, '/magazin', '<b>Text vechi</b>', $g, $p), true);
    $ruleaza('text cu o listă de pagini, găsit pe o pagină din afara listei', static fn (array &$g, array &$p): string
        => diferente_texte($peMaiMulte, '/cos', '<b>Text nou</b>', $g, $p), true);
    // „ori”: exact atâtea apariții pe fiecare pagină a declarației (nici mai puține, nici mai multe).
    $deTrei = ['$porturiBioscem' => [['nume' => 'o', 'cale' => '/produs/ceai', 'nou' => 'Ceai – Marca | Firma', 'vechi' => 'Ceai | Firma', 'motiv' => 'test',
        'ori' => 3]]];
    $trei = '<title>Ceai – Marca | Firma</title><meta content="Ceai – Marca | Firma"><meta content="Ceai – Marca | Firma">';
    $ruleaza('text cu „ori” 3, găsit de 3 ori', static fn (array &$g, array &$p): string
        => diferente_texte($deTrei, '/produs/ceai', $trei, $g, $p), false, str_replace('Ceai – Marca | Firma', 'Ceai | Firma', $trei));
    $ruleaza('text cu „ori” 3, găsit de 2 ori', static fn (array &$g, array &$p): string
        => diferente_texte($deTrei, '/produs/ceai', substr($trei, 0, (int) strrpos($trei, '<meta')), $g, $p), true);
    $ruleaza('text cu „ori” 3, găsit de 4 ori', static fn (array &$g, array &$p): string
        => diferente_texte($deTrei, '/produs/ceai', $trei . '<p>Ceai – Marca | Firma</p>', $g, $p), true);
    $ruleaza('text cu „ori” 3, pe altă pagină', static fn (array &$g, array &$p): string
        => diferente_texte($deTrei, '/produs/alt-ceai', $trei, $g, $p), true);
    // Declarații greșite („cale” sau „ori”). Pagina fiecărui caz se potrivește cu ce ar număra declarația
    // dacă ar fi primită (un text lipsă la 0 apariții sau la o listă goală, de 3 ori la „3”, o dată la
    // 1.0), deci cazul pică doar dacă declarația e respinsă, și trebuie să pice cu numele câmpului.
    $declaratiiGresite = [
        'listă de pagini goală' => [['cale' => []], '<b>Text vechi</b>', 'cale'],
        'listă de pagini cu o pagină goală' => [['cale' => ['/', '']], '<b>Text nou</b>', 'cale'],
        '„ori” 0' => [['ori' => 0], '<b>Text vechi</b>', 'ori'],
        '„ori” negativ' => [['ori' => -1], '<b>Text vechi</b>', 'ori'],
        '„ori” text' => [['ori' => '3'], str_repeat('<b>Text nou</b>', 3), 'ori'],
        '„ori” zecimal' => [['ori' => 1.0], '<b>Text nou</b>', 'ori'],
        '„ori” null' => [['ori' => null], '<b>Text nou</b>', 'ori'],
    ];
    foreach ($declaratiiGresite as $cazGresit => [$campuri, $htmlGresit, $campGresit]) {
        $ruleaza('text declarat cu ' . $cazGresit, static fn (array &$g, array &$p): string
            => diferente_texte(['$porturiBioscem' => [array_merge(['nume' => 'g', 'cale' => '/', 'nou' => 'Text nou', 'vechi' => 'Text vechi',
                'motiv' => 'test'], $campuri)]], '/', $htmlGresit, $g, $p), true, null, '„' . $campGresit . '” lipsește sau e greșit');
    }
    // „fara_pagini” (WP-12): un prefix cu pagini scoase; pe ele textul nu are voie să apară, pe restul exact o dată.
    $faraEditoare = ['$porturiBiovitality' => [['nume' => 'f', 'cale' => '/admin*', 'fara_pagini' => ['/admin/design', '/admin/pages/*'],
        'nou' => 'Text nou', 'vechi' => 'Text vechi', 'motiv' => 'test']]];
    $ruleaza('text cu „fara_pagini”, găsit o dată pe o pagină a prefixului', static fn (array &$g, array &$p): string
        => diferente_texte($faraEditoare, '/admin/orders', '<b>Text nou</b>', $g, $p), false, '<b>Text vechi</b>');
    foreach (['/admin/design', '/admin/pages/7/edit'] as $paginaScoasa) {
        $ruleaza('text cu „fara_pagini”, lipsă pe pagina scoasă ' . $paginaScoasa, static fn (array &$g, array &$p): string
            => diferente_texte($faraEditoare, $paginaScoasa, '<b>Text vechi</b>', $g, $p), false, '<b>Text vechi</b>');
        $ruleaza('text cu „fara_pagini”, găsit pe pagina scoasă ' . $paginaScoasa, static fn (array &$g, array &$p): string
            => diferente_texte($faraEditoare, $paginaScoasa, '<b>Text nou</b>', $g, $p), true);
    }
    $ruleaza('text cu „fara_pagini”, lipsă de pe o pagină a prefixului', static fn (array &$g, array &$p): string
        => diferente_texte($faraEditoare, '/admin/orders', '<b>Text vechi</b>', $g, $p), true);
    $ruleaza('text cu „fara_pagini”, găsit pe o pagină din afara prefixului', static fn (array &$g, array &$p): string
        => diferente_texte($faraEditoare, '/cos', '<b>Text nou</b>', $g, $p), true);
    // „ori_pe_pagina” (WP-12): pe pagina din listă, numărul ei; pe celelalte pagini, „ori”.
    $peDoua = ['$porturiBiovitality' => [['nume' => 'p', 'cale' => ['/admin/products', '/admin/settings/store'], 'ori' => 2,
        'ori_pe_pagina' => ['/admin/settings/store' => 4], 'nou' => 'Text nou', 'vechi' => 'Text vechi', 'motiv' => 'test']]];
    $ruleaza('text cu „ori_pe_pagina”, de 4 ori pe pagina cu alt număr', static fn (array &$g, array &$p): string
        => diferente_texte($peDoua, '/admin/settings/store', str_repeat('<b>Text nou</b>', 4), $g, $p), false, str_repeat('<b>Text vechi</b>', 4));
    $ruleaza('text cu „ori_pe_pagina”, de 2 ori pe pagina cu alt număr (4)', static fn (array &$g, array &$p): string
        => diferente_texte($peDoua, '/admin/settings/store', str_repeat('<b>Text nou</b>', 2), $g, $p), true);
    $ruleaza('text cu „ori_pe_pagina”, de 2 ori pe celelalte pagini („ori”)', static fn (array &$g, array &$p): string
        => diferente_texte($peDoua, '/admin/products', str_repeat('<b>Text nou</b>', 2), $g, $p), false, str_repeat('<b>Text vechi</b>', 2));
    $ruleaza('text cu „ori_pe_pagina”, de 4 ori pe celelalte pagini', static fn (array &$g, array &$p): string
        => diferente_texte($peDoua, '/admin/products', str_repeat('<b>Text nou</b>', 4), $g, $p), true);
    // „ori_pe_pagina” greșit: pe pagina lui, cu exact cât ar număra declarația dacă ar fi primită.
    foreach (['listă goală' => [[], 2], 'o pagină din afara „cale”' => [['/cos' => 4], 2], 'un număr text' => [['/admin/settings/store' => '4'], 2],
        'un număr 0' => [['/admin/settings/store' => 0], 2]] as $cazOri => [$oriGresit, $deCateOri]) {
        $ruleaza('text declarat cu „ori_pe_pagina” ' . $cazOri, static fn (array &$g, array &$p): string
            => diferente_texte(['$porturiBiovitality' => [array_merge($peDoua['$porturiBiovitality'][0], ['ori_pe_pagina' => $oriGresit])]],
                '/admin/settings/store', str_repeat('<b>Text nou</b>', $deCateOri), $g, $p), true, null, '„ori_pe_pagina” lipsește sau e greșit');
    }
    // „fara_pagini” greșit: pe o pagină scoasă, unde textul lipsește (cât ar număra declarația dacă ar fi primită).
    foreach (['listă goală' => [], 'un text, nu o listă' => '/admin/design', 'listă cu o pagină goală' => ['/admin/design', '']] as $cazFara => $faraGresit) {
        $ruleaza('text declarat cu „fara_pagini” ' . $cazFara, static fn (array &$g, array &$p): string
            => diferente_texte(['$porturiBiovitality' => [['nume' => 'f', 'cale' => '/admin*', 'fara_pagini' => $faraGresit, 'nou' => 'Text nou',
                'vechi' => 'Text vechi', 'motiv' => 'test']]], '/admin/design', '<b>Text vechi</b>', $g, $p), true, null, '„fara_pagini” lipsește sau e greșit');
    }

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
