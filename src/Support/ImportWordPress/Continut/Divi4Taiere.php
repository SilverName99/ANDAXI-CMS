<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

use App\Support\CadruCapturat;
use App\Support\CadruCapturat\Jetoane;
use App\Support\Html5\Editare;
use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;
use App\Support\HtmlCapturat\SchemaDivi4;
use App\Support\ImportWordPress\Captura\Normalizare;
use InvalidArgumentException;

/**
 * Tăierea unei pagini capturate Divi 4 (adoptarea unui site WordPress, pasul 5a; DECIZIE §2.3 #5, #8, #9; C2 §5a), peste T-ul
 * dialectului (Divi4::transforma). În trei treceri, toate pe intervale de octeți, fără reserializare:
 *
 *  1. **conținutul**, prin Html5\Editare (cu verificarea contextului), fiecare editare de un fel din lista închisă FELURI (§6.7 #6):
 *     `sectiune` (`data-ax-s="<n>"` pe fiecare `.et_pb_section` din conținut; numele din eticheta admin Divi stau separat),
 *     `slot` (`data-ax-slot` pe textele, titlurile, butoanele, pozele, video-urile, elementele de meniu, de carusel și de galerie,
 *     câmpurile formularelor) și `grup` (`data-ax-g`: textele egale din conținut, ca seturile desktop/telefon), puse într-o
 *     singură înlocuire a tagului întreg; `capcana` (câmpul-capcană al fiecărui formular Divi, lângă câmpul nonce). Rezultatul
 *     e T, documentul servit;
 *  2. **jetoanele** `{{ax:…}}` (CadruCapturat\Jetoane): fiecare înlocuiește un interval întreg al lui T cu jetonul (și, la
 *     ancore, cu aceiași octeți): scripturile runtime-ului (`runtime-cap` pe tagul lui `ax-date` sau pe `</head>`, `runtime-final`
 *     pe tagul de unde stătea ultimul script scos sau pe `</body>`: exact o ancoră, altfel NU), datele (`date`: JSON-ul lui
 *     `ax-date`, refăcut la randare din partea paginii și a fragmentelor), elementul curent din meniu (`meniu`: clasele și
 *     `aria-current` fiecărui element), numărul formularului din subsol (`formular-index`), calea cererii (`cale`: `action`,
 *     `_wp_http_referer`, iar pe 404 și JSON-LD-ul), anul (`an`, doar cu Divi Dynamic Content) și head-ul SEO (`seo`: toate
 *     locurile valorilor AIOSEO, altfel head-ul rămâne înghețat întreg);
 *  3. **tăierea** în cele cinci bucăți (atributele `<html>`, head-ul până la `</head>` inclusiv, atributele `<body>`, înainte,
 *     conținutul, după până la `</body>` inclusiv: așa ancorele de pe `</head>` și `</body>` stau în bucăți), cu antetul,
 *     subsolul și bannerul ca fragmente (`{{ax:fragment:antet}}`…).
 *
 * **Regiunile dinamice** (pista A a mutării unui site WooCommerce; CadruCapturat\Regiuni, TaiereRegiuni): cu regulile pachetului
 * pentru document (`$ctx['regiuni']`), trecerea 1 nu atinge nimic din interiorul unei regiuni (octeții ei rămân cei capturați), iar
 * trecerea 2 pune jetonul `{{ax:regiune:<id>:<n>}}` în locul fiecărei regiuni (interiorul elementului sau valoarea atributului),
 * `{{ax:clase_body}}` în valoarea lui `class` de pe `<body>` și `{{ax:titlu}}` în interiorul elementului titlului, printr-o
 * editare Html5\Editare (contextul verificat). Octeții capturați ai fiecărei regiuni ies în `regiuni` (îi scrie ScriereCapturata în
 * `regiuni_capturate`, cu starea capturii), iar valorile jetoanelor la import sunt chiar ei: dovada de mai jos îi cuprinde. Fără
 * reguli (toate site-urile de azi) nimic din toate acestea nu se întâmplă.
 *
 * **Dovada:** bucățile, cu fragmentele și valorile de la import, dau exact T (altfel NU). Forma documentului trebuie să fie cea
 * a cadrului (`<!DOCTYPE html>`, `<html…>`, `<head>`, `</head>`, `<body…>`, `</body>`, `</html>`, cu câte un rând între ele).
 */
final class Divi4Taiere
{
    /** Felurile editărilor de conținut (trecerea 1, §6.7 #6). */
    public const FELURI = ['sectiune', 'slot', 'grup', 'capcana'];

    /** Felurile jetoanelor (trecerea 2). */
    public const JETOANE = ['runtime-cap', 'runtime-final', 'date', 'meniu', 'formular-index', 'cale', 'an', 'seo', 'fragment', 'regiune', 'clase_body',
        'titlu'];

    /** Valorile lui `data-ax-slot`. */
    public const SLOTURI = ['text', 'titlu', 'buton', 'imagine', 'video', 'meniu', 'lista', 'camp'];

    /**
     * Felurile documentelor capturate. Pe lângă pagini, 404, carduri embed și arhive, pista A a rezervat (nucleu/0072, coloana
     * `pagini_capturate.tip`): `produs` (pagina capturată a unui produs, `/product/<slug>/`, PlanCapturat::RE_PRODUS; pista G) și
     * șabloanele `sablon_articol`, `sablon_categorie`, `sablon_cautare` (un articol, o categorie, o căutare capturate, randate pentru
     * alte articole și categorii prin regiunile lor; pista B) și `sablon_comanda` (comanda primită, randată pentru orice comandă;
     * pista L), din `privat/import-wordpress.json` → `sabloane` (PlanCapturat::dinCaptura).
     */
    public const TIPURI = ['pagina', '404', 'embed', 'arhiva', 'produs', 'sablon_articol', 'sablon_categorie', 'sablon_cautare', 'sablon_comanda'];

    /** Fragmentele, în ordinea din document. */
    public const FRAGMENTE = ['antet', 'subsol', 'banner'];

    /** Câmpul-capcană (§2.5: invizibil, gol; serverul refuză un mesaj care îl completează). */
    public const CAPCANA = '<input type="text" name="ax_capcana" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="ax-capcana"'
        . ' style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">';

    /** Numele câmpului nonce al formularelor Divi (prefixul; urmează numărul formularului). */
    private const NONCE_FORMULAR = '_wpnonce-et-pb-contact-form-submitted-';

    /** Atributele în care stă numărul unui formular Divi și prefixele lor. */
    private const RE_FORMULAR = '/^(et_pb_contact_form_|et_pb_contact_[A-Za-z0-9_-]{1,80}_|et_pb_contactform_submit_|_wpnonce-et-pb-contact-form-submitted-'
        . '|et_pb_contact_email_fields_|et_pb_contact_email_hidden_fields_)(\d{1,3})$/D';

    /** Santinela nonce-ului în dovadă (caractere din zona privată Unicode, care nu apar în capturi). */
    public const SANTINELA_NONCE = "\u{E000}nonce\u{E000}";

    /**
     * @param array{tip?: string, cale?: string, seo?: ?array<string, string>, sectiuni?: ?list<string>, an?: ?array{modul: string, an: string}, regiuni?: list<array<string, mixed>>, stare?: ?array<string, mixed>} $ctx
     *   `tip` (TIPURI), `cale` (calea capturată: valoarea lui `{{ax:cale}}` la import), `seo` (valorile AIOSEO ale paginii:
     *   titlu, descriere, canonical, robots, imagine), `sectiuni` (etichetele admin ale secțiunilor, din shortcoduri, în ordine),
     *   `an` (modulul subsolului cu Dynamic Content `current_date` și anul capturii), `regiuni` (regulile pachetului care se aplică
     *   documentului, validate: Regiuni::pentruDocument), `stare` (starea pe care o arată captura, pentru dovada regiunilor)
     */
    public static function taie(RezultatDialect $d, array $ctx): Taiere
    {
        $tip = (string) ($ctx['tip'] ?? 'pagina');
        if (!in_array($tip, self::TIPURI, true)) {
            throw new InvalidArgumentException('tip de document necunoscut: ' . $tip);
        }
        if ($d->html === '' || $d->probleme !== []) {
            return Taiere::refuz(['dialectul a refuzat documentul (nimic de tăiat)']);
        }
        $D = $d->html;
        if (preg_match('/\sdata-ax-(?:s|slot|g)(?=[\s=\/>])/i', $D, $m, PREG_OFFSET_CAPTURE) === 1) {
            return Taiere::refuz(['captura are deja un atribut ' . trim($m[0][0], " \t\n\r=") . ' (al importului nostru), la octetul ' . $m[0][1]]);
        }
        // ── Trecerea 1: conținutul (Editare) ──────────────────────────────────────────────────────────────────────────
        $an = Tokenizer::analizeaza($D);
        if ($an['nesigure'] !== []) {
            return Taiere::refuz(['document nesigur: ' . $an['nesigure'][0]]);
        }
        $tk = $an['tokeni'];
        $s = self::structura($D, $tk, $tip);
        if ($s['probleme'] !== []) {
            return Taiere::refuz($s['probleme']);
        }
        // Regiunile dinamice (pista A): locurile lor în documentul dialectului; nimic din interiorul unei regiuni nu se editează aici.
        $reguliRegiuni = array_values((array) ($ctx['regiuni'] ?? []));
        $locuriD = [];
        if ($reguliRegiuni !== [] && ($pr = \App\Support\CadruCapturat\Regiuni::valideazaReguli($reguliRegiuni)) !== []) {
            return Taiere::refuz(array_map(static fn (string $x): string => 'import-wordpress.json → ' . $x, $pr));
        }
        if ($reguliRegiuni !== []) {
            $lr = TaiereRegiuni::locuri($D, $tk, $reguliRegiuni, array_values(array_filter([$s['antet'], $s['subsol'], $s['banner']])), (int) $s['body']);
            if ($lr['probleme'] !== []) {
                return Taiere::refuz($lr['probleme']);
            }
            $locuriD = $lr['locuri'];
        }
        $inRegiune = static function (Token $t) use ($locuriD): bool {
            foreach ($locuriD as $l) {
                if ($l['mod'] === 'interior' && $t->start >= $l['start'] && $t->end <= $l['end']) {
                    return true;
                }
            }
            return false;
        };
        $atribute = []; // index tag → [nume => valoare]
        $ops = []; // [start, end, text, fel]
        $probleme = [];
        $avertismente = [];
        $zone = array_values(array_filter([$s['continut'], $s['antet'], $s['subsol'], $s['banner']]));
        $inZona = static function (Token $t, ?array $z): bool {
            return $z !== null && $t->start >= $z[0] && $t->end <= $z[1];
        };
        $inOrice = static function (Token $t) use ($zone, $inZona): bool {
            foreach ($zone as $z) {
                if ($inZona($t, $z)) {
                    return true;
                }
            }
            return false;
        };
        $nrSectiune = 0;
        $texte = [];
        foreach ($tk as $k => $t) {
            if ($t->tip !== Token::DESCHIDERE || $t->ignorat || $t->spatiu !== 'html' || !$inOrice($t) || ($locuriD !== [] && $inRegiune($t))) {
                continue;
            }
            $cl = $t->clase($D);
            if ($t->nume === 'div' && in_array('et_pb_section', $cl, true) && $inZona($t, $s['continut'])) {
                $atribute[$k]['data-ax-s'] = (string) $nrSectiune++;
            }
            $slot = self::slot($t, $cl);
            if ($slot !== null) {
                $atribute[$k]['data-ax-slot'] = $slot;
                if (in_array($slot, ['text', 'titlu', 'buton'], true) && $inZona($t, $s['continut'])) {
                    $text = self::textNormalizat($D, $tk, $k);
                    if ($text !== '') {
                        $texte[$text][] = $k;
                    }
                }
            }
            if ($t->nume === 'form' && in_array('et_pb_contact_form', $cl, true)) {
                $capcana = self::capcana($D, $tk, $k);
                if (is_string($capcana)) {
                    $probleme[] = $capcana;
                } elseif ($capcana !== null) {
                    $ops[] = $capcana;
                }
            }
        }
        $g = 0;
        foreach ($texte as $lista) {
            if (count($lista) < 2) {
                continue;
            }
            $g++;
            foreach ($lista as $k) {
                $atribute[$k]['data-ax-g'] = (string) $g;
            }
        }
        foreach ($atribute as $k => $atr) {
            $t = $tk[$k];
            $poz = self::sfarsitAtribute($D, $t);
            $adaos = '';
            foreach ($atr as $n => $v) {
                $adaos .= ' ' . $n . '="' . $v . '"';
            }
            $fel = isset($atr['data-ax-s']) ? 'sectiune' : (isset($atr['data-ax-g']) ? 'slot+grup' : 'slot');
            $ops[] = [$t->start, $t->end, substr($D, $t->start, $poz - $t->start) . $adaos . substr($D, $poz, $t->end - $poz), $fel];
        }
        if ($probleme !== []) {
            return Taiere::refuz($probleme);
        }
        usort($ops, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $ed = new Editare($D);
        $editari = [];
        try {
            foreach ($ops as [$a, $b, $text]) {
                $ed->inlocuieste($a, $b, $text);
            }
            $T = $ed->aplica();
        } catch (InvalidArgumentException $e) {
            return Taiere::refuz(['editarea conținutului (pasul 5a) a fost refuzată: ' . $e->getMessage()]);
        }
        $delte = [];
        foreach ($ops as [$a, $b, $text, $fel]) {
            $editari[] = ['fel' => $fel, 'start' => $a, 'end' => $b, 'text' => $text];
            $delte[] = [$b, strlen($text) - ($b - $a)];
        }
        $mapeaza = static function (int $off) use ($delte): int {
            $n = $off;
            foreach ($delte as [$b, $delta]) {
                if ($b <= $off) {
                    $n += $delta;
                }
            }
            return $n;
        };

        // ── Trecerea 2: jetoanele, pe T ─────────────────────────────────────────────────────────────────────────────
        $an2 = Tokenizer::analizeaza($T);
        if ($an2['nesigure'] !== []) {
            return Taiere::refuz(['documentul cu editările pasului 5a e nesigur: ' . $an2['nesigure'][0]], $T);
        }
        $tk2 = $an2['tokeni'];
        $s2 = self::structura($T, $tk2, $tip);
        if ($s2['probleme'] !== []) {
            return Taiere::refuz($s2['probleme'], $T);
        }
        $j = []; // [start, end, text, fel]
        $valori = ['nonce' => self::SANTINELA_NONCE];
        // Scripturile runtime-ului: exact o ancoră fiecare, tokenul de la locul lor (unde stătea primul script scos din head,
        // ultimul din corp). Între două scripturi scoase spațiile s-au unit într-un singur text: atunci ancora e acel text, doar
        // din spații (jetonul intră înaintea lui). Fără scripturi scoase, ancora e </head>, respectiv </body>.
        $ancore = [];
        foreach (['cap' => $s2['headEnd'], 'final' => $s2['bodyEnd']] as $loc => $rezerva) {
            $slot = $d->sloturi[$loc] ?? null;
            $k = $rezerva;
            if ($slot !== null) {
                $poz = $mapeaza((int) $slot);
                $k = null;
                foreach ($tk2 as $i => $t) {
                    if ($t->start === $poz || ($t->start < $poz && $poz < $t->end && $t->tip === Token::TEXT && $t->brut === ''
                        && trim($t->octeti($T), "\t\n\f\r ") === '')) {
                        $k = $i;
                        break;
                    }
                    if ($t->start > $poz) {
                        break;
                    }
                }
            }
            if ($k === null) {
                return Taiere::refuz(['locul scripturilor runtime-ului (' . $loc . ') nu cade la începutul unui token sau într-un text de spații (ancora lipsește)'], $T);
            }
            $t = $tk2[$k];
            foreach (self::FRAGMENTE as $f) {
                if ($s2[$f] !== null && $t->start >= $s2[$f][0] && $t->start < $s2[$f][1]) {
                    return Taiere::refuz(['ancora scripturilor runtime-ului (' . $loc . ') e în fragmentul „' . $f . '”'], $T);
                }
            }
            $j[] = [$t->start, $t->end, Jetoane::jeton('runtime:' . $loc) . $t->octeti($T), 'runtime-' . $loc];
            $valori['runtime:' . $loc] = '';
            $ancore[$loc] = $t->start;
        }
        // Datele comportamentului (ax-date), refăcute la randare din partea paginii și a fragmentelor.
        $date = null;
        $dateFragment = ['antet' => [], 'subsol' => []];
        foreach ($tk2 as $k => $t) {
            if ($t->tip === Token::DESCHIDERE && $t->nume === 'script' && strtolower((string) $t->valoare($T, 'id')) === SchemaDivi4::AX_DATE_ID) {
                $text = $tk2[$k + 1] ?? null;
                if ($text === null || $text->tip !== Token::TEXT || $text->brut !== 'script') {
                    break;
                }
                $parti = self::imparte($d->date);
                if (CadruCapturat::json(CadruCapturat::asambleaza($parti['pagina'], $parti['antet'], $parti['subsol'], $parti['reteta'])) === $text->octeti($T)) {
                    $j[] = [$text->start, $text->end, Jetoane::jeton('date'), 'date'];
                    $valori['date'] = str_replace(Normalizare::LOC_NONCE, self::SANTINELA_NONCE, $text->octeti($T));
                    $date = ['pagina' => $parti['pagina'], 'reteta' => $parti['reteta']];
                    $dateFragment = ['antet' => $parti['antet'], 'subsol' => $parti['subsol']];
                } else {
                    $avertismente[] = 'datele comportamentului rămân în head cum sunt: rețeta lor pe proprietari nu le reface exact';
                }
                break;
            }
        }
        // Elementul curent din meniu: clasele și aria-current ale fiecărui element, în antet și în subsol.
        $meniu = [];
        foreach (['antet', 'subsol'] as $f) {
            if ($s2[$f] === null) {
                continue;
            }
            $nr = 0;
            foreach ($tk2 as $k => $t) {
                if ($t->tip !== Token::DESCHIDERE || $t->nume !== 'li' || $t->start < $s2[$f][0] || $t->end > $s2[$f][1] || !in_array('menu-item', $t->clase($T), true)) {
                    continue;
                }
                $a = $t->atribut('class');
                $nume = 'meniu:' . $f . ':' . $nr;
                $j[] = [$a->valoareStart, $a->valoareEnd, Jetoane::jeton($nume . ':clase'), 'meniu'];
                $valori[$nume . ':clase'] = $a->valoare($T);
                $meniu[$f][$nr] = [$a->valoare($T), ''];
                // Primul <a> al elementului: aria-current="page" pe elementul curent.
                for ($i = $k + 1; $i < count($tk2) && $tk2[$i]->start < $s2[$f][1]; $i++) {
                    $x = $tk2[$i];
                    if ($x->tip === Token::DESCHIDERE && $x->nume === 'li') {
                        break;
                    }
                    if ($x->tip !== Token::DESCHIDERE || $x->nume !== 'a' || $x->atribute === []) {
                        continue;
                    }
                    $aria = $x->atribut('aria-current');
                    if ($aria !== null) {
                        $inainte = $x->numeEnd;
                        foreach ($x->atribute as $alt) {
                            if ($alt->end <= $aria->start) {
                                $inainte = max($inainte, $alt->end);
                            }
                        }
                        $j[] = [$inainte, $aria->end, Jetoane::jeton($nume . ':aria'), 'meniu'];
                        $valori[$nume . ':aria'] = substr($T, $inainte, $aria->end - $inainte);
                        $meniu[$f][$nr][1] = $valori[$nume . ':aria'];
                    } else {
                        $ultim = $x->atribute[count($x->atribute) - 1];
                        $j[] = [$ultim->start, $ultim->end, substr($T, $ultim->start, $ultim->end - $ultim->start) . Jetoane::jeton($nume . ':aria'), 'meniu'];
                        $valori[$nume . ':aria'] = '';
                    }
                    break;
                }
                $nr++;
            }
        }
        // Numărul formularului din subsol (`_0` peste tot, `_1` pe o pagină cu un formular în conținut).
        $formulare = self::formulare($T, $tk2, $s2);
        foreach ($formulare as $fo) {
            if ($fo['loc'] !== 'subsol') {
                continue;
            }
            $inContinut = count(array_filter($formulare, static fn (array $x): bool => $x['loc'] === 'continut'));
            if ($fo['indice'] !== $inContinut) {
                $avertismente[] = 'formularul din subsol are numărul ' . $fo['indice'] . ', dar conținutul are ' . $inContinut . ' formulare: numărul rămâne cel capturat';
                continue;
            }
            foreach ($tk2 as $t) {
                if ($t->tip !== Token::DESCHIDERE || $t->start < $fo['start'] || $t->end > $fo['end']) {
                    continue;
                }
                foreach ($t->atribute as $a) {
                    if ($a->duplicat || !$a->areValoare() || !in_array($a->nume, ['id', 'name', 'for', 'data-form_unique_num'], true)) {
                        continue;
                    }
                    $v = $a->valoare($T);
                    if ($a->nume === 'data-form_unique_num' ? $v === (string) $fo['indice']
                        : (preg_match(self::RE_FORMULAR, $v, $m) === 1 && $m[2] === (string) $fo['indice'])) {
                        $pref = $a->nume === 'data-form_unique_num' ? 0 : strlen($m[1]);
                        $j[] = [$a->valoareStart + $pref, $a->valoareEnd, Jetoane::jeton('formular_index'), 'formular-index'];
                    }
                }
            }
            $valori['formular_index'] = (string) $fo['indice'];
        }
        // Calea cererii: action-ul și _wp_http_referer ale formularelor; pe 404, și în JSON-LD.
        $zone2 = array_values(array_filter([$s2['continut'], $s2['antet'], $s2['subsol'], $s2['banner']]));
        $inOriceT = static function (Token $t) use ($zone2): bool {
            foreach ($zone2 as $z) {
                if ($t->start >= $z[0] && $t->end <= $z[1]) {
                    return true;
                }
            }
            return false;
        };
        $cale = (string) ($ctx['cale'] ?? '');
        if ($cale !== '') {
            $caleAttr = Jetoane::scapa($cale, 'attr');
            foreach ($tk2 as $k => $t) {
                if ($t->tip !== Token::DESCHIDERE || !$inOriceT($t)) {
                    continue;
                }
                $a = null;
                if ($t->nume === 'form') {
                    $a = $t->atribut('action');
                } elseif ($t->nume === 'input' && $t->valoare($T, 'name') === '_wp_http_referer') {
                    $a = $t->atribut('value');
                }
                if ($a !== null && $a->areValoare() && $a->valoare($T) === $caleAttr) {
                    $j[] = [$a->valoareStart, $a->valoareEnd, Jetoane::jeton('cale', 'attr'), 'cale'];
                    $valori['cale'] = $cale;
                }
            }
            if ($tip === '404') {
                $caleJson = Jetoane::scapa($cale, 'json');
                foreach ($tk2 as $k => $t) {
                    if ($t->tip === Token::TEXT && $t->brut === 'script' && $t->end <= $s2['headEndPoz'] && in_array('aioseo-schema', $tk2[$k - 1]->clase($T), true)) {
                        $o = 0;
                        $text = $t->octeti($T);
                        while (($p = strpos($text, $caleJson, $o)) !== false) {
                            $j[] = [$t->start + $p, $t->start + $p + strlen($caleJson), Jetoane::jeton('cale', 'json'), 'cale'];
                            $valori['cale'] = $cale;
                            $o = $p + strlen($caleJson);
                        }
                    }
                }
            }
        }
        // Anul (Divi Dynamic Content `current_date` în subsol).
        $anCtx = $ctx['an'] ?? null;
        if (is_array($anCtx) && $s2['subsol'] !== null) {
            $gasit = false;
            foreach ($tk2 as $k => $t) {
                if ($t->tip !== Token::DESCHIDERE || $t->start < $s2['subsol'][0] || $t->end > $s2['subsol'][1] || !in_array($anCtx['modul'], $t->clase($T), true)) {
                    continue;
                }
                $p = Tokenizer::pereche($tk2, $k);
                for ($i = $k + 1; $p !== null && $i < $p; $i++) {
                    $x = $tk2[$i];
                    if ($x->tip !== Token::TEXT || $x->brut !== '') {
                        continue;
                    }
                    if (preg_match_all('/(?<![0-9])' . preg_quote((string) $anCtx['an'], '/') . '(?![0-9])/', $x->octeti($T), $mm, PREG_OFFSET_CAPTURE) > 0) {
                        foreach ($mm[0] as [$txt, $off]) {
                            $j[] = [$x->start + $off, $x->start + $off + strlen($txt), Jetoane::jeton('an'), 'an'];
                            $gasit = true;
                        }
                    }
                }
                break;
            }
            if ($gasit) {
                $valori['an'] = (string) $anCtx['an'];
            } else {
                $avertismente[] = 'anul din subsol (Dynamic Content) nu s-a găsit în modulul ' . $anCtx['modul'] . ': rămâne cel capturat';
            }
        }
        // Head-ul SEO pe jetoane (toate locurile valorilor), altfel înghețat întreg.
        [$jSeo, $seo, $seoMotiv, $valoriSeo] = self::seo($T, $tk2, $s2, $ctx['seo'] ?? null, $tip);
        if ($seo === 'jetoane') {
            array_push($j, ...$jSeo);
            $valori += $valoriSeo;
        }
        // Regiunile dinamice (pista A): locurile lor pe T (aceleași ca pe documentul dialectului), jetoanele lor printr-o editare
        // Html5\Editare (contextul arborelui verificat) și octeții capturați ca valori la import. Calea din formularele unei regiuni
        // (action, _wp_http_referer) o dă randatorul ei, deci nu mai e un jeton al documentului; orice alt jeton într-o regiune e NU
        // (suprapunerea, mai jos).
        $regiuniTaiere = [];
        if ($reguliRegiuni !== []) {
            $lr2 = TaiereRegiuni::locuri($T, $tk2, $reguliRegiuni, array_values(array_filter([$s2['antet'], $s2['subsol'], $s2['banner']])), (int) $s2['body']);
            if ($lr2['probleme'] !== [] || array_column($lr2['locuri'], 'jeton') !== array_column($locuriD, 'jeton')) {
                return Taiere::refuz($lr2['probleme'] !== [] ? $lr2['probleme'] : ['regiuni: locurile de pe T nu sunt cele din documentul dialectului'], $T);
            }
            // Fiecare regiune stă într-o bucată a documentului (capul sau corpul: inainte, continut, dupa); în afara lor (atributele lui
            // <html> și <body>, care se copiază întocmai) jetonul n-ar ajunge în pagină și regiunea ar rămâne înghețată (SPEC-A-08).
            foreach ($lr2['locuri'] as $l) {
                $inCap = $l['start'] >= $s2['headStart'] && $l['end'] <= $s2['capEnd'];
                $inCorp = $l['start'] >= $s2['bodyStart'] && $l['end'] <= $s2['corpEnd'];
                if ($l['fel'] === 'regiune' && !$inCap && !$inCorp) {
                    return Taiere::refuz(['regiuni: regiunea „' . $l['regiune'] . '” de la octetul ' . $l['start'] . ' nu e în nicio bucată a documentului (capul sau corpul)'], $T);
                }
            }
            $jr = TaiereRegiuni::jetoane($T, $tk2, $lr2['locuri']);
            if (is_string($jr)) {
                return Taiere::refuz([$jr], $T);
            }
            $interioare = array_values(array_filter($lr2['locuri'], static fn (array $l): bool => $l['fel'] === 'regiune' && $l['mod'] === 'interior'));
            $j = array_values(array_filter($j, static function (array $x) use ($interioare): bool {
                foreach ($interioare as $l) {
                    if ($x[3] === 'cale' && $x[0] >= $l['start'] && $x[1] <= $l['end']) {
                        return false;
                    }
                }
                return true;
            }));
            array_push($j, ...$jr);
            foreach ($lr2['locuri'] as $l) {
                $octeti = substr($T, $l['start'], $l['end'] - $l['start']);
                $valori[$l['jeton']] = $octeti;
                if ($l['fel'] === 'regiune') {
                    $regiuniTaiere[] = ['regiune' => (string) $l['regiune'], 'n' => (int) $l['n'], 'mod' => (string) $l['mod'], 'atribut' => $l['atribut'],
                        'octeti' => $octeti, 'stare' => is_array($ctx['stare'] ?? null) ? $ctx['stare'] : null, 'start' => (int) $l['start']];
                }
            }
        }
        // Intervalele jetoanelor nu se suprapun și nu trec peste marginile bucăților sau ale fragmentelor.
        usort($j, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $margini = [$s2['headStart'], $s2['capEnd'], $s2['bodyStart'], $s2['corpEnd']];
        if ($s2['continut'] !== null) {
            array_push($margini, $s2['continut'][0], $s2['continut'][1]);
        }
        foreach (self::FRAGMENTE as $f) {
            if ($s2[$f] !== null) {
                array_push($margini, $s2[$f][0], $s2[$f][1]);
            }
        }
        $ultim = -1;
        foreach ($j as [$a, $b, , $fel]) {
            if ($a < $ultim) {
                return Taiere::refuz(['două jetoane se suprapun la octetul ' . $a . ' (' . $fel . ')'], $T);
            }
            foreach ($margini as $m) {
                if ($a < $m && $m < $b) {
                    return Taiere::refuz(['jetonul „' . $fel . '” de la octetul ' . $a . ' trece peste marginea unei bucăți'], $T);
                }
            }
            $ultim = $b;
        }

        // ── Trecerea 3: bucățile și fragmentele ───────────────────────────────────────────────────────────────────
        $fragmente = [];
        foreach (self::FRAGMENTE as $f) {
            if ($s2[$f] !== null) {
                $fragmente[$f] = ['fel' => $f, 'layout' => $s2['layout'][$f] ?? 'x', 'html' => self::bucata($T, $j, $s2[$f][0], $s2[$f][1], []),
                    'date' => $dateFragment[$f] ?? []];
            }
        }
        $fz = [];
        foreach (self::FRAGMENTE as $f) {
            if ($s2[$f] !== null) {
                $fz[$f] = $s2[$f];
            }
        }
        $tHtml = $tk2[$s2['html']];
        $tBody = $tk2[$s2['body']];
        $htmlAtribute = substr($T, $tHtml->numeEnd, $tHtml->end - 1 - $tHtml->numeEnd);
        // Atributele lui <body>, cu jetonul `{{ax:clase_body}}` dacă regulile îl cer (fără el, octeții lor întocmai).
        $bodyAtribute = self::bucata($T, $j, $tBody->numeEnd, $tBody->end - 1, []);
        $cap = self::bucata($T, $j, $s2['headStart'], $s2['capEnd'], $fz);
        if ($s2['continut'] !== null) {
            $inainte = self::bucata($T, $j, $s2['bodyStart'], $s2['continut'][0], $fz);
            $continut = self::bucata($T, $j, $s2['continut'][0], $s2['continut'][1], $fz);
            $dupa = self::bucata($T, $j, $s2['continut'][1], $s2['corpEnd'], $fz);
        } else {
            $inainte = self::bucata($T, $j, $s2['bodyStart'], $s2['corpEnd'], $fz);
            $continut = '';
            $dupa = '';
        }
        // Dovada: bucățile, cu fragmentele și valorile de la import, dau exact T.
        $taiere = new Taiere($T, $editari, $ancore, $htmlAtribute, $cap, $bodyAtribute, $inainte, $continut, $dupa, $fragmente, $valori, $meniu, $date,
            $seo, $seoMotiv, self::sectiuni($ctx['sectiuni'] ?? null, $nrSectiune, $avertismente), $formulare, self::galerii($T, $tk2, $s2), [], $avertismente,
            $regiuniTaiere);
        try {
            $refacut = self::refa($taiere);
        } catch (\Throwable $e) {
            return Taiere::refuz(['dovada tăierii: ' . $e->getMessage()], $T);
        }
        if ($refacut !== $T) {
            $p = 0;
            $n = min(strlen($refacut), strlen($T));
            while ($p < $n && $refacut[$p] === $T[$p]) {
                $p++;
            }
            return Taiere::refuz(['dovada tăierii: bucățile nu refac documentul, prima diferență la octetul ' . $p], $T);
        }
        return $taiere;
    }

    /**
     * Documentul refăcut din bucăți, cu fragmentele și valorile de la import (dovada; nonce-ul trece prin santinelă). Același
     * algoritm ca randarea (CadruCapturat): fragmentele întâi, apoi toate jetoanele într-o singură trecere.
     */
    public static function refa(Taiere $t): string
    {
        $doc = CadruCapturat::asambleazaBucati($t->htmlAtribute, $t->cap, $t->bodyAtribute, $t->inainte, $t->continut, $t->dupa);
        $doc = CadruCapturat::puneFragmente($doc, array_map(static fn (array $f): string => $f['html'], $t->fragmente));
        $valori = $t->valori;
        return str_replace(self::SANTINELA_NONCE, Normalizare::LOC_NONCE, Jetoane::rezolva($doc, static fn (string $n): ?string => $valori[$n] ?? null));
    }

    /**
     * Împărțirea datelor pe proprietar (ca Divi4::proprietari), cu rețeta ordinii lor: pentru fiecare configurație, proprietarul
     * fiecărei chei (obiecte) sau al fiecărui element (liste), în ordinea din pagină.
     *
     * @param array<string, mixed> $date
     * @return array{pagina: array<string, mixed>, antet: array<string, mixed>, subsol: array<string, mixed>, reteta: array<string, mixed>}
     */
    public static function imparte(array $date): array
    {
        $parti = Divi4::proprietari($date);
        $al = static fn (string $cheie): string => str_contains($cheie, '_tb_header') ? 'antet' : (str_contains($cheie, '_tb_footer') ? 'subsol' : 'pagina');
        $reteta = [];
        foreach ($date as $nume => $val) {
            if ($val instanceof \stdClass && get_object_vars($val) !== []) {
                $reteta[$nume] = ['obiect', array_map(static fn (string $k): array => [$al($k), $k], array_map('strval', array_keys(get_object_vars($val))))];
            } elseif (is_array($val) && $val !== [] && array_is_list($val)) {
                $reteta[$nume] = ['lista', array_map(static fn (mixed $v): string => $al($v instanceof \stdClass && isset($v->class) && is_string($v->class) ? $v->class : ''), $val)];
            } else {
                $reteta[$nume] = ['valoare'];
            }
        }
        return ['pagina' => $parti['pagina'], 'antet' => $parti['antet'], 'subsol' => $parti['subsol'], 'reteta' => $reteta];
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Structura documentului: tagurile cadrului, zona conținutului și cele ale fragmentelor (`[start, end)`), plus problemele
     * (forma cadrului, conținutul negăsit, fragmente duble).
     *
     * @param list<Token> $tk
     * @return array<string, mixed>
     */
    private static function structura(string $h, array $tk, string $tip): array
    {
        $rez = ['probleme' => [], 'continut' => null, 'antet' => null, 'subsol' => null, 'banner' => null, 'layout' => []];
        $gaseste = static function (string $tipT, string $nume, bool $ultimul = false) use ($tk): ?int {
            $g = null;
            foreach ($tk as $k => $t) {
                // Închiderile lui </body> și </html> nu scot elemente de pe stivă (le „ignoră” constructorul): contează tagul.
                if ($t->tip === $tipT && $t->nume === $nume && ($tipT === Token::INCHIDERE || !$t->ignorat)) {
                    $g = $k;
                    if (!$ultimul) {
                        break;
                    }
                }
            }
            return $g;
        };
        $iHtml = $gaseste(Token::DESCHIDERE, 'html');
        $iHead = $gaseste(Token::DESCHIDERE, 'head');
        $iHeadEnd = $gaseste(Token::INCHIDERE, 'head');
        $iBody = $gaseste(Token::DESCHIDERE, 'body');
        $iBodyEnd = $gaseste(Token::INCHIDERE, 'body', true);
        $iHtmlEnd = $gaseste(Token::INCHIDERE, 'html', true);
        if (in_array(null, [$iHtml, $iHead, $iHeadEnd, $iBody, $iBodyEnd, $iHtmlEnd], true)) {
            $rez['probleme'][] = 'documentul nu are toate tagurile cadrului (html, head, body, cu închiderile lor)';
            return $rez;
        }
        $o = static fn (int $k): string => $tk[$k]->octeti($h);
        $intre = static fn (int $a, int $b): string => substr($h, $tk[$a]->end, $tk[$b]->start - $tk[$a]->end);
        $forma = substr($h, 0, $tk[$iHtml]->start) === CadruCapturat::DOCTYPE && $intre($iHtml, $iHead) === "\n" && $o($iHead) === '<head>'
            && $o($iHeadEnd) === '</head>' && $intre($iHeadEnd, $iBody) === "\n" && $o($iBodyEnd) === '</body>' && $intre($iBodyEnd, $iHtmlEnd) === "\n"
            && $o($iHtmlEnd) === '</html>' && substr($h, $tk[$iHtmlEnd]->end) === "\n" && !$tk[$iHtml]->autoInchis && !$tk[$iBody]->autoInchis;
        if (!$forma) {
            $rez['probleme'][] = 'documentul nu are forma cadrului (<!DOCTYPE html>, <html…>, <head>, </head>, <body…>, </body>, </html>, cu câte un rând între ele)';
            return $rez;
        }
        $rez += ['html' => $iHtml, 'body' => $iBody, 'headEnd' => $iHeadEnd, 'bodyEnd' => $iBodyEnd, 'headStart' => $tk[$iHead]->end,
            'headEndPoz' => $tk[$iHeadEnd]->start, 'capEnd' => $tk[$iHeadEnd]->end, 'bodyStart' => $tk[$iBody]->end, 'bodyEndPoz' => $tk[$iBodyEnd]->start,
            'corpEnd' => $tk[$iBodyEnd]->end];
        // Zonele, după clasele Divi (doar în corp).
        $interior = static function (int $k) use ($tk): ?array {
            $p = Tokenizer::pereche($tk, $k);
            return $p === null ? null : [$tk[$k]->end, $tk[$p]->start];
        };
        $element = static function (int $k) use ($tk): ?array {
            $p = Tokenizer::pereche($tk, $k);
            return $p === null ? null : [$tk[$k]->start, $tk[$p]->end];
        };
        $cauta = static function (callable $potrivire, ?array $in = null) use ($tk, $h, $iBody): array {
            $rez = [];
            foreach ($tk as $k => $t) {
                if ($k <= $iBody || $t->tip !== Token::DESCHIDERE || $t->ignorat || $t->spatiu !== 'html') {
                    continue;
                }
                if ($in !== null && ($t->start < $in[0] || $t->end > $in[1])) {
                    continue;
                }
                if ($potrivire($t, $t->clase($h))) {
                    $rez[] = $k;
                }
            }
            return $rez;
        };
        $prima = static fn (array $l): ?int => $l[0] ?? null;
        if (in_array($tip, ['pagina', 'arhiva', '404', 'produs', 'sablon_articol', 'sablon_categorie', 'sablon_cautare', 'sablon_comanda'], true)) {
            $invelis = $prima($cauta(static fn (Token $t, array $c): bool => $t->nume === 'div' && in_array($tip === '404' ? 'et-l--body' : 'et-l--post', $c, true)));
            $zona = null;
            if ($invelis !== null && ($el = $element($invelis)) !== null) {
                $inner = $prima($cauta(static fn (Token $t, array $c): bool => $t->nume === 'div' && in_array('et_builder_inner_content', $c, true), $el));
                $zona = $inner !== null ? $interior($inner) : null;
            }
            if ($zona === null && $tip !== '404') {
                $entry = $prima($cauta(static fn (Token $t, array $c): bool => $t->nume === 'div' && in_array('entry-content', $c, true)));
                $zona = $entry !== null ? $interior($entry) : null;
            }
            if ($zona === null && in_array($tip, ['pagina', '404'], true)) {
                $rez['probleme'][] = 'conținutul ' . ($tip === '404' ? 'șablonului 404 (.et-l--body)' : 'paginii (.et-l--post sau .entry-content)') . ' nu se găsește sau nu se închide';
            }
            $rez['continut'] = $zona;
        }
        $fragmente = [
            'antet' => static fn (Token $t, array $c): bool => $t->nume === 'header' && in_array('et-l--header', $c, true),
            'subsol' => static fn (Token $t, array $c): bool => $t->nume === 'footer' && in_array('et-l--footer', $c, true),
            'banner' => static fn (Token $t, array $c): bool => $t->nume === 'div' && $t->valoare($h, 'id') === 'cmplz-cookiebanner-container',
        ];
        foreach ($fragmente as $f => $pot) {
            $l = $cauta($pot);
            if (count($l) > 1) {
                $rez['probleme'][] = 'documentul are ' . count($l) . ' elemente „' . $f . '”: fragmentul nu e unul singur';
                continue;
            }
            if ($l === []) {
                continue;
            }
            $el = $element($l[0]);
            if ($el === null) {
                $rez['probleme'][] = 'fragmentul „' . $f . '” nu se închide cu tagul lui';
                continue;
            }
            if ($rez['continut'] !== null && !($el[1] <= $rez['continut'][0] || $el[0] >= $rez['continut'][1])) {
                $rez['probleme'][] = 'fragmentul „' . $f . '” e în conținutul paginii';
                continue;
            }
            $rez[$f] = $el;
        }
        // Layout-urile Theme Builder (doar pentru numele fragmentelor): din id-ul foii lor din head.
        if (preg_match('/et-core-unified-tb-(\d+)(?:-tb-(\d+))?(?:-tb-(\d+))?-/', substr($h, 0, $rez['headEndPoz']), $m) === 1) {
            $ids = array_values(array_filter(array_slice($m, 1), static fn (string $x): bool => $x !== ''));
            $clase = (string) $tk[$iBody]->valoare($h, 'class');
            $nume = array_keys(array_filter(['antet' => str_contains($clase, 'et-tb-has-header'), 'corp' => str_contains($clase, 'et-tb-has-body'),
                'subsol' => str_contains($clase, 'et-tb-has-footer')]));
            if (count($nume) === count($ids)) {
                $rez['layout'] = array_combine($nume, $ids);
            }
        }
        return $rez;
    }

    /** Felul slotului unui tag (§2.3 #8), sau null. @param list<string> $cl */
    private static function slot(Token $t, array $cl): ?string
    {
        return match (true) {
            $t->nume === 'div' && (in_array('et_pb_text_inner', $cl, true) || in_array('et_pb_toggle_content', $cl, true)) => 'text',
            in_array($t->nume, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) && (in_array('et_pb_module_header', $cl, true) || in_array('et_pb_toggle_title', $cl, true)) => 'titlu',
            $t->nume === 'a' && in_array('et_pb_button', $cl, true) => 'buton',
            $t->nume === 'img' => 'imagine',
            $t->nume === 'video' => 'video',
            $t->nume === 'li' && in_array('menu-item', $cl, true) => 'meniu',
            $t->nume === 'div' && (in_array('dica_divi_carouselitem', $cl, true) || in_array('el_masonry_gallery_item', $cl, true)) => 'lista',
            $t->nume === 'p' && in_array('et_pb_contact_field', $cl, true) => 'camp',
            default => null,
        };
    }

    /** Textul arătat al unui element (fără taguri, cu entitățile decodate, spațiile strânse), pentru grupuri. @param list<Token> $tk */
    private static function textNormalizat(string $h, array $tk, int $k): string
    {
        $p = Tokenizer::pereche($tk, $k);
        if ($p === null) {
            return '';
        }
        $text = '';
        for ($i = $k + 1; $i < $p; $i++) {
            if ($tk[$i]->tip === Token::TEXT && $tk[$i]->brut === '') {
                $text .= $tk[$i]->octeti($h);
            } elseif ($tk[$i]->esteTag()) {
                $text .= ' ';
            }
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * Câmpul-capcană al unui formular Divi: înlocuirea câmpului nonce (sau, fără el, a lui `</form>`) cu aceiași octeți plus
     * câmpul; null dacă formularul nu se închide; un text (problema) dacă formularul are deja un câmp cu numele capcanei.
     *
     * @param list<Token> $tk
     * @return array{0: int, 1: int, 2: string, 3: string}|string|null
     */
    private static function capcana(string $h, array $tk, int $k): array|string|null
    {
        $p = Tokenizer::pereche($tk, $k);
        if ($p === null) {
            return null;
        }
        $nonce = null;
        for ($i = $k + 1; $i < $p; $i++) {
            $t = $tk[$i];
            if ($t->tip !== Token::DESCHIDERE || $t->nume !== 'input') {
                continue;
            }
            $nume = (string) $t->valoare($h, 'name');
            if ($nume === 'ax_capcana') {
                return 'formularul de la octetul ' . $tk[$k]->start . ' are deja un câmp „ax_capcana”';
            }
            if ($nonce === null && str_starts_with($nume, self::NONCE_FORMULAR)) {
                $nonce = $t;
            }
        }
        if ($nonce !== null) {
            return [$nonce->start, $nonce->end, $nonce->octeti($h) . self::CAPCANA, 'capcana'];
        }
        return [$tk[$p]->start, $tk[$p]->end, self::CAPCANA . $tk[$p]->octeti($h), 'capcana'];
    }

    /** Poziția de după ultimul atribut al unui tag (înaintea spațiilor, a lui `/` și a lui `>`). */
    private static function sfarsitAtribute(string $h, Token $t): int
    {
        $poz = $t->end - 1;
        if ($t->autoInchis && substr($h, $poz - 1, 1) === '/') {
            $poz--;
        }
        while ($poz > $t->numeEnd && strpbrk($h[$poz - 1], "\t\n\f\r ") !== false) {
            $poz--;
        }
        return $poz;
    }

    /**
     * O bucată a lui T, `[a, b)`, cu jetoanele din ea puse și cu fragmentele din ea înlocuite de `{{ax:fragment:<fel>}}`.
     *
     * @param list<array{0: int, 1: int, 2: string, 3: string}> $j
     * @param array<string, array{0: int, 1: int}> $fragmente
     */
    private static function bucata(string $T, array $j, int $a, int $b, array $fragmente): string
    {
        $rez = '';
        $poz = $a;
        $evenimente = [];
        foreach ($j as $x) {
            if ($x[0] >= $a && $x[1] <= $b) {
                $evenimente[] = [$x[0], $x[1], $x[2]];
            }
        }
        foreach ($fragmente as $f => [$fa, $fb]) {
            if ($fa >= $a && $fb <= $b) {
                $evenimente[] = [$fa, $fb, '{{ax:fragment:' . $f . '}}'];
            }
        }
        usort($evenimente, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        foreach ($evenimente as [$s, $e, $text]) {
            if ($s < $poz) {
                continue; // un jeton dintr-un fragment, pus deja în fragment
            }
            $rez .= substr($T, $poz, $s - $poz) . $text;
            $poz = $e;
        }
        return $rez . substr($T, $poz, $b - $poz);
    }

    /**
     * Formularele Divi ale documentului: unde sunt (conținutul sau subsolul), numărul lor (`et_pb_contact_form_N`), intervalul,
     * câmpurile (id, tip, eticheta, obligatoriu) și textul butonului. Date pentru serverul formularelor (pasul 9b).
     *
     * @param list<Token> $tk
     * @return list<array<string, mixed>>
     */
    private static function formulare(string $h, array $tk, array $s): array
    {
        $rez = [];
        foreach ($tk as $k => $t) {
            if ($t->tip !== Token::DESCHIDERE || $t->nume !== 'div' || !in_array('et_pb_contact_form_container', $t->clase($h), true)) {
                continue;
            }
            if (preg_match('/^et_pb_contact_form_(\d{1,3})$/D', (string) $t->valoare($h, 'id'), $m) !== 1) {
                continue;
            }
            $p = Tokenizer::pereche($tk, $k);
            if ($p === null) {
                continue;
            }
            $loc = $s['continut'] !== null && $t->start >= $s['continut'][0] && $t->end <= $s['continut'][1] ? 'continut'
                : ($s['subsol'] !== null && $t->start >= $s['subsol'][0] && $t->end <= $s['subsol'][1] ? 'subsol' : 'altundeva');
            $modul = '';
            foreach ($t->clase($h) as $c) {
                if (preg_match('/^et_pb_contact_form_\d+(_tb_(?:header|footer))?$/D', $c) === 1) {
                    $modul = $c;
                }
            }
            $campuri = [];
            $buton = '';
            for ($i = $k + 1; $i < $p; $i++) {
                $x = $tk[$i];
                if ($x->tip !== Token::DESCHIDERE) {
                    continue;
                }
                if ($x->nume === 'p' && in_array('et_pb_contact_field', $x->clase($h), true)) {
                    $campuri[] = ['id' => (string) $x->valoare($h, 'data-id'), 'tip' => (string) $x->valoare($h, 'data-type'),
                        'eticheta' => self::textNormalizat($h, $tk, $i), 'obligatoriu' => false];
                } elseif (in_array($x->nume, ['input', 'textarea', 'select'], true) && $campuri !== [] && $x->valoare($h, 'data-required_mark') !== null) {
                    $campuri[count($campuri) - 1]['obligatoriu'] = $x->valoare($h, 'data-required_mark') === 'required';
                    $campuri[count($campuri) - 1]['fel'] = (string) $x->valoare($h, 'data-field_type');
                } elseif ($x->nume === 'button' && in_array('et_pb_contact_submit', $x->clase($h), true)) {
                    $buton = self::textNormalizat($h, $tk, $i);
                }
            }
            $rez[] = ['loc' => $loc, 'indice' => (int) $m[1], 'modul' => $modul, 'start' => $t->start, 'end' => $tk[$p]->end, 'campuri' => $campuri, 'buton' => $buton];
        }
        return $rez;
    }

    /**
     * Galeriile din conținut (modulul de galerie masonry): setările modulului (atributele `data-*` ale filtrului și ale grilei,
     * categoriile filtrului) și elementele lui (fișierul, categoriile, ordinea, titlul, textul alternativ). Date pentru serverul
     * galeriei (pasul 9b).
     *
     * @param list<Token> $tk
     * @return list<array<string, mixed>>
     */
    private static function galerii(string $h, array $tk, array $s): array
    {
        $rez = [];
        if ($s['continut'] === null) {
            return $rez;
        }
        $dec = static fn (string $v): string => html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ($tk as $k => $t) {
            if ($t->tip !== Token::DESCHIDERE || $t->nume !== 'div' || $t->start < $s['continut'][0] || $t->end > $s['continut'][1]) {
                continue;
            }
            $modul = null;
            foreach ($t->clase($h) as $c) {
                if (preg_match('/^el_dynamic_masonry_gallery_\d+$/D', $c) === 1) {
                    $modul = $c;
                }
            }
            $p = Tokenizer::pereche($tk, $k);
            if ($modul === null || $p === null) {
                continue;
            }
            $setari = [];
            $categorii = [];
            $elemente = [];
            for ($i = $k + 1; $i < $p; $i++) {
                $x = $tk[$i];
                if ($x->tip !== Token::DESCHIDERE) {
                    continue;
                }
                $cl = $x->clase($h);
                if (in_array('el_masonry_gallery_filter_wrapper', $cl, true) || in_array('el_masonry_gallery_wrapper', $cl, true)) {
                    foreach ($x->atribute as $a) {
                        if (str_starts_with($a->nume, 'data-') && !$a->duplicat) {
                            $setari[substr($a->nume, 5)] = $dec($a->valoare($h));
                        }
                    }
                } elseif ($x->nume === 'li' && $x->valoare($h, 'data-category') !== null) {
                    $categorii[] = ['id' => $dec((string) $x->valoare($h, 'data-category')), 'nume' => self::textNormalizat($h, $tk, $i)];
                } elseif (in_array('el_masonry_gallery_item', $cl, true)) {
                    $elemente[] = ['fisier' => $dec((string) $x->valoare($h, 'data-mfp-src')), 'categorii' => [], 'titlu' => '', 'alt' => ''];
                } elseif ($x->nume === 'img' && $elemente !== []) {
                    $e = &$elemente[count($elemente) - 1];
                    if ($e['fisier'] === '') {
                        $e['fisier'] = $dec((string) $x->valoare($h, 'src'));
                    }
                    $e['categorii'] = array_values(array_filter(array_map('trim', explode(',', $dec((string) $x->valoare($h, 'data-categories'))))));
                    $e['titlu'] = $dec((string) ($x->valoare($h, 'title') ?? ''));
                    $e['alt'] = $dec((string) ($x->valoare($h, 'alt') ?? ''));
                    unset($e);
                }
            }
            $rez[] = ['modul' => $modul, 'setari' => $setari + ['categorii' => $categorii], 'elemente' => $elemente];
        }
        return $rez;
    }

    /**
     * Head-ul SEO pe jetoane (DECIZIE §2.3 #9): fiecare loc al valorilor AIOSEO (titlul, descrierea, canonicalul, robots, poza;
     * `<title>`, meta, Open Graph, Twitter, JSON-LD) devine jetonul cu escaparea locului. Dacă un element SEO din head are altă
     * valoare decât cea importată (deci randarea n-ar da octeții capturați), head-ul rămâne înghețat întreg.
     *
     * @param list<Token> $tk
     * @param array<string, string>|null $seo
     * @return array{0: list<array{0: int, 1: int, 2: string, 3: string}>, 1: string, 2: ?string, 3: array<string, string>}
     */
    private static function seo(string $h, array $tk, array $s, ?array $seo, string $tip): array
    {
        if ($tip !== 'pagina') {
            return [[], 'inghetat', 'doar paginile au valori SEO de pus pe jetoane', []];
        }
        if ($seo === null || trim((string) ($seo['titlu'] ?? '')) === '') {
            return [[], 'inghetat', 'pagina n-are titlul SEO importat (AIOSEO)', []];
        }
        $v = ['seo.titlu' => (string) ($seo['titlu'] ?? ''), 'seo.descriere' => (string) ($seo['descriere'] ?? ''),
            'seo.canonical' => (string) ($seo['canonical'] ?? ''), 'seo.robots' => (string) ($seo['robots'] ?? ''), 'seo.imagine' => (string) ($seo['imagine'] ?? '')];
        $j = [];
        $nepotrivite = [];
        $folosite = [];
        $pune = static function (int $a, int $b, string $bytes, string $nume, string $esc, string $loc) use (&$j, &$nepotrivite, &$folosite, $v): void {
            if ($v[$nume] !== '' && $bytes === Jetoane::scapa($v[$nume], $esc)) {
                $j[] = [$a, $b, Jetoane::jeton($nume, $esc), 'seo'];
                $folosite[$nume] = true;
            } else {
                $nepotrivite[] = $loc;
            }
        };
        foreach ($tk as $k => $t) {
            if ($t->end > $s['headEndPoz']) {
                break;
            }
            if ($t->tip !== Token::DESCHIDERE) {
                continue;
            }
            if ($t->nume === 'title') {
                $x = $tk[$k + 1] ?? null;
                if ($x !== null && $x->tip === Token::TEXT && $x->brut === 'title') {
                    $pune($x->start, $x->end, $x->octeti($h), 'seo.titlu', 'html', '<title>');
                }
                continue;
            }
            $cheie = $t->nume === 'meta' ? strtolower((string) ($t->valoare($h, 'name') ?? $t->valoare($h, 'property') ?? '')) : '';
            $camp = match ($cheie) {
                'description', 'og:description', 'twitter:description' => 'seo.descriere',
                'og:title', 'twitter:title' => 'seo.titlu',
                'og:url' => 'seo.canonical',
                'robots' => 'seo.robots',
                'og:image', 'twitter:image' => 'seo.imagine',
                default => null,
            };
            if ($camp !== null && ($a = $t->atribut('content')) !== null && $a->areValoare()) {
                // robots și poza: doar dacă pagina le are în AIOSEO (altfel sunt ale profilului, înghețate).
                if (in_array($camp, ['seo.robots', 'seo.imagine'], true) && $v[$camp] === '') {
                    continue;
                }
                $pune($a->valoareStart, $a->valoareEnd, $a->valoare($h), $camp, 'attr', $cheie);
                continue;
            }
            if ($t->nume === 'link' && strtolower(trim((string) $t->valoare($h, 'rel'))) === 'canonical' && ($a = $t->atribut('href')) !== null && $a->areValoare()) {
                $pune($a->valoareStart, $a->valoareEnd, $a->valoare($h), 'seo.canonical', 'attr', 'canonical');
            }
        }
        if ($nepotrivite !== []) {
            return [[], 'inghetat', 'elementele SEO din head nu au valorile importate (' . implode(', ', array_slice(array_unique($nepotrivite), 0, 6))
                . '): head-ul rămâne înghețat întreg, panoul SEO doar de citit', []];
        }
        if (!isset($folosite['seo.titlu'])) {
            return [[], 'inghetat', 'head-ul n-are <title> cu titlul importat', []];
        }
        // JSON-LD: aceleași valori ca șiruri JSON (canonicalul și ca prefix al unui @id: `…/#webpage`).
        foreach ($tk as $k => $t) {
            if ($t->end > $s['headEndPoz'] || $t->tip !== Token::TEXT || $t->brut !== 'script' || !in_array('aioseo-schema', $tk[$k - 1]->clase($h), true)) {
                continue;
            }
            $text = $t->octeti($h);
            foreach (['seo.titlu', 'seo.descriere', 'seo.canonical'] as $nume) {
                if ($v[$nume] === '') {
                    continue;
                }
                $js = Jetoane::scapa($v[$nume], 'json');
                $o = 0;
                while (($p = strpos($text, '"' . $js, $o)) !== false) {
                    $dupa = substr($text, $p + 1 + strlen($js), 1);
                    if ($dupa === '"' || ($nume === 'seo.canonical' && $dupa === '#')) {
                        $j[] = [$t->start + $p + 1, $t->start + $p + 1 + strlen($js), Jetoane::jeton($nume, 'json'), 'seo'];
                        $folosite[$nume] = true;
                    }
                    $o = $p + 1 + strlen($js);
                }
            }
        }
        $valori = [];
        foreach (array_keys($folosite) as $n) {
            $valori[$n] = $v[$n];
        }
        return [$j, 'jetoane', null, $valori];
    }

    /** @param list<string>|null $nume @param list<string> $avertismente @return list<string> */
    private static function sectiuni(?array $nume, int $n, array &$avertismente): array
    {
        if ($nume === null) {
            return [];
        }
        if (count($nume) !== $n) {
            $avertismente[] = 'secțiunile: ' . $n . ' în captură, ' . count($nume) . ' în shortcoduri: numele lor nu se pun';
            return [];
        }
        return array_values(array_map('strval', $nume));
    }
}
