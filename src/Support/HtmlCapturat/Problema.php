<?php

declare(strict_types=1);

namespace App\Support\HtmlCapturat;

/**
 * Un motiv pentru care politica HTML-ului capturat (App\Support\HtmlCapturat) refuză un document: codul (pentru teste și
 * jurnale), mesajul în cuvinte (ce se pune în plan la „NU”) și octetul din sursă la care s-a găsit (-1 când e despre
 * documentul întreg, ca o nesiguranță de tokenizare). Politica nu scoate nimic în tăcere: fiecare lucru nepermis dă o
 * Problema, iar un document cu măcar o Problema nu se importă și nu se salvează (DECIZIE §2.9, pasul 3).
 */
final class Problema
{
    public function __construct(
        public readonly string $cod,
        public readonly string $mesaj,
        public readonly int $octet = -1,
    ) {
    }

    /** Rândul „NU” din planul importului (scripts/importa-wordpress.php) sau din refuzul salvării din admin. */
    public function linie(): string
    {
        return $this->mesaj . ($this->octet >= 0 ? ' (octetul ' . $this->octet . ')' : '');
    }
}
