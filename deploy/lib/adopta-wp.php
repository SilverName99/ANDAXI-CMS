<?php

declare(strict_types=1);

/**
 * deploy/adopta-wp.sh: trece un site WordPress de pe găzduire pe codul comun (docs/DEPLOY.md, „Adoptarea unui site
 * WordPress”). Spre deosebire de deploy/adopta.sh (codul vechi al platformei, a cărui bază se migrează pe loc), aici site-ul
 * nou primește o bază NOUĂ (făcută de proprietar în hPanel, dată o dată cu --baza-noua), instalată de scripts/install.php, în
 * care scripts/importa-wordpress.php aduce paginile, SEO-ul, meniul, setările și pozele din WordPress, citit DOAR (baza lui
 * cu datele din wp-config.php, pozele copiate). WordPress rămâne întreg în public_html.vechi/, deci revenirea
 * (deploy/revino.sh) doar îl pune la loc.
 *
 *   deploy/adopta-wp.sh --site=<slug> --domeniu=<d> --baza-noua              o dată: datele bazei noi (din hPanel), întrebate pe ecran
 *   deploy/adopta-wp.sh --site=<slug> --domeniu=<d> --versiune=<v> --cheie-licenta=<cheia CRM> --admin-email=<adresa>
 *                                                                            planul: nu schimbă nimic
 *   deploy/adopta-wp.sh … aceleași … --aplica                                adoptarea
 *
 * Fazele lui --aplica (ținute minte în site/deploy/adopta-wp.stare; aceeași comandă continuă de la prima neterminată):
 *
 *   verificari  uneltele, PHP-ul, versiunea, WordPress (wp-config.php, baza lui, doar citită), baza nouă (goală, alta decât a
 *               WordPress, cu drepturile cerute), pachetul, licența, spațiul, site-ul răspunde, sonda web, planul importului
 *               (scripts/importa-wordpress.php --plan: fără nicio problemă NU), pe drumul capturat captura (site/capturat/, sau
 *               --captura=) și planul fișierelor statice (scripts/publica-statice.php --plan), cronurile; nimic schimbat
 *   pregatire   site/ (.env cu cheile site-ului nou, cu APP_KEY scris o dată, RELEASE, pachetul, storage/), public_html.nou/
 *               (stub-ul, assets/, tema/); pe drumul capturat fișierele statice cerute de pagini (scripts/publica-statice.php
 *               --aplica: foile, et-cache/, wp-includes/, fonturile, de pe discul WordPress, cu manifestul
 *               site/storage/import-wordpress/statice.json); diagnostic --pachet și --licenta
 *   instalare   scripts/install.php pe baza nouă (migrările, setările, primul administrator, cu o parolă nouă scrisă în
 *               site/deploy/parola-admin.txt, 0600), apoi migreaza --plan = la zi
 *   import      scripts/importa-wordpress.php --aplica (paginile, SEO, setările, redirecționările; mediile copiate în
 *               public_html.nou/wp-content/uploads/, la aceeași cale, cu manifestul sha256); fără variantele WebP ale nucleului
 *               (paginile folosesc variantele WordPress)
 *   copie       copia de siguranță a WordPress: codul (fără pozele, care rămân pe loc) și baza lui
 *   comutare    conținutul WordPress neschimbat de la import (altfel: --reia=import), MENTENANTA strictă, public_html →
 *               public_html.vechi, public_html.nou → public_html, amprenta WordPress (pentru revenire), fișierele de verificare a
 *               domeniului aduse, .htaccess-urile arborilor copiați (wp-content/, wp-includes/, capturat/: din deploy/stub/, fără
 *               execuție, cu cache-ul ca live), primele cereri, mentenanța ușoară
 *   verificare  testul de fum (pingul, /health, /, /admin/login, assets/, o poză) și adresele vechi: fiecare pagină importată,
 *               o poză la adresa ei din wp-content/uploads (200, aceiași octeți), sitemap.xml; pe drumul capturat
 *               diagnostic.php --capturat (fiecare fișier cerut de pagini, cu sha256-ul lui, în docroot) și fiecare fișier static
 *               publicat și fiecare mediu cerut de pagini, la adresa lui (200, aceiași octeți)
 *   deschidere  MENTENANTA ștearsă, site-ul deschis
 *
 * Opțiuni: --admin-email=<email> (OBLIGATORIU: primul administrator al site-ului nou; niciodată adresa de administrare din
 * WordPress, care poate fi a agenției), --url=<adresa> (implicit https://<d>), --repo=<clonă>, --pachet=<dosar>,
 * --captura=<dosar> (drumul capturat: captura site-ului live; implicit site/capturat/), --sha-captura=<sha256 al lui
 * raport-captura.json> (ancora capturii, tipărită la sfârșitul ei; fără ea, sha256-ul raportului din dosar),
 * --cron-vechi=<fișier>, --cron-de-mana, --fara-sonda-web,
 * --fara-magazin, --fara-newsletter, --fara-articole (părți ale site-ului WordPress lăsate deoparte, vezi planul importului),
 * --reia=<faza>, --inlocuieste (cu --baza-noua: alte date ale bazei noi), --acasa=<dosar> (teste).
 */

namespace Platforma\Deploy;

require __DIR__ . '/deploy.php';
require __DIR__ . '/wordpress.php';

const FAZE_WP = ['verificari', 'pregatire', 'instalare', 'import', 'copie', 'comutare', 'verificare', 'deschidere'];

final class AdoptareWp
{
    public Site $s;
    public Stare $stare;
    public string $slug;
    public string $versiune = '';
    public string $cod = '';
    public string $url;
    public bool $aplica;

    public function __construct(public Optiuni $o)
    {
        $this->slug = $o->cere('site', '<site> (numele pachetului din site-uri/, de exemplu pensiune-proba)');
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $this->slug) !== 1) {
            throw new Oprire('--site=' . $this->slug . ' nu e un nume de site', 2);
        }
        $this->s = new Site(Site::acasa($o), $o->cere('domeniu', '<domeniu> (exemplu.ro: dosarul ~/domains/<domeniu>/public_html)'));
        if (!$o->are('baza-noua')) {
            $this->versiune = $o->cere('versiune', '<versiune> (făcută cu deploy/release.sh)');
            if (preg_match(Versiune::TIPAR, $this->versiune) !== 1) {
                throw new Oprire('--versiune=' . $this->versiune . ' nu are forma 2026.11.05-e4f5a6b', 2);
            }
        }
        $this->url = rtrim($o->text('url') ?? 'https://' . $this->s->domeniu, '/');
        $this->aplica = $o->aplica();
        $this->stare = new Stare($this->s->lucru . '/adopta-wp.stare');
        $this->cod = $this->versiune !== '' ? $this->s->releases . '/' . $this->versiune : '';
    }

    public function comutat(): bool
    {
        return (bool) $this->stare->ia('comutat', false) || isset($this->stare->date['redenumiri']['comutare'])
            || in_array('comutare', (array) $this->stare->ia('redenumiri_facute', []), true);
    }

    /** Dosarul WordPress: public_html înainte de comutare, public_html.vechi după. */
    public function dosarWp(): string
    {
        return $this->comutat() ? $this->s->vechi : $this->s->docroot;
    }

    /** @return array<string, string> */
    public function optiuni(): array
    {
        return ['versiune' => $this->versiune, 'pachet' => (string) $this->o->text('pachet'), 'repo' => $this->repo(),
            'cheie-licenta' => hash('sha256', (string) $this->o->text('cheie-licenta')), 'admin-email' => (string) $this->o->text('admin-email'),
            'fara' => implode(',', $this->fara()), 'captura' => (string) $this->o->text('captura'), 'sha-captura' => (string) $this->o->text('sha-captura')];
    }

    /** @return list<string> părțile lăsate deoparte (--fara-magazin, --fara-newsletter, --fara-articole) */
    public function fara(): array
    {
        return array_values(array_filter(['magazin', 'newsletter', 'articole'], fn (string $p): bool => $this->o->are('fara-' . $p)));
    }

    public function codSite(): string
    {
        return $this->s->cod($this->versiune);
    }

    public function commitVersiune(): string
    {
        return trim((string) @file_get_contents($this->cod . '/COMMIT'));
    }

    public function repo(): string
    {
        return $this->o->text('repo') ?? $this->s->acasa . '/platforma/repo';
    }

    /** Captura site-ului live (drumul capturat): --captura=, altfel site/capturat/ (unde o scrie scripts/captureaza-wordpress.php). */
    public function captura(): string
    {
        return rtrim($this->o->text('captura') ?? $this->s->site . '/capturat', '/');
    }

    /** Pachetul site-ului e pe drumul capturat (`import-wordpress.json` → `"continut": "capturat"`)? Citit din --pachet sau din repo. */
    public function capturat(): bool
    {
        if (($dosar = $this->o->text('pachet')) !== null) {
            return WordPress::capturat($dosar);
        }
        $r = Comanda::ruleaza(['git', '-C', $this->repo(), 'show', $this->commitVersiune() . ':site-uri/' . $this->slug . '/import-wordpress.json']);
        $d = $r['cod'] === 0 ? json_decode($r['iesire'], true) : null;
        return is_array($d) && ($d['continut'] ?? null) === 'capturat';
    }

    /** Argumentele capturii pentru scripturile codului nou (importul, fișierele statice). @return list<string> */
    public function argumenteCaptura(): array
    {
        $sha = $this->o->text('sha-captura');
        return array_merge(['--captura=' . $this->captura()], $sha !== null ? ['--sha-raport=' . $sha] : []);
    }

    public function scriePachet(string $tinta): void
    {
        scriePachet($this->s, $this->slug, $this->o->text('pachet'), $this->repo(), $this->commitVersiune(), $tinta);
    }

    /** @return array{0: ?array, 1: ?array} */
    public function citestePachet(): array
    {
        return citestePachet($this->slug, $this->o->text('pachet'), $this->repo(), $this->commitVersiune());
    }

    /** @return array{env: array<string, string>, prefix: string} baza WordPress (din wp-config.php, fără să-l ruleze) */
    public function wp(): array
    {
        return WordPress::config($this->dosarWp());
    }

    /** Cheile site-ului nou în site/.env (pe lângă DB_* scrise de --baza-noua). @return array<string, string> */
    public function cheiEnv(string $dosarPachet, string $adminEmail): array
    {
        $chei = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => $this->url, 'APP_TIMEZONE' => 'Europe/Bucharest',
            'SITE_SLUG' => $this->slug, 'SITE_DATE_DOSAR' => $dosarPachet, 'LICENTA_SITE' => $this->slug];
        if ($adminEmail !== '') {
            $chei['ADMIN_DEFAULT_EMAIL'] = $adminEmail;
        }
        if (($cheie = $this->o->text('cheie-licenta')) !== null) {
            $chei['LICENTA_CHEIE_PUBLICA'] = $cheie;
        }
        return $chei;
    }

    /**
     * Adresa primului administrator (corectura 1.13): doar --admin-email (sau cea ținută minte de adoptarea începută cu ea).
     * Fără implicit: adresa de administrare din WordPress poate fi a agenției care a făcut site-ul.
     */
    public function adminEmail(): string
    {
        return WordPress::adminEmail($this->o->text('admin-email'), (string) $this->stare->ia('admin_email', ''));
    }
}

exit(porneste(static function () use ($argv): int {
    $o = Optiuni::citeste($argv, ['site=', 'domeniu=', 'versiune=', 'url=', 'repo=', 'pachet=', 'cheie-licenta=', 'admin-email=', 'captura=', 'sha-captura=',
        'cron-vechi=', 'cron-de-mana',
        'fara-sonda-web', 'fara-magazin', 'fara-newsletter', 'fara-articole', 'baza-noua', 'inlocuieste', 'reia=', 'acasa=', 'plan', 'aplica']);
    $a = new AdoptareWp($o);
    $lacat = $a->s->lacat();
    if ($o->are('baza-noua')) {
        bazaNoua($a);
        return 0;
    }
    stergeSondeWp($a);
    Mentenanta::avertizeazaVeche($a->s);
    Iesire::titlu('Adoptarea site-ului WordPress ' . $a->s->domeniu . ' (' . $a->slug . ') pe versiunea ' . $a->versiune . ($a->aplica ? '' : ' — PLAN'));
    Iesire::info('dosarul : ' . $a->s->dosar);
    Iesire::info('adresa  : ' . $a->url);
    if ($a->stare->facuta('deschidere') && $a->stare->ia('revino') === null) {
        throw new Oprire('site-ul ' . $a->s->domeniu . ' e deja adoptat (' . $a->stare->date['faze']['deschidere'] . '). O versiune nouă: deploy/actualizeaza.sh', 1);
    }
    // O adoptare întoarsă (deploy/revino.sh) nu se continuă: una nouă pornește de la început, pe aceeași bază nouă (importul
    // o pune la zi: aceleași pagini, după harta lui).
    $rev = (array) $a->stare->ia('revino', []);
    if (!empty($rev['gata'])) {
        Iesire::atentie('adoptarea de dinainte a fost întoarsă la WordPress (' . $rev['gata'] . '); o iau de la capăt'
            . ($a->aplica ? '' : ' (cu --aplica; starea veche se păstrează alături)'));
        if ($a->aplica) {
            $pastrate = ['baza_instalata' => $a->stare->ia('baza_instalata'), 'admin_email' => $a->stare->ia('admin_email')];
            Fisiere::muta($a->stare->fisier, $a->stare->fisier . '.intoarsa-' . date('Ymd-His'));
            $a->stare = new Stare($a->s->lucru . '/adopta-wp.stare');
            foreach (array_filter($pastrate) as $k => $v) {
                $a->stare->pune($k, $v);
            }
        } else {
            // Planul: o stare doar în memorie (nescrisă), cu baza și adminul celei vechi.
            $vecheDate = $a->stare->date;
            $a->stare = new Stare($a->s->lucru . '/adopta-wp.stare.plan-' . getmypid());
            foreach (['baza_instalata', 'admin_email'] as $k) {
                if (isset($vecheDate[$k])) {
                    $a->stare->date[$k] = $vecheDate[$k];
                }
            }
        }
    } elseif ($rev !== []) {
        throw new Oprire('revenirea a început (deploy/revino.sh); termin-o cu aceeași comandă, nu continui adoptarea', 2, [
            'deploy/revino.sh --site=' . $a->slug . ' --domeniu=' . $a->s->domeniu . ' --aplica',
        ]);
    }
    if (!$a->aplica) {
        verificariWp($a);
        planulWp($a);
        return 0;
    }
    Iesire::jurnal($a->s->lucru . '/jurnale/adopta-wp-' . date('Ymd-His') . '.log');
    Redenumiri::termina($a->stare);
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
        $i = array_search($reia, FAZE_WP, true);
        $iComutare = (int) array_search('comutare', FAZE_WP, true);
        if ($i === false || ($i < $iComutare && $a->comutat())) {
            throw new Oprire('--reia=' . $reia . ': ' . ($i === false ? 'fazele sunt ' . implode(', ', FAZE_WP)
                : 'site-ul e deja comutat; importul se reia doar întorcându-te întâi (deploy/revino.sh), apoi o adoptare nouă'), 2);
        }
        foreach (array_slice(FAZE_WP, (int) $i) as $f) {
            unset($a->stare->date['faze'][$f]);
        }
        $a->stare->salveaza();
    }
    $a->stare->pune('versiune', $a->versiune);
    $a->stare->pune('site', $a->slug);
    foreach (FAZE_WP as $faza) {
        if ($a->stare->facuta($faza)) {
            Iesire::titlu($faza . ': făcută deja (' . $a->stare->date['faze'][$faza] . '), o sar');
            continue;
        }
        Iesire::titlu($faza);
        try {
            testOpreste('inainte-' . $faza);
            ('Platforma\\Deploy\\wp' . ucfirst($faza))($a);
        } catch (Oprire $e) {
            throw new Oprire('faza „' . $faza . '”: ' . $e->getMessage(), $e->getCode(), array_merge($e->pasi, dupaEsecWp($a, $faza)));
        }
        $a->stare->marcheaza($faza);
    }
    cronWp($a);
    Iesire::titlu('GATA');
    Iesire::scrie('Site-ul ' . $a->s->domeniu . ' merge pe codul comun, versiunea ' . $a->versiune . '.');
    Iesire::scrie('WordPress a rămas, neatins, în ' . $a->s->vechi . ' (revenirea: deploy/revino.sh --site=' . $a->slug . ' --domeniu=' . $a->s->domeniu . ' --aplica).');
    Iesire::scrie('Adminul nou: ' . $a->url . '/admin/login, cu adresa ' . (string) $a->stare->ia('admin_email', '?') . ' și parola din fișierul '
        . $a->s->lucru . '/parola-admin.txt (o vezi cu: cat ' . $a->s->lucru . '/parola-admin.txt). După prima logare o schimbi din admin.');
    Iesire::scrie('Copiile WordPress: ' . $a->stare->ia('copii') . '. Raportul importului: ' . $a->stare->ia('raport_import') . '.');
    unset($lacat);
    return 0;
}));

/** @return list<string> */
function dupaEsecWp(AdoptareWp $a, string $faza): array
{
    if (!$a->comutat()) {
        return ['site-ul a rămas pe WordPress, neatins; vizitatorii n-au văzut nimic',
            'după reparare rulezi din nou aceeași comandă (cu --aplica): continuă de la faza „' . $faza . '”'];
    }
    return [
        'site-ul e în MENTENANȚĂ: vizitatorii văd „Revenim în câteva minute”',
        'site-ul nou îl vezi în browser cu ' . $a->url . '/?andaxi_deploy=' . (string) $a->stare->ia('jeton', ''),
        'drumul 1: repari, apoi rulezi din nou aceeași comandă (cu --aplica); continuă de la faza „' . $faza . '”',
        'drumul 2, înapoi la WordPress (un minut): deploy/revino.sh --site=' . $a->slug . ' --domeniu=' . $a->s->domeniu . ' --aplica',
    ];
}

/**
 * --baza-noua: datele bazei noi (făcută de proprietar în hPanel → Databases), întrebate pe ecran (parola fără ecou), verificate
 * (răspunde, e goală, nu e baza WordPress) și scrise în site/.env (0600). O singură dată; --inlocuieste le schimbă.
 */
function bazaNoua(AdoptareWp $a): void
{
    $s = $a->s;
    Iesire::titlu('Baza nouă a site-ului ' . $s->domeniu);
    $env = Env::citeste($s->site . '/.env');
    if (($env['DB_DATABASE'] ?? '') !== '' && !$a->o->are('inlocuieste')) {
        throw new Oprire('site/.env are deja baza ' . $env['DB_DATABASE'] . ' (utilizatorul ' . ($env['DB_USERNAME'] ?? '?') . '); alte date: aceeași comandă cu --inlocuieste', 1);
    }
    if ($a->stare->ia('baza_instalata') !== null && $a->o->are('inlocuieste')) {
        throw new Oprire('pe baza din site/.env s-a instalat deja site-ul nou (' . $a->stare->ia('baza_instalata') . '); nu o schimb la jumătatea adoptării', 1);
    }
    $wp = WordPress::este($s->docroot) ? WordPress::config($s->docroot) : null;
    $intreaba = static function (string $intrebare, bool $ascuns = false, string $implicit = ''): string {
        echo $intrebare . ($implicit !== '' ? ' [' . $implicit . ']' : '') . ': ';
        $tty = $ascuns && function_exists('posix_isatty') && posix_isatty(STDIN);
        if ($tty) {
            shell_exec('stty -echo 2>/dev/null');
        }
        $r = fgets(STDIN);
        if ($tty) {
            shell_exec('stty echo 2>/dev/null');
            echo "\n";
        }
        $r = $r === false ? '' : trim($r);
        return $r !== '' ? $r : $implicit;
    };
    Iesire::info('o faci întâi în hPanel → Databases → „Create a new MySQL database”: numele bazei, utilizatorul și parola (le copiezi de acolo)');
    $nume = $intreaba('Numele bazei noi (de exemplu u123456789_cms)');
    $utilizator = $intreaba('Utilizatorul bazei noi');
    $parola = $intreaba('Parola utilizatorului (nu se vede cât scrii)', true);
    $gazda = $intreaba('Gazda bazei', false, $wp['env']['DB_HOST'] ?? 'localhost');
    foreach (['numele' => $nume, 'utilizatorul' => $utilizator, 'parola' => $parola] as $ce => $v) {
        if ($v === '' || preg_match('/[\r\n]/', $v) === 1) {
            throw new Oprire('lipsește ' . $ce . ' bazei; n-am scris nimic', 1);
        }
    }
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $nume) !== 1 || preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $utilizator) !== 1 || preg_match('/^[A-Za-z0-9_.:-]{1,190}$/', $gazda) !== 1) {
        throw new Oprire('numele bazei, al utilizatorului sau gazda au caractere neașteptate; n-am scris nimic', 1);
    }
    $port = '3306';
    if (str_contains($gazda, ':')) {
        [$gazda, $port] = explode(':', $gazda, 2);
    }
    $chei = ['DB_HOST' => $gazda, 'DB_PORT' => $port, 'DB_DATABASE' => $nume, 'DB_USERNAME' => $utilizator, 'DB_PASSWORD' => $parola];
    $b = new Baza($chei);
    $tabele = $b->tabele();
    if ($wp !== null && strtolower($wp['env']['DB_DATABASE']) === strtolower($nume) && strtolower($wp['env']['DB_HOST']) === strtolower($gazda)) {
        throw new Oprire('aceasta e chiar baza WordPress (din wp-config.php): site-ul nou are nevoie de o bază nouă, goală; n-am scris nimic', 1);
    }
    if ($tabele !== []) {
        throw new Oprire('baza ' . $nume . ' nu e goală (' . count($tabele) . ' tabele, de exemplu ' . implode(', ', array_slice($tabele, 0, 3))
            . '); fă una nouă în hPanel, sau golește-o în phpMyAdmin, apoi aceeași comandă; n-am scris nimic', 1);
    }
    Iesire::ok('baza ' . $nume . ' răspunde și e goală');
    Fisiere::dosar($s->site, 0750);
    Fisiere::dosar($s->lucru, 0700);
    $text = is_file($s->site . '/.env') ? (string) file_get_contents($s->site . '/.env') : "# Site-ul " . $s->domeniu . " pe codul comun (deploy/adopta-wp.sh)\n";
    Fisiere::scrie($s->site . '/.env', Env::cuChei($text, $chei, 'Baza nouă a site-ului (deploy/adopta-wp.sh --baza-noua, ' . date('Y-m-d H:i') . ')'), 0600);
    Iesire::ok('scris în ' . $s->site . '/.env (0600): DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD');
    Iesire::scrie('');
    Iesire::scrie('Gata. Mai departe, planul adoptării: deploy/adopta-wp.sh --site=' . $a->slug . ' --domeniu=' . $s->domeniu . ' --versiune=<v> --cheie-licenta=<cheia>');
}

/** Șterge sondele web rămase de la o rulare omorâtă. */
function stergeSondeWp(AdoptareWp $a): void
{
    foreach ([$a->s->docroot, $a->s->vechi] as $d) {
        foreach (glob($d . '/_andaxi_sonda_*.php') ?: [] as $f) {
            if (preg_match('/^_andaxi_sonda_[0-9a-f]{16}\.php$/', basename($f)) === 1 && @unlink($f)) {
                Iesire::atentie('am șters o sondă rămasă de la o rulare oprită: ' . $f);
            }
        }
    }
}

/** Sonda PHP-ului web (versiunea, sodium, dacă poate citi versiunea comună și site/), o clipă în docroot-ul WordPress. */
function sondaWebWp(AdoptareWp $a): ?array
{
    $nume = '_andaxi_sonda_' . bin2hex(random_bytes(8)) . '.php';
    $fisier = $a->s->docroot . '/' . $nume;
    $tinta = $a->cod . '/public/index.php';
    $site = $a->s->site;
    $cod = '<?php header("Cache-Control: no-store"); header("Content-Type: application/json");' . "\n"
        . 'echo json_encode(["php" => PHP_VERSION, "sodium" => extension_loaded("sodium") && function_exists("sodium_crypto_sign_verify_detached"), '
        . '"pdo_mysql" => extension_loaded("pdo_mysql"), "cod_citibil" => @is_file(' . var_export($tinta, true) . '), '
        . '"site_citibil" => ' . (is_dir($site) ? '@is_dir(' . var_export($site, true) . ') && @is_readable(' . var_export($site, true) . ')' : 'null') . ']);' . "\n";
    register_shutdown_function(static function () use ($fisier): void {
        @unlink($fisier);
    });
    if (@file_put_contents($fisier, $cod) === false) {
        return null;
    }
    @chmod($fisier, 0644);
    try {
        $r = Http::cere($a->url . '/' . $nume);
    } finally {
        @unlink($fisier);
    }
    $d = $r['cod'] === 200 ? json_decode($r['corp'], true) : null;
    return is_array($d) && isset($d['php']) ? $d : null;
}

/** Dosarul de site temporar pentru o comandă a codului nou înainte de pregătire (planul importului): pachetul, .env, RELEASE. */
function siteTemporar(AdoptareWp $a, callable $f): mixed
{
    $temp = $a->s->acasa . '/platforma/plan-' . $a->s->domeniu . '-' . getmypid() . '/site';
    try {
        Fisiere::dosar($temp, 0700);
        Fisiere::dosar($temp . '/storage/logs', 0700);
        $a->scriePachet($temp . '/pachet');
        $text = is_file($a->s->site . '/.env') ? (string) file_get_contents($a->s->site . '/.env') : '';
        Fisiere::scrie($temp . '/.env', Env::cuChei($text, $a->cheiEnv($temp . '/pachet', ''), 'planul adoptării WordPress'), 0600);
        Fisiere::scrie($temp . '/RELEASE', $a->versiune . "\n");
        return $f($temp);
    } finally {
        Fisiere::sterge(dirname($temp), [$a->s->acasa . '/platforma']);
    }
}

/**
 * Argumentele importului (dosarul WordPress, adresa, părțile lăsate deoparte, captura). Captura se dă mereu explicit: planul rulează
 * pe un dosar de site temporar (siteTemporar), unde implicitul importului (capturat/ lângă .env) n-ar găsi-o. @return list<string>
 */
function argumenteImport(AdoptareWp $a): array
{
    return array_merge(['scripts/importa-wordpress.php', '--wp=' . $a->dosarWp(), '--url=' . $a->url], array_map(static fn (string $p): string => '--fara-' . $p, $a->fara()),
        $a->argumenteCaptura());
}

/** Argumentele fișierelor statice (scripts/publica-statice.php), cu --plan sau --aplica. @return list<string> */
function argumenteStatice(AdoptareWp $a, string $mod): array
{
    return array_merge(['scripts/publica-statice.php', '--wp=' . $a->dosarWp(), '--url=' . $a->url], $a->argumenteCaptura(), [$mod]);
}

function verificariWp(AdoptareWp $a): void
{
    $s = $a->s;
    $probleme = [];
    $nu = static function (string $text) use (&$probleme): void {
        Iesire::nu($text);
        $probleme[] = $text;
    };
    foreach (['tar', 'gzip', 'cp', 'du'] as $p) {
        if (!Comanda::exista($p)) {
            $nu('lipsește programul ' . $p);
        }
    }
    if (Comanda::primul('mysqldump', 'mariadb-dump') === null) {
        $nu('lipsește mysqldump (sau mariadb-dump): copia de siguranță a bazei WordPress');
    }
    $lipsa = array_values(array_filter(['sodium', 'pdo_mysql', 'curl', 'mbstring', 'json'], static fn (string $e): bool => !extension_loaded($e)));
    if ($lipsa === []) {
        Iesire::ok('PHP-ul liniei de comandă ' . PHP_VERSION . ' are sodium, pdo_mysql, curl, mbstring');
    } else {
        $nu('PHP-ul liniei de comandă n-are: ' . implode(', ', $lipsa) . (in_array('sodium', $lipsa, true) ? ' (pe Hostinger: PHP="/opt/alt/php83/usr/bin/php -d extension=sodium.so")' : ''));
    }
    if (!function_exists('imagewebp')) {
        Iesire::atentie('PHP-ul n-are GD cu WebP: pozele importate nu primesc variantele mici (paginile merg, doar mai grele)');
    }

    // Versiunea de cod.
    $problemeVersiune = Versiune::verifica($a->cod, $a->versiune);
    if ($problemeVersiune === []) {
        Iesire::ok('versiunea ' . $a->versiune . ' e urcată și neatinsă (' . $a->cod . ')');
        if (!is_file($a->cod . '/scripts/importa-wordpress.php')) {
            $nu('versiunea ' . $a->versiune . ' n-are importul WordPress (scripts/importa-wordpress.php): fă o versiune mai nouă');
        }
    } else {
        $nu('versiunea ' . $a->versiune . ': ' . implode('; ', array_slice($problemeVersiune, 0, 3)) . ' (deploy/release.sh --ref=… --aplica)');
    }
    $phpMin = $problemeVersiune === [] ? Versiune::phpMin($a->cod) : '8.3';

    // WordPress.
    if (!is_dir($s->docroot)) {
        throw new Oprire('nu există ' . $s->docroot);
    }
    if ($s->cuStub()) {
        throw new Oprire('în ' . $s->docroot . ' e deja stub-ul codului comun: site-ul e adoptat. O versiune nouă: deploy/actualizeaza.sh', 1);
    }
    if (!WordPress::este($s->docroot)) {
        throw new Oprire($s->docroot . ' nu e o instalare WordPress (lipsește ' . implode(' sau ', WordPress::SEMNE) . '); un site vechi al platformei se adoptă cu deploy/adopta.sh', 1);
    }
    if (file_exists($s->vechi)) {
        $nu('există deja ' . $s->vechi . ' (de la o încercare de dinainte?); uită-te în el și mută-l de acolo înainte');
    }
    $wp = null;
    try {
        $wp = $a->wp();
        $bw = new Baza($wp['env']);
        $tabele = $bw->tabele();
        $aleWp = array_values(array_filter($tabele, static fn (string $t): bool => str_starts_with($t, $wp['prefix'])));
        if (!in_array($wp['prefix'] . 'posts', $tabele, true) || !in_array($wp['prefix'] . 'options', $tabele, true)) {
            $nu('baza WordPress ' . $bw->nume() . ' n-are tabelele ' . $wp['prefix'] . 'posts și ' . $wp['prefix'] . 'options');
        } else {
            Iesire::ok('WordPress ' . WordPress::versiune($s->docroot) . ' în ' . $s->docroot . '; baza lui ' . $bw->nume() . ' răspunde (' . count($aleWp) . ' tabele '
                . $wp['prefix'] . '*, ' . Fisiere::om($bw->octeti()) . '): o citesc doar, n-o schimb niciodată');
            $a->stare->date['wp_octeti'] = $bw->octeti();
        }
    } catch (Oprire $e) {
        $nu('WordPress: ' . $e->getMessage());
    }

    // Baza nouă (din site/.env, scrisă de --baza-noua).
    $env = Env::citeste($s->site . '/.env');
    if (($env['DB_DATABASE'] ?? '') === '') {
        $nu('lipsește baza nouă a site-ului: o faci în hPanel → Databases, apoi: deploy/adopta-wp.sh --site=' . $a->slug . ' --domeniu=' . $s->domeniu . ' --baza-noua');
    } else {
        try {
            $bn = new Baza($env);
            $tabele = $bn->tabele();
            if ($wp !== null && strtolower($bn->nume()) === strtolower($wp['env']['DB_DATABASE']) && strtolower((string) ($env['DB_HOST'] ?? '')) === strtolower($wp['env']['DB_HOST'])) {
                $nu('baza din site/.env e chiar baza WordPress: site-ul nou are nevoie de o bază nouă (--baza-noua --inlocuieste)');
            } elseif ($tabele === []) {
                Iesire::ok('baza nouă ' . $bn->nume() . ' răspunde și e goală');
            } elseif ($a->stare->ia('baza_instalata') === $bn->nume()) {
                Iesire::ok('baza nouă ' . $bn->nume() . ' e cea instalată de această adoptare (' . count($tabele) . ' tabele); importul o pune la zi');
            } else {
                $nu('baza nouă ' . $bn->nume() . ' nu e goală (' . count($tabele) . ' tabele) și nu e a acestei adoptări; folosește una nouă (--baza-noua --inlocuieste)');
            }
            $drepturi = $bn->drepturi();
            if (!in_array('ALL PRIVILEGES', $drepturi, true)) {
                $lipsaD = array_values(array_diff(['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX'], $drepturi));
                if ($lipsaD !== []) {
                    $nu('utilizatorul bazei noi n-are dreptul ' . implode(', ', $lipsaD) . ' (instalarea îl cere)');
                }
            }
        } catch (Oprire $e) {
            $nu('baza nouă: ' . $e->getMessage());
        }
    }

    // Pachetul, licența.
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
        $fis = array_keys((array) ($manifest['fisiere'] ?? []));
        Iesire::ok('pachetul site-uri/' . $a->slug . '/: ' . count($fis) . ' fișiere' . (in_array('tema/tema.json', $fis, true) ? ', cu tema' : ', FĂRĂ temă')
            . (in_array('import-wordpress.json', $fis, true) ? ', cu regulile importului' : ''));
        if (!in_array('tema/tema.json', $fis, true)) {
            Iesire::atentie('pachetul n-are tema/tema.json: paginile ar avea doar stilurile nucleului (docs/PACHETE.md, „Tema”)');
        }
    }
    $licente = glob($s->site . '/storage/licenta/licenta*.json') ?: [];
    if ($licente === []) {
        $nu('lipsește licența: ' . $s->site . '/storage/licenta/licenta.json (din CRM)');
    } else {
        Iesire::ok('licența e pusă: ' . implode(', ', array_map('basename', $licente)));
    }
    if (($a->o->text('cheie-licenta') ?? ($env['LICENTA_CHEIE_PUBLICA'] ?? '')) === '') {
        $nu('lipsește cheia publică a licenței: --cheie-licenta=<base64> (cheia CRM-ului)');
    }
    $admin = $a->adminEmail();
    if ($admin === '') {
        $nu('lipsește adresa primului administrator al site-ului nou: --admin-email=<adresa> (obligatorie; adresa de administrare din '
            . 'WordPress nu se ia niciodată singură: poate fi a agenției)');
    } else {
        Iesire::ok('primul administrator al site-ului nou: ' . $admin);
    }

    // Spațiul (corectura 1.14): mediile și arborii statici stau de două ori pe disc (WordPress neatins + docroot-ul nou), plus
    // copia codului WordPress și a bazei. `df` vede discul serverului, nu cota contului Hostinger.
    $spatiu = WordPress::spatiu($s->docroot, (int) ($a->stare->date['wp_octeti'] ?? 0));
    $liber = (float) @disk_free_space($s->acasa);
    if ($liber > 0 && $liber < $spatiu['nevoie']) {
        $nu('spațiu liber ' . Fisiere::om($liber) . ', trebuie cel puțin ' . Fisiere::om($spatiu['nevoie']) . ' (mediile și arborii statici de două ori, copia WordPress, baza)');
    } else {
        Iesire::ok('spațiu liber pe disc (df): ' . Fisiere::om($liber) . '; trebuie ~' . Fisiere::om($spatiu['nevoie']));
    }
    Iesire::atentie('mediile (' . Fisiere::om($spatiu['media']) . ') și arborii statici (' . Fisiere::om($spatiu['statice']) . ') stau de două ori pe disc: '
        . Fisiere::om($spatiu['doua_copii']) . ' (WordPress rămâne neatins pentru revenire), plus copia codului WordPress (' . Fisiere::om($spatiu['cod']) . ') și a bazei');
    Iesire::atentie('verifici în hPanel → Disk usage că mai încap ' . Fisiere::om($spatiu['nevoie']) . ': df nu arată cota contului Hostinger');

    // Site-ul de acum și sonda web.
    $r = Http::cere($a->url . '/');
    if ($r['cod'] !== 200) {
        $nu('site-ul de acum nu răspunde 200 la ' . $a->url . '/ (HTTP ' . $r['cod'] . ($r['eroare'] !== '' ? ', ' . $r['eroare'] : '') . ')');
    } else {
        Iesire::ok('site-ul de acum răspunde la ' . $a->url . '/ (200, ' . round($r['timp'], 2) . ' s)' . (WordPress::pagina($r['corp']) ? ', e WordPress' : ''));
    }
    if ($a->o->are('fara-sonda-web')) {
        Iesire::atentie('fără sonda web: sodium-ul site-ului și PHP-ul lui le-ai verificat în hPanel (PHP Configuration)');
    } elseif ($problemeVersiune === []) {
        $sonda = sondaWebWp($a);
        if ($sonda === null) {
            $nu('sonda PHP-ului web nu răspunde (' . $a->url . '/_andaxi_sonda_….php); verifică în hPanel sau rulează cu --fara-sonda-web');
        } else {
            $a->stare->date['release_local'] = !($sonda['cod_citibil'] ?? false);
            $bun = version_compare((string) $sonda['php'], $phpMin, '>=') && ($sonda['sodium'] ?? false) && ($sonda['pdo_mysql'] ?? false);
            ($bun ? [Iesire::class, 'ok'] : $nu)('PHP-ul site-ului (web): ' . $sonda['php'] . (($sonda['sodium'] ?? false) ? ', sodium da'
                : ', SODIUM NU (hPanel → PHP Configuration → PHP extensions → sodium)') . (($sonda['pdo_mysql'] ?? false) ? '' : ', PDO MySQL NU'));
            Iesire::info(($sonda['cod_citibil'] ?? false) ? 'site-ul poate citi versiunea comună' : 'site-ul NU poate citi în afara dosarului lui: versiunea se pune și în ' . $s->site . '/releases/');
            if (($sonda['site_citibil'] ?? null) === false) {
                $nu('PHP-ul site-ului nu poate citi ' . $s->site . ' (open_basedir sau drepturi)');
            }
        }
    }

    // .htaccess-ul WordPress nu trece: docroot-ul nou are .htaccess-ul stub-ului. Regulile puse de mână (în afara blocurilor scrise
    // de WordPress și de pluginurile de cache, cu „# BEGIN …” / „# END …”) se arată, ca să fie mutate de om dacă trebuie.
    $deMana = WordPress::reguliHtaccessDeMana((string) @file_get_contents($s->docroot . '/.htaccess'));
    if ($deMana !== []) {
        Iesire::atentie('.htaccess-ul WordPress are ' . count($deMana) . ' rânduri puse de mână, care NU trec la site-ul nou (docs/DEPLOY.md): '
            . implode(' | ', array_map(static fn (string $l): string => mb_substr($l, 0, 120), array_slice($deMana, 0, 8))) . (count($deMana) > 8 ? ' | …' : ''));
    }

    // Drumul capturat: captura site-ului live trebuie să fie la locul ei (docs/DEPLOY.md, „Captura”).
    $capturat = $problemeVersiune === [] && $problemePachet === [] && $a->capturat();
    if ($capturat) {
        $c = $a->captura();
        if (!is_file($c . '/manifest.json') || !is_file($c . '/raport-captura.json')) {
            $nu('drumul capturat: lipsește captura site-ului live în ' . $c . ' (manifest.json, raport-captura.json); o faci cu scripts/captureaza-wordpress.php '
                . '--url=' . $a->url . ' --wp=' . $s->docroot . ' --iesire=' . $c . ' --fonturi --aplica (docs/DEPLOY.md, „Captura”), sau dai --captura=<dosar>');
        } else {
            Iesire::ok('captura site-ului live: ' . $c . ' (raport-captura.json: sha256 ' . substr((string) ($a->o->text('sha-captura') ?? hash_file('sha256', $c . '/raport-captura.json')), 0, 12)
                . ($a->o->text('sha-captura') !== null ? ', ancora dată' : ', ancora din dosar') . ')');
        }
    }

    // Planul importului (codul nou, pe un dosar de site temporar; baza WordPress doar citită).
    if ($problemeVersiune === [] && $problemePachet === [] && $wp !== null) {
        Iesire::titlu('Planul importului WordPress (scripts/importa-wordpress.php --plan, doar citire)');
        $r = siteTemporar($a, static fn (string $temp): array => platforma($temp, $a->cod, $a->versiune, array_merge(argumenteImport($a), ['--plan']), true));
        if ($r['cod'] !== 0) {
            $nu('planul importului are probleme (NU, mai sus) sau n-a mers (codul ' . $r['cod'] . ')');
        } else {
            Iesire::ok('planul importului: fără probleme');
        }
        if ($capturat) {
            Iesire::titlu('Planul fișierelor statice (scripts/publica-statice.php --plan, doar citire)');
            // Un docroot gol al planului (public_html.nou se face abia la pregătire): coliziunile se caută și în codul versiunii.
            $r = siteTemporar($a, static function (string $temp) use ($a): array {
                Fisiere::dosar(dirname($temp) . '/docroot', 0700);
                return platforma($temp, $a->cod, $a->versiune, argumenteStatice($a, '--plan'), true, dirname($temp) . '/docroot');
            });
            if ($r['cod'] !== 0) {
                $nu('planul fișierelor statice are probleme (NU, mai sus) sau n-a mers (codul ' . $r['cod'] . ')');
            } else {
                Iesire::ok('planul fișierelor statice: fără probleme');
            }
        }
    }

    // Cronurile vechi ale site-ului: ale WordPress (wp-cron.php) se șterg; altele se verifică de mână.
    foreach (cronuriWp($a) ?? [] as $c) {
        if ($c['fel'] === 'verifica') {
            ($a->o->are('cron-de-mana') ? [Iesire::class, 'atentie'] : $nu)('cron DE VERIFICAT (' . $c['motiv'] . '): ' . $c['linie']
                . ($a->o->are('cron-de-mana') ? '' : '; îl rezolvi tu în hPanel și rulezi cu --cron-de-mana'));
        }
    }

    if ($probleme !== []) {
        throw new Oprire(count($probleme) . ' verificări au ieșit NU (mai sus); nu schimb nimic', 1);
    }
    Iesire::ok('toate verificările au trecut');
    if ($a->aplica) {
        $a->stare->pune('admin_email', $admin);
    }
}

/**
 * Rândurile de cron ale site-ului (crontab -l sau --cron-vechi): „sterge” (WordPress: wp-cron.php, wp-cli pe dosarul lui,
 * care după comutare n-ar mai avea ce rula) sau „verifica” (pomenesc site-ul, dar nu sunt ale WordPress).
 *
 * @return list<array{fel: string, linie: string, motiv: string}>|null
 */
function cronuriWp(AdoptareWp $a): ?array
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
    $rez = [];
    foreach (explode("\n", $text) as $l) {
        $l = trim($l);
        if ($l === '' || str_starts_with($l, '#') || preg_match('/^[A-Z_]+=/', $l) === 1) {
            continue;
        }
        if (!str_contains($l, $a->s->domeniu) && !str_contains($l, $a->s->docroot)) {
            continue;
        }
        $rez[] = preg_match('#wp-cron\.php|(^|\s)wp\s#', $l) === 1
            ? ['fel' => 'sterge', 'linie' => $l, 'motiv' => 'cronul WordPress (wp-cron)']
            : ['fel' => 'verifica', 'linie' => $l, 'motiv' => 'pomenește site-ul, dar nu e cronul WordPress'];
    }
    return $rez;
}

function planulWp(AdoptareWp $a): void
{
    $s = $a->s;
    Iesire::titlu('Ce ar face --aplica');
    foreach ([
        'pregatire   ' . $s->site . '/ (.env: baza nouă + SITE_SLUG, APP_URL, APP_KEY, LICENTA_*; RELEASE=' . $a->versiune . '; pachet/; storage/), '
            . $s->nou . '/ (stub-ul, assets/, tema/' . ($a->capturat() ? '; fișierele statice cerute de pagini, din ' . $a->captura() : '') . '); diagnostic --pachet, --licenta',
        'instalare   scripts/install.php pe baza nouă: migrările, setările, primul administrator (' . ($a->adminEmail() ?: '?') . ', parola nouă în '
            . $s->lucru . '/parola-admin.txt)',
        'import      scripts/importa-wordpress.php --aplica: paginile, SEO-ul, setările, redirecționările; mediile copiate în ' . $s->nou
            . '/wp-content/uploads/ (aceeași cale, manifestul sha256), fără variantele WebP ale nucleului',
        'copie       ' . $s->lucru . '/copii/<data>-wordpress/: codul WordPress (fără poze) și baza lui (~' . Fisiere::om((int) $a->stare->ia('wp_octeti', 0)) . ')',
        'comutare    MENTENANTA; ' . $s->docroot . ' → ' . $s->vechi . ' (WordPress, neatins); ' . $s->nou . ' → ' . $s->docroot
            . '; fișierele de verificare a domeniului; .htaccess-urile arborilor copiați (deploy/stub/: fără execuție, cache ca live)',
        'verificare  ping, /health, /, /admin/login, assets/, o poză; fiecare pagină veche; o poză la adresa ei (200, aceiași octeți); sitemap.xml'
            . ($a->capturat() ? '; diagnostic --capturat; fiecare fișier cerut de pagini la adresa lui' : ''),
        'deschidere  MENTENANTA ștearsă; apoi cronurile pentru hPanel',
    ] as $rand) {
        Iesire::info($rand);
    }
    cronWp($a);
    Iesire::scrie('');
    Iesire::scrie('PLAN: site-ul n-a fost schimbat (sonda web a stat o clipă în docroot și s-a șters). Pentru a-l adopta: aceeași comandă cu --aplica.');
}

function wpVerificari(AdoptareWp $a): void
{
    verificariWp($a);
}

/** Textul unui .env fără o cheie (nici rândul ei comentat, ca o parolă să nu rămână în fișier). */
function faraCheie(string $text, string $cheie): string
{
    return (string) preg_replace('/^[ \t#]*' . preg_quote($cheie, '/') . '[ \t]*=.*\R?/m', '', $text);
}

function wpPregatire(AdoptareWp $a): void
{
    $s = $a->s;
    Fisiere::dosar($s->site, 0750);
    Fisiere::dosar($s->lucru, 0700);
    foreach (Fisiere::lista($a->cod . '/storage') as $rel) {
        Fisiere::dosar($s->site . '/storage/' . dirname($rel), 0770);
    }
    Fisiere::dosar($s->site . '/storage/logs', 0770);
    Iesire::ok('dosarul site-ului: ' . $s->site);
    $nou = $s->site . '/pachet.nou';
    Fisiere::sterge($nou, [$s->site]);
    $a->scriePachet($nou);
    Fisiere::sterge($s->site . '/pachet', [$s->site]);
    Fisiere::muta($nou, $s->site . '/pachet');
    Iesire::ok('pachetul: ' . $s->site . '/pachet');
    $admin = (string) $a->stare->ia('admin_email', '') ?: $a->adminEmail();
    $chei = $a->cheiEnv($s->site . '/pachet', $admin);
    // APP_KEY: cheia nonce-urilor formularelor (CadruCapturat::nonceFormular) și a gălețile rapoartelor CSP; scrisă o singură dată
    // (o reluare sau o revenire o păstrează: paginile din cache și formularele deschise rămân bune), aleatoare, niciodată în git.
    $cheieVeche = (string) (Env::citeste($s->site . '/.env')['APP_KEY'] ?? '');
    $chei['APP_KEY'] = strlen($cheieVeche) >= 32 ? $cheieVeche : bin2hex(random_bytes(32));
    Fisiere::scrie($s->site . '/.env', Env::cuChei((string) file_get_contents($s->site . '/.env'), $chei,
        'Site-ul nou (deploy/adopta-wp.sh, ' . date('Y-m-d H:i') . ')'), 0600);
    Iesire::ok('.env: baza nouă, plus APP_URL, APP_KEY' . (strlen($cheieVeche) >= 32 ? ' (cea de dinainte)' : ' (nouă)') . ', SITE_SLUG=' . $a->slug
        . ', SITE_DATE_DOSAR, LICENTA_*, ADMIN_DEFAULT_EMAIL');
    Fisiere::scrie($s->site . '/RELEASE', $a->versiune . "\n", 0644);
    golesteCacheCod($s);
    if ($a->stare->ia('release_local', false)) {
        legaVersiunea($s, $a->versiune);
    }
    $cod = $a->codSite();
    if (is_dir($s->nou)) {
        if (!is_file($s->nou . '/.andaxi-nou')) {
            throw new Oprire($s->nou . ' există și nu e făcut de deploy; uită-te în el și mută-l');
        }
        // Mediile copiate de un import de dinainte rămân (importul le sare pe cele la zi); restul se face din nou.
        foreach (Fisiere::lista($s->nou) as $rel) {
            if (!str_starts_with($rel, 'uploads/') && !str_starts_with($rel, 'wp-content/uploads/')) {
                @unlink($s->nou . '/' . $rel);
            }
        }
        foreach (['assets', 'tema'] as $d) {
            Fisiere::sterge($s->nou . '/' . $d, [$s->nou]);
        }
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
    Fisiere::dosar($s->nou . '/uploads', 0755);
    Iesire::ok('docroot-ul nou: ' . $s->nou . ' (stub-ul, assets/' . ($cuTema ? ', tema/' : '') . ', uploads/)');
    // Drumul capturat: foile, et-cache/, wp-includes/, fonturile și pozele temei cerute de pagini, de pe discul WordPress (doar citit),
    // verificate față de captură (aceleași verificări ca mediile; scripts/publica-statice.php). Mediile le copiază importul.
    if ($a->capturat()) {
        $r = platforma($s->site, $cod, $a->versiune, argumenteStatice($a, '--aplica'), true, $s->nou);
        if ($r['cod'] !== 0) {
            throw new Oprire('fișierele statice ale paginilor nu s-au copiat (scripts/publica-statice.php, codul ' . $r['cod'] . ', mai sus); site-ul a rămas pe WordPress');
        }
        Iesire::ok('fișierele statice ale paginilor capturate: în ' . $s->nou . ' (manifestul: ' . $s->site . '/storage/import-wordpress/statice.json)');
    }
    foreach (['--pachet' => 'pachetul site-ului', '--licenta' => 'licența'] as $opt => $ce) {
        $r = platforma($s->site, $cod, $a->versiune, ['scripts/diagnostic.php', $opt], true, $s->nou);
        if ($r['cod'] !== 0) {
            throw new Oprire('diagnostic.php ' . $opt . ' nu trece (' . $ce . ', mai sus)');
        }
        Iesire::ok('diagnostic.php ' . $opt . ': ok');
    }
}

function wpInstalare(AdoptareWp $a): void
{
    $s = $a->s;
    $cod = $a->codSite();
    $env = Env::citeste($s->site . '/.env');
    $baza = new Baza($env);
    $email = (string) ($env['ADMIN_DEFAULT_EMAIL'] ?? '');
    $fisierParola = $s->lucru . '/parola-admin.txt';
    $areAdmini = static function () use ($baza): int {
        try {
            return in_array('admins', $baza->tabele(), true) ? $baza->numar('SELECT COUNT(*) FROM admins') : 0;
        } catch (\Throwable) {
            return 0;
        }
    };
    if ($a->stare->ia('baza_instalata') === $baza->nume() && $areAdmini() > 0) {
        Iesire::ok('baza ' . $baza->nume() . ' e instalată deja (de această adoptare)');
    } else {
        // Parola primului administrator: scrisă întâi în fișierul ei (0600), apoi dată instalării prin .env și scoasă de acolo.
        if (!is_file($fisierParola)) {
            Fisiere::scrie($fisierParola, bin2hex(random_bytes(9)) . "\n", 0600);
        }
        $parola = trim((string) file_get_contents($fisierParola));
        Fisiere::scrie($s->site . '/.env', Env::cuChei((string) file_get_contents($s->site . '/.env'), ['ADMIN_DEFAULT_PASSWORD' => $parola], 'instalarea (scoasă după)'), 0600);
        try {
            for ($i = 1; $i <= 20; $i++) {
                $r = platforma($s->site, $cod, $a->versiune, ['scripts/install.php'], true, $s->nou);
                if ($r['cod'] === 0) {
                    break;
                }
                $text = $r['iesire'] . $r['erori'];
                if (str_contains($text, 'Baza există (are administratori)') && $areAdmini() > 0) {
                    // O rulare de dinainte a terminat instalarea (administratorul e ultimul pas) înainte să-și țină minte faza.
                    Iesire::info('instalarea fusese terminată de o rulare de dinainte (baza are administratorul)');
                    break;
                }
                if (!str_contains($text, 'continuă de unde a rămas')) {
                    throw new Oprire('scripts/install.php n-a mers (codul ' . $r['cod'] . ', mai sus)');
                }
                Iesire::info('instalarea continuă (bugetul de timp al migrărilor), încercarea ' . ($i + 1));
            }
        } finally {
            Fisiere::scrie($s->site . '/.env', faraCheie((string) file_get_contents($s->site . '/.env'), 'ADMIN_DEFAULT_PASSWORD'), 0600);
        }
        if ($areAdmini() === 0) {
            throw new Oprire('după instalare baza n-are administratorul');
        }
        $a->stare->pune('baza_instalata', $baza->nume());
        Iesire::ok('baza nouă instalată: migrările, setările, administratorul ' . $email . ' (parola în ' . $fisierParola . ', 0600; scoasă din .env)');
    }
    $r = platforma($s->site, $cod, $a->versiune, ['scripts/migreaza.php', '--plan', '--site=' . $a->slug], true);
    if ($r['cod'] !== 0) {
        throw new Oprire('după instalare, migreaza.php --plan nu spune „la zi” (codul ' . $r['cod'] . ': ' . sensMigrari($r['cod']) . ')');
    }
    Iesire::ok('migreaza.php --plan: baza e la zi');
}

function wpImport(AdoptareWp $a): void
{
    $s = $a->s;
    $cod = $a->codSite();
    Fisiere::dosar($s->lucru . '/import-wp', 0700);
    $raport = $s->lucru . '/import-wp/raport-' . date('Ymd-His') . '.json';
    $r = platforma($s->site, $cod, $a->versiune, array_merge(argumenteImport($a), ['--aplica', '--raport=' . $raport]), true, $s->nou);
    if ($r['cod'] !== 0) {
        throw new Oprire('importul WordPress n-a mers (codul ' . $r['cod'] . ', mai sus); baza nouă n-a primit nimic din el');
    }
    $d = json_decode((string) @file_get_contents($raport), true);
    if (!is_array($d) || !is_array($d['amprenta_wordpress'] ?? null)) {
        throw new Oprire('importul n-a scris raportul ' . $raport);
    }
    $a->stare->pune('raport_import', $raport);
    $a->stare->pune('amprenta_wordpress', $d['amprenta_wordpress']);
    $pagini = [];
    foreach ((array) ($d['plan']['pagini'] ?? []) as $p) {
        if (!empty($p['publicata'])) {
            $pagini[(string) $p['cale_veche']] = (string) $p['cale'];
        }
    }
    $a->stare->pune('pagini_importate', $pagini);
    Iesire::ok('importul: ' . count($pagini) . ' pagini publicate, raportul în ' . $raport);
    // Corectura 1.11: fără variantele WebP ale nucleului (scripts/optimizeaza-imagini.php): mediile stau la căile lor din
    // wp-content/uploads, iar paginile folosesc variantele făcute de WordPress (VarianteImagini și PozeOptimizate nu le ating).
    Iesire::info('mediile WordPress rămân cu variantele lor (fără variantele WebP ale nucleului)'
        . (WordPress::capturat($s->site . '/pachet') ? '; drumul capturat' : ''));
}

function wpCopie(AdoptareWp $a): void
{
    $s = $a->s;
    $dosar = $s->lucru . '/copii/' . date('Ymd-His') . '-wordpress';
    Fisiere::dosar($dosar, 0700);
    $inceput = microtime(true);
    $r = Comanda::ruleaza(['tar', '-czf', $dosar . '/wordpress-cod.tar.gz', '-C', $s->dosar, '--exclude=public_html/wp-content/uploads',
        '--exclude=public_html/wp-content/cache', '--exclude=public_html/wp-content/et-cache', '--exclude=public_html/_andaxi_sonda_*', 'public_html']);
    $doarSchimbate = $r['cod'] === 1 && array_filter(preg_split('/\R/', trim($r['erori'])) ?: [], static fn (string $l): bool =>
        trim($l) !== '' && !str_contains($l, 'file changed as we read it') && !str_contains($l, 'Exiting with failure status')) === [];
    if ($r['cod'] !== 0 && !$doarSchimbate) {
        throw new Oprire('copia codului WordPress n-a mers: ' . trim($r['erori']));
    }
    @chmod($dosar . '/wordpress-cod.tar.gz', 0600);
    Iesire::ok('codul WordPress: ' . $dosar . '/wordpress-cod.tar.gz (' . Fisiere::om((int) filesize($dosar . '/wordpress-cod.tar.gz')) . ', '
        . round(microtime(true) - $inceput, 1) . ' s; fără pozele, care rămân în WordPress)');
    $w = $a->wp();
    $secunde = (new Baza($w['env']))->copie($dosar . '/wordpress-baza.sql.gz', false);
    Iesire::ok('baza WordPress: ' . $dosar . '/wordpress-baza.sql.gz (' . Fisiere::om((int) filesize($dosar . '/wordpress-baza.sql.gz')) . ', ' . round($secunde, 1)
        . ' s; doar o copie de siguranță: adoptarea n-o schimbă)');
    $a->stare->pune('copii', $dosar);
}

function wpComutare(AdoptareWp $a): void
{
    $s = $a->s;
    $comutat = $a->comutat();
    if (!$comutat) {
        // Ce a importat importul trebuie să fie ce e acum în WordPress (o pagină editată între timp s-ar pierde).
        $w = $a->wp();
        $acum = WordPress::amprentaContinut(new Baza($w['env']), $w['prefix']);
        $atunci = (array) $a->stare->ia('amprenta_wordpress', []);
        $diferite = array_keys(array_filter($acum, static fn (string $v, string $k): bool => ($atunci[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        if ($diferite !== []) {
            throw new Oprire('conținutul WordPress s-a schimbat de la import (' . implode(', ', $diferite) . '): cineva a editat site-ul între timp', 1, [
                'importul din nou, cu ce e acum în WordPress: aceeași comandă cu --reia=import',
            ]);
        }
        Iesire::ok('conținutul WordPress e cel de la import');
        if (!is_dir($s->nou) || !is_file($s->nou . '/.andaxi-nou')) {
            throw new Oprire($s->nou . ' nu e întreg: nu comut pe el; îl faci din nou cu aceeași comandă și --reia=pregatire', 1);
        }
        // .well-known/ cu o legătură simbolică: refuzul vine înaintea mentenanței (recenzia 3, R1-N3), deci site-ul nu rămâne oprit.
        if (($leg = WordPress::legaturaWellKnown($s->docroot . '/.well-known')) !== null) {
            throw new Oprire($leg . ': nu o urmez; verific-o de mână, apoi aceeași comandă', 1);
        }
    }
    $jeton = (string) $a->stare->ia('jeton', '') ?: bin2hex(random_bytes(16));
    $a->stare->pune('jeton', $jeton);
    if (!$comutat) {
        // .htaccess-urile arborilor copiați sunt în docroot-ul nou înainte să servească ceva (serverul web dă fișierele înaintea PHP-ului).
        WordPress::scrieHtaccess($a->codSite(), $s->nou);
    }
    Mentenanta::pune($s, $jeton, true);
    Redenumiri::fa($a->stare, 'comutare', [[$s->docroot, $s->vechi], [$s->nou, $s->docroot]]);
    $a->stare->pune('comutat', true);
    @unlink($s->docroot . '/.andaxi-nou');
    if (!$comutat) {
        Iesire::ok('comutat: WordPress în ' . $s->vechi . ', site-ul nou în ' . $s->docroot . ' (vizitatorii văd acum „Revenim în câteva minute”)');
    }
    $amprenta = $a->stare->ia('copii') . '/wordpress.amprenta';
    if (!is_file($amprenta)) {
        Fisiere::scrie($amprenta, Fisiere::amprenta($s->vechi, ['wp-content/uploads', 'wp-content/cache', 'wp-content/et-cache']), 0600);
        Iesire::ok('amprenta WordPress, cum era la comutare: ' . $amprenta);
    }
    // Fișierele de verificare a domeniului (Google, Bing…) și .well-known/, din rădăcina WordPress, unde nu există deja.
    $preluate = [];
    foreach (scandir($s->vechi) ?: [] as $f) {
        foreach (WordPress::FISIERE_SITE as $tipar) {
            if (preg_match($tipar, $f) === 1 && is_file($s->vechi . '/' . $f) && !is_link($s->vechi . '/' . $f) && !file_exists($s->docroot . '/' . $f)) {
                Fisiere::copiaza($s->vechi . '/' . $f, $s->docroot . '/' . $f);
                $preluate[] = $f;
            }
        }
    }
    // .well-known/ (recenzia 2, R1-1): o legătură simbolică (dosarul însuși sau orice din el) e NU, niciodată urmată; se aduc
    // doar fișierele din lista albă (WordPress::permisWellKnown), iar dosarul primește un .htaccess care nu execută nimic.
    $wk = $s->vechi . '/.well-known';
    if (($leg = WordPress::legaturaWellKnown($wk)) !== null) {
        throw new Oprire($leg . ': nu o urmez (verific-o de mână)', 1);
    }
    if (is_dir($wk)) {
        $deAdus = [];
        foreach (Fisiere::lista($wk) as $rel) {
            if (!str_starts_with($rel, 'acme-challenge/') && WordPress::permisWellKnown($rel) && is_file($wk . '/' . $rel)
                && !file_exists($s->docroot . '/.well-known/' . $rel)) {
                $deAdus[] = $rel;
            } elseif (!str_starts_with($rel, 'acme-challenge/')) {
                Iesire::atentie('.well-known/' . $rel . ': nu se aduce (în afara listei albe: doar .txt, .json, .xml sau fără extensie)');
            }
        }
        foreach ($deAdus as $rel) {
            Fisiere::dosar(dirname($s->docroot . '/.well-known/' . $rel), 0755);
            Fisiere::copiaza($wk . '/' . $rel, $s->docroot . '/.well-known/' . $rel);
            $preluate[] = '.well-known/' . $rel;
        }
        if ($deAdus !== []) {
            Fisiere::scrie($s->docroot . '/.well-known/.htaccess', WordPress::HTACCESS_WELL_KNOWN, 0644);
        }
    }
    $a->stare->pune('preluate', $preluate);
    Iesire::ok('fișierele site-ului aduse din WordPress: ' . ($preluate === [] ? 'niciunul' : implode(', ', array_slice($preluate, 0, 6)) . (count($preluate) > 6 ? ', …' : '')));
    // Corectura 1.12: arborii copiați (wp-content/, wp-includes/, capturat/) primesc .htaccess-urile din git: fără execuție,
    // fără liste de fișiere, cu cache-ul ca live și CORS doar pe fonturi. Mediile rămân la adresele lor (fără 301).
    $arbori = WordPress::scrieHtaccess($a->codSite(), $s->docroot);
    Iesire::ok('.htaccess-urile arborilor copiați (din deploy/stub/): ' . implode(', ', array_map(static fn (string $x): string => $x . '/', $arbori)));
    foreach (['.user.ini', 'php.ini'] as $f) {
        if (is_file($s->vechi . '/' . $f)) {
            Iesire::atentie('WordPress avea ' . $f . ' (setări PHP); nu l-am adus (poate trimite la fișiere ale WordPress): uită-te în ' . $s->vechi . '/' . $f);
        }
    }
    // Primele cereri ale codului nou (pașii de activare, redirecționările pachetului), apoi amprenta bazei noi.
    $antete = ['X-Andaxi-Deploy' => $jeton, 'Cookie' => 'andaxi_deploy=' . $jeton, 'Cache-Control' => 'no-cache'];
    $coduri = [];
    foreach (['/', '/admin/login', '/health'] as $cale) {
        $coduri[] = $cale . ' ' . Http::cere($a->url . $cale, $antete)['cod'];
    }
    Iesire::info('primele cereri ale site-ului nou: ' . implode(', ', $coduri));
    $fisierDupa = $a->stare->ia('copii') . '/baza-noua-dupa-import.json';
    if (!is_file($fisierDupa)) {
        $b = new Baza(Env::citeste($s->site . '/.env'));
        Fisiere::scrie($fisierDupa, (string) json_encode(['facuta' => date('c'), 'baza' => $b->nume(), 'amprenta' => $b->amprenta()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", 0600);
    }
    Mentenanta::pune($s, $jeton, false);
}

function wpVerificare(AdoptareWp $a): void
{
    $jeton = (string) $a->stare->ia('jeton');
    $probleme = verificaSiteul($a->s, $a->url, $jeton, $a->versiune, '');
    $antete = ['X-Andaxi-Deploy' => $jeton, 'Cookie' => 'andaxi_deploy=' . $jeton, 'Cache-Control' => 'no-cache'];
    $pagini = (array) $a->stare->ia('pagini_importate', []);
    $rele = [];
    foreach ($pagini as $vechi => $nou) {
        $r = Http::cere($a->url . $vechi, $antete);
        if ($r['cod'] !== 200 || str_contains($r['corp'], 'Revenim în câteva minute')) {
            $rele[] = $vechi . ' (HTTP ' . $r['cod'] . ')';
        }
    }
    if ($rele === []) {
        Iesire::ok('adresele vechi ale paginilor (' . count($pagini) . '): fiecare răspunde 200 (direct sau după un 301)');
    } else {
        Iesire::nu('adresele vechi ale paginilor: ' . implode(', ', array_slice($rele, 0, 8)));
        $probleme[] = 'adresele vechi ale paginilor';
    }
    $r = Http::cere($a->url . '/sitemap.xml', $antete);
    if ($r['cod'] === 200 && (str_contains($r['corp'], '<urlset') || str_contains($r['corp'], '<sitemapindex'))) {
        Iesire::ok('/sitemap.xml: 200');
    } else {
        Iesire::nu('/sitemap.xml: HTTP ' . $r['cod']);
        $probleme[] = 'sitemap.xml';
    }
    // O poză la adresa ei (/wp-content/uploads/…, aceeași ca pe WordPress): 200, cu aceiași octeți ca fișierul copiat.
    $poza = null;
    foreach (Fisiere::lista($a->s->docroot . '/wp-content/uploads') as $rel) {
        if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $rel) === 1) {
            $poza = $rel;
            break;
        }
    }
    if ($a->capturat()) {
        $probleme = array_merge($probleme, verificaStaticeWp($a, $antete));
    }
    if ($poza !== null) {
        $r = Http::cere($a->url . '/wp-content/uploads/' . str_replace('%2F', '/', rawurlencode($poza)), $antete);
        if ($r['cod'] === 200 && hash('sha256', $r['corp']) === (string) hash_file('sha256', $a->s->docroot . '/wp-content/uploads/' . $poza)) {
            Iesire::ok('o poză la adresa ei (/wp-content/uploads/' . $poza . '): 200, aceiași octeți');
        } else {
            Iesire::nu('o poză la adresa ei (/wp-content/uploads/' . $poza . '): HTTP ' . $r['cod'] . ($r['cod'] === 200 ? ', alți octeți' : ''));
            $probleme[] = 'mediile la adresele lor';
        }
    }
    if ($probleme !== []) {
        throw new Oprire(count($probleme) . ' verificări ale site-ului nou au picat: ' . implode('; ', $probleme));
    }
}

/**
 * Drumul capturat, după comutare: fiecare fișier cerut de pagini e în docroot cu sha256-ul lui (diagnostic.php --capturat, pe
 * manifestul capturii și al copiilor), iar fiecare fișier static publicat, fiecare font Google și fiecare mediu cerut de pagini
 * răspunde la adresa lui cu aceiași octeți (prin serverul web, cu .htaccess-urile arborilor copiați). @return list<string> problemele
 */
function verificaStaticeWp(AdoptareWp $a, array $antete): array
{
    $s = $a->s;
    $probleme = [];
    $r = platforma($s->site, $a->codSite(), $a->versiune, ['scripts/diagnostic.php', '--capturat'], true, $s->docroot);
    if ($r['cod'] !== 0) {
        Iesire::nu('diagnostic.php --capturat: un fișier cerut de pagini lipsește sau are alt sha256 (mai sus)');
        $probleme[] = 'fișierele cerute de pagini (diagnostic.php --capturat)';
    } else {
        Iesire::ok('diagnostic.php --capturat: fiecare fișier cerut de pagini e în docroot, cu sha256-ul lui');
    }
    $deCerut = [];
    $statice = json_decode((string) @file_get_contents($s->site . '/storage/import-wordpress/statice.json'), true);
    if (!is_array($statice)) {
        Iesire::nu('lipsește manifestul fișierelor statice (' . $s->site . '/storage/import-wordpress/statice.json)');
        return array_merge($probleme, ['manifestul fișierelor statice']);
    }
    foreach ((array) ($statice['fisiere'] ?? []) as $f) {
        if (is_array($f) && is_string($f['sha256_publicat'] ?? null) && is_string($f['cale'] ?? null)) {
            $deCerut[$f['cale']] = $f['sha256_publicat'];
        }
    }
    foreach ((array) ($statice['fonturi'] ?? []) as $f) {
        if (is_array($f) && is_string($f['publicat'] ?? null) && is_string($f['sha256_publicat'] ?? null)) {
            $deCerut[$f['publicat']] = $f['sha256_publicat'];
        }
    }
    $manifest = json_decode((string) @file_get_contents($a->captura() . '/manifest.json'), true);
    foreach (is_array($manifest) ? (array) ($manifest['fisiere'] ?? []) : [] as $f) {
        if (is_array($f) && str_starts_with(strtolower((string) ($f['cale'] ?? '')), '/wp-content/uploads/') && is_string($f['sha256'] ?? null)) {
            $deCerut[(string) $f['cale']] = (string) ($f['sha256_sursa'] ?? $f['sha256']);
        }
    }
    $rele = [];
    $n = 0;
    foreach ($deCerut as $cale => $sha) {
        if (++$n > 2000) {
            break;
        }
        $r = Http::cere($a->url . str_replace('%2F', '/', rawurlencode((string) $cale)), $antete);
        if ($r['cod'] !== 200 || !hash_equals($sha, hash('sha256', $r['corp']))) {
            $rele[] = $cale . ' (HTTP ' . $r['cod'] . ($r['cod'] === 200 ? ', alți octeți' : '') . ')';
        }
    }
    if ($rele === []) {
        Iesire::ok('fișierele cerute de pagini (' . count($deCerut) . ': foile, fonturile, mediile), la adresele lor: 200, aceiași octeți');
    } else {
        Iesire::nu('fișierele cerute de pagini: ' . count($rele) . ' nu răspund cu octeții lor: ' . implode(', ', array_slice($rele, 0, 8)));
        $probleme[] = 'fișierele cerute de pagini, la adresele lor';
    }
    $necopiate = array_values(array_filter((array) ($statice['fisiere'] ?? []), static fn (mixed $f): bool => is_array($f) && is_string($f['necopiat'] ?? null)
        && !str_starts_with((string) $f['necopiat'], 'script')));
    foreach (array_slice($necopiate, 0, 8) as $f) {
        Iesire::atentie('necopiat (refuzat de verificări la pregătire): ' . $f['cale'] . ': ' . $f['necopiat']);
    }
    return $probleme;
}

function wpDeschidere(AdoptareWp $a): void
{
    $s = $a->s;
    $h = Http::cere($a->url . '/health', ['X-Andaxi-Deploy' => (string) $a->stare->ia('jeton', '')]);
    if ($h['cod'] !== 200 || !str_starts_with($h['corp'], "ok\n") || !str_contains($h['corp'], 'schema: la_zi')) {
        throw new Oprire('/health nu spune „schema: la_zi” (HTTP ' . $h['cod'] . '); nu scot MENTENANTA', 1);
    }
    Mentenanta::scoate($s);
    $r = Http::cere($a->url . '/');
    if ($r['cod'] !== 200 || str_contains($r['corp'], 'Revenim în câteva minute')) {
        Mentenanta::pune($s, (string) $a->stare->ia('jeton'), false);
        throw new Oprire('fără MENTENANTA site-ul nu răspunde ca la verificare (/ → HTTP ' . $r['cod'] . '); l-am pus la loc în mentenanță');
    }
    Iesire::ok('MENTENANTA ștearsă: ' . $a->url . '/ răspunde 200 oricui (' . round($r['timp'], 2) . ' s)');
    $a->stare->pune('jeton', null);
}

function cronWp(AdoptareWp $a): void
{
    $s = $a->s;
    Iesire::titlu('Cronurile (hPanel → Advanced → Cron Jobs)');
    $linii = cronuriWp($a);
    if ($linii === null) {
        Iesire::info('nu văd cronurile (crontab -l); dacă hPanel arată un rând cu wp-cron.php al acestui site, îl ȘTERGI după GATA');
        $linii = [];
    }
    foreach ($linii as $c) {
        Iesire::info(($c['fel'] === 'sterge' ? 'ȘTERGI  : ' : 'DE VERIFICAT: ') . $c['linie'] . '   (' . $c['motiv'] . ')');
    }
    Iesire::info('un site doar cu nucleul n-are nevoie de cronuri; preluarea automată a licenței (opțională, docs/DEPLOY.md, „Licența”): '
        . '*/5 * * * * /opt/alt/php83/usr/bin/php -d extension=sodium.so ' . $s->acasa . '/platforma/bin/platforma --site=' . $s->site . ' scripts/licenta-sincronizare.php');
}
