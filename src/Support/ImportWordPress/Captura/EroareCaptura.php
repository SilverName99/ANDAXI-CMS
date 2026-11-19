<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

/**
 * Oprirea capturii, cu motivul (`$fel`): `buget` (bugetul de cereri s-a terminat), `gazda` (o adresă spre o gazdă nepermisă
 * sau spre un tracker: nu pleacă nicio cerere), `limitare` (gazda răspunde 429 prea des: captura se oprește politicos),
 * `retea` (cererea n-a primit răspuns), `folosire` (o opțiune greșită), `scriere` (ieșirea nu se poate scrie unde trebuie),
 * `lant` (o captură urcată nu trece verificarea sha256: raportul, indexul, manifestul sau un fișier din brut/), `integritate`
 * (WordPress-ul de pe disc nu trece verificarea dinaintea capturii).
 */
final class EroareCaptura extends \RuntimeException
{
    public function __construct(public readonly string $fel, string $mesaj)
    {
        parent::__construct($mesaj);
    }
}
