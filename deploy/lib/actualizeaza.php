<?php

declare(strict_types=1);

/**
 * deploy/actualizeaza.sh: trece un site deja adoptat pe o versiune nouă de cod (docs/DEPLOY.md, „O versiune nouă”).
 * Implicit doar planul; cu --aplica, fazele:
 *
 *   verificari  versiunea nouă (și cea de acum) urcate și neatinse, pachetul, licența și migreaza --plan cu codul nou
 *   pregatire   pachetul nou (site/pachet.nou), assets/ și tema/ noi (public_html/.assets-nou, .tema-nou), copia
 *               fișierelor din rădăcina docroot-ului (stub-ul) de acum
 *   mentenanta  MENTENANTA strictă (nici plățile, ERP-ul sau cronurile nu scriu), apoi liniștea pe bază
 *   copie       copia bazei (amprenta înainte și după: nimic scris în timpul ei)
 *   comutare    pachetul, assets/, tema/ și fișierele din rădăcina docroot-ului, pe rând, fiecare redenumire ținută minte
 *   migrari     migreaza --aplica cu codul nou (--versiune, încă fără RELEASE schimbat; reluat la codul 13)
 *   release     RELEASE pe versiunea nouă, cache-ul paginilor golit, mentenanța ușoară (plățile trec din nou)
 *   diagnostic  diagnostic.php --pachet și --licenta
 *   verificare  testul de fum (pingul spune versiunea nouă)
 *   deschidere  MENTENANTA ștearsă
 *
 * La o problemă în comutare sau după ea, se întoarce singur: RELEASE, pachetul, assets/, tema/ și rădăcina iau înapoi
 * valorile de dinainte, versiunea veche trece prin același test de fum și, dacă îl trece, site-ul se deschide (codul de
 * ieșire rămâne 1: actualizarea n-a mers). Baza rămâne cum au lăsat-o migrările: ele doar adaugă, deci codul de dinainte
 * merge pe ea; copia bazei e pentru o revenire de mână.
 *
 *   deploy/actualizeaza.sh --site=biovitality --versiune=2026.11.12-a1b2c3d
 *   deploy/actualizeaza.sh --site=biovitality --versiune=2026.11.12-a1b2c3d --aplica
 *
 * Opțiuni: --domeniu=<d> (în locul lui --site), --url=<adresa>, --repo=<clonă>, --pachet=<dosar>, --produs=<slug>,
 * --permite-blocante, --inapoi (o versiune mai veche decât baza: fără migrări; migreaza --plan dă atunci codul 12),
 * --acasa=<dosar> (teste).
 */

namespace Platforma\Deploy;

require __DIR__ . '/deploy.php';

const FAZE_ACTUALIZARE = ['verificari', 'pregatire', 'mentenanta', 'copie', 'comutare', 'migrari', 'release', 'diagnostic', 'verificare', 'deschidere'];
const FAZE_CU_INTOARCERE = ['comutare', 'migrari', 'release', 'diagnostic', 'verificare', 'deschidere'];

final class Actualizare
{
    public Site $s;
    public Stare $stare;
    public string $slug;
    public string $veche;
    public string $noua;
    public string $url;
    public bool $aplica;

    public function __construct(public Optiuni $o)
    {
        $this->s = Site::gaseste($o);
        $this->noua = $o->cere('versiune', '<versiune> (făcută cu deploy/release.sh)');
        if (preg_match(Versiune::TIPAR, $this->noua) !== 1) {
            throw new Oprire('--versiune=' . $this->noua . ' nu are forma 2026.11.05-e4f5a6b', 2);
        }
        $env = Env::citeste($this->s->site . '/.env');
        $this->slug = (string) ($env['SITE_SLUG'] ?? '');
        if ($this->slug === '' || !$this->s->cuStub()) {
            throw new Oprire($this->s->domeniu . ' nu e încă adoptat (fără stub în public_html sau fără SITE_SLUG în site/.env): întâi deploy/adopta.sh', 2);
        }
        $adoptare = new Stare($this->s->lucru . '/adopta.stare');
        if (is_file($adoptare->fisier) && !$adoptare->facuta('deschidere')) {
            throw new Oprire('adoptarea lui ' . $this->s->domeniu . ' nu e terminată: întâi o termini (deploy/adopta.sh, aceeași comandă) sau revii (deploy/revino.sh)', 2);
        }
        $this->url = rtrim($o->text('url') ?? 'https://' . $this->s->domeniu, '/');
        $this->aplica = $o->aplica();
        $this->stare = new Stare($this->s->lucru . '/actualizare.stare');
        $this->veche = trim((string) @file_get_contents($this->s->site . '/RELEASE'));
    }

    public function repo(): string
    {
        return $this->o->text('repo') ?? $this->s->acasa . '/platforma/repo';
    }

    public function commit(string $versiune): string
    {
        return trim((string) @file_get_contents($this->s->releases . '/' . $versiune . '/COMMIT'));
    }

    public function radacinaVeche(): string
    {
        return $this->s->lucru . '/actualizare/radacina-veche';
    }
}

exit(porneste(static function () use ($argv): int {
    $o = Optiuni::citeste($argv, ['site=', 'domeniu=', 'versiune=', 'url=', 'repo=', 'pachet=', 'produs=', 'permite-blocante', 'inapoi', 'forteaza-liniste', 'accepta-revenirea', 'acasa=', 'plan', 'aplica']);
    $u = new Actualizare($o);
    $lacat = $u->s->lacat();
    $stare = $u->stare;
    Mentenanta::avertizeazaVeche($u->s);
    // Mentenanța de la pornire: o fază fără întoarcere care pică o lasă cum era (de obicei: niciuna).
    $mentenantaLaPornire = Mentenanta::citeste($u->s);
    // O întoarcere ale cărei redenumiri s-au terminat, dar a cărei versiune veche nu trece testul de fum, lasă site-ul în
    // mentenanță. De acolo: o versiune nouă (reparația, fix-forward), sau --accepta-revenirea (deschis cu versiunea veche).
    // Doar cu --aplica se schimbă ceva (MENTENANTA, starea); planul doar spune ce ar face.
    if ($stare->ia('intoarcere_in_curs', false) && $stare->ia('intoarcere_pasi_gata', false)) {
        if ($o->are('accepta-revenirea')) {
            if (!$u->aplica) {
                Iesire::scrie('PLAN: nimic schimbat. Cu --aplica, --accepta-revenirea deschide site-ul pe ' . (string) $stare->ia('versiune_veche')
                    . ' (MENTENANTA ștearsă), deși n-a trecut testul de fum după întoarcere.');
                return 0;
            }
            Mentenanta::scoate($u->s);
            $stare->pune('intoarsa', true);
            $stare->pune('intoarcere_in_curs', false);
            Iesire::atentie('--accepta-revenirea: site-ul e deschis pe ' . (string) $stare->ia('versiune_veche') . ' deși n-a trecut testul de fum după întoarcere; '
                . 'verifică-l acum în browser și în ' . $u->s->site . '/storage/logs/');
            return 0;
        }
        if ($stare->ia('versiune_noua') !== $u->noua) {
            Iesire::atentie('întoarcerea la ' . (string) $stare->ia('versiune_veche') . ' a rămas fără testul de fum trecut; ' . ($u->aplica ? 'merg' : 'cu --aplica aș merge')
                . ' mai departe spre ' . $u->noua . ' (reparația)');
            if ($u->aplica) {
                $stare->pune('intoarsa', true);
                $stare->pune('intoarcere_in_curs', false);
            } else {
                // Planul continuă ca după --aplica, dar fără să scrie starea.
                $stare->date['intoarsa'] = true;
                $stare->date['intoarcere_in_curs'] = false;
            }
        }
    }
    // O actualizare neterminată spre aceeași versiune continuă; spre alta, nu; una terminată lasă loc uneia noi.
    if ($stare->ia('gata', false) || $stare->ia('intoarsa', false) || $stare->ia('versiune_noua') === null) {
        if ($u->aplica && is_file($stare->fisier)) {
            @rename($stare->fisier, $stare->fisier . '.' . date('Ymd-His'));
        }
        $stare->date = ['faze' => [], 'versiune_veche' => $u->veche, 'versiune_noua' => $u->noua];
    } elseif ($stare->ia('versiune_noua') !== $u->noua) {
        throw new Oprire('o actualizare spre ' . $stare->ia('versiune_noua') . ' a rămas neterminată; termin-o (aceeași comandă cu --versiune=' . $stare->ia('versiune_noua')
            . ') înainte de alta', 2);
    } else {
        $u->veche = (string) $stare->ia('versiune_veche');
    }
    Iesire::titlu('Actualizarea site-ului ' . $u->slug . ' (' . $u->s->domeniu . '): ' . $u->veche . ' → ' . $u->noua . ($u->aplica ? '' : ' — PLAN'));
    if ($u->veche === $u->noua && !$stare->facuta('verificari')) {
        Iesire::ok('site-ul e deja pe versiunea ' . $u->noua . '; nimic de făcut');
        return 0;
    }
    if (!$u->aplica) {
        verificari($u);
        Iesire::scrie('');
        Iesire::scrie('PLAN: nimic schimbat. Cu --aplica: pregătirea, mentenanța strictă, copia bazei, comutarea, migrările, RELEASE, diagnosticul, testul de fum, deschiderea '
            . '(cu întoarcerea singură la ' . $u->veche . ' dacă ceva pică după comutare).');
        return 0;
    }
    Iesire::jurnal($u->s->lucru . '/jurnale/actualizeaza-' . date('Ymd-His') . '.log');
    $stare->salveaza();
    Redenumiri::termina($stare);
    if ($stare->ia('intoarcere_in_curs', false)) {
        Iesire::atentie('o întoarcere la ' . $u->veche . ' a rămas la jumătate (procesul s-a oprit); o termin acum');
        intoarce($u);
        throw new Oprire('actualizarea spre ' . $u->noua . ' a fost întoarsă; site-ul e din nou pe ' . $u->veche . ', deschis');
    }
    foreach (FAZE_ACTUALIZARE as $faza) {
        if ($stare->facuta($faza)) {
            Iesire::titlu($faza . ': făcută deja (' . $stare->date['faze'][$faza] . '), o sar');
            continue;
        }
        Iesire::titlu($faza);
        try {
            ('Platforma\\Deploy\\' . $faza . 'Actualizare')($u);
        } catch (Oprire $e) {
            if (!in_array($faza, FAZE_CU_INTOARCERE, true)) {
                $inainte = $stare->ia('mentenanta_inainte');
                Mentenanta::puneLaLoc($u->s, is_array($inainte) ? ($inainte['continut'] ?? null) : $mentenantaLaPornire);
                throw new Oprire('faza „' . $faza . '”: ' . $e->getMessage(), $e->getCode(), array_merge($e->pasi,
                    ['site-ul a rămas pe ' . $u->veche . ', deschis; după reparare, aceeași comandă continuă de la „' . $faza . '”']));
            }
            Iesire::scrie('');
            Iesire::scrie('PROBLEMĂ în faza „' . $faza . '”: ' . $e->getMessage());
            intoarce($u);
            throw new Oprire('actualizarea spre ' . $u->noua . ' n-a mers (faza „' . $faza . '”); site-ul e din nou pe ' . $u->veche . ', deschis', 1, [
                'cauza e mai sus; după reparare (o versiune nouă), rulezi din nou actualizeaza.sh',
                'baza a rămas cum au lăsat-o migrările (doar adăugări); copia ei: ' . $stare->ia('copie_baza'),
            ]);
        }
        $stare->marcheaza($faza);
    }
    $stare->pune('gata', true);
    curataResturi($u);
    Iesire::titlu('GATA');
    Iesire::scrie('Site-ul ' . $u->s->domeniu . ' merge pe versiunea ' . $u->noua . ' (înainte: ' . $u->veche . ').');
    Iesire::scrie('Înapoi, dacă e nevoie: deploy/actualizeaza.sh --site=' . $u->slug . ' --versiune=' . $u->veche . ' --inapoi --aplica (baza rămâne).');
    unset($lacat);
    return 0;
}));

function verificari(Actualizare $u): void
{
    $s = $u->s;
    $probleme = [];
    $nu = static function (string $t) use (&$probleme): void {
        Iesire::nu($t);
        $probleme[] = $t;
    };
    foreach ([$u->noua, $u->veche] as $v) {
        $p = Versiune::verifica($s->releases . '/' . $v, $v);
        if ($p === []) {
            Iesire::ok('versiunea ' . $v . ($v === $u->noua ? ' (nouă)' : ' (de acum, pentru întoarcere)') . ' e urcată și neatinsă');
        } else {
            $nu('versiunea ' . $v . ': ' . implode('; ', array_slice($p, 0, 3)));
        }
    }
    $lipsa = array_values(array_filter(['sodium', 'pdo_mysql', 'curl', 'mbstring'], static fn (string $e): bool => !extension_loaded($e)));
    if ($lipsa !== []) {
        $nu('PHP-ul liniei de comandă n-are: ' . implode(', ', $lipsa));
    }
    if (is_file($s->releases . '/' . $u->noua . '/config/app.php') && version_compare(PHP_VERSION, Versiune::phpMin($s->releases . '/' . $u->noua), '<')) {
        $nu('PHP-ul liniei de comandă ' . PHP_VERSION . ' e sub minimul versiunii noi (' . Versiune::phpMin($s->releases . '/' . $u->noua) . ')');
    }
    if ($probleme !== []) {
        throw new Oprire(count($probleme) . ' verificări au ieșit NU; nu schimb nimic');
    }
    $u->stare->date['release_local'] = is_dir($s->site . '/releases/' . $u->veche);
    if ($u->o->text('pachet') === null && Comanda::ruleaza(['git', '-C', $u->repo(), 'cat-file', '-e', $u->commit($u->noua) . '^{commit}'])['cod'] !== 0) {
        $nu('repo-ul ' . $u->repo() . ' n-are commit-ul versiunii noi: git -C ' . $u->repo() . ' fetch origin');
    } else {
        [$manifest, $inghetare] = citestePachet($u->slug, $u->o->text('pachet'), $u->repo(), $u->commit($u->noua));
        foreach (problemePachet($s, $u->slug, $manifest, $inghetare) as $p) {
            $nu($p);
        }
        if ($manifest !== null) {
            Iesire::ok('pachetul versiunii noi: ' . count((array) ($manifest['fisiere'] ?? [])) . ' fișiere');
        }
    }
    $liber = (float) @disk_free_space($s->acasa);
    $nevoie = (int) ((int) (new Baza(Env::citeste($s->site . '/.env')))->octeti() + 30 * 1024 * 1024);
    if ($liber > 0 && $liber < $nevoie) {
        $nu('spațiu liber ' . Fisiere::om($liber) . ', trebuie ~' . Fisiere::om($nevoie));
    }
    $r = Http::cere($u->url . '/_andaxi/ping');
    $d = json_decode($r['corp'], true);
    if ($r['cod'] !== 200 || !is_array($d) || ($d['cod'] ?? null) !== $u->veche) {
        Iesire::atentie('pingul de acum: HTTP ' . $r['cod'] . ', ' . mb_substr($r['corp'], 0, 120) . ' (aștept versiunea ' . $u->veche . ')');
    } else {
        Iesire::ok('site-ul răspunde acum, pe ' . $u->veche . ' (schema ' . (string) ($d['schema'] ?? '?') . ')');
    }
    if ($probleme !== []) {
        throw new Oprire(count($probleme) . ' verificări au ieșit NU; nu schimb nimic');
    }
    // Codul nou, pe dosarul site-ului (fără să schimbe nimic): licența și planul migrărilor.
    $cod = $s->releases . '/' . $u->noua;
    $r = platforma($s->site, $cod, $u->noua, ['scripts/diagnostic.php', '--licenta'], true);
    if ($r['cod'] !== 0) {
        throw new Oprire('cu versiunea nouă, licența nu se verifică (diagnostic.php --licenta, mai sus)');
    }
    Iesire::ok('cu versiunea nouă, licența se verifică');
    $r = platforma($s->site, $cod, $u->noua, ['scripts/migreaza.php', '--plan', '--site=' . $u->slug], true);
    Iesire::info('migreaza.php --plan cu versiunea nouă: codul ' . $r['cod'] . ' (' . sensMigrari($r['cod']) . ')');
    $u->stare->date['plan_migrari'] = $r['cod'];
    if ($r['cod'] === 12 && $u->o->are('inapoi')) {
        Iesire::info('--inapoi: versiunea e mai veche decât baza; migrările nu rulează (garda o vede tot la zi)');
    } elseif ($r['cod'] === 11 && !$u->o->are('permite-blocante')) {
        throw new Oprire('migrările versiunii noi sunt blocante: rulează cu --permite-blocante (site-ul e oricum în mentenanță la migrări)');
    } elseif (!in_array($r['cod'], [0, 10, 11], true)) {
        throw new Oprire('migreaza.php --plan cu versiunea nouă: ' . sensMigrari($r['cod']) . ($r['cod'] === 12 ? ' (pentru o versiune mai veche: --inapoi)' : ''));
    }
}

function verificariActualizare(Actualizare $u): void
{
    verificari($u);
}

function pregatireActualizare(Actualizare $u): void
{
    $s = $u->s;
    if ($u->stare->ia('release_local', false)) {
        legaVersiunea($s, $u->noua);
    }
    $cod = $s->cod($u->noua);
    $codVechi = $s->cod($u->veche);
    // Resturile unei încercări de dinainte se șterg doar dacă originalul e la locul lui: niciodată singura copie.
    foreach ([[$s->site . '/.pachet-' . $u->veche, $s->site . '/pachet'], [$s->docroot . '/.assets-' . $u->veche, $s->docroot . '/assets'],
        [$s->docroot . '/.tema-' . $u->veche, $s->docroot . '/tema']] as [$rest, $original]) {
        if (is_dir($rest) && (is_dir($original) || !str_contains($rest, '.tema-'))) {
            if (!is_dir($original)) {
                throw new Oprire($original . ' lipsește, iar ' . $rest . ' e singura lui copie; nu continui (ne trimiți ce vezi)');
            }
            Fisiere::sterge($rest, [$s->site, $s->docroot]);
        }
    }
    Fisiere::sterge($s->site . '/pachet.nou', [$s->site]);
    scriePachet($s, $u->slug, $u->o->text('pachet'), $u->repo(), $u->commit($u->noua), $s->site . '/pachet.nou');
    Iesire::ok('pachetul nou: ' . $s->site . '/pachet.nou');
    foreach (['.assets-nou', '.tema-nou'] as $d) {
        Fisiere::sterge($s->docroot . '/' . $d, [$s->docroot]);
    }
    Docroot::assets($cod, $s->site . '/pachet.nou', $s->docroot . '/.assets-nou', $s->docroot . '/assets', $codVechi, $s->site . '/pachet');
    $cuTema = Docroot::tema($s->site . '/pachet.nou', $s->docroot . '/.tema-nou');
    Iesire::ok('assets/' . ($cuTema ? ' și tema/' : '') . ' noi, pregătite alături (' . $s->docroot . '/.assets-nou)');
    $u->stare->pune('cu_tema', $cuTema);
    $u->stare->pune('avea_tema', is_dir($s->docroot . '/tema'));
    // Fișierele din rădăcina docroot-ului (stub-ul, fișierele publice ale pachetului), copiate acum, cât sunt încă cele vechi.
    $veche = Docroot::radacina($codVechi, $s->site . '/pachet');
    $noua = Docroot::radacina($cod, $s->site . '/pachet.nou');
    $copie = $u->radacinaVeche();
    Fisiere::sterge($copie, [$s->lucru]);
    Fisiere::dosar($copie, 0700);
    $existau = [];
    foreach (array_keys($veche + $noua) as $rel) {
        $f = $s->docroot . '/' . $rel;
        $existau[$rel] = is_file($f);
        if (is_file($f)) {
            Fisiere::dosar(dirname($copie . '/' . $rel), 0700);
            Fisiere::copiaza($f, $copie . '/' . $rel);
        }
    }
    $u->stare->pune('radacina', $existau);
    Iesire::ok('rădăcina docroot-ului de acum copiată (' . count($existau) . ' fișiere): ' . $copie);
}

function copieActualizare(Actualizare $u): void
{
    $dosar = $u->s->lucru . '/copii/' . date('Ymd-His') . '-actualizare-' . $u->noua;
    Fisiere::dosar($dosar, 0700);
    $secunde = (new Baza(Env::citeste($u->s->site . '/.env')))->copie($dosar . '/baza.sql.gz');
    $u->stare->pune('copie_baza', $dosar . '/baza.sql.gz');
    Iesire::ok('copia bazei: ' . $dosar . '/baza.sql.gz (' . Fisiere::om((int) filesize($dosar . '/baza.sql.gz')) . ', ' . round($secunde, 1) . ' s)');
}

function mentenantaActualizare(Actualizare $u): void
{
    $jeton = (string) $u->stare->ia('jeton', '') ?: bin2hex(random_bytes(16));
    $u->stare->pune('jeton', $jeton);
    if (!is_array($u->stare->ia('mentenanta_inainte'))) {
        $u->stare->pune('mentenanta_inainte', ['continut' => Mentenanta::citeste($u->s)]);
    }
    Mentenanta::pune($u->s, $jeton, true);
    Iesire::ok('mentenanța strictă: vizitatorii văd „Revenim în câteva minute”; plățile și ERP-ul primesc 503 și retrimit; cronurile nu rulează');
    // Fără liniște, faza se oprește, iar mentenanța se scoate (faza nu are întoarcere): site-ul rămâne pe versiunea de acum.
    (new Baza(Env::citeste($u->s->site . '/.env')))->cereLiniste($u->o->are('forteaza-liniste'));
}

/**
 * Pachetul, assets/, tema/ și rădăcina docroot-ului, fiecare cu redenumirile ținute minte (Redenumiri): o comutare oprită
 * la jumătate se termină la rularea următoare, fără să șteargă nimic. RELEASE se schimbă abia după migrări (faza release).
 */
function comutareActualizare(Actualizare $u): void
{
    $s = $u->s;
    Redenumiri::fa($u->stare, 'pachet', [[$s->site . '/pachet', $s->site . '/.pachet-' . $u->veche], [$s->site . '/pachet.nou', $s->site . '/pachet']]);
    Redenumiri::fa($u->stare, 'assets', [[$s->docroot . '/assets', $s->docroot . '/.assets-' . $u->veche], [$s->docroot . '/.assets-nou', $s->docroot . '/assets']]);
    $tema = [];
    if ($u->stare->ia('avea_tema', false)) {
        $tema[] = [$s->docroot . '/tema', $s->docroot . '/.tema-' . $u->veche];
    }
    if ($u->stare->ia('cu_tema', false)) {
        $tema[] = [$s->docroot . '/.tema-nou', $s->docroot . '/tema'];
    }
    if ($tema !== []) {
        Redenumiri::fa($u->stare, 'tema', $tema);
    }
    // Rădăcina: fișierele noi scrise peste (fiecare dintr-o bucată); copia celor vechi e din pregătire, deci o reluare
    // nu o strică.
    $noua = Docroot::radacina($s->cod($u->noua), $s->site . '/pachet');
    foreach (array_keys((array) $u->stare->ia('radacina', [])) as $rel) {
        $f = $s->docroot . '/' . $rel;
        if (isset($noua[$rel])) {
            if (!is_file($f) || hash_file('sha256', $f) !== hash_file('sha256', $noua[$rel])) {
                Fisiere::dosar(dirname($f), 0755);
                Fisiere::scrie($f, (string) file_get_contents($noua[$rel]), 0644);
            }
        } else {
            @unlink($f);
        }
    }
    $u->stare->pune('radacina_scrisa', true);
    Iesire::ok('comutat: pachetul, assets/, tema/ și stub-ul versiunii ' . $u->noua . ' (de dinainte: ' . $s->docroot . '/.assets-' . $u->veche . ', '
        . $s->site . '/.pachet-' . $u->veche . '); RELEASE rămâne ' . $u->veche . ' până după migrări');
}

function migrariActualizare(Actualizare $u): void
{
    if ($u->stare->ia('plan_migrari') === 12 && $u->o->are('inapoi')) {
        Iesire::info('--inapoi: fără migrări');
        return;
    }
    $cod = $u->s->cod($u->noua);
    for ($i = 1; $i <= 30; $i++) {
        $r = platforma($u->s->site, $cod, $u->noua, array_merge(['scripts/migreaza.php', '--aplica', '--site=' . $u->slug, '--buget=300'],
            $u->o->are('permite-blocante') ? ['--permite-blocante'] : []), true);
        Iesire::info('migreaza.php --aplica: codul ' . $r['cod'] . ' (' . sensMigrari($r['cod']) . ')');
        if ($r['cod'] === 0) {
            Iesire::ok('migrările sunt la zi');
            return;
        }
        if ($r['cod'] !== 13) {
            throw new Oprire('migreaza.php --aplica: ' . sensMigrari($r['cod']));
        }
    }
    throw new Oprire('migrările n-au terminat după 30 de reluări');
}

/** RELEASE pe versiunea nouă (după migrări), cache-ul paginilor golit, apoi mentenanța ușoară pentru verificări. */
function releaseActualizare(Actualizare $u): void
{
    $u->stare->pune('release_schimbat', true);
    Fisiere::scrie($u->s->site . '/RELEASE', $u->noua . "\n", 0644);
    Iesire::ok('RELEASE = ' . $u->noua . '; cache-ul paginilor golit (' . golesteCacheCod($u->s) . ' fișiere)');
    Mentenanta::pune($u->s, (string) $u->stare->ia('jeton'), false);
    Iesire::ok('mentenanța ușoară: plățile și ERP-ul trec din nou (la codul nou), vizitatorii încă nu');
}

function diagnosticActualizare(Actualizare $u): void
{
    foreach (['--pachet', '--licenta'] as $opt) {
        $r = platforma($u->s->site, $u->s->cod($u->noua), $u->noua, ['scripts/diagnostic.php', $opt], true);
        if ($r['cod'] !== 0) {
            throw new Oprire('diagnostic.php ' . $opt . ' nu trece (mai sus)');
        }
        Iesire::ok('diagnostic.php ' . $opt . ': ok');
    }
}

function verificareActualizare(Actualizare $u): void
{
    $probleme = verificaSiteul($u->s, $u->url, (string) $u->stare->ia('jeton'), $u->noua, $u->o->text('produs'));
    if ($probleme !== []) {
        throw new Oprire(count($probleme) . ' verificări ale versiunii noi au picat: ' . implode('; ', $probleme));
    }
}

function deschidereActualizare(Actualizare $u): void
{
    Mentenanta::scoate($u->s);
    $r = Http::cere($u->url . '/');
    if ($r['cod'] !== 200 || str_contains($r['corp'], 'Revenim în câteva minute')) {
        Mentenanta::pune($u->s, (string) $u->stare->ia('jeton'), false);
        throw new Oprire('fără MENTENANTA prima pagină nu răspunde 200 (HTTP ' . $r['cod'] . ')');
    }
    Iesire::ok('MENTENANTA ștearsă: ' . $u->url . '/ răspunde 200 oricui');
    $u->stare->pune('jeton', null);
}

/** Desface comutarea, în ordinea inversă, apoi verifică versiunea veche și deschide site-ul dacă trece. */
function intoarce(Actualizare $u): void
{
    $s = $u->s;
    $st = $u->stare;
    Iesire::titlu('Întoarcerea la ' . $u->veche);
    $st->pune('intoarcere_in_curs', true);
    $st->pune('intoarcere_pasi_gata', false);
    Mentenanta::pune($s, (string) $st->ia('jeton', '') ?: bin2hex(random_bytes(16)), true);
    // O redenumire rămasă la jumătate (de exemplu procesul oprit) se termină întâi, ca să se poată desface întreagă.
    Redenumiri::termina($st);
    $facute = (array) $st->ia('redenumiri_facute', []);
    if ($st->ia('release_schimbat', false)) {
        Fisiere::scrie($s->site . '/RELEASE', $u->veche . "\n", 0644);
        Iesire::ok('RELEASE = ' . $u->veche . '; cache-ul paginilor golit (' . golesteCacheCod($s) . ' fișiere)');
    } else {
        Iesire::ok('RELEASE a rămas ' . $u->veche . ' (nu ajunsese să se schimbe)');
    }
    if ($st->ia('radacina_scrisa', false) || in_array('assets', $facute, true)) {
        foreach ((array) $st->ia('radacina', []) as $rel => $exista) {
            $f = $s->docroot . '/' . $rel;
            if ($exista) {
                Fisiere::scrie($f, (string) file_get_contents($u->radacinaVeche() . '/' . $rel), 0644);
            } else {
                @unlink($f);
            }
        }
        Iesire::ok('stub-ul și fișierele din rădăcina docroot-ului, cele de dinainte');
    }
    if (in_array('tema', $facute, true)) {
        $pasi = [];
        if ($st->ia('cu_tema', false)) {
            $pasi[] = [$s->docroot . '/tema', $s->docroot . '/.tema-nou'];
        }
        if ($st->ia('avea_tema', false)) {
            $pasi[] = [$s->docroot . '/.tema-' . $u->veche, $s->docroot . '/tema'];
        }
        Redenumiri::fa($st, 'inapoi-tema', $pasi);
    }
    if (in_array('assets', $facute, true)) {
        Redenumiri::fa($st, 'inapoi-assets', [[$s->docroot . '/assets', $s->docroot . '/.assets-nou'], [$s->docroot . '/.assets-' . $u->veche, $s->docroot . '/assets']]);
        Iesire::ok('assets/' . (in_array('tema', $facute, true) ? ' și tema/' : '') . ' de dinainte');
    }
    if (in_array('pachet', $facute, true)) {
        Redenumiri::fa($st, 'inapoi-pachet', [[$s->site . '/pachet', $s->site . '/pachet.nou'], [$s->site . '/.pachet-' . $u->veche, $s->site . '/pachet']]);
        Iesire::ok('pachetul de dinainte');
    }
    $st->pune('intoarcere_pasi_gata', true);
    Iesire::scrie('');
    Iesire::scrie('Verificarea versiunii ' . $u->veche . ', după întoarcere:');
    Mentenanta::pune($s, (string) $st->ia('jeton', '') ?: bin2hex(random_bytes(16)), false);
    $probleme = verificaSiteul($s, $u->url, trim((string) strtok((string) Mentenanta::citeste($s), "\n")), $u->veche, $u->o->text('produs'));
    if ($probleme !== []) {
        throw new Oprire('nici versiunea ' . $u->veche . ' nu trece verificarea după întoarcere: ' . implode('; ', $probleme) . '; site-ul rămâne în MENTENANȚĂ', 1, [
            'uită-te în ' . $s->site . '/storage/logs/php.log și fatal.log',
            'site-ul îl vezi cu ' . $u->url . '/?andaxi_deploy=' . trim((string) strtok((string) Mentenanta::citeste($s), "\n")),
            'o versiune care repară problema: actualizeaza.sh --versiune=<versiunea reparată> --aplica (merge mai departe de aici)',
            'sau deschis cu versiunea veche, cu riscul tău: aceeași comandă cu --accepta-revenirea',
        ]);
    }
    Mentenanta::scoate($s);
    $st->pune('intoarsa', true);
    $st->pune('intoarcere_in_curs', false);
    Iesire::ok('versiunea ' . $u->veche . ' trece verificarea; MENTENANTA ștearsă, site-ul e deschis');
}

/** După o actualizare reușită: păstrează doar resturile versiunii de dinainte (pentru o întoarcere de mână). */
function curataResturi(Actualizare $u): void
{
    $s = $u->s;
    foreach ([[$s->docroot, '.assets-'], [$s->docroot, '.tema-'], [$s->site, '.pachet-']] as [$dosar, $prefix]) {
        foreach (glob($dosar . '/' . $prefix . '*', GLOB_ONLYDIR) ?: [] as $d) {
            if (basename($d) !== $prefix . $u->veche) {
                Fisiere::sterge($d, [$dosar]);
            }
        }
    }
    foreach ([$s->docroot . '/.assets-nou', $s->docroot . '/.tema-nou', $s->site . '/pachet.nou'] as $d) {
        Fisiere::sterge($d, [$s->docroot, $s->site]);
    }
    // Copiile locale ale versiunilor (open_basedir): doar cea nouă și cea de dinainte. Ștergerea nu atinge fișierele
    // versiunii comune (legături fixe; Fisiere::sterge face scriibile doar dosarele copiei).
    foreach (glob($s->site . '/releases/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (!in_array(basename($d), [$u->noua, $u->veche], true)) {
            Fisiere::sterge($d, [$s->site . '/releases']);
        }
    }
}
