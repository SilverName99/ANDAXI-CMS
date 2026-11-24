<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

use App\Support\CadruCapturat;
use App\Support\CadruCapturat\Jetoane;
use App\Support\Html5\Editare;
use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;
use App\Support\HtmlCapturat;
use App\Support\HtmlCapturat\ContextCapturat;
use App\Support\HtmlCapturat\SchemaDivi4;
use App\Support\ImportWordPress\Captura\DosarCapturi;
use App\Support\ImportWordPress\Captura\FonturiGoogle;
use App\Support\ImportWordPress\Captura\Normalizare;
use App\Support\ImportWordPress\Context;
use InvalidArgumentException;

/**
 * Dialectul `divi4` (adoptarea unui site WordPress pe drumul capturat, pasul 5a; DECIZIE §2.3 #6–#9, §6.7, C2 §5a): face din
 * pagina capturată (Divi 4 cu pluginurile lui) documentul servit de site-ul nou, T(captura), DOAR prin editări pe intervale
 * (Html5\Editare), fiecare de un fel din lista închisă. În afara intervalelor, octeții sunt cei ai capturii. Nimic din
 * WordPress sau de la furnizori nu rămâne să ruleze:
 *
 *  - **scripturile** (`script-aruncat`, `config`): un `<script>` cu date inerte (JSON-LD, `speculationrules`) rămâne. O
 *    configurație `var <nume> = <JSON>;` cu numele din lista închisă (SchemaDivi4::CONFIG) se scoate, iar valorile ei, citite
 *    strict ca JSON, trec în blocul `ax-date`. Orice alt script se scoate doar dacă sha256-ul lui — calculat după normalizarea
 *    adreselor proprii și a nonce-urilor (CB-14, CB-15), cu literalele de date cunoscute (DATE_INCORPORATE: vectorul CSS-ului
 *    „late”) înlocuite de numele lor — e în `import-wordpress.json` → `scripturi_aruncate` (cu motivul); literalele lui trec și
 *    ele în `ax-date`. Un script necunoscut înseamnă NU, cu sha256-ul lui de revizuit. `<noscript>`-urile din aceeași listă
 *    (iframe-ul GTM) se scot (`noscript-aruncat`); celelalte rămân, iar politica le verifică;
 *  - **`ax-date`** (`ax-date`): `<script type="application/json" id="ax-date">` cu configurațiile, în ordinea din pagină, pus
 *    unde stătea primul script scos din head; pozițiile runtime-ului (sloturile `cap` și `final`) se dau separat. Ultima cheie,
 *    când pachetul o dă pentru document (`import-wordpress.json` → `captura.structura` → `"<cale>"` sau `"404"`), e
 *    `ax_structura`: clasele de structură observate pe pagină fără o regulă în DOM, pe care runtime-ul (`structura.js`) le pune
 *    la încărcare (SchemaDivi4::structura: clasele din lista închisă, selectorii din gramatica închisă; altfel NU);
 *  - **handlerele din lista închisă** (`handler-tarziu`, `handler-media`): `onload` de pe `<link rel=preload as=style>` →
 *    atributul `data-ax-tarziu` (în locul lui); `onload="media='all'"` → `media="all"`. Orice alt `on…` rămâne, iar politica îl
 *    refuză (NU);
 *  - **head-ul** (`link-wp`, §2.3 #9): legăturile spre adresele WordPress care dispar (pingback, EditURI, `api.w.org`, JSON-ul
 *    REST al paginii, wlwmanifest) se scot; shortlink-ul, alternatele RSS și oEmbed și iconițele rămân. Un `<link>` se scoate
 *    doar dacă TOATE `rel`-urile lui sunt din mulțimea scoasă (recenzia m6): `rel="pingback stylesheet"` rămâne, iar politica
 *    îl refuză;
 *  - **fonturile Google** (`fonturi-locale`, `dns-prefetch`, §6.7 #3): foaia Google devine `/capturat/fonturi/<cheie>.css`
 *    (FonturiGoogle), `preconnect`-ul spre gazdele lor și orice `dns-prefetch` (doar `dns-prefetch`) se scot;
 *  - **indiciile de resurse spre alte gazde** (`indiciu-tert`, hotărârea din recenzie, declarată în `ce-se-schimba.md`): un
 *    `<link>` doar cu `rel`-uri din HtmlCapturat::LINK_INDICII (`preconnect`, `prefetch`, `preload`, `modulepreload`,
 *    `dns-prefetch`) spre o gazdă străină (adresă absolută http(s) sau protocol-relativă, și cu `\`) se scoate: site-ul nou nu
 *    pregătește nicio conexiune spre terți. Unul spre site rămâne (relativ după normalizare); politica refuză unul rămas spre
 *    altă gazdă;
 *  - **legăturile pachetului** (`legatura-pachet`; `import-wordpress.json` → `legaturi`, ce-se-schimba.md): în modulul numit (o
 *    singură apariție în document; un document fără el rămâne neatins), textul exact dat devine
 *    `<a href="…" style="line-height:inherit">text</a>` (spațiile din jur rămân în afară; culoarea și fontul, din regulile Divi ale
 *    legăturilor modulului; înălțimea rândului, a textului); modulul pierde legătura Divi prin script (clasa `et_clickable` și intrarea lui din
 *    `et_link_options_data`), ca să aibă o singură legătură. Un text care nu e în modul e NU (pachetul și captura nu se mai
 *    potrivesc); unul deja într-un `<a>` rămâne, cu un avertisment;
 *  - **normalizările capturii** (Normalizare: `nonce`, `clasa-ua`, `adresa-proprie`). Secretul aleator al cardului embed nu
 *    intră în T: T păstrează varianta capturată (decizia D7), iar comparațiile (captura, P1, P2) îl normalizează; la fel
 *    celelalte variații aleatoare (Normalizare::FELURI_COMPARATIE).
 *
 * **Fail-closed:** jetoanele noastre au forma `{{ax:…}}`, deci o captură care conține deja textul `{{ax:` (oricum scris) e NU:
 * altfel un text al paginii ar fi luat drept jeton la randare.
 *
 * Rezultatul trece prin politica HtmlCapturat în planul importului (Plan), deci ce rămâne nepermis e NU pe pagină.
 */
final class Divi4 implements Convertor, Dialect
{
    /** Felurile editărilor dialectului (în plus față de Normalizare::FELURI). */
    public const FELURI = ['script-aruncat', 'noscript-aruncat', 'config', 'ax-date', 'handler-tarziu', 'handler-media', 'link-wp',
        'dns-prefetch', 'fonturi-locale', 'indiciu-tert', 'legatura-pachet'];

    /** Literalele JSON din scripturile-șablon aruncate care trec în `ax-date` (`var file = […]`: foile „late”). */
    public const DATE_INCORPORATE = SchemaDivi4::DATE_INCORPORATE;

    /** `rel`-urile legăturilor spre adrese WordPress care dispar (DECIZIE §2.3 #9). */
    private const REL_WP = ['pingback', 'edituri', 'wlwmanifest', 'https://api.w.org/'];

    private const GAZDE_FONTURI = [FonturiGoogle::GAZDA_CSS, FonturiGoogle::GAZDA_FONTURI];

    private const PROPRIETARI_GOI = ['pagina' => [], 'antet' => [], 'subsol' => []];

    public function __construct(private readonly ?DosarCapturi $captura = null)
    {
    }

    public function nume(): string
    {
        return 'dialectul divi4 (captura)';
    }

    /** Captura pe care lucrează dialectul (pasul 4), sau null (atunci fiecare pagină e NU). */
    public function captura(): ?DosarCapturi
    {
        return $this->captura;
    }

    public function dialect(): string
    {
        return 'divi4';
    }

    public function stie(string $constructor): bool
    {
        return $constructor === 'dialect:divi4';
    }

    public function design(Context $ctx): array
    {
        return [];
    }

    public function converteste(array $pagina, Context $ctx): array
    {
        $rez = ['html' => '', 'css' => '', 'js' => '', 'probleme' => [], 'avertismente' => []];
        if ($this->captura === null) {
            $rez['probleme'][] = 'lipsește captura site-ului (pasul 4: scripts/captureaza-wordpress.php)';
            return $rez;
        }
        $prima = $ctx->inventar->site['arata_pe_prima'] === 'page' && (int) $ctx->inventar->site['prima_pagina'] === (int) ($pagina['id'] ?? 0);
        $cale = $prima ? '/' : (string) ($pagina['cale_wp'] ?? $pagina['cale'] ?? '');
        $r = $this->captura->dupaCale($cale);
        if ($r === null) {
            $rez['probleme'][] = 'lipsește din captură (' . $cale . ')';
            return $rez;
        }
        if ($r->status !== 200) {
            $rez['probleme'][] = 'captura paginii (' . $cale . ') are statusul ' . $r->status . ', nu 200';
            return $rez;
        }
        $d = $this->transforma($r->corp, $this->captura->url, $ctx->reguli, $cale);
        return ['html' => $d->html, 'css' => '', 'js' => '', 'probleme' => $d->probleme, 'avertismente' => $d->avertismente, 'dialect' => $d];
    }

    /** sha256 → motiv, din `import-wordpress.json` → `scripturi_aruncate`. @return array<string, string> */
    public static function listaAruncate(array $reguli): array
    {
        $rez = [];
        foreach ((array) ($reguli['scripturi_aruncate'] ?? []) as $x) {
            if (is_array($x) && is_string($x['sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $x['sha256']) === 1) {
                $rez[$x['sha256']] = (string) ($x['motiv'] ?? '');
            }
        }
        return $rez;
    }

    /**
     * T(captura), prin Dialect::transforma. `$document` (în plus față de interfață): calea documentului pe site (`/camere/`) sau
     * `404`, după care se caută datele lui din pachet (`captura.structura` → `ax_structura`); fără ea, nimic din pachet pe document.
     */
    public function transforma(string $html, string $urlSite, array $reguli, ?string $document = null): RezultatDialect
    {
        // Orice eroare neașteptată (o configurație care nu se poate coda, un caz neprevăzut) e NU pe pagină, nu o eroare fatală
        // a importului (recenzia m1).
        try {
            return $this->transformaNeprotejat($html, $urlSite, $reguli, $document);
        } catch (\Throwable $e) {
            return new RezultatDialect('', [], [], self::PROPRIETARI_GOI, [], [], ['cap' => null, 'final' => null],
                ['eroare neașteptată în dialect (' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200) . '): pagina nu se importă']);
        }
    }

    private function transformaNeprotejat(string $html, string $urlSite, array $reguli, ?string $document): RezultatDialect
    {
        if (($j = Jetoane::literal($html)) !== null) {
            return new RezultatDialect('', [], [], self::PROPRIETARI_GOI, [], [], ['cap' => null, 'final' => null],
                ['captura conține deja textul „{{ax:” la octetul ' . $j . ' (forma jetoanelor noastre): pagina nu se importă']);
        }
        $an = Tokenizer::analizeaza($html);
        if ($an['nesigure'] !== []) {
            return new RezultatDialect('', [], [], self::PROPRIETARI_GOI, [], [], ['cap' => null, 'final' => null],
                ['document nesigur (tokenizare care poate diferi între browsere): ' . $an['nesigure'][0]]);
        }
        $tokeni = $an['tokeni'];
        // Id-urile și numele „ax-…” sunt ale noastre (blocul ax-date, runtime-ul): unul deja în captură, oricum scris (și cu
        // entități, ca `ax&#45;date`), ar putea lua locul blocului nostru în DOM (recenzia M2). NU.
        foreach ($tokeni as $t) {
            if ($t->tip !== Token::DESCHIDERE) {
                continue;
            }
            foreach (['id', 'name'] as $at) {
                $v = $t->valoare($html, $at);
                if ($v !== null && str_starts_with(strtolower(trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 'ax-')) {
                    return new RezultatDialect('', [], [], self::PROPRIETARI_GOI, [], [], ['cap' => null, 'final' => null],
                        ['captura are deja un element cu ' . $at . '="' . mb_substr($v, 0, 40) . '" la octetul ' . $t->start
                            . ' (id-urile și numele „ax-…” sunt ale importului): pagina nu se importă']);
                }
            }
        }
        $norm = new Normalizare($urlSite, array_map('strval', (array) ($reguli['captura']['nonce'] ?? [])));
        $lista = self::listaAruncate($reguli);
        $corpStart = PHP_INT_MAX;
        $headSfarsit = null;
        foreach ($tokeni as $t) {
            if ($t->tip === Token::DESCHIDERE && $t->nume === 'body' && !$t->ignorat && $corpStart === PHP_INT_MAX) {
                $corpStart = $t->start;
            }
            if ($t->tip === Token::INCHIDERE && $t->nume === 'head' && $headSfarsit === null) {
                $headSfarsit = $t->start;
            }
        }
        /** @var list<array{fel: string, zona: array{0: int, 1: int}, fa: callable(Editare): void}> $ops */
        $ops = [];
        $sterse = [];
        $date = [];
        $aruncate = [];
        $necunoscute = [];
        $probleme = [];
        $avertismente = [];
        $primulCap = null;
        $ultimulCorp = null;
        $sterge = function (int $k, int $s, int $e, string $fel) use (&$ops, &$sterse, &$primulCap, &$ultimulCorp, $corpStart): void {
            $ops[] = ['fel' => $fel, 'zona' => [$s, $e], 'fa' => static fn (Editare $ed) => $ed->stergeNod($k)];
            $sterse[] = [$s, $e];
            if ($fel === 'script-aruncat' || $fel === 'config') {
                if ($s < $corpStart) {
                    $primulCap ??= $s;
                } else {
                    $ultimulCorp = $s;
                }
            }
        };
        foreach ($tokeni as $k => $t) {
            if ($t->tip !== Token::DESCHIDERE || $t->spatiu !== 'html' || $t->ignorat) {
                continue;
            }
            if ($t->nume === 'script' || $t->nume === 'noscript') {
                $p = Tokenizer::pereche($tokeni, $k);
                if ($p === null) {
                    $probleme[] = '<' . $t->nume . '> fără tag de închidere la octetul ' . $t->start . ': nu se poate scoate întreg';
                    continue;
                }
                $s = $t->start;
                $e = $tokeni[$p]->end;
                $urm = $tokeni[$k + 1] ?? null;
                $continut = $urm !== null && $urm->tip === Token::TEXT && $urm->brut === $t->nume && $urm->end <= $tokeni[$p]->start ? $urm->octeti($html) : '';
                $deschidere = $t->octeti($html);
                $inchidere = $tokeni[$p]->octeti($html);
                if ($t->nume === 'noscript') {
                    $sha = hash('sha256', $norm->textScript($deschidere . $continut . $inchidere));
                    if (isset($lista[$sha])) {
                        $sterge($k, $s, $e, 'noscript-aruncat');
                        $aruncate[] = ['sha256' => $sha, 'motiv' => $lista[$sha], 'fel' => 'noscript', 'start' => $s, 'end' => $e];
                    } else {
                        // Rămâne în pagină (politica îi verifică conținutul); sha256-ul lui, pentru o eventuală trecere pe listă.
                        $necunoscute[] = ['sha256' => $sha, 'fel' => 'noscript', 'id' => null, 'type' => null, 'src' => null,
                            'inceput' => trim((string) preg_replace('/\s+/', ' ', mb_substr($norm->textScript($continut), 0, 70))), 'start' => $s];
                    }
                    continue;
                }
                $type = $t->valoare($html, 'type');
                $id = $t->valoare($html, 'id');
                $src = $t->valoare($html, 'src');
                if (SchemaDivi4::scriptInert($type, $id)) {
                    if ($id !== null && strtolower(trim($id)) === SchemaDivi4::AX_DATE_ID) {
                        $probleme[] = 'captura are deja un bloc „' . SchemaDivi4::AX_DATE_ID . '” la octetul ' . $s . ' (ar intra în conflict cu al nostru)';
                    }
                    continue; // JSON-LD, speculationrules: date inerte, rămân
                }
                $cn = $norm->textScript($continut);
                if ($src === null) {
                    try {
                        $config = self::configuratie($cn, $id);
                    } catch (InvalidArgumentException $e) {
                        $probleme[] = 'configurația de la octetul ' . $s . ' nu se poate citi strict ca JSON (' . $e->getMessage() . ')';
                        continue;
                    }
                    if ($config !== null && array_diff(array_keys($config), SchemaDivi4::CONFIG) === []) {
                        foreach ($config as $nume => $val) {
                            // Aceeași configurație de două ori: doar dacă JSON-ul canonic e același (recenzia m5), altfel NU.
                            if (array_key_exists($nume, $date) && self::json([$nume => $date[$nume]]) !== self::json([$nume => $val])) {
                                $probleme[] = 'configurația „' . $nume . '” apare de două ori, cu valori diferite (octetul ' . $s . ')';
                            }
                            $date[$nume] = $val;
                        }
                        $sterge($k, $s, $e, 'config');
                        continue;
                    }
                    try {
                        [$schelet, $incorporate] = self::schelet($cn);
                    } catch (InvalidArgumentException $e) {
                        $probleme[] = 'datele scriptului de la octetul ' . $s . ' nu se pot citi strict ca JSON (' . $e->getMessage() . ')';
                        continue;
                    }
                    $sha = hash('sha256', $norm->textScript($deschidere) . $schelet . $inchidere);
                } else {
                    $incorporate = [];
                    $sha = hash('sha256', $norm->textScript($deschidere . $continut . $inchidere));
                }
                if (isset($lista[$sha])) {
                    foreach ($incorporate as $nume => $val) {
                        // Mai multe încărcătoare „late” pe pagină: toate foile lor, în ordinea din pagină (recenzia m6).
                        if ($nume === 'file' && is_array($val) && array_is_list($val) && is_array($date['file'] ?? null)) {
                            $date['file'] = array_merge($date['file'], $val);
                            continue;
                        }
                        if (array_key_exists($nume, $date) && self::json([$nume => $date[$nume]]) !== self::json([$nume => $val])) {
                            $probleme[] = 'datele „' . $nume . '” apar de două ori, cu valori diferite (octetul ' . $s . ')';
                        }
                        $date[$nume] = $val;
                    }
                    $sterge($k, $s, $e, 'script-aruncat');
                    $aruncate[] = ['sha256' => $sha, 'motiv' => $lista[$sha], 'fel' => 'script', 'id' => $id, 'src' => $src, 'start' => $s, 'end' => $e];
                    continue;
                }
                $inceput = trim((string) preg_replace('/\s+/', ' ', mb_substr($cn !== '' ? $cn : (string) $src, 0, 70)));
                $necunoscute[] = ['sha256' => $sha, 'fel' => $src !== null ? 'script-src' : 'script-inline', 'id' => $id, 'type' => $type, 'src' => $src,
                    'inceput' => $inceput, 'start' => $s];
                $probleme[] = 'script ' . ($src !== null ? 'src' : 'inline') . ' necunoscut la octetul ' . $s . ' (sha256 ' . $sha . ($id !== null ? ', id „' . $id . '”' : '')
                    . ', „' . $inceput . '”): după o revizie, trece-l în import-wordpress.json → scripturi_aruncate';
                continue;
            }
            if ($t->nume === 'link') {
                $rel = strtolower(trim((string) $t->valoare($html, 'rel')));
                $reluri = preg_split('/[\t\n\f\r ]+/', $rel, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $href = (string) $t->valoare($html, 'href');
                $tipLink = strtolower(trim((string) $t->valoare($html, 'type')));
                $hrefDec = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Un <link> se scoate doar dacă TOATE rel-urile lui sunt din mulțimea scoasă (recenzia m6).
                $doarDin = static fn (array $multime): bool => $reluri !== [] && array_diff($reluri, $multime) === [];
                if ($doarDin(self::REL_WP) || ($reluri === ['alternate'] && $tipLink === 'application/json' && str_contains($hrefDec, '/wp-json/'))) {
                    $sterge($k, $t->start, $t->end, 'link-wp');
                    continue;
                }
                if ($reluri === ['dns-prefetch']) {
                    $sterge($k, $t->start, $t->end, 'dns-prefetch');
                    continue;
                }
                if ($doarDin(HtmlCapturat::LINK_INDICII) && ($gazda = self::gazdaStraina($hrefDec, $norm)) !== null) {
                    $sterge($k, $t->start, $t->end, $reluri === ['preconnect'] && in_array($gazda, self::GAZDE_FONTURI, true) ? 'fonturi-locale' : 'indiciu-tert');
                    continue;
                }
                if (FonturiGoogle::esteFoaie($href)) {
                    $a = $t->atribut('href');
                    $cale = FonturiGoogle::cale($href);
                    $ops[] = ['fel' => 'fonturi-locale', 'zona' => [$a->valoareStart, $a->valoareEnd], 'fa' => static fn (Editare $ed) => $ed->valoareAtribut($k, 'href', $cale)];
                }
            }
            foreach ($t->atribute as $a) {
                if ($a->duplicat || !str_starts_with($a->nume, 'on')) {
                    continue;
                }
                $rec = SchemaDivi4::handlerRecunoscut($t->nume, $a->nume, $a->valoare($html), self::contextHandler($html, $t, $norm));
                if ($rec === null) {
                    continue; // politica îl refuză (NU pe pagină): niciun handler necunoscut nu se transformă
                }
                if ($rec[0] === 'data-ax-tarziu') {
                    $ops[] = ['fel' => 'handler-tarziu', 'zona' => [$a->start, $a->end], 'fa' => static fn (Editare $ed) => $ed->inlocuieste($a->start, $a->end, 'data-ax-tarziu')];
                } else {
                    // `onload="media='all'"` → `media="all"`: o singură operație (scoaterea handlerului și valoarea lui media), cu
                    // zona de la primul atribut atins până la sfârșitul tagului.
                    $media = $t->atribut('media');
                    $de = $t->numeEnd;
                    foreach ($t->atribute as $alt) {
                        if ($alt->end <= min($a->start, $media?->start ?? PHP_INT_MAX)) {
                            $de = max($de, $alt->end);
                        }
                    }
                    $nume = $a->nume;
                    $valoare = (string) $rec[1];
                    $ops[] = ['fel' => 'handler-media', 'zona' => [$de, $t->end], 'fa' => static function (Editare $ed) use ($k, $nume, $valoare): void {
                        $ed->stergeAtribut($k, $nume);
                        $ed->valoareAtribut($k, 'media', $valoare);
                    }];
                }
            }
        }
        // Normalizările capturii, în afara nodurilor scoase. Secretul cardului embed rămâne cel capturat (D7): din editările
        // normalizării se scoate doar partea lui (`{{ax:secret}}` înapoi la octeții capturii, în ordine).
        foreach ($norm->editari($html, $tokeni, false, $sterse) as $n) {
            if (in_array('secret', $n['fel'], true)) {
                $n = self::faraSecret($html, $n);
                if ($n === null) {
                    continue;
                }
                if (str_contains($n['text'], '{{ax:secret}}')) {
                    $probleme[] = 'secretul cardului embed de la octetul ' . $n['start'] . ' nu poate rămâne cel capturat (D7)';
                    continue;
                }
            }
            $fel = implode('+', $n['fel']);
            if ($n['tip'] === 'atribut') {
                $k = $n['token'];
                $nume = (string) $n['atribut'];
                $text = $n['text'];
                $ops[] = ['fel' => $fel, 'zona' => [$n['start'], $n['end']], 'fa' => static fn (Editare $ed) => $ed->valoareAtribut($k, $nume, $text)];
            } else {
                $s = $n['start'];
                $e = $n['end'];
                $text = $n['text'];
                $ops[] = ['fel' => $fel, 'zona' => [$s, $e], 'fa' => static fn (Editare $ed) => $ed->inlocuieste($s, $e, $text)];
            }
        }
        // Legăturile pachetului (import-wordpress.json → legaturi): textul exact din modulul numit devine <a href>, iar legătura Divi
        // a modulului (prin script) se scoate.
        $pbL = self::valideazaLegaturi($reguli['legaturi'] ?? []);
        if ($pbL !== []) {
            array_push($probleme, ...$pbL);
        } else {
            foreach ((array) ($reguli['legaturi'] ?? []) as $leg) {
                $this->legatura($html, $tokeni, $leg, $ops, $date, $probleme, $avertismente);
            }
        }
        // ax_structura: clasele de structură ale documentului, din pachet (captura.structura; runtime-ul structura.js), ultimele în
        // ax-date și ale paginii; verificate și aici: una nepermisă e NU, nu o scoatere în tăcere.
        $structura = self::structuraDocument($reguli, $document);
        if ($structura !== null) {
            $pbS = SchemaDivi4::structura($structura);
            foreach ($pbS as $pb) {
                $probleme[] = 'captura.structura („' . $document . '”): ' . $pb;
            }
            if ($pbS === []) {
                $date[SchemaDivi4::AX_STRUCTURA] = json_decode((string) json_encode($structura, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            }
        }
        // Blocul ax-date ia locul primului script scos din head (o singură înlocuire a nodului: o inserare separată, lângă
        // ștergere, n-ar trece de verificarea combinată a lui Editare); fără niciun script scos în head, stă înaintea lui </head>.
        if ($date !== []) {
            $bloc = '<script type="application/json" id="' . SchemaDivi4::AX_DATE_ID . '">' . self::json($date) . '</script>';
            $loc = null;
            foreach ($ops as $i => $op) {
                if ($primulCap !== null && $op['zona'][0] === $primulCap && in_array($op['fel'], ['script-aruncat', 'config'], true)) {
                    $loc = $i;
                }
            }
            if ($loc !== null) {
                [$s, $e] = $ops[$loc]['zona'];
                $ops[$loc] = ['fel' => 'ax-date', 'zona' => [$s, $e], 'fa' => static fn (Editare $ed) => $ed->inlocuieste($s, $e, $bloc)];
            } elseif ($headSfarsit !== null) {
                $inchidere = '';
                foreach ($tokeni as $t) {
                    if ($t->start === $headSfarsit) {
                        $inchidere = $t->octeti($html);
                        $he = $t->end;
                    }
                }
                $hs = $headSfarsit;
                $he ??= $hs;
                $ops[] = ['fel' => 'ax-date', 'zona' => [$hs, $he], 'fa' => static fn (Editare $ed) => $ed->inlocuieste($hs, $he, $bloc . $inchidere)];
            } else {
                $probleme[] = 'pagina n-are head: blocul ax-date n-are unde sta';
            }
        }
        $proprietari = self::proprietari($date);
        $ed = new Editare($html);
        usort($ops, static fn (array $a, array $b): int => $a['zona'] <=> $b['zona']);
        try {
            foreach ($ops as $op) {
                ($op['fa'])($ed);
            }
            $intervale = $ed->inlocuiri();
            $nou = $ed->aplica();
        } catch (InvalidArgumentException $e) {
            $probleme[] = 'editarea capturii a fost refuzată (' . $e->getMessage() . ')';
            return new RezultatDialect('', [], $date, $proprietari, $aruncate, $necunoscute, ['cap' => null, 'final' => null], $probleme, $avertismente);
        }
        // Felul fiecărui interval: operația cu cea mai mică zonă care îl cuprinde.
        $editari = [];
        $folosite = [];
        $delta = 0;
        $cap = null;
        $final = null;
        foreach ($intervale as [$s, $e, $text]) {
            $ales = null;
            foreach ($ops as $i => $op) {
                [$za, $zb] = $op['zona'];
                if ($s >= $za && $e <= $zb && ($ales === null || ($zb - $za) < ($ops[$ales]['zona'][1] - $ops[$ales]['zona'][0]))) {
                    $ales = $i;
                }
            }
            if ($ales === null) {
                $probleme[] = 'o înlocuire [' . $s . ', ' . $e . ') nu e a niciunei operații declarate (eroare internă a dialectului)';
                continue;
            }
            $folosite[$ales] = true;
            $fel = $ops[$ales]['fel'];
            $pozT = $s + $delta;
            if ($fel === 'ax-date' || (($fel === 'script-aruncat' || $fel === 'config') && $s === $primulCap)) {
                $cap ??= $pozT;
            }
            if (in_array($fel, ['script-aruncat', 'config', 'ax-date'], true) && $s === $ultimulCorp) {
                $final = $pozT;
            }
            $editari[] = ['fel' => $fel, 'start' => $s, 'end' => $e, 'text' => $text];
            $delta += strlen($text) - ($e - $s);
        }
        if (count($folosite) !== count($ops)) {
            $probleme[] = (count($ops) - count($folosite)) . ' operații ale dialectului n-au dat nicio înlocuire (eroare internă)';
        }
        return new RezultatDialect($nou, $editari, $date, $proprietari, $aruncate, $necunoscute, ['cap' => $cap, 'final' => $final], $probleme, $avertismente);
    }

    /**
     * Regula pachetului `legaturi` validă? O listă de `{"modul": "et_pb_text_8_tb_footer", "text": "…", "href": "https://…"}`:
     * modulul, o clasă numerotată Divi (o singură dată în listă); textul, 1–200 de caractere fără `<`, `>`, `&` și fără spații la
     * capete (se compară cu textul decodat al modulului); adresa, `https://gazdă[/cale]` sau o cale a site-ului, fără ghilimele,
     * spații sau `<>`.
     *
     * @return list<string> problemele
     */
    public static function valideazaLegaturi(mixed $v): array
    {
        if ($v === []) {
            return [];
        }
        if (!is_array($v) || !array_is_list($v)) {
            return ['legaturi: o listă de {"modul": "et_pb_text_<n>…", "text": "…", "href": "https://…"}'];
        }
        $p = [];
        $module = [];
        foreach ($v as $i => $l) {
            $e = 'legaturi[' . $i . ']';
            if (!is_array($l) || array_keys($l) !== ['modul', 'text', 'href']) {
                $p[] = $e . ': exact cheile modul, text, href, în ordinea asta';
                continue;
            }
            [$modul, $text, $href] = [$l['modul'], $l['text'], $l['href']];
            if (!is_string($modul) || preg_match(\App\Support\ImportWordPress\StiluriComune::CLASA_MODUL, $modul) !== 1 || isset($module[$modul])) {
                $p[] = $e . '.modul: o clasă numerotată de modul Divi (et_pb_text_8_tb_footer), o singură dată în listă';
            }
            if (!is_string($text) || $text === '' || mb_strlen($text) > 200 || trim($text) !== $text || strpbrk($text, '<>&') !== false) {
                $p[] = $e . '.text: 1–200 de caractere, fără <, >, & și fără spații la capete';
            }
            if (!is_string($href) || preg_match('#^(?:https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?:/[^\s"\'<>\\\\]*)?|/[^\s"\'<>\\\\/][^\s"\'<>\\\\]*|/)$#', $href) !== 1) {
                $p[] = $e . '.href: https://gazdă[/cale] sau o cale a site-ului (/…)';
            }
            $module[(string) (is_string($modul) ? $modul : '')] = true;
        }
        return $p;
    }

    /**
     * O legătură a pachetului pe document (valideazaLegaturi a trecut): operațiile ei (textul, clasa modulului) și intrarea scoasă
     * din `et_link_options_data`.
     *
     * @param list<Token> $tokeni
     * @param array{modul: string, text: string, href: string} $leg
     * @param list<array{fel: string, zona: array{0: int, 1: int}, fa: callable(Editare): void}> $ops
     * @param array<string, mixed> $date
     * @param list<string> $probleme
     * @param list<string> $avertismente
     */
    private function legatura(string $html, array $tokeni, array $leg, array &$ops, array &$date, array &$probleme, array &$avertismente): void
    {
        $gasite = [];
        foreach ($tokeni as $k => $t) {
            if ($t->tip === Token::DESCHIDERE && !$t->ignorat && in_array($leg['modul'], $t->clase($html), true)) {
                $gasite[] = $k;
            }
        }
        if ($gasite === []) {
            return;
        }
        $e = 'legaturi (' . $leg['modul'] . '): ';
        if (count($gasite) > 1) {
            $probleme[] = $e . 'modulul apare de ' . count($gasite) . ' ori în document';
            return;
        }
        $k = $gasite[0];
        $p = Tokenizer::pereche($tokeni, $k);
        if ($p === null) {
            $probleme[] = $e . 'modulul nu se închide';
            return;
        }
        $deschise = [];
        for ($j = $k + 1; $j < $p; $j++) {
            $x = $tokeni[$j];
            if ($x->tip === Token::DESCHIDERE && $x->nume === 'a' && !$x->ignorat) {
                $deschise[] = Tokenizer::pereche($tokeni, $j) ?? $p;
            }
            if ($x->tip !== Token::TEXT || trim(html_entity_decode($x->octeti($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== $leg['text']) {
                continue;
            }
            if (array_filter($deschise, static fn (int $inchis): bool => $inchis > $j) !== []) {
                $avertismente[] = $e . 'textul e deja într-o legătură: rămâne';
                return;
            }
            $brut = $x->octeti($html);
            preg_match('/^(\s*)(.*?)(\s*)$/s', $brut, $m);
            // Culoarea, fontul și hover-ul vin din regulile Divi pentru legăturile modulului; înălțimea rândului rămâne a textului
            // (Divi le dă adesea un line-height propriu, care ar înălța rândul față de textul de azi).
            $nou = $m[1] . '<a href="' . Editare::scapa($leg['href']) . '" style="line-height:inherit">' . $m[2] . '</a>' . $m[3];
            [$s, $f] = [$x->start, $x->end];
            $ops[] = ['fel' => 'legatura-pachet', 'zona' => [$s, $f], 'fa' => static fn (Editare $ed) => $ed->inlocuieste($s, $f, $nou)];
            $t = $tokeni[$k];
            $clasa = $t->valoare($html, 'class');
            if ($clasa !== null && in_array('et_clickable', $t->clase($html), true)) {
                $faraClic = trim((string) preg_replace('/(?:^|[\t\n\f\r ]+)et_clickable(?=[\t\n\f\r ]|$)/', '', $clasa));
                $ops[] = ['fel' => 'legatura-pachet', 'zona' => [$t->start, $t->end], 'fa' => static fn (Editare $ed) => $ed->valoareAtribut($k, 'class', $faraClic)];
            }
            if (is_array($date['et_link_options_data'] ?? null)) {
                $date['et_link_options_data'] = array_values(array_filter($date['et_link_options_data'],
                    static fn (mixed $o): bool => !($o instanceof \stdClass && ($o->class ?? null) === $leg['modul'])));
                if ($date['et_link_options_data'] === []) {
                    unset($date['et_link_options_data']);
                }
            }
            return;
        }
        $probleme[] = $e . 'textul „' . $leg['text'] . '” nu e în modul (pachetul și captura nu se mai potrivesc)';
    }

    /**
     * O editare a normalizării fără partea `secret` (D7): fiecare `{{ax:secret}}` din textul nou primește înapoi, în ordine, secretul
     * capturat din intervalul ei; null dacă, fără el, editarea nu mai schimbă nimic.
     *
     * @param array{start: int, end: int, text: string, fel: list<string>} $n
     * @return array{start: int, end: int, text: string, fel: list<string>}|null
     */
    private static function faraSecret(string $html, array $n): ?array
    {
        $vechi = substr($html, $n['start'], $n['end'] - $n['start']);
        preg_match_all('/(?:data-secret=(?:&quot;|&#034;|\\\\"|"|\')|#\?secret=)([A-Za-z0-9]{10})(?![A-Za-z0-9])/', $vechi, $m);
        $i = 0;
        $n['text'] = (string) preg_replace_callback('/\{\{ax:secret\}\}/', static function (array $x) use ($m, &$i): string {
            return $m[1][$i++] ?? $x[0];
        }, $n['text']);
        $n['fel'] = array_values(array_diff($n['fel'], ['secret']));
        return $n['text'] === $vechi || $n['fel'] === [] ? null : $n;
    }

    /**
     * Intrarea din `captura.structura` a documentului (după cale, comparată decodat, sau `404`), ori null.
     *
     * @param array<string, mixed> $reguli
     */
    private static function structuraDocument(array $reguli, ?string $document): mixed
    {
        if ($document === null || !is_array($reguli['captura']['structura'] ?? null)) {
            return null;
        }
        foreach ($reguli['captura']['structura'] as $cheie => $v) {
            if (rawurldecode((string) $cheie) === rawurldecode($document)) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Gazda unei adrese absolute (`http(s)://gazdă…`) sau protocol-relative (`//gazdă…`, și cu `\`, cum o citește browserul) care
     * nu e a site-ului; null pentru o adresă relativă, a site-ului sau cu altă schemă (pe aceea o judecă politica).
     */
    private static function gazdaStraina(string $href, Normalizare $norm): ?string
    {
        if (preg_match('#^(?:https?:)?[/\\\\]{2}([^/?\#\\\\@]*)#i', trim($href), $m) !== 1) {
            return null;
        }
        $gazda = strtolower($m[1]);
        return $gazda !== '' && in_array($gazda, $norm->gazde(), true) ? null : $gazda;
    }

    /**
     * Contextul politicii pe drumul capturat (recenzia m7): lista albă implicită a iframe-urilor și foile de stil din manifestul
     * capturii (`manifest.json` → `fisiere`), singurele pe care un `<link>` dintr-un `<noscript>` le poate cere. Fără captură
     * sau fără un manifest citibil: nicio foaie (niciun `<link>` în `<noscript>`).
     */
    public function contextPolitica(): ContextCapturat
    {
        return ContextCapturat::dinManifest($this->captura !== null ? $this->captura->dosar . '/manifest.json' : null);
    }

    /**
     * Contextul unui handler pentru lista închisă (SchemaDivi4::handlerRecunoscut): `rel`, `as`, `href` (octeții lor), dacă
     * `href`-ul e al site-ului (relativ, ori absolut pe o gazdă proprie) și dacă tagul are mai multe atribute `on…`.
     *
     * @return array{rel: ?string, as: ?string, href: ?string, hrefPropriu: bool, dubluOn: bool}
     */
    private static function contextHandler(string $html, Token $t, Normalizare $norm): array
    {
        $on = 0;
        foreach ($t->atribute as $a) {
            if (str_starts_with($a->nume, 'on')) {
                $on++;
            }
        }
        $href = $t->valoare($html, 'href');
        $propriu = false;
        if ($href !== null && $href !== '') {
            $h = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($h, '/') && !str_starts_with($h, '//') && !str_contains($h, '\\')) {
                $propriu = true;
            } elseif (preg_match('#^https?://([^/?\#@\\\\]+)(?:[/?\#]|$)#i', $h, $m) === 1) {
                $propriu = in_array(strtolower($m[1]), $norm->gazde(), true);
            }
        }
        return ['rel' => $t->valoare($html, 'rel'), 'as' => $t->valoare($html, 'as'), 'href' => $href, 'hrefPropriu' => $propriu, 'dubluOn' => $on > 1];
    }

    /**
     * Configurația unui script `var <nume> = <JSON>;` (una sau mai multe declarații, doar spații între ele, plus cel mult
     * comentariul `//# sourceURL=<id>` pus de WordPress, cu id-ul scriptului): numele → valoarea, citită strict ca JSON
     * (SchemaDivi4::jsonStrict: obiectele rămân obiecte; o cheie dublată sau goală, un întreg peste ±2^53, un număr infinit =
     * InvalidArgumentException); null dacă scriptul nu are exact această formă.
     *
     * @return array<string, mixed>|null
     * @throws InvalidArgumentException
     */
    public static function configuratie(string $js, ?string $id = null): ?array
    {
        // WordPress pune după scripturile lui comentariul `//# sourceURL=<id-ul scriptului>`: doar acesta, exact, e acceptat.
        if ($id !== null && preg_match('/\s*\/\/# sourceURL=([A-Za-z0-9_.-]+)\s*$/', $js, $m, PREG_OFFSET_CAPTURE) === 1 && $m[1][0] === $id) {
            $js = substr($js, 0, $m[0][1]);
        }
        if (SchemaDivi4::scriptConfig($js) === []) {
            return null;
        }
        $rez = [];
        $p = 0;
        $n = strlen($js);
        while (preg_match('/\G\s*var\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*/A', $js, $m, 0, $p) === 1) {
            $p += strlen($m[0]);
            $sf = SchemaDivi4::sfarsitJson($js, $p);
            if ($sf === null) {
                return null;
            }
            $rez[$m[1]] = SchemaDivi4::jsonStrict(substr($js, $p, $sf - $p));
            $p = $sf;
            if (preg_match('/\G\s*;/A', $js, $m2, 0, $p) !== 1) {
                return null;
            }
            $p += strlen($m2[0]);
            if ($p >= $n) {
                break;
            }
        }
        return trim(substr($js, $p)) === '' ? $rez : null;
    }

    /**
     * Scheletul unui script-șablon: fiecare `var <nume> = <JSON>` cu numele din DATE_INCORPORATE are literalul înlocuit de
     * `«nume»` (așa sha256-ul nu depinde de datele paginii), iar literalele se întorc decodate.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function schelet(string $js): array
    {
        $date = [];
        $rez = '';
        $poz = 0;
        $nume = implode('|', array_map(static fn (string $x): string => preg_quote($x, '/'), self::DATE_INCORPORATE));
        if (preg_match_all('/\bvar\s+(' . $nume . ')\s*=\s*/', $js, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as $i => [$potrivire, $start]) {
                $inceput = $start + strlen($potrivire);
                if ($inceput < $poz) {
                    continue;
                }
                $sf = SchemaDivi4::sfarsitJson($js, $inceput);
                if ($sf === null) {
                    continue;
                }
                $val = SchemaDivi4::jsonStrict(substr($js, $inceput, $sf - $inceput));
                $n = $m[1][$i][0];
                $date[$n] = $val;
                $rez .= substr($js, $poz, $inceput - $poz) . '«' . $n . '»';
                $poz = $sf;
            }
        }
        return [$rez . substr($js, $poz), $date];
    }

    /**
     * JSON-ul blocului `ax-date`: aceleași date, codate determinist; „<” și „&” scăpate (nimic nu poate închide scriptul). Aceeași
     * codare ca la randare (CadruCapturat::json), ca dovada tăierii și P1 să compare aceiași octeți.
     */
    public static function json(array $date): string
    {
        return CadruCapturat::json($date);
    }

    /**
     * Configurațiile împărțite pe proprietar (DECIZIE §2.3 #6): cheile (sau elementele cu `class`) cu sufixul `_tb_header` merg la
     * antet, cele cu `_tb_footer` la subsol, restul la pagină.
     *
     * @param array<string, mixed> $date
     * @return array{pagina: array<string, mixed>, antet: array<string, mixed>, subsol: array<string, mixed>}
     */
    public static function proprietari(array $date): array
    {
        $rez = self::PROPRIETARI_GOI;
        $al = static fn (string $cheie): string => str_contains($cheie, '_tb_header') ? 'antet' : (str_contains($cheie, '_tb_footer') ? 'subsol' : 'pagina');
        foreach ($date as $nume => $val) {
            if ($val instanceof \stdClass && get_object_vars($val) !== []) {
                foreach (get_object_vars($val) as $k => $v) {
                    $p = $al((string) $k);
                    $rez[$p][$nume] ??= new \stdClass();
                    $rez[$p][$nume]->{$k} = $v;
                }
            } elseif (is_array($val) && $val !== [] && array_is_list($val)) {
                foreach ($val as $v) {
                    $p = $al($v instanceof \stdClass && isset($v->class) && is_string($v->class) ? $v->class : '');
                    $rez[$p][$nume][] = $v;
                }
            } else {
                $rez['pagina'][$nume] = $val;
            }
        }
        return $rez;
    }
}
