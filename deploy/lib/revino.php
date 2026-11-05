<?php

declare(strict_types=1);

/**
 * deploy/revino.sh: întoarce un site adoptat (sau o adoptare oprită la jumătate) la codul lui vechi, în câteva minute
 * (docs/DEPLOY.md, „Revenirea”). Implicit doar planul; cu --aplica:
 *
 *   1. MENTENANTA (nicio scriere nouă cât se pune baza la loc);
 *   2. baza pusă la loc din copia de revenire (făcută în mentenanță, înainte de migrări), dacă migrările au pornit:
 *      tabelele făcute de migrări se șterg, copia se încarcă, apoi amprenta (structura și CHECKSUM TABLE ale fiecărui
 *      tabel, declanșatoarele) se compară cu cea de la copie;
 *   3. pozele (uploads/) mutate înapoi în codul vechi;
 *   4. public_html (codul nou) → public_html.esuat-<data>, public_html.vechi → public_html;
 *   5. codul vechi comparat cu amprenta lui de la comutare (fiecare fișier, octet cu octet; la poze mărimea și data);
 *   6. site-ul vechi verificat de pe web (prima pagină 200); MENTENANTA ștearsă.
 *
 *   deploy/revino.sh --site=biovitality            # planul: ce ar face și ce date s-ar pierde
 *   deploy/revino.sh --site=biovitality --aplica
 *
 * Datele scrise după copie (comenzi, conturi, abonați noi) s-ar pierde cu baza pusă la loc: planul le numără, iar
 * --aplica refuză dacă există, afară de --pierde-datele-noi. --fara-baza lasă baza cum e (migrările doar adaugă, deci
 * codul vechi merge și pe ea, docs/MIGRARI.md, „Regulile”). Alte opțiuni: --domeniu=<d> (în locul lui --site),
 * --url=<adresa>, --acasa=<dosar> (teste).
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
    }

    if (!$stare->ia('comutat', false)) {
        Iesire::info('adoptarea s-a oprit înainte de comutare: codul vechi e încă în ' . $s->docroot . ', neatins, și vizitatorii n-au văzut nimic');
        if (!$aplica) {
            Iesire::scrie('PLAN: aș șterge doar ' . $s->nou . ' (pregătirea) și aș încheia adoptarea începută; site/ rămâne. Cu --aplica o face.');
            return 0;
        }
        if (is_dir($s->nou) && is_file($s->nou . '/.andaxi-nou')) {
            Fisiere::sterge($s->nou, [$s->dosar]);
            Iesire::ok('șters ' . $s->nou);
        }
        @unlink($s->site . '/MENTENANTA');
        Fisiere::muta($fisierStare, $fisierStare . '.revenit-' . date('Ymd-His'));
        Iesire::ok('adoptarea începută e încheiată; o adoptare nouă pornește de la început');
        return 0;
    }

    if (!is_dir($s->vechi)) {
        throw new Oprire('lipsește ' . $s->vechi . ' (codul vechi): nu am ce pune la loc. Copia lui: ' . $stare->ia('copii') . '/cod-vechi.tar.gz');
    }
    $cuBaza = (bool) $stare->ia('migrari_pornite', false) && !$o->are('fara-baza');
    $copieBaza = (string) $stare->ia('copie_baza', '');
    $baza = new Baza(Env::citeste(is_file($s->vechi . '/.env') ? $s->vechi . '/.env' : $s->site . '/.env'));
    $noi = [];
    $info = [];
    if ($cuBaza) {
        if ($copieBaza === '' || !is_file($copieBaza) || !is_file($copieBaza . '.json')) {
            throw new Oprire('lipsește copia bazei de revenire (' . ($copieBaza ?: '?') . '); fără ea, doar --fara-baza');
        }
        $info = (array) json_decode((string) file_get_contents($copieBaza . '.json'), true);
        foreach ($baza->ultimeleIduri() as $tabel => $id) {
            $atunci = (int) ($info['ultimele_iduri'][$tabel] ?? 0);
            if ($id > $atunci) {
                $noi[] = $tabel . ': ' . ($id - $atunci) . ' rânduri noi (id ' . ($atunci + 1) . '–' . $id . ')';
            }
        }
    }

    Iesire::info('codul vechi  : ' . $s->vechi . ' → ' . $s->docroot);
    Iesire::info('pozele       : ' . $s->docroot . '/uploads → ' . $s->vechi . '/' . ($stare->ia('uploads') ?: '(n-au fost mutate)'));
    Iesire::info('baza         : ' . ($cuBaza ? 'pusă la loc din ' . $copieBaza . ' (făcută ' . (string) ($info['facuta'] ?? '?') . ')'
        : ($o->are('fara-baza') ? 'lăsată cum e (--fara-baza): codul vechi merge pe baza migrată' : 'neatinsă (migrările n-au pornit)')));
    foreach ($noi as $n) {
        Iesire::atentie('scris după copie, s-ar pierde: ' . $n);
    }
    if (!$aplica) {
        Iesire::scrie('');
        Iesire::scrie('PLAN: nimic schimbat. Cu --aplica o face' . ($noi !== [] ? ' (cu datele noi de mai sus: --pierde-datele-noi, sau --fara-baza)' : '') . '.');
        return 0;
    }
    if ($noi !== [] && !$o->are('pierde-datele-noi')) {
        throw new Oprire('după copie s-au scris date noi (mai sus); nu le șterg fără --pierde-datele-noi', 1, [
            'păstrezi baza și întorci doar codul: aceeași comandă cu --fara-baza (codul vechi merge pe baza migrată)',
            'sau exporți întâi comenzile noi din admin, apoi aceeași comandă cu --pierde-datele-noi',
        ]);
    }

    // 1. Nicio scriere nouă.
    if ($s->cuStub()) {
        Fisiere::scrie($s->site . '/MENTENANTA', bin2hex(random_bytes(16)) . "\n", 0640);
        Iesire::ok('MENTENANTA: codul nou nu mai servește pagini cât lucrez');
    }
    // 2. Baza.
    $probleme = [];
    if ($cuBaza) {
        $t = microtime(true);
        $diferente = $baza->restaureaza($copieBaza);
        if ($diferente === []) {
            Iesire::ok('baza pusă la loc, exact ca la copie (structura și CHECKSUM TABLE ale fiecărui tabel, declanșatoarele; ' . round(microtime(true) - $t, 1) . ' s)');
            $stare->pune('migrari_pornite', false);
        } else {
            Iesire::nu('baza pusă la loc, dar diferă de copie: ' . implode('; ', array_slice($diferente, 0, 8)));
            $probleme[] = 'baza';
        }
    }
    // 3. Pozele.
    $uploads = (string) $stare->ia('uploads', '');
    if ($uploads !== '' && is_dir($s->docroot . '/uploads') && !file_exists($s->vechi . '/' . $uploads)) {
        Fisiere::muta($s->docroot . '/uploads', $s->vechi . '/' . $uploads);
        Iesire::ok('pozele mutate înapoi: ' . $s->vechi . '/' . $uploads);
    }
    // 4. Codul.
    $esuat = $s->dosar . '/public_html.esuat-' . date('Ymd-His');
    Fisiere::muta($s->docroot, $esuat);
    try {
        Fisiere::muta($s->vechi, $s->docroot);
    } catch (Oprire $e) {
        @rename($esuat, $s->docroot);
        throw $e;
    }
    @unlink($s->site . '/MENTENANTA');
    // RELEASE nu mai spune nimic (stub-ul nu mai e în docroot); fără el, versiunea nu mai apare „folosită” (release.sh --sterge).
    if (is_file($s->site . '/RELEASE')) {
        @rename($s->site . '/RELEASE', $s->site . '/RELEASE.inainte-de-revenire');
    }
    Iesire::ok('codul vechi e din nou în ' . $s->docroot . ' (codul nou păstrat în ' . $esuat . ')');
    // 5. Exact cum era.
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
    // 6. Site-ul vechi răspunde.
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
