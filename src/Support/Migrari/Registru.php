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
 * Pașii unui singur site: `database/migrari/site/<site>/<nume>.php` (id `site/<site>/<nume>`),
 * doar cu --site=<site>, după toate migrările, în ordinea numelui.
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
     * Pașii site-ului dat, în ordinea numelui (ord 0).
     *
     * @return list<array<string, mixed>>
     */
    public function pasiSite(string $site): array
    {
        if (isset($this->pasiSite[$site])) {
            return $this->pasiSite[$site];
        }
        $pasi = [];
        $dosar = $this->dosar . '/site/' . $site;
        if (is_dir($dosar)) {
            foreach ($this->intrari($dosar) as $fisier) {
                if (!str_ends_with($fisier, '.php')) {
                    continue;
                }
                if (preg_match('/^[a-z0-9][a-z0-9_-]*\.php$/', $fisier) !== 1) {
                    throw new Eroare('registrul migrărilor: site/' . $site . '/' . $fisier . ' nu are un nume valid (litere mici, cifre, _ și -)', Eroare::CONFIGURARE);
                }
                $pasi[] = $this->incarca($dosar . '/' . $fisier, 'site/' . $site . '/' . substr($fisier, 0, -4), 0, 'site', true);
            }
        }
        return $this->pasiSite[$site] = $pasi;
    }

    /**
     * Toate id-urile cu fișier pe disc (migrările și pașii tuturor site-urilor) și sha-ul lor,
     * fără să le încarce: pentru „o migrare aplicată a dispărut”.
     *
     * @return array<string, string>
     */
    public function fisiere(): array
    {
        $fisiere = [];
        foreach ($this->migrari() as $m) {
            $fisiere[$m['id']] = $m['sha256'];
        }
        $dosarSite = $this->dosar . '/site';
        if (is_dir($dosarSite)) {
            foreach ($this->intrari($dosarSite) as $site) {
                if (!is_dir($dosarSite . '/' . $site)) {
                    continue;
                }
                foreach ($this->intrari($dosarSite . '/' . $site) as $fisier) {
                    if (str_ends_with($fisier, '.php')) {
                        $fisiere['site/' . $site . '/' . substr($fisier, 0, -4)] = self::sha($dosarSite . '/' . $site . '/' . $fisier);
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
