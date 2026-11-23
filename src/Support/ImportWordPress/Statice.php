<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use App\Support\ImportWordPress\Captura\CopiereStatica;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\EroareCaptura;
use App\Support\ImportWordPress\Captura\FonturiGoogle;
use App\Support\ImportWordPress\Captura\Normalizare;

/**
 * Fișierele statice ale paginilor capturate, publicate în docroot-ul nou la adoptare (deploy/adopta-wp.sh, faza `pregatire`;
 * scripts/publica-statice.php; docs/DEPLOY.md, „Fișierele statice”): foile temei și ale pluginurilor, `et-cache/`, fișierele din
 * `wp-includes/`, fonturile și pozele temei, adică tot ce cer paginile din manifestul capturii verificate (`fisiere`), în afară de
 * `wp-content/uploads/` (mediile, copiate de import: Media) și de scripturi (aruncate: comportamentul îl dă runtime-ul compat),
 * plus fonturile Google aduse de captură (`fonturi`, în `capturat/fonturi/`).
 *
 * Fiecare fișier se copiază de pe discul WordPress (doar citit) cu CopiereStatica::copiaza: aceleași verificări ca mediile (fără
 * legături simbolice sau tari, `realpath` în rădăcina WordPress, fără fișiere ascunse, fără `.php`/`.js` sau o bucată executabilă
 * în nume, fără cod PHP înăuntru, SVG-urile prin SvgSigur, cel mult 200 MB pe fișier), dintr-o singură deschidere, scris atomic,
 * cu adresele proprii din foi relative. Apoi:
 *  - sha256-ul sursei trebuie să fie cel din manifestul capturii (altfel WordPress s-a schimbat de la captură: copia se scoate, NU);
 *  - o coliziune cu un fișier sau o rută a CMS-ului (fișierele de la rădăcină, sub `capturat/radacina/`) e NU;
 *  - un fișier refuzat de verificări rămâne necopiat, cu motivul (ATENȚIE; verificarea de după comutare îl arată);
 *  - plafoanele: cel mult MAX_FISIERE fișiere și MAX_OCTETI octeți în total (NU);
 *  - stilurile comune ale pachetului (`import-wordpress.json` → `stiluri_comune`, StiluriComune): regulile unui layout Theme
 *    Builder rămase vechi în foaia `et-cache` a unor pagini se înlocuiesc cu cele ale paginii-sursă, în copiile publicate (sha256-ul
 *    copiei din manifest e al foii rescrise); `stiluri` din rezultat spune ce pagini și ce foi.
 * Manifestul, cu sha256-ul sursei și al copiei: `storage/import-wordpress/statice.json` (îl citesc `diagnostic.php --capturat` și
 * verificarea adoptării, CadruCapturat::verificaFisiereManifest).
 */
final class Statice
{
    public const MAX_FISIERE = 20000;
    public const MAX_OCTETI = 2 * 1024 * 1024 * 1024;

    /** Mediile WordPress: le copiază importul (Media), la aceeași cale și cu aceiași octeți. */
    public const UPLOADS = '/wp-content/uploads/';

    /**
     * @param array<string, string> $stiluriComune `import-wordpress.json` → `stiluri_comune` (marcaj → calea paginii-sursă), validat
     * @return array{copiate: int, octeti: int, necopiate: list<array{cale: string, motiv: string}>, probleme: list<string>, fonturi: int,
     *   manifest: array<string, mixed>, stiluri: array{pagini: list<string>, foi: list<string>}}
     */
    public static function publica(string $dosarWp, string $dosarCaptura, string $shaRaport, string $url, string $docroot, bool $aplica, array $stiluriComune = []): array
    {
        $rez = ['copiate' => 0, 'octeti' => 0, 'necopiate' => [], 'probleme' => [], 'fonturi' => 0, 'manifest' => [], 'stiluri' => ['pagini' => [], 'foi' => []]];
        try {
            $dosar = DosarCapturi::deschideVerificat($dosarCaptura, $shaRaport, $url);
        } catch (EroareCaptura $e) {
            $rez['probleme'][] = 'captura ' . $dosarCaptura . ': ' . $e->getMessage();
            return $rez;
        }
        $m = $dosar->manifest();
        $normalizare = new Normalizare((string) ($m['url'] ?? $url));
        $fisiere = [];
        $planFoi = [];
        $numar = 0;
        $total = 0;
        foreach (is_array($m['fisiere'] ?? null) ? $m['fisiere'] : [] as $f) {
            $cale = is_array($f) ? (string) ($f['cale'] ?? '') : '';
            if ($cale === '' || !str_starts_with($cale, '/') || str_starts_with(strtolower($cale), self::UPLOADS)) {
                continue;
            }
            $intrare = ['url' => (string) ($f['url'] ?? ''), 'cale' => $cale];
            if (($f['fel'] ?? '') === 'script' || preg_match('/\.(?:m|c)?js$/i', $cale) === 1) {
                $fisiere[] = $intrare + ['necopiat' => 'script (aruncat: comportamentul îl dă runtime-ul compat)'];
                continue;
            }
            $asteptat = (string) ($f['sha256_sursa'] ?? $f['sha256'] ?? '');
            if (($col = CopiereStatica::coliziune($cale, $docroot)) !== null) {
                $rez['probleme'][] = 'coliziune: ' . $col;
                continue;
            }
            if (++$numar > self::MAX_FISIERE) {
                $rez['probleme'][] = 'peste ' . self::MAX_FISIERE . ' fișiere statice: nu le copiez (o captură greșită?)';
                break;
            }
            $total += (int) ($f['marime'] ?? 0);
            if ($total > self::MAX_OCTETI) {
                $rez['probleme'][] = 'fișierele statice trec de ' . intdiv(self::MAX_OCTETI, 1048576) . ' MB: nu le copiez (o captură greșită?)';
                break;
            }
            if (!$aplica) {
                $p = CopiereStatica::problema($dosarWp, $cale);
                if ($p !== null) {
                    $rez['necopiate'][] = ['cale' => $cale, 'motiv' => $p];
                } elseif (($sha = self::shaPeDisc($dosarWp, $cale)) !== null && $asteptat !== '' && !hash_equals($asteptat, $sha)) {
                    $rez['probleme'][] = 'fișierul de pe disc nu e cel din captură (alt sha256; WordPress s-a schimbat de la captură?): ' . $cale;
                } else {
                    $rez['copiate']++;
                    $planFoi[] = $cale;
                }
                continue;
            }
            try {
                $copie = CopiereStatica::copiaza($dosarWp, $docroot, $cale, $normalizare);
            } catch (EroareCaptura $e) {
                $rez['necopiate'][] = ['cale' => $cale, 'motiv' => $e->getMessage()];
                $fisiere[] = $intrare + ['necopiat' => $e->getMessage()];
                continue;
            }
            if ($asteptat === '' || !hash_equals($asteptat, $copie['sha256_sursa'])) {
                @unlink(rtrim($docroot, '/') . $copie['publicat']);
                $rez['probleme'][] = 'fișierul de pe disc nu e cel din captură (alt sha256; WordPress s-a schimbat de la captură?), copia scoasă: ' . $cale;
                continue;
            }
            $fisiere[] = $intrare + ['publicat' => $copie['publicat'], 'sha256_sursa' => $copie['sha256_sursa'], 'sha256_publicat' => $copie['sha256_publicat'],
                'marime' => $copie['marime']];
            $rez['copiate']++;
            $rez['octeti'] += $copie['marime'];
        }
        if ($stiluriComune !== [] && $rez['probleme'] === []) {
            // Foile et-cache: la copiere, copiile abia scrise; la plan, textul pe care copierea l-ar scrie (de pe discul WordPress).
            $foiCss = [];
            foreach ($aplica ? $fisiere : array_map(static fn (string $c): array => ['cale' => $c], $planFoi) as $f) {
                $cale = (string) $f['cale'];
                if (!str_contains($cale, '/et-cache/') || !str_ends_with($cale, '.css') || ($aplica && !isset($f['publicat']))) {
                    continue;
                }
                try {
                    $text = $aplica ? @file_get_contents(rtrim($docroot, '/') . $f['publicat']) : CopiereStatica::continut($dosarWp, $cale, $normalizare);
                } catch (EroareCaptura) {
                    $text = false;
                }
                if (is_string($text)) {
                    $foiCss[$cale] = $text;
                }
            }
            $fisiere = self::stiluriComune($dosar, $fisiere, $foiCss, $docroot, $aplica, $stiluriComune, $rez);
        }
        // Fonturile Google ale capturii (FonturiGoogle: în `fonturi/` al capturii, sha256-ul fiecăruia în manifestul verificat).
        $fonturi = [];
        $foi = is_array($m['fonturi'] ?? null) ? $m['fonturi'] : [];
        if ($foi !== [] && ($m['fonturi_dosar'] ?? '') !== FonturiGoogle::DOSAR_CAPTURA) {
            $rez['probleme'][] = 'fonturile Google ale capturii au fost publicate direct într-un docroot (captura cu --public), nu sunt în captură: '
                . 'refă captura fără --public (docs/DEPLOY.md, „Captura”)';
        } elseif ($foi !== [] && $rez['probleme'] === []) {
            foreach ($foi as $foaie) {
                foreach (array_merge([['fisier' => $foaie['fisier_css'] ?? '', 'sha256' => $foaie['sha256_css'] ?? '']], (array) ($foaie['fonturi'] ?? [])) as $x) {
                    if (is_array($x) && is_string($x['fisier'] ?? null) && $x['fisier'] !== '') {
                        $fonturi[] = ['publicat' => FonturiGoogle::DOSAR . '/' . $x['fisier'], 'sha256_publicat' => (string) ($x['sha256'] ?? '')];
                    }
                }
            }
            if ($aplica) {
                try {
                    $rez['fonturi'] = CopiereStatica::publicaFonturi($dosarCaptura, $foi, $docroot);
                } catch (EroareCaptura $e) {
                    $rez['probleme'][] = 'fonturile Google: ' . $e->getMessage();
                }
            } else {
                $rez['fonturi'] = count($fonturi);
            }
        }
        $rez['manifest'] = ['format' => 1, 'facut' => date('c'), 'captura' => $shaRaport, 'url' => (string) ($m['url'] ?? $url), 'fisiere' => $fisiere, 'fonturi' => $fonturi];
        return $rez;
    }

    /**
     * Stilurile comune (StiluriComune::unifica): paginile HTML ale capturii și foile `et-cache` (`$foi`, calea → textul copiei). La
     * copiere, o foaie rescrisă se scrie atomic, iar intrarea ei din manifest primește sha256-ul și mărimea noi; la plan, doar se
     * spune ce pagini și ce foi s-ar schimba și ce probleme ar fi.
     *
     * @param list<array<string, mixed>> $fisiere
     * @param array<string, string> $foi
     * @param array<string, string> $config
     * @param array<string, mixed> $rez
     * @return list<array<string, mixed>>
     */
    private static function stiluriComune(DosarCapturi $dosar, array $fisiere, array $foi, string $docroot, bool $aplica, array $config, array &$rez): array
    {
        $pagini = [];
        foreach ($dosar->toate() as $url => $i) {
            $tip = '';
            foreach ($i['antete'] as [$n, $v]) {
                $tip = strtolower((string) $n) === 'content-type' ? strtolower((string) $v) : $tip;
            }
            $cale = (string) parse_url((string) $url, PHP_URL_PATH);
            if ($i['status'] === 200 && str_contains($tip, 'text/html') && preg_match('#^/(?:[a-z0-9-]+/)*$#', $cale) === 1 && !isset($pagini[$cale])) {
                $r = $dosar->raspuns((string) $url);
                if ($r !== null) {
                    $pagini[$cale] = $r->corp;
                }
            }
        }
        $u = StiluriComune::unifica($pagini, $foi, $config);
        foreach ($u['probleme'] as $p) {
            $rez['probleme'][] = $p;
        }
        $rez['stiluri'] = ['pagini' => $u['pagini'], 'foi' => array_keys($u['foi'])];
        if (!$aplica) {
            return $fisiere;
        }
        foreach ($fisiere as $k => $f) {
            $cale = (string) ($f['cale'] ?? '');
            if (!isset($u['foi'][$cale], $f['publicat'])) {
                continue;
            }
            $tinta = rtrim($docroot, '/') . $f['publicat'];
            $tmp = dirname($tinta) . '/.copie-' . bin2hex(random_bytes(8));
            if (@file_put_contents($tmp, $u['foi'][$cale], LOCK_EX) !== strlen($u['foi'][$cale]) || !@chmod($tmp, 0644) || !@rename($tmp, $tinta)) {
                @unlink($tmp);
                $rez['probleme'][] = 'stiluri_comune: foaia ' . $cale . ' nu s-a putut rescrie';
                continue;
            }
            $fisiere[$k]['sha256_publicat'] = hash('sha256', $u['foi'][$cale]);
            $rez['octeti'] += strlen($u['foi'][$cale]) - (int) ($f['marime'] ?? 0);
            $fisiere[$k]['marime'] = strlen($u['foi'][$cale]);
        }
        return $fisiere;
    }

    /** sha256-ul unui fișier de pe discul WordPress (doar pentru plan; copierea îl citește din nou, verificat), sau null. */
    private static function shaPeDisc(string $radacina, string $cale): ?string
    {
        $f = rtrim($radacina, '/') . $cale;
        return is_file($f) && !is_link($f) ? (string) hash_file('sha256', $f) : null;
    }

    /** Scrie manifestul (atomic). */
    public static function scrieManifest(array $manifest, string $fisier): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || @file_put_contents($fisier . '.tmp', $json . "\n") === false || !@rename($fisier . '.tmp', $fisier)) {
            @unlink($fisier . '.tmp');
            throw new EroareImport('nu pot scrie manifestul fișierelor statice (' . $fisier . ')');
        }
    }

    /**
     * Manifestul fișierelor statice publicate (`statice.json`), ca hartă adresă → intrarea lui, pentru
     * CadruCapturat::verificaFisiereManifest. Fără fișier: []. @return array<string, array<string, mixed>>
     */
    public static function publicate(string $fisier): array
    {
        $d = is_file($fisier) ? json_decode((string) file_get_contents($fisier), true) : null;
        $rez = [];
        foreach (is_array($d) && is_array($d['fisiere'] ?? null) ? $d['fisiere'] : [] as $f) {
            if (is_array($f) && is_string($f['url'] ?? null) && $f['url'] !== '') {
                $rez[$f['url']] = $f;
            }
        }
        return $rez;
    }
}
