<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use RuntimeException;

/**
 * O oprire a runner-ului de migrări, cu codul de ieșire al lui `scripts/migreaza.php`
 * (docs/MIGRARI.md, „Codurile de ieșire”) în getCode():
 *
 *   1  eroare într-o migrare          2  configurare, conexiune, server, registru
 *   3  lacăt ținut de altcineva        4  bază străină (nu e a unui site ANDAXI)
 *   11 blocantă/contract fără voie     12 o migrare aplicată s-a schimbat sau a dispărut
 *   13 bugetul de timp s-a terminat    14 verificarea a găsit un obiect lipsă
 *   15 lipsește un parametru al site-ului
 *
 * Mesajul nu conține niciodată valori din rânduri (vezi Schema::faraValori).
 */
class Eroare extends RuntimeException
{
    public const MIGRARE = 1;
    public const CONFIGURARE = 2;
    public const LACAT = 3;
    public const BAZA_STRAINA = 4;
    public const DE_APLICAT = 10;
    public const NEPERMISA = 11;
    public const SCHIMBATA = 12;
    public const BUGET = 13;
    public const VERIFICARE = 14;
    public const PARAMETRU = 15;
}
