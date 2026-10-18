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
 *  - fără nicio licență bună, niciodată: doar nucleul, iar în admin apare „Licența lipsește”.
 * Orice problemă (licență respinsă, dependență lipsă) se scrie o singură dată în
 * `storage/logs/pluginuri.log`, nu la fiecare cerere. Când stările se schimbă,
 * se golește memoria rapidă a paginilor, ca nimeni să nu primească o pagină
 * salvată dinainte.
 */
final class Plugins
{
    public const PORNIT = 'pornit';
    public const SE_OPRESTE = 'se_opreste';
    public const OPRIT = 'oprit';
    public const NUCLEU = 'nucleu';

    private const RANG = [self::OPRIT => 0, self::SE_OPRESTE => 1, self::PORNIT => 2];

    private static ?array $catalog = null;
    /** @var array<string, string>|null */
    private static ?array $stari = null;
    private static ?array $licenta = null;
    private static array $mesaje = [];
    private static ?string $jurnal = null;
    /** @var array<string, array> */
    private static array $declaratiiRute = [];
    private static ?array $listaDeclaratii = null;
    private static ?array $impartite = null;

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

    /** Nu există nicio licență bună: site-ul merge doar cu nucleul. */
    public static function licentaLipseste(): bool
    {
        self::stari();
        return self::$licenta === null;
    }

    /** Licența folosită acum (conținutul verificat) sau null. */
    public static function licenta(): ?array
    {
        self::stari();
        return self::$licenta;
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
        self::$licenta = $rezultat['licenta'];
        $calcul = self::calculeazaStari(self::$licenta['pluginuri'] ?? []);
        self::$stari = $calcul['stari'];

        $mesaje = $rezultat['probleme'];
        if (self::$licenta === null) {
            $mesaje[] = 'Nicio licență bună: site-ul merge doar cu nucleul (în admin apare „Licența lipsește”).';
        }
        self::$mesaje = array_merge($mesaje, $calcul['mesaje']);
        self::noteazaSchimbarile();
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
                $alese[] = $intrare;
            }
            usort($alese, static fn (array $a, array $b): int
                => [(int) ($a['ordine'] ?? 0), $a['_pozitie']] <=> [(int) ($b['ordine'] ?? 0), $b['_pozitie']]);
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

    // ---------------------------------------------------------------- jurnal

    /** O linie în `storage/logs/pluginuri.log`. */
    public static function jurnal(string $mesaj): void
    {
        $fisier = self::$jurnal ?? dirname(__DIR__, 2) . '/storage/logs/pluginuri.log';
        @file_put_contents($fisier, '[' . date('Y-m-d H:i:s') . '] ' . $mesaj . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Doar pentru teste: unde scrie jurnalul. */
    public static function folosesteJurnal(?string $fisier): void
    {
        self::$jurnal = $fisier;
    }

    /** Uită tot ce s-a calculat (teste, unelte care schimbă licența în același proces). */
    public static function reseteaza(): void
    {
        self::$stari = null;
        self::$licenta = null;
        self::$mesaje = [];
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
     * Ce s-a schimbat de la cererea precedentă: mesajele noi se scriu în jurnal
     * o singură dată, iar la o schimbare de stare se golește memoria paginilor.
     */
    private static function noteazaSchimbarile(): void
    {
        $fisier = Licenta::dosar() . '/stare-aplicata.json';
        $amprentaStari = sha1((string) json_encode([self::$stari, self::$licenta['redirectionari'] ?? []]));
        $amprentaMesaje = sha1(implode("\n", self::$mesaje));
        $vechi = is_file($fisier) ? json_decode((string) @file_get_contents($fisier), true) : null;
        $vechi = is_array($vechi) ? $vechi : [];
        if (($vechi['stari'] ?? '') === $amprentaStari && ($vechi['mesaje'] ?? '') === $amprentaMesaje) {
            return;
        }
        if (!is_dir(dirname($fisier))) {
            @mkdir(dirname($fisier), 0770, true);
        }
        $nou = ['stari' => $amprentaStari, 'mesaje' => $amprentaMesaje, 'la' => date('c')];
        // Fără loc de scris, nu putem ține minte ce am notat: mai bine tăcem
        // decât să golim memoria și să scriem în jurnal la fiecare cerere.
        if (!Licenta::scrieAtomic($fisier, (string) json_encode($nou))) {
            return;
        }
        if (($vechi['mesaje'] ?? '') !== $amprentaMesaje) {
            foreach (self::$mesaje as $mesaj) {
                self::jurnal($mesaj);
            }
        }
        if (($vechi['stari'] ?? '') !== $amprentaStari) {
            $pornite = [];
            foreach (self::$stari as $cheie => $stare) {
                if ($stare !== self::OPRIT) {
                    $pornite[] = $cheie . ($stare === self::SE_OPRESTE ? ' (se oprește)' : '');
                }
            }
            $licenta = self::$licenta;
            self::jurnal('Stările pluginurilor s-au schimbat' . ($licenta !== null
                    ? ' (licența ' . $licenta['site'] . ' v' . $licenta['versiune'] . ')' : ' (fără licență)')
                . ': ' . ($pornite === [] ? 'doar nucleul' : implode(', ', $pornite)) . '.');
            ResponseCache::purgePageCache();
        }
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
