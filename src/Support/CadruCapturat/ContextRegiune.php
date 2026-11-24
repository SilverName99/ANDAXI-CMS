<?php

declare(strict_types=1);

namespace App\Support\CadruCapturat;

use App\Support\HtmlCapturat\SchemaDivi4;
use PDO;

/**
 * Contextul în care randează o regiune dinamică (Regiuni, Randator; pista A, docs/MAGAZIN-WC.md): baza, documentul, calea, query-ul
 * și metoda cererii, regiunea însăși (id-ul, numărul, felul cache-ului, modul: `interior` sau `atribut`, cu atributul), starea
 * capturii la dovadă și, comune tuturor regiunilor aceluiași document, clasele corpului (`{{ax:clase_body}}`) și titlul
 * (`{{ax:titlu}}`). Un randator cu ambele moduri (coșul: interiorul și clasa modulului) le deosebește prin mod() și atribut().
 *
 *  - `sesiune()`: datele sesiunii, doar pentru o regiune cu `cache` = `sesiune` (altfel \LogicException: o regiune a unei pagini
 *    care poate sta în cache nu citește sesiunea, deci niciun document din cache nu are date de sesiune);
 *  - `stare()`: starea arătată de captură (de exemplu „coșul cu produsul P, cantitatea 1”), doar la dovadă; null la o cerere;
 *  - `capturat()`: octeții capturați ai regiunii (din `regiuni_capturate` la o cerere, din tăiere la dovadă), de exemplu lista
 *    claselor Divi a unui modul al coșului, la care randatorul adaugă sau scoate `et_pb_wc_cart_empty`;
 *  - `adaugaClasaBody()`, `scoateClasaBody()`: clasele corpului, doar din lista închisă SchemaDivi4::CLASE_BODY;
 *  - `titlu()`: titlul paginii (endpointurile contului), text simplu (scăpat aici, cu `{` ca `&#123;`: un `{{ax:` din date rămâne
 *    text, ca la Jetoane::scapa).
 *
 * Toate regiunile unui document primesc clone ale aceluiași context (pentru()), deci clasele corpului și titlul cerute de una se
 * văd la toate; la dovadă, valorile finale se compară cu cele capturate (ScriereCapturata::dovadaRegiuni).
 */
final class ContextRegiune
{
    private string $regiune = '';
    private int $n = 0;
    private string $cache = 'pagina';
    private string $mod = 'interior';
    private ?string $atribut = null;
    private string $capturat = '';

    /** Starea comună a documentului (clasele corpului, titlul), aceeași pentru toate regiunile lui. */
    private \stdClass $comun;

    /**
     * @param array<string, mixed> $get query-ul cererii (o copie)
     * @param array<string, mixed>|null $stare starea capturii (la dovadă)
     */
    public function __construct(
        public readonly ?PDO $db,
        public readonly string $document,
        public readonly string $cale,
        public readonly array $get = [],
        public readonly string $metoda = 'GET',
        private readonly ?array $stare = null,
    ) {
        $this->comun = new \stdClass();
        $this->comun->adauga = [];
        $this->comun->scoate = [];
        $this->comun->titlu = null;
    }

    /**
     * Același context, pentru regiunea dată: id-ul, numărul ei în document, felul cache-ului din Regiuni::CATALOG, modul (`interior`
     * sau `atribut`), la `atribut` numele atributului și octeții ei capturați (din `regiuni_capturate`: `fel`, `atribut`, `octeti`).
     */
    public function pentru(string $regiune, int $n, string $cache, string $mod = 'interior', ?string $atribut = null, string $capturat = ''): self
    {
        $c = clone $this;
        $c->regiune = $regiune;
        $c->n = $n;
        $c->cache = $cache;
        $c->mod = $mod === 'atribut' ? 'atribut' : 'interior';
        $c->atribut = $c->mod === 'atribut' ? $atribut : null;
        $c->capturat = $capturat;
        return $c;
    }

    /** Octeții capturați ai regiunii (interiorul elementului sau valoarea atributului), la o cerere și la dovadă. */
    public function capturat(): string
    {
        return $this->capturat;
    }

    public function regiune(): string
    {
        return $this->regiune;
    }

    public function n(): int
    {
        return $this->n;
    }

    /** `pagina` sau `sesiune`. */
    public function cache(): string
    {
        return $this->cache;
    }

    /** `interior` (interiorul elementului) sau `atribut` (valoarea atributului, fără ghilimele). */
    public function mod(): string
    {
        return $this->mod;
    }

    /** Numele atributului regiunii (`class`, `data-events`) la modul `atribut`; null la `interior`. */
    public function atribut(): ?string
    {
        return $this->atribut;
    }

    /** La dovadă (import, teste): starea capturii; la o cerere: null. @return array<string, mixed>|null */
    public function stare(): ?array
    {
        return $this->stare;
    }

    public function laDovada(): bool
    {
        return $this->stare !== null;
    }

    /**
     * Datele sesiunii (fără să o pornească), doar pentru o regiune cu `cache` = `sesiune`.
     *
     * @return array<string, mixed>
     * @throws \LogicException o regiune `pagina` (documentul ei poate sta în cache)
     */
    public function sesiune(): array
    {
        if ($this->cache !== 'sesiune') {
            throw new \LogicException('regiunea „' . $this->regiune . '” are cache „pagina”: nu citește sesiunea');
        }
        return session_status() === PHP_SESSION_ACTIVE && is_array($_SESSION ?? null) ? $_SESSION : [];
    }

    /** O clasă a corpului, în plus (doar din SchemaDivi4::CLASE_BODY). */
    public function adaugaClasaBody(string $clasa): void
    {
        self::clasaPermisa($clasa);
        $this->comun->adauga[$clasa] = true;
        unset($this->comun->scoate[$clasa]);
    }

    /** O clasă a corpului, scoasă (doar din SchemaDivi4::CLASE_BODY). */
    public function scoateClasaBody(string $clasa): void
    {
        self::clasaPermisa($clasa);
        $this->comun->scoate[$clasa] = true;
        unset($this->comun->adauga[$clasa]);
    }

    /** Titlul paginii (`{{ax:titlu}}`), ca text simplu (scăpat; `{` ca `&#123;`, ca Jetoane::scapa: titlul trece prin jetoane). */
    public function titlu(string $text): void
    {
        $this->comun->titlu = str_replace('{', '&#123;', Regiuni::html($text));
    }

    /** A cerut vreo regiune a documentului clase ale corpului? (dovada: un document fără `{{ax:clase_body}}` nu le poate pune) */
    public function cereClaseBody(): bool
    {
        return $this->comun->adauga !== [] || $this->comun->scoate !== [];
    }

    /** A cerut vreo regiune a documentului un titlu? (dovada: un document fără `{{ax:titlu}}` nu îl poate pune) */
    public function cereTitlu(): bool
    {
        return is_string($this->comun->titlu);
    }

    /**
     * Valoarea lui `{{ax:clase_body}}`: clasele capturate (octeții atributului), neschimbate fără nicio cerere a regiunilor; altfel
     * lista lor (despărțită de spațiile HTML) fără cele scoase și cu cele adăugate, la sfârșit, unite cu câte un spațiu.
     */
    public function claseBody(string $capturate): string
    {
        if ($this->comun->adauga === [] && $this->comun->scoate === []) {
            return $capturate;
        }
        $clase = array_values(array_filter(preg_split('/[\t\n\f\r ]+/', $capturate) ?: [], static fn (string $c): bool => $c !== ''));
        $clase = array_values(array_filter($clase, fn (string $c): bool => !isset($this->comun->scoate[$c])));
        foreach (array_keys($this->comun->adauga) as $c) {
            if (!in_array((string) $c, $clase, true)) {
                $clase[] = (string) $c;
            }
        }
        return implode(' ', $clase);
    }

    /** Valoarea lui `{{ax:titlu}}`: titlul cerut de o regiune (scăpat), altfel octeții capturați. */
    public function titluFinal(string $capturat): string
    {
        return is_string($this->comun->titlu) ? $this->comun->titlu : $capturat;
    }

    private static function clasaPermisa(string $clasa): void
    {
        if (!in_array($clasa, SchemaDivi4::CLASE_BODY, true)) {
            throw new \InvalidArgumentException('clasa corpului „' . mb_substr($clasa, 0, 60) . '” nu e în lista închisă (SchemaDivi4::CLASE_BODY)');
        }
    }
}
