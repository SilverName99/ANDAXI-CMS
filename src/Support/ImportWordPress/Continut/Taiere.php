<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

/**
 * Ce dă tăierea unui document capturat (Divi4Taiere::taie, pasul 5a; DECIZIE §2.3 #5, #8, #9): bucățile pe care le scrie
 * importul și din care cadrul (CadruCapturat) randează pagina, plus dovezile.
 *
 *  - `html` (T): documentul servit, adică T(captura) al dialectului cu editările pasului 5a (secțiunile, sloturile, grupurile,
 *    câmpul-capcană; `editari`, cu felul lor). P1 îl compară cu randarea, după ce pune scripturile runtime-ului la `ancore`;
 *  - bucățile, cu jetoanele `{{ax:…}}` (CadruCapturat\Jetoane): `htmlAtribute` (din `<html…>`), `cap` (head-ul), `bodyAtribute`,
 *    `inainte`, `continut` (ce editează omul: `pages.html_content`), `dupa`; antetul, subsolul și bannerul sunt `fragmente`
 *    (`{{ax:fragment:antet}}` în bucată);
 *  - `valori`: valorile jetoanelor la import (cu ele, bucățile dau exact T: dovada), `meniu`: clasele și `aria-current` ale
 *    elementelor de meniu observate pe pagina aceasta, `date`: partea paginii din `ax-date` cu rețeta ordinii;
 *  - `seo`: `jetoane` (head-ul SEO pe jetoane, dovedit pe octeți) sau `inghetat` (cu `seoMotiv`: panoul SEO doar de citit);
 *  - `sectiuni` (numele din eticheta admin Divi), `formulare` și `galerii` (datele pentru server), `probleme` (NU), `avertismente`.
 */
final class Taiere
{
    /**
     * @param list<array{fel: string, start: int, end: int, text: string}> $editari
     * @param array{cap: int, final: int} $ancore pozițiile din `html` unde intră scripturile runtime-ului
     * @param array<string, array{fel: string, layout: string, html: string, date: array<string, mixed>}> $fragmente
     * @param array<string, string> $valori
     * @param array<string, array<int, array{0: string, 1: string}>> $meniu
     * @param array{pagina: array<string, mixed>, reteta: array<string, mixed>}|null $date
     * @param list<string> $sectiuni
     * @param list<array<string, mixed>> $formulare
     * @param list<array<string, mixed>> $galerii
     * @param list<string> $probleme
     * @param list<string> $avertismente
     */
    public function __construct(
        public readonly string $html,
        public readonly array $editari,
        public readonly array $ancore,
        public readonly string $htmlAtribute,
        public readonly string $cap,
        public readonly string $bodyAtribute,
        public readonly string $inainte,
        public readonly string $continut,
        public readonly string $dupa,
        public readonly array $fragmente,
        public readonly array $valori,
        public readonly array $meniu,
        public readonly ?array $date,
        public readonly string $seo,
        public readonly ?string $seoMotiv,
        public readonly array $sectiuni,
        public readonly array $formulare,
        public readonly array $galerii,
        public readonly array $probleme,
        public readonly array $avertismente = [],
    ) {
    }

    /** O tăiere refuzată: doar problemele. @param list<string> $probleme */
    public static function refuz(array $probleme, string $html = ''): self
    {
        return new self($html, [], ['cap' => 0, 'final' => 0], '', '', '', '', '', '', [], [], [], null, 'inghetat', null, [], [], [], $probleme);
    }

    /** @return array<string, int> câte editări de fiecare fel (pasul 5a) */
    public function numarPeFel(): array
    {
        $n = [];
        foreach ($this->editari as $e) {
            $n[$e['fel']] = ($n[$e['fel']] ?? 0) + 1;
        }
        ksort($n);
        return $n;
    }
}
