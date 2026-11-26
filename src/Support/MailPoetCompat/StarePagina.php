<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

/**
 * Starea unei pagini a abonamentului MailPoet (pista E): textul titlului (H1, fără sufixul titlului documentului), corpul (`BODY`
 * din specificație: interiorul lui `div.entry-content` fără marginile lui WordPress) și statusul HTTP. Routerul o calculează după
 * efectele cererii și o pune în `curenta()`; randatorul regiunii `mailpoet:pagina` (RandarePagina) o citește. O stare pe cerere.
 */
final class StarePagina
{
    private static ?self $curenta = null;

    public function __construct(
        public readonly string $titlu,
        public readonly string $corp,
        public readonly int $status = 200,
    ) {
    }

    public static function curenta(): ?self
    {
        return self::$curenta;
    }

    public static function seteaza(?self $stare): void
    {
        self::$curenta = $stare;
    }

    /** Interiorul regiunii pentru `div.entry-content` (n = 1): marginile lui WordPress în jurul corpului. */
    public function interiorContinut(): string
    {
        return "\n\t\t\t\t\t" . $this->corp . "\t\t\t\t\t";
    }
}
