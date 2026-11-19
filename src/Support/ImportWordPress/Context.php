<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use App\Support\ImportWordPress\Continut\Convertor;
use App\Support\ImportWordPress\Continut\WordPressSimplu;

/**
 * Ce au în comun planul și convertoarele: inventarul site-ului WordPress, adresele (vechi → noi), regulile pachetului
 * (`import-wordpress.json`, ImportWordPress::REGULI), dosarul WordPress și fișierele din uploads/ pomenite de conținut
 * (care se copiază și ele, chiar dacă nu sunt atașamente).
 */
final class Context
{
    /** @var array<string, true> căile relative la wp-content/uploads ale fișierelor pomenite */
    private array $fisiere = [];

    /** @var list<Convertor> */
    private array $convertoare;

    /**
     * @param array<string, mixed> $reguli
     * @param list<Convertor> $convertoare în plus față de cel simplu (de exemplu cel pentru Divi), încercate primele
     */
    public function __construct(
        public readonly Inventar $inventar,
        public readonly Adrese $adrese,
        public readonly array $reguli,
        public readonly string $dosarWp,
        public readonly string $dosarUploads,
        public readonly string $url = '',
        array $convertoare = [],
    ) {
        $this->convertoare = array_merge($convertoare, [new WordPressSimplu()]);
    }

    /** Convertorul care știe constructorul dat, sau null. */
    public function convertor(string $constructor): ?Convertor
    {
        foreach ($this->convertoare as $c) {
            if ($c->stie($constructor)) {
                return $c;
            }
        }
        return null;
    }

    /** @return list<Convertor> */
    public function convertoare(): array
    {
        return $this->convertoare;
    }

    /** Un fișier din uploads/ pomenit de conținut (cale relativă la wp-content/uploads). */
    public function fisier(string $rel): void
    {
        $rel = ltrim($rel, '/');
        if ($rel !== '' && !str_contains($rel, '..')) {
            $this->fisiere[$rel] = true;
        }
    }

    /** @return list<string> */
    public function fisierePomenite(): array
    {
        $rez = array_keys($this->fisiere);
        sort($rez);
        return $rez;
    }

    /**
     * Drumul capturat (`import-wordpress.json` → `"continut": "capturat"`): paginile vin din captura site-ului live
     * (pasul 4), convertite de dialectul lor (`"dialect"`, pasul 5); head-ul, antetul și subsolul sunt ale capturii.
     */
    public function capturat(): bool
    {
        return ($this->reguli['continut'] ?? null) === ImportWordPress::CONTINUT_CAPTURAT;
    }

    /** Dialectul capturii („divi4”), sau '' pe drumul necapturat. */
    public function dialect(): string
    {
        return $this->capturat() && is_string($this->reguli['dialect'] ?? null) ? (string) $this->reguli['dialect'] : '';
    }

    /**
     * Convertorul dialectului capturii (cel care știe „dialect:<nume>”, pasul 5), sau null: pe drumul capturat nu se
     * folosește niciodată convertorul simplu, deci fără el planul spune NU.
     */
    public function convertorDialect(): ?Convertor
    {
        if ($this->dialect() === '') {
            return null;
        }
        foreach ($this->convertoare as $c) {
            if ($c->stie('dialect:' . $this->dialect())) {
                return $c;
            }
        }
        return null;
    }

    /** O regulă a pachetului pentru o pagină (după slug-ul WordPress), sau []. @return array<string, mixed> */
    public function regulaPagina(string $slug): array
    {
        $r = $this->reguli['pagini'][$slug] ?? [];
        return is_array($r) ? $r : [];
    }
}
