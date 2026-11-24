<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\CadruCapturat\EroareJeton;
use App\Support\CadruCapturat\Jetoane;

/**
 * Cadrul paginilor capturate (adoptarea unui site WordPress, pașii 5a și 6; DECIZIE §2.7 [design] 1, I-C §6, B Anexa C): un
 * document capturat (`pagini_capturate`, cu fragmentele lui din `fragmente_capturate`) se randează din cele cinci bucăți și
 * conținutul lui (`pages.html_content` pentru o pagină, coloana `continut` pentru 404, embed, arhive), apoi:
 *  1. fragmentele (`{{ax:fragment:antet|subsol|banner}}`), după cheile documentului (varianta de antet și subsol se alege pe pagină);
 *  2. toate celelalte jetoane, într-o singură trecere (Jetoane::rezolva): scripturile runtime-ului (`runtime:cap`, `runtime:final`),
 *     nonce-ul, datele comportamentului (`date`: `ax-date` refăcut din partea paginii și a fragmentelor), clasele și
 *     `aria-current` ale elementelor de meniu (`meniu:*`), numărul formularului din subsol (`formular_index`: formularele din
 *     conținut), calea cererii (`cale`), anul (`an`) și valorile SEO (`seo.*`).
 * Spațiile și rândurile dintre bucăți sunt cele din captură (tăierea le ține în bucăți). Un jeton necunoscut sau rămas e o
 * EroareJeton: răspunsul e 500 și un rând în jurnal, niciodată o pagină pe jumătate. Aceeași funcție o folosesc dovada tăierii
 * (la import), P1 și cererile web.
 */
final class CadruCapturat
{
    /** Începutul documentului, cum îl scrie cadrul. */
    public const DOCTYPE = "<!DOCTYPE html>\n";

    /** Fragmentele unui document, în ordinea din el. */
    public const FRAGMENTE = ['antet', 'subsol', 'banner'];

    /** Containerul managerului de consimțământ din pagina Cookie Policy (Complianz), gol în captură. */
    public const MANAGER_CMPLZ = 'id="cmplz-manage-consent-container"';

    /** Id-ul șablonului inert cu bannerul site-ului, pus pe pagina managerului (consimtamant.js îi copiază categoriile). */
    public const SABLON_CMPLZ = 'ax-cmplz-sablon';

    /** Forma unui nonce scris de Normalizare în captură (`{{ax:nonce}}`). */
    public const LOC_NONCE = '{{ax:nonce}}';

    /**
     * Documentul randat.
     *
     * @param array<string, mixed> $doc rândul din `pagini_capturate`, cu coloanele JSON decodate (dinJson): `date_pagina`
     *   (obiect: partea paginii din `ax-date`), `date_reteta` (rețeta ordinii), `meniu` (clasele și aria-current ale elementelor
     *   de meniu care diferă pe pagina aceasta de cele ale fragmentului), `valori_import` (cale, an, seo: valorile de la import)
     * @param array<string, array{html: string, date?: mixed, meniu?: array<string, array{0: string, 1: string}>}> $fragmente fel → fragmentul ales
     * @param array{cale: string, nonce: string, an: string, runtime?: array{cap?: string, final?: string}, seo?: array<string, string>, sablon_cmplz?: string, urmarire?: list<array<string, mixed>>|null} $cerere
     * @throws EroareJeton
     */
    public static function randeaza(array $doc, string $continut, array $fragmente, array $cerere): string
    {
        $html = self::asambleazaBucati((string) $doc['html_atribute'], (string) $doc['cap_html'], (string) $doc['body_atribute'], (string) $doc['inainte'],
            $continut, (string) $doc['dupa']);
        $html = self::puneFragmente($html, array_map(static fn (array $f): string => (string) $f['html'], $fragmente));
        $html = self::puneSablonManager($html, (string) ($cerere['sablon_cmplz'] ?? ''));
        $meniuPagina = self::lista($doc['meniu'] ?? []);
        $seoImport = self::lista(self::lista($doc['valori_import'] ?? [])['seo'] ?? []);
        $nonce = (string) $cerere['nonce'];
        $valori = static function (string $nume) use ($doc, $meniuPagina, $fragmente, $continut, $cerere, $seoImport, $nonce): ?string {
            if ($nume === 'nonce') {
                return $nonce;
            }
            if ($nume === 'runtime:cap' || $nume === 'runtime:final') {
                return (string) ($cerere['runtime'][substr($nume, 8)] ?? '');
            }
            if ($nume === 'cale') {
                return (string) $cerere['cale'];
            }
            if ($nume === 'an') {
                return (string) $cerere['an'];
            }
            if ($nume === 'formular_index') {
                return (string) self::formulareInContinut($continut);
            }
            if ($nume === 'date') {
                $reteta = self::lista($doc['date_reteta'] ?? null);
                if ($reteta === []) {
                    return null;
                }
                $d = self::asambleaza(self::obiecte($doc['date_pagina'] ?? null), self::obiecte($fragmente['antet']['date'] ?? null),
                    self::obiecte($fragmente['subsol']['date'] ?? null), $reteta);
                // Trackerele active (Urmarire): doar la randare, din pachet și din privat; importul nu poate scrie cheia.
                $u = $cerere['urmarire'] ?? Urmarire::date();
                if ($u !== null && $u !== []) {
                    $d[Urmarire::CHEIE_DATE] = $u;
                }
                return self::json(self::cuNonce($d, $nonce));
            }
            if (preg_match('/^meniu:(antet|subsol):(\d{1,4}):(clase|aria)$/D', $nume, $m) === 1) {
                $i = $m[3] === 'clase' ? 0 : 1;
                $v = self::lista(self::lista($meniuPagina[$m[1]] ?? [])[$m[2]] ?? [])[$i]
                    ?? self::lista(self::lista($fragmente[$m[1]]['meniu'] ?? [])[$m[2]] ?? [])[$i] ?? null;
                return is_string($v) ? $v : null;
            }
            if (preg_match('/^seo\.(titlu|descriere|canonical|robots|imagine)$/D', $nume, $m) === 1) {
                $v = $cerere['seo'][$m[1]] ?? $seoImport[$m[1]] ?? null;
                return is_string($v) ? $v : null;
            }
            return null;
        };
        return Jetoane::rezolva($html, $valori);
    }

    /**
     * Un rând din `pagini_capturate` randat: conținutul lui (`pages.html_content` pentru o pagină, altfel coloana `continut`),
     * fragmentele alese din `fragmente_capturate`, coloanele JSON decodate. `$ciorna`: conținutul de pus în locul celui salvat
     * (previzualizarea din editor și verificarea la salvare: previzualizare(), problemeContinut()).
     *
     * @param array<string, mixed> $doc rândul, cum vine din bază
     * @param array{cale: string, nonce: string, an: string, seo?: array<string, string>} $cerere
     * @param array{cap?: string, final?: string} $runtime
     * @throws EroareJeton
     */
    public static function randeazaRand(\PDO $db, array $doc, array $cerere, array $runtime, ?string $ciorna = null): string
    {
        $continut = (string) ($doc['continut'] ?? '');
        if ($ciorna !== null) {
            $continut = $ciorna;
        } elseif (($doc['tip'] ?? '') === 'pagina') {
            $q = $db->prepare('SELECT html_content FROM pages WHERE id = ?');
            $q->execute([(int) $doc['page_id']]);
            $continut = (string) $q->fetchColumn();
        }
        $fragmente = [];
        $q = $db->prepare('SELECT html, date, meniu FROM fragmente_capturate WHERE cheie = ?');
        foreach (self::FRAGMENTE as $f) {
            if (($doc[$f] ?? '') === '' || $doc[$f] === null) {
                continue;
            }
            $q->execute([(string) $doc[$f]]);
            $r = $q->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($r)) {
                throw new EroareJeton('lipsește fragmentul ' . $doc[$f]);
            }
            $fragmente[$f] = ['html' => (string) $r['html'], 'date' => self::dinJson($r['date']), 'meniu' => self::dinJsonLista($r['meniu'])];
        }
        // Șablonul managerului din Cookie Policy: doar la răspunsul web (P1 și amprenta randării îl opresc, cu `sablon_cmplz` gol).
        if (!array_key_exists('sablon_cmplz', $cerere) && !isset($fragmente['banner']) && str_contains($continut, self::MANAGER_CMPLZ)) {
            $q = $db->prepare("SELECT html FROM fragmente_capturate WHERE fel = 'banner' ORDER BY cheie LIMIT 1");
            $q->execute();
            $sablon = $q->fetchColumn();
            if (is_string($sablon) && !str_contains($sablon, '{{ax:fragment:')) {
                $cerere['sablon_cmplz'] = $sablon;
            }
        }
        $doc['date_pagina'] = self::dinJson($doc['date_pagina'] ?? null);
        $doc['date_reteta'] = self::dinJsonLista($doc['date_reteta'] ?? null);
        $doc['meniu'] = self::dinJsonLista($doc['meniu'] ?? null);
        $doc['valori_import'] = self::dinJsonLista($doc['valori_import'] ?? null);
        return self::randeaza($doc, $continut, $fragmente, $cerere + ['runtime' => $runtime]);
    }

    /**
     * Previzualizarea unei pagini capturate în editorul din admin (POST /admin/pages/{id}/previzualizare): documentul ei randat
     * pe aceeași cale ca pagina publică (randeazaRand, cu `$ciorna` în locul conținutului salvat): head-ul capturat (foile temei,
     * `et-cache`, foile rescrise de stilurile comune și după model, fonturile), antetul și subsolul, scripturile runtime-ului pe
     * sloturi, nonce-urile formularelor legate de cale, foile amânate ca foi obișnuite (foiTarziiCaFoi). Fără trackere (nicio
     * vizită de numărat în admin). Ciorna trece întâi de problemeContinut() (o face cine cere): niciun script capturat.
     *
     * @param array<string, mixed> $doc rândul paginii din `pagini_capturate`
     * @param array<string, string> $seo seoPagina()
     * @throws EroareJeton
     */
    public static function previzualizare(\PDO $db, array $doc, string $ciorna, string $cale, array $seo = []): string
    {
        $html = self::randeazaRand($db, $doc, ['cale' => $cale, 'nonce' => self::nonceZi(), 'an' => date('Y'), 'seo' => $seo, 'urmarire' => []],
            self::scripturiRuntime(Tema::runtime()), $ciorna);
        return self::foiTarziiCaFoi(self::legaNonceFormulare($html, $cale));
    }

    /**
     * Răspunsul previzualizării (documentul unei pagini capturate în cadrul editorului, sandbox="allow-scripts"): politica
     * site-ului pentru previzualizare (CspSite::politicaPrevizualizare: fără trackere, fără rapoarte, aplicată), `nosniff`,
     * fără cache și fără indexare.
     */
    public static function trimitePrevizualizare(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Security-Policy: ' . CspSite::politicaPrevizualizare());
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: same-origin');
        echo $html;
    }

    /** Locul conținutului în previzualizarea antetului și subsolului („Antet, subsol și meniu”, previzualizareFragmente()). */
    public const LOC_CONTINUT = '<div class="ax-previzualizare-continut" style="box-sizing:border-box;max-width:1080px;margin:32px auto;padding:40px 16px;'
        . 'border:2px dashed #c7cdd4;border-radius:8px;text-align:center;color:#6b7280;font:16px/1.5 system-ui,sans-serif">Conținutul paginii</div>';

    /**
     * Antetul și subsolul paginilor capturate, cum apar pe site (previzualizarea din „Antet, subsol și meniu”, GET
     * /admin/design/previzualizare): documentul primei pagini (slug-ul gol, altfel prima pagină capturată) randat ca în
     * editor (previzualizare()), cu LOC_CONTINUT în locul conținutului: head-ul cu foile lui, antetul cu meniul, subsolul,
     * runtime-ul. Null dacă site-ul n-are nicio pagină capturată.
     *
     * @throws EroareJeton
     */
    public static function previzualizareFragmente(\PDO $db): ?string
    {
        try {
            $r = $db->query("SELECT pc.page_id, p.slug FROM pagini_capturate pc JOIN pages p ON p.id = pc.page_id AND p.deleted_at IS NULL "
                . "WHERE pc.tip = 'pagina' ORDER BY p.slug = '' DESC, pc.page_id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        $doc = is_array($r) ? self::document($db, pagina: (int) $r['page_id']) : null;
        if ($doc === null) {
            return null;
        }
        return self::previzualizare($db, $doc, self::LOC_CONTINUT, WpCompat::adresaPagina((string) $r['slug']), self::seoPagina($db, (int) $r['page_id']));
    }

    /** Marcajele inerte din jurul conținutului, în documentul pe care îl verifică problemeContinut() (rândul unei probleme). */
    private const INCEPUT_CONTINUT = '<!--ax-continut-inceput-->';
    private const SFARSIT_CONTINUT = '<!--ax-continut-sfarsit-->';

    /**
     * Politica HTML-ului capturat (HtmlCapturat, modul `salvare`) pe codul unei pagini capturate, înainte de salvare și de
     * previzualizare: documentul întreg randat cu acest conținut (ca la import: fără runtime, fără trackere, fără șablonul
     * managerului), verificat cu contextul capturii site-ului (foile din manifest, iframe-urile permise). Se numără doar ce aduce
     * conținutul: problemele documentului randat cu un conținut gol (head-ul și fragmentele, trecute deja la import) nu se pun
     * pe seama paginii. Un jeton pe care cadrul nu-l cunoaște (pagina ar da 500) e și el o problemă. Rândurile, în română, cu
     * rândul din codul paginii acolo unde problema e în el; [] = se poate salva.
     *
     * @param array<string, mixed> $doc rândul paginii din `pagini_capturate`
     * @return list<string>
     */
    public static function problemeContinut(\PDO $db, array $doc, string $continut, ?HtmlCapturat\ContextCapturat $ctx = null): array
    {
        $ctx ??= HtmlCapturat\ContextCapturat::dinManifest(self::fisierManifest());
        $cerere = ['cale' => '/', 'nonce' => 'abcdef0123', 'an' => date('Y'), 'urmarire' => [], 'sablon_cmplz' => ''];
        $randeaza = static fn (string $c): string => self::randeazaRand($db, $doc, $cerere, ['cap' => '', 'final' => ''], $c);
        try {
            $html = $randeaza(self::INCEPUT_CONTINUT . $continut . self::SFARSIT_CONTINUT);
        } catch (EroareJeton $e) {
            return ['codul are un jeton pe care pagina nu-l poate completa (' . $e->getMessage() . '): pagina n-ar mai putea fi afișată'];
        }
        $inainte = [];
        try {
            foreach (HtmlCapturat::verifica($randeaza(''), $ctx, 'salvare') as $p) {
                $inainte[$p->cod . "\0" . $p->mesaj] = ($inainte[$p->cod . "\0" . $p->mesaj] ?? 0) + 1;
            }
        } catch (EroareJeton) {
        }
        $inceput = strpos($html, self::INCEPUT_CONTINUT);
        $sfarsit = $inceput === false ? false : strpos($html, self::SFARSIT_CONTINUT, $inceput);
        $rez = [];
        foreach (HtmlCapturat::verifica($html, $ctx, 'salvare') as $p) {
            $k = $p->cod . "\0" . $p->mesaj;
            if (($inainte[$k] ?? 0) > 0) {
                $inainte[$k]--;
                continue;
            }
            $start = $inceput === false ? 0 : $inceput + strlen(self::INCEPUT_CONTINUT);
            if ($p->octet >= $start && $sfarsit !== false && $p->octet <= $sfarsit) {
                $rez[] = 'rândul ' . (substr_count(substr($html, $start, $p->octet - $start), "\n") + 1) . ': ' . $p->mesaj;
            } else {
                $rez[] = $p->mesaj;
            }
        }
        return $rez;
    }

    /**
     * Scripturile runtime-ului compat pe sloturile documentului (contractul pistei R, pasul 8a): manifestul versiunii
     * (`public/assets/compat/<dialect>/v<n>/sloturi.json`), fiecare cale din `cap` și `final` ca
     * `<script src="/assets/compat/<cale>?v=<12 hex din sha256-ul fișierului>"></script>`, sincron, în ordinea listei. Fără runtime
     * (`""`), nimic. Un manifest sau un fișier lipsă (o versiune copiată pe jumătate) e o eroare: pagina nu iese fără runtime.
     *
     * @return array{cap: string, final: string}
     * @throws \RuntimeException
     */
    public static function scripturiRuntime(string $runtime): array
    {
        if ($runtime === '') {
            return ['cap' => '', 'final' => ''];
        }
        if (isset(self::$runtime[$runtime])) {
            return self::$runtime[$runtime];
        }
        if (preg_match('/^([a-z0-9]{2,20})@([1-9][0-9]{0,2})$/D', $runtime, $m) !== 1) {
            throw new \RuntimeException('runtime necunoscut: ' . $runtime);
        }
        $manifest = Paths::public('assets/compat/' . $m[1] . '/v' . $m[2] . '/sloturi.json');
        $date = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
        if (!is_array($date) || !is_array($date['cap'] ?? null) || !is_array($date['final'] ?? null)) {
            throw new \RuntimeException('manifestul runtime-ului lipsește sau e stricat: ' . $manifest);
        }
        $rez = ['cap' => '', 'final' => ''];
        foreach (['cap', 'final'] as $slot) {
            foreach ($date[$slot] as $cale) {
                if (!is_string($cale) || preg_match('#^[a-z0-9]{2,20}/v[1-9][0-9]{0,2}/[a-z0-9-]{1,60}\.js$#D', $cale) !== 1) {
                    throw new \RuntimeException('manifestul runtime-ului: cale nepermisă');
                }
                $fisier = Paths::public('assets/compat/' . $cale);
                if (!is_file($fisier)) {
                    throw new \RuntimeException('runtime-ul compat: lipsește ' . $cale);
                }
                $rez[$slot] .= '<script src="/assets/compat/' . $cale . '?v=' . substr((string) hash_file('sha256', $fisier), 0, 12) . '"></script>';
            }
        }
        return self::$runtime[$runtime] = $rez;
    }

    /**
     * Valoarea jetoanelor `{{ax:nonce}}` (nonce-urile capturate din `ax-date` și din câmpuri) pentru o zi: un HMAC cu cheia
     * site-ului (cheie()), același pentru toată ziua (pagina poate sta în cache). Câmpul nonce al unui formular Divi primește
     * apoi, la răspuns, nonce-ul legat de formularul lui (nonceFormular(), legaNonceFormulare()).
     */
    public static function nonceZi(?string $zi = null, string $cheie = ''): string
    {
        $cheie = $cheie !== '' ? $cheie : self::cheie();
        return $cheie === '' ? '' : substr(hash_hmac('sha256', 'formular-capturat|' . ($zi ?? date('Y-m-d')), $cheie), 0, 10);
    }

    /**
     * Nonce-ul unui formular Divi capturat (serverul formularelor, App\Support\FormularCapturat): HMAC cu cheia site-ului peste
     * zi, calea paginii (cum a fost cerută: acolo trimite `action`) și numărul formularului (`et_pb_contact_form_N`). Un nonce
     * luat de pe o pagină nu trece pe alt formular sau pe altă pagină; serverul îl primește pe ziua de azi și pe cea de ieri.
     * Gol fără cheie (atunci niciun formular nu trece: fail-closed).
     */
    public static function nonceFormular(string $cale, int $numar, ?string $zi = null, string $cheie = ''): string
    {
        $cheie = $cheie !== '' ? $cheie : self::cheie();
        return $cheie === '' ? '' : substr(hash_hmac('sha256', 'formular-capturat|' . ($zi ?? date('Y-m-d')) . '|' . $cale . '|' . $numar, $cheie), 0, 20);
    }

    /**
     * Cheia nonce-urilor: APP_KEY din `.env` (o scrie adoptarea, deploy/adopta-wp.sh), altfel un secret aleator scris o dată în
     * `storage/cache/cadru-capturat/secret` (0600; dezvoltarea, testele). Niciodată o constantă. Gol dacă nici secretul nu se
     * poate scrie: atunci nonce-urile sunt goale și niciun formular nu trece.
     */
    public static function cheie(): string
    {
        $cheie = trim((string) Env::get('APP_KEY', ''));
        if ($cheie !== '') {
            return $cheie;
        }
        if (self::$secret !== null) {
            return self::$secret;
        }
        $dosar = Paths::stocare('cache/cadru-capturat');
        $f = $dosar . '/secret';
        $s = is_file($f) ? trim((string) @file_get_contents($f)) : '';
        if (strlen($s) < 32) {
            if (!is_dir($dosar) && !@mkdir($dosar, 0770, true) && !is_dir($dosar)) {
                return '';
            }
            $h = @fopen($f, 'x');
            if ($h === false) {
                $s = trim((string) @file_get_contents($f)); // altă cerere l-a scris între timp
            } else {
                $s = bin2hex(random_bytes(32));
                fwrite($h, $s);
                fclose($h);
                @chmod($f, 0600);
            }
        }
        return self::$secret = strlen($s) >= 32 ? $s : '';
    }

    /**
     * Câmpurile nonce ale formularelor Divi din documentul randat (`_wpnonce-et-pb-contact-form-submitted-N`) primesc nonce-ul
     * legat de formular (nonceFormular, pe calea cererii). Doar tagurile `<input>` cu exact acest nume; restul octeților rămâne.
     */
    public static function legaNonceFormulare(string $html, string $cale, ?string $zi = null): string
    {
        if (!str_contains($html, 'et-pb-contact-form-submitted-')) {
            return $html;
        }
        return (string) preg_replace_callback('/<input\b[^<>]*>/i', static function (array $m) use ($cale, $zi): string {
            if (preg_match('/\sname=(["\'])_wpnonce-et-pb-contact-form-submitted-(\d{1,3})\1/', $m[0], $n) !== 1) {
                return $m[0];
            }
            $nonce = self::nonceFormular($cale, (int) $n[2], $zi);
            $nou = preg_replace('/(\svalue=)(["\'])[^"\'<>]*\2/', '${1}"' . $nonce . '"', $m[0], 1, $inlocuiri);
            return $inlocuiri === 1 && is_string($nou) ? $nou : $m[0];
        }, $html);
    }

    /**
     * SEO-ul scris pentru o pagină capturată (rândul ei din `seo_pages`), ca valori ale jetoanelor `seo.*`. @return array<string, string>
     */
    public static function seoPagina(\PDO $db, int $pagina): array
    {
        if ($pagina <= 0) {
            return [];
        }
        $q = $db->prepare("SELECT title, description, canonical_url, robots, image_url FROM seo_pages WHERE page_type = 'custom_page' AND page_ref = ?");
        $q->execute([(string) $pagina]);
        $r = $q->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($r)) {
            return [];
        }
        return array_filter(['titlu' => $r['title'], 'descriere' => $r['description'], 'canonical' => $r['canonical_url'], 'robots' => $r['robots'],
            'imagine' => $r['image_url']], static fn (mixed $v): bool => is_string($v) && $v !== '');
    }

    /**
     * Rândul documentului capturat al unei pagini (`page_id`), al 404-ului (`404`) sau al unei căi (embed, arhive), ori null
     * (fără tabel, fără document).
     *
     * @return array<string, mixed>|null
     */
    public static function document(\PDO $db, ?int $pagina = null, ?string $cheie = null, ?string $cale = null): ?array
    {
        try {
            if ($pagina !== null) {
                $q = $db->prepare('SELECT * FROM pagini_capturate WHERE page_id = ? AND tip = \'pagina\'');
                $q->execute([$pagina]);
            } elseif ($cheie !== null) {
                $q = $db->prepare('SELECT * FROM pagini_capturate WHERE cheie = ?');
                $q->execute([$cheie]);
            } else {
                $q = $db->prepare('SELECT * FROM pagini_capturate WHERE cale_hash = ? AND tip IN (\'embed\', \'arhiva\')');
                $q->execute([hash('sha256', (string) $cale)]);
            }
            $r = $q->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    /**
     * Trimite un document capturat ca răspuns: statusul, antetele capturate din lista albă (tipul conținutului, cache-ul,
     * `expires`, `x-robots-tag`), corpul randat. Un jeton necunoscut sau rămas, un runtime lipsă: 500, un rând în jurnal, nicio
     * pagină pe jumătate. Întoarce codul trimis.
     *
     * Câmpurile nonce ale formularelor Divi primesc nonce-ul legat de formular (legaNonceFormulare). Serverul formularelor
     * (FormularCapturat) dă `$dupa` (mesajul în locul formularului, valorile trimise) și `$faraCache` (răspunsul unui POST:
     * `Cache-Control: no-store`, fără antetele de cache capturate).
     *
     * @param array<string, mixed> $doc rândul din `pagini_capturate`
     * @param array{cale: string, nonce: string, an: string, seo?: array<string, string>} $cerere
     * @param (callable(string): string)|null $dupa
     */
    public static function raspunde(\PDO $db, array $doc, array $cerere, int $status, ?callable $dupa = null, bool $faraCache = false): int
    {
        try {
            $html = self::legaNonceFormulare(self::randeazaRand($db, $doc, $cerere, self::scripturiRuntime(Tema::runtime())), (string) $cerere['cale']);
            if ($dupa !== null) {
                $html = $dupa($html);
            }
        } catch (\Throwable $e) {
            error_log('ANDAXI-CMS: cadrul capturat: documentul „' . (string) ($doc['cheie'] ?? '?') . '” nu se poate randa (' . get_class($e) . ': ' . $e->getMessage() . ')');
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            echo "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Eroare</title></head><body><p>Pagina nu se poate afișa acum.</p></body></html>\n";
            return 500;
        }
        // Politica site-ului pleacă de la cine răspunde (recenzia 3, R2-N1): un document capturat (și 404-ul lui pe o cale pe
        // care o rută a CMS-ului n-o servește) o are mereu, oricare ar fi ruta potrivită.
        CspSite::trimite((string) ($_SERVER['REQUEST_URI'] ?? ($cerere['cale'] ?? '/')), true);
        http_response_code($status);
        $tip = false;
        $antete = json_decode((string) ($doc['antete_http'] ?? ''), true);
        foreach (is_array($antete) ? $antete : [] as $a) {
            if (!is_array($a) || count($a) !== 2 || !is_string($a[0]) || !is_string($a[1]) || preg_match('/[\r\n]/', $a[1]) === 1) {
                continue;
            }
            $n = strtolower($a[0]);
            if ($faraCache && in_array($n, ['cache-control', 'expires'], true)) {
                continue;
            }
            if (in_array($n, ['content-type', 'cache-control', 'expires', 'x-robots-tag'], true)) {
                header(self::numeAntet($n) . ': ' . $a[1], $n !== 'x-robots-tag');
                $tip = $tip || $n === 'content-type';
            }
        }
        if (!$tip) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        if ($faraCache) {
            header('Cache-Control: no-store, private', true);
        }
        echo self::foiTarziiCaFoi($html);
        return $status;
    }

    /**
     * Foile amânate ale dialectului, trimise ca foi obișnuite: în head, fiecare `<link rel="preload" as="style" … data-ax-tarziu>`
     * (CSS-ul pe care Divi îl încarca după pagină cu `onload="this.rel='stylesheet'"`: foaia „deferred” a paginii, cu restul
     * modulelor și subsolul Theme Builder, și foile dinamice) iese `<link rel="stylesheet" …>`, pe loc (aceeași cascadă), fără
     * `as` și fără `data-ax-tarziu`. Aspectul paginii nu mai depinde de un script și de evenimentul `load` al unui preload
     * (tarziu.js rămâne rezerva pentru un document care le-ar mai avea), cu prețul că foile se aplică înaintea primei desenări,
     * nu după ea. Doar la răspunsul web: T, P1 și amprenta randării (`randeazaRand`) rămân octet cu octet ale capturii.
     */
    public static function foiTarziiCaFoi(string $html): string
    {
        $sf = stripos($html, '</head>');
        $cap = $sf === false ? $html : substr($html, 0, $sf);
        $cap = (string) preg_replace_callback('/<link\b[^<>]*\sdata-ax-tarziu(?=[\s\/>=])[^<>]*>/i', static function (array $m): string {
            $t = $m[0];
            $rel = '/\srel\s*=\s*(["\']?)preload\1(?=[\s\/>])/i';
            $as = '/\sas\s*=\s*(["\']?)style\1(?=[\s\/>])/i';
            if (preg_match($rel, $t) !== 1 || preg_match($as, $t) !== 1) {
                return $t;
            }
            $t = (string) preg_replace($rel, ' rel=$1stylesheet$1', $t, 1);
            $t = (string) preg_replace($as, '', $t, 1);
            return (string) preg_replace('/\sdata-ax-tarziu(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?(?=[\s\/>])/i', '', $t, 1);
        }, $cap);
        return $sf === false ? $cap : $cap . substr($html, $sf);
    }

    /**
     * Fișierele manifestului capturii în docroot (`diagnostic.php --capturat`): fiecare fișier copiat (`sha256_publicat`) trebuie
     * să fie la calea lui publicată (`publicat`; sub `capturat/radacina/` pentru cele de la rădăcină), cu acel sha256. Cele lăsate
     * intenționat necopiate la captură (`necopiat`, cu motivul: scripturile, SVG-urile refuzate de SvgSigur, tipurile nepermise)
     * nu se cer și se numără separat. Unul fără copie și fără motiv, care lipsește, e NU; la fel unul cu alt sha256.
     *
     * Cu `$publicate` (manifestul adoptării, `storage/import-wordpress/statice.json`: Statice::publicate, adresa → copia), copia
     * de acolo are întâietate (calea și sha256-ul publicat, sau motivul pentru care a rămas necopiată), iar un script fără copie
     * e necopiat intenționat (scripturile nu se publică niciodată).
     *
     * @param array<mixed> $m manifestul
     * @param array<string, array<string, mixed>> $publicate
     * @return array{verificate: int, necopiate: int, probleme: list<string>}
     */
    public static function verificaFisiereManifest(array $m, string $docroot, array $publicate = []): array
    {
        $rez = ['verificate' => 0, 'necopiate' => 0, 'probleme' => []];
        $bun = static fn (string $c): bool => $c !== '' && str_starts_with($c, '/') && !str_contains($c, '..') && !str_contains($c, "\0");
        foreach (is_array($m['fisiere'] ?? null) ? $m['fisiere'] : [] as $f) {
            $cale = is_array($f) ? (string) ($f['cale'] ?? '') : '';
            if (!$bun($cale)) {
                $rez['probleme'][] = 'manifest: o intrare fără cale bună';
                continue;
            }
            $copie = $publicate[(string) ($f['url'] ?? '')] ?? null;
            if (is_array($copie)) {
                $f = array_merge($f, array_intersect_key($copie, ['publicat' => 1, 'sha256_publicat' => 1, 'necopiat' => 1]));
            } elseif ($publicate !== [] && (($f['fel'] ?? '') === 'script' || preg_match('/\.(?:m|c)?js$/i', $cale) === 1) && !is_string($f['sha256_publicat'] ?? null)) {
                $f['necopiat'] = 'script (aruncat)';
            }
            $shaPublicat = is_string($f['sha256_publicat'] ?? null) ? $f['sha256_publicat'] : '';
            if ($shaPublicat === '' && is_string($f['necopiat'] ?? null) && trim($f['necopiat']) !== '') {
                $rez['necopiate']++;
                continue;
            }
            $publicat = is_string($f['publicat'] ?? null) && $f['publicat'] !== '' ? $f['publicat'] : $cale;
            if (!$bun($publicat)) {
                $rez['probleme'][] = 'manifest: o cale publicată greșită pentru ' . $cale;
                continue;
            }
            $sha = $shaPublicat !== '' ? $shaPublicat : (string) ($f['sha256'] ?? '');
            $fisier = rtrim($docroot, '/') . $publicat;
            if (!is_file($fisier)) {
                $rez['probleme'][] = 'lipsește ' . $publicat;
            } elseif ($sha !== '' && !hash_equals($sha, (string) hash_file('sha256', $fisier))) {
                $rez['probleme'][] = 'alt sha256 decât în manifest: ' . $publicat;
            }
            $rez['verificate']++;
        }
        return $rez;
    }

    /** Manifestul capturii site-ului (pasul 4): `capturat/manifest.json` lângă `.env` (unde îl caută și importul). */
    public static function fisierManifest(): string
    {
        return dirname(Paths::env()) . '/capturat/manifest.json';
    }

    /**
     * Scripturile unei ieșiri a cadrului care nu sunt permise (P1, pasul 7): doar datele inerte (`ax-date`, JSON-LD,
     * `speculationrules`) și scripturile runtime-ului de pe sloturi (`/assets/compat/…?v=…`) au voie. @return list<string>
     */
    public static function scripturiNepermise(string $html, array $runtime): array
    {
        $permise = [];
        preg_match_all('#<script src="[^"]+"></script>#', ($runtime['cap'] ?? '') . ($runtime['final'] ?? ''), $m);
        foreach ($m[0] as $tag) {
            $permise[$tag] = true;
        }
        $rez = [];
        preg_match_all('#<script\b[^>]*>#i', $html, $mm);
        foreach ($mm[0] as $deschidere) {
            if (preg_match('#^<script (?:type="application/json" id="ax-date"|type="application/ld\+json"(?: class="[a-z-]+")?|type="speculationrules")>$#', $deschidere) === 1) {
                continue;
            }
            if (preg_match('#^<script src="[^"]+">$#', $deschidere) === 1 && isset($permise[$deschidere . '</script>'])) {
                continue;
            }
            $rez[] = mb_substr($deschidere, 0, 120);
        }
        return $rez;
    }

    /** Numele unui antet cu majusculele obișnuite (`content-type` → `Content-Type`). */
    private static function numeAntet(string $n): string
    {
        return implode('-', array_map('ucfirst', explode('-', $n)));
    }

    /** @var array<string, array{cap: string, final: string}> */
    private static array $runtime = [];

    /** Secretul de rezervă al cheii (cheie()), citit o dată pe proces. */
    private static ?string $secret = null;

    /** Un JSON salvat ca listă asociativă (rețeta, meniul, valorile de la import); gol = []. @return array<mixed> */
    public static function dinJsonLista(?string $json): array
    {
        $v = $json === null || $json === '' ? null : json_decode($json, true);
        return is_array($v) ? $v : [];
    }

    /** Cele cinci bucăți puse la loc, în forma cadrului (`$cap` se termină cu `</head>`, bucata de după cu `</body>`). */
    public static function asambleazaBucati(string $htmlAtribute, string $cap, string $bodyAtribute, string $inainte, string $continut, string $dupa): string
    {
        return self::DOCTYPE . '<html' . $htmlAtribute . ">\n<head>" . $cap . "\n<body" . $bodyAtribute . '>' . $inainte . $continut . $dupa . "\n</html>\n";
    }

    /**
     * Pagina Cookie Policy (fără banner, cu containerul managerului): bannerul site-ului (fragmentul lui, HTML-ul capturat și
     * verificat la import) într-un `<template>` inert înainte de `</body>`. Runtime-ul (consimtamant.js) copiază din el categoriile
     * în manager prin cloneNode, ca pe live (unde managerul vine din răspunsul `manage_consent_html`, același HTML cu categoriile
     * bannerului), apoi scoate șablonul; nimic nu se construiește din text în browser.
     */
    public static function puneSablonManager(string $html, string $sablon): string
    {
        $i = strrpos($html, '</body>');
        if ($sablon === '' || $i === false || !str_contains($html, self::MANAGER_CMPLZ) || str_contains($sablon, '</template')) {
            return $html;
        }
        return substr($html, 0, $i) . '<template id="' . self::SABLON_CMPLZ . '">' . $sablon . '</template>' . substr($html, $i);
    }

    /**
     * Fragmentele (`{{ax:fragment:<fel>}}`) puse în document, o dată; un fragment nu poate cuprinde alt fragment.
     *
     * @param array<string, string> $fragmente fel → html
     * @throws EroareJeton un fragment lipsă sau cuprins în altul
     */
    public static function puneFragmente(string $doc, array $fragmente): string
    {
        foreach ($fragmente as $html) {
            if (str_contains($html, '{{ax:fragment:')) {
                throw new EroareJeton('un fragment cuprinde alt fragment');
            }
        }
        return (string) preg_replace_callback('/\{\{ax:fragment:(antet|subsol|banner)\}\}/', static function (array $m) use ($fragmente): string {
            if (!isset($fragmente[$m[1]])) {
                throw new EroareJeton('lipsește fragmentul „' . $m[1] . '”');
            }
            return $fragmente[$m[1]];
        }, $doc);
    }

    /**
     * Datele comportamentului refăcute din părțile lor (pagina, antetul, subsolul), după rețeta ordinii (Divi4Taiere::imparte):
     * pentru fiecare configurație, proprietarul fiecărei chei (obiecte) sau al fiecărui element (liste), în ordinea din pagină.
     *
     * @param array<string, mixed> $pagina
     * @param array<string, mixed> $antet
     * @param array<string, mixed> $subsol
     * @param array<string, mixed> $reteta
     * @return array<string, mixed>
     */
    public static function asambleaza(array $pagina, array $antet, array $subsol, array $reteta): array
    {
        $parti = ['pagina' => $pagina, 'antet' => $antet, 'subsol' => $subsol];
        $rez = [];
        foreach ($reteta as $nume => $r) {
            $r = (array) $r;
            if (($r[0] ?? '') === 'obiect') {
                $o = new \stdClass();
                foreach ((array) ($r[1] ?? []) as $x) {
                    [$p, $k] = array_values((array) $x);
                    $sursa = $parti[$p][$nume] ?? null;
                    if ($sursa instanceof \stdClass && property_exists($sursa, (string) $k)) {
                        $o->{$k} = $sursa->{$k};
                    }
                }
                $rez[$nume] = $o;
            } elseif (($r[0] ?? '') === 'lista') {
                $poz = ['pagina' => 0, 'antet' => 0, 'subsol' => 0];
                $l = [];
                foreach ((array) ($r[1] ?? []) as $p) {
                    $sursa = array_values((array) ($parti[$p][$nume] ?? []));
                    if (array_key_exists($poz[$p], $sursa)) {
                        $l[] = $sursa[$poz[$p]];
                    }
                    $poz[$p]++;
                }
                $rez[$nume] = $l;
            } elseif (array_key_exists($nume, $pagina)) {
                $rez[$nume] = $pagina[$nume];
            }
        }
        return $rez;
    }

    /** JSON-ul blocului `ax-date`: aceleași date, codate determinist; „<” și „&” scăpate (nimic nu poate închide scriptul). */
    public static function json(array $date): string
    {
        return json_encode((object) $date, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Datele, din JSON-ul lor salvat (obiectele rămân obiecte, ca la citirea capturii). Fără nicio schimbare de tip: importul a
     * refuzat deja întregii în afara ±2^53 și numerele infinite (SchemaDivi4::jsonStrict), deci ce s-a scris se citește la fel.
     */
    public static function dinJson(?string $json): mixed
    {
        return $json === null || $json === '' ? null : json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /** Câte formulare Divi are conținutul (numărul formularului din subsol, ca în WordPress). */
    public static function formulareInContinut(string $continut): int
    {
        return preg_match_all('/\bid="et_pb_contact_form_\d{1,3}"/', $continut);
    }

    /** Un obiect al datelor ca listă asociativă de nivel întâi (proprietățile lui), lăsând valorile neatinse. @return array<string, mixed> */
    private static function obiecte(mixed $v): array
    {
        return $v instanceof \stdClass ? get_object_vars($v) : (is_array($v) ? $v : []);
    }

    /** O valoare JSON decodată (obiect sau listă) ca listă asociativă, recursiv pe obiecte; altceva = []. @return array<mixed> */
    private static function lista(mixed $v): array
    {
        if ($v instanceof \stdClass) {
            $v = get_object_vars($v);
        }
        return is_array($v) ? array_map(static fn (mixed $x): mixed => $x instanceof \stdClass ? self::lista($x) : $x, $v) : [];
    }

    /** Valorile `{{ax:nonce}}` din date înlocuite cu nonce-ul cererii. */
    private static function cuNonce(mixed $v, string $nonce): mixed
    {
        if ($v === self::LOC_NONCE) {
            return $nonce;
        }
        if ($v instanceof \stdClass) {
            $o = new \stdClass();
            foreach (get_object_vars($v) as $k => $x) {
                $o->{$k} = self::cuNonce($x, $nonce);
            }
            return $o;
        }
        if (is_array($v)) {
            return array_map(static fn (mixed $x): mixed => self::cuNonce($x, $nonce), $v);
        }
        return $v;
    }
}
