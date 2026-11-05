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
 *      la fel după ce site-ul a fost deschis (deschidere), oricum ar arăta amprenta. La refuz mentenanța revine cum era;
 *   3. baza pusă la loc din copia de revenire (făcută în mentenanța strictă, înainte de migrări), dacă migrările au
 *      pornit: întâi se verifică fișierul (gzip -t) și că e copia acestei baze (numele și gazda din .env), apoi
 *      tabelele, vederile, rutinele și evenimentele de după copie se șterg, copia se încarcă și amprenta se compară cu
 *      cea de la copie;
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
 * nicio dată nu se pierde. Alte opțiuni: --domeniu=<d> (în locul lui --site), --url=<adresa>, --acasa=<dosar> (teste).
 */

namespace Platforma\Deploy;

require __DIR__ . '/deploy.php';

exit(porneste(static function () use ($argv): int {
    $o = Optiuni::citeste($argv, ['site=', 'domeniu=', 'url=', 'acasa=', 'fara-baza', 'pierde-datele-noi', 'plan', 'aplica']);
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
    $inceput = microtime(true);
    if ($aplica) {
        Iesire::jurnal($s->lucru . '/jurnale/revino-' . date('Ymd-His') . '.log');
        Redenumiri::termina($stare);
    } elseif ((array) $stare->ia('redenumiri', []) !== []) {
        Iesire::atentie('o rulare de dinainte s-a oprit la jumătatea unei redenumiri (' . implode(', ', array_keys((array) $stare->ia('redenumiri'))) . '); --aplica o termină întâi');
    }
    $facute = (array) $stare->ia('redenumiri_facute', []);
    $comutat = in_array('comutare', $facute, true) || isset($stare->date['redenumiri']['comutare']);
    $rev = (array) $stare->ia('revino', []);

    if (!$comutat) {
        Iesire::info('adoptarea s-a oprit înainte de comutare: codul vechi e încă în ' . $s->docroot . ', neatins, și vizitatorii n-au văzut nimic');
        if (!$aplica) {
            Iesire::scrie('PLAN: aș șterge doar ' . $s->nou . ' (pregătirea) și aș încheia adoptarea începută; site/ rămâne. Cu --aplica o face.');
            return 0;
        }
        if (is_dir($s->nou) && is_file($s->nou . '/.andaxi-nou')) {
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
    $cuBaza = (bool) $stare->ia('migrari_pornite', false) && !$o->are('fara-baza') && empty($rev['baza']);
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
    Iesire::info('baza         : ' . (!empty($rev['baza']) ? 'deja pusă la loc' : ($cuBaza ? 'pusă la loc din ' . $copieBaza . ' (făcută ' . (string) ($info['facuta'] ?? '?') . ')'
        : ($o->are('fara-baza') ? 'lăsată cum e (--fara-baza): codul vechi merge pe baza migrată' : 'neatinsă (migrările n-au pornit)'))));

    // 1. Nicio scriere nouă (doar cu --aplica), apoi 2. ce s-ar pierde.
    $mentenantaInainte = Mentenanta::citeste($s);
    $amPusMentenanta = false;
    if ($aplica && $s->cuStub() && !$codIntors) {
        Mentenanta::pune($s, bin2hex(random_bytes(16)), true);
        $amPusMentenanta = true;
        Iesire::ok('mentenanța strictă: nici vizitatorii, nici plățile, ERP-ul sau cronurile nu mai scriu');
        $ramase = $baza->asteaptaLiniste(60);
        if ($ramase > 0) {
            Mentenanta::puneLaLoc($s, $mentenantaInainte);
            throw new Oprire($ramase . ' conexiuni lucrează încă pe bază după un minut; rulează din nou peste câteva minute (mentenanța e cum era)');
        }
    }
    if ($cuBaza) {
        $probleme = dateNoi($stare, $baza);
        foreach ($probleme as $p) {
            Iesire::atentie($p);
        }
        if ($probleme !== [] && !$o->are('pierde-datele-noi')) {
            if ($amPusMentenanta) {
                Mentenanta::puneLaLoc($s, $mentenantaInainte);
            }
            if (!$aplica) {
                Iesire::scrie('');
                Iesire::scrie('PLAN: nimic schimbat. Cu datele noi de mai sus, --aplica refuză: --fara-baza (le păstrezi) sau --pierde-datele-noi.');
                return 0;
            }
            throw new Oprire('baza are date scrise după migrări (mai sus); nu le șterg fără --pierde-datele-noi. Mentenanța e cum era', 1, [
                'păstrezi baza și întorci doar codul: aceeași comandă cu --fara-baza (codul vechi merge pe baza migrată; nu se pierde nimic)',
                'sau notezi întâi din admin ce s-a schimbat în tabelele de mai sus, apoi aceeași comandă cu --pierde-datele-noi',
            ]);
        }
        if ($probleme === []) {
            Iesire::ok('nicio dată scrisă de la sfârșitul migrărilor (amprenta fiecărui tabel e aceeași)' . ($aplica ? '' : ' — o estimare; --aplica verifică din nou cu scrierile oprite'));
        }
    }
    if (!$aplica) {
        Iesire::scrie('');
        Iesire::scrie('PLAN: nimic schimbat. Cu --aplica o face.');
        return 0;
    }

    // 3. Baza.
    $probleme = [];
    if ($cuBaza) {
        $t = microtime(true);
        $diferente = $baza->restaureaza($copieBaza);
        $rev['baza'] = date('c');
        $stare->pune('revino', $rev);
        if ($diferente === []) {
            Iesire::ok('baza pusă la loc, exact ca la copie (structura și CHECKSUM TABLE ale fiecărui tabel, declanșatoarele, rutinele; '
                . round(microtime(true) - $t, 1) . ' s)');
        } else {
            Iesire::nu('baza pusă la loc, dar diferă de copie: ' . implode('; ', array_slice($diferente, 0, 8)));
            $probleme[] = 'baza';
        }
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
 * Ce s-ar pierde punând baza la loc: tabelele schimbate de la sfârșitul migrărilor (amprenta dupa-migrari.json, luată cu
 * scrierile oprite) și, dacă site-ul a fost deschis, asta însuși. Rândurile noi din tabelele cu comenzi și conturi, ca
 * informație în plus. @return list<string>
 */
function dateNoi(Stare $stare, Baza $baza): array
{
    $rez = [];
    $deschis = $stare->date['faze']['deschidere'] ?? null;
    $dupa = json_decode((string) @file_get_contents($stare->ia('copii') . '/dupa-migrari.json'), true);
    $schimbate = is_array($dupa) ? $baza->tabeleSchimbate((array) ($dupa['amprenta'] ?? [])) : [];
    if ($deschis !== null) {
        $rez[] = 'site-ul a fost deschis vizitatorilor (' . $deschis . '): tot ce s-a scris de atunci s-ar pierde';
    }
    if ($schimbate !== []) {
        $rez[] = 'tabele schimbate după migrări (' . count($schimbate) . '): ' . implode(', ', array_slice($schimbate, 0, 20)) . (count($schimbate) > 20 ? ', …' : '');
    } elseif (!is_array($dupa) && $deschis !== null) {
        $rez[] = 'amprenta de după migrări lipsește: nu pot spune ce tabele s-au schimbat';
    }
    $info = json_decode((string) @file_get_contents((string) $stare->ia('copie_baza') . '.json'), true);
    foreach ($baza->ultimeleIduri() as $tabel => $id) {
        $atunci = (int) ($info['ultimele_iduri'][$tabel] ?? 0);
        if ($id > $atunci) {
            $rez[] = $tabel . ': ' . ($id - $atunci) . ' rânduri noi (id ' . ($atunci + 1) . '–' . $id . ')';
        }
    }
    return $rez;
}
