<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

use App\Support\CadruCapturat\Jetoane;
use App\Support\CadruCapturat\Regiuni;
use App\Support\Html5\Editare;
use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;
use App\Support\HtmlCapturat\SchemaDivi4;
use InvalidArgumentException;

/**
 * Locurile regiunilor dinamice și ale jetoanelor documentului (`{{ax:clase_body}}`, `{{ax:titlu}}`) într-un document capturat, după
 * regulile pachetului (`import-wordpress.json` → `regiuni`, CadruCapturat\Regiuni::valideazaReguli; pista A, docs/MAGAZIN-WC.md).
 * Le folosește tăierea (Divi4Taiere): întâi pe documentul dialectului (ce rămâne neatins de editările pasului 5a), apoi pe T (unde
 * intră jetoanele, după o editare Html5\Editare care verifică contextul arborelui).
 *
 * Selectorii sunt cei din gramatica închisă SchemaDivi4::selectorSigur, potriviți pe elementele HTML ale constructorului arborelui
 * (Tokenizer::analizeaza): un nume de tag, `#id`, `.clasă`, legate prin spații (descendent) și „>” (copil). Fail-closed:
 *  - o regulă care nu găsește nimic, un element fără tagul lui de închidere (sau mutat de parser), un atribut lipsă, dublat sau fără
 *    ghilimele, un titlu cu taguri în el, două locuri care se suprapun sau unul într-un fragment (antet, subsol, banner) = NU.
 */
final class TaiereRegiuni
{
    /**
     * Locurile tuturor regulilor într-un document: regiunile (id, numărul în document, felul, atributul, intervalul `[start, end)`
     * al octeților capturați, indexul tagului) și jetoanele, în ordinea documentului; plus problemele.
     *
     * @param list<Token> $tk tokenii lui `$h` (Tokenizer::analizeaza)
     * @param list<array<string, mixed>> $reguli regulile documentului (Regiuni::pentruDocument)
     * @param list<array{0: int, 1: int}> $fragmente zonele fragmentelor
     * @return array{locuri: list<array{fel: string, regiune: ?string, n: int, mod: string, atribut: ?string, k: int, start: int, end: int, jeton: string}>, probleme: list<string>}
     */
    public static function locuri(string $h, array $tk, array $reguli, array $fragmente, int $body): array
    {
        $locuri = [];
        $probleme = [];
        $parinti = self::parinti($tk);
        foreach ($reguli as $i => $r) {
            $et = 'regiuni: regula „' . (string) ($r['regiune'] ?? $r['jeton'] ?? '?') . '”' . (isset($r['selector']) ? ' („' . $r['selector'] . '”)' : '');
            if (($r['jeton'] ?? null) === 'clase_body') {
                $a = $tk[$body]->atribut('class');
                if ($a === null || !in_array($a->ghilimele, ['"', "'"], true)) {
                    $probleme[] = $et . ': <body> n-are atributul class între ghilimele';
                    continue;
                }
                $locuri[] = ['fel' => 'jeton', 'regiune' => null, 'n' => 0, 'mod' => 'atribut', 'atribut' => 'class', 'k' => $body, 'start' => $a->valoareStart,
                    'end' => $a->valoareEnd, 'jeton' => 'clase_body'];
                continue;
            }
            $gasite = self::potriviri($h, $tk, (string) ($r['selector'] ?? ''), $parinti);
            if ($gasite === []) {
                $probleme[] = $et . ': selectorul nu găsește niciun element în document';
                continue;
            }
            foreach ($gasite as $k) {
                $t = $tk[$k];
                $mod = (string) ($r['mod'] ?? 'interior');
                if (($r['jeton'] ?? null) === 'titlu' || $mod === 'interior') {
                    $p = Tokenizer::pereche($tk, $k);
                    if ($p === null || $t->mutat) {
                        $probleme[] = $et . ': <' . $t->nume . '> de la octetul ' . $t->start . ' nu se închide cu tagul lui (sau parserul îi mută nodurile)';
                        continue;
                    }
                    if (($r['jeton'] ?? null) === 'titlu') {
                        for ($x = $k + 1; $x < $p; $x++) {
                            if ($tk[$x]->tip !== Token::TEXT || $tk[$x]->brut !== '') {
                                $probleme[] = $et . ': titlul de la octetul ' . $t->start . ' are și altceva decât text';
                                continue 3;
                            }
                        }
                        $locuri[] = ['fel' => 'jeton', 'regiune' => null, 'n' => 0, 'mod' => 'interior', 'atribut' => null, 'k' => $k, 'start' => $t->end,
                            'end' => $tk[$p]->start, 'jeton' => 'titlu'];
                        continue;
                    }
                    $locuri[] = ['fel' => 'regiune', 'regiune' => (string) $r['regiune'], 'n' => 0, 'mod' => 'interior', 'atribut' => null, 'k' => $k,
                        'start' => $t->end, 'end' => $tk[$p]->start, 'jeton' => ''];
                    continue;
                }
                $numeAtribut = (string) ($r['atribut'] ?? '');
                $a = $t->atribut($numeAtribut);
                $dublat = count(array_filter($t->atribute, static fn ($x): bool => $x->nume === $numeAtribut)) > 1;
                if ($a === null || $dublat || !in_array($a->ghilimele, ['"', "'"], true)) {
                    $probleme[] = $et . ': <' . $t->nume . '> de la octetul ' . $t->start . ' n-are atributul ' . $numeAtribut . ' o singură dată, între ghilimele';
                    continue;
                }
                $locuri[] = ['fel' => 'regiune', 'regiune' => (string) $r['regiune'], 'n' => 0, 'mod' => 'atribut', 'atribut' => $numeAtribut, 'k' => $k,
                    'start' => $a->valoareStart, 'end' => $a->valoareEnd, 'jeton' => ''];
            }
        }
        usort($locuri, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
        $numar = [];
        $jetoane = [];
        $ultim = -1;
        foreach ($locuri as $i => $l) {
            if ($l['start'] < $ultim) {
                $probleme[] = 'regiuni: două locuri se suprapun (sau unul e în altul), la octetul ' . $l['start'];
            }
            $ultim = max($ultim, $l['end']);
            foreach ($fragmente as [$fa, $fb]) {
                if ($l['start'] < $fb && $l['end'] > $fa) {
                    $probleme[] = 'regiuni: locul de la octetul ' . $l['start'] . ' e într-un fragment (antet, subsol sau banner), comun mai multor documente';
                }
            }
            if ($l['fel'] === 'regiune') {
                $locuri[$i]['n'] = $numar[$l['regiune']] = isset($numar[$l['regiune']]) ? $numar[$l['regiune']] + 1 : 0;
                if ($locuri[$i]['n'] > 999) {
                    $probleme[] = 'regiuni: prea multe regiuni „' . $l['regiune'] . '” într-un document';
                }
                $locuri[$i]['jeton'] = 'regiune:' . $l['regiune'] . ':' . $locuri[$i]['n'];
            } else {
                if (isset($jetoane[$l['jeton']])) {
                    $probleme[] = 'regiuni: jetonul „' . $l['jeton'] . '” de două ori în document';
                }
                $jetoane[$l['jeton']] = true;
                $locuri[$i]['jeton'] = $l['jeton'];
            }
        }
        return ['locuri' => array_values($locuri), 'probleme' => array_values(array_unique($probleme))];
    }

    /**
     * Tăierea pe T: fiecare loc primește jetonul lui printr-o editare Html5\Editare (interiorul elementului, valoarea atributului),
     * care verifică și efectul în arbore (și pe cel al tuturor editărilor împreună). Întoarce intervalele cu jetoanele lor, în forma
     * jetoanelor tăierii (`[start, end, text, fel]`), sau problema.
     *
     * @param list<Token> $tk
     * @param list<array<string, mixed>> $locuri
     * @return list<array{0: int, 1: int, 2: string, 3: string}>|string
     */
    public static function jetoane(string $T, array $tk, array $locuri): array|string
    {
        $ed = new Editare($T);
        try {
            foreach ($locuri as $l) {
                $jeton = Jetoane::jeton($l['jeton']);
                if ($l['mod'] === 'interior') {
                    $ed->interior((int) $l['k'], $jeton);
                } else {
                    $ed->valoareAtribut((int) $l['k'], (string) $l['atribut'], $jeton);
                }
            }
            $inlocuiri = $ed->inlocuiri();
        } catch (InvalidArgumentException $e) {
            return 'tăierea regiunilor (Html5\\Editare) a fost refuzată: ' . $e->getMessage();
        }
        $rez = [];
        foreach ($locuri as $l) {
            $gasit = null;
            foreach ($inlocuiri as $x) {
                if ($x[0] === $l['start'] && $x[1] === $l['end']) {
                    $gasit = $x;
                    break;
                }
            }
            if ($gasit === null) {
                return 'tăierea regiunilor: editarea locului de la octetul ' . $l['start'] . ' nu e chiar intervalul lui';
            }
            $rez[] = [(int) $l['start'], (int) $l['end'], (string) $gasit[2], $l['fel'] === 'regiune' ? 'regiune' : (string) $l['jeton']];
        }
        return $rez;
    }

    /**
     * Elementele care se potrivesc cu un selector din gramatica închisă (SchemaDivi4::selectorSigur), în ordinea documentului.
     *
     * @param list<Token> $tk
     * @param array<int, int>|null $parinti
     * @return list<int>
     */
    public static function potriviri(string $h, array $tk, string $selector, ?array $parinti = null): array
    {
        if (!SchemaDivi4::selectorSigur($selector)) {
            throw new InvalidArgumentException('selector nepermis: ' . mb_substr($selector, 0, 60));
        }
        $parinti ??= self::parinti($tk);
        // Compușii și legăturile dintre ei (de la dreapta la stânga se potrivesc).
        $parti = preg_split('/( *> *| +)/', $selector, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $compusi = [];
        $legaturi = [];
        foreach ($parti as $i => $x) {
            if ($i % 2 === 0) {
                preg_match('/^([a-z][a-z0-9]*)?((?:[#.][-A-Za-z0-9_]+)*)$/D', $x, $m);
                preg_match_all('/([#.])(-?[A-Za-z_][A-Za-z0-9_-]*)/', $m[2] ?? '', $mm, PREG_SET_ORDER);
                $compusi[] = ['tag' => ($m[1] ?? '') !== '' ? $m[1] : null, 'id' => array_values(array_map(static fn (array $y): string => $y[2],
                    array_filter($mm, static fn (array $y): bool => $y[1] === '#'))), 'clase' => array_values(array_map(static fn (array $y): string => $y[2],
                    array_filter($mm, static fn (array $y): bool => $y[1] === '.')))];
            } else {
                $legaturi[] = trim($x) === '>' ? '>' : ' ';
            }
        }
        $potriveste = static function (int $k, array $c) use ($h, $tk): bool {
            $t = $tk[$k];
            if ($c['tag'] !== null && $t->nume !== $c['tag']) {
                return false;
            }
            foreach ($c['id'] as $id) {
                if ($t->valoare($h, 'id') !== $id) {
                    return false;
                }
            }
            $clase = $c['clase'] !== [] ? $t->clase($h) : [];
            foreach ($c['clase'] as $cl) {
                if (!in_array($cl, $clase, true)) {
                    return false;
                }
            }
            return true;
        };
        $ultim = count($compusi) - 1;
        $urca = static function (int $k, int $i) use (&$urca, $compusi, $legaturi, $parinti, $potriveste): bool {
            if (!$potriveste($k, $compusi[$i])) {
                return false;
            }
            if ($i === 0) {
                return true;
            }
            $p = $parinti[$k] ?? null;
            if ($legaturi[$i - 1] === '>') {
                return $p !== null && $urca($p, $i - 1);
            }
            while ($p !== null) {
                if ($urca($p, $i - 1)) {
                    return true;
                }
                $p = $parinti[$p] ?? null;
            }
            return false;
        };
        $rez = [];
        foreach ($tk as $k => $t) {
            if ($t->tip === Token::DESCHIDERE && !$t->ignorat && $t->spatiu === 'html' && $urca($k, $ultim)) {
                $rez[] = $k;
            }
        }
        return $rez;
    }

    /**
     * Părintele fiecărui element (indexul tagului de deschidere al elementului care îl cuprinde, după constructorul arborelui:
     * cel deschis cel mai recent și încă neînchis), pentru selectori. @param list<Token> $tk @return array<int, int>
     */
    private static function parinti(array $tk): array
    {
        $rez = [];
        $stiva = [];
        foreach ($tk as $k => $t) {
            if ($t->tip !== Token::DESCHIDERE || $t->ignorat) {
                continue;
            }
            while ($stiva !== [] && ($tk[$stiva[count($stiva) - 1]]->inchidere >= 0 && $tk[$stiva[count($stiva) - 1]]->inchidere <= $k)) {
                array_pop($stiva);
            }
            if ($stiva !== []) {
                $rez[$k] = $stiva[count($stiva) - 1];
            }
            if ($t->inchidere !== $k) {
                $stiva[] = $k;
            }
        }
        return $rez;
    }

    /**
     * Regulile pachetului care se aplică unui document (Regiuni::pentruDocument). Fail-closed: reguli greșite (Regiuni::valideazaReguli)
     * se dau întregi, nefiltrate, iar tăierea le refuză cu motivul lor (Divi4Taiere::taie), deci niciun document nu se importă cu
     * regiunile lui netăiate (o captură cu un coș, înghețată și pusă în cache). @return list<mixed>
     */
    public static function reguliDocument(array $reguli, string $tip, ?string $cale): array
    {
        return Regiuni::valideazaReguli($reguli) === [] ? Regiuni::pentruDocument(array_values($reguli), $tip, $cale) : array_values($reguli);
    }
}
