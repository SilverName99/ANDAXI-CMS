<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use DateTimeImmutable;

/**
 * Ceasul automatizărilor: toate orele pe care le scrie sau le compară motorul (intrarea, așteptările, scadențele, revendicările)
 * vin de aici, nu din NOW() al bazei. Testele îl îngheață (`fixeaza`) și îl mută (`avanseaza`), deci „așteaptă 3 zile” se
 * verifică fără să aștepte nimeni.
 */
final class Ceas
{
    private static ?DateTimeImmutable $fixat = null;

    public static function acum(): DateTimeImmutable
    {
        return self::$fixat ?? new DateTimeImmutable('now');
    }

    /** Ora de acum, în formatul coloanelor DATETIME. */
    public static function sql(?DateTimeImmutable $moment = null): string
    {
        return ($moment ?? self::acum())->format('Y-m-d H:i:s');
    }

    /** Doar pentru teste: ceasul stă pe `$moment` (null = ceasul adevărat din nou). */
    public static function fixeaza(?string $moment): void
    {
        self::$fixat = $moment === null ? null : new DateTimeImmutable($moment);
    }

    /** Doar pentru teste: mută ceasul înghețat (de exemplu „+3 days”). */
    public static function avanseaza(string $cu): void
    {
        self::$fixat = self::acum()->modify($cu);
    }
}
