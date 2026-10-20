<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use Closure;
use Throwable;

/**
 * Migrările din cod: `database/migrari/<proprietar>/NNNN_<nume>.php`, în ordinea numărului
 * NNNN (unic în tot dosarul, oricare ar fi proprietarul). Proprietarul e `nucleu` sau cheia
 * unui plugin din config/plugins.php: dosarul spune doar al cui e codul; migrarea rulează pe
 * orice site, oricare ar fi starea pluginului (docs/MIGRARI.md).
 *
 * Pașii unui singur site, doar cu --site=<site>, după toate migrările: întâi înghețarea setărilor din
 * pachetul site-ului (`<SITE_DATE_DOSAR>/setari-inghetate.json`, apoi corecturile `-2`, `-3` …; id
 * `site/<site>/setari-inghetate[-N]`, sha256 = al fișierului; clasa Inghetare), apoi
 * `database/migrari/site/<site>/<nume>.php` (id `site/<site>/<nume>`), în ordinea numelui.
 *
 * Fișierele de lângă ele (generate, în git):
 *  - `migrari.lock`: {"migrari": {id: sha256}, "retrase": {id: motiv}}: o migrare publicată
 *    nu se mai schimbă (sha diferit → codul 12), una ștearsă trebuie trecută la „retrase”;
 *  - `asteptat.json`: amprenta schemei după toate migrările, pe o bază goală (Amprenta);
 *  - `ultima.php`: numărul ultimei migrări, citit de garda de la fiecare cerere (WP-05).
 *
 * sha256 se calculează pe fișierul cu sfârșituri de rând LF (un checkout pe Windows nu
 * schimbă nimic).
 */
final class Registru
{
    public const TIPURI = ['schema', 'date', 'contract'];
    private const CHEI = ['descriere', 'tip', 'blocanta', 'sus'];
    private const FISIERE_RADACINA = ['migrari.lock', 'asteptat.json', 'ultima.php', 'README.md', '.gitkeep'];
    /** Un fișier PHP al site-ului nu poate lua numele pasului de înghețare (acela vine din pachet). */
    private const TIPAR_INGHETARE_PHP = '/^setari-inghetate(?:-[^.]*)?\.php$/';

    /** @var list<array<string, mixed>>|null */
    private ?array $migrari = null;

    /** @var array<string, list<array<string, mixed>>> */
    private array $pasiSite = [];

    /**
     * @param list<string> $proprietari `nucleu` și cheile pluginurilor (Plugins::chei())
     */
    public function __construct(
        private string $dosar,
        private array $proprietari,
    ) {
    }

    public function dosar(): string
    {
        return $this->dosar;
    }

    /**
     * Migrările comune, în ordinea NNNN. Fiecare: id, ord, proprietar, tip, blocanta,
     * descriere, sus (Closure), sha256, fisier.
     *
     * @return list<array<string, mixed>>
     */
    public function migrari(): array
    {
        if ($this->migrari !== null) {
            return $this->migrari;
        }
        $migrari = [];
        $numere = [];
        if (is_dir($this->dosar)) {
            foreach ($this->intrari($this->dosar) as $nume) {
                $cale = $this->dosar . '/' . $nume;
                if (!is_dir($cale)) {
                    if (str_ends_with($nume, '.php') && !in_array($nume, self::FISIERE_RADACINA, true)) {
                        throw new Eroare('registrul migrărilor: ' . $nume . ' stă direct în ' . $this->dosar
                            . '; o migrare stă în dosarul proprietarului ei (nucleu/, <plugin>/)', Eroare::CONFIGURARE);
                    }
                    continue;
                }
                if ($nume === 'site') {
                    continue;
                }
                if (!in_array($nume, $this->proprietari, true)) {
                    throw new Eroare('registrul migrărilor: dosarul „' . $nume . '” nu e nucleu și nici un plugin din config/plugins.php', Eroare::CONFIGURARE);
                }
                foreach ($this->intrari($cale) as $fisier) {
                    if (!str_ends_with($fisier, '.php')) {
                        continue;
                    }
                    if (preg_match('/^(\d{4})_([a-z0-9_]+)\.php$/', $fisier, $m) !== 1 || (int) $m[1] === 0) {
                        throw new Eroare('registrul migrărilor: ' . $nume . '/' . $fisier . ' nu are forma NNNN_nume.php (litere mici, cifre, _)', Eroare::CONFIGURARE);
                    }
                    $ord = (int) $m[1];
                    $id = $nume . '/' . substr($fisier, 0, -4);
                    if (isset($numere[$ord])) {
                        throw new Eroare('registrul migrărilor: numărul ' . $m[1] . ' e folosit de două ori (' . $numere[$ord] . ', ' . $id
                            . '); numerele sunt unice în tot dosarul', Eroare::CONFIGURARE);
                    }
                    $numere[$ord] = $id;
                    $migrari[] = $this->incarca($cale . '/' . $fisier, $id, $ord, $nume, false);
                }
            }
        }
        usort($migrari, static fn (array $a, array $b): int => $a['ord'] <=> $b['ord']);
        return $this->migrari = $migrari;
    }

    /**
     * Pașii site-ului dat (ord 0): înghețarea setărilor din pachetul site-ului ($dosarSite =
     * SITE_DATE_DOSAR), apoi fișierele PHP ale site-ului, în ordinea numelui. Un pachet cu înghețarea
     * altui site → codul 2.
     *
     * @return list<array<string, mixed>>
     */
    public function pasiSite(string $site, string $dosarSite = ''): array
    {
        $cheieMemo = $site . "\0" . $dosarSite;
        if (isset($this->pasiSite[$cheieMemo])) {
            return $this->pasiSite[$cheieMemo];
        }
        $pasi = [];
        foreach ($this->fisiereInghetare($dosarSite) as $nume => $fisier) {
            $inghetare = Inghetare::dinFisier($fisier);
            if ($inghetare->site() !== $site) {
                throw new Eroare('dosarul site-ului (SITE_DATE_DOSAR) are înghețarea setărilor site-ului „' . $inghetare->site()
                    . '” (' . $nume . '.json), nu a lui „' . $site . '”; refuz să rulez pașii altui site', Eroare::CONFIGURARE);
            }
            $id = 'site/' . $site . '/' . $nume;
            $pasi[] = [
                'id' => $id,
                'ord' => 0,
                'proprietar' => 'site',
                'tip' => 'site',
                'blocanta' => false,
                'descriere' => 'Înghețarea setărilor (' . $nume . '.json: ' . count($inghetare->setari()) . ' setări, din '
                    . $inghetare->sursa() . ($inghetare->provizoriu() !== null ? ', PROVIZORIE' : '') . ')',
                'sus' => static function (Schema $s) use ($inghetare, $id): void {
                    $inghetare->aplica($s, $id);
                },
                'sha256' => $inghetare->sha256(),
                'fisier' => $fisier,
            ];
        }
        $dosar = $this->dosar . '/site/' . $site;
        if (is_dir($dosar)) {
            foreach ($this->intrari($dosar) as $fisier) {
                if (!str_ends_with($fisier, '.php')) {
                    continue;
                }
                if (preg_match('/^[a-z0-9][a-z0-9_-]*\.php$/', $fisier) !== 1) {
                    throw new Eroare('registrul migrărilor: site/' . $site . '/' . $fisier . ' nu are un nume valid (litere mici, cifre, _ și -)', Eroare::CONFIGURARE);
                }
                if (preg_match(self::TIPAR_INGHETARE_PHP, $fisier) === 1) {
                    throw new Eroare('registrul migrărilor: site/' . $site . '/' . $fisier . ' are numele pasului de înghețare a setărilor, '
                        . 'care vine din pachetul site-ului (setari-inghetate.json); alt nume', Eroare::CONFIGURARE);
                }
                $pasi[] = $this->incarca($dosar . '/' . $fisier, 'site/' . $site . '/' . substr($fisier, 0, -4), 0, 'site', true);
            }
        }
        return $this->pasiSite[$cheieMemo] = $pasi;
    }

    /**
     * Fișierele de înghețare din pachetul unui site, în ordinea aplicării: `setari-inghetate.json`, apoi
     * `setari-inghetate-2.json`, `-3` … Un alt nume `setari-inghetate*.json` → codul 2 (alte fișiere, de exemplu
     * o notă `.md`, nu contează).
     *
     * @return array<string, string> numele pasului (fără .json) → calea fișierului
     */
    public function fisiereInghetare(string $dosarSite): array
    {
        if ($dosarSite === '' || !is_dir($dosarSite)) {
            return [];
        }
        $fisiere = [];
        foreach ($this->intrari($dosarSite) as $nume) {
            if (!str_starts_with($nume, 'setari-inghetate') || !str_ends_with($nume, '.json') || !is_file($dosarSite . '/' . $nume)) {
                continue;
            }
            if (preg_match(Inghetare::TIPAR_FISIER, $nume, $m) !== 1) {
                throw new Eroare('pachetul site-ului: ' . $nume . ' nu e un nume de înghețare (setari-inghetate.json, setari-inghetate-2.json …)',
                    Eroare::CONFIGURARE);
            }
            $fisiere[(int) ($m[1] ?? 1)] = $nume;
        }
        ksort($fisiere);
        $rezultat = [];
        foreach ($fisiere as $nume) {
            $rezultat[substr($nume, 0, -5)] = rtrim($dosarSite, '/') . '/' . $nume;
        }
        return $rezultat;
    }

    /**
     * Site-ul al cărui pachet e în $dosarSite, după înghețarea lui (null = pachet fără înghețare).
     * Toate fișierele de înghețare trebuie să fie ale aceluiași site (altfel codul 2).
     */
    public function sitePachet(string $dosarSite): ?string
    {
        $site = null;
        foreach ($this->fisiereInghetare($dosarSite) as $nume => $fisier) {
            $alSau = Inghetare::dinFisier($fisier)->site();
            if ($site !== null && $alSau !== $site) {
                throw new Eroare('pachetul site-ului are înghețări pentru două site-uri („' . $site . '”, „' . $alSau . '” în ' . $nume . '.json)',
                    Eroare::CONFIGURARE);
            }
            $site = $alSau;
        }
        return $site;
    }

    /** Site-ul unui pas de înghețare a setărilor (id `site/<site>/setari-inghetate[-N]`), sau null pentru orice alt id. */
    public static function pasInghetare(string $id): ?string
    {
        return preg_match('#^site/([a-z0-9][a-z0-9-]*)/setari-inghetate(?:-([2-9]|[1-9]\d+))?$#', $id, $m) === 1 ? $m[1] : null;
    }

    /**
     * Toate id-urile cu fișier pe disc (migrările și pașii tuturor site-urilor; înghețarea doar a site-ului
     * dat, din pachetul lui) și sha-ul lor, fără să le încarce: pentru „o migrare aplicată a dispărut”.
     *
     * @return array<string, string>
     */
    public function fisiere(?string $site = null, string $dosarSite = ''): array
    {
        $fisiere = [];
        foreach ($this->migrari() as $m) {
            $fisiere[$m['id']] = $m['sha256'];
        }
        if ($site !== null) {
            foreach ($this->pasiSite($site, $dosarSite) as $pas) {
                $fisiere[$pas['id']] = $pas['sha256'];
            }
        }
        $dosarPasi = $this->dosar . '/site';
        if (is_dir($dosarPasi)) {
            foreach ($this->intrari($dosarPasi) as $alCui) {
                if (!is_dir($dosarPasi . '/' . $alCui)) {
                    continue;
                }
                foreach ($this->intrari($dosarPasi . '/' . $alCui) as $fisier) {
                    if (str_ends_with($fisier, '.php')) {
                        $fisiere['site/' . $alCui . '/' . substr($fisier, 0, -4)] = self::sha($dosarPasi . '/' . $alCui . '/' . $fisier);
                    }
                }
            }
        }
        return $fisiere;
    }

    /** @return array{migrari: array<string, string>, retrase: array<string, string>} */
    public function lacat(): array
    {
        $fisier = $this->dosar . '/migrari.lock';
        if (!is_file($fisier)) {
            return ['migrari' => [], 'retrase' => []];
        }
        $date = json_decode((string) file_get_contents($fisier), true);
        if (!is_array($date) || !is_array($date['migrari'] ?? []) || !is_array($date['retrase'] ?? [])) {
            throw new Eroare('registrul migrărilor: migrari.lock nu e JSON valid ({"migrari": {…}, "retrase": {…}})', Eroare::CONFIGURARE);
        }
        return ['migrari' => (array) ($date['migrari'] ?? []), 'retrase' => (array) ($date['retrase'] ?? [])];
    }

    /**
     * O migrare publicată (în migrari.lock) nu se mai schimbă și nu dispare: codul 12.
     * Una nouă, încă nescrisă în migrari.lock, e în regulă (lint-ul cere să fie trecută).
     */
    public function verificaLacatul(): void
    {
        $lacat = $this->lacat();
        $fisiere = $this->fisiere();
        foreach ($lacat['migrari'] as $id => $sha) {
            if (!isset($fisiere[$id])) {
                if (!isset($lacat['retrase'][$id])) {
                    throw new Eroare('migrarea ' . $id . ' e în migrari.lock, dar fișierul ei lipsește (o migrare retrasă se trece la „retrase”)', Eroare::SCHIMBATA);
                }
                continue;
            }
            if ($fisiere[$id] !== $sha) {
                throw new Eroare('migrarea ' . $id . ' s-a schimbat după ce a fost publicată (sha256 diferit de migrari.lock); '
                    . 'o schimbare se face într-o migrare nouă', Eroare::SCHIMBATA);
            }
        }
    }

    public function retrasa(string $id): bool
    {
        return isset($this->lacat()['retrase'][$id]);
    }

    /** asteptat.json, sau null dacă lipsește. */
    public function asteptat(): ?array
    {
        $fisier = $this->dosar . '/asteptat.json';
        if (!is_file($fisier)) {
            return null;
        }
        $date = json_decode((string) file_get_contents($fisier), true);
        if (!is_array($date) || !isset($date['tabele']) || !is_array($date['tabele'])) {
            throw new Eroare('registrul migrărilor: asteptat.json nu e o amprentă validă', Eroare::CONFIGURARE);
        }
        return $date;
    }

    /** Numărul celei mai noi migrări din cod (0 = niciuna). */
    public function ultimaOrd(): int
    {
        $migrari = $this->migrari();
        return $migrari === [] ? 0 : (int) $migrari[count($migrari) - 1]['ord'];
    }

    /** Amprenta codului: sha256 al listei ordonate „id:sha” (în _platforma_stare.amprenta și în ultima.php). */
    public function amprenta(): string
    {
        $linii = array_map(static fn (array $m): string => $m['id'] . ':' . $m['sha256'], $this->migrari());
        return hash('sha256', implode("\n", $linii));
    }

    public static function sha(string $fisier): string
    {
        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($fisier)));
    }

    /** @return list<string> */
    private function intrari(string $dosar): array
    {
        $intrari = array_values(array_filter(scandir($dosar) ?: [], static fn (string $n): bool => $n !== '.' && $n !== '..'));
        sort($intrari, SORT_STRING);
        return $intrari;
    }

    /** @return array<string, mixed> */
    private function incarca(string $fisier, string $id, int $ord, string $proprietar, bool $pasSite): array
    {
        try {
            $definitie = (static function (string $__fisier): mixed {
                return require $__fisier;
            })($fisier);
        } catch (Throwable $e) {
            throw new Eroare('registrul migrărilor: ' . $id . ' nu se poate încărca: ' . $e->getMessage(), Eroare::CONFIGURARE);
        }
        if (!is_array($definitie)) {
            throw new Eroare('registrul migrărilor: ' . $id . ' trebuie să întoarcă un array (descriere, tip, blocanta, sus)', Eroare::CONFIGURARE);
        }
        $necunoscute = array_diff(array_keys($definitie), self::CHEI);
        $lipsa = array_diff(self::CHEI, array_keys($definitie));
        if ($necunoscute !== [] || $lipsa !== []) {
            throw new Eroare('registrul migrărilor: ' . $id . ': '
                . ($lipsa !== [] ? 'lipsesc ' . implode(', ', $lipsa) : 'chei necunoscute: ' . implode(', ', $necunoscute)), Eroare::CONFIGURARE);
        }
        $tipuri = $pasSite ? ['site'] : self::TIPURI;
        if (!is_string($definitie['descriere']) || trim($definitie['descriere']) === ''
            || !in_array($definitie['tip'], $tipuri, true) || !is_bool($definitie['blocanta'])
            || !($definitie['sus'] instanceof Closure)) {
            throw new Eroare('registrul migrărilor: ' . $id . ': descriere (text), tip (' . implode('|', $tipuri)
                . '), blocanta (true/false), sus (funcție)', Eroare::CONFIGURARE);
        }
        return [
            'id' => $id,
            'ord' => $ord,
            'proprietar' => $proprietar,
            'tip' => $definitie['tip'],
            'blocanta' => $definitie['blocanta'],
            'descriere' => trim($definitie['descriere']),
            'sus' => $definitie['sus'],
            'sha256' => self::sha($fisier),
            'fisier' => $fisier,
        ];
    }
}
