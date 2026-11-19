<?php

declare(strict_types=1);

namespace App\Support\Html5;

/**
 * Un atribut al unui tag (Token), cu pozițiile lui în textul sursă: numele `[numeStart, numeEnd)` (cu `$nume` cu litere
 * mici), valoarea `[valoareStart, valoareEnd)` fără ghilimele (-1 când atributul n-are valoare), ghilimelele (`"`, `'`,
 * `''` pentru o valoare fără ghilimele, `null` fără valoare) și întregul atribut `[start, end)`, de la nume până după
 * ghilimeaua de închidere. `$duplicat`: un atribut cu același nume a apărut mai devreme în tag (browserul îl ignoră pe
 * acesta; octeții rămân).
 */
final class Atribut
{
    public function __construct(
        public readonly string $nume,
        public readonly int $start,
        public readonly int $end,
        public readonly int $numeStart,
        public readonly int $numeEnd,
        public readonly int $valoareStart = -1,
        public readonly int $valoareEnd = -1,
        public readonly ?string $ghilimele = null,
        public readonly bool $duplicat = false,
    ) {
    }

    public function areValoare(): bool
    {
        return $this->ghilimele !== null;
    }

    /** Valoarea ca octeți (nedecodată; referințele de caractere rămân), '' fără valoare. */
    public function valoare(string $html): string
    {
        return $this->areValoare() ? substr($html, $this->valoareStart, $this->valoareEnd - $this->valoareStart) : '';
    }
}
