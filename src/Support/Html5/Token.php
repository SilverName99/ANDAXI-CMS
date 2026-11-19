<?php

declare(strict_types=1);

namespace App\Support\Html5;

/**
 * Un token al tokenizerului HTML5 (Tokenizer): felul lui și intervalul lui de octeți în textul sursă, `[start, end)`.
 * Tokenii unui text se ating cap la cap și îl acoperă întreg, deci textul se recompune exact din ei
 * (`Tokenizer::recompune`). Nimic nu se decodează: referințele de caractere (`&amp;`, `&nbsp;`) rămân octeții lor.
 *
 *  - TEXT: text obișnuit, textul brut al unui element (`script`, `style`, `xmp`, `iframe`, `noembed`, `noframes`,
 *    `noscript`, `plaintext`: `$brut` = numele elementului) sau RCDATA (`title`, `textarea`: tot `$brut`);
 *  - DESCHIDERE / INCHIDERE: un tag de deschidere sau de închidere: `$nume` cu litere mici, poziția numelui, atributele
 *    (Atribut), `$autoInchis` pentru `/>`, `$complet` = false când textul se termină în interiorul tagului;
 *  - COMENTARIU: `<!-- … -->` (și formele scurte `<!-->`, `<!--->`, închiderea `--!>`), cu intervalul conținutului;
 *  - DOCTYPE: `<!DOCTYPE …>`;
 *  - CDATA: `<![CDATA[ … ]]>` în conținutul străin (`svg`, `math`);
 *  - FALS: un comentariu „fals” (`<?…>`, `<!…>`, `</ …>`), sau `</>`, pe care browserul îl ignoră.
 *
 * Ce face constructorul arborelui cu un tag (Constructie, fără arbore; Tokenizer::analizeaza):
 *  - `$spatiu`: la o deschidere, spațiul de nume al elementului făcut: `html`, `svg` sau `math` ('' fără element);
 *  - `$ignorat`: tagul nu face nimic (o deschidere fără element, ca `<head>` în corp; o închidere fără element deschis);
 *  - `$inchidere`: la o deschidere, indexul tokenului care scoate elementul de pe stivă (el însuși la un element gol sau la
 *    un `/>` din SVG și MathML; -1 dacă rămâne deschis până la sfârșit);
 *  - `$inchidereProprie`: elementul îl închide chiar tagul lui de închidere (`$inchidere`), iar parserul nu i-a mutat nimic
 *    înăuntru sau în afară: doar atunci intervalul dintre cele două taguri e conținutul lui (Tokenizer::pereche);
 *  - `$mutat`: parserul a mutat noduri ale lui (algoritmul de adopție al formatării, conținutul scos în afara unui tabel,
 *    un `</form>` din mijlocul stivei): octeții lui nu mai sunt conținutul lui din DOM.
 */
final class Token
{
    public const TEXT = 'text';
    public const DESCHIDERE = 'deschidere';
    public const INCHIDERE = 'inchidere';
    public const COMENTARIU = 'comentariu';
    public const DOCTYPE = 'doctype';
    public const CDATA = 'cdata';
    public const FALS = 'fals';

    /**
     * @param list<Atribut> $atribute
     */
    public function __construct(
        public readonly string $tip,
        public readonly int $start,
        public readonly int $end,
        public readonly string $nume = '',
        public readonly int $numeStart = -1,
        public readonly int $numeEnd = -1,
        public readonly array $atribute = [],
        public readonly bool $autoInchis = false,
        public readonly bool $complet = true,
        public readonly int $continutStart = -1,
        public readonly int $continutEnd = -1,
        public readonly string $brut = '',
        public readonly string $spatiu = '',
        public readonly bool $ignorat = false,
        public readonly int $inchidere = -1,
        public readonly bool $inchidereProprie = false,
        public readonly bool $mutat = false,
    ) {
    }

    /** Același token, cu ce a făcut constructorul arborelui cu el (Tokenizer::analizeaza). */
    public function cuConstructie(string $spatiu, bool $ignorat, int $inchidere, bool $inchidereProprie, bool $mutat): self
    {
        return new self($this->tip, $this->start, $this->end, $this->nume, $this->numeStart, $this->numeEnd, $this->atribute, $this->autoInchis,
            $this->complet, $this->continutStart, $this->continutEnd, $this->brut, $spatiu, $ignorat, $inchidere, $inchidereProprie, $mutat);
    }

    public function esteTag(): bool
    {
        return $this->tip === self::DESCHIDERE || $this->tip === self::INCHIDERE;
    }

    /** Octeții tokenului din textul sursă. */
    public function octeti(string $html): string
    {
        return substr($html, $this->start, $this->end - $this->start);
    }

    /** Primul atribut cu acest nume (cu litere mici; cel care contează, ca în browser), sau null. */
    public function atribut(string $nume): ?Atribut
    {
        $nume = strtolower($nume);
        foreach ($this->atribute as $a) {
            if ($a->nume === $nume && !$a->duplicat) {
                return $a;
            }
        }
        return null;
    }

    /** Valoarea primului atribut cu acest nume, ca octeți (nedecodată), sau null. */
    public function valoare(string $html, string $nume): ?string
    {
        $a = $this->atribut($nume);
        return $a === null ? null : $a->valoare($html);
    }

    /** @return list<string> clasele din `class` (despărțite de spațiile HTML), nedecodate */
    public function clase(string $html): array
    {
        $v = $this->valoare($html, 'class');
        if ($v === null) {
            return [];
        }
        return array_values(array_filter(preg_split('/[\t\n\f\r ]+/', $v) ?: [], static fn (string $c): bool => $c !== ''));
    }
}
