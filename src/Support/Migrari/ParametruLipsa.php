<?php

declare(strict_types=1);

namespace App\Support\Migrari;

/**
 * O migrare are nevoie de un răspuns pe care îl știe doar site-ul (Schema::parametruSite,
 * din `migrari.json` din dosarul site-ului) și răspunsul lipsește. Runner-ul se oprește
 * cu codul 15 înainte să schimbe ceva: o migrare care ar ghici ar putea strica datele.
 */
final class ParametruLipsa extends Eroare
{
    public function __construct(string $mesaj)
    {
        parent::__construct($mesaj, self::PARAMETRU);
    }
}
