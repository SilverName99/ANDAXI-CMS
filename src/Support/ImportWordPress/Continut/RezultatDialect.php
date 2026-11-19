<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

/**
 * Ce dă un dialect pentru o pagină capturată (Dialect::transforma): T(captura) și dovada lui.
 *  - `html`: documentul transformat ('' când captura e refuzată înainte de orice editare: un document nesigur);
 *  - `editari`: fiecare înlocuire, cu felul ei (lista închisă: Normalizare::FELURI + Divi4::FELURI), intervalul `[start, end)` din
 *    captură și textul nou; în afara lor, octeții lui `html` sunt exact cei ai capturii;
 *  - `date`: configurațiile comportamentului, în ordinea din pagină (`ax-date`), și `proprietari`: aceleași împărțite pe pagină,
 *    antet și subsol (sufixele `_tb_header` / `_tb_footer`);
 *  - `aruncate`: scripturile (și `<noscript>`-urile) scoase, cu sha256-ul lor după normalizare și motivul din pachet;
 *  - `necunoscute`: ce n-a fost recunoscut (sha256, id, început), de revizuit și, după revizie, trecut în `scripturi_aruncate`;
 *  - `sloturi`: pozițiile din `html` ale scripturilor runtime-ului (`cap` în head, înaintea lui `ax-date`; `final`, unde stătea
 *    ultimul script din corp);
 *  - `probleme` (NU) și `avertismente` (ATENȚIE).
 */
final class RezultatDialect
{
    /**
     * @param list<array{fel: string, start: int, end: int, text: string}> $editari
     * @param array<string, mixed> $date
     * @param array{pagina: array<string, mixed>, antet: array<string, mixed>, subsol: array<string, mixed>} $proprietari
     * @param list<array<string, mixed>> $aruncate
     * @param list<array<string, mixed>> $necunoscute
     * @param array{cap: ?int, final: ?int} $sloturi
     * @param list<string> $probleme
     * @param list<string> $avertismente
     */
    public function __construct(
        public readonly string $html,
        public readonly array $editari,
        public readonly array $date,
        public readonly array $proprietari,
        public readonly array $aruncate,
        public readonly array $necunoscute,
        public readonly array $sloturi,
        public readonly array $probleme,
        public readonly array $avertismente = [],
    ) {
    }

    /** @return array<string, int> câte editări de fiecare fel */
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
