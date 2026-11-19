<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use App\Support\CadruCapturat;
use App\Support\CadruCapturat\Jetoane;
use App\Support\HtmlCapturat;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Continut\Divi4;
use App\Support\ImportWordPress\Continut\Divi4Taiere;
use App\Support\ImportWordPress\Continut\Shortcoduri;
use App\Support\ImportWordPress\Continut\Taiere;

/**
 * Partea capturată a planului (drumul capturat, pasul 5a): documentele tăiate (paginile, 404-ul, cardurile embed, arhivele),
 * fragmentele lor grupate pe octeți (o diferență nouă face o variantă nouă), elementele de meniu proprii fiecărui document,
 * răspunsurile înghețate (robots, sitemap-urile, XSL-ul, feed-urile, oEmbed), formularele și galeriile. O scrie
 * ScriereCapturata; problemele ei sunt NU în plan.
 */
final class PlanCapturat
{
    /** Antetele unui răspuns capturat care se trimit din nou (restul — codarea, lungimea, hop-by-hop, serverul, CSP-ul lor — nu). */
    public const ANTETE = ['content-type', 'cache-control', 'expires', 'x-robots-tag'];

    /** @var array<string, array<string, mixed>> cheie → documentul (tip, cale, wp_id, taiere, sha_captura, antete, …) */
    public array $documente = [];
    /** @var array<string, array<string, mixed>> cheie → fragmentul (fel, layout, html, date, meniu, sha) */
    public array $fragmente = [];
    /** @var list<array<string, mixed>> */
    public array $raspunsuri = [];
    /** @var array<string, array<string, mixed>> cheie → formularul */
    public array $formulare = [];
    /** @var array<string, array<string, mixed>> cheie → galeria */
    public array $galerii = [];
    /** @var list<string> */
    public array $probleme = [];
    /** @var list<string> */
    public array $avertismente = [];
    /** @var list<string> */
    public array $info = [];
    /** Adresa site-ului capturat (originea adreselor proprii; P1 normalizează cu ea). */
    public string $url = '';
    /** @var list<string> documentele (căile lor sau `404`) trecute prin dialect, pentru verificarea lui `captura.structura` */
    public array $documenteVazute = [];

    /**
     * Contextul tăierii unei pagini: secțiunile (etichetele admin din shortcoduri), anul din subsol (Dynamic Content).
     *
     * @return array<string, mixed>
     */
    public static function contextPagina(Inventar $i, array $pagina, string $cale, ?array $seo, string $anCaptura): array
    {
        return ['tip' => 'pagina', 'cale' => $cale, 'seo' => $seo, 'sectiuni' => self::sectiuniAdmin((string) ($pagina['continut'] ?? '')),
            'an' => self::anDinamic($i, $anCaptura)];
    }

    /**
     * Etichetele admin ale secțiunilor (`[et_pb_section admin_label="…"]`), în ordine; null fără shortcoduri Divi.
     *
     * @return list<string>|null
     */
    public static function sectiuniAdmin(string $continut): ?array
    {
        if (!str_contains($continut, '[et_pb_section')) {
            return null;
        }
        return array_map(static fn (array $s): string => (string) ($s['atribute']['admin_label'] ?? ''),
            Shortcoduri::cauta(Shortcoduri::desface($continut), 'et_pb_section'));
    }

    /**
     * Anul din subsol, dacă un layout de subsol al Theme Builder-ului are Divi Dynamic Content `current_date` într-un modul text:
     * clasa modulului (`et_pb_text_<n>_tb_footer`, după ordinea modulelor text din layout) și anul capturii.
     *
     * @return array{modul: string, an: string}|null
     */
    public static function anDinamic(Inventar $i, string $anCaptura): ?array
    {
        $gasite = [];
        foreach ($i->themeBuilder['layouturi'] as $id => $l) {
            if (($l['parte'] ?? '') !== 'footer' || !str_contains((string) $l['continut'], '@ET-DC@')) {
                continue;
            }
            $texte = Shortcoduri::cauta(Shortcoduri::desface((string) $l['continut']), 'et_pb_text');
            foreach ($texte as $n => $t) {
                $brut = (string) ($t['continut'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($t['atribute'] ?? [])));
                if (preg_match_all('/@ET-DC@([A-Za-z0-9+\/=]+)@/', $brut, $mm) > 0) {
                    foreach ($mm[1] as $b64) {
                        $d = json_decode((string) base64_decode($b64, true), true);
                        if (is_array($d) && ($d['content'] ?? '') === 'current_date' && (bool) ($d['dynamic'] ?? false)) {
                            $gasite[] = ['modul' => 'et_pb_text_' . $n . '_tb_footer', 'an' => $anCaptura, 'layout' => (string) $id];
                        }
                    }
                }
            }
        }
        return count($gasite) === 1 ? ['modul' => $gasite[0]['modul'], 'an' => $gasite[0]['an']] : null;
    }

    /**
     * Corpusul căutării unei pagini (pentru CautareWp, pasul 9b): ce caută WordPress cu `LIKE` (titlul, rezumatul, conținutul
     * brut din baza WordPress, cu shortcodurile lui), ca JSON. Rămâne al importului; după prima editare, căutarea citește textul
     * paginii.
     */
    public static function corpus(array $pagina): string
    {
        return (string) json_encode(['titlu' => (string) ($pagina['titlu'] ?? ''), 'rezumat' => (string) ($pagina['rezumat'] ?? ''),
            'continut' => (string) ($pagina['continut'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Data WordPress (`post_modified`) ca DATETIME, sau null (o dată goală ori zero). */
    public static function dataWp(string $data): ?string
    {
        return preg_match('/^(\d{4})-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data, $m) === 1 && $m[1] !== '0000' ? $data : null;
    }

    /** Rezumatul părții capturate (raportul planului): câte documente, fragmente, răspunsuri, formulare și galerii. @return array<string, mixed> */
    public function raport(): array
    {
        $tipuri = [];
        $seo = [];
        foreach ($this->documente as $cheie => $d) {
            $tipuri[$d['tip']] = ($tipuri[$d['tip']] ?? 0) + 1;
            $seo[(string) $cheie] = $d['taiere']->seo;
        }
        $fragmente = [];
        foreach ($this->fragmente as $f) {
            $fragmente[$f['fel']] = ($fragmente[$f['fel']] ?? 0) + 1;
        }
        return ['documente' => $tipuri, 'fragmente' => $fragmente, 'raspunsuri' => array_map(static fn (array $r): string => $r['status'] . ' ' . $r['cale']
            . ($r['query'] !== '' ? '?' . $r['query'] : ''), $this->raspunsuri), 'formulare' => array_keys($this->formulare), 'galerii' => array_keys($this->galerii),
            'seo' => $seo];
    }

    /** Un document tăiat, cu ce se scrie despre el. @param array<string, mixed> $meta */
    public function adaugaDocument(string $cheie, string $tip, Taiere $t, array $meta): void
    {
        foreach ($t->probleme as $p) {
            $this->probleme[] = ($meta['titlu'] ?? $cheie) . ': ' . $p;
        }
        foreach ($t->avertismente as $a) {
            $this->avertismente[] = ($meta['titlu'] ?? $cheie) . ': ' . $a;
        }
        if ($t->probleme !== []) {
            return;
        }
        $this->documente[$cheie] = $meta + ['cheie' => $cheie, 'tip' => $tip, 'taiere' => $t];
    }

    /**
     * Documentele capturii din afara paginilor planului (404-ul, cardurile embed ale paginilor importate, arhivele păstrate) și
     * răspunsurile înghețate, din captură. `$pagini`: calea capturată → cheia documentului paginii (`pagina:<wp_id>`);
     * `$neimportate`: căile paginilor scoase (nici ele, nici cardurile lor nu se scriu).
     *
     * @param array<string, string> $pagini
     * @param list<string> $neimportate
     */
    public function dinCaptura(DosarCapturi $d, array $reguli, array $pagini, array $neimportate, ?array $an = null): void
    {
        $this->url = $d->url;
        $divi4 = new Divi4($d);
        $ctxPolitica = $divi4->contextPolitica();
        foreach (self::adrese($d) as $a) {
            $r = $d->dupaCale($a['cale']);
            if ($r === null) {
                continue;
            }
            if (($j = Jetoane::literal($r->corp)) !== null) {
                $this->probleme[] = $a['cale'] . ': captura conține deja textul „{{ax:” la octetul ' . $j . ' (forma jetoanelor noastre)';
                continue;
            }
            $antete = self::antete($r->antete, $d->url);
            if (in_array($a['fel'], ['raspuns', 'oembed'], true) || ($r->status >= 300 && $r->status < 400)) {
                $drum = rawurldecode((string) (parse_url($a['cale'], PHP_URL_PATH) ?? '/'));
                foreach ($neimportate as $c) {
                    $c = rawurldecode($c);
                    if ($c !== '/' && str_starts_with($drum, rtrim($c, '/') . '/')) {
                        continue 2; // feed-ul sau cardul unei pagini scoase (urmările ei: redirecționările, pasul 5b)
                    }
                }
                $p = parse_url($a['cale']);
                // Nimic peste o rută, un fișier sau un dosar al CMS-ului (recenzia 2, R2-1), nici un Location care nu e strict relativ (R2-4).
                if (($col = \App\Support\RaspunsuriInghetate::coliziune((string) ($p['path'] ?? '/'), (string) ($p['query'] ?? ''))) !== null) {
                    $this->probleme[] = $a['cale'] . ': răspunsul înghețat se ciocnește cu CMS-ul: ' . $col;
                    continue;
                }
                foreach ($antete as [$nA, $vA]) {
                    if ($nA === 'location' && !\App\Support\RaspunsuriInghetate::locatieSigura($vA)) {
                        $this->probleme[] = $a['cale'] . ': Location nu e o cale strict relativă a site-ului (' . mb_substr(addcslashes($vA, "\0..\37"), 0, 80) . ')';
                        continue 2;
                    }
                }
                $this->raspunsuri[] = ['metoda' => 'GET', 'cale' => (string) ($p['path'] ?? '/'), 'query' => (string) ($p['query'] ?? ''), 'status' => $r->status,
                    'antete' => $antete, 'corp' => $r->corp, 'sursa' => 'captura:' . $a['fel']];
                continue;
            }
            if (isset($pagini[$a['cale']])) {
                continue; // paginile vin din plan (cu SEO-ul și conținutul lor)
            }
            $tip = match ($a['fel']) {
                '404' => '404',
                'embed' => 'embed',
                default => 'arhiva',
            };
            $cheie = match ($tip) {
                '404' => '404',
                'embed' => 'embed:' . substr((string) ($pagini[(string) preg_replace('#embed/$#', '', $a['cale'])] ?? ''), strlen('pagina:')),
                default => 'arhiva:' . substr(hash('sha256', $a['cale']), 0, 16),
            };
            if ($tip === 'embed' && $cheie === 'embed:') {
                continue; // cardul unei pagini care nu se importă
            }
            if ($tip === 'arhiva' && in_array(rawurldecode($a['cale']), array_map('rawurldecode', $neimportate), true)) {
                continue;
            }
            if ($tip !== '404' && $r->status !== 200) {
                $this->avertismente[] = $a['cale'] . ': captura are statusul ' . $r->status . ', nu se scrie';
                continue;
            }
            $document = $tip === '404' ? '404' : $a['cale'];
            $this->documenteVazute[] = $document;
            $dialect = $divi4->transforma($r->corp, $d->url, $reguli, $document);
            foreach ($dialect->probleme as $pr) {
                $this->probleme[] = $a['cale'] . ': ' . $pr;
            }
            if ($dialect->probleme !== []) {
                continue;
            }
            $pb = HtmlCapturat::verifica($dialect->html, $ctxPolitica, 'import');
            foreach ($pb as $problema) {
                $this->probleme[] = $a['cale'] . ' (HtmlCapturat): ' . $problema->linie();
            }
            if ($pb !== []) {
                continue;
            }
            $t = Divi4Taiere::taie($dialect, ['tip' => $tip, 'cale' => $a['cale'], 'an' => $an]);
            if ($t->probleme === []) {
                foreach (HtmlCapturat::verifica($t->html, $ctxPolitica, 'import') as $problema) {
                    $this->probleme[] = $a['cale'] . ' (HtmlCapturat, după pasul 5a): ' . $problema->linie();
                }
            }
            $this->adaugaDocument($cheie, $tip, $t, ['titlu' => $a['cale'], 'cale' => $tip === '404' ? null : $a['cale'], 'status' => $r->status,
                'sha_captura' => $r->sha256(), 'antete' => $antete, 'valori_import' => ['cale' => $a['cale']]]);
        }
        if (!isset($this->documente['404'])) {
            $this->avertismente[] = 'fără pagina 404 a site-ului: adresele necunoscute primesc 404-ul nucleului';
        }
        // `captura.structura` pe un document care nu e în import (o cale greșită în pachet): clasele lui nu s-ar pune nicăieri.
        foreach (array_keys((array) ($reguli['captura']['structura'] ?? [])) as $cheieS) {
            $cheieS = (string) $cheieS;
            $gasit = false;
            foreach ($this->documenteVazute as $doc) {
                if (rawurldecode($doc) === rawurldecode($cheieS)) {
                    $gasit = true;
                    break;
                }
            }
            if (!$gasit) {
                $this->avertismente[] = 'import-wordpress.json → captura.structura: „' . $cheieS . '” nu e niciun document importat (clasele lui nu se pun)';
            }
        }
    }

    /**
     * Fragmentele grupate pe octeți (și pe datele lor), cu cheia `<fel>:<sha16>`; elementele de meniu ale fiecărui fragment
     * (clasele și aria-current cele mai dese) și, pe fiecare document, doar cele care diferă. Apoi formularele și galeriile.
     */
    public function grupeaza(?Inventar $i = null): void
    {
        $folosiri = [];
        foreach ($this->documente as $cheie => $doc) {
            $cheie = (string) $cheie; // o cheie ca „404” devine întreg în PHP
            /** @var Taiere $t */
            $t = $doc['taiere'];
            foreach ($t->fragmente as $fel => $f) {
                $sha = hash('sha256', $fel . "\0" . $f['html'] . "\0" . CadruCapturat::json(array_map(static fn (mixed $v): mixed => $v, $f['date'])));
                $k = $fel . ':' . substr($sha, 0, 16);
                if (isset($this->fragmente[$k]) && $this->fragmente[$k]['sha'] !== $sha) {
                    $this->probleme[] = 'două fragmente diferite cu aceeași cheie ' . $k;
                    continue;
                }
                $this->fragmente[$k] ??= ['cheie' => $k, 'fel' => $fel, 'layout' => (string) $f['layout'], 'html' => $f['html'], 'date' => $f['date'], 'meniu' => [], 'sha' => $sha];
                $this->documente[$cheie][$fel] = $k;
                foreach ($t->meniu[$fel] ?? [] as $n => $v) {
                    $folosiri[$k][$n][json_encode($v)][] = $cheie;
                }
            }
        }
        foreach ($folosiri as $k => $elemente) {
            foreach ($elemente as $n => $variante) {
                uasort($variante, static fn (array $a, array $b): int => count($b) <=> count($a));
                $implicit = json_decode((string) array_key_first($variante), true);
                $this->fragmente[$k]['meniu'][$n] = $implicit;
                foreach ($variante as $v => $docs) {
                    if ($v === array_key_first($variante)) {
                        continue;
                    }
                    foreach ($docs as $cheie) {
                        $this->documente[$cheie]['meniu'][$this->fragmente[$k]['fel']][$n] = json_decode($v, true);
                    }
                }
            }
        }
        $formulareWp = [];
        foreach ($i !== null ? $i->formulareDivi : [] as $f) {
            $formulareWp[(int) $f['postare'] . ':' . (int) $f['ordine']] = (array) $f['atribute'];
        }
        foreach ($this->documente as $cheie => $doc) {
            $cheie = (string) $cheie;
            $t = $doc['taiere'];
            foreach ($t->formulare as $fo) {
                if ($fo['loc'] === 'subsol' && isset($doc['subsol'])) {
                    $k = 'fragment:' . $doc['subsol'] . ':' . $fo['modul'];
                    $layout = (string) ($this->fragmente[$doc['subsol']]['layout'] ?? '');
                    $ordine = preg_match('/^et_pb_contact_form_(\d+)_tb_footer$/', (string) $fo['modul'], $m) === 1 ? (int) $m[1] : 0;
                    $wp = ctype_digit($layout) ? ($formulareWp[(int) $layout . ':' . $ordine] ?? null) : null;
                    $this->formulare[$k] ??= ['cheie' => $k, 'pagina_capturata' => null, 'fragment' => $doc['subsol'], 'indice' => 0,
                        'config' => self::configFormular($fo, $wp)];
                } elseif ($fo['loc'] === 'continut') {
                    $k = 'doc:' . $cheie . ':' . $fo['indice'];
                    $wp = isset($doc['wp_id']) ? ($formulareWp[(int) $doc['wp_id'] . ':' . (int) $fo['indice']] ?? null) : null;
                    $this->formulare[$k] = ['cheie' => $k, 'pagina_capturata' => $cheie, 'fragment' => null, 'indice' => (int) $fo['indice'],
                        'config' => self::configFormular($fo, $wp)];
                }
            }
            foreach ($t->galerii as $g) {
                $k = $cheie . ':' . $g['modul'];
                $this->galerii[$k] = ['cheie' => $k, 'pagina_capturata' => $cheie, 'modul' => $g['modul'], 'setari' => $g['setari'], 'elemente' => $g['elemente']];
            }
        }
    }

    /** Antetele de trimis din nou ale unui răspuns capturat (ANTETE; adresele proprii din Location devin relative). @return list<array{0: string, 1: string}> */
    public static function antete(array $antete, string $url): array
    {
        $rez = [];
        foreach ($antete as [$n, $v]) {
            $n = strtolower((string) $n);
            if (in_array($n, self::ANTETE, true)) {
                $rez[] = [$n, (string) $v];
            } elseif ($n === 'location') {
                $v = (string) $v;
                if (str_starts_with(strtolower($v), strtolower(rtrim($url, '/')) . '/')) {
                    $v = substr($v, strlen(rtrim($url, '/')));
                }
                $rez[] = [$n, $v];
            }
        }
        return $rez;
    }

    /** Anul capturii (din `index.json`; altfel anul de acum): valoarea lui `{{ax:an}}` la import. */
    public static function anCaptura(DosarCapturi $d): string
    {
        if ($d->alNostru && is_file($d->dosar . '/index.json')) {
            $ix = json_decode((string) file_get_contents($d->dosar . '/index.json'), true);
            if (is_string($ix['facuta'] ?? null) && preg_match('/^(\d{4})-/', $ix['facuta'], $m) === 1) {
                return $m[1];
            }
        }
        return date('Y');
    }

    /**
     * Adresele capturii, cu felul lor: din `index.json` (formatul nostru), altfel după cale.
     *
     * @return list<array{fel: string, cale: string}>
     */
    public static function adrese(DosarCapturi $d): array
    {
        $rez = [];
        if ($d->alNostru && is_file($d->dosar . '/index.json')) {
            $ix = json_decode((string) file_get_contents($d->dosar . '/index.json'), true);
            foreach ((array) ($ix['adrese'] ?? []) as $a) {
                if (is_array($a) && is_string($a['cale'] ?? null) && is_string($a['fel'] ?? null)) {
                    $rez[] = ['fel' => $a['fel'], 'cale' => $a['cale']];
                }
            }
            return $rez;
        }
        foreach ($d->toate() as $x) {
            $p = parse_url((string) $x['url']);
            $cale = (string) ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
            $fel = str_ends_with((string) ($p['path'] ?? ''), '/embed/') ? 'embed'
                : (str_starts_with($cale, '/wp-json/oembed/') ? 'oembed'
                : ((int) $x['status'] === 404 ? '404' : (preg_match('#\.(xml|xsl|txt|rss)$|/feed/$#', (string) ($p['path'] ?? '')) === 1 ? 'raspuns' : 'pagina')));
            $rez[] = ['fel' => $fel, 'cale' => $cale];
        }
        return $rez;
    }

    /** Configurația unui formular Divi: câmpurile din DOM, plus atributele modulului din baza WordPress (destinatarul, mesajele). */
    private static function configFormular(array $fo, ?array $wp): array
    {
        return ['campuri' => $fo['campuri'], 'buton' => $fo['buton'], 'modul' => $fo['modul'], 'destinatar' => ($wp['email'] ?? '') !== '' ? (string) $wp['email'] : null,
            'titlu' => (string) ($wp['title'] ?? ''), 'mesaj_succes' => (string) ($wp['success_message'] ?? ''), 'tipar' => (string) ($wp['custom_message'] ?? ''),
            'redirect' => ($wp['use_redirect'] ?? 'off') === 'on' ? (string) ($wp['redirect_url'] ?? '') : '', 'captcha' => (string) ($wp['captcha'] ?? ''),
            'din_baza_wp' => $wp !== null];
    }
}
