<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

/**
 * Fișierele din wp-content/uploads/ care trec pe site-ul nou, la aceeași cale (corectura 1.2, VC-MEDIA-001/002): în
 * `<docroot-ul nou>/wp-content/uploads/<cale>` (docroot-ul vine din opțiunea `--public` a lui bin/platforma, deci
 * public_html.nou/ la adoptare), cu aceiași octeți. Se copiază fiecare atașament (poza, mărimile făcute de WordPress,
 * originalul unei poze „-scaled”) și fiecare fișier pomenit de conținut sau de foile lui, chiar dacă nu e atașament.
 *
 * Doar fișiere obișnuite, cu extensii de media și documente, din afara dosarelor private ale pluginurilor (descărcările
 * WooCommerce, copiile de siguranță, jurnalele). Se refuză, cu motivul: legăturile simbolice (fișierul sau un dosar de
 * pe drum), ce iese din dosarul uploads prin `realpath`, fișierele peste 200 MB, numele cu o extensie executabilă
 * înăuntru (`poza.php.png`: un server care leagă PHP de „.php” oriunde în nume l-ar rula), fișierele cu cod PHP înăuntru
 * (oricare ar fi extensia; motivPhp: `<?php`, iar `<?=` și `<?` urmat de spațiu, pe care PHP le rulează și ele) și
 * SVG-urile pe care App\Support\SvgSigur nu le primește.
 *
 * Fișierele se COPIAZĂ (WordPress rămâne neatins, pentru revenire); o copie existentă, de aceeași mărime și dată, se
 * sare, deci importul se poate relua. Fiecare fișier copiat sau la zi intră în manifest cu sha256 de pe disc (sursa și
 * copia trebuie să aibă același sha256).
 */
final class Media
{
    public const EXTENSII = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'pdf', 'mp4', 'm4v', 'webm', 'ogv', 'mov',
        'mp3', 'm4a', 'ogg', 'oga', 'wav', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'woff', 'woff2', 'ttf', 'otf'];

    /** Dosarele din uploads/ care nu se copiază niciodată (date private ale pluginurilor). */
    public const PRIVATE = ['woocommerce_uploads/', 'wc-logs/', 'wpforms/', 'gravity_forms/', 'edd/', 'updraft/', 'backwpup', 'ai1wm-backups/',
        'backup', 'wp-file-manager', 'cache/', 'et-cache/', 'wp-staging', 'mailpoet/', 'complianz/', 'elementor/css/', 'wpo-cache/'];

    /** Dosarul din docroot în care ajung fișierele (aceeași cale ca pe WordPress). */
    public const DOSAR_DOCROOT = 'wp-content/uploads';

    /** Fișierele mai mari nu se copiază („peste 200 MB”): 200 MiB. */
    public const MARIME_MAXIMA = 200 * 1024 * 1024;

    /** Extensiile text: în ele orice `<?=` sau `<?` urmat de spațiu e cod PHP (în celelalte, doar o bucată de cod: motivPhp). */
    public const EXTENSII_TEXT = ['svg', 'txt', 'csv'];

    /** Extensiile executabile, refuzate și înăuntrul numelui (`x.php.png`), ca în șabloanele .htaccess din deploy/stub/. */
    private const EXECUTABILE = '(php[0-9]?|phtml|phar|pht|phps|pl|py|cgi|sh|asp|aspx|jsp|shtml)';

    /**
     * Lista fișierelor de copiat: [cale relativă => [sursa, mărimea, atașamentul sau 0]], plus ce lipsește sau se sare.
     *
     * @param list<string> $pomenite
     * @return array{fisiere: array<string, array{sursa: string, marime: int, atasament: int}>, lipsa: list<string>, sarite: list<string>, octeti: int}
     */
    public static function lista(Inventar $i, string $dosarUploads, array $pomenite): array
    {
        $dosarUploads = rtrim($dosarUploads, '/');
        $candidati = [];
        foreach ($i->atasamente as $id => $a) {
            foreach (array_merge([$a['fisier'], $a['original']], array_column($a['marimi'], 'fisier')) as $rel) {
                if ($rel !== '') {
                    $candidati[$rel] = $id;
                }
            }
        }
        foreach ($pomenite as $rel) {
            $candidati[$rel] ??= 0;
        }
        ksort($candidati);
        $rez = ['fisiere' => [], 'lipsa' => [], 'sarite' => [], 'octeti' => 0];
        $real = realpath($dosarUploads);
        foreach ($candidati as $rel => $id) {
            $motiv = self::motivRefuz($rel);
            if ($motiv !== null) {
                $rez['sarite'][] = $rel . ' (' . $motiv . ')';
                continue;
            }
            $sursa = $dosarUploads . '/' . $rel;
            if (self::legaturaPeDrum($dosarUploads, $rel)) {
                $rez['sarite'][] = $rel . ' (legătură simbolică)';
                continue;
            }
            if (!is_file($sursa)) {
                // Un atașament fără mărimea lui pe disc (ștearsă de un plugin de optimizare) e obișnuit; poza mare contează.
                $rez['lipsa'][] = $rel;
                continue;
            }
            $realSursa = realpath($sursa);
            if ($real === false || $realSursa === false || !str_starts_with($realSursa, $real . '/')) {
                $rez['sarite'][] = $rel . ' (în afara dosarului uploads)';
                continue;
            }
            $marime = (int) filesize($sursa);
            if ($marime > self::MARIME_MAXIMA) {
                $rez['sarite'][] = $rel . ' (peste 200 MB: ' . number_format($marime / 1048576, 1, ',', '') . ' MB)';
                continue;
            }
            $php = self::motivPhp($sursa, $rel);
            if ($php !== null) {
                $rez['sarite'][] = $rel . ' (' . $php . ')';
                continue;
            }
            $rez['fisiere'][$rel] = ['sursa' => $sursa, 'marime' => $marime, 'atasament' => (int) $id, 'radacina' => $dosarUploads];
            $rez['octeti'] += $marime;
        }
        return $rez;
    }

    /** Calea (relativă la uploads/) e într-un dosar privat al unui plugin (PRIVATE: la început, sau oriunde pentru cele fără „/”)? */
    public static function dosarPrivat(string $rel): bool
    {
        $rel = strtolower($rel);
        foreach (self::PRIVATE as $p) {
            if (str_starts_with($rel, $p) || (!str_ends_with($p, '/') && str_contains($rel, $p))) {
                return true;
            }
        }
        return false;
    }

    /** De ce nu se copiază un fișier (extensia, un dosar privat, o cale ciudată), sau null dacă se copiază. */
    public static function motivRefuz(string $rel): ?string
    {
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('#(^|/)\.#', $rel) === 1) {
            return 'cale nepermisă';
        }
        if (self::dosarPrivat($rel)) {
            return 'dosar privat al unui plugin';
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSII, true)) {
            return 'extensia .' . $ext . ' nu e de media sau document';
        }
        if (preg_match('/\.' . self::EXECUTABILE . '\./i', basename($rel)) === 1) {
            return 'o extensie executabilă înăuntrul numelui';
        }
        return null;
    }

    /**
     * Sursa unui fișier din lista(), verificată din nou chiar înainte de copiere: [calea reală, stat-ul ei], sau motivul
     * refuzului. Fără `radacina` (o listă făcută altfel decât prin lista()) fișierul nu se copiază.
     *
     * @param array{sursa: string, marime: int, atasament: int, radacina?: string} $f
     * @return array{0: string, 1: array<string|int, int>}|string
     */
    private static function sursaVerificata(array $f, string $rel): array|string
    {
        $radacina = rtrim((string) ($f['radacina'] ?? ''), '/');
        if ($radacina === '' || $f['sursa'] !== $radacina . '/' . $rel) {
            return 'sursa nu vine din lista dosarului uploads';
        }
        if (self::legaturaPeDrum($radacina, $rel)) {
            return 'legătură simbolică apărută între plan și copiere';
        }
        clearstatcache();
        $real = realpath($radacina);
        $realSursa = realpath($f['sursa']);
        if ($real === false || $realSursa === false || !str_starts_with($realSursa, $real . '/') || !is_file($realSursa)) {
            return 'în afara dosarului uploads';
        }
        $stat = @stat($realSursa);
        if (!is_array($stat)) {
            return 'nu se poate citi';
        }
        if ((int) $stat['size'] > self::MARIME_MAXIMA) {
            return 'peste 200 MB';
        }
        return [$realSursa, $stat];
    }

    /** Fișierul sau unul dintre dosarele dintre `$baza` și el e o legătură simbolică? */
    public static function legaturaPeDrum(string $baza, string $rel): bool
    {
        $cale = rtrim($baza, '/');
        foreach (explode('/', $rel) as $parte) {
            $cale .= '/' . $parte;
            if (is_link($cale)) {
                return true;
            }
        }
        return false;
    }

    /** Fișierul are cod PHP înăuntru (motivPhp)? */
    public static function arePhp(string $fisier, ?string $rel = null): bool
    {
        return self::motivPhp($fisier, $rel) !== null;
    }

    /**
     * De ce fișierul ar putea rula ca PHP (citit pe bucăți, cu suprapunere), sau null:
     *  - `<?php` oriunde, oricare ar fi extensia (cu litere mari sau mici);
     *  - într-un fișier text (EXTENSII_TEXT, după `$rel` sau numele fișierului), `<?=` sau `<?` urmat de spațiu: PHP rulează
     *    `<?=` mereu și `<?` cu short_open_tag;
     *  - orice alt „<?” (M1c: fiecare, presupunând short_open_tag=1, inclusiv un short tag lipit „<?echo”), urmat de cod PHP pe
     *    care PHP l-ar COMPILA și rula (codDupa). Cei doi octeți apar la întâmplare în pozele comprimate, dar acolo urmează
     *    octeți la întâmplare, pe care PHP nu-i poate compila (o eroare de compilare înainte de margine, deci nu rulează nimic);
     *    un poliglot adevărat are cod valid, închis de `?>` sau `__halt_compiler()` înaintea datelor binare. Fereastra de
     *    compilare se mărește dacă padding-ul (spații, un comentariu sau un șir lung) a tăiat codul la margine, până la EOF sau
     *    8 MiB (atunci se refuză). `<?xml` dintr-un SVG nu compilează ca PHP, deci nu se refuză pentru el.
     * Un fișier necitibil întoarce un motiv: nu-l putem garanta, deci nu se copiază.
     */
    public static function motivPhp(string $fisier, ?string $rel = null): ?string
    {
        $f = @fopen($fisier, 'rb');
        if ($f === false) {
            return 'nu se poate citi';
        }
        $text = in_array(strtolower(pathinfo($rel ?? $fisier, PATHINFO_EXTENSION)), self::EXTENSII_TEXT, true);
        // F5: un fișier de „<?<?<?…” declanșează codDupa pe fiecare „<?” (O(numărul de „<?” × fereastră)): ~27 min pe 200 MiB.
        // Mărginim numărul de sonde codDupa pe fișier, proporțional cu mărimea (cu un prag generos). Mediile adevărate au „<?”
        // la întâmplare (cam o dată la 64 KB), deci rămân mult sub buget; peste el, fișierul se refuză fail-closed.
        $buget = max(self::SONDE_MINIM, ((int) @filesize($fisier)) >> 12);
        $sonde = 0;
        $coada = '';
        $pozCoada = 0;
        try {
            while (!feof($f)) {
                $bucata = fread($f, 1 << 20);
                if ($bucata === false) {
                    return 'nu se poate citi';
                }
                $fereastra = $coada . $bucata;
                if (stripos($fereastra, '<?php') !== false) {
                    return 'conține <?php';
                }
                $poz = 0;
                while (($poz = strpos($fereastra, '<?', $poz)) !== false) {
                    $urm = $fereastra[$poz + 2] ?? '';
                    // M1c: se verifică FIECARE „<?”, presupunând short_open_tag=1 (un „<?echo” lipit rulează la fel ca „<? echo”).
                    // Într-un fișier text, „<?=” și „<?” urmat de spațiu rulează sigur (cu sau fără short_open_tag): refuz imediat.
                    // Orice alt „<?” (inclusiv un short tag lipit, și în binar) se decide prin compilarea a ce urmează (codDupa):
                    // „<?xml” dintr-un SVG nu compilează ca PHP (eroare de parsare), deci nu se refuză; un „<?echo …” da.
                    if ($text && ($urm === '=' || ($urm !== '' && strpbrk($urm, " \t\r\n") !== false))) {
                        return 'conține ' . ($urm === '=' ? '<?=' : '<? urmat de spațiu') . ' (PHP)';
                    }
                    if (++$sonde > $buget) {
                        return 'prea multe „<?” de verificat (peste ' . $buget . '): fișier refuzat ca suspect';
                    }
                    if (self::codDupa($f, $pozCoada + $poz)) {
                        return 'conține cod PHP după „<?” (compilează ca PHP)';
                    }
                    $poz++;
                }
                // Ultimii 4 octeți rămân pentru bucata următoare: „<?php” și „<?=” care trec de margine.
                $coada = substr($fereastra, -4);
                $pozCoada = (int) ftell($f) - strlen($coada);
            }
        } finally {
            fclose($f);
        }
        return null;
    }

    /** Sub atâtea sonde codDupa un fișier nu se refuză pentru numărul lor (un fișier mic cu câteva „<?”). */
    private const SONDE_MINIM = 16384;

    /** O fereastră de compilare pornește de la 64 KiB și se dublează la nevoie până aici; peste atât, fișierul se refuză. */
    private const FEREASTRA_COMPILARE_MAX = 8 * 1024 * 1024;

    /**
     * De la deschiderea tagului PHP (`$pozTag` chiar pe „<?”) urmează cod PHP pe care PHP l-ar compila și rula? PHP compilează
     * tot fișierul înainte să ruleze, deci octeții la întâmplare dintr-o poză dau o eroare de compilare înainte de margine și nu
     * rulează nimic; un poliglot adevărat are cod valid, închis de `?>` sau `__halt_compiler()` înaintea datelor binare. Tagul se
     * rescrie în `<?php` (short_open_tag-ul PHP-ului curent n-ar parsa altfel un „<? ” sau „<?echo”; presupunem short_open_tag=1),
     * apoi token_get_all cu TOKEN_PARSE.
     *
     * M1c: fereastra pornește de la 64 KiB, dar dacă eroarea de compilare e „sfârșit de intrare” chiar la marginea ei (codul a
     * fost tăiat de padding: spații, un comentariu sau un șir lung înainte de cod), rezultatul e neconcludent — fereastra se
     * mărește (se dublează), până la sfârșitul fișierului. Dacă peste 8 MiB tot nu s-a închis (încă „așteaptă” intrare), fișierul
     * se refuză (un PHP valabil de peste 8 MiB deghizat în media e suspect). O eroare de compilare adevărată ÎNAINTE de margine
     * înseamnă că nu e PHP (octeți la întâmplare). Dacă fișierul se termină în mijlocul parsării, PHP n-ar rula întregul fișier.
     */
    private static function codDupa($f, int $pozTag): bool
    {
        $inapoi = (int) ftell($f);
        try {
            $cerut = 65536;
            while (true) {
                if (fseek($f, $pozTag) !== 0) {
                    return false;
                }
                $fereastra = (string) fread($f, $cerut);
                $laEof = strlen($fereastra) < $cerut; // am primit mai puțin decât am cerut → fereastra ajunge la sfârșitul fișierului
                if (str_starts_with($fereastra, '<?php')) {
                    $php = $fereastra;
                } elseif (str_starts_with($fereastra, '<?=')) {
                    $php = '<?php echo ' . substr($fereastra, 3);
                } elseif (str_starts_with($fereastra, '<?')) {
                    $php = '<?php ' . substr($fereastra, 2);
                } else {
                    return false;
                }
                try {
                    @token_get_all($php, TOKEN_PARSE);
                    return true; // compilează întreagă fereastra → fișierul chiar ar rula
                } catch (\Throwable $e) {
                    if (!self::sfarsitPrematur($e)) {
                        return false; // eroare de compilare înainte de margine: nu e PHP (octeți la întâmplare)
                    }
                    if ($laEof) {
                        return false; // fișierul se termină în mijlocul parsării: PHP n-ar compila/rula întregul fișier
                    }
                    if ($cerut >= self::FEREASTRA_COMPILARE_MAX) {
                        return true; // peste 8 MiB încă „așteaptă” intrare, deci compilează: îl refuzăm
                    }
                    $cerut *= 2;
                }
            }
        } finally {
            fseek($f, $inapoi);
        }
    }

    /** Eroarea de compilare e „sfârșit de intrare” (codul a fost tăiat de marginea ferestrei), nu o eroare adevărată? */
    private static function sfarsitPrematur(\Throwable $e): bool
    {
        $m = $e->getMessage();
        return str_contains($m, 'unexpected end of file') || str_contains($m, 'Unterminated');
    }

    /**
     * Copiază fișierele în $tinta (`<docroot>/wp-content/uploads`), la aceeași cale relativă, sărind copiile la zi
     * (aceeași mărime și dată). Un SVG trece doar dacă e sigur (App\Support\SvgSigur): altfel se sare, cu motivul. Sursa se
     * verifică din nou chiar înainte de copiere, ca un fișier schimbat între plan și copiere să nu treacă: nicio legătură
     * simbolică pe drumul ei de la dosarul uploads (`radacina`, din lista()), `realpath` tot în el, cel mult 200 MB, fără cod
     * PHP; apoi se deschide o singură dată, iar fișierul deschis trebuie să fie chiar cel verificat (dispozitivul și inodul),
     * deci un dosar înlocuit între timp cu o legătură spre afara lui nu se urmează. Manifestul: sha256 de pe disc al fiecărui
     * fișier copiat sau la zi (octeții citiți = copia; altfel EroareImport).
     *
     * @param array<string, array{sursa: string, marime: int, atasament: int, radacina?: string}> $fisiere
     * @return array{copiate: int, la_zi: int, octeti: int, sarite: list<string>, manifest: array<string, array{sha256: string, marime: int}>}
     */
    public static function copiaza(array $fisiere, string $tinta): array
    {
        $rez = ['copiate' => 0, 'la_zi' => 0, 'octeti' => 0, 'sarite' => [], 'manifest' => []];
        $tinta = rtrim($tinta, '/');
        if (!is_dir($tinta) && !@mkdir($tinta, 0755, true) && !is_dir($tinta)) {
            throw new EroareImport('nu pot face dosarul ' . $tinta);
        }
        foreach ($fisiere as $rel => $f) {
            if (self::motivRefuz($rel) !== null || is_link($f['sursa']) || self::legaturaPeDrum($tinta, $rel)) {
                $rez['sarite'][] = $rel . ' (cale sau legătură nepermisă)';
                continue;
            }
            $verificata = self::sursaVerificata($f, (string) $rel);
            if (is_string($verificata)) {
                $rez['sarite'][] = $rel . ' (' . $verificata . ')';
                continue;
            }
            [$realSursa, $stat] = $verificata;
            $dest = $tinta . '/' . $rel;
            if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'svg' && class_exists(\App\Support\SvgSigur::class)
                && ($problema = \App\Support\SvgSigur::problemaFisier((string) @file_get_contents($realSursa))) !== null) {
                $rez['sarite'][] = $rel . ' (SVG nesigur: ' . $problema . ')';
                continue;
            }
            $php = self::motivPhp($realSursa, (string) $rel);
            if ($php !== null) {
                $rez['sarite'][] = $rel . ' (' . $php . ')';
                continue;
            }
            $sursa = @fopen($realSursa, 'rb');
            $deschis = is_resource($sursa) ? fstat($sursa) : false;
            if (!is_array($deschis) || $deschis['dev'] !== $stat['dev'] || $deschis['ino'] !== $stat['ino']) {
                if (is_resource($sursa)) {
                    fclose($sursa);
                }
                $rez['sarite'][] = $rel . ' (fișierul s-a schimbat între verificare și copiere)';
                continue;
            }
            try {
                $sha = (string) hash_file('sha256', $realSursa);
                $mtime = (int) $deschis['mtime'];
                if (is_file($dest) && (int) filesize($dest) === $f['marime'] && (int) filemtime($dest) === $mtime) {
                    if ((string) hash_file('sha256', $dest) !== $sha) {
                        throw new EroareImport('copia ' . $dest . ' are aceeași mărime și dată ca sursa, dar alți octeți; o ștergi și reiei importul');
                    }
                    $rez['la_zi']++;
                    $rez['manifest'][$rel] = ['sha256' => $sha, 'marime' => $f['marime']];
                    continue;
                }
                $dosar = dirname($dest);
                if (!is_dir($dosar) && !@mkdir($dosar, 0755, true) && !is_dir($dosar)) {
                    throw new EroareImport('nu pot face dosarul ' . $dosar);
                }
                $temp = $dest . '.import-' . getmypid();
                $scris = @fopen($temp, 'wb');
                $copiat = is_resource($scris) ? stream_copy_to_stream($sursa, $scris) : false;
                if (is_resource($scris)) {
                    fclose($scris);
                }
                if ($copiat === false) {
                    @unlink($temp);
                    throw new EroareImport('nu pot copia ' . $f['sursa'] . ' în ' . $dest . ' (spațiul pe disc? drepturile?)');
                }
            } finally {
                fclose($sursa);
            }
            if ((string) hash_file('sha256', $temp) !== $sha) {
                @unlink($temp);
                throw new EroareImport('copia lui ' . $f['sursa'] . ' nu are aceiași octeți (sha256)');
            }
            @chmod($temp, 0644);
            @touch($temp, $mtime);
            if (!@rename($temp, $dest)) {
                @unlink($temp);
                throw new EroareImport('nu pot scrie ' . $dest);
            }
            $rez['copiate']++;
            $rez['octeti'] += $f['marime'];
            $rez['manifest'][$rel] = ['sha256' => $sha, 'marime' => $f['marime']];
        }
        ksort($rez['manifest']);
        return $rez;
    }

    /**
     * Manifestul mediilor copiate (`storage/import-wordpress/media.json`): adresa, calea relativă, sha256 de pe disc și
     * mărimea fiecărui fișier. Captura (pasul 4) îl ia în manifestul ei.
     *
     * @param array<string, array{sha256: string, marime: int}> $manifest
     */
    public static function scrieManifest(array $manifest, string $fisier): void
    {
        $randuri = [];
        foreach ($manifest as $rel => $m) {
            $randuri[] = ['adresa' => Adrese::media($rel), 'cale' => self::DOSAR_DOCROOT . '/' . $rel, 'sha256_disc' => $m['sha256'], 'marime' => $m['marime'],
                'sursa' => 'wp-content/uploads'];
        }
        $json = json_encode(['format' => 1, 'fisiere' => $randuri], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || @file_put_contents($fisier . '.tmp', $json . "\n") === false || !@rename($fisier . '.tmp', $fisier)) {
            @unlink($fisier . '.tmp');
            throw new EroareImport('nu pot scrie manifestul mediilor (' . $fisier . ')');
        }
    }
}
