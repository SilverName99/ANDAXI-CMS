<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use App\Support\HtmlCapturat;
use App\Support\HtmlCapturat\ContextCapturat;
use App\Support\ImportWordPress\Continut\Divi4Taiere;
use App\Support\ImportWordPress\Continut\RezultatDialect;
use App\Support\ImportWordPress\Continut\Taiere;
use App\Support\Slug;

/**
 * Ce ar face importul, fără să scrie nimic: paginile (adresa veche, calea nouă, conținutul convertit, SEO-ul), ce nu se
 * importă și de ce, redirecționările, setările, meniul, fișierele de copiat; plus problemele (NU: importul nu pornește)
 * și avertismentele (ATENȚIE: doar spuse). Același plan îl arată `--plan` și îl aplică `--aplica` (Importator).
 *
 * Regulile pachetului (`import-wordpress.json`, ImportWordPress::REGULI) spun pentru fiecare pagină dacă se importă,
 * cu ce slug și unde duce adresa ei veche dacă nu se importă.
 */
final class Plan
{
    /** @var list<array<string, mixed>> */
    public array $pagini = [];
    /** @var list<array<string, mixed>> */
    public array $neimportate = [];
    /** @var list<array{din: string, spre: ?string, cod: int, nota: string, prefix: bool}> */
    public array $redirecturi = [];
    /** @var array<string, string> */
    public array $setari = [];
    /** @var list<array<string, mixed>> elementele meniului principal, cu copiii lor */
    public array $meniu = [];
    /** @var array<string, string> antetul, meniul și subsolul (setările design_*), dacă le face convertorul */
    public array $design = [];
    /** @var array<int, array<string, mixed>> atașamentele site-ului (Inventar::$atasamente), pentru Galerie */
    public array $atasamente = [];
    /** @var array<string, mixed> Media::lista */
    public array $media = ['fisiere' => [], 'lipsa' => [], 'sarite' => [], 'octeti' => 0];
    /** @var list<string> */
    public array $probleme = [];
    /** @var list<string> */
    public array $avertismente = [];
    /** @var list<string> */
    public array $info = [];
    /** @var list<array{wp_id: int, tip: string, slug_wp: string, cale: string, stare: string, ordine: int, fisier: string}> harta WordPress (HartaWp) */
    public array $hartaWp = [];
    /** Drumul capturat (Context::capturat): antetul, meniul și subsolul nu se scriu în setările design_*. */
    public bool $capturat = false;
    /**
     * Partea capturată (drumul capturat, pasul 5a): documentele tăiate (paginile, 404-ul, cardurile embed, arhivele), fragmentele,
     * răspunsurile înghețate, formularele, galeriile. O scrie ScriereCapturata, în tranzacția importului; null pe drumul necapturat.
     */
    public ?PlanCapturat $capturata = null;
    /**
     * Rândurile editate de om de la importul de dinainte (corectura 1.9, Amprente::conflicte): la --aplica refuzate fără
     * --suprascrie. Le pune scripts/importa-wordpress.php, din baza site-ului.
     *
     * @var list<array{fel: string, cheie: string, motiv: string}>
     */
    public array $conflicte = [];

    /**
     * Resturile tehnice WordPress: 410, ca Google să le uite (corectura 1.4). Nu și arborii copiați (`/wp-content/`,
     * `/wp-includes/`): fișierele lor le servește serverul web înaintea PHP-ului (deploy/stub/.htaccess), iar unul care
     * lipsește răspunde 404, ca pe site-ul live.
     */
    public const RESTURI_WORDPRESS = [
        ['/wp-admin/', 'panoul vechi de administrare WordPress', true],
        ['/wp-json/', 'API-ul WordPress', true],
        ['/wp-login.php', 'logarea WordPress', false],
        ['/xmlrpc.php', 'XML-RPC WordPress', false],
        ['/readme.html', 'fișierul readme al WordPress', false],
    ];

    /** Arborii copiați în docroot-ul nou (fișierele lor nu primesc niciodată 410). */
    public const ARBORI_COPIATI = ['/wp-content/', '/wp-includes/'];

    /**
     * Lista albă a endpointurilor compatibile (corectura 1.4): adrese WordPress pe care paginile capturate le cer și pe
     * care le servește codul nostru, deci nu primesc 410. După pluginul activ pe site; CF7 vine la pasul 17.
     *
     * @var array<string, list<array{metoda: string, cale: string, actiune?: string}>>
     */
    public const COMPATIBILE = [
        'divi-gallery-extended' => [['metoda' => 'POST', 'cale' => '/wp-admin/admin-ajax.php', 'actiune' => 'dge_get_images']],
        'complianz-gdpr' => [['metoda' => 'GET', 'cale' => '/wp-json/complianz/v1/manage_consent_html']],
        'complianz-gdpr-premium' => [['metoda' => 'GET', 'cale' => '/wp-json/complianz/v1/manage_consent_html']],
    ];

    /** Endpointurile WordPress ale drumului capturat (pasul 9a): oEmbed, servit din răspunsurile înghețate. */
    public const COMPATIBILE_CAPTURAT = [['metoda' => 'GET', 'cale' => '/wp-json/oembed/1.0/embed']];

    /**
     * @param array{fara?: list<string>} $optiuni fara: părțile pe care proprietarul a ales să nu le importe (magazin, newsletter, articole)
     */
    public static function fa(Inventar $i, Context $ctx, array $optiuni = []): self
    {
        $p = new self();
        $p->atasamente = $i->atasamente;
        $p->hartaWp = $i->harta;
        $p->capturat = $ctx->capturat();
        $fara = array_values(array_unique((array) ($optiuni['fara'] ?? [])));
        $p->verificaSite($i, $ctx, $fara);
        $p->planificaPagini($i, $ctx);
        $p->planificaArticole($i, $ctx, $fara);
        $p->planificaResturi($i, $ctx);
        $p->convertestePagini($i, $ctx);
        $p->planificaMeniu($i, $ctx);
        if ($ctx->capturat()) {
            // Corectura 1.6: antetul și subsolul sunt fragmente capturate; meniul WordPress rămâne doar ca date (slotul de meniu).
            $p->info[] = 'drumul capturat: antetul, meniul și subsolul vin din captură (fragmentele, pasul 5); meniul WordPress rămâne doar ca date';
        } else {
            $p->planificaDesign($ctx);
        }
        $p->planificaSetari($i, $ctx);
        $p->media = Media::lista($i, $ctx->dosarUploads, $ctx->fisierePomenite());
        $p->verificaMedia($i);
        return $p;
    }

    /** @param list<string> $fara */
    private function verificaSite(Inventar $i, Context $ctx, array $fara): void
    {
        $this->info[] = 'site-ul WordPress: „' . $i->site['nume'] . '” la ' . $i->site['url'] . ' (WordPress ' . ($i->site['versiune_wp'] ?: '?')
            . ', tema ' . ($i->site['tema']['stylesheet'] ?: '?') . ')';
        if (!str_contains((string) $i->site['permalinkuri'], '%postname%') && $i->site['permalinkuri'] !== '') {
            $this->avertismente[] = 'structura permalinkurilor e ' . $i->site['permalinkuri'] . ': adresele articolelor se calculează după ea';
        } elseif ($i->site['permalinkuri'] === '') {
            $this->probleme[] = 'site-ul folosește adresele simple (?page_id=…): fără permalinkuri, adresele vechi nu se pot păstra (Setări → Legături permanente în WordPress)';
        }
        foreach ($i->pluginuri as $plugin) {
            $stiut = Inventar::PLUGINURI[$plugin] ?? null;
            if ($stiut === null) {
                $this->avertismente[] = 'pluginul „' . $plugin . '” nu e știut de import: ce face el pe site nu trece singur (de verificat de mână)';
            } else {
                $this->info[] = 'pluginul „' . $plugin . '” (' . $stiut['ce'] . '): ' . $stiut['cum'];
            }
        }
        $parti = [
            'magazin' => ['produse' => 'produse', 'comenzi' => 'comenzi', 'cupoane' => 'cupoane'],
            'newsletter' => ['abonati_newsletter' => 'abonați la newsletter'],
        ];
        foreach ($parti as $parte => $chei) {
            $ce = [];
            foreach ($chei as $k => $nume) {
                if ((int) ($i->altele[$k] ?? 0) > 0) {
                    $ce[] = $i->altele[$k] . ' ' . $nume;
                }
            }
            if ($ce === []) {
                continue;
            }
            if (in_array($parte, $fara, true)) {
                $this->avertismente[] = 'NU se importă (ai ales --fara-' . $parte . '): ' . implode(', ', $ce);
            } else {
                $this->probleme[] = 'site-ul are ' . implode(', ', $ce) . ', iar importul lor (' . $parte . ') nu e făcut încă (docs/DEPLOY.md, „Ce rămâne pentru '
                    . 'un magazin WordPress”); fără ele adoptarea doar cu --fara-' . $parte;
            }
        }
        foreach ($i->altele as $k => $n) {
            if (str_starts_with($k, 'tip:')) {
                $this->avertismente[] = 'tipul de conținut „' . substr($k, 4) . '” (' . $n . ') nu se importă';
            }
        }
        if ((int) ($i->altele['comentarii'] ?? 0) > 0) {
            $this->avertismente[] = $i->altele['comentarii'] . ' comentarii aprobate nu se importă (codul comun n-are comentarii)';
        }
    }

    private function planificaPagini(Inventar $i, Context $ctx): void
    {
        $sluguri = [];
        foreach ($i->pagini as $id => $pg) {
            $regula = $ctx->regulaPagina((string) $pg['slug']);
            $prima = $i->site['arata_pe_prima'] === 'page' && (int) $i->site['prima_pagina'] === $id;
            $caleVeche = $prima ? '/' : (string) $pg['cale'];
            if (!in_array($pg['stare'], ['publish', 'private'], true)) {
                $this->neimportate[] = ['wp_id' => $id, 'titlu' => $pg['titlu'], 'cale' => $pg['cale'], 'motiv' => 'stare „' . $pg['stare'] . '” (nepublicată)', 'spre' => null];
                continue;
            }
            if ($ctx->capturat() && ($pg['stare'] === 'private' || $pg['parola'])) {
                // Drumul capturat ia doar ce vede un vizitator (pasul 4a: paginile publicate): o pagină privată sau cu parolă n-are
                // captură, deci nu se importă (altfel ar opri tot importul cu un NU pentru o pagină pe care n-o vede nimeni).
                $this->neimportate[] = ['wp_id' => $id, 'titlu' => $pg['titlu'], 'cale' => $caleVeche, 'motiv' => 'pagină ' . ($pg['parola'] ? 'cu parolă' : 'privată')
                    . ' în WordPress: drumul capturat ia doar paginile publice', 'spre' => null];
                $this->avertismente[] = 'pagina „' . $pg['titlu'] . '” e ' . ($pg['parola'] ? 'cu parolă' : 'privată') . ' în WordPress: pe drumul capturat nu se importă (n-are captură)';
                continue;
            }
            if (($regula['importa'] ?? true) === false) {
                $spre = isset($regula['spre']) && is_string($regula['spre']) ? (string) $regula['spre'] : null;
                $cod = (int) ($regula['cod'] ?? ($spre !== null ? 301 : 0));
                $this->neimportate[] = ['wp_id' => $id, 'titlu' => $pg['titlu'], 'cale' => $caleVeche, 'motiv' => (string) ($regula['motiv'] ?? 'regula pachetului'),
                    'spre' => $spre];
                if ($pg['stare'] === 'publish' && $caleVeche !== '/' && ($spre !== null || $cod === 410)) {
                    $this->redirecturi[] = ['din' => rtrim($caleVeche, '/'), 'spre' => $spre, 'cod' => $spre !== null ? 301 : 410,
                        'nota' => 'WordPress: pagina „' . $pg['titlu'] . '” (' . ($regula['motiv'] ?? 'neimportată') . ')', 'prefix' => false];
                }
                if ($spre !== null) {
                    $ctx->adrese->adaugaPostare($id, $pg['cale'], $spre);
                }
                continue;
            }
            // Corectura 1.3: slug-ul paginii e post_name-ul WordPress, păstrat exact: decodat în `pages.slug` (pentru rutare),
            // în forma percent-encoded în adrese (calea WordPress). Slug::make doar pentru regulile „slug” ale pachetului.
            if ($prima) {
                $slug = '';
                $caleNoua = '/';
            } elseif (isset($regula['slug']) && is_string($regula['slug'])) {
                $segmente = array_map(static fn (string $s): string => Slug::make($s, 'pagina'), explode('/', trim((string) $regula['slug'], '/')));
                $slug = implode('/', $segmente);
                $caleNoua = Adrese::caleWordPress($segmente, $i->slashFinal());
            } else {
                $slug = trim((string) $pg['cale'], '/');
                $caleNoua = (string) ($pg['cale_wp'] ?? Adrese::caleWordPress(explode('/', $slug), $i->slashFinal()));
            }
            if (isset($sluguri[$slug])) {
                $this->probleme[] = 'două pagini ar avea aceeași adresă /' . $slug . ': „' . $sluguri[$slug] . '” și „' . $pg['titlu'] . '” (o regulă „slug” în import-wordpress.json)';
                continue;
            }
            $sluguri[$slug] = $pg['titlu'];
            $ctx->adrese->adaugaPostare($id, $pg['cale'], $caleNoua);
            $publicata = $pg['stare'] === 'publish' && !$pg['parola'] && ($regula['publicata'] ?? true) !== false;
            if ($pg['stare'] === 'private' || $pg['parola']) {
                $this->avertismente[] = 'pagina „' . $pg['titlu'] . '” e ' . ($pg['parola'] ? 'cu parolă' : 'privată') . ' în WordPress: se importă nepublicată';
            }
            $this->pagini[] = ['wp_id' => $id, 'titlu' => $pg['titlu'], 'cale_veche' => $caleVeche, 'slug' => $slug, 'cale' => $caleNoua, 'publicata' => $publicata,
                'constructor' => $pg['constructor'], 'prima' => $prima];
            // Adresa veche diferă de cea nouă altfel decât prin „/” final (diacritice, majuscule, o regulă „slug”): 301.
            if (!$prima && Adrese::cheie($caleVeche) !== Adrese::cheie($caleNoua) && $pg['stare'] === 'publish') {
                $this->redirecturi[] = ['din' => rtrim($caleVeche, '/'), 'spre' => $caleNoua, 'cod' => 301, 'nota' => 'WordPress: adresa veche a paginii „' . $pg['titlu'] . '”',
                    'prefix' => false];
            }
            if ($prima && $pg['slug'] !== '') {
                // WordPress trimitea /<slug-ul primei pagini>/ la /; pe codul comun la fel.
                $this->redirecturi[] = ['din' => '/' . rawurldecode((string) $pg['slug']), 'spre' => '/', 'cod' => 301,
                    'nota' => 'WordPress: prima pagină („' . $pg['titlu'] . '”) avea și adresa ei', 'prefix' => false];
                $ctx->adrese->adaugaCale('/' . rawurldecode((string) $pg['slug']) . '/', '/');
            }
            if ($prima) {
                $ctx->adrese->adaugaCale('/', '/');
            }
            foreach ($pg['sluguri_vechi'] as $vechi) {
                $din = '/' . trim(dirname(trim((string) $pg['cale'], '/')) !== '.' ? dirname(trim((string) $pg['cale'], '/')) . '/' . rawurldecode($vechi) : rawurldecode($vechi), '/');
                if (Adrese::cheie($din) !== Adrese::cheie($caleNoua)) {
                    $this->redirecturi[] = ['din' => $din, 'spre' => $caleNoua, 'cod' => 301, 'nota' => 'WordPress: slug vechi al paginii „' . $pg['titlu'] . '”', 'prefix' => false];
                }
            }
        }
        if ($i->site['arata_pe_prima'] !== 'page' || !isset($i->pagini[(int) $i->site['prima_pagina']])) {
            $this->avertismente[] = 'prima pagină a site-ului WordPress e lista articolelor, nu o pagină: pe codul comun / va fi pagina de start a nucleului';
        }
    }

    /** @param list<string> $fara */
    private function planificaArticole(Inventar $i, Context $ctx, array $fara): void
    {
        $regulaArticole = is_array($ctx->reguli['articole'] ?? null) ? $ctx->reguli['articole'] : [];
        $publicate = array_filter($i->articole, static fn (array $a): bool => $a['stare'] === 'publish');
        $reale = array_filter($publicate, static fn (array $a): bool => !self::articolExemplu($a));
        foreach ($publicate as $id => $a) {
            $exemplu = self::articolExemplu($a);
            $spre = is_string($regulaArticole['spre'] ?? null) ? (string) $regulaArticole['spre'] : null;
            $this->neimportate[] = ['wp_id' => $id, 'titlu' => $a['titlu'], 'cale' => $a['cale'],
                'motiv' => $exemplu ? 'articolul de exemplu al WordPress' : 'articol (blogul nu se importă încă)', 'spre' => $exemplu ? null : $spre];
            $this->redirecturi[] = ['din' => rtrim((string) $a['cale'], '/'), 'spre' => $exemplu ? null : $spre, 'cod' => ($exemplu || $spre === null) ? 410 : 301,
                'nota' => 'WordPress: ' . ($exemplu ? 'articolul de exemplu' : 'articolul') . ' „' . $a['titlu'] . '”', 'prefix' => false];
        }
        if ($reale !== [] && !in_array('articole', $fara, true) && !isset($regulaArticole['spre'])) {
            $this->probleme[] = count($reale) . ' articole publicate, iar importul blogului nu e făcut încă; fără ele adoptarea doar cu --fara-articole '
                . '(adresele lor primesc 410) sau cu „articole.spre” în import-wordpress.json';
        }
        if ($reale === [] || in_array('articole', $fara, true) || isset($regulaArticole['spre'])) {
            // Fără blog pe site: categoriile, etichetele și fluxurile articolelor dispar și ele.
            foreach ([['/category/', 'categoriile articolelor WordPress'], ['/tag/', 'etichetele articolelor WordPress']] as [$prefix, $nota]) {
                $this->redirecturi[] = ['din' => $prefix, 'spre' => null, 'cod' => 410, 'nota' => 'WordPress: ' . $nota, 'prefix' => true];
            }
        }
    }

    /** Un articol pus de WordPress la instalare („Hello world!” / „Salut lume!”), nu scris de om. */
    public static function articolExemplu(array $a): bool
    {
        return in_array((string) $a['slug'], ['hello-world', 'salut-lume'], true)
            && (str_contains((string) $a['continut'], 'Welcome to WordPress') || str_contains((string) $a['continut'], 'Bine ai venit în WordPress')
                || str_contains((string) $a['continut'], 'Bun venit în WordPress'));
    }

    /**
     * Endpointurile compatibile ale site-ului (COMPATIBILE, după pluginurile active).
     *
     * @return list<array{metoda: string, cale: string, actiune?: string}>
     */
    public static function compatibile(Inventar $i, bool $capturat = false): array
    {
        $rez = [];
        // Pasul 9a: pe drumul capturat, oEmbed-ul paginilor rămase îl servesc răspunsurile înghețate (nu 410).
        foreach ($capturat ? self::COMPATIBILE_CAPTURAT : [] as $e) {
            $rez[$e['metoda'] . ' ' . $e['cale'] . ' ' . ($e['actiune'] ?? '')] = $e;
        }
        foreach ($i->pluginuri as $plugin) {
            foreach (self::COMPATIBILE[$plugin] ?? [] as $e) {
                $rez[$e['metoda'] . ' ' . $e['cale'] . ' ' . ($e['actiune'] ?? '')] = $e;
            }
        }
        ksort($rez);
        return array_values($rez);
    }

    private function planificaResturi(Inventar $i, Context $ctx): void
    {
        $compatibile = self::compatibile($i, $ctx->capturat());
        foreach (self::RESTURI_WORDPRESS as [$din, $nota, $prefix]) {
            // Harta adreselor vechi lucrează doar pe GET/HEAD: un endpoint compatibil cerut prin POST nu e atins de ea. Unul
            // cerut prin GET sub această adresă ar primi 410, deci regula nu se scrie în hartă.
            $acoperite = array_values(array_filter($compatibile, static fn (array $e): bool => $e['metoda'] === 'GET'
                && ($prefix ? str_starts_with($e['cale'], $din) : $e['cale'] === $din)));
            if ($acoperite !== []) {
                $this->avertismente[] = '410 pe ' . $din . ($prefix ? '…' : '') . ' nu se scrie în harta adreselor: sub el e un endpoint compatibil ('
                    . implode(', ', array_map(static fn (array $e): string => $e['metoda'] . ' ' . $e['cale'], $acoperite)) . '); 410-ul îl dă stratul '
                    . 'adreselor WordPress (wp-compat.json: resturi_410, cu lista albă „compatibile”)';
                continue;
            }
            $this->redirecturi[] = ['din' => $din, 'spre' => null, 'cod' => 410, 'nota' => 'rest tehnic WordPress (' . $nota . ')', 'prefix' => $prefix];
        }
        foreach ($compatibile as $e) {
            $this->info[] = 'endpoint compatibil (fără 410): ' . $e['metoda'] . ' ' . $e['cale'] . (isset($e['actiune']) ? ' (acțiunea ' . $e['actiune'] . ')' : '');
        }
        $this->info[] = 'fișierele din ' . implode(' și ', self::ARBORI_COPIATI) . ' le servește serverul web din copii; unul care lipsește răspunde 404, ca pe site-ul live';
        foreach ((array) ($ctx->reguli['redirecturi'] ?? []) as $r) {
            if (is_array($r) && is_string($r[0] ?? null)) {
                $this->redirecturi[] = ['din' => (string) $r[0], 'spre' => is_string($r[1] ?? null) ? (string) $r[1] : null, 'cod' => (int) ($r[2] ?? 301),
                    'nota' => (string) ($r[3] ?? 'import-wordpress.json'), 'prefix' => (bool) ($r[4] ?? false)];
            }
        }
    }

    private function convertestePagini(Inventar $i, Context $ctx): void
    {
        if ($ctx->capturat()) {
            // Corectura 1.15: pe drumul capturat convertorul vine din dialect (pasul 5), pe captura de la pasul 4.
            $convertorDialect = $ctx->convertorDialect();
            if ($convertorDialect === null) {
                $this->probleme[] = 'drumul capturat (dialectul „' . $ctx->dialect() . '”): lipsește convertorul dialectului (Continut\\Divi4, pasul 5a); importul nu pornește';
            }
            // Contextul politicii: manifestul capturii (foile permise în <noscript>), dacă dialectul îl dă (recenzia m7).
            $ctxPolitica = $convertorDialect !== null && method_exists($convertorDialect, 'contextPolitica')
                ? $convertorDialect->contextPolitica() : new ContextCapturat();
            // Pasul 5a: captura pe care lucrează dialectul (fără ea, NU-ul vine de la dialect), anul ei și modulul cu anul din subsol.
            $dosar = $convertorDialect !== null && method_exists($convertorDialect, 'captura') ? $convertorDialect->captura() : null;
            $this->capturata = new PlanCapturat();
            $anCaptura = $dosar !== null ? PlanCapturat::anCaptura($dosar) : date('Y');
            $paginiCapturate = [];
            foreach ($this->pagini as $k => $pg) {
                $this->pagini[$k]['seo'] = $this->seoPagina($i, $ctx, $pg);
                if ($convertorDialect === null) {
                    $this->pagini[$k] += ['html' => '', 'css' => '', 'js' => '', 'convertor' => null];
                    continue;
                }
                // Orice eroare neașteptată a dialectului sau a politicii e NU pe pagină, nu o eroare fatală a planului (recenzia m1).
                try {
                    $c = $convertorDialect->converteste($i->pagini[$pg['wp_id']], $ctx);
                } catch (\Throwable $e) {
                    $c = ['html' => '', 'css' => '', 'js' => '', 'probleme' => ['eroare neașteptată în dialect (' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200) . ')'],
                        'avertismente' => []];
                }
                foreach ($c['probleme'] as $pr) {
                    $this->probleme[] = 'pagina „' . $pg['titlu'] . '”: ' . $pr;
                }
                foreach ($c['avertismente'] as $av) {
                    $this->avertismente[] = 'pagina „' . $pg['titlu'] . '”: ' . $av;
                }
                $this->pagini[$k] += ['html' => $c['html'], 'css' => $c['css'], 'js' => $c['js'], 'convertor' => $convertorDialect->nume()];
                // Pasul 3: politica listei albe pe HTML-ul transformat de dialect (de neîncredere). Ce nu e explicit permis =
                // NU pe pagină, cu motivul. Dialectul (Divi4, 5a) a scos scripturile, a mutat configurațiile în ax-date și a
                // transformat handlerele, deci aici doar se verifică; un refuz oprește importul paginii. (Fără captură, html-ul
                // e gol și NU-ul vine de la dialect.)
                $acceptat = $c['probleme'] === [];
                if (is_string($c['html']) && $c['html'] !== '') {
                    try {
                        foreach (HtmlCapturat::verifica($c['html'], $ctxPolitica, 'import') as $problema) {
                            $this->probleme[] = 'pagina „' . $pg['titlu'] . '” (HtmlCapturat): ' . $problema->linie();
                            $acceptat = false;
                        }
                    } catch (\Throwable $e) {
                        $this->probleme[] = 'pagina „' . $pg['titlu'] . '” (HtmlCapturat): eroare neașteptată (' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200) . ')';
                        $acceptat = false;
                    }
                }
                // Pasul 5a: T al dialectului se taie în cele cinci bucăți, cu fragmentele și jetoanele (Divi4Taiere). În
                // `pages.html_content` intră doar bucata conținutului; restul documentului merge în tabelele capturii
                // (ScriereCapturata), deci documentul întreg nu ajunge niciodată în pagină.
                $this->pagini[$k]['html'] = '';
                $sursa = $i->pagini[$pg['wp_id']];
                $cale = $pg['prima'] ? '/' : (string) ($sursa['cale_wp'] ?? $sursa['cale'] ?? '');
                $cheie = 'pagina:' . $pg['wp_id'];
                $paginiCapturate[$cale] = $cheie; // și când pagina e NU: captura ei nu e o arhivă
                $this->capturata->documenteVazute[] = $cale;
                $rd = $c['dialect'] ?? null;
                if (!$rd instanceof RezultatDialect) {
                    if ($acceptat) {
                        $this->probleme[] = 'pagina „' . $pg['titlu'] . '”: convertorul „' . $convertorDialect->nume() . '” n-a dat documentul transformat (T), deci nu se poate tăia (pasul 5a)';
                    }
                    continue;
                }
                if (!$acceptat) {
                    continue;
                }
                try {
                    $t = Divi4Taiere::taie($rd, PlanCapturat::contextPagina($i, $sursa, $cale, $this->pagini[$k]['seo'], $anCaptura, $ctx->reguli,
                        $this->capturata->stareCaptura($dosar, $cale)));
                    if ($t->probleme === []) {
                        foreach (HtmlCapturat::verifica($t->html, $ctxPolitica, 'import') as $problema) {
                            $this->probleme[] = 'pagina „' . $pg['titlu'] . '” (HtmlCapturat, după pasul 5a): ' . $problema->linie();
                            $acceptat = false;
                        }
                    }
                } catch (\Throwable $e) {
                    $t = Taiere::refuz(['eroare neașteptată la tăiere (' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200) . ')']);
                }
                $r = $dosar?->dupaCale($cale);
                $this->capturata->adaugaDocument($cheie, 'pagina', $t, ['titlu' => 'pagina „' . $pg['titlu'] . '”', 'wp_id' => (int) $pg['wp_id'], 'status' => 200,
                    'sha_captura' => $r?->sha256() ?? '', 'antete' => $r !== null && $dosar !== null ? PlanCapturat::antete($r->antete, $dosar->url) : [],
                    'valori_import' => ['cale' => $cale], 'corpus' => PlanCapturat::corpus($sursa), 'wp_modificat' => PlanCapturat::dataWp((string) ($sursa['modificat'] ?? ''))]);
                if ($t->probleme === [] && $acceptat) {
                    $this->pagini[$k]['html'] = $t->continut;
                    $this->pagini[$k]['document'] = $cheie;
                }
            }
            foreach ($this->capturata->probleme as $pr) {
                $this->probleme[] = $pr;
            }
            foreach ($this->capturata->avertismente as $av) {
                $this->avertismente[] = $av;
            }
            if ($dosar !== null) {
                // 404-ul, cardurile embed ale paginilor importate, arhivele și răspunsurile înghețate; apoi fragmentele (pe octeți),
                // meniurile, formularele și galeriile.
                $pb = count($this->capturata->probleme);
                $av = count($this->capturata->avertismente);
                $this->capturata->dinCaptura($dosar, $ctx->reguli, $paginiCapturate, array_map(static fn (array $n): string => (string) $n['cale'], $this->neimportate),
                    PlanCapturat::anDinamic($i, $anCaptura));
                $this->capturata->grupeaza($i);
                foreach (array_slice($this->capturata->probleme, $pb) as $pr) {
                    $this->probleme[] = 'captura: ' . $pr;
                }
                foreach (array_slice($this->capturata->avertismente, $av) as $x) {
                    $this->avertismente[] = 'captura: ' . $x;
                }
                foreach ($this->capturata->info as $x) {
                    $this->info[] = $x;
                }
            }
            return;
        }
        foreach ($this->pagini as $k => $pg) {
            $sursa = $i->pagini[$pg['wp_id']];
            $convertor = $ctx->convertor((string) $pg['constructor']);
            if ($convertor === null) {
                $this->probleme[] = 'pagina „' . $pg['titlu'] . '” (' . $pg['cale_veche'] . ') e făcută cu constructorul „' . $pg['constructor']
                    . '”, pentru care importul n-are încă un convertor';
                $this->pagini[$k] += ['html' => '', 'css' => '', 'js' => '', 'convertor' => null];
                continue;
            }
            $c = $convertor->converteste($sursa, $ctx);
            foreach ($c['probleme'] as $pr) {
                $this->probleme[] = 'pagina „' . $pg['titlu'] . '”: ' . $pr;
            }
            foreach ($c['avertismente'] as $av) {
                $this->avertismente[] = 'pagina „' . $pg['titlu'] . '”: ' . $av;
            }
            $this->pagini[$k] += ['html' => $c['html'], 'css' => $c['css'], 'js' => $c['js'], 'convertor' => $convertor->nume()];
            $this->pagini[$k]['seo'] = $this->seoPagina($i, $ctx, $pg);
        }
    }

    /** @return array{titlu: string, descriere: string, robots: string, imagine: string, canonical: string} */
    private function seoPagina(Inventar $i, Context $ctx, array $pg): array
    {
        $s = $i->seo[$pg['wp_id']] ?? ['titlu' => '', 'descriere' => '', 'canonical' => '', 'robots' => '', 'imagine' => '', 'probleme' => []];
        foreach ((array) ($s['probleme'] ?? []) as $pr) {
            $this->avertismente[] = 'SEO-ul paginii „' . $pg['titlu'] . '”: ' . $pr;
        }
        $imagine = (string) $s['imagine'];
        if (str_starts_with($imagine, 'uploads:')) {
            $ctx->fisier(substr($imagine, 8));
            $imagine = Adrese::media(substr($imagine, 8));
        } elseif ($imagine !== '') {
            $rel = $ctx->adrese->fisierUploads($imagine);
            if ($rel !== null) {
                $ctx->fisier($rel);
            }
            $imagine = $ctx->adrese->rescrie($imagine);
        }
        if ($ctx->capturat()) {
            // Corectura 1.5: pe drumul capturat head-ul vine din captură; seo_pages primește valorile AIOSEO exacte (și
            // canonicalul, cum îl scrie AIOSEO: cel pus de om, altfel adresa absolută a paginii), pentru panoul SEO și jetoane.
            $canonical = trim((string) $s['canonical']);
            if ($canonical === '') {
                $canonical = rtrim((string) $i->site['url'], '/') . (string) $pg['cale'];
            }
            $imagine = (string) $s['imagine'];
            if (str_starts_with($imagine, 'uploads:')) {
                $ctx->fisier(substr($imagine, 8));
                $imagine = rtrim((string) $i->site['url'], '/') . Adrese::media(substr($imagine, 8));
            }
            return ['titlu' => mb_substr((string) $s['titlu'], 0, 255), 'descriere' => (string) $s['descriere'], 'robots' => (string) $s['robots'],
                'imagine' => mb_substr($imagine, 0, 255), 'canonical' => mb_substr($canonical, 0, 255)];
        }
        // Un canonical spre aceeași pagină nu se păstrează (codul comun îl face singur); unul spre altă pagină, rescris.
        $canonical = trim((string) $s['canonical']);
        if ($canonical !== '') {
            $nou = $ctx->adrese->rescrie($canonical);
            $canonical = Adrese::cheie($nou) === Adrese::cheie($pg['cale']) || Adrese::cheie($canonical) === Adrese::cheie($pg['cale_veche']) ? '' : $nou;
        }
        return ['titlu' => mb_substr((string) $s['titlu'], 0, 255), 'descriere' => (string) $s['descriere'], 'robots' => (string) $s['robots'],
            'imagine' => mb_substr($imagine, 0, 255), 'canonical' => mb_substr($canonical, 0, 255)];
    }

    private function planificaMeniu(Inventar $i, Context $ctx): void
    {
        if ($i->meniuri === []) {
            $this->avertismente[] = 'site-ul n-are meniuri WordPress: meniul site-ului nou rămâne cel implicit (de scris în Design site)';
            return;
        }
        // Meniul principal: cel din locația „primary-menu” / „primary” / „main”, altfel cel cu cele mai multe elemente.
        $ales = null;
        foreach (['primary-menu', 'primary', 'main', 'main-menu', 'header-menu', 'menu-1'] as $loc) {
            foreach ($i->meniuri as $m) {
                if ($ales === null && in_array($loc, $m['locatii'], true)) {
                    $ales = $m;
                }
            }
        }
        if ($ales === null) {
            $meniuri = $i->meniuri;
            usort($meniuri, static fn (array $a, array $b): int => count($b['elemente']) <=> count($a['elemente']));
            $ales = $meniuri[0];
        }
        $this->info[] = 'meniul principal: „' . $ales['nume'] . '” (' . count($ales['elemente']) . ' elemente)';
        $noduri = [];
        foreach ($ales['elemente'] as $e) {
            $url = $this->urlElement($i, $ctx, $e);
            if ($url === null) {
                $this->avertismente[] = 'elementul de meniu „' . $e['titlu'] . '” duce la ceva neimportat (' . $e['obiect'] . ' ' . $e['obiect_id'] . '): scos din meniu';
                continue;
            }
            $titlu = $e['titlu'] !== '' ? $e['titlu'] : (string) ($i->pagini[$e['obiect_id']]['titlu'] ?? $url);
            $noduri[$e['id']] = ['id' => $e['id'], 'titlu' => $titlu, 'url' => $url, 'tinta' => $e['tinta'], 'parinte' => $e['parinte'], 'copii' => []];
        }
        $arbore = [];
        foreach ($noduri as $id => $n) {
            if ($n['parinte'] > 0 && isset($noduri[$n['parinte']])) {
                $noduri[$n['parinte']]['copii'][] = &$noduri[$id];
            } else {
                $arbore[] = &$noduri[$id];
            }
        }
        $this->meniu = json_decode((string) json_encode($arbore), true) ?: [];
    }

    private function urlElement(Inventar $i, Context $ctx, array $e): ?string
    {
        if ($e['tip'] === 'post_type') {
            return $ctx->adrese->postare((int) $e['obiect_id']);
        }
        if ($e['tip'] === 'custom') {
            return $ctx->adrese->rescrie((string) $e['url']);
        }
        return null;
    }

    private function planificaDesign(Context $ctx): void
    {
        foreach ($ctx->convertoare() as $c) {
            foreach ($c->design($ctx) as $k => $v) {
                $this->design[$k] ??= $v;
            }
        }
        if ($this->design === []) {
            $this->avertismente[] = 'antetul și subsolul nu vin din WordPress: rămân cele ale temei și ale setărilor Design site (meniul vine din WordPress)';
        }
    }

    private function planificaSetari(Inventar $i, Context $ctx): void
    {
        $s = ['store_name' => (string) $i->site['nume']];
        $poza = static function (int $id) use ($i, $ctx): string {
            $a = $i->atasamente[$id] ?? null;
            if ($a === null || $a['fisier'] === '') {
                return '';
            }
            $ctx->fisier($a['fisier']);
            return Adrese::media($a['fisier']);
        };
        if ((int) $i->site['iconita'] > 0 && ($fav = $poza((int) $i->site['iconita'])) !== '') {
            $s['store_favicon_url'] = $fav;
        }
        $logo = (int) $i->site['logo_atasament'] > 0 ? $poza((int) $i->site['logo_atasament']) : '';
        if ($logo === '' && (string) $i->site['logo_url'] !== '') {
            $rel = $ctx->adrese->fisierUploads((string) $i->site['logo_url']);
            if ($rel !== null) {
                $ctx->fisier($rel);
                $logo = Adrese::media($rel);
            }
        }
        if ($logo !== '') {
            $s['store_logo_url'] = $logo;
            $s['email_logo_url'] = $logo;
        }
        $confidentialitate = (int) $i->site['pagina_confidentialitate'];
        if ($confidentialitate > 0 && ($cale = $ctx->adrese->postare($confidentialitate)) !== null) {
            $s['cookie_banner_policy_url'] = $cale;
        }
        foreach ($i->urmarire as $k => $v) {
            if ($v === '') {
                continue;
            }
            $s[$k] = $v;
            // Corectura 1.7: pe drumul capturat doar ID-urile (GA, GTM, Clarity), fără fanioanele de pornire: nucleul nu pune
            // niciun tracker în pagini; trackerele pornesc doar după acordul vizitatorului (pasul 9).
            if ($ctx->capturat()) {
                continue;
            }
            if ($k === 'google_analytics_id') {
                $s['google_analytics_enabled'] = '1';
            } elseif ($k === 'microsoft_clarity_project_id') {
                $s['microsoft_clarity_enabled'] = '1';
            }
        }
        if ($ctx->capturat()) {
            // Bannerul de consimțământ e cel capturat (al site-ului); bannerul nucleului e oprit.
            $s['cookie_banner_enabled'] = '0';
        }
        foreach ((array) ($ctx->reguli['setari'] ?? []) as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z0-9_]{2,120}$/', $k) === 1 && is_scalar($v)) {
                $s[$k] = (string) $v;
            }
        }
        $this->setari = $s;
    }

    private function verificaMedia(Inventar $i): void
    {
        $lipsaPoze = [];
        foreach ($this->media['lipsa'] as $rel) {
            $a = $i->atasamentDupaFisier($rel);
            if ($a === null || $a['fisier'] === $rel) {
                $lipsaPoze[] = $rel;
            }
        }
        if ($lipsaPoze !== []) {
            $this->avertismente[] = count($lipsaPoze) . ' fișiere pomenite (atașamente sau din conținut) lipsesc din wp-content/uploads: '
                . implode(', ', array_slice($lipsaPoze, 0, 5)) . (count($lipsaPoze) > 5 ? ', …' : '');
        }
        $marimiLipsa = count($this->media['lipsa']) - count($lipsaPoze);
        if ($marimiLipsa > 0) {
            $this->info[] = $marimiLipsa . ' mărimi ale pozelor (făcute de WordPress) nu mai sunt pe disc; se folosesc celelalte';
        }
        if ($this->media['sarite'] !== []) {
            $this->info[] = count($this->media['sarite']) . ' fișiere sărite (' . implode(', ', array_slice($this->media['sarite'], 0, 3)) . (count($this->media['sarite']) > 3 ? ', …' : '') . ')';
        }
    }

    /** Planul ca date simple (raportul JSON), fără conținutul paginilor (doar mărimea lui). @return array<string, mixed> */
    public function raport(): array
    {
        $pagini = array_map(static function (array $p): array {
            $p['html'] = strlen((string) ($p['html'] ?? '')) . ' octeți';
            $p['css'] = strlen((string) ($p['css'] ?? '')) . ' octeți';
            $p['js'] = strlen((string) ($p['js'] ?? '')) . ' octeți';
            return $p;
        }, $this->pagini);
        return ['pagini' => $pagini, 'neimportate' => $this->neimportate, 'redirecturi' => $this->redirecturi, 'setari' => $this->setari,
            'harta_wp' => count($this->hartaWp) . ' rânduri', 'conflicte' => $this->conflicte,
            'meniu' => $this->meniu, 'design' => array_map(static fn (string $v): string => strlen($v) . ' octeți', $this->design),
            'media' => ['fisiere' => count($this->media['fisiere']), 'octeti' => $this->media['octeti'], 'lipsa' => $this->media['lipsa'], 'sarite' => $this->media['sarite']],
            'probleme' => $this->probleme, 'avertismente' => $this->avertismente, 'info' => $this->info, 'capturat' => $this->capturata?->raport()];
    }
}
