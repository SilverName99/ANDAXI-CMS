<?php

declare(strict_types=1);

/**
 * deploy/actualizeaza.sh: trece un site deja adoptat pe o versiune nouă de cod (docs/DEPLOY.md, „O versiune nouă”).
 * Implicit doar planul; cu --aplica, fazele:
 *
 *   verificari  versiunea nouă (și cea de acum) urcate și neatinse, pachetul, licența și migreaza --plan cu codul nou
 *   pregatire   pachetul nou (site/pachet.nou), assets/ și tema/ noi (public_html/.assets-nou, .tema-nou), stub-ul
 *   copie       copia bazei
 *   mentenanta  MENTENANTA (cu jetonul verificărilor)
 *   comutare    pachetul, assets/, tema/, fișierele din rădăcina docroot-ului și RELEASE, pe rând, ținute minte
 *   migrari     migreaza --aplica cu codul nou (reluat la codul 13)
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

const FAZE_ACTUALIZARE = ['verificari', 'pregatire', 'copie', 'mentenanta', 'comutare', 'migrari', 'diagnostic', 'verificare', 'deschidere'];
const FAZE_CU_INTOARCERE = ['comutare', 'migrari', 'diagnostic', 'verificare', 'deschidere'];

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
    $o = Optiuni::citeste($argv, ['site=', 'domeniu=', 'versiune=', 'url=', 'repo=', 'pachet=', 'produs=', 'permite-blocante', 'inapoi', 'acasa=', 'plan', 'aplica']);
    $u = new Actualizare($o);
    $lacat = $u->s->lacat();
    $stare = $u->stare;
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
        Iesire::scrie('PLAN: nimic schimbat. Cu --aplica: pregătirea, copia bazei, mentenanța, comutarea, migrările, diagnosticul, testul de fum, deschiderea '
            . '(cu întoarcerea singură la ' . $u->veche . ' dacă ceva pică după comutare).');
        return 0;
    }
    Iesire::jurnal($u->s->lucru . '/jurnale/actualizeaza-' . date('Ymd-His') . '.log');
    $stare->salveaza();
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
                @unlink($u->s->site . '/MENTENANTA');
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
    Fisiere::scrie($u->s->site . '/MENTENANTA', $jeton . "\n", 0640);
    Iesire::ok('MENTENANTA: vizitatorii văd „Revenim în câteva minute” (plățile, ERP-ul și pingul trec)');
}

/** Fiecare pas al comutării se ține minte, ca întoarcerea să-l desfacă exact. */
function comutareActualizare(Actualizare $u): void
{
    $s = $u->s;
    $facute = (array) $u->stare->ia('comutari', []);
    $pas = static function (string $nume, callable $f) use ($u, &$facute): void {
        if (!in_array($nume, $facute, true)) {
            $f();
            $facute[] = $nume;
            $u->stare->pune('comutari', $facute);
        }
    };
    $pas('pachet', static function () use ($s, $u): void {
        Fisiere::sterge($s->site . '/.pachet-' . $u->veche, [$s->site]);
        Fisiere::muta($s->site . '/pachet', $s->site . '/.pachet-' . $u->veche);
        Fisiere::muta($s->site . '/pachet.nou', $s->site . '/pachet');
    });
    $pas('assets', static function () use ($s, $u): void {
        Fisiere::sterge($s->docroot . '/.assets-' . $u->veche, [$s->docroot]);
        Fisiere::muta($s->docroot . '/assets', $s->docroot . '/.assets-' . $u->veche);
        Fisiere::muta($s->docroot . '/.assets-nou', $s->docroot . '/assets');
    });
    $pas('tema', static function () use ($s, $u): void {
        Fisiere::sterge($s->docroot . '/.tema-' . $u->veche, [$s->docroot]);
        $u->stare->pune('avea_tema', is_dir($s->docroot . '/tema'));
        if (is_dir($s->docroot . '/tema')) {
            Fisiere::muta($s->docroot . '/tema', $s->docroot . '/.tema-' . $u->veche);
        }
        if (is_dir($s->docroot . '/.tema-nou')) {
            Fisiere::muta($s->docroot . '/.tema-nou', $s->docroot . '/tema');
        }
    });
    $pas('radacina', static function () use ($s, $u): void {
        $veche = Docroot::radacina($s->cod($u->veche), $s->site . '/.pachet-' . $u->veche);
        $noua = Docroot::radacina($s->cod($u->noua), $s->site . '/pachet');
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
        foreach (array_keys($veche + $noua) as $rel) {
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
    });
    $pas('release', static function () use ($s, $u): void {
        Fisiere::scrie($s->site . '/RELEASE', $u->noua . "\n", 0644);
    });
    Iesire::ok('cache-ul paginilor golit (' . golesteCacheCod($s) . ' fișiere): nicio pagină a versiunii de dinainte nu se mai servește');
    Iesire::ok('comutat pe ' . $u->noua . ': pachetul, assets/, tema/, stub-ul și RELEASE (de dinainte: ' . $s->docroot . '/.assets-' . $u->veche . ', '
        . $s->site . '/.pachet-' . $u->veche . ')');
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
    @unlink($u->s->site . '/MENTENANTA');
    $r = Http::cere($u->url . '/');
    if ($r['cod'] !== 200 || str_contains($r['corp'], 'Revenim în câteva minute')) {
        Fisiere::scrie($u->s->site . '/MENTENANTA', (string) $u->stare->ia('jeton') . "\n", 0640);
        throw new Oprire('fără MENTENANTA prima pagină nu răspunde 200 (HTTP ' . $r['cod'] . ')');
    }
    Iesire::ok('MENTENANTA ștearsă: ' . $u->url . '/ răspunde 200 oricui');
    $u->stare->pune('jeton', null);
}

/** Desface comutarea, în ordinea inversă, apoi verifică versiunea veche și deschide site-ul dacă trece. */
function intoarce(Actualizare $u): void
{
    $s = $u->s;
    Iesire::titlu('Întoarcerea la ' . $u->veche);
    if (!is_file($s->site . '/MENTENANTA')) {
        Fisiere::scrie($s->site . '/MENTENANTA', (string) $u->stare->ia('jeton', bin2hex(random_bytes(16))) . "\n", 0640);
    }
    $facute = (array) $u->stare->ia('comutari', []);
    // Un pas oprit la jumătate (între cele două redenumiri) se pune la loc aici.
    foreach ([[$s->docroot . '/assets', $s->docroot . '/.assets-' . $u->veche, $s->docroot . '/.assets-nou'],
        [$s->site . '/pachet', $s->site . '/.pachet-' . $u->veche, $s->site . '/pachet.nou']] as [$loc, $vechi, $nou]) {
        if (!is_dir($loc) && is_dir($vechi)) {
            Fisiere::muta($vechi, $loc);
            Iesire::ok('pus la loc, după un pas oprit la jumătate: ' . $loc);
            $facute = array_values(array_diff($facute, [basename($loc) === 'assets' ? 'assets' : 'pachet']));
        }
    }
    if (in_array('release', $facute, true)) {
        Fisiere::scrie($s->site . '/RELEASE', $u->veche . "\n", 0644);
        Iesire::ok('RELEASE = ' . $u->veche . '; cache-ul paginilor golit (' . golesteCacheCod($s) . ' fișiere)');
    }
    if (in_array('radacina', $facute, true)) {
        foreach ((array) $u->stare->ia('radacina', []) as $rel => $exista) {
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
        if (is_dir($s->docroot . '/tema')) {
            Fisiere::sterge($s->docroot . '/.tema-nou', [$s->docroot]);
            Fisiere::muta($s->docroot . '/tema', $s->docroot . '/.tema-nou');
        }
        if ($u->stare->ia('avea_tema', false)) {
            Fisiere::muta($s->docroot . '/.tema-' . $u->veche, $s->docroot . '/tema');
        }
    }
    if (in_array('assets', $facute, true)) {
        Fisiere::sterge($s->docroot . '/.assets-nou', [$s->docroot]);
        Fisiere::muta($s->docroot . '/assets', $s->docroot . '/.assets-nou');
        Fisiere::muta($s->docroot . '/.assets-' . $u->veche, $s->docroot . '/assets');
        Iesire::ok('assets/' . (in_array('tema', $facute, true) ? ' și tema/' : '') . ' de dinainte');
    }
    if (in_array('pachet', $facute, true)) {
        Fisiere::sterge($s->site . '/pachet.nou', [$s->site]);
        Fisiere::muta($s->site . '/pachet', $s->site . '/pachet.nou');
        Fisiere::muta($s->site . '/.pachet-' . $u->veche, $s->site . '/pachet');
        Iesire::ok('pachetul de dinainte');
    }
    $u->stare->pune('comutari', []);
    $u->stare->pune('intoarsa', true);
    Iesire::scrie('');
    Iesire::scrie('Verificarea versiunii ' . $u->veche . ', după întoarcere:');
    $probleme = verificaSiteul($s, $u->url, (string) $u->stare->ia('jeton'), $u->veche, $u->o->text('produs'));
    if ($probleme !== []) {
        throw new Oprire('nici versiunea ' . $u->veche . ' nu trece verificarea după întoarcere: ' . implode('; ', $probleme) . '; site-ul rămâne în MENTENANȚĂ', 1, [
            'uită-te în ' . $s->site . '/storage/logs/php.log și fatal.log',
            'site-ul nou îl vezi cu ' . $u->url . '/?andaxi_deploy=' . $u->stare->ia('jeton'),
            'deschis de mână, cu riscul tău: rm ' . $s->site . '/MENTENANTA',
        ]);
    }
    @unlink($s->site . '/MENTENANTA');
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
}
