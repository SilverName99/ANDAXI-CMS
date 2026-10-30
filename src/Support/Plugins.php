<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Ce pluginuri are site-ul și în ce stare, pentru tot codul.
 *
 * Stările vin din licența semnată de CRM (`Licenta`), peste catalogul din
 * `config/plugins.php`:
 *  - pornit:     totul merge normal;
 *  - se_opreste: nu începe nimic nou (checkout-ul se închide, plățile noi nu mai
 *                pornesc), dar se termină tot ce e în curs (comenzi, rambursări,
 *                AWB-uri, plăți); meniurile și paginile pentru asta rămân;
 *  - oprit:      meniurile, paginile, cronurile pluginului dispar. Datele NU se
 *                șterg; la repornire totul revine.
 *
 * Întrebările din cod:
 *  - `activ($k)` / `permiteInceput($k)`: pornit (poate începe ceva nou: o comandă, o plată);
 *  - `vizibil($k)`: pornit sau se_opreste (meniuri și pagini, ca să se termine ce e în curs);
 *  - `stare($k)`: starea, ca text.
 * Nucleul („nucleu”) e mereu pornit.
 *
 * Reguli:
 *  - cheie lipsă din licență = oprit; cheie necunoscută în licență = ignorată;
 *  - un plugin nu poate fi mai „pornit” decât pluginurile de care are nevoie:
 *    dacă una e oprită, e tratat ca oprit; dacă una se oprește, se oprește și el
 *    (altfel livrarea FAN ar dispărea exact când magazinul își termină comenzile);
 *  - nicio licență nu se verifică acum (cheie greșită în .env, licență ștearsă),
 *    dar site-ul a avut una: merge mai departe cu ultima licență aplicată
 *    (`stare-aplicata.json`), ca o greșeală de configurare să nu închidă
 *    magazinul și cronurile care termină plățile; în admin apare o notă neutră;
 *  - nicio licență, niciodată: doar nucleul, iar în admin apare „Licența lipsește”.
 * Orice problemă (licență respinsă, dependență lipsă) se scrie o singură dată în
 * `storage/logs/pluginuri.log`, nu la fiecare cerere. Când stările se schimbă,
 * se golește memoria rapidă a paginilor; în plus, amprenta stărilor face parte
 * din cheia ei, deci o pagină salvată sub alte stări nu se mai servește.
 */
final class Plugins
{
    public const PORNIT = 'pornit';
    public const SE_OPRESTE = 'se_opreste';
    public const OPRIT = 'oprit';
    public const NUCLEU = 'nucleu';
    private const FISIER_HARTA_DE_REFACUT = 'harta-site-de-refacut';
    public const FISIER_STARE = 'stare-aplicata.json';
    /** Semnul pus de filtru în locul unui element scos (ESC: nu apare în HTML). */
    private const SCOS = "\x1B";

    private const RANG = [self::OPRIT => 0, self::SE_OPRESTE => 1, self::PORNIT => 2];

    /** De unde vin stările: licența verificată, ultima licență aplicată (fără una verificabilă acum) sau doar nucleul. */
    private const DIN_LICENTA = 'licenta';
    private const DIN_ULTIMA_APLICATA = 'ultima_aplicata';
    private const DOAR_NUCLEU = 'nucleu';

    private static ?array $catalog = null;
    /** @var array<string, string>|null */
    private static ?array $stari = null;
    private static ?array $licenta = null;
    private static string $sursa = self::DOAR_NUCLEU;
    private static string $amprenta = '';
    /** @var array<string, string> pluginul => momentul (ISO) în care a intrat în „se oprește” */
    private static array $seOpresteDin = [];
    private static bool $stareNesalvata = false;
    private static array $mesaje = [];
    private static ?string $jurnal = null;
    /** @var array<string, array> */
    private static array $declaratiiRute = [];
    private static ?array $listaDeclaratii = null;
    private static ?array $impartite = null;
    private static ?bool $nimicAscuns = null;
    /** @var array<string, bool> */
    private static array $adreseVizibile = [];
    private static ?\App\Http\Router $router = null;
    private static ?string $gazda = null;

    // ---------------------------------------------------------------- stări

    public static function stare(string $cheie): string
    {
        if ($cheie === self::NUCLEU) {
            return self::PORNIT;
        }
        $stari = self::stari();
        if (!array_key_exists($cheie, $stari)) {
            self::jurnalizeazaOData('Codul a întrebat de pluginul „' . $cheie . '”, care nu există în catalog (tratat ca oprit).');
            return self::OPRIT;
        }
        return $stari[$cheie];
    }

    /** Pornit: totul merge, inclusiv lucruri noi. */
    public static function activ(string $cheie): bool
    {
        return self::stare($cheie) === self::PORNIT;
    }

    /**
     * Se poate începe ceva nou (o comandă, o plată, o donație)? Doar cu pluginul
     * pornit; în „se oprește” se termină doar ce e deja început.
     */
    public static function permiteInceput(string $cheie): bool
    {
        return self::stare($cheie) === self::PORNIT;
    }

    /** Pornit sau se oprește: meniurile și paginile lui se văd (ca să se termine ce e în curs). */
    public static function vizibil(string $cheie): bool
    {
        return self::stare($cheie) !== self::OPRIT;
    }

    /** @param list<string> $chei */
    public static function oricareVizibil(array $chei): bool
    {
        foreach ($chei as $cheie) {
            if (self::vizibil((string) $cheie)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, string> starea efectivă a fiecărui plugin din catalog */
    public static function stari(): array
    {
        if (self::$stari === null) {
            self::incarca();
        }
        return self::$stari ?? [];
    }

    /** Site-ul n-a avut niciodată o licență bună: merge doar cu nucleul. */
    public static function licentaLipseste(): bool
    {
        self::stari();
        return self::$sursa === self::DOAR_NUCLEU;
    }

    /**
     * Nicio licență nu se verifică acum, dar site-ul merge mai departe cu ultima
     * licență aplicată (vezi regulile de sus).
     */
    public static function licentaNeverificata(): bool
    {
        self::stari();
        return self::$sursa === self::DIN_ULTIMA_APLICATA;
    }

    /**
     * Nota pentru admin când licența are o problemă pe server (nu spune nimic
     * despre pluginuri), sau null. „Licența lipsește” are nota ei, separată.
     */
    public static function notaLicentaAdmin(): ?string
    {
        self::stari();
        if (self::$sursa === self::DIN_ULTIMA_APLICATA) {
            return 'Licența site-ului nu a putut fi verificată. Site-ul funcționează în continuare ca până acum. '
                . 'Vă rugăm să contactați ANDAXI WEB SOLUTIONS.';
        }
        if (self::$stareNesalvata) {
            return 'Licența site-ului nu poate fi salvată pe server (drepturi de scriere). Vă rugăm să contactați ANDAXI WEB SOLUTIONS.';
        }
        return null;
    }

    /** Licența folosită acum (conținutul verificat, sau ultima aplicată) sau null. */
    public static function licenta(): ?array
    {
        self::stari();
        return self::$licenta;
    }

    /**
     * Pluginul e în „se oprește” chiar în licență (nu doar pentru că se oprește
     * un plugin de care are nevoie)? Contează la ERP: dacă se oprește doar
     * magazinul, checkout-ul e închis și tot ce e pe site s-a plasat înainte.
     */
    public static function seOprestePrinLicenta(string $cheie): bool
    {
        return (self::licenta()['pluginuri'][$cheie] ?? '') === self::SE_OPRESTE;
    }

    /**
     * Momentul (timestamp) din care pluginul e în „se oprește”, ca să se știe ce
     * a început înainte (se termină) și ce după (nu mai pornește); null dacă nu e
     * în „se oprește”. Fără stare salvată (dosarul licenței nu se poate scrie):
     * data emiterii licenței care a cerut oprirea.
     */
    public static function seOpresteDin(string $cheie): ?int
    {
        if (self::stare($cheie) !== self::SE_OPRESTE) {
            return null;
        }
        $text = (string) (self::$seOpresteDin[$cheie] ?? '');
        $moment = $text !== '' ? strtotime($text) : false;
        if ($moment !== false) {
            return $moment;
        }
        $emisa = strtotime((string) (self::$licenta['emisa_la'] ?? ''));
        return $emisa !== false ? $emisa : time();
    }

    /**
     * Amprenta stărilor (stări efective, stările din licență, redirecționări):
     * se schimbă exact când se schimbă ce vede site-ul. Intră în cheia memoriei
     * rapide a paginilor.
     */
    public static function amprenta(): string
    {
        self::stari();
        return self::$amprenta;
    }

    /** Unde duc adresele publice ale pluginului când e oprit (din licență), sau null = pagina 404. */
    public static function redirectionare(string $cheie): ?string
    {
        $cale = self::licenta()['redirectionari'][$cheie] ?? null;
        return is_string($cale) && $cale !== '' ? $cale : null;
    }

    /**
     * Citește licența și calculează stările. Se cheamă singur la prima întrebare;
     * `public/index.php` îl cheamă devreme, ca o schimbare de stare să golească
     * memoria rapidă a paginilor înainte să fie servită o pagină din ea.
     */
    public static function incarca(): void
    {
        if (self::$stari !== null) {
            return;
        }
        $rezultat = Licenta::incarca();
        $licenta = $rezultat['licenta'];
        $mesaje = $rezultat['probleme'];
        $salvata = self::stareSalvata();
        self::$sursa = self::DIN_LICENTA;
        if ($licenta === null) {
            $ultima = $salvata['licenta'] ?? null;
            if (is_array($ultima) && is_array($ultima['pluginuri'] ?? null)) {
                // Site-ul a avut o licență bună, dar acum nu se verifică niciuna (cheia
                // sau site-ul din .env greșite, sodium lipsă, fișierele șterse): merge
                // mai departe cu ultima aplicată. Altfel magazinul ar da 404, iar
                // cronurile care termină plățile (BT, ERP, FAN) s-ar opri pe loc.
                $licenta = $ultima;
                self::$sursa = self::DIN_ULTIMA_APLICATA;
                $mesaje[] = 'Nicio licență nu se poate verifica acum: site-ul merge mai departe cu ultima licență aplicată ('
                    . (string) ($ultima['site'] ?? '?') . ' v' . (int) ($ultima['versiune'] ?? 0)
                    . '). Verifică LICENTA_CHEIE_PUBLICA, LICENTA_SITE și fișierele din ' . Licenta::dosar() . '.';
            } else {
                self::$sursa = self::DOAR_NUCLEU;
                $mesaje[] = 'Nicio licență bună: site-ul merge doar cu nucleul (în admin apare „Licența lipsește”).';
            }
        }
        self::$licenta = $licenta;
        $calcul = self::calculeazaStari((array) ($licenta['pluginuri'] ?? []));
        self::$stari = $calcul['stari'];
        self::$mesaje = array_merge($mesaje, $calcul['mesaje']);
        self::noteazaSchimbarile($salvata);
    }

    /**
     * Stările efective pentru stările din licență, după regulile de mai sus.
     * Funcție pură (fără fișiere), folosită și de teste.
     *
     * @param array<string, mixed> $dinLicenta
     * @return array{stari: array<string, string>, mesaje: list<string>}
     */
    public static function calculeazaStari(array $dinLicenta): array
    {
        $pluginuri = self::catalog()['pluginuri'];
        $mesaje = [];
        $brute = [];
        foreach ($pluginuri as $cheie => $definitie) {
            $stare = $dinLicenta[$cheie] ?? self::OPRIT;
            if (!is_string($stare) || !isset(self::RANG[$stare])) {
                $mesaje[] = 'Licența dă pluginului „' . $cheie . '” starea necunoscută „' . (is_string($stare) ? $stare : gettype($stare))
                    . '”; îl tratez ca oprit.';
                $stare = self::OPRIT;
            }
            $brute[$cheie] = $stare;
        }
        $necunoscute = array_diff(array_keys($dinLicenta), array_keys($pluginuri));
        if ($necunoscute !== []) {
            sort($necunoscute);
            $mesaje[] = 'Licența are chei pe care codul nu le cunoaște (ignorate): ' . implode(', ', $necunoscute) . '.';
        }

        $efective = [];
        $calculeaza = static function (string $cheie, array $drum = []) use (&$calculeaza, &$efective, &$mesaje, $brute, $pluginuri): string {
            if (isset($efective[$cheie])) {
                return $efective[$cheie];
            }
            if (in_array($cheie, $drum, true)) {
                return self::OPRIT; // dependență circulară: verifica-rute.php o prinde
            }
            $drum[] = $cheie;
            $stare = $brute[$cheie] ?? self::OPRIT;
            foreach ((array) ($pluginuri[$cheie]['necesita'] ?? []) as $nevoie) {
                if ($stare === self::OPRIT) {
                    break;
                }
                $variante = is_array($nevoie) ? $nevoie : [$nevoie];
                $cea = self::OPRIT;
                foreach ($variante as $varianta) {
                    $s = $calculeaza((string) $varianta, $drum);
                    if (self::RANG[$s] > self::RANG[$cea]) {
                        $cea = $s;
                    }
                }
                if (self::RANG[$cea] < self::RANG[$stare]) {
                    $nume = implode(' sau ', array_map('strval', $variante));
                    $mesaje[] = 'Pluginul „' . $cheie . '” e „' . $stare . '” în licență, dar are nevoie de ' . $nume
                        . ' („' . $cea . '”): îl tratez ca „' . $cea . '”.';
                    $stare = $cea;
                }
            }
            $efective[$cheie] = $stare;
            return $stare;
        };
        foreach (array_keys($pluginuri) as $cheie) {
            $calculeaza((string) $cheie);
        }
        // Ordinea din catalog, nu ordinea calculului.
        $ordonate = [];
        foreach (array_keys($pluginuri) as $cheie) {
            $ordonate[$cheie] = $efective[$cheie];
        }
        return ['stari' => $ordonate, 'mesaje' => $mesaje];
    }

    // ---------------------------------------------------------------- catalog

    public static function catalog(): array
    {
        if (self::$catalog === null) {
            self::$catalog = require __DIR__ . '/../../config/plugins.php';
        }
        return self::$catalog;
    }

    /** @return list<string> cheile pluginurilor, în ordinea din catalog */
    public static function chei(): array
    {
        return array_keys(self::catalog()['pluginuri']);
    }

    public static function definitie(string $cheie): ?array
    {
        if ($cheie === self::NUCLEU) {
            return self::catalog()['nucleu'];
        }
        return self::catalog()['pluginuri'][$cheie] ?? null;
    }

    /**
     * Poate rula acum cronul acesta? Fiecare script programat e al unui plugin
     * (în catalog, „cron”); scripturile nucleului nu sunt acolo și rulează mereu.
     */
    public static function cronPermis(string $fisierScript): bool
    {
        $nume = 'scripts/' . basename($fisierScript);
        foreach (self::catalog()['pluginuri'] as $cheie => $definitie) {
            $cerinta = $definitie['cron'][$nume] ?? null;
            if ($cerinta === null) {
                continue;
            }
            return $cerinta === self::PORNIT ? self::permiteInceput((string) $cheie) : self::vizibil((string) $cheie);
        }
        return true;
    }

    /**
     * Ce scrie și cu ce cod iese un cron care nu are voie să ruleze: 0 când
     * licența îl oprește (o alegere), 2 când site-ul nu are nicio licență (o
     * problemă de rezolvat: monitorizarea cronurilor trebuie s-o vadă).
     *
     * @return array{0: string, 1: int}
     */
    public static function cronOprit(string $fisierScript): array
    {
        $plugin = (string) self::pluginCron($fisierScript);
        if (self::licentaLipseste()) {
            return ['Licența site-ului lipsește: pluginul „' . $plugin . '” nu rulează (site-ul merge doar cu nucleul). '
                . 'Vezi storage/logs/pluginuri.log.', 2];
        }
        return ['Pluginul „' . $plugin . '” nu este pornit pe acest site; nu fac nimic.', 0];
    }

    /** Pluginul căruia îi aparține cronul (pentru mesaje), sau null. */
    public static function pluginCron(string $fisierScript): ?string
    {
        $nume = 'scripts/' . basename($fisierScript);
        foreach (self::catalog()['pluginuri'] as $cheie => $definitie) {
            if (isset($definitie['cron'][$nume])) {
                return (string) $cheie;
            }
        }
        return null;
    }

    /**
     * Meniul adminului, construit din catalog, doar cu ce se vede pe acest site.
     * Permisiunile rolurilor (Auth) le aplică șablonul, peste.
     *
     * @return list<array{cheie:string, tip:string, eticheta:string, icon:string, deschis:array, intrari:list<array>}>
     */
    public static function meniuAdmin(): array
    {
        $catalog = self::catalog();
        $intrari = [];
        $ordine = 0;
        $adauga = static function (array $lista, string $proprietar) use (&$intrari, &$ordine): void {
            foreach ($lista as $intrare) {
                $intrare['plugin'] = $proprietar;
                $intrare['_pozitie'] = $ordine++;
                $intrari[] = $intrare;
            }
        };
        $adauga((array) ($catalog['nucleu']['meniu'] ?? []), self::NUCLEU);
        foreach ($catalog['pluginuri'] as $cheie => $definitie) {
            $adauga((array) ($definitie['meniu'] ?? []), (string) $cheie);
        }

        $sloturi = [];
        foreach ($catalog['meniu_admin'] as $slot) {
            $alese = [];
            foreach ($intrari as $intrare) {
                if (($intrare['grup'] ?? '') !== $slot['cheie']) {
                    continue;
                }
                $proprietari = isset($intrare['oricare_din']) ? (array) $intrare['oricare_din'] : [$intrare['plugin']];
                if (!self::oricareVizibil($proprietari)) {
                    continue;
                }
                // Eticheta sau iconița depind de un plugin („Setări magazin” → „Setări site”,
                // coșul de lângă „Trimite email test” → plicul, fără magazin).
                foreach (['eticheta_fara' => 'eticheta', 'icon_fara' => 'icon'] as $cheieFara => $camp) {
                    foreach ((array) ($intrare[$cheieFara] ?? []) as $pluginLipsa => $altaValoare) {
                        if (!self::vizibil((string) $pluginLipsa)) {
                            $intrare[$camp] = (string) $altaValoare;
                        }
                    }
                }
                $alese[] = $intrare;
            }
            usort($alese, static fn (array $a, array $b): int
                => [(int) ($a['ordine'] ?? 0), $a['_pozitie']] <=> [(int) ($b['ordine'] ?? 0), $b['_pozitie']]);
            // Și eticheta sau iconița unui grup pot depinde de un plugin (WP-26, GR-14c: „Magazin” → „Produse” fără magazin).
            foreach (['eticheta_fara' => 'eticheta', 'icon_fara' => 'icon'] as $cheieFara => $camp) {
                foreach ((array) ($slot[$cheieFara] ?? []) as $pluginLipsa => $altaValoare) {
                    if (!self::vizibil((string) $pluginLipsa)) {
                        $slot[$camp] = (string) $altaValoare;
                    }
                }
            }
            $sloturi[] = [
                'cheie' => (string) $slot['cheie'],
                'tip' => (string) ($slot['tip'] ?? 'grup'),
                'eticheta' => (string) ($slot['eticheta'] ?? ''),
                'icon' => (string) ($slot['icon'] ?? ''),
                'deschis' => (array) ($slot['deschis'] ?? []),
                'intrari' => $alese,
            ];
        }
        return $sloturi;
    }

    /**
     * E activă intrarea din meniu pe pagina curentă? Regula din catalog e o
     * listă de prefixe sau o funcție (prefix-checker, cale, tab).
     */
    public static function intrareActiva(mixed $regula, callable $prefix, string $cale, ?string $tab): bool
    {
        if ($regula instanceof Closure) {
            return (bool) $regula($prefix, $cale, $tab);
        }
        return is_array($regula) && $prefix($regula);
    }

    // ---------------------------------------------------------------- rute

    /**
     * Al cui e o rută: nucleu sau plugin(uri). O listă cu mai multe chei
     * înseamnă „se vede dacă oricare e vizibil”.
     *
     * @param array<string, string> $parametri parametrii rutei (ex. slug, section)
     * @return array{chei: list<string>, mereu_deschis: bool, explicita: bool, declaratie: string}
     *   explicita = false: nicio declarație nu acoperă ruta (verifica-rute.php o raportează;
     *   la rulare e tratată ca nucleu, ca să nu dispară nimic din greșeală)
     */
    public static function proprietarRuta(string $metoda, string $tipar, array $parametri = [], array $get = [], array $post = []): array
    {
        $metoda = strtoupper($metoda);
        $impartita = self::impartitaNormalizata($metoda, $tipar);
        if ($impartita !== null) {
            foreach ($impartita as [$conditii, $tinta]) {
                if (self::conditiiIndeplinite((array) $conditii, $parametri, $get, $post)) {
                    return [
                        'chei' => array_values(array_map('strval', (array) $tinta)),
                        'mereu_deschis' => false,
                        'explicita' => true,
                        'declaratie' => 'rute_impartite: ' . $metoda . ' ' . $tipar,
                    ];
                }
            }
        }

        $castigatoare = self::declaratiiPentru($metoda, $tipar)[0] ?? null;
        if ($castigatoare === null) {
            return ['chei' => [self::NUCLEU], 'mereu_deschis' => false, 'explicita' => false, 'declaratie' => ''];
        }
        $chei = [$castigatoare['proprietar']];

        // Paginile din baza de date care sunt ale unui plugin (ex. „acorduri-gdpr”).
        if (self::normalizeazaTipar($tipar) === '/{*}' && isset($parametri['slug'])) {
            $pagina = self::proprietarPagina((string) $parametri['slug']);
            if ($pagina !== null) {
                return ['chei' => [$pagina], 'mereu_deschis' => false, 'explicita' => true, 'declaratie' => 'pagini: ' . $parametri['slug']];
            }
        }

        return [
            'chei' => $chei,
            'mereu_deschis' => $castigatoare['mereu_deschis'],
            'explicita' => true,
            'declaratie' => $castigatoare['sursa'],
        ];
    }

    /**
     * Pluginul care are pagina din baza de date cu acest slug, sau null (= nucleu):
     * paginile declarate în catalog („pagini”) și paginile care stau la adresa
     * unei rute de plugin („magazin”, „cos”, „checkout”, „contul-meu”, „blog”…).
     * Contează la adrese scrise altfel decât ruta (/MAGAZIN, /Cos), pe care
     * routerul le trimite la pagina generală, iar baza le găsește oricum.
     */
    public static function proprietarPagina(string $slug): ?string
    {
        $slug = strtolower(trim($slug, "/ \t\n"));
        if ($slug === '') {
            return null;
        }
        foreach (self::catalog()['pluginuri'] as $cheie => $definitie) {
            foreach ((array) ($definitie['pagini'] ?? []) as $pagina) {
                if (strtolower((string) $pagina) === $slug) {
                    return (string) $cheie;
                }
            }
        }
        $ruta = self::declaratiiPentru('GET', '/' . $slug)[0] ?? null;
        return $ruta !== null && $ruta['proprietar'] !== self::NUCLEU ? $ruta['proprietar'] : null;
    }

    /**
     * Toate declarațiile din catalog care acoperă ruta, de la cea mai precisă.
     * Prima e cea care contează; dacă a doua are aceeași precizie și alt
     * proprietar, declarația e ambiguă (verifica-rute.php o raportează).
     *
     * @return list<array{proprietar:string, mereu_deschis:bool, scor:int, sursa:string}>
     */
    public static function declaratiiPentru(string $metoda, string $tipar): array
    {
        $metoda = strtoupper($metoda);
        $normalizat = self::normalizeazaTipar($tipar);
        $cheieMemo = $metoda . ' ' . $normalizat;
        if (isset(self::$declaratiiRute[$cheieMemo])) {
            return self::$declaratiiRute[$cheieMemo];
        }
        $potriviri = [];
        foreach (self::listaDeclaratii() as $declaratie) {
            if ($declaratie['metoda'] !== null && $declaratie['metoda'] !== $metoda) {
                continue;
            }
            $baza = $declaratie['baza'];
            $sePotriveste = $declaratie['prefix']
                ? ($normalizat === $baza || str_starts_with($normalizat, rtrim($baza, '/') . '/'))
                : $normalizat === $baza;
            if (!$sePotriveste) {
                continue;
            }
            $potriviri[] = [
                'proprietar' => $declaratie['proprietar'],
                'mereu_deschis' => $declaratie['mereu_deschis'],
                'scor' => ($declaratie['prefix'] ? 0 : 2000000) + ($declaratie['metoda'] !== null ? 1000000 : 0) + strlen($baza),
                'sursa' => $declaratie['sursa'],
            ];
        }
        usort($potriviri, static fn (array $a, array $b): int => $b['scor'] <=> $a['scor']);
        return self::$declaratiiRute[$cheieMemo] = $potriviri;
    }

    /**
     * Toate declarațiile de rute din catalog, desfăcute.
     *
     * @return list<array{proprietar:string, metoda:?string, baza:string, prefix:bool, mereu_deschis:bool, sursa:string}>
     */
    public static function listaDeclaratii(): array
    {
        if (self::$listaDeclaratii !== null) {
            return self::$listaDeclaratii;
        }
        $catalog = self::catalog();
        $surse = [self::NUCLEU => $catalog['nucleu']] + $catalog['pluginuri'];
        $lista = [];
        foreach ($surse as $proprietar => $definitie) {
            foreach (['rute_admin', 'rute_publice', 'rute_mereu_deschise'] as $camp) {
                foreach ((array) ($definitie[$camp] ?? []) as $text) {
                    $text = trim((string) $text);
                    $metoda = null;
                    if (preg_match('#^(GET|POST)\s+(/.*)$#', $text, $m) === 1) {
                        $metoda = $m[1];
                        $text = $m[2];
                    }
                    $prefix = str_ends_with($text, '/*');
                    $baza = $prefix ? substr($text, 0, -2) : $text;
                    $lista[] = [
                        'proprietar' => (string) $proprietar,
                        'metoda' => $metoda,
                        'baza' => self::normalizeazaTipar($baza === '' ? '/' : $baza),
                        'prefix' => $prefix,
                        'mereu_deschis' => $camp === 'rute_mereu_deschise',
                        'sursa' => $proprietar . '.' . $camp . ': ' . ($metoda !== null ? $metoda . ' ' : '') . $text . ($prefix ? '/*' : ''),
                    ];
                }
            }
        }
        return self::$listaDeclaratii = $lista;
    }

    /** `/admin/orders/{id}/bt/{actiune}` → `/admin/orders/{}/bt/{}`; `{slug*}` → `{*}`. */
    public static function normalizeazaTipar(string $tipar): string
    {
        $tipar = (string) preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\*\}#', '{*}', $tipar);
        return (string) preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '{}', $tipar);
    }

    // ---------------------------------------------------------------- pagini: linkuri și tokenuri

    /**
     * Toate pluginurile care au cod sunt pe site (pornite sau „se oprește”)?
     * Atunci nu e nimic de ascuns în pagini: filtrele de mai jos nu se ating de
     * HTML (cu licența „toate” sau cu licența Bioscem, paginile ies exact ca
     * înainte de pluginuri). Uneltele interne („intern”, de exemplu migrarea din
     * WordPress) nu contează: n-au linkuri sau coduri în pagini, doar o rută de
     * admin (oprită de gardă) și un formular ascuns în șablonul lui.
     */
    public static function nimicAscuns(): bool
    {
        if (self::$nimicAscuns === null) {
            self::$nimicAscuns = true;
            foreach (self::catalog()['pluginuri'] as $cheie => $definitie) {
                if (($definitie['portat'] ?? true) !== false && ($definitie['intern'] ?? false) !== true
                    && !self::vizibil((string) $cheie)) {
                    self::$nimicAscuns = false;
                    break;
                }
            }
            // WP-24: paginile doar pentru membri, fără pluginul `membri`, sunt ascunse (linkurile spre ele se scot).
            if (self::$nimicAscuns && !self::vizibil('membri') && AccesMembri::areCaiRestrictionate()) {
                self::$nimicAscuns = false;
            }
        }
        return self::$nimicAscuns;
    }

    /**
     * Duce adresa (dintr-un link, dintr-un formular, din harta site-ului) la o
     * pagină a unui plugin pe care site-ul nu-l are? Adresele din afara
     * site-ului, ancorele, fișierele și adresele necunoscute de router sunt
     * mereu „vizibile”: nu e treaba noastră să le ascundem.
     */
    public static function adresaVizibila(string $url, string $metoda = 'GET'): bool
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || $url[0] === '#' || $url[0] === '?' || preg_match('#^(mailto|tel|javascript|data):#i', $url) === 1) {
            return true;
        }
        $bucati = parse_url($url);
        if (!is_array($bucati)) {
            return true;
        }
        if (isset($bucati['host'])) {
            if (strcasecmp((string) $bucati['host'], self::gazdaSite()) !== 0) {
                return true;
            }
        } elseif (isset($bucati['scheme'])) {
            return true;
        }
        $cale = (string) ($bucati['path'] ?? '/');
        if ($cale === '' || $cale[0] !== '/' || str_starts_with($cale, '//')
            || str_starts_with($cale, '/uploads/') || str_starts_with($cale, '/assets/')) {
            return true;
        }
        $get = [];
        if (isset($bucati['query'])) {
            parse_str((string) $bucati['query'], $get);
        }
        $cheieMemo = strtoupper($metoda) . ' ' . $cale . '?' . http_build_query($get);
        if (isset(self::$adreseVizibile[$cheieMemo])) {
            return self::$adreseVizibile[$cheieMemo];
        }
        // WP-24 (VT-X2): o pagină sau un articol doar pentru membri e al pluginului `membri`; fără el răspunde 404,
        // deci linkurile spre el pleacă din pagini ca ale oricărui plugin oprit (App\Support\AccesMembri).
        if (strtoupper($metoda) === 'GET' && !self::vizibil('membri') && AccesMembri::caleRestrictionata($cale)) {
            return self::$adreseVizibile[$cheieMemo] = false;
        }
        $gasita = self::routerSite()->gaseste(strtoupper($metoda), $cale);
        $vizibila = true;
        if ($gasita !== null) {
            $proprietar = self::proprietarRuta(strtoupper($metoda), $gasita['tipar'], $gasita['parametri'], $get, []);
            $vizibila = $proprietar['mereu_deschis'] || self::oricareVizibil($proprietar['chei']);
        }
        if (count(self::$adreseVizibile) > 2000) {
            self::$adreseVizibile = [];
        }
        return self::$adreseVizibile[$cheieMemo] = $vizibila;
    }

    /**
     * Scoate din HTML linkurile și formularele care duc la paginile pluginurilor
     * pe care site-ul nu le are: antetul, meniul și subsolul scrise de client în
     * admin, butoanele din paginile adminului, linkurile din conținut. Un `<li>`
     * rămas gol din cauza filtrului dispare cu tot cu el, la fel o listă rămasă
     * goală; listele și elementele goale scrise așa de client (de umplut din
     * JavaScript, separatoare de meniu) rămân neatinse.
     * Conținutul din `<script>`, `<style>` și `<textarea>` nu se atinge (se pune
     * deoparte și se pune la loc la final; un formular cu textarea se scoate tot).
     * Cu toate pluginurile pe site, HTML-ul se întoarce neschimbat; dacă PCRE
     * cedează pe o pagină uriașă, pagina se întoarce nefiltrată, niciodată goală.
     */
    public static function filtreazaLinkuri(string $html): string
    {
        if ($html === '' || self::nimicAscuns()) {
            return $html;
        }
        $deoparte = [];
        $mascat = preg_replace_callback(
            '#<(script|style|textarea)\b[^>]*>.*?</\1\s*>#is',
            static function (array $m) use (&$deoparte): string {
                $deoparte[] = $m[0];
                return "\x1A" . (count($deoparte) - 1) . "\x1A";
            },
            $html
        );
        if ($mascat === null) {
            self::jurnalizeazaOData('Filtrul de linkuri n-a putut citi o pagină (PCRE: ' . preg_last_error_msg() . '); a rămas nefiltrată.');
            return $html;
        }
        $filtrat = self::filtreazaFragment($mascat);
        $pusLaLoc = preg_replace_callback(
            '#\x1A(\d+)\x1A#',
            static fn (array $m): string => $deoparte[(int) $m[1]] ?? '',
            $filtrat
        );
        return $pusLaLoc ?? $html;
    }

    /**
     * Tokenurile din paginile din bază (`{{shop_catalog}}`, `{{latest_posts:3}}`
     * …) ale pluginurilor care nu sunt pe site se scot, ca pagina să nu
     * arate nici bucata pluginului, nici codul tokenului. Lista lor e în
     * catalog („tokenuri”). Un titlu pus de client chiar înaintea tokenului
     * (`<h2>Din blog</h2>{{latest_posts:3}}`, doar spații între ele) e titlul
     * bucății și pleacă odată cu ea; orice alt text din jur rămâne.
     */
    public static function curataTokenuri(string $html): string
    {
        if ($html === '' || !str_contains($html, '{{') || self::nimicAscuns()) {
            return $html;
        }
        $nume = [];
        foreach (self::catalog()['pluginuri'] as $cheie => $definitie) {
            if (!self::vizibil((string) $cheie)) {
                foreach ((array) ($definitie['tokenuri'] ?? []) as $token) {
                    $nume[] = preg_quote((string) $token, '/');
                }
            }
        }
        if ($nume === []) {
            return $html;
        }
        $titlu = '(?:<h([1-6])\b[^>]*>(?:(?!<\/?h[1-6]\b)[^{}]){0,300}?<\/h\1>\s*)?';
        return preg_replace('/' . $titlu . '\{\{\s*(?:' . implode('|', $nume) . ')\s*(?::[^}]*)?\}\}/i', '', $html) ?? $html;
    }

    /**
     * Tokenul (fără acolade: „shop_catalog”) e al unui plugin pe care site-ul
     * îl are, sau al nimănui? Folosit de lista de coduri din editorul de pagini.
     */
    public static function tokenVizibil(string $token): bool
    {
        foreach (self::catalog()['pluginuri'] as $cheie => $definitie) {
            if (in_array($token, (array) ($definitie['tokenuri'] ?? []), true)) {
                return self::vizibil((string) $cheie);
            }
        }
        return true;
    }

    /** HTML-ul fără scripturi/stiluri: formularele, linkurile și listele rămase goale din cauza lor. */
    private static function filtreazaFragment(string $html): string
    {
        if (stripos($html, 'href') === false && stripos($html, 'action') === false) {
            return $html;
        }
        // În locul fiecărui element scos rămâne un semn, ca la pasul 3 să se știe
        // ce a golit filtrul. O pagină care are deja caracterul (n-ar trebui) își
        // pierde doar linkurile, fără strângerea listelor.
        $semn = str_contains($html, self::SCOS) ? '' : self::SCOS;
        // 1. Formularele trimise la o rută a unui plugin ascuns (cu metoda formularului), întregi.
        $html = self::scoateElemente($html, 'form', static function (string $eticheta): bool {
            if (preg_match('#\baction\s*=\s*(["\'])(.*?)\1#is', $eticheta, $m) !== 1) {
                return false;
            }
            $metoda = preg_match('#\bmethod\s*=\s*["\']?post#i', $eticheta) === 1 ? 'POST' : 'GET';
            return !self::adresaVizibila($m[2], $metoda);
        }, $semn);
        // 2. Linkurile.
        $html = self::scoateElemente($html, 'a', static fn (string $eticheta): bool
            => preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#is', $eticheta, $m) === 1 && !self::adresaVizibila($m[2]), $semn);
        if ($semn === '' || !str_contains($html, $semn)) {
            return $html;
        }
        // 3. Elementele de listă și listele golite de filtru (un meniu sau submeniu
        //    fără nicio intrare): doar cele în care au rămas numai semne și spații.
        $golit = '\s*' . $semn . '[\s' . $semn . ']*';
        for ($pas = 0; $pas < 3; $pas++) {
            $inainte = $html;
            $html = preg_replace('#<li\b[^>]*>' . $golit . '</li>#i', $semn, $html) ?? $html;
            $html = preg_replace('#<(ul|ol)\b[^>]*>' . $golit . '</\1>#i', $semn, $html) ?? $html;
            if ($html === $inainte) {
                break;
            }
        }
        return str_replace($semn, '', $html);
    }

    /**
     * Scoate elementele `<$nume …>…</$nume>` pentru care $deScos(eticheta de
     * deschidere) spune da și pune $inLoc în locul lor. Parcurge HTML-ul o
     * singură dată, fără expresii care să se întindă peste toată pagina (pe
     * pagini mari, PCRE ar ceda). Un element fără închidere pierde doar eticheta
     * de deschidere.
     */
    private static function scoateElemente(string $html, string $nume, callable $deScos, string $inLoc = ''): string
    {
        $deschidere = '#<' . $nume . '\b[^>]*>#i';
        $inchidere = '#</' . $nume . '\s*>#i';
        $rezultat = '';
        $pozitie = 0;
        while (preg_match($deschidere, $html, $m, PREG_OFFSET_CAPTURE, $pozitie) === 1) {
            [$eticheta, $inceput] = $m[0];
            $dupaEticheta = (int) $inceput + strlen((string) $eticheta);
            if (!$deScos((string) $eticheta)) {
                $rezultat .= substr($html, $pozitie, $dupaEticheta - $pozitie);
                $pozitie = $dupaEticheta;
                continue;
            }
            $rezultat .= substr($html, $pozitie, (int) $inceput - $pozitie) . $inLoc;
            if (preg_match($inchidere, $html, $i, PREG_OFFSET_CAPTURE, $dupaEticheta) === 1) {
                $pozitie = (int) $i[0][1] + strlen((string) $i[0][0]);
            } else {
                $pozitie = $dupaEticheta;
            }
        }
        return $rezultat . substr($html, $pozitie);
    }

    /** Routerul cu toate rutele site-ului (pentru adresele din linkuri), construit o dată. */
    private static function routerSite(): \App\Http\Router
    {
        if (self::$router === null) {
            self::$router = new \App\Http\Router();
            (require dirname(__DIR__) . '/Http/rute.php')(self::$router);
        }
        return self::$router;
    }

    /** Gazda site-ului (din APP_URL sau din cerere), ca linkurile absolute spre site să fie verificate și ele. */
    private static function gazdaSite(): string
    {
        if (self::$gazda === null) {
            $app = (string) Env::get('APP_URL', '');
            $gazda = (string) (parse_url($app, PHP_URL_HOST) ?: '');
            if ($gazda === '') {
                $gazda = (string) (parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: '');
            }
            self::$gazda = $gazda;
        }
        return self::$gazda;
    }

    // ---------------------------------------------------------------- jurnal

    /** O linie în `storage/logs/pluginuri.log`. */
    public static function jurnal(string $mesaj): void
    {
        @file_put_contents(self::fisierJurnal(), '[' . date('Y-m-d H:i:s') . '] ' . $mesaj . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * O linie în jurnal, cel mult o dată pe zi pentru aceeași cheie: cererile
     * repetate (pixelul unui newsletter vechi, un robot pe o adresă de confirmare)
     * nu umplu discul. Ce s-a notat azi stă în `pluginuri-azi.json`, lângă jurnal;
     * fără loc de scris, nu se notează nimic (mai bine tăcere decât o linie pe cerere).
     */
    public static function jurnalOdataPeZi(string $cheie, string $mesaj): void
    {
        $fisier = dirname(self::fisierJurnal()) . '/pluginuri-azi.json';
        $azi = date('Y-m-d');
        $notate = json_decode((string) @file_get_contents($fisier), true);
        if (!is_array($notate) || ($notate['zi'] ?? '') !== $azi || !is_array($notate['chei'] ?? null)) {
            $notate = ['zi' => $azi, 'chei' => []];
        }
        $amprenta = substr(sha1($cheie), 0, 16);
        if (isset($notate['chei'][$amprenta]) || count($notate['chei']) >= 500) {
            return;
        }
        $notate['chei'][$amprenta] = 1;
        if (Licenta::scrieAtomic($fisier, (string) json_encode($notate))) {
            self::jurnal($mesaj);
        }
    }

    private static function fisierJurnal(): string
    {
        return self::$jurnal ?? Paths::stocare('logs/pluginuri.log');
    }

    /** Doar pentru teste: unde scrie jurnalul. */
    public static function folosesteJurnal(?string $fisier): void
    {
        self::$jurnal = $fisier;
    }

    /**
     * Doar pentru teste și unelte (ca `Licenta::configureaza`): stările din licență date
     * direct, fără fișiere. Se aplică regula dependențelor, dar nu se citește și nu se
     * scrie nimic în dosarul licenței (nici `stare-aplicata.json`, nici jurnalul).
     * `reseteaza()` întoarce la licența de pe disc. Site-ul nu o cheamă niciodată.
     *
     * @param array<string, string> $stari pluginul => starea din licență (lipsă = oprit)
     */
    public static function folosesteStari(array $stari): void
    {
        self::reseteaza();
        $calcul = self::calculeazaStari($stari);
        $pluginuri = array_filter($stari, static fn ($stare): bool => $stare !== self::OPRIT);
        self::$stari = $calcul['stari'];
        self::$mesaje = $calcul['mesaje'];
        self::$licenta = ['site' => 'test', 'emisa_la' => '1970-01-01T00:00:00Z', 'versiune' => 0, 'pluginuri' => $pluginuri];
        self::$sursa = self::DIN_LICENTA;
        self::$amprenta = sha1((string) json_encode([self::$stari, [], $pluginuri]));
    }

    /** Uită tot ce s-a calculat (teste, unelte care schimbă licența în același proces). */
    public static function reseteaza(): void
    {
        self::$stari = null;
        self::$licenta = null;
        self::$sursa = self::DOAR_NUCLEU;
        self::$amprenta = '';
        self::$seOpresteDin = [];
        self::$stareNesalvata = false;
        self::$mesaje = [];
        self::$nimicAscuns = null;
        self::$adreseVizibile = [];
        Licenta::reseteaza();
    }

    /** Problemele găsite la ultima încărcare (licență respinsă, dependențe). */
    public static function mesaje(): array
    {
        self::stari();
        return self::$mesaje;
    }

    // ---------------------------------------------------------------- intern

    /**
     * Ce a aplicat site-ul ultima oară (`stare-aplicata.json`): amprentele, stările
     * efective, de când e fiecare plugin în „se oprește” și ultima licență bună
     * aplicată (conținutul verificat, fără semnătură). Gol dacă lipsește.
     */
    private static function stareSalvata(): array
    {
        $fisier = Licenta::dosar() . '/' . self::FISIER_STARE;
        $date = is_file($fisier) ? json_decode((string) @file_get_contents($fisier), true) : null;
        return is_array($date) ? $date : [];
    }

    /**
     * Ce s-a schimbat de la cererea precedentă: mesajele noi se scriu în jurnal
     * o singură dată, iar la o schimbare de stare se golește memoria paginilor.
     * Tot aici se ține minte de când e fiecare plugin în „se oprește” și ultima
     * licență aplicată (pentru cererile în care nicio licență nu se verifică).
     */
    private static function noteazaSchimbarile(array $vechi): void
    {
        $fisier = Licenta::dosar() . '/' . self::FISIER_STARE;
        $licenta = self::$licenta;
        self::$amprenta = sha1((string) json_encode([self::$stari, $licenta['redirectionari'] ?? [], $licenta['pluginuri'] ?? []]));
        $amprentaMesaje = sha1(implode("\n", self::$mesaje));
        self::$seOpresteDin = is_array($vechi['se_opreste_din'] ?? null) ? $vechi['se_opreste_din'] : [];
        if (($vechi['stari'] ?? '') === self::$amprenta && ($vechi['mesaje'] ?? '') === $amprentaMesaje) {
            return;
        }
        // De când e fiecare plugin în „se oprește”: momentul trecerii din altă stare;
        // cât rămâne în „se oprește”, momentul nu se mută.
        $efectiveVechi = is_array($vechi['efective'] ?? null) ? $vechi['efective'] : [];
        $acum = date('c');
        $seOpresteDin = [];
        foreach ((array) self::$stari as $cheie => $stare) {
            if ($stare === self::SE_OPRESTE) {
                $deDinainte = ($efectiveVechi[$cheie] ?? '') === self::SE_OPRESTE ? (string) (self::$seOpresteDin[$cheie] ?? '') : '';
                $seOpresteDin[$cheie] = $deDinainte !== '' ? $deDinainte : $acum;
            }
        }
        if (!is_dir(dirname($fisier))) {
            @mkdir(dirname($fisier), 0770, true);
        }
        $nou = [
            'stari' => self::$amprenta,
            'mesaje' => $amprentaMesaje,
            'la' => $acum,
            'efective' => self::$stari,
            'se_opreste_din' => $seOpresteDin,
            'licenta' => self::$sursa === self::DOAR_NUCLEU ? null : $licenta,
        ];
        if (!Licenta::scrieAtomic($fisier, (string) json_encode($nou, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
            // Fără loc de scris nu putem ține minte ce am notat. Problemele se scriu
            // în jurnal o dată pe zi (nu la fiecare cerere), adminul vede o notă,
            // iar memoria paginilor nu încurcă stările: amprenta e în cheia ei.
            self::$stareNesalvata = true;
            self::$seOpresteDin = [];
            $problema = 'Nu pot scrie ' . $fisier . ' (drepturi de scriere?): licența bună nu avansează, harta site-ului nu se '
                . 'reface singură, iar momentul intrării în „se oprește” se ia din data licenței.';
            self::jurnalOdataPeZi('stare-nescrisa', $problema);
            foreach (self::$mesaje as $mesaj) {
                self::jurnalOdataPeZi('mesaj|' . $mesaj, $mesaj);
            }
            return;
        }
        self::$seOpresteDin = $seOpresteDin;
        if (($vechi['mesaje'] ?? '') !== $amprentaMesaje) {
            foreach (self::$mesaje as $mesaj) {
                self::jurnal($mesaj);
            }
        }
        if (($vechi['stari'] ?? '') !== self::$amprenta) {
            $pornite = [];
            foreach (self::$stari as $cheie => $stare) {
                if ($stare !== self::OPRIT) {
                    $pornite[] = $cheie . ($stare === self::SE_OPRESTE ? ' (se oprește)' : '');
                }
            }
            self::jurnal('Stările pluginurilor s-au schimbat' . ($licenta !== null
                    ? ' (licența ' . $licenta['site'] . ' v' . $licenta['versiune']
                        . (self::$sursa === self::DIN_ULTIMA_APLICATA ? ', ultima aplicată' : '') . ')'
                    : ' (fără licență)')
                . ': ' . ($pornite === [] ? 'doar nucleul' : implode(', ', $pornite)) . '.');
            ResponseCache::purgePageCache();
            // Harta site-ului (fișierul static generat din admin) se reface la
            // prima cerere web: altfel ar trimite Google spre pagini oprite.
            @touch(Licenta::dosar() . '/' . self::FISIER_HARTA_DE_REFACUT);
        }
    }

    /** Stările s-au schimbat de la ultima generare a hărții site-ului? */
    public static function hartaSiteDeRefacut(): bool
    {
        self::stari();
        return is_file(Licenta::dosar() . '/' . self::FISIER_HARTA_DE_REFACUT);
    }

    public static function hartaSiteRefacuta(): void
    {
        @unlink(Licenta::dosar() . '/' . self::FISIER_HARTA_DE_REFACUT);
    }

    private static function jurnalizeazaOData(string $mesaj): void
    {
        if (!in_array($mesaj, self::$mesaje, true)) {
            self::$mesaje[] = $mesaj;
            self::jurnal($mesaj);
        }
    }

    /**
     * Regulile din „rute_impartite” pentru ruta asta, sau null.
     *
     * @return list<array{0: array, 1: string|list<string>}>|null
     */
    public static function impartitaNormalizata(string $metoda, string $tipar): ?array
    {
        if (self::$impartite === null) {
            self::$impartite = [];
            foreach (self::catalog()['rute_impartite'] as $cheie => $reguli) {
                [$m, $t] = array_pad(explode(' ', (string) $cheie, 2), 2, '');
                self::$impartite[strtoupper($m) . ' ' . self::normalizeazaTipar($t)] = $reguli;
            }
        }
        return self::$impartite[strtoupper($metoda) . ' ' . self::normalizeazaTipar($tipar)] ?? null;
    }

    private static function conditiiIndeplinite(array $conditii, array $parametri, array $get, array $post): bool
    {
        foreach ($conditii as $sursaNume => $valoriPermise) {
            [$sursa, $nume] = array_pad(explode('.', (string) $sursaNume, 2), 2, '');
            $valoare = match ($sursa) {
                'ruta' => $parametri[$nume] ?? '',
                'get' => $get[$nume] ?? '',
                'post' => $post[$nume] ?? '',
                default => '',
            };
            $valoare = is_scalar($valoare) ? trim((string) $valoare) : '';
            $potrivit = false;
            foreach ((array) $valoriPermise as $permis) {
                $permis = (string) $permis;
                if (str_ends_with($permis, '*') ? str_starts_with($valoare, substr($permis, 0, -1)) : $valoare === $permis) {
                    $potrivit = true;
                    break;
                }
            }
            if (!$potrivit) {
                return false;
            }
        }
        return true;
    }
}
