<?php

declare(strict_types=1);

/**
 * deploy/adopta.sh: trece un site live de pe codul lui vechi (un checkout git în public_html/) pe codul comun, în
 * așezarea din Etapa 2 (docs/DEPLOY.md; docs/MIGRARI.md, „Pe server, la adoptare”). Implicit doar planul (--plan): ce
 * ar verifica și ce ar face, fără nicio schimbare a site-ului. Cu --aplica rulează fazele, în ordine:
 *
 *   verificari  sodium (web și linia de comandă), PHP-ul, uneltele, spațiul, baza, pachetul, privat/, licența, codul vechi
 *               (git curat, la commit-ul revizuit), site-ul vechi răspunde; nimic schimbat
 *   pregatire   site/ (.env, RELEASE, pachetul, storage/) și public_html.nou/ (stub-ul, assets/, tema/); diagnostic
 *               --pachet și --licenta, migreaza --plan; vizitatorii nu văd nimic
 *   copie       copia codului vechi (tar.gz) și o copie a bazei, cu site-ul încă pornit
 *   mentenanta  MENTENANTA strictă (nici plățile, ERP-ul sau cronurile nu scriu), comutarea (public_html →
 *               public_html.vechi, public_html.nou → public_html), uploads/ și fișierele site-ului aduse în docroot,
 *               liniștea pe bază, copia bazei de revenire (amprenta înainte și după ea)
 *   migrari     migreaza --plan (arătat), apoi --aplica, reluat cât spune bugetul de timp (codul 13); amprenta bazei
 *               după migrări (pentru revino.sh), apoi mentenanța ușoară (plățile și ERP-ul trec din nou)
 *   diagnostic  diagnostic.php --pachet și --licenta
 *   verificare  testul de fum: pingul, /health, prima pagină, un produs, logarea din admin, coșul, assets/, o poză
 *   deschidere  MENTENANTA ștearsă, site-ul deschis
 * apoi rândurile noi de cron pentru hPanel (și care se șterg).
 *
 * Fiecare fază se ține minte în site/deploy/adopta.stare: aceeași comandă, rulată din nou, continuă de la prima fază
 * neterminată. La prima problemă se oprește cu un mesaj clar; după comutare site-ul rămâne în mentenanță până la
 * reparare (aceeași comandă) sau la revenire (deploy/revino.sh).
 *
 *   deploy/adopta.sh --site=biovitality --domeniu=biovitality.ro --versiune=2026.11.05-e4f5a6b --commit-vechi=d95598e
 *   deploy/adopta.sh … --aplica
 *
 * O reluare cu alte opțiuni (versiune, pachet, cheia licenței, commit-ul vechi) e refuzată: înainte de comutare se ia de
 * la capăt cu --reia=verificari, după comutare doar cu aceleași opțiuni (sau revino.sh). --cron-de-mana: rândurile de cron
 * „DE VERIFICAT” le rescrii tu (altfel verificările se opresc).
 *
 * Obligatorii: --site=<slug> (pachetul din site-uri/<slug>/), --domeniu=<d> (~/domains/<d>/public_html),
 * --versiune=<v> (făcută cu release.sh), --commit-vechi=<commit> (commit-ul revizuit al codului vechi, docs/de-portat.md).
 * Opțiuni: --url=<adresa> (implicit https://<d>), --repo=<clonă> (pachetul, la commit-ul versiunii; implicit
 * ~/platforma/repo), --pachet=<dosar> (pachetul dintr-un dosar, în locul repo-ului), --cheie-licenta=<base64>
 * (LICENTA_CHEIE_PUBLICA, dacă .env-ul vechi n-o are), --cron-vechi=<fișier> (rândurile vechi de cron, dacă `crontab -l`
 * nu le arată), --php-cron=<comanda> (PHP-ul din rândurile noi), --produs=<slug>, --permite-blocante, --accepta-fisiere-noi
 * (fișiere necunoscute lui git în codul vechi), --fara-sonda-web (sodium-ul web verificat de mână, în hPanel),
 * --reia=<faza> (rulează din nou o fază terminată și pe cele de după), --acasa=<dosar> (teste).
 */

namespace Platforma\Deploy;

require __DIR__ . '/deploy.php';

const FAZE = ['verificari', 'pregatire', 'copie', 'mentenanta', 'migrari', 'diagnostic', 'verificare', 'deschidere'];

final class Adoptare
{
    public Site $s;
    public Stare $stare;
    public string $slug;
    public string $versiune;
    public string $cod;
    public string $url;
    public string $commitVechi;
    public bool $aplica;
    /** @var array<string, string> */
    public array $envVechi = [];

    public function __construct(public Optiuni $o)
    {
        $this->slug = $o->cere('site', '<site> (numele pachetului: biovitality, bioscem, vertij, grafoanaytis)');
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $this->slug) !== 1) {
            throw new Oprire('--site=' . $this->slug . ' nu e un nume de site', 2);
        }
        $this->s = new Site(Site::acasa($o), $o->cere('domeniu', '<domeniu> (exemplu.ro: dosarul ~/domains/<domeniu>/public_html)'));
        $this->versiune = $o->cere('versiune', '<versiune> (făcută cu deploy/release.sh)');
        if (preg_match(Versiune::TIPAR, $this->versiune) !== 1) {
            throw new Oprire('--versiune=' . $this->versiune . ' nu are forma 2026.11.05-e4f5a6b', 2);
        }
        $this->commitVechi = strtolower($o->cere('commit-vechi', '<commit> (commit-ul revizuit al codului vechi, docs/de-portat.md)'));
        if (preg_match('/^[0-9a-f]{7,40}$/', $this->commitVechi) !== 1) {
            throw new Oprire('--commit-vechi=' . $this->commitVechi . ' nu e un commit (7–40 de caractere hexazecimale)', 2);
        }
        $this->url = rtrim($o->text('url') ?? 'https://' . $this->s->domeniu, '/');
        $this->aplica = $o->aplica();
        $this->stare = new Stare($this->s->lucru . '/adopta.stare');
        $this->cod = $this->s->releases . '/' . $this->versiune;
    }

    /** Comutarea a început (sau s-a făcut)? După starea ei și după jurnalul redenumirilor. */
    public function comutat(): bool
    {
        return (bool) $this->stare->ia('comutat', false) || isset($this->stare->date['redenumiri']['comutare'])
            || in_array('comutare', (array) $this->stare->ia('redenumiri_facute', []), true);
    }

    /** Opțiunile de care depinde ce se pregătește (cheia licenței doar ca sha256). @return array<string, string> */
    public function optiuni(): array
    {
        return ['versiune' => $this->versiune, 'commit-vechi' => $this->commitVechi, 'pachet' => (string) $this->o->text('pachet'),
            'repo' => $this->repo(), 'cheie-licenta' => hash('sha256', (string) $this->o->text('cheie-licenta'))];
    }

    public function codSite(): string
    {
        return $this->s->cod($this->versiune);
    }

    /** Unde stă pachetul în repo: commit-ul versiunii (COMMIT). */
    public function commitVersiune(): string
    {
        return trim((string) @file_get_contents($this->cod . '/COMMIT'));
    }

    public function repo(): string
    {
        return $this->o->text('repo') ?? $this->s->acasa . '/platforma/repo';
    }

    /** Pachetul site-ului, scris în $tinta, cu privat/ copiat din site/privat. */
    public function scriePachet(string $tinta): void
    {
        scriePachet($this->s, $this->slug, $this->o->text('pachet'), $this->repo(), $this->commitVersiune(), $tinta);
    }

    /** @return array{0: ?array, 1: ?array} pachet.json și setari-inghetate.json ale pachetului */
    public function citestePachet(): array
    {
        return citestePachet($this->slug, $this->o->text('pachet'), $this->repo(), $this->commitVersiune());
    }

    /** Cheile Etapei 2 din .env. @return array<string, string> */
    public function cheiEnv(string $dosarPachet): array
    {
        $chei = ['SITE_SLUG' => $this->slug, 'SITE_DATE_DOSAR' => $dosarPachet, 'LICENTA_SITE' => $this->slug];
        $cheie = $this->o->text('cheie-licenta');
        if ($cheie !== null) {
            $chei['LICENTA_CHEIE_PUBLICA'] = $cheie;
        }
        return $chei;
    }

    /** Textul noului .env: cel vechi, cu cheile Etapei 2 puse. */
    public function envNou(string $dosarPachet): string
    {
        return Env::cuChei((string) file_get_contents($this->s->docroot . '/.env'), $this->cheiEnv($dosarPachet),
            'Etapa 2 (deploy/adopta.sh, ' . date('Y-m-d H:i') . '): pachetul site-ului și licența');
    }

    /** Unde pune sonda web: docroot-ul pe care îl servește codul vechi (public/ când .htaccess-ul din rădăcină trimite acolo). */
    public function dosarSonda(): string
    {
        $d = $this->s->docroot;
        return is_file($d . '/public/index.php') && str_contains((string) @file_get_contents($d . '/.htaccess'), 'public/') ? $d . '/public' : $d;
    }
}

exit(porneste(static function () use ($argv): int {
    $o = Optiuni::citeste($argv, ['site=', 'domeniu=', 'versiune=', 'commit-vechi=', 'url=', 'repo=', 'pachet=', 'cheie-licenta=', 'cron-vechi=',
        'php-cron=', 'produs=', 'permite-blocante', 'accepta-fisiere-noi', 'fara-sonda-web', 'cron-de-mana', 'forteaza-liniste', 'reia=', 'acasa=', 'plan', 'aplica']);
    $a = new Adoptare($o);
    $lacat = $a->s->lacat();
    stergeSonde($a);
    Mentenanta::avertizeazaVeche($a->s);
    Iesire::titlu('Adoptarea site-ului ' . $a->slug . ' (' . $a->s->domeniu . ') pe versiunea ' . $a->versiune . ($a->aplica ? '' : ' — PLAN'));
    Iesire::info('dosarul : ' . $a->s->dosar);
    Iesire::info('adresa  : ' . $a->url);

    if ($a->stare->facuta('deschidere')) {
        throw new Oprire('site-ul ' . $a->s->domeniu . ' e deja adoptat (' . $a->stare->date['faze']['deschidere'] . '). O versiune nouă: deploy/actualizeaza.sh', 1);
    }
    if (!$a->aplica) {
        verificari($a);
        planul($a);
        return 0;
    }
    Iesire::jurnal($a->s->lucru . '/jurnale/adopta-' . date('Ymd-His') . '.log');
    Redenumiri::termina($a->stare);
    // O adoptare începută continuă doar cu aceleași opțiuni: altă versiune, alt pachet sau altă cheie ar amesteca ce s-a
    // pregătit cu ce urmează. Înainte de comutare se poate începe din nou (--reia=verificari); după, nu.
    $optiuni = $a->optiuni();
    $vechi = $a->stare->ia('optiuni');
    if (is_array($vechi) && $a->stare->date['faze'] !== [] && $vechi !== $optiuni) {
        $diferite = implode(', ', array_keys(array_diff_assoc(array_map('strval', $optiuni), array_map('strval', $vechi))
            + array_diff_assoc(array_map('strval', $vechi), array_map('strval', $optiuni))));
        if ($a->comutat()) {
            throw new Oprire('adoptarea începută (comutată deja) a avut alte opțiuni (' . $diferite . '): continu-o cu aceleași, sau revino (deploy/revino.sh)', 2);
        }
        if ($o->text('reia') !== 'verificari') {
            throw new Oprire('adoptarea începută a avut alte opțiuni (' . $diferite . '); pentru a o lua de la capăt cu cele noi: aceeași comandă cu --reia=verificari', 2);
        }
    }
    $a->stare->pune('optiuni', $optiuni);
    if (($reia = $o->text('reia')) !== null) {
        $i = array_search($reia, FAZE, true);
        if ($i === false || ($i <= 3 && $a->stare->facuta('mentenanta'))) {
            throw new Oprire('--reia=' . $reia . ': ' . ($i === false ? 'fazele sunt ' . implode(', ', FAZE) : 'site-ul e deja comutat; poți relua doar de la migrari încolo'), 2);
        }
        if ($a->stare->facuta('migrari') && $i <= array_search('migrari', FAZE, true)) {
            verificaReluareaMigrarilor($a);
        }
        foreach (array_slice(FAZE, (int) $i) as $f) {
            unset($a->stare->date['faze'][$f]);
        }
        $a->stare->salveaza();
    }
    $a->stare->pune('versiune', $a->versiune);
    $a->stare->pune('site', $a->slug);
    foreach (FAZE as $faza) {
        if ($a->stare->facuta($faza)) {
            Iesire::titlu($faza . ': făcută deja (' . $a->stare->date['faze'][$faza] . '), o sar');
            continue;
        }
        Iesire::titlu($faza);
        try {
            testOpreste('inainte-' . $faza);
            ('Platforma\\Deploy\\' . $faza)($a);
        } catch (Oprire $e) {
            throw new Oprire('faza „' . $faza . '”: ' . $e->getMessage(), $e->getCode(), array_merge($e->pasi, dupaEsec($a, $faza)));
        }
        $a->stare->marcheaza($faza);
    }
    cron($a);
    Iesire::titlu('GATA');
    Iesire::scrie('Site-ul ' . $a->s->domeniu . ' merge pe codul comun, versiunea ' . $a->versiune . '.');
    Iesire::scrie('Codul vechi a rămas, neatins, în ' . $a->s->vechi . ' (revenirea: deploy/revino.sh --site=' . $a->slug . ' --aplica).');
    Iesire::scrie('Copiile: ' . $a->stare->ia('copii') . '. Rândurile de cron: ' . $a->s->lucru . '/cron-nou.txt.');
    unset($lacat);
    return 0;
}));

/** @return list<string> ce poate face omul după o oprire în faza dată */
function dupaEsec(Adoptare $a, string $faza): array
{
    if (!$a->comutat()) {
        return ['site-ul a rămas pe codul vechi, neatins; vizitatorii n-au văzut nimic',
            'după reparare rulezi din nou aceeași comandă (cu --aplica): continuă de la faza „' . $faza . '”'];
    }
    $jeton = (string) $a->stare->ia('jeton', '');
    return [
        Mentenanta::mod($a->s) === 'strict'
            ? 'site-ul e în MENTENANȚA STRICTĂ: vizitatorii văd „Revenim în câteva minute”, plățile și ERP-ul primesc 503 și retrimit mai târziu, cronurile nu rulează'
            : 'site-ul e în MENTENANȚĂ: vizitatorii văd „Revenim în câteva minute” (plățile, ERP-ul și pingul trec; cronurile nu rulează)',
        'site-ul nou îl vezi în browser cu ' . $a->url . '/?andaxi_deploy=' . $jeton,
        'drumul 1: repari, apoi rulezi din nou aceeași comandă (cu --aplica); continuă de la faza „' . $faza . '”',
        'drumul 2, te întorci la codul vechi (câteva minute): deploy/revino.sh --site=' . $a->slug . ' --domeniu=' . $a->s->domeniu . ' --aplica',
    ];
}

function verificari(Adoptare $a): void
{
    $s = $a->s;
    $probleme = [];
    $nu = static function (string $text) use (&$probleme): void {
        Iesire::nu($text);
        $probleme[] = $text;
    };

    // Uneltele și PHP-ul liniei de comandă.
    foreach (['git', 'tar', 'gzip', 'cp', 'du'] as $p) {
        if (!Comanda::exista($p)) {
            $nu('lipsește programul ' . $p);
        }
    }
    foreach ([['mysqldump', 'mariadb-dump'], ['mysql', 'mariadb']] as $variante) {
        if (Comanda::primul(...$variante) === null) {
            $nu('lipsește ' . $variante[0] . ' (sau ' . $variante[1] . ')');
        }
    }
    $lipsa = array_values(array_filter(['sodium', 'pdo_mysql', 'curl', 'mbstring', 'json'], static fn (string $e): bool => !extension_loaded($e)));
    if ($lipsa === []) {
        Iesire::ok('PHP-ul liniei de comandă ' . PHP_VERSION . ' are sodium, pdo_mysql, curl, mbstring (' . implode(' ', Comanda::php()) . ')');
    } else {
        $nu('PHP-ul liniei de comandă n-are: ' . implode(', ', $lipsa) . (in_array('sodium', $lipsa, true)
            ? ' (pe Hostinger: PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so")' : ''));
    }

    // Versiunea de cod.
    $problemeVersiune = Versiune::verifica($a->cod, $a->versiune);
    if ($problemeVersiune === []) {
        $phpMin = Versiune::phpMin($a->cod);
        Iesire::ok('versiunea ' . $a->versiune . ' e urcată și neatinsă (' . $a->cod . ')');
        if (version_compare(PHP_VERSION, $phpMin, '<')) {
            $nu('PHP-ul liniei de comandă ' . PHP_VERSION . ' e sub minimul codului, ' . $phpMin);
        }
    } else {
        $nu('versiunea ' . $a->versiune . ': ' . implode('; ', array_slice($problemeVersiune, 0, 3)) . ' (deploy/release.sh --ref=… --aplica)');
        $phpMin = '8.3';
    }

    // Site-ul de acum.
    if (!is_dir($s->docroot)) {
        throw new Oprire('nu există ' . $s->docroot);
    }
    if ($s->cuStub()) {
        throw new Oprire('în ' . $s->docroot . ' e deja stub-ul codului comun: site-ul e adoptat. O versiune nouă: deploy/actualizeaza.sh', 1);
    }
    if (is_dir($s->vechi)) {
        $nu('există deja ' . $s->vechi . ' (de la o încercare de dinainte?); uită-te în el și mută-l de acolo înainte');
    }
    $git = static fn (string ...$x): array => Comanda::ruleaza(array_merge(['git', '-C', $s->docroot], $x));
    $head = trim($git('rev-parse', 'HEAD')['iesire']);
    if (!is_dir($s->docroot . '/.git') || preg_match('/^[0-9a-f]{40}$/', $head) !== 1) {
        $nu('codul vechi din ' . $s->docroot . ' nu e un checkout git');
    } elseif (!str_starts_with($head, $a->commitVechi)) {
        $nu('codul vechi e la commit-ul ' . substr($head, 0, 12) . ', nu la cel revizuit (' . $a->commitVechi . '); verifică cu git -C ' . $s->docroot . ' log -1');
    } else {
        Iesire::ok('codul vechi e la commit-ul revizuit ' . substr($head, 0, 12) . ' („' . trim($git('show', '-s', '--format=%s', 'HEAD')['iesire']) . '”)');
        $a->stare->date['commit_vechi_complet'] = $head;
    }
    $schimbate = [];
    $noi = [];
    foreach (explode("\n", $git('status', '--porcelain=v1', '--untracked-files=all')['iesire']) as $linie) {
        if (trim($linie) === '') {
            continue;
        }
        $cale = substr($linie, 3);
        if (str_starts_with($linie, '??')) {
            if (basename($cale) !== 'error_log') {
                $noi[] = $cale;
            }
        } else {
            $schimbate[] = trim($linie);
        }
    }
    if ($schimbate !== []) {
        $nu('codul vechi are fișiere schimbate față de commit (' . count($schimbate) . '): ' . implode(', ', array_slice($schimbate, 0, 8))
            . '; git -C ' . $s->docroot . ' diff arată ce');
    }
    if ($noi !== [] && !$a->o->are('accepta-fisiere-noi')) {
        $nu('codul vechi are fișiere pe care git nu le știe (' . count($noi) . '): ' . implode(', ', array_slice($noi, 0, 8))
            . '; dacă rămân în codul vechi (și cele din public/ se aduc în docroot), rulează cu --accepta-fisiere-noi');
    } elseif ($schimbate === [] && is_dir($s->docroot . '/.git')) {
        Iesire::ok('git status: nicio schimbare față de commit' . ($noi !== [] ? ', ' . count($noi) . ' fișiere noi acceptate' : ''));
    }

    // .env-ul și baza.
    $a->envVechi = Env::citeste($s->docroot . '/.env');
    if ($a->envVechi === []) {
        throw new Oprire('nu există ' . $s->docroot . '/.env (sau e gol): de acolo iau baza site-ului');
    }
    if (in_array(strtolower($a->envVechi['APP_DEBUG'] ?? ''), ['true', '1'], true)) {
        Iesire::atentie('.env-ul vechi are APP_DEBUG=true: pe un site live erorile ar apărea vizitatorilor (îl schimbi în noul site/.env)');
    }
    $baza = new Baza($a->envVechi);
    try {
        $tabele = $baza->tabele();
        $octeti = $baza->octeti();
        $obiecte = $baza->obiecte();
        $a->stare->date['baza_octeti'] = $octeti;
        if (!in_array('settings', $tabele, true)) {
            $nu('baza ' . $baza->nume() . ' n-are tabelul settings: nu e baza unui site al platformei');
        } else {
            Iesire::ok('baza ' . $baza->nume() . ' răspunde: ' . count($tabele) . ' tabele, ' . Fisiere::om($octeti)
                . ($obiecte['declansatoare'] + $obiecte['rutine'] + $obiecte['vederi'] > 0 ? ' (declanșatoare ' . $obiecte['declansatoare'] . ', rutine '
                    . $obiecte['rutine'] . ', vederi ' . $obiecte['vederi'] . ': intră în copie)' : ''));
        }
        $straini = $baza->definitoriStraini();
        if ($straini !== []) {
            $nu('obiecte ale bazei cu alt DEFINER decât utilizatorul din .env (copia nu s-ar putea pune la loc): ' . implode(', ', array_slice($straini, 0, 5)));
        }
        $drepturi = $baza->drepturi();
        if (!in_array('ALL PRIVILEGES', $drepturi, true)) {
            $lipsa = array_values(array_diff(['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX'], $drepturi));
            if ($lipsa !== []) {
                $nu('utilizatorul bazei n-are dreptul ' . implode(', ', $lipsa) . ' (migrările și revenirea îl cer)');
            }
        }
    } catch (Oprire $e) {
        $nu($e->getMessage());
        $octeti = 0;
    }

    // Pachetul, privat/, licența.
    if ($a->o->text('pachet') === null && $problemeVersiune === []) {
        if (preg_match('/^[0-9a-f]{40}$/', $a->commitVersiune()) !== 1) {
            $nu('versiunea n-are fișierul COMMIT');
        } elseif (Comanda::ruleaza(['git', '-C', $a->repo(), 'cat-file', '-e', $a->commitVersiune() . '^{commit}'])['cod'] !== 0) {
            $nu('repo-ul ' . $a->repo() . ' n-are commit-ul versiunii (' . substr($a->commitVersiune(), 0, 12) . '): git -C ' . $a->repo() . ' fetch origin');
        }
    }
    [$manifest, $inghetare] = $a->citestePachet();
    $problemePachet = problemePachet($s, $a->slug, $manifest, $inghetare);
    foreach ($problemePachet as $p) {
        $nu($p);
    }
    if ($problemePachet === []) {
        Iesire::ok('pachetul site-uri/' . $a->slug . '/: ' . count((array) ($manifest['fisiere'] ?? [])) . ' fișiere'
            . ($a->o->text('pachet') !== null ? ' (din ' . $a->o->text('pachet') . ')' : ' (din repo, commit-ul versiunii)')
            . (is_file($s->site . '/privat/setari-inghetate.json') ? '; valorile private în ' . $s->site . '/privat/' : ''));
    }
    $licente = glob($s->site . '/storage/licenta/licenta*.json') ?: [];
    if ($licente === []) {
        $nu('lipsește licența: ' . $s->site . '/storage/licenta/licenta.json (din CRM; fără ea site-ul ar merge doar cu nucleul, fără magazin)');
    } else {
        Iesire::ok('licența e pusă: ' . implode(', ', array_map('basename', $licente)) . ' (o verifică diagnostic.php la pregătire)');
    }
    $cheie = $a->o->text('cheie-licenta') ?? ($a->envVechi['LICENTA_CHEIE_PUBLICA'] ?? '') ?: (Env::citeste($s->site . '/.env')['LICENTA_CHEIE_PUBLICA'] ?? '');
    if ($cheie === '') {
        $nu('lipsește cheia publică a licenței: --cheie-licenta=<base64> (cheia CRM-ului)');
    }

    // Spațiul.
    $cod = Fisiere::marime($s->docroot, ['*/public/uploads']);
    $nevoie = (int) ($octeti * 2 + $cod + 50 * 1024 * 1024);
    $liber = (float) @disk_free_space($s->acasa);
    if ($liber > 0 && $liber < $nevoie) {
        $nu('spațiu liber ' . Fisiere::om($liber) . ', trebuie cel puțin ' . Fisiere::om($nevoie) . ' (două copii ale bazei, copia codului, assets/)');
    } else {
        Iesire::ok('spațiu liber ' . Fisiere::om($liber) . ' (trebuie ~' . Fisiere::om($nevoie) . '; în hPanel → Disk usage vezi și limita contului)');
    }

    // Site-ul vechi, de pe web, și sonda: PHP-ul site-ului (versiunea, sodium) și dacă poate citi versiunea comună.
    $r = Http::cere($a->url . '/');
    if ($r['cod'] !== 200) {
        $nu('site-ul de acum nu răspunde 200 la ' . $a->url . '/ (HTTP ' . $r['cod'] . ($r['eroare'] !== '' ? ', ' . $r['eroare'] : '') . ')');
    } else {
        Iesire::ok('site-ul de acum răspunde la ' . $a->url . '/ (200, ' . round($r['timp'], 2) . ' s)');
    }
    if ($a->o->are('fara-sonda-web')) {
        Iesire::atentie('fără sonda web: sodium-ul site-ului și PHP-ul lui le-ai verificat în hPanel (PHP Configuration)');
    } else {
        $sonda = sondaWeb($a);
        if ($sonda === null) {
            $nu('sonda PHP-ului web nu răspunde (' . $a->url . '/_andaxi_sonda_….php); verifică în hPanel sau rulează cu --fara-sonda-web');
        } else {
            $citibil = (bool) ($sonda['cod_citibil'] ?? false);
            $a->stare->date['release_local'] = !$citibil;
            $bun = version_compare((string) $sonda['php'], $phpMin, '>=') && ($sonda['sodium'] ?? false) && ($sonda['pdo_mysql'] ?? false);
            ($bun ? [Iesire::class, 'ok'] : $nu)('PHP-ul site-ului (web): ' . $sonda['php'] . (($sonda['sodium'] ?? false) ? ', sodium da' : ', SODIUM NU (hPanel → PHP Configuration → PHP extensions → sodium)')
                . (($sonda['pdo_mysql'] ?? false) ? '' : ', PDO MySQL NU'));
            Iesire::info($citibil ? 'site-ul poate citi versiunea comună (' . $s->releases . ')'
                : 'site-ul NU poate citi în afara dosarului lui (open_basedir): versiunea se pune și în ' . $s->site . '/releases/ (legături, fără spațiu în plus)');
            if (($sonda['site_citibil'] ?? null) === false) {
                $nu('PHP-ul site-ului nu poate citi ' . $s->site . ' (open_basedir sau drepturi): acolo stau .env-ul, RELEASE și storage/ ale codului nou');
            } elseif (($sonda['site_citibil'] ?? null) === true) {
                Iesire::ok('PHP-ul site-ului poate citi ' . $s->site . ' (.env, RELEASE, storage/)');
            }
        }
    }

    // Cronurile vechi: fiecare rând care rulează codul vechi trebuie să aibă un rând nou; ce nu se poate rescrie singur se
    // verifică de mână (--cron-de-mana, după ce l-ai rescris tu în hPanel).
    $linii = cronuriVechi($a);
    if ($linii === null) {
        Iesire::atentie('cronurile vechi nu le văd (crontab -l nu merge): dă --cron-vechi=<fișier> cu rândurile din hPanel');
    } else {
        $deVerificat = array_values(array_filter(array_map(static fn (string $l): array => clasificaCron($a, $l), $linii),
            static fn (array $c): bool => $c['fel'] === 'verifica'));
        Iesire::info('cronuri vechi ale site-ului: ' . count($linii) . ($deVerificat === [] ? '' : ', din care ' . count($deVerificat) . ' DE VERIFICAT'));
        foreach ($deVerificat as $c) {
            ($a->o->are('cron-de-mana') ? [Iesire::class, 'atentie'] : $nu)('cron DE VERIFICAT (nu-l pot rescrie singur: ' . $c['motiv'] . '): ' . $c['linie']
                . ($a->o->are('cron-de-mana') ? ' (--cron-de-mana: îl rescrii tu în hPanel)' : '; îl rescrii tu în hPanel și rulezi cu --cron-de-mana'));
        }
    }

    if ($probleme !== []) {
        throw new Oprire(count($probleme) . ' verificări au ieșit NU (mai sus); nu schimb nimic', 1);
    }
    Iesire::ok('toate verificările au trecut');
    if ($a->aplica) {
        $a->stare->salveaza();
    }
}

/** @return array<string, mixed>|null */
function sondaWeb(Adoptare $a): ?array
{
    $nume = '_andaxi_sonda_' . bin2hex(random_bytes(8)) . '.php';
    $fisier = $a->dosarSonda() . '/' . $nume;
    $tinta = $a->cod . '/public/index.php';
    $site = $a->s->site;
    $cod = '<?php header("Cache-Control: no-store"); header("Content-Type: application/json");' . "\n"
        . 'echo json_encode(["php" => PHP_VERSION, "sodium" => extension_loaded("sodium") && function_exists("sodium_crypto_sign_verify_detached"), '
        . '"pdo_mysql" => extension_loaded("pdo_mysql"), "mbstring" => extension_loaded("mbstring"), "curl" => extension_loaded("curl"), '
        . '"open_basedir" => (string) ini_get("open_basedir"), "cod_citibil" => @is_file(' . var_export($tinta, true) . '), '
        . '"site_citibil" => ' . (is_dir($site) ? '@is_dir(' . var_export($site, true) . ') && @is_readable(' . var_export($site, true) . ')' : 'null') . ']);' . "\n";
    // Sonda nu are voie să rămână în docroot: o șterg la ieșire (și la Ctrl+C sau un SSH căzut), iar la pornire scriptul
    // șterge orice sondă rămasă de la o rulare omorâtă (stergeSonde).
    register_shutdown_function(static function () use ($fisier): void {
        @unlink($fisier);
    });
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $semnal) {
            pcntl_signal($semnal, static function () use ($fisier): void {
                @unlink($fisier);
                exit(130);
            });
        }
    }
    if (@file_put_contents($fisier, $cod) === false) {
        return null;
    }
    @chmod($fisier, 0644);
    try {
        $r = Http::cere($a->url . '/' . $nume);
    } finally {
        @unlink($fisier);
        if (function_exists('pcntl_signal')) {
            foreach ([SIGINT, SIGTERM, SIGHUP] as $semnal) {
                pcntl_signal($semnal, SIG_DFL);
            }
        }
    }
    $d = $r['cod'] === 200 ? json_decode($r['corp'], true) : null;
    return is_array($d) && isset($d['php']) ? $d : null;
}

/** Șterge sondele web rămase în docroot de la o rulare omorâtă (_andaxi_sonda_<16 hex>.php). */
function stergeSonde(Adoptare $a): void
{
    foreach ([$a->s->docroot, $a->s->docroot . '/public', $a->s->vechi, $a->s->vechi . '/public'] as $d) {
        foreach (glob($d . '/_andaxi_sonda_*.php') ?: [] as $f) {
            if (preg_match('/^_andaxi_sonda_[0-9a-f]{16}\.php$/', basename($f)) === 1 && @unlink($f)) {
                Iesire::atentie('am șters o sondă rămasă de la o rulare oprită: ' . $f);
            }
        }
    }
}

/**
 * Căile sub care cronurile pot pomeni docroot-ul site-ului: ~/domains/<d>/public_html și ~/public_html, când e o
 * legătură spre el (pe Hostinger, la domeniul principal al contului). @return list<string>
 */
function aliasuri(Adoptare $a): array
{
    $rez = [$a->s->docroot];
    $legatura = $a->s->acasa . '/public_html';
    if (is_link($legatura) && preg_match('#(^|/)domains/' . preg_quote($a->s->domeniu, '#') . '/public_html/?$#', (string) readlink($legatura)) === 1) {
        $rez[] = $legatura;
    }
    return $rez;
}

/** Numele scripturilor din codul vechi și din versiune (scripts/*.php), după care se recunoaște un cron al site-ului. @return list<string> */
function scripturiCunoscute(Adoptare $a): array
{
    $rez = [];
    foreach ([$a->s->docroot, $a->s->vechi, $a->cod] as $d) {
        foreach (glob($d . '/scripts/*.php') ?: [] as $f) {
            $rez[] = basename($f);
        }
    }
    return array_values(array_unique($rez));
}

/** @return list<string>|null rândurile de cron ale site-ului, sau null dacă nu se pot citi */
function cronuriVechi(Adoptare $a): ?array
{
    $fisier = $a->o->text('cron-vechi');
    if ($fisier !== null) {
        if (!is_file($fisier)) {
            throw new Oprire('--cron-vechi=' . $fisier . ': fișierul nu există', 2);
        }
        $text = (string) file_get_contents($fisier);
    } else {
        $r = Comanda::ruleaza(['crontab', '-l']);
        if ($r['cod'] !== 0) {
            return null;
        }
        $text = $r['iesire'];
    }
    $aliasuri = aliasuri($a);
    $scripturi = scripturiCunoscute($a);
    $rez = [];
    foreach (explode("\n", $text) as $l) {
        $l = trim($l);
        if ($l === '' || str_starts_with($l, '#') || preg_match('/^[A-Z_]+=/', $l) === 1) {
            continue;
        }
        $alSau = str_contains($l, 'domains/' . $a->s->domeniu . '/') || str_contains($l, $a->s->domeniu);
        foreach ($aliasuri as $al) {
            $alSau = $alSau || preg_match('#' . preg_quote($al, '#') . '(/|\s|$)#', $l) === 1;
        }
        // Un script cunoscut, fără dosarul vreunui alt site: poate fi al acestuia (rulat cu „cd” sau o cale relativă).
        if (!$alSau && !str_contains($l, 'domains/')) {
            foreach ($scripturi as $sc) {
                $alSau = $alSau || str_contains($l, $sc);
            }
        }
        if ($alSau) {
            $rez[] = $l;
        }
    }
    return $rez;
}

/**
 * Un rând de cron vechi: „nou” (rulează un script din scripts/ al codului vechi, care există în codul comun: rândul nou
 * are același program, aceleași opțiuni PHP și aceleași argumente), „sterge” (scriptul nu mai există în codul comun),
 * „ramane” (nu rulează codul vechi: un curl spre /health) sau „verifica” (pomenește public_html, dosarul de acasă sau un
 * script cunoscut, dar nu-l pot rescrie sigur).
 *
 * @return array{fel: string, linie: string, program: string, nou: string, motiv: string}
 */
function clasificaCron(Adoptare $a, string $linie): array
{
    $rez = ['fel' => 'verifica', 'linie' => $linie, 'program' => '', 'nou' => '', 'motiv' => ''];
    $comanda = $linie;
    $camp = '(\*|[0-9A-Za-z*\/,\-]+)';
    if (preg_match('/^(@\w+)\s+(.*)$/', $linie, $m) === 1 || preg_match('/^((?:' . $camp . '\s+){4}' . $camp . ')\s+(.*)$/', $linie, $m) === 1) {
        $rez['program'] = $m[1];
        $comanda = (string) end($m);
    }
    $pomeneste = str_contains($comanda, 'public_html') || str_contains($comanda, $a->s->acasa);
    foreach (scripturiCunoscute($a) as $sc) {
        $pomeneste = $pomeneste || str_contains($comanda, $sc);
    }
    if (preg_match('/["\'`$()|]/', (string) preg_replace('/\s[12]?>\S+|\s[12]?>&[12]|\s[12]?>>\s*\S+|\s[12]?>\s*\S+/', '', $comanda)) === 1) {
        $rez['motiv'] = 'ghilimele, variabile sau o conductă';
        return $pomeneste ? $rez : ['fel' => 'ramane'] + $rez;
    }
    $t = preg_split('/\s+/', trim($comanda)) ?: [];
    $dosar = null;
    if (($t[0] ?? '') === 'cd' && isset($t[1])) {
        $dosar = rtrim($t[1], ';');
        $t = array_slice($t, str_ends_with($t[1], ';') ? 2 : (($t[2] ?? '') === '&&' || ($t[2] ?? '') === ';' ? 3 : 2));
    }
    $php = $t[0] ?? '';
    if (preg_match('#(^|/)(php[0-9.]*|lsphp[0-9]*|php-cli)$#', $php) !== 1) {
        $rez['motiv'] = 'nu pornește PHP';
        return $pomeneste ? $rez : ['fel' => 'ramane'] + $rez;
    }
    $optiuni = [];
    $script = null;
    $argumente = [];
    for ($i = 1; $i < count($t); $i++) {
        if ($script !== null) {
            $argumente[] = $t[$i];
        } elseif ($t[$i] === '-d' && isset($t[$i + 1])) {
            $optiuni[] = '-d ' . $t[++$i];
        } elseif ($t[$i] === '-f') {
            continue;
        } elseif (str_starts_with($t[$i], '-')) {
            $optiuni[] = $t[$i];
        } else {
            $script = $t[$i];
        }
    }
    if ($script === null || !str_ends_with($script, '.php')) {
        $rez['motiv'] = 'nu găsesc scriptul PHP';
        return $rez;
    }
    $relativ = null;
    if (str_starts_with($script, '/')) {
        foreach (aliasuri($a) as $al) {
            if (str_starts_with($script, $al . '/')) {
                $relativ = substr($script, strlen($al) + 1);
            }
        }
    } elseif ($dosar !== null && in_array(rtrim($dosar, '/'), aliasuri($a), true)) {
        $relativ = (string) preg_replace('#^\./#', '', $script);
    }
    if ($relativ === null) {
        $rez['motiv'] = 'scriptul nu e sub ' . $a->s->docroot . ' (sau e relativ, fără „cd” în el)';
        return $pomeneste ? $rez : ['fel' => 'ramane'] + $rez;
    }
    if (!str_starts_with($relativ, 'scripts/') || str_contains($relativ, '..')) {
        $rez['motiv'] = $relativ . ' nu e în scripts/';
        return $rez;
    }
    if (!is_file($a->cod . '/' . $relativ)) {
        return ['fel' => 'sterge', 'motiv' => $relativ . ' nu mai există în codul comun'] + $rez;
    }
    $optiuni = array_values(array_filter($optiuni, static fn (string $x): bool => !str_contains($x, 'extension=sodium')));
    $phpCron = $a->o->text('php-cron') ?? '/opt/alt/php83/usr/bin/php -d extension=sodium.so';
    $rez['fel'] = 'nou';
    $rez['nou'] = trim($phpCron . ' ' . implode(' ', $optiuni) . ' ' . $a->s->acasa . '/platforma/bin/platforma --site=' . $a->s->site
        . ' ' . $relativ . ($argumente !== [] ? ' ' . implode(' ', $argumente) : ''));
    $rez['nou'] = (string) preg_replace('/\s+/', ' ', $rez['nou']);
    return $rez;
}

/** --plan: verificările de mai sus, apoi planul migrărilor pe un dosar de site temporar și lista fazelor. */
function planul(Adoptare $a): void
{
    $s = $a->s;
    Iesire::titlu('Planul migrărilor (pe baza site-ului, fără nicio schimbare)');
    $temp = $s->acasa . '/platforma/plan-' . $s->domeniu . '-' . getmypid() . '/site';
    try {
        Fisiere::dosar($temp, 0700);
        $a->scriePachet($temp . '/pachet');
        Fisiere::scrie($temp . '/.env', $a->envNou($temp . '/pachet'), 0600);
        Fisiere::scrie($temp . '/RELEASE', $a->versiune . "\n");
        $r = platforma($temp, $a->cod, $a->versiune, ['scripts/migreaza.php', '--plan', '--site=' . $a->slug], true);
        Iesire::info('migreaza.php --plan: codul ' . $r['cod'] . ' (' . sensMigrari($r['cod']) . ')');
        if (!in_array($r['cod'], [0, 10, 11], true)) {
            Iesire::nu('cu acest cod, faza „migrari” s-ar opri; repară înainte de --aplica');
        } elseif ($r['cod'] === 11 && !$a->o->are('permite-blocante')) {
            Iesire::nu('migrările blocante cer --permite-blocante (rulează în mentenanță oricum)');
        }
    } finally {
        Fisiere::sterge(dirname($temp), [$s->acasa . '/platforma']);
    }
    Iesire::titlu('Ce ar face --aplica');
    $copii = $s->lucru . '/copii/<data>';
    foreach ([
        'pregatire   ' . $s->site . '/ (.env din cel vechi + SITE_SLUG, SITE_DATE_DOSAR, LICENTA_*; RELEASE=' . $a->versiune . '; pachet/; storage/), '
            . $s->nou . '/ (stub-ul, assets/, tema/); diagnostic --pachet, --licenta; migreaza --plan',
        'copie       ' . $copii . '/cod-vechi.tar.gz (fără public/uploads) și baza-inainte.sql.gz (~' . Fisiere::om((int) $a->stare->ia('baza_octeti', 0)) . ' de date)',
        'mentenanta  ' . $s->site . '/MENTENANTA; ' . $s->docroot . ' → ' . $s->vechi . '; ' . $s->nou . ' → ' . $s->docroot
            . '; public/uploads/ mutat în public_html/uploads/; copia bazei de revenire',
        'migrari     migreaza.php --aplica --site=' . $a->slug . ' (reluat la codul 13)',
        'diagnostic  diagnostic.php --pachet și --licenta',
        'verificare  ping, /health, /, un produs, /admin/login, /cos, assets/, o poză',
        'deschidere  MENTENANTA ștearsă; apoi rândurile de cron pentru hPanel',
    ] as $rand) {
        Iesire::info($rand);
    }
    cron($a);
    Iesire::scrie('');
    Iesire::scrie('PLAN: site-ul n-a fost schimbat (sonda web a stat o clipă în docroot și s-a șters). Pentru a-l adopta: aceeași comandă cu --aplica.');
}

function pregatire(Adoptare $a): void
{
    $s = $a->s;
    if ($a->envVechi === []) {
        $a->envVechi = Env::citeste($s->docroot . '/.env');
    }
    Fisiere::dosar($s->site, 0750);
    Fisiere::dosar($s->lucru, 0700);
    foreach (Fisiere::lista($a->cod . '/storage') as $rel) {
        Fisiere::dosar($s->site . '/storage/' . dirname($rel), 0770);
    }
    Fisiere::dosar($s->site . '/storage/logs', 0770);
    Iesire::ok('dosarul site-ului: ' . $s->site . ' (storage/ cu dosarele versiunii)');

    // Pachetul (cu privat/ copiat lângă el).
    $nou = $s->site . '/pachet.nou';
    Fisiere::sterge($nou, [$s->site]);
    $a->scriePachet($nou);
    Fisiere::sterge($s->site . '/pachet', [$s->site]);
    Fisiere::muta($nou, $s->site . '/pachet');
    Iesire::ok('pachetul: ' . $s->site . '/pachet' . (is_dir($s->site . '/pachet/privat') ? ' (cu privat/)' : ''));

    // .env, RELEASE, versiunea locală (open_basedir).
    if (!is_file($s->site . '/.env')) {
        Fisiere::scrie($s->site . '/.env', $a->envNou($s->site . '/pachet'), 0600);
        Iesire::ok('.env: cel vechi, plus SITE_SLUG=' . $a->slug . ', SITE_DATE_DOSAR, LICENTA_SITE' . ($a->o->text('cheie-licenta') !== null ? ', LICENTA_CHEIE_PUBLICA' : ''));
    } else {
        // Un .env de la o rulare de dinainte (sau scris de proprietar): cheile deploy-ului puse la zi, restul lăsat.
        Fisiere::scrie($s->site . '/.env', Env::cuChei((string) file_get_contents($s->site . '/.env'), $a->cheiEnv($s->site . '/pachet'),
            'Etapa 2 (deploy/adopta.sh, ' . date('Y-m-d H:i') . '): pachetul site-ului și licența'), 0600);
        Iesire::ok('.env există deja (de la o rulare de dinainte): SITE_SLUG, SITE_DATE_DOSAR și licența puse la zi, restul lăsat');
    }
    Fisiere::scrie($s->site . '/RELEASE', $a->versiune . "\n", 0644);
    golesteCacheCod($s);
    if ($a->stare->ia('release_local', false)) {
        legaVersiunea($s, $a->versiune);
    }
    $cod = $a->codSite();

    // Cheia linkurilor din newslettere (MIGRARI.md, pasul 6): cea pe care o folosea site-ul.
    if ((Env::citeste($s->site . '/.env')['NEWSLETTER_CHEIE_SEMNARE'] ?? '') === '') {
        $r = platforma($s->site, $cod, $a->versiune, ['scripts/diagnostic.php', '--cheie-newsletter']);
        if ($r['cod'] === 0 && preg_match('/^NEWSLETTER_CHEIE_SEMNARE=([0-9a-f]{64})$/m', $r['iesire'], $m) === 1) {
            Fisiere::scrie($s->site . '/.env', Env::cuChei((string) file_get_contents($s->site . '/.env'), ['NEWSLETTER_CHEIE_SEMNARE' => $m[1]],
                'Cheia linkurilor din newslettere trimise până acum (diagnostic.php --cheie-newsletter)'), 0600);
            Iesire::ok('NEWSLETTER_CHEIE_SEMNARE scrisă în .env (linkurile newsletterelor trimise merg mai departe)');
        } else {
            Iesire::atentie('cheia newsletterelor nu s-a putut afla (' . trim($r['erori'] . $r['iesire']) . '); linkurile vechi merg cât APP_URL și baza rămân aceleași');
        }
    }

    // public_html.nou: stub-ul, assets/, tema/, fișierele publice ale pachetului.
    if (is_dir($s->nou)) {
        if (!is_file($s->nou . '/.andaxi-nou')) {
            throw new Oprire($s->nou . ' există și nu e făcut de deploy; uită-te în el și mută-l');
        }
        Fisiere::sterge($s->nou, [$s->dosar]);
    }
    Fisiere::dosar($s->nou, 0755);
    file_put_contents($s->nou . '/.andaxi-nou', $a->versiune . "\n");
    foreach (Docroot::radacina($cod, $s->site . '/pachet') as $rel => $sursa) {
        Fisiere::dosar(dirname($s->nou . '/' . $rel), 0755);
        Fisiere::copiaza($sursa, $s->nou . '/' . $rel);
        @chmod($s->nou . '/' . $rel, 0644);
    }
    Docroot::assets($cod, $s->site . '/pachet', $s->nou . '/assets');
    $cuTema = Docroot::tema($s->site . '/pachet', $s->nou . '/tema');
    Iesire::ok('docroot-ul nou: ' . $s->nou . ' (stub-ul, assets/' . ($cuTema ? ', tema/' : '') . ')');

    // Verificările codului nou, pe dosarul nou (încă nevăzut de vizitatori).
    $r = platforma($s->site, $cod, $a->versiune, ['scripts/diagnostic.php', '--pachet'], true, $s->nou);
    if ($r['cod'] !== 0) {
        throw new Oprire('diagnostic.php --pachet nu spune „ok” (mai sus ce lipsește sau diferă)');
    }
    Iesire::ok('diagnostic.php --pachet: ok');
    $r = platforma($s->site, $cod, $a->versiune, ['scripts/diagnostic.php', '--licenta'], true, $s->nou);
    if ($r['cod'] !== 0) {
        throw new Oprire('licența nu se verifică (diagnostic.php --licenta, mai sus): fără ea site-ul ar merge doar cu nucleul');
    }
    Iesire::ok('diagnostic.php --licenta: licența se verifică');
    planMigrari($a, $cod);
}

function planMigrari(Adoptare $a, string $cod): int
{
    $r = platforma($a->s->site, $cod, $a->versiune, ['scripts/migreaza.php', '--plan', '--site=' . $a->slug], true);
    Iesire::info('migreaza.php --plan: codul ' . $r['cod'] . ' (' . sensMigrari($r['cod']) . ')');
    if ($r['cod'] === 11 && !$a->o->are('permite-blocante')) {
        throw new Oprire('migrările blocante cer --permite-blocante (în faza „migrari” site-ul e oricum în mentenanță)');
    }
    if (!in_array($r['cod'], [0, 10, 11], true)) {
        throw new Oprire('migreaza.php --plan: ' . sensMigrari($r['cod']));
    }
    return $r['cod'];
}

function copie(Adoptare $a): void
{
    $s = $a->s;
    $dosar = $s->lucru . '/copii/' . date('Ymd-His');
    Fisiere::dosar($dosar, 0700);
    $inceput = microtime(true);
    $r = Comanda::ruleaza(['tar', '-czf', $dosar . '/cod-vechi.tar.gz', '-C', $s->dosar, '--exclude=public_html/public/uploads', 'public_html']);
    if ($r['cod'] !== 0) {
        throw new Oprire('copia codului vechi n-a mers: ' . trim($r['erori']));
    }
    @chmod($dosar . '/cod-vechi.tar.gz', 0600);
    Iesire::ok('codul vechi: ' . $dosar . '/cod-vechi.tar.gz (' . Fisiere::om((int) filesize($dosar . '/cod-vechi.tar.gz')) . ', '
        . round(microtime(true) - $inceput, 1) . ' s; fără public/uploads, care rămân pe loc)');
    $baza = new Baza(Env::citeste($s->docroot . '/.env'));
    $secunde = $baza->copie($dosar . '/baza-inainte.sql.gz', false);
    Iesire::ok('baza (cu site-ul încă pornit): ' . $dosar . '/baza-inainte.sql.gz (' . Fisiere::om((int) filesize($dosar . '/baza-inainte.sql.gz'))
        . ', ' . round($secunde, 1) . ' s; copia de revenire se face din nou în mentenanță)');
    $a->stare->pune('copii', $dosar);
}

function mentenanta(Adoptare $a): void
{
    $s = $a->s;
    $comutat = (bool) $a->stare->ia('comutat', false);
    if (!$comutat && !in_array('comutare', (array) $a->stare->ia('redenumiri_facute', []), true)) {
        $git = Comanda::ruleaza(['git', '-C', $s->docroot, 'rev-parse', 'HEAD']);
        if (trim($git['iesire']) !== (string) $a->stare->ia('commit_vechi_complet', '')) {
            throw new Oprire('codul vechi s-a schimbat de la verificări (alt commit); rulează din nou de la început (--reia=verificari)');
        }
    }
    $jeton = (string) $a->stare->ia('jeton', '') ?: bin2hex(random_bytes(16));
    $a->stare->pune('jeton', $jeton);
    // Strictă: din clipa comutării nici plățile, nici ERP-ul, nici cronurile nu scriu în bază până după migrări.
    Mentenanta::pune($s, $jeton, true);
    // Intenția se scrie înainte de prima redenumire (Redenumiri): o rulare oprită între ele e terminată la pornire.
    Redenumiri::fa($a->stare, 'comutare', [[$s->docroot, $s->vechi], [$s->nou, $s->docroot]]);
    $a->stare->pune('comutat', true);
    @unlink($s->docroot . '/.andaxi-nou');
    if (!$comutat) {
        Iesire::ok('comutat: codul vechi în ' . $s->vechi . ', stub-ul în ' . $s->docroot . ' (vizitatorii văd acum „Revenim în câteva minute”)');
    }
    $amprenta = $a->stare->ia('copii') . '/cod-vechi.amprenta';
    if (!is_file($amprenta)) {
        Fisiere::scrie($amprenta, Fisiere::amprenta($s->vechi, ['public/uploads', 'uploads']), 0600);
        Iesire::ok('amprenta codului vechi, cum era la comutare: ' . $amprenta);
    }

    // Pozele: mutate (nu copiate: pot fi mulți GB), înapoi la revenire. Unde erau se scrie înainte de mutare.
    if ($a->stare->ia('uploads') === null) {
        $rel = is_dir($s->vechi . '/public/uploads') ? 'public/uploads' : (is_dir($s->vechi . '/uploads') ? 'uploads' : '');
        $a->stare->pune('uploads', $rel);
    }
    $rel = (string) $a->stare->ia('uploads');
    if ($rel !== '') {
        Redenumiri::fa($a->stare, 'uploads', [[$s->vechi . '/' . $rel, $s->docroot . '/uploads']]);
        Iesire::ok('pozele: ' . $s->vechi . '/' . $rel . ' → ' . $s->docroot . '/uploads');
    } elseif (!is_dir($s->docroot . '/uploads')) {
        Fisiere::dosar($s->docroot . '/uploads', 0755);
        Iesire::info('codul vechi n-avea uploads/; am făcut unul gol');
    }

    // Fișierele site-ului din public/ (ignorate de git: sitemap-uri, assets/.htaccess, fișiere de verificare a domeniului),
    // plus .user.ini, php.ini și .well-known/ din rădăcină: copiate în docroot, unde nu există deja.
    $preluate = [];
    $git = static fn (string ...$x): array => Comanda::ruleaza(array_merge(['git', '-C', $s->vechi], $x));
    $liste = [$git('ls-files', '-z', '--others', '--ignored', '--exclude-standard', '--', 'public/', '.user.ini', 'php.ini', '.well-known/')['iesire']];
    if ($a->o->are('accepta-fisiere-noi')) {
        $liste[] = $git('ls-files', '-z', '--others', '--exclude-standard', '--', 'public/', '.user.ini', 'php.ini', '.well-known/')['iesire'];
    }
    foreach (array_unique(array_filter(explode("\0", implode("\0", $liste)))) as $rel) {
        if (str_starts_with($rel, 'public/uploads/') || basename($rel) === 'error_log') {
            continue;
        }
        $tinta = str_starts_with($rel, 'public/') ? substr($rel, 7) : $rel;
        if ($tinta === '' || file_exists($s->docroot . '/' . $tinta) || !is_file($s->vechi . '/' . $rel)) {
            continue;
        }
        Fisiere::dosar(dirname($s->docroot . '/' . $tinta), 0755);
        Fisiere::copiaza($s->vechi . '/' . $rel, $s->docroot . '/' . $tinta);
        $preluate[] = $tinta;
    }
    $a->stare->pune('preluate', $preluate);
    Iesire::ok('fișierele site-ului aduse în docroot: ' . ($preluate === [] ? 'niciunul' : count($preluate) . ' (' . implode(', ', array_slice($preluate, 0, 6))
        . (count($preluate) > 6 ? ', …' : '') . ')'));

    // Copia bazei de revenire: cu scrierile oprite (după ce s-au terminat și cererile sau cronurile pornite înainte).
    $baza = new Baza(Env::citeste($s->site . '/.env'));
    // Dacă liniștea nu vine, nu las mentenanța strictă doar din cauza așteptării: cea ușoară (plățile trec) până la reluare.
    $baza->cereLiniste($a->o->are('forteaza-liniste'), static fn () => Mentenanta::pune($s, $jeton, false));
    $fisier = $a->stare->ia('copii') . '/baza.sql.gz';
    $secunde = $baza->copie($fisier);
    $a->stare->pune('copie_baza', $fisier);
    Iesire::ok('copia bazei de revenire: ' . $fisier . ' (' . Fisiere::om((int) filesize($fisier)) . ', ' . round($secunde, 1) . ' s)');
}

function migrari(Adoptare $a): void
{
    $cod = $a->codSite();
    $jeton = (string) $a->stare->ia('jeton');
    $modInainte = Mentenanta::mod($a->s);
    Mentenanta::pune($a->s, $jeton, true);
    $baza = new Baza(Env::citeste($a->s->site . '/.env'));
    $laOprire = static fn () => Mentenanta::pune($a->s, $jeton, $modInainte === 'strict');
    $dupaFisier = $a->stare->ia('copii') . '/dupa-migrari.json';
    $dupa = json_decode((string) @file_get_contents($dupaFisier), true);
    if (is_array($dupa)) {
        // O reluare după migrări terminate (--reia=migrari): baza trebuie să fie cea de atunci. Altfel s-a scris în ea între
        // timp (o plată, cu mentenanța ușoară), iar amprenta de după migrări, cu care revino.sh vede datele noi, nu se rescrie.
        $schimbate = $baza->tabeleSchimbate((array) ($dupa['amprenta'] ?? []));
        if ($schimbate !== []) {
            $laOprire();
            throw new Oprire('de la sfârșitul migrărilor (' . (string) ($dupa['facuta'] ?? '?') . ') s-a scris în ' . implode(', ', array_slice($schimbate, 0, 10))
                . '; nu rescriu amprenta de după migrări (cu ea revino.sh vede datele noi). Migrările sunt deja făcute: continui cu aceeași comandă fără --reia');
        }
    } elseif (!$a->stare->ia('migrari_pornite', false)) {
        // Prima intrare în migrări: baza trebuie să fie exact cea din copia de revenire (nimic scris de la copie).
        $copie = json_decode((string) @file_get_contents((string) $a->stare->ia('copie_baza') . '.json'), true);
        $schimbate = is_array($copie) ? $baza->tabeleSchimbate((array) ($copie['amprenta'] ?? [])) : ['(copia de revenire lipsește)'];
        if ($schimbate !== []) {
            $laOprire();
            throw new Oprire('de la copia de revenire s-a scris în bază (' . implode(', ', array_slice($schimbate, 0, 10)) . '): copia n-ar mai fi cea de dinaintea '
                . 'migrărilor, deci nu migrez. Revii (deploy/revino.sh --aplica: baza nu fusese atinsă de migrări) și iei adoptarea de la capăt');
        }
    }
    $a->stare->pune('migrari_pornite', true);
    $plan = planMigrari($a, $cod);
    if ($plan === 0) {
        Iesire::ok('nicio migrare de aplicat');
    }
    for ($i = 1; $plan !== 0 && $i <= 30; $i++) {
        $inceput = microtime(true);
        $r = platforma($a->s->site, $cod, $a->versiune, array_merge(['scripts/migreaza.php', '--aplica', '--site=' . $a->slug, '--buget=300'],
            $a->o->are('permite-blocante') ? ['--permite-blocante'] : []), true);
        Iesire::info('migreaza.php --aplica: codul ' . $r['cod'] . ' (' . sensMigrari($r['cod']) . '), ' . round(microtime(true) - $inceput, 1) . ' s');
        if ($r['cod'] === 0) {
            Iesire::ok('migrările sunt aplicate și verificate');
            $plan = 0;
            break;
        }
        if ($r['cod'] !== 13) {
            throw new Oprire('migreaza.php --aplica: ' . sensMigrari($r['cod']));
        }
    }
    if ($plan !== 0) {
        throw new Oprire('migrările n-au terminat după 30 de reluări');
    }
    // Amprenta bazei după migrări, încă cu scrierile oprite, o singură dată: revino.sh o compară cu baza de atunci, ca orice
    // scriere de după (o plată confirmată, o comandă, o schimbare din admin) să fie văzută înainte să fie pierdută.
    if (!is_array($dupa)) {
        incalzeste($a);
        Fisiere::scrie($dupaFisier, (string) json_encode(['facuta' => date('c'), 'baza' => $baza->nume(), 'amprenta' => $baza->amprenta(),
            'ultimele_iduri' => $baza->ultimeleIduri()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        Iesire::ok('amprenta bazei după migrări: ' . $dupaFisier);
    } else {
        Iesire::ok('amprenta bazei după migrări e cea de atunci (' . (string) ($dupa['facuta'] ?? '?') . '); n-o rescriu');
    }
    // De aici, mentenanța ușoară: plățile și ERP-ul trec din nou (codul nou e pe baza migrată), vizitatorii încă nu.
    Mentenanta::pune($a->s, $jeton, false);
}

/**
 * Primele cereri ale codului nou, cu jetonul, încă în mentenanța strictă: pașii de activare ai pluginurilor (pagina
 * /acorduri-gdpr, pragul ERP, rândurile lor din jurnal), regulile de redirecționare ale pachetului și ce mai scrie codul o
 * singură dată la prima pagină se fac acum, deci intră în amprenta de după migrări. Altfel testul de fum le-ar scrie
 * după ea, iar revino.sh le-ar vedea ca date noi.
 */
function incalzeste(Adoptare $a): void
{
    $jeton = (string) $a->stare->ia('jeton');
    $antete = ['X-Andaxi-Deploy' => $jeton, 'Cookie' => 'andaxi_deploy=' . $jeton, 'Cache-Control' => 'no-cache'];
    $coduri = [];
    foreach (['/', '/magazin', '/cos', '/admin/login', '/health'] as $cale) {
        $coduri[] = $cale . ' ' . Http::cere($a->url . $cale, $antete)['cod'];
    }
    Iesire::info('primele cereri ale codului nou (pașii de activare, redirecționările pachetului): ' . implode(', ', $coduri));
}

/** --reia=migrari după migrări terminate: refuzat dacă s-a scris în bază de atunci (amprenta de după migrări rămâne). */
function verificaReluareaMigrarilor(Adoptare $a): void
{
    $dupa = json_decode((string) @file_get_contents($a->stare->ia('copii') . '/dupa-migrari.json'), true);
    if (!is_array($dupa)) {
        return;
    }
    $schimbate = (new Baza(Env::citeste($a->s->site . '/.env')))->tabeleSchimbate((array) ($dupa['amprenta'] ?? []));
    if ($schimbate !== []) {
        throw new Oprire('--reia: de la sfârșitul migrărilor (' . (string) ($dupa['facuta'] ?? '?') . ') s-a scris în ' . implode(', ', array_slice($schimbate, 0, 10))
            . '; nu rescriu amprenta de după migrări (cu ea revino.sh vede datele noi). Migrările sunt deja făcute: aceeași comandă fără --reia continuă', 1);
    }
}

function diagnostic(Adoptare $a): void
{
    $cod = $a->codSite();
    foreach (['--pachet' => 'pachetul site-ului e cel din repo, cu tema și fișierele publice în docroot', '--licenta' => 'licența se verifică'] as $opt => $ce) {
        $r = platforma($a->s->site, $cod, $a->versiune, ['scripts/diagnostic.php', $opt], true);
        if ($r['cod'] !== 0) {
            throw new Oprire('diagnostic.php ' . $opt . ' nu trece (mai sus)');
        }
        Iesire::ok('diagnostic.php ' . $opt . ': ' . $ce);
    }
    platforma($a->s->site, $cod, $a->versiune, ['scripts/diagnostic.php']);
    Iesire::info('diagnostic.php (tot): în jurnal');
}

function verificare(Adoptare $a): void
{
    $probleme = verificaSiteul($a->s, $a->url, (string) $a->stare->ia('jeton'), $a->versiune, $a->o->text('produs'));
    if ($probleme !== []) {
        throw new Oprire(count($probleme) . ' verificări ale site-ului nou au picat: ' . implode('; ', $probleme));
    }
}

function deschidere(Adoptare $a): void
{
    $s = $a->s;
    Mentenanta::scoate($s);
    $r = Http::cere($a->url . '/');
    $p = Http::cere($a->url . '/_andaxi/ping');
    if ($r['cod'] !== 200 || str_contains($r['corp'], 'Revenim în câteva minute') || $p['cod'] !== 200) {
        Mentenanta::pune($s, (string) $a->stare->ia('jeton'), false);
        throw new Oprire('fără MENTENANTA site-ul nu răspunde ca la verificare (/ → HTTP ' . $r['cod'] . ', ping → ' . $p['cod'] . '); l-am pus la loc în mentenanță');
    }
    Iesire::ok('MENTENANTA ștearsă: ' . $a->url . '/ răspunde 200 oricui (' . round($r['timp'], 2) . ' s)');
    $a->stare->pune('jeton', null);
}

/** Rândurile noi de cron (hPanel → Advanced → Cron Jobs), din cele vechi, și cele care se șterg. */
function cron(Adoptare $a): void
{
    $s = $a->s;
    Iesire::titlu('Cronurile (hPanel → Advanced → Cron Jobs)');
    $php = $a->o->text('php-cron') ?? '/opt/alt/php83/usr/bin/php -d extension=sodium.so';
    $lansator = $s->acasa . '/platforma/bin/platforma';
    $linii = cronuriVechi($a);
    $text = "# Cronurile site-ului " . $s->domeniu . " pe codul comun (deploy/adopta.sh, " . date('Y-m-d H:i') . ")\n";
    if ($linii === null) {
        Iesire::atentie('nu văd cronurile vechi (crontab -l); dă --cron-vechi=<fișier> cu rândurile din hPanel, sau scrie-le după modelul de mai jos');
        $linii = [];
    }
    foreach ($linii as $l) {
        $c = clasificaCron($a, $l);
        if ($c['fel'] === 'nou') {
            Iesire::info('ȘTERGI  : ' . $l);
            Iesire::info('ADAUGI  : ' . ($c['program'] !== '' ? $c['program'] . '  ' : '(programul celui vechi)  ') . $c['nou']);
            $text .= ($c['program'] !== '' ? $c['program'] . ' ' : '# programul celui vechi: ') . $c['nou'] . "\n";
        } elseif ($c['fel'] === 'sterge') {
            Iesire::info('ȘTERGI  : ' . $l . '   (' . $c['motiv'] . ')');
        } elseif ($c['fel'] === 'ramane') {
            Iesire::info('RĂMÂNE : ' . $l . '   (nu rulează un script din codul vechi)');
        } else {
            Iesire::atentie('DE VERIFICAT: ' . $l . '   (' . $c['motiv'] . '; îl rescrii tu, după modelul de mai jos)');
            $text .= '# DE VERIFICAT, de rescris de mână: ' . $l . "\n";
        }
    }
    Iesire::info('modelul unui rând nou: ' . $php . ' ' . $lansator . ' --site=' . $s->site . ' scripts/<script>.php');
    if ($a->aplica) {
        Fisiere::scrie($s->lucru . '/cron-nou.txt', $text, 0600);
    }
}
