<?php

declare(strict_types=1);

/**
 * deploy/revino.sh: întoarce un site adoptat (sau o adoptare oprită la jumătate) la codul lui vechi, în câteva minute
 * (docs/DEPLOY.md, „Revenirea”). Implicit doar planul; cu --aplica:
 *
 *   1. mentenanța strictă (nici vizitatorii, nici plățile, ERP-ul sau cronurile nu mai scriu), apoi așteptarea
 *      conexiunilor care mai lucrează pe bază;
 *   2. verificarea datelor noi: amprenta bazei de acum (structura și CHECKSUM TABLE ale fiecărui tabel) comparată cu
 *      cea de la sfârșitul migrărilor. Orice tabel schimbat de atunci (o plată confirmată, o comandă, o schimbare din
 *      admin, un stoc din ERP) înseamnă date care s-ar pierde: refuz, cu lista tabelelor, afară de --pierde-datele-noi;
 *      la fel după ce site-ul a fost deschis (deschidere), oricum ar arăta amprenta. La un refuz înainte ca revenirea să
 *      schimbe ceva, mentenanța revine cum era; după ce a început (baza sau dosarele), rămâne strictă;
 *   3. baza pusă la loc din copia de revenire (făcută în mentenanța strictă, înainte de migrări), dacă migrările au
 *      pornit: întâi se verifică fișierul (gzip -t) și că e copia acestei baze (numele și gazda din .env), apoi
 *      tabelele, vederile, rutinele și evenimentele de după copie se șterg, copia se încarcă și amprenta se compară cu
 *      cea de la copie (dacă diferă: oprire în mentenanța strictă, codul neîntors; reluarea încarcă din nou copia);
 *   4. pozele (uploads/) mutate înapoi în codul vechi;
 *   5. public_html (codul nou) → public_html.esuat-<data>, public_html.vechi → public_html;
 *   6. codul vechi comparat cu amprenta lui de la comutare (fiecare fișier, octet cu octet; la poze mărimea și data);
 *   7. site-ul vechi verificat de pe web (prima pagină 200); MENTENANTA ștearsă.
 *
 * Fiecare pas se ține minte în starea adoptării: o revenire oprită la jumătate (SSH-ul căzut) se reia cu aceeași comandă,
 * iar o redenumire rămasă la jumătate se termină înainte de orice.
 *
 *   deploy/revino.sh --site=biovitality            # planul: ce ar face și ce date noi s-ar pierde (o estimare)
 *   deploy/revino.sh --site=biovitality --aplica
 *
 * --fara-baza lasă baza cum e (migrările doar adaugă, deci codul vechi merge și pe ea, docs/MIGRARI.md, „Regulile”):
 * nicio dată nu se pierde. După o încărcare întreagă a copiei care diferă de copie, revenirea se oprește în mentenanța
 * strictă până alegi: --accepta-baza-diferita (codul vechi pe baza încărcată, cu diferențele arătate; refuzat dacă baza
 * s-a schimbat de la încărcare), --fara-baza (baza lăsată cum e acum, fără altă încărcare) sau --reincarca-baza (copia
 * încărcată din nou, pentru o diferență întâmplătoare). Alte opțiuni: --domeniu=<d> (în locul lui --site), --url=<adresa>, --acasa=<dosar> (teste).
 */

namespace Platforma\Deploy;

require __DIR__ . '/deploy.php';

exit(porneste(static function () use ($argv): int {
    $o = Optiuni::citeste($argv, ['site=', 'domeniu=', 'url=', 'acasa=', 'fara-baza', 'pierde-datele-noi', 'forteaza-liniste', 'rezolvat-de-mana', 'accepta-baza-diferita', 'reincarca-baza', 'plan', 'aplica']);
    $aplica = $o->aplica();
    $s = Site::gaseste($o);
    $lacat = $s->lacat();
    $url = rtrim($o->text('url') ?? 'https://' . $s->domeniu, '/');
    $fisierStare = $s->lucru . '/adopta.stare';
    $stare = new Stare($fisierStare);
    Iesire::titlu('Revenirea site-ului ' . $s->domeniu . ' la codul vechi' . ($aplica ? '' : ' — PLAN'));
    if (!is_file($fisierStare)) {
        throw new Oprire('nu există ' . $fisierStare . ': site-ul n-a fost adoptat cu deploy/adopta.sh (sau a fost deja întors)');
    }
    // O actualizare neterminată (actualizeaza.sh) are mentenanța și întoarcerea ei: revenirea ar amesteca două deploy-uri
    // (și ar pune la loc o mentenanță de dinaintea actualizării).
    $act = new Stare($s->lucru . '/actualizare.stare');
    if (is_file($act->fisier) && !$act->ia('gata', false) && !$act->ia('intoarsa', false)) {
        $spre = (string) $act->ia('versiune_noua', '?');
        throw new Oprire('o actualizare spre ' . $spre . ' a rămas neterminată (' . $act->fisier . '); o termini întâi, apoi revii', 2, [
            'aceeași comandă de actualizare: deploy/actualizeaza.sh --site=' . $s->domeniu . ' --versiune=' . $spre . ' --aplica (merge mai departe sau se întoarce singură)',
            'dacă întoarcerea ei a rămas fără testul de fum trecut: aceeași comandă cu --accepta-revenirea (deschide versiunea de dinainte), sau o versiune reparată',
        ]);
    }
    Mentenanta::avertizeazaVeche($s);
    $inceput = microtime(true);
    if ($aplica) {
        Iesire::jurnal($s->lucru . '/jurnale/revino-' . date('Ymd-His') . '.log');
        Redenumiri::termina($stare, $o->are('rezolvat-de-mana'), true);
    } elseif ((array) $stare->ia('redenumiri', []) !== []) {
        Iesire::atentie('o rulare de dinainte s-a oprit la jumătatea unei redenumiri (' . implode(', ', array_keys((array) $stare->ia('redenumiri'))) . '); --aplica o termină întâi');
    }
    $facute = (array) $stare->ia('redenumiri_facute', []);
    $lasate = (array) $stare->ia('redenumiri_lasate', []);
    $rev = (array) $stare->ia('revino', []);
    // Întoarcerea codului lăsată de mână (--rezolvat-de-mana): ce s-a făcut o spun dosarele. Gata: codul vechi (checkout-ul,
    // la commit-ul de la verificări) în public_html, codul nou în public_html.esuat-…, fără public_html.vechi. Neîncepută:
    // stub-ul în public_html, codul vechi în public_html.vechi, fără public_html.esuat-… (pasul se face din nou). Altfel, refuz.
    if (in_array('revino-cod', $lasate, true) && !in_array('revino-cod', $facute, true)) {
        $esuatLasat = (string) ($rev['esuat'] ?? '');
        $asteptat = (string) $stare->ia('commit_vechi_complet', '');
        $head = is_dir($s->docroot . '/.git') ? trim(Comanda::ruleaza(['git', '-C', $s->docroot, 'rev-parse', 'HEAD'])['iesire']) : '';
        if ($asteptat !== '' && $head === $asteptat && $esuatLasat !== '' && is_dir($esuatLasat) && !file_exists($s->vechi)) {
            Iesire::atentie('pasul „revino-cod” lăsat de mână: codul vechi e în ' . $s->docroot . ' (commit ' . substr($head, 0, 12) . '), codul nou în '
                . $esuatLasat . '; îl socotesc făcut');
            if ($aplica) {
                $facute[] = 'revino-cod';
                $stare->pune('redenumiri_facute', array_values(array_unique($facute)));
            } else {
                $facute[] = 'revino-cod';
            }
        } elseif ($s->cuStub() && is_dir($s->vechi) && ($esuatLasat === '' || !file_exists($esuatLasat))) {
            Iesire::atentie('pasul „revino-cod” lăsat de mână: dosarele sunt cum erau înainte de el; îl fac din nou');
            if ($aplica) {
                $stare->pune('redenumiri_lasate', array_values(array_diff($lasate, ['revino-cod'])));
            }
        } else {
            throw new Oprire('pasul „revino-cod” a fost lăsat de mână (--rezolvat-de-mana), dar dosarele nu sunt în niciuna dintre cele două așezări pe care le '
                . 'recunosc; nu schimb nimic', 1, [
                'întoarcerea făcută: codul vechi (checkout-ul git la ' . ($asteptat !== '' ? substr($asteptat, 0, 12) : '?') . ') în ' . $s->docroot . ', codul nou în '
                    . ($esuatLasat !== '' ? $esuatLasat : $s->dosar . '/public_html.esuat-<data>') . ', fără ' . $s->vechi,
                'sau neîncepută: codul nou (stub-ul) în ' . $s->docroot . ', codul vechi în ' . $s->vechi . ($esuatLasat !== '' ? ', fără ' . $esuatLasat : ''),
                'pui dosarele într-una dintre ele, apoi aceeași comandă; sau ne trimiți ce vezi în ' . $s->dosar . ' (ls -la)',
            ]);
        }
    }
    $comutat = in_array('comutare', $facute, true) || isset($stare->date['redenumiri']['comutare']) || (bool) $stare->ia('comutat', false);

    $comutareLasata = in_array('comutare', $lasate, true);
    if (!$comutat && ($comutareLasata || $s->cuStub())) {
        // Comutarea lăsată de mână (--rezolvat-de-mana) nu mai spune dacă s-a făcut: o spune docroot-ul, care trebuie să fie
        // checkout-ul vechi, la commit-ul de la verificări. Altfel (stub-ul, alt commit) nu e „înainte de comutare”.
        $head = is_dir($s->docroot . '/.git') ? trim(Comanda::ruleaza(['git', '-C', $s->docroot, 'rev-parse', 'HEAD'])['iesire']) : '';
        $asteptat = (string) $stare->ia('commit_vechi_complet', '');
        if ($asteptat === '' || $head !== $asteptat) {
            throw new Oprire(($comutareLasata ? 'pasul „comutare” a fost lăsat de mână (--rezolvat-de-mana)' : 'starea nu spune că site-ul a fost comutat')
                . ', iar în ' . $s->docroot . ' nu e codul vechi (git HEAD '
                . ($head !== '' && preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? substr($head, 0, 12) : 'lipsă') . ', așteptat '
                . ($asteptat !== '' ? substr($asteptat, 0, 12) : '?') . '): nu știu dacă site-ul a fost comutat, deci nu schimb nimic', 1, [
                'pui codul vechi înapoi în ' . $s->docroot . ' (și codul nou în ' . $s->nou . '), apoi aceeași comandă',
                'sau ne trimiți ce vezi în ' . $s->dosar . ' (ls -la) și ' . $fisierStare,
            ]);
        }
    }
    if (!$comutat) {
        Iesire::info('adoptarea s-a oprit înainte de comutare: codul vechi e încă în ' . $s->docroot . ', neatins, și vizitatorii n-au văzut nimic');
        if (!$aplica) {
            Iesire::scrie('PLAN: aș șterge doar ' . $s->nou . ' (pregătirea) și aș încheia adoptarea începută; site/ rămâne. Cu --aplica o face.');
            return 0;
        }
        // Urma se scrie înainte, iar semnul .andaxi-nou se șterge primul: o ștergere omorâtă la jumătate se termină la reluare,
        // iar adopta.sh nu comută pe un public_html.nou pe jumătate șters (nici nu continuă: revenirea a început).
        $stergereInceputa = !empty($rev['sterge_nou']);
        if (is_dir($s->nou) && (is_file($s->nou . '/.andaxi-nou') || $stergereInceputa)) {
            $rev['sterge_nou'] = $rev['sterge_nou'] ?? date('c');
            $stare->pune('revino', $rev);
            @unlink($s->nou . '/.andaxi-nou');
            testOpreste('revino-sterge-nou');
            Fisiere::sterge($s->nou, [$s->dosar]);
            Iesire::ok('șters ' . $s->nou);
        }
        Mentenanta::scoate($s);
        Fisiere::muta($fisierStare, $fisierStare . '.revenit-' . date('Ymd-His'));
        Iesire::ok('adoptarea începută e încheiată; o adoptare nouă pornește de la început');
        return 0;
    }

    $codIntors = in_array('revino-cod', $facute, true);
    if (!$codIntors && !is_dir($s->vechi)) {
        throw new Oprire('lipsește ' . $s->vechi . ' (codul vechi): nu am ce pune la loc. Copia lui: ' . $stare->ia('copii') . '/cod-vechi.tar.gz');
    }
    // O revenire începută continuă cu aceeași alegere despre bază: una cu --fara-baza nu devine, la reluare, una care pune
    // baza la loc sub codul vechi deja pornit, și invers.
    $restaurareInCurs = !empty($rev['baza_in_curs']) && empty($rev['baza']);
    // O încărcare întreagă care a ieșit altfel decât copia: nu se reface singură (ar ieși la fel); omul alege.
    $bazaDiferita = !empty($rev['baza_diferita']) && empty($rev['baza']) && !$restaurareInCurs;
    $alegere = $o->are('fara-baza') ? 'fara-baza' : 'cu-baza';
    $alegeri = array_filter(['fara-baza', 'accepta-baza-diferita', 'reincarca-baza'], static fn (string $x): bool => $o->are($x));
    if ($bazaDiferita && count($alegeri) > 1) {
        throw new Oprire('alegi una singură dintre --' . implode(', --', $alegeri), 2);
    }
    $reincarca = $bazaDiferita && $o->are('reincarca-baza');
    if ($bazaDiferita && $alegeri === []) {
        if ($aplica && $s->cuStub() && Mentenanta::mod($s) !== 'strict') {
            Mentenanta::pune($s, bin2hex(random_bytes(16)), true);
        }
        throw new Oprire('copia bazei a fost încărcată întreagă (' . (string) ($rev['baza_diferita']['facuta'] ?? '?') . '), dar baza diferă de copie: '
            . implode('; ', array_slice((array) ($rev['baza_diferita']['diferente'] ?? []), 0, 8)) . '. Codul e tot cel nou, în mentenanța strictă; alegi', 2, [
            'codul vechi pe baza încărcată, cu diferențele de mai sus: aceeași comandă cu --accepta-baza-diferita',
            'sau baza lăsată cum e acum (fără altă încărcare), doar codul întors: aceeași comandă cu --fara-baza',
            'sau copia încărcată din nou (o diferență întâmplătoare, de exemplu o scriere în timpul încărcării): aceeași comandă cu --reincarca-baza',
            'oricare, ne trimiți ieșirea; codul vechi e neatins în ' . $s->vechi . ', copia bazei în ' . (string) $stare->ia('copie_baza', ''),
        ]);
    }
    foreach (['accepta-baza-diferita', 'reincarca-baza'] as $x) {
        if ($o->are($x) && !$bazaDiferita) {
            Iesire::atentie('--' . $x . ': nu e nicio încărcare a bazei diferită de copie; o las deoparte');
        }
    }
    if (isset($rev['alegere']) && $rev['alegere'] !== $alegere && !$restaurareInCurs && !$bazaDiferita) {
        throw new Oprire('revenirea începută a ales ' . ($rev['alegere'] === 'fara-baza' ? '--fara-baza' : 'să pună baza la loc')
            . '; continu-o cu aceeași alegere (aceeași comandă ' . ($rev['alegere'] === 'fara-baza' ? 'cu' : 'fără') . ' --fara-baza)', 2);
    }
    if ($restaurareInCurs && $o->are('forteaza-liniste')) {
        throw new Oprire('o încărcare a copiei bazei a început și nu s-a terminat: încărcarea rulării oprite (gzip | mysql) poate lucra încă pe bază, '
            . 'iar --forteaza-liniste ar porni a doua încărcare peste ea. Rulezi aceeași comandă fără --forteaza-liniste (așteaptă să se termine)', 2, [
            'dacă o conexiune rămâne mult (lista o dă aceeași comandă fără --forteaza-liniste) și știi că e încărcarea oprită, o oprești din phpMyAdmin, apoi aceeași comandă',
        ]);
    }
    if ($restaurareInCurs && $o->are('fara-baza')) {
        throw new Oprire('o încărcare a copiei bazei a început și nu s-a terminat: baza e pe jumătate încărcată. Codul vechi nu pornește pe ea; '
            . 'rulezi aceeași comandă fără --fara-baza (copia se încarcă din nou, de la început)', 2);
    }
    $cuBaza = $restaurareInCurs || $reincarca || ((bool) $stare->ia('migrari_pornite', false) && !$o->are('fara-baza') && empty($rev['baza']) && !$bazaDiferita);
    if ($cuBaza && $codIntors) {
        throw new Oprire('codul vechi e deja din nou în ' . $s->docroot . ' (revenirea cu --fara-baza a mers până acolo); nu mai pun baza la loc sub el', 2);
    }
    $copieBaza = (string) $stare->ia('copie_baza', '');
    $envVechi = is_file($s->vechi . '/.env') ? $s->vechi . '/.env' : ($codIntors ? $s->docroot . '/.env' : $s->site . '/.env');
    $baza = new Baza(Env::citeste($envVechi));
    $info = [];
    if ($cuBaza) {
        if ($copieBaza === '') {
            throw new Oprire('lipsește copia bazei de revenire; fără ea, doar --fara-baza');
        }
        $info = $baza->verificaCopia($copieBaza);
    }

    Iesire::info('codul vechi  : ' . ($codIntors ? 'deja în ' . $s->docroot : $s->vechi . ' → ' . $s->docroot));
    Iesire::info('pozele       : ' . $s->docroot . '/uploads → ' . $s->vechi . '/' . ($stare->ia('uploads') ?: '(n-au fost mutate)'));
    Iesire::info('baza         : ' . (!empty($rev['baza']) ? 'deja pusă la loc' : ($bazaDiferita ? 'încărcată din copie, dar diferită de ea; '
        . ($o->are('fara-baza') ? 'lăsată așa (--fara-baza)' : ($reincarca ? 'o încarc din nou din ' . $copieBaza . ' (--reincarca-baza)'
            : 'acceptată așa (--accepta-baza-diferita), dacă n-a mai fost schimbată')) : ($restaurareInCurs ? 'ÎNCĂRCAREA COPIEI A RĂMAS LA JUMĂTATE: o reiau de la început din ' . $copieBaza
        : ($cuBaza ? 'pusă la loc din ' . $copieBaza . ' (făcută ' . (string) ($info['facuta'] ?? '?') . ')'
            : ($o->are('fara-baza') ? 'lăsată cum e (--fara-baza): codul vechi merge pe baza migrată' : 'neatinsă (migrările n-au pornit)'))))));

    // 1. Nicio scriere nouă (doar cu --aplica). Mentenanța de dinainte se ține minte în stare o singură dată, la prima
    // scriere a celei stricte, ca o revenire oprită și reluată s-o pună la loc pe cea adevărată, nu pe a ei.
    if (!isset($rev['mentenanta_inainte'])) {
        $rev['mentenanta_inainte'] = ['continut' => Mentenanta::citeste($s)];
    }
    // La un refuz înainte ca revenirea să schimbe ceva: mentenanța cum era și alegerea uitată. După ce a început (o încărcare
    // a bazei pornită, poate încă vie; o redenumire revino-* începută sau făcută), mentenanța rămâne strictă: cea de dinainte
    // ar deschide codul nou pe o bază pe jumătate încărcată sau fără poze.
    $inceputa = revenireInceputa($stare);
    $puneLaLoc = static function () use ($s, $stare, &$rev, $inceputa): void {
        if ($inceputa) {
            if (Mentenanta::mod($s) !== 'strict') {
                Mentenanta::pune($s, bin2hex(random_bytes(16)), true);
            }
            Iesire::atentie('revenirea începută a schimbat deja baza sau dosarele: mentenanța rămâne strictă până o termini (aceeași comandă)');
            return;
        }
        Mentenanta::puneLaLoc($s, $rev['mentenanta_inainte']['continut'] ?? null);
        // Și mentenanța de dinainte se uită: o revenire de mai târziu (după o actualizare, de exemplu) o citește din nou.
        unset($rev['alegere'], $rev['mentenanta_inainte']);
        $stare->pune('revino', $rev);
    };
    if ($aplica && $s->cuStub() && !$codIntors) {
        $rev['alegere'] = $alegere;
        $stare->pune('revino', $rev);
        Mentenanta::pune($s, bin2hex(random_bytes(16)), true);
        Iesire::ok('mentenanța strictă: nici vizitatorii, nici plățile, ERP-ul sau cronurile nu mai scriu');
        testOpreste('revino-mentenanta');
        // După o încărcare oprită contează și conexiunile care doar dorm (clientul mysql al ei, între două instrucțiuni).
        $baza->cereLiniste($o->are('forteaza-liniste'), $puneLaLoc, $restaurareInCurs);
    }
    // 2. Ce s-ar pierde (nu și când o încărcare a rămas la jumătate sau s-a terminat diferită de copie: atunci baza nu mai
    // e a nimănui, se reface din copie).
    if ($cuBaza && !$restaurareInCurs && !$reincarca) {
        [$blocante, $informative] = dateNoi($stare, $baza);
        foreach (array_merge($blocante, $informative) as $p) {
            Iesire::atentie($p);
        }
        if ($blocante !== [] && !$o->are('pierde-datele-noi')) {
            if ($aplica) {
                $puneLaLoc();
            }
            if (!$aplica) {
                Iesire::scrie('');
                Iesire::scrie('PLAN: nimic schimbat. Cu datele noi de mai sus, --aplica refuză: --fara-baza (baza rămâne cum e, cu ele) sau --pierde-datele-noi.');
                return 0;
            }
            throw new Oprire('baza are date scrise după migrări (mai sus); nu le șterg fără --pierde-datele-noi. Mentenanța e cum era', 1, [
                'păstrezi baza și întorci doar codul: aceeași comandă cu --fara-baza (codul vechi merge pe baza migrată; datele de mai sus rămân în ea)',
                'sau notezi întâi din admin ce s-a schimbat în tabelele de mai sus, apoi aceeași comandă cu --pierde-datele-noi',
            ]);
        }
        if ($blocante === []) {
            Iesire::ok('nicio dată scrisă de la sfârșitul migrărilor (amprenta fiecărui tabel e aceeași)' . ($aplica ? '' : ' — o estimare; --aplica verifică din nou cu scrierile oprite'));
        }
    }
    if (!$aplica) {
        Iesire::scrie('');
        Iesire::scrie('PLAN: nimic schimbat. Cu --aplica o face.');
        return 0;
    }

    // 3. Baza. „În curs” se scrie înainte: o încărcare omorâtă la jumătate se reia de la început, niciodată sărită.
    $probleme = [];
    if ($bazaDiferita && $o->are('accepta-baza-diferita')) {
        // Acceptată e baza de la încărcare, nu una schimbată de atunci (de mână, sau de o conexiune rămasă).
        $acum = Baza::diferente((array) ($rev['baza_diferita']['amprenta'] ?? []), $baza->amprenta());
        if (!isset($rev['baza_diferita']['amprenta']) || $acum !== []) {
            $puneLaLoc();
            throw new Oprire('baza s-a schimbat de la încărcarea copiei (' . (string) ($rev['baza_diferita']['facuta'] ?? '?') . '): '
                . implode('; ', array_slice($acum, 0, 8) ?: ['amprenta de după încărcare lipsește']) . '; nu o accept așa', 1, [
                'copia încărcată din nou: aceeași comandă cu --reincarca-baza',
                'sau baza lăsată cum e acum, doar codul întors: aceeași comandă cu --fara-baza',
            ]);
        }
        $rev['baza'] = date('c');
        $rev['baza_acceptata'] = $rev['baza_diferita'];
        unset($rev['baza_diferita']);
        $stare->pune('revino', $rev);
        Iesire::atentie('--accepta-baza-diferita: codul vechi pornește pe baza încărcată, care diferă de copie (mai sus)');
    }
    if ($cuBaza) {
        $t = microtime(true);
        unset($rev['baza_diferita']);
        $rev['baza_in_curs'] = date('c');
        $stare->pune('revino', $rev);
        $diferente = $baza->restaureaza($copieBaza);
        if ($diferente !== []) {
            // Încărcarea s-a terminat (nu mai e „în curs”), dar baza diferă: codul nu se întoarce până nu alege omul.
            unset($rev['baza_in_curs']);
            $rev['baza_diferita'] = ['facuta' => date('c'), 'diferente' => array_slice($diferente, 0, 40), 'amprenta' => $baza->amprenta()];
            $stare->pune('revino', $rev);
            throw new Oprire('baza pusă la loc diferă de copie: ' . implode('; ', array_slice($diferente, 0, 8)) . '. Nu întorc codul pe ea; '
                . 'mentenanța rămâne strictă', 1, [
                'codul vechi pe baza încărcată, cu diferențele de mai sus: aceeași comandă cu --accepta-baza-diferita',
                'sau baza lăsată cum e acum (fără altă încărcare), doar codul întors: aceeași comandă cu --fara-baza',
                'sau copia încărcată din nou (o diferență întâmplătoare): aceeași comandă cu --reincarca-baza',
                'oricare, ne trimiți ieșirea; codul vechi e neatins în ' . $s->vechi . ', copia bazei în ' . $copieBaza,
            ]);
        }
        $rev['baza'] = date('c');
        unset($rev['baza_in_curs']);
        $stare->pune('revino', $rev);
        Iesire::ok('baza pusă la loc, exact ca la copie (structura și CHECKSUM TABLE ale fiecărui tabel, declanșatoarele, rutinele; '
            . round(microtime(true) - $t, 1) . ' s)');
    }
    // 4. Pozele: înapoi doar dacă au fost mutate și nu sunt deja acolo.
    $uploads = (string) $stare->ia('uploads', '');
    if (!$codIntors && $uploads !== '' && is_dir($s->docroot . '/uploads') && !file_exists($s->vechi . '/' . $uploads)) {
        Redenumiri::fa($stare, 'revino-uploads', [[$s->docroot . '/uploads', $s->vechi . '/' . $uploads]]);
        Iesire::ok('pozele mutate înapoi: ' . $s->vechi . '/' . $uploads);
    }
    // 5. Codul.
    $esuat = (string) ($rev['esuat'] ?? $s->dosar . '/public_html.esuat-' . date('Ymd-His'));
    $rev['esuat'] = $esuat;
    $stare->pune('revino', $rev);
    Redenumiri::fa($stare, 'revino-cod', [[$s->docroot, $esuat], [$s->vechi, $s->docroot]]);
    Mentenanta::scoate($s);
    // RELEASE nu mai spune nimic (stub-ul nu mai e în docroot); fără el, versiunea nu mai apare „folosită” (release.sh --sterge).
    if (is_file($s->site . '/RELEASE')) {
        @rename($s->site . '/RELEASE', $s->site . '/RELEASE.inainte-de-revenire');
    }
    Iesire::ok('codul vechi e din nou în ' . $s->docroot . ' (codul nou păstrat în ' . $esuat . ')');
    // 6. Exact cum era.
    $amprenta = $stare->ia('copii') . '/cod-vechi.amprenta';
    if (is_file($amprenta)) {
        $acum = Fisiere::amprenta($s->docroot, ['public/uploads', 'uploads']);
        [$cod, $poze] = Fisiere::diferente((string) file_get_contents($amprenta), $acum, $uploads);
        if ($cod === []) {
            Iesire::ok('codul vechi e octet cu octet cel de la comutare (' . substr_count($acum, "\n") . ' intrări; la poze mărimea și data)');
        } else {
            Iesire::nu('codul vechi diferă de amprenta de la comutare (' . count($cod) . '): ' . implode(', ', array_slice($cod, 0, 8)));
            $probleme[] = 'codul';
        }
        if ($poze !== []) {
            Iesire::atentie('pozele: ' . count($poze) . ' intrări noi sau schimbate de când a mers codul nou (de exemplu ' . implode(', ', array_slice($poze, 0, 4))
                . '); au rămas în ' . $uploads);
        }
    } else {
        Iesire::atentie('nu găsesc amprenta codului vechi (' . $amprenta . '); nu pot spune dacă e exact cel de la comutare');
    }
    // 7. Site-ul vechi răspunde.
    $r = Http::cere($url . '/');
    if ($r['cod'] === 200) {
        Iesire::ok('site-ul vechi răspunde la ' . $url . '/ (200)');
    } else {
        Iesire::nu('site-ul vechi nu răspunde 200 la ' . $url . '/ (HTTP ' . $r['cod'] . ($r['eroare'] !== '' ? ', ' . $r['eroare'] : '') . ')');
        $probleme[] = 'site-ul';
    }
    Fisiere::muta($fisierStare, $fisierStare . '.revenit-' . date('Ymd-His'));
    Iesire::titlu($probleme === [] ? 'GATA' : 'GATA, CU PROBLEME');
    Iesire::scrie('Site-ul ' . $s->domeniu . ' e din nou pe codul vechi (' . round(microtime(true) - $inceput, 1) . ' s). Cronurile vechi le pui înapoi în hPanel, '
        . 'dacă le-ai schimbat.');
    Iesire::scrie('Dosarul site/ (.env, pachetul, copiile) a rămas; o adoptare nouă pornește de la început.');
    unset($lacat);
    if ($probleme !== []) {
        throw new Oprire('revenirea a mers, dar verificările au găsit: ' . implode(', ', $probleme) . ' (mai sus)');
    }
    return 0;
}));

/**
 * Ce s-ar pierde punând baza la loc: [blocante, informative]. Blocante: tabelele schimbate de la sfârșitul migrărilor
 * (amprenta din dupa-migrari.json, luată cu scrierile oprite) și, dacă site-ul a fost deschis, asta însuși. Informative:
 * rândurile noi din tabelele cu comenzi și conturi față de cele de după migrări (o migrare de date poate adăuga rânduri,
 * de exemplu recenziile copiate din tabelul vechi, deci față de copia de dinainte nu spun nimic).
 *
 * @return array{0: list<string>, 1: list<string>}
 */
function dateNoi(Stare $stare, Baza $baza): array
{
    $blocante = [];
    $informative = [];
    $deschis = $stare->date['faze']['deschidere'] ?? null;
    $dupa = json_decode((string) @file_get_contents($stare->ia('copii') . '/dupa-migrari.json'), true);
    $schimbate = is_array($dupa) ? $baza->tabeleSchimbate((array) ($dupa['amprenta'] ?? [])) : [];
    if ($deschis !== null) {
        $blocante[] = 'site-ul a fost deschis vizitatorilor (' . $deschis . '): tot ce s-a scris de atunci s-ar pierde';
    }
    if ($schimbate !== []) {
        $blocante[] = 'tabele schimbate după migrări (' . count($schimbate) . '): ' . implode(', ', array_slice($schimbate, 0, 20)) . (count($schimbate) > 20 ? ', …' : '');
    } elseif (!is_array($dupa) && $deschis !== null) {
        $blocante[] = 'amprenta de după migrări lipsește: nu pot spune ce tabele s-au schimbat';
    }
    if (is_array($dupa)) {
        foreach ($baza->ultimeleIduri() as $tabel => $id) {
            $atunci = (int) ($dupa['ultimele_iduri'][$tabel] ?? 0);
            if ($id > $atunci) {
                $informative[] = $tabel . ': ' . ($id - $atunci) . ' rânduri noi după migrări (id ' . ($atunci + 1) . '–' . $id . ')';
            }
        }
    }
    return [$blocante, $informative];
}
