<?php

declare(strict_types=1);

namespace Unelte\Inghetare;

use App\Support\Migrari\Inghetare;
use RuntimeException;
use stdClass;

/**
 * Generatorul înghețării setărilor (docs/MIGRARI.md, „Înghețarea setărilor”; WP-06, MIG-09, DB-02):
 * comparația dintre ce folosește codul vechi al unui site și ce folosește codul comun când o setare
 * lipsește din bază, și fișierul `site-uri/<site>/setari-inghetate.json` care o păstrează.
 *
 * Pentru fiecare cheie (din Settings::DEFAULTS și din rezervele scrise în cod, `$settings['x'] ?? 'y'`,
 * ale ambelor coduri; tools/inghetare/lib/citeste-cod.php, câte un proces pe cod) valoarea efectivă a
 * fiecărui cod e: `implicit` (DEFAULTS), altfel `cod` (o singură rezervă), `ambiguu` (mai multe rezerve
 * sau o expresie) sau `lipsa`. Lipsa și textul gol sunt același lucru (nicio valoare). Unde cele două
 * diferă:
 *
 *  - codul vechi are o valoare sigură (implicit sau o singură rezervă): intrare automată, cu valoarea
 *    lui („din”: „implicit” | „cod”); modul (`lipsa_sau_gol`) și nota se păstrează de la o generare la
 *    alta;
 *  - altfel (codul vechi nu avea setarea, sau o citea cu rezerve diferite): o DECIZIE scrisă de mână în
 *    fișier, fie o setare („din”: „manual”, cu valoarea; „extras”, cu valoarea scoasă din codul vechi
 *    prin `extrage`), fie `fara_inghetare` cu motivul. Lipsa unei decizii = eroare, fișierul nu se scrie.
 *
 * Fiecare decizie e legată de ce arătau atunci cele două coduri (`vechi`, `nucleu`): când unul se
 * schimbă (o nouă valoare implicită în codul comun, alt commit al codului vechi), decizia trebuie
 * confirmată din nou (se șterg `vechi`/`nucleu` din intrare) sau schimbată. O intrare automată poate fi
 * înlocuită de o decizie (aceeași cheie, „din”: „manual”/„extras”, sau în fara_inghetare).
 *
 * Datele private (OD-49): o valoare cu o adresă de email care nu e @exemplu.ro sau cu un telefon (sau o
 * intrare marcată `privat`) intră în fișierul din git cu un înlocuitor, iar valoarea adevărată în
 * `site-uri/<site>/privat/setari-inghetate.json` (ignorat de git). O valoare scrisă de mână nu poate fi
 * privată (ar fi în git): ea vine din cod, cu „extras”.
 *
 * `amprenta_nucleu` = sha256 al valorilor codului comun (DEFAULTS și rezerve) la generare: verificarea
 * (verifica-inghetare.php) pică dacă s-au schimbat de atunci, chiar fără clonele codurilor vechi.
 */
final class Generator
{
    /** Site-urile vechi: cheia din docs/de-portat.md → variabila clonei (tools/dev/comun.sh). */
    public const SITURI = [
        'bioscem' => 'CMS_DEV_REPO_BS',
        'biovitality' => 'CMS_DEV_REPO_BV',
        'vertij' => 'CMS_DEV_REPO_VT',
        'grafoanaytis' => 'CMS_DEV_REPO_GR',
    ];
    public const EXPRESIE = '<expresie>';
    private const PARTI_COD = ['src', 'views', 'scripts', 'config', 'public', 'bootstrap.php'];
    private const DESPRE = 'Valorile pe care codul vechi al site-ului le folosea efectiv acolo unde codul comun ar folosi altceva; '
        . 'se scriu o singură dată, la adoptare, și doar unde lipsesc (docs/MIGRARI.md, „Înghețarea setărilor”). Generat de '
        . 'tools/inghetare/genereaza-setari.php: intrările „din”: „implicit”/„cod” le face generatorul; deciziile („din”: '
        . '„manual”/„extras”, fara_inghetare) se scriu aici de mână. Valorile „privat” sunt înlocuitori: cele adevărate stau în '
        . 'privat/setari-inghetate.json, în afara git-ului (decizia OD-49).';
    private const DESPRE_PRIVAT = 'Valorile adevărate ale setărilor „privat” din ../setari-inghetate.json (date ale clientului: nu intră '
        . 'în git cât repo-ul e public, decizia OD-49). Scris de tools/inghetare/genereaza-setari.php; pe server stă lângă pachetul '
        . 'site-ului, în privat/ (docs/MIGRARI.md).';

    public function __construct(
        private string $radacina,
        private string $lucru,
        private string $php = PHP_BINARY,
    ) {
    }

    // ------------------------------------------------------------------- sursele

    /**
     * Tabelul de sus din docs/de-portat.md: site → [repo, ramura, revizuit] („ultimul commit revizuit”).
     *
     * @return array<string, array{repo: string, ramura: string, revizuit: string}>
     */
    public function tabelDePortat(): array
    {
        $text = @file_get_contents($this->radacina . '/docs/de-portat.md');
        if (!is_string($text)) {
            throw new RuntimeException('nu pot citi docs/de-portat.md');
        }
        $tabel = [];
        foreach (explode("\n", $text) as $rand) {
            if (preg_match('/^\|\s*([^|`]+?)\s*\|\s*`([^`]+)`,\s*`([^`]+)`\s*\|\s*`([0-9a-f]{7,40})`/u', $rand, $m) === 1) {
                $tabel[mb_strtolower($m[1])] = ['repo' => $m[2], 'ramura' => $m[3], 'revizuit' => $m[4]];
            }
        }
        foreach (array_keys(self::SITURI) as $site) {
            if (!isset($tabel[$site])) {
                throw new RuntimeException('docs/de-portat.md nu are rândul „' . $site . '” în tabelul „Ultimul commit revizuit”');
            }
        }
        return $tabel;
    }

    /** Clona codului vechi al unui site: variabila din tools/dev/comun.sh, altfel alături de acest repo. */
    public function clona(string $site): string
    {
        $cale = (string) getenv(self::SITURI[$site] ?? '');
        return $cale !== '' ? rtrim($cale, '/') : dirname($this->radacina) . '/' . $this->tabelDePortat()[$site]['repo'];
    }

    /**
     * Copia codului vechi la commit-ul dat (git archive, doar src, views, scripts, config, public,
     * bootstrap.php), refolosită dacă există. Clona rămâne neatinsă.
     *
     * @return array{0: string, 1: string} [dosarul copiei, commit-ul scurt]
     */
    public function copie(string $clona, string $commit): array
    {
        if (!is_dir($clona . '/.git') && !is_file($clona . '/.git')) {
            throw new RuntimeException($clona . ' nu e o clonă git');
        }
        $scurt = trim((string) shell_exec('git -C ' . escapeshellarg($clona) . ' rev-parse --short=7 ' . escapeshellarg($commit . '^{commit}') . ' 2>/dev/null'));
        if (preg_match('/^[0-9a-f]{7,40}$/', $scurt) !== 1) {
            throw new RuntimeException('commit necunoscut în ' . $clona . ': ' . $commit);
        }
        $dosar = $this->lucru . '/cod-' . basename($clona) . '-' . $scurt;
        if (!is_file($dosar . '/src/Support/Settings.php')) {
            exec('rm -rf ' . escapeshellarg($dosar));
            @mkdir($dosar, 0777, true);
            exec('git -C ' . escapeshellarg($clona) . ' ls-tree --name-only ' . escapeshellarg($scurt), $intrari, $cod);
            $parti = array_values(array_intersect(self::PARTI_COD, $intrari));
            if ($cod !== 0 || !in_array('src', $parti, true)) {
                throw new RuntimeException('codul ' . basename($clona) . '@' . $scurt . ' nu are src/');
            }
            exec('git -C ' . escapeshellarg($clona) . ' archive ' . escapeshellarg($scurt) . ' ' . implode(' ', array_map('escapeshellarg', $parti))
                . ' | tar -x -C ' . escapeshellarg($dosar), $_, $codTar);
            if ($codTar !== 0 || !is_file($dosar . '/src/Support/Settings.php')) {
                throw new RuntimeException('git archive ' . $scurt . ' din ' . $clona . ' n-a mers (sau codul nu are src/Support/Settings.php)');
            }
        }
        return [$dosar, $scurt];
    }

    /**
     * Ce folosește un cod când o setare lipsește (procesul-copil citeste-cod.php).
     *
     * @param array<string, array<string, mixed>> $extrase cheie → cererea „extrage” a unei decizii
     * @return array{implicite: array<string, string>, rezerve: array<string, list<string>>, extrase: array<string, array<string, string>>, fisiere: int}
     */
    public function citesteCod(string $dosar, array $extrase = []): array
    {
        $comanda = [$this->php, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'error_reporting=E_ALL', '-d', 'allow_url_fopen=0',
            $this->radacina . '/tools/inghetare/lib/citeste-cod.php', $dosar];
        $fisierCereri = null;
        if ($extrase !== []) {
            @mkdir($this->lucru, 0777, true);
            $fisierCereri = $this->lucru . '/extrage-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
            file_put_contents($fisierCereri, json_encode($extrase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $comanda[] = '--extrage=' . $fisierCereri;
        }
        $env = getenv();
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $proxy) {
            $env[$proxy] = 'http://127.0.0.1:9';
        }
        $proces = proc_open($comanda, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, $dosar, $env);
        if (!is_resource($proces)) {
            throw new RuntimeException('nu pot porni citirea codului din ' . $dosar);
        }
        $out = (string) stream_get_contents($pipe[1]);
        $err = (string) stream_get_contents($pipe[2]);
        fclose($pipe[1]);
        fclose($pipe[2]);
        $cod = proc_close($proces);
        if ($fisierCereri !== null) {
            @unlink($fisierCereri);
        }
        $citire = json_decode($out, true);
        if ($cod !== 0 || !is_array($citire) || !is_array($citire['implicite'] ?? null)) {
            throw new RuntimeException('citirea codului din ' . $dosar . ' n-a mers (cod ' . $cod . '): ' . trim(substr($err . $out, 0, 600)));
        }
        if (preg_match('/^(PHP )?(Warning|Notice|Deprecated):/m', $err) === 1) {
            throw new RuntimeException('citirea codului din ' . $dosar . ' a dat avertismente PHP: ' . trim(substr($err, 0, 600)));
        }
        return $citire + ['rezerve' => [], 'extrase' => []];
    }

    /** sha256 al valorilor codului comun (DEFAULTS și rezerve): se schimbă cu orice implicit sau rezervă nouă. */
    public static function amprenta(array $citire): string
    {
        return hash('sha256', (string) json_encode(['implicite' => $citire['implicite'], 'rezerve' => $citire['rezerve']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ------------------------------------------------------------- valorile efective

    /** @return array{fel: string, valori: list<string>} fel = implicit | cod | ambiguu | lipsa */
    public static function efectiv(string $cheie, array $citire): array
    {
        if (array_key_exists($cheie, $citire['implicite'])) {
            return ['fel' => 'implicit', 'valori' => [(string) $citire['implicite'][$cheie]]];
        }
        $rezerve = array_values(array_map('strval', $citire['rezerve'][$cheie] ?? []));
        if (count($rezerve) === 1 && $rezerve[0] !== self::EXPRESIE) {
            return ['fel' => 'cod', 'valori' => $rezerve];
        }
        return $rezerve !== [] ? ['fel' => 'ambiguu', 'valori' => $rezerve] : ['fel' => 'lipsa', 'valori' => []];
    }

    /** Aceeași valoare (lipsa = textul gol: nicio valoare, oricare ar fi felul). */
    public static function echivalent(array $a, array $b): bool
    {
        $gol = static fn (array $e): bool => $e['fel'] === 'lipsa' || ($e['fel'] !== 'ambiguu' && $e['valori'][0] === '');
        if ($gol($a) || $gol($b)) {
            return $gol($a) && $gol($b);
        }
        if ($a['fel'] !== 'ambiguu' && $b['fel'] !== 'ambiguu') {
            return $a['valori'][0] === $b['valori'][0];
        }
        return $a['fel'] === 'ambiguu' && $b['fel'] === 'ambiguu' && $a['valori'] === $b['valori'];
    }

    /** Cum arată o valoare efectivă în legătura unei decizii (fără date private: doar lungimea lor). */
    public static function arata(array $e): string
    {
        $literal = static function (string $v): string {
            if ($v === self::EXPRESIE) {
                return 'expresie';
            }
            if (Inghetare::arePrivat($v)) {
                return 'privat (' . mb_strlen($v) . ' caractere)';
            }
            if (mb_strlen($v) <= 60 && preg_match('/[\x00-\x1F\x7F]/', $v) !== 1) {
                return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return 'sha256:' . substr(hash('sha256', $v), 0, 16) . ' (' . mb_strlen($v) . ' caractere)';
        };
        return match ($e['fel']) {
            'lipsa' => 'lipsește',
            'implicit' => 'implicit ' . $literal($e['valori'][0]),
            'cod' => 'cod ' . $literal($e['valori'][0]),
            default => 'cod ' . implode(' | ', array_map($literal, $e['valori'])),
        };
    }

    // ---------------------------------------------------------------------- generarea

    /**
     * Fișierul de înghețare al unui site, din cele două citiri și deciziile fișierului de acum.
     *
     * @param array<string, mixed>|null $existent fișierul de acum, decodat (null = nu există)
     * @param array<string, mixed> $vechi citirea codului vechi (cu extrase)
     * @param array<string, mixed> $nucleu citirea codului comun
     * @param string|false|null $provizoriu text = provizoriu cu acest motiv; false = confirmat; null = ca înainte
     * @return array{text: string, privat: ?string, erori: list<string>, scoase: list<string>, rezumat: string}
     */
    public function genereaza(string $site, string $sursa, ?array $existent, array $vechi, array $nucleu, string|false|null $provizoriu = null): array
    {
        $erori = [];
        $scoase = [];
        $existentSetari = [];
        foreach ((array) ($existent['setari'] ?? []) as $cheie => $intrare) {
            $existentSetari[(string) $cheie] = is_string($intrare) ? ['valoare' => $intrare, 'din' => 'manual'] : (array) $intrare;
        }
        $existentFara = [];
        foreach ((array) ($existent['fara_inghetare'] ?? []) as $cheie => $intrare) {
            $existentFara[(string) $cheie] = is_string($intrare) ? ['motiv' => $intrare] : (array) $intrare;
        }
        if (isset($existent['inchis'])) {
            $erori[] = 'fișierul e închis („inchis”: ' . $existent['inchis'] . '): aplicat pe site, nu se mai schimbă; o corectură merge în '
                . 'setari-inghetate-2.json (docs/MIGRARI.md)';
        }

        $chei = array_unique(array_merge(array_keys($vechi['implicite']), array_keys($vechi['rezerve']),
            array_keys($nucleu['implicite']), array_keys($nucleu['rezerve'])));
        sort($chei, SORT_STRING);
        $legaturi = [];
        $setari = [];
        $fara = [];
        $esteDecizie = static fn (array $i): bool => in_array($i['din'] ?? 'manual', ['manual', 'extras'], true);

        foreach ($chei as $cheie) {
            $ev = self::efectiv($cheie, $vechi);
            $en = self::efectiv($cheie, $nucleu);
            $legaturi[$cheie] = ['vechi' => self::arata($ev), 'nucleu' => self::arata($en)];
            $manuala = isset($existentSetari[$cheie]) && $esteDecizie($existentSetari[$cheie]) ? $existentSetari[$cheie] : null;
            $automata = isset($existentSetari[$cheie]) && !$esteDecizie($existentSetari[$cheie]) ? $existentSetari[$cheie] : null;
            if (self::echivalent($ev, $en)) {
                if (isset($existentFara[$cheie])) {
                    $scoase[] = 'fara_inghetare.' . $cheie . ': nu mai e nevoie (codul vechi și codul comun folosesc aceeași valoare)';
                    unset($existentFara[$cheie]);
                }
                if ($automata !== null) {
                    $scoase[] = 'setari.' . $cheie . ': nu mai e nevoie (aceeași valoare în ambele coduri)';
                }
                continue;
            }
            if ($manuala !== null || isset($existentFara[$cheie])) {
                continue; // decizie: mai jos, cu legătura ei
            }
            if (in_array($ev['fel'], ['implicit', 'cod'], true)) {
                $setari[$cheie] = [
                    'valoare' => $ev['valori'][0],
                    'mod' => (string) ($automata['mod'] ?? 'lipsa'),
                    'din' => $ev['fel'],
                    'privat' => (bool) ($automata['privat'] ?? false),
                    'sursa' => $ev['fel'] === 'implicit' ? 'Settings::DEFAULTS' : '$settings[\'' . $cheie . '\'] ?? … (rezerva din cod)',
                ] + (isset($automata['nota']) ? ['nota' => (string) $automata['nota']] : []);
                continue;
            }
            $erori[] = 'decizie lipsă pentru „' . $cheie . '” (codul vechi: ' . $legaturi[$cheie]['vechi'] . '; codul comun: ' . $legaturi[$cheie]['nucleu']
                . '): o setare în „setari” {"valoare", "mod", "din": "manual" | "extras", "sursa"} sau un motiv în „fara_inghetare”';
        }

        // Deciziile: setările scrise de mână (oricare cheie) și cele fără înghețare (doar unde codurile diferă).
        $legatura = static function (string $cheie) use ($legaturi): array {
            return $legaturi[$cheie] ?? ['vechi' => 'lipsește', 'nucleu' => 'lipsește'];
        };
        $verificaLegatura = static function (string $unde, array $intrare, array $acum) use (&$erori): void {
            if (!array_key_exists('vechi', $intrare) && !array_key_exists('nucleu', $intrare)) {
                return; // decizie nouă (sau confirmată din nou): se leagă acum
            }
            if (($intrare['vechi'] ?? null) !== $acum['vechi'] || ($intrare['nucleu'] ?? null) !== $acum['nucleu']) {
                $erori[] = 'decizie de refăcut: ' . $unde . ' a fost luată când codul vechi era ' . json_encode($intrare['vechi'] ?? null, JSON_UNESCAPED_UNICODE)
                    . ' și codul comun ' . json_encode($intrare['nucleu'] ?? null, JSON_UNESCAPED_UNICODE) . '; acum: ' . $acum['vechi'] . ' / ' . $acum['nucleu']
                    . '. Dacă rămâne, șterge „vechi” și „nucleu” din intrare (se leagă din nou); altfel schimb-o';
            }
        };
        foreach ($existentSetari as $cheie => $intrare) {
            if (!$esteDecizie($intrare)) {
                continue;
            }
            $acum = $legatura($cheie);
            $verificaLegatura('setari.' . $cheie, $intrare, $acum);
            $din = (string) ($intrare['din'] ?? 'manual');
            $sursaIntrarii = trim((string) ($intrare['sursa'] ?? ''));
            if ($sursaIntrarii === '') {
                $erori[] = 'setari.' . $cheie . ': o decizie spune de unde vine valoarea („sursa”: fișierul și rândul codului vechi, sau decizia)';
            }
            if ($din === 'extras') {
                $extras = $vechi['extrase'][$cheie] ?? ['eroare' => 'nu s-a cerut'];
                if (!isset($extras['valoare'])) {
                    $erori[] = 'setari.' . $cheie . ': valoarea din codul vechi nu se poate scoate („extrage”): ' . ($extras['eroare'] ?? '?');
                    continue;
                }
                $valoare = (string) $extras['valoare'];
                $privat = Inghetare::arePrivat($valoare) || ($intrare['privat'] ?? false) === true;
            } else {
                $valoare = (string) ($intrare['valoare'] ?? '');
                if (Inghetare::arePrivat($valoare) || ($intrare['privat'] ?? false) === true) {
                    $erori[] = 'setari.' . $cheie . ': o valoare scrisă de mână nu poate fi privată (ar sta în git, OD-49): scoate-o din codul vechi '
                        . 'cu „din”: „extras” și „extrage”';
                    continue;
                }
                $privat = false;
            }
            $setari[$cheie] = [
                'valoare' => $valoare,
                'mod' => (string) ($intrare['mod'] ?? 'lipsa'),
                'din' => $din,
                'privat' => $privat,
                'sursa' => $sursaIntrarii,
            ] + ($din === 'extras' ? ['extrage' => $intrare['extrage'] ?? []] : []) + $acum
                + (isset($intrare['nota']) ? ['nota' => (string) $intrare['nota']] : []);
        }
        foreach ($existentFara as $cheie => $intrare) {
            if (!isset($legaturi[$cheie])) {
                $scoase[] = 'fara_inghetare.' . $cheie . ': cheia nu mai apare în niciun cod';
                continue;
            }
            $verificaLegatura('fara_inghetare.' . $cheie, $intrare, $legaturi[$cheie]);
            $fara[$cheie] = ['motiv' => trim((string) ($intrare['motiv'] ?? ''))] + $legaturi[$cheie];
            if ($fara[$cheie]['motiv'] === '') {
                $erori[] = 'fara_inghetare.' . $cheie . ': motivul lipsește';
            }
        }
        ksort($setari, SORT_STRING);
        ksort($fara, SORT_STRING);

        // Datele private: înlocuitorul în git, valoarea adevărată în fișierul privat.
        $private = [];
        foreach ($setari as $cheie => &$intrare) {
            if (!$intrare['privat'] && Inghetare::arePrivat($intrare['valoare'])) {
                $intrare['privat'] = true;
            }
            if ($intrare['privat']) {
                $private[$cheie] = $intrare['valoare'];
                $intrare['valoare'] = Inghetare::inlocuitor($intrare['valoare']);
            }
        }
        unset($intrare);

        $document = ['_despre' => self::DESPRE, 'format' => Inghetare::FORMAT, 'site' => $site, 'sursa' => $sursa];
        $motivProvizoriu = $provizoriu === null ? ($existent['provizoriu'] ?? null) : ($provizoriu === false ? null : $provizoriu);
        if (is_string($motivProvizoriu) && trim($motivProvizoriu) !== '') {
            $document['provizoriu'] = trim($motivProvizoriu);
        }
        $document['amprenta_nucleu'] = self::amprenta($nucleu);
        $document['setari'] = $setari === [] ? new stdClass() : array_map(static function (array $i): array {
            $ordonat = [];
            foreach (['valoare', 'mod', 'din', 'privat', 'sursa', 'extrage', 'vechi', 'nucleu', 'nota'] as $camp) {
                if (array_key_exists($camp, $i) && !($camp === 'privat' && $i[$camp] === false)) {
                    $ordonat[$camp] = $i[$camp];
                }
            }
            return $ordonat;
        }, $setari);
        $document['fara_inghetare'] = $fara === [] ? new stdClass() : $fara;
        $text = self::json($document);

        $textPrivat = null;
        if ($private !== []) {
            $textPrivat = self::json(['_despre' => self::DESPRE_PRIVAT, 'site' => $site, 'sursa' => $sursa,
                'pentru' => hash('sha256', $text), 'setari' => $private]);
        }
        if (Inghetare::arePrivat($text)) {
            $erori[] = 'fișierul ar avea date private (o adresă de email sau un telefon) în afara valorilor „privat” (într-o notă, o sursă, un motiv): scoate-le';
        }
        try {
            Inghetare::dinText($text, Inghetare::FISIER);
        } catch (\Throwable $e) {
            $erori[] = 'fișierul generat nu trece de citirea runner-ului: ' . $e->getMessage();
        }
        $numara = static fn (string $din): int => count(array_filter($setari, static fn (array $i): bool => $i['din'] === $din));
        $rezumat = $site . ': ' . count($setari) . ' setări (' . $numara('implicit') . ' din DEFAULTS, ' . $numara('cod') . ' din rezervele codului, '
            . ($numara('manual') + $numara('extras')) . ' decizii), ' . count($fara) . ' fără înghețare, ' . count($private) . ' private; din ' . $sursa
            . (isset($document['provizoriu']) ? ' (PROVIZORIU)' : '');
        return ['text' => $text, 'privat' => $textPrivat, 'erori' => $erori, 'scoase' => $scoase, 'rezumat' => $rezumat];
    }

    public static function json(array $date): string
    {
        return json_encode($date, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Cererile „extrage” ale deciziilor din fișierul de acum (pentru procesul-copil al codului vechi).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cereriExtrase(?array $existent): array
    {
        $cereri = [];
        foreach ((array) ($existent['setari'] ?? []) as $cheie => $intrare) {
            if (is_array($intrare) && ($intrare['din'] ?? '') === 'extras' && is_array($intrare['extrage'] ?? null)) {
                $cereri[(string) $cheie] = $intrare['extrage'];
            }
        }
        return $cereri;
    }
}
