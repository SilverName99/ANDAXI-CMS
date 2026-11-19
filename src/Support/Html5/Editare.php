<?php

declare(strict_types=1);

namespace App\Support\Html5;

use InvalidArgumentException;

/**
 * Editarea HTML-ului capturat doar prin înlocuiri pe intervale de octeți (Tokenizer): valoarea unui atribut, interiorul
 * unui element, ștergerea unui nod întreg sau a unui atribut, inserarea unui atribut. Restul octeților rămâne neatins:
 * nicio reserializare, nicio normalizare, nicio altă schimbare de spații, ghilimele sau litere mari. Așa lucrează și
 * importul, și salvarea din admin.
 *
 *   $e = new Editare($html);                       // tokenizează o dată
 *   $k = $e->primul('div', 'class', 'antet');      // indexul unui tag
 *   $html = $e->valoareAtribut($k, 'id', 'sus')->interior($k2, '<p>Text</p>')->aplica();
 *
 * Valorile se dau gata scăpate pentru HTML (`Editare::scapa` pentru textul unui atribut). Înlocuirile se strâng și se
 * aplică împreună, pe textul de la început; două care se suprapun sunt refuzate (InvalidArgumentException), ca o
 * editare să nu poată strica alta în tăcere.
 *
 * O editare schimbă doar octeții din intervalul ei, dar efectul ei în arbore se poate întinde dincolo de el: ștergerea unui
 * element care scotea parserul din SVG/MathML, scoaterea lui `color`/`face`/`size` dintr-un `<font>` din SVG sau a lui
 * `encoding` dintr-un `annotation-xml` pot face dintr-un text brut inert (`<style>`, `<noscript>`…) conținut viu cu
 * `<img onerror>`; la fel reconstruirea formatării sau pointerul formularului. De aceea fiecare editare care schimbă structura
 * (stergeNod, stergeAtribut, valoareAtribut, insereazaAtribut, interior, inlocuieste) se verifică: tokenii din AFARA nodului ei
 * (spațiul de nume, textul brut, perechile, tagurile ignorate/mutate) trebuie să rămână la fel; altfel editarea e refuzată. Iar
 * la materializarea octeților (aplica/inlocuiri) se verifică și efectul COMBINAT al tuturor editărilor: două bune în parte pot,
 * împreună, trece peste intervalele lor (de pildă ștergerea lui `color` ȘI a lui `face` dintr-un `<font>` din SVG).
 */
final class Editare
{
    /** @var list<Token> */
    private array $tokeni;

    /** @var list<array{0: int, 1: int, 2: string}> intervalul [start, end) și textul nou */
    private array $inlocuiri = [];

    /** @var list<array{0: int, 1: int}> nodul protejat [a, b) al fiecărei editări ACCEPTATE (verificarea contextului combinat) */
    private array $noduri = [];

    /** Nodul protejat al editării în curs: intră în $noduri doar după ce adauga() o acceptă (nu și una refuzată). */
    private ?array $nodInAsteptare = null;

    /**
     * O editare refuzată „otrăvește” toată editarea: aplica() (și inlocuiri()) aruncă mereu după aceea, nu întoarce HTML
     * neverificat (SPEC-R3-03). Ține motivul primei editări refuzate.
     */
    private ?string $otravit = null;

    /** @var array<string, int>|null șabloanele „nesigure" ale documentului de la început (calculate o dată) */
    private ?array $nesigureInainte = null;

    public function __construct(private readonly string $html)
    {
        $this->tokeni = Tokenizer::tokenizeaza($html);
    }

    /** @return list<Token> */
    public function tokeni(): array
    {
        return $this->tokeni;
    }

    public function html(): string
    {
        return $this->html;
    }

    /**
     * Indexul primului tag de deschidere cu acest nume (și, opțional, cu atributul dat egal cu valoarea dată sau, pentru
     * `class`, având clasa dată), sau null.
     */
    public function primul(string $nume, ?string $atribut = null, ?string $valoare = null): ?int
    {
        $nume = strtolower($nume);
        foreach ($this->tokeni as $k => $t) {
            if ($t->tip !== Token::DESCHIDERE || $t->nume !== $nume) {
                continue;
            }
            if ($atribut === null) {
                return $k;
            }
            $v = $t->valoare($this->html, $atribut);
            if ($v === null) {
                continue;
            }
            if ($valoare === null || (strtolower($atribut) === 'class' ? in_array($valoare, $t->clase($this->html), true) : $v === $valoare)) {
                return $k;
            }
        }
        return null;
    }

    /**
     * Valoarea unui atribut al tagului de la `$index` (`$valoare` gata scăpată). Ghilimelele rămân cele de până acum;
     * o valoare fără ghilimele primește ghilimele duble doar dacă noua valoare are nevoie de ele; un atribut fără valoare
     * primește `="…"`. Un atribut care lipsește se inserează (insereazaAtribut).
     */
    public function valoareAtribut(int $index, string $nume, string $valoare): self
    {
        $t = $this->tag($index);
        $a = $t->atribut($nume);
        if ($a === null) {
            return $this->insereazaAtribut($index, $nume, $valoare);
        }
        if ($a->ghilimele === null) {
            // Primește ghilimele duble: o ghilimea dublă în valoare ar închide atributul (ca la cele cu ghilimele, mai jos).
            if (str_contains($valoare, '"')) {
                throw new InvalidArgumentException('valoarea conține ghilimele duble: scap-o întâi (Editare::scapa)');
            }
            // Înlocuim tot numele atributului cu „nume="valoare"”, nu inserăm „="valoare"” la numeEnd: altfel o ștergere a
            // aceluiași atribut (care taie [start, numeEnd)) n-ar fi văzută ca suprapunere, iar `<input disabled>` + ștergere
            // + valoare ar da `<input="x">` (SPEC-R2-03). Octeții ieșiți sunt aceiași ca inserarea.
            $s = $a->numeStart;
            $e = $a->numeEnd;
            $text = substr($this->html, $a->numeStart, $a->numeEnd - $a->numeStart) . '="' . $valoare . '"';
        } elseif ($a->ghilimele === '') {
            $s = $a->valoareStart;
            $e = $a->valoareEnd;
            $text = ($valoare === '' || preg_match('/[\t\n\f\r "\'=<>`]/', $valoare) === 1)
                ? '"' . str_replace('"', '&quot;', $valoare) . '"' : $valoare;
        } else {
            if (str_contains($valoare, $a->ghilimele)) {
                throw new InvalidArgumentException('valoarea conține ghilimeaua atributului (' . $a->ghilimele . '): scap-o întâi (Editare::scapa)');
            }
            $s = $a->valoareStart;
            $e = $a->valoareEnd;
            $text = $valoare;
            // E2 (cost): o valoare cu ghilimele, scăpată (fără ghilimeaua atributului), a unui atribut care NU dirijează
            // construcția arborelui, se înlocuiește strict între ghilimele: nicio graniță de token nu se mișcă, deci editarea e
            // sigură în sine, fără retokenizarea întregului document pe fiecare editare. Verificarea combinată de la aplica()
            // rămâne autoritativă (nodul — doar intervalul valorii, regiune sigură — intră în uniune la adauga). Celelalte
            // căi (fără ghilimele, fără valoare, atribut dirijent, meta) păstrează verificarea întreagă.
            if (!self::atributDirijeaza($t->nume, strtolower($nume))) {
                if ($this->otravit !== null) {
                    throw new InvalidArgumentException('o editare refuzată mai devreme (' . $this->otravit . ') oprește toate editările');
                }
                $this->nodInAsteptare = [$s, $e];
                return $this->adauga($s, $e, $text);
            }
        }
        $this->cereContext($t->start, $t->end, [[$s, $e, $text]], 'valoarea atributului ' . $nume . ' al lui <' . $t->nume . '>');
        return $this->adauga($s, $e, $text);
    }

    /**
     * Atributul dirijează construcția arborelui (valoarea sau prezența lui o citește secțiunea de construcție WHATWG), deci
     * o editare a lui cere verificarea întreagă a contextului, nu calea rapidă: `type` (input), `encoding` (annotation-xml),
     * `color`/`face`/`size` (ieșirea unui `<font>` din SVG/MathML) și ORICE atribut al unui `<meta>` (codarea documentului).
     */
    private static function atributDirijeaza(string $numeElement, string $numeAtribut): bool
    {
        return $numeElement === 'meta' || in_array($numeAtribut, ['type', 'encoding', 'color', 'face', 'size'], true);
    }

    /** Interiorul elementului deschis la `$index` (între tagul lui de deschidere și cel de închidere). */
    public function interior(int $index, string $continut): self
    {
        $t = $this->tag($index);
        $pereche = Tokenizer::pereche($this->tokeni, $index);
        if ($t->tip !== Token::DESCHIDERE || $pereche === null) {
            throw new InvalidArgumentException('<' . $t->nume . '> de la octetul ' . $t->start . ' nu are un tag de închidere: interiorul nu se poate înlocui');
        }
        $s = $t->end;
        $e = $this->tokeni[$pereche]->start;
        $this->cereContext($t->start, $this->tokeni[$pereche]->end, [[$s, $e, $continut]], 'interiorul lui <' . $t->nume . '>');
        return $this->adauga($s, $e, $continut);
    }

    /**
     * Șterge nodul întreg: tagul de deschidere, conținutul și tagul de închidere, cum le vede browserul (Tokenizer::pereche);
     * un element gol, sau unul din SVG și MathML scris cu `/>`, doar tagul. Un nod al cărui sfârșit nu e un tag al lui (închis
     * implicit, neînchis, ignorat de parser, mutat de el) se refuză: ștergerea doar a deschiderii ar lăsa conținutul lui (de
     * exemplu textul brut al unui `<iframe/>`, al unui `<noscript/>`) să devină marcaj.
     */
    public function stergeNod(int $index): self
    {
        $t = $this->tag($index);
        if ($t->tip !== Token::DESCHIDERE) {
            throw new InvalidArgumentException('la octetul ' . $t->start . ' e un tag de închidere: se șterge nodul de la tagul lui de deschidere');
        }
        $pereche = Tokenizer::pereche($this->tokeni, $index);
        if ($pereche !== null) {
            $b = $this->tokeni[$pereche]->end;
            $this->cereContext($t->start, $b, [[$t->start, $b, '']], 'ștergerea nodului <' . $t->nume . '>');
            return $this->adauga($t->start, $b, '');
        }
        if (!$t->ignorat && $t->inchidere === $index) {
            $this->cereContext($t->start, $t->end, [[$t->start, $t->end, '']], 'ștergerea nodului <' . $t->nume . '>');
            return $this->adauga($t->start, $t->end, '');
        }
        throw new InvalidArgumentException('<' . $t->nume . '> de la octetul ' . $t->start . ' nu se termină cu un tag al lui (închis implicit, '
            . 'neînchis, ignorat sau mutat de parser): nodul nu se poate șterge întreg');
    }

    /**
     * Șterge un atribut, toate aparițiile numelui (și duplicatele), cu spațiile dinaintea lui. Tagul rămas trebuie să se
     * tokenizeze la fel, fără atribut: același nume, aceleași celelalte atribute cu aceleași valori, același `/>`. Altfel se
     * încearcă și alte tăieturi ale separatorilor: `<g/x=1>` nu devine `<g/>` (în SVG, auto-închis), ci `<g>`; `<a href=y x/>`
     * nu devine `<a href=y/>` (valoarea ar primi „/”), ci `<a href=y />`. Fără o tăietură bună, refuzul.
     */
    public function stergeAtribut(int $index, string $nume): self
    {
        $t = $this->tag($index);
        $nume = strtolower($nume);
        $sterse = array_values(array_filter($t->atribute, static fn (Atribut $a): bool => $a->nume === $nume));
        if ($sterse === []) {
            throw new InvalidArgumentException('<' . $t->nume . '> de la octetul ' . $t->start . ' nu are atributul ' . $nume);
        }
        $asteptat = [];
        foreach ($t->atribute as $a) {
            if ($a->nume !== $nume && !$a->duplicat) {
                $asteptat[$a->nume] = $a->valoare($this->html);
            }
        }
        foreach (['spatiiInainte', 'doarAtributul', 'separatoriiInainte', 'unSpatiu'] as $fel) {
            $intervale = array_map(fn (Atribut $a): array => $this->taietura($t, $a, $fel), $sterse);
            if ($this->tagRamas($t, $intervale, $asteptat)) {
                // Tagul se tokenizează la fel, dar atributul putea decide construcția dincolo de el (color/face/size pe un
                // <font> din SVG, encoding pe annotation-xml): verificăm că restul documentului nu se schimbă.
                $this->cereContext($t->start, $t->end, $intervale, 'ștergerea atributului ' . $nume . ' al lui <' . $t->nume . '>');
                foreach ($intervale as [$start, $end, $text]) {
                    $this->adauga($start, $end, $text);
                }
                return $this;
            }
        }
        throw new InvalidArgumentException('atributul ' . $nume . ' al lui <' . $t->nume . '> de la octetul ' . $t->start
            . ' nu se poate șterge fără să schimbe tokenizarea tagului');
    }

    /** Intervalul tăiat pentru un atribut, după fel. @return array{0: int, 1: int, 2: string} */
    private function taietura(Token $t, Atribut $a, string $fel): array
    {
        // Sfârșitul atributului de dinainte (sau al numelui tagului): separatorii stau între el și atribut.
        $dinainte = $t->numeEnd;
        foreach ($t->atribute as $alt) {
            if ($alt->end <= $a->start) {
                $dinainte = max($dinainte, $alt->end);
            }
        }
        if ($fel === 'doarAtributul') {
            return [$a->start, $a->end, ''];
        }
        if ($fel === 'separatoriiInainte') {
            return [$dinainte, $a->end, ''];
        }
        if ($fel === 'unSpatiu') {
            return [$dinainte, $a->end, ' '];
        }
        // Spațiile dinainte pleacă odată cu el doar dacă după el urmează un separator („ ”, „/”, „>”): altfel atributul
        // de după s-ar lipi de nume (`<p a="x"b="y">` → `<p b="y">`, nu `<pb="y">`).
        $start = $a->start;
        $dupa = $a->end < strlen($this->html) ? $this->html[$a->end] : '>';
        if (strpbrk($dupa, "\t\n\f\r />") !== false) {
            while ($start > $dinainte && strpbrk($this->html[$start - 1], "\t\n\f\r ") !== false) {
                $start--;
            }
        }
        return [$start, $a->end, ''];
    }

    /**
     * Tagul, cu tăieturile date, se tokenizează la fel, fără atributele șterse?
     *
     * @param list<array{0: int, 1: int, 2: string}> $intervale
     * @param array<string, string> $asteptat
     */
    private function tagRamas(Token $t, array $intervale, array $asteptat): bool
    {
        usort($intervale, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        $nou = '';
        $poz = $t->start;
        foreach ($intervale as [$start, $end, $text]) {
            if ($start < $poz) {
                return false;
            }
            $nou .= substr($this->html, $poz, $start - $poz) . $text;
            $poz = $end;
        }
        $nou .= substr($this->html, $poz, $t->end - $poz);
        $tok = Tokenizer::tokenizeaza($nou)[0] ?? null;
        if ($tok === null || $tok->tip !== $t->tip || $tok->nume !== $t->nume || $tok->end !== strlen($nou) || $tok->complet !== $t->complet
            || $tok->autoInchis !== $t->autoInchis) {
            return false;
        }
        $ramase = [];
        foreach ($tok->atribute as $a) {
            if (!$a->duplicat) {
                $ramase[$a->nume] = $a->valoare($nou);
            }
        }
        return $ramase === $asteptat;
    }

    /** Inserează un atribut la sfârșitul tagului (înainte de `>` sau `/>`): ` nume="valoare"` (`$valoare` null: doar numele). */
    public function insereazaAtribut(int $index, string $nume, ?string $valoare): self
    {
        $t = $this->tag($index);
        if (preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $nume) !== 1) {
            throw new InvalidArgumentException('nume de atribut nevalid: ' . $nume);
        }
        if (!$t->complet) {
            throw new InvalidArgumentException('<' . $t->nume . '> de la octetul ' . $t->start . ' nu se termină: nu primește atribute');
        }
        if ($valoare !== null && str_contains($valoare, '"')) {
            throw new InvalidArgumentException('valoarea conține ghilimele: scap-o întâi (Editare::scapa)');
        }
        $poz = $t->end - 1;
        if ($t->autoInchis && substr($this->html, $poz - 1, 1) === '/') {
            $poz--;
        }
        // Spațiile dinaintea „>” rămân după atributul nou (octeții lor nu se mută).
        while ($poz > $t->numeEnd && strpbrk($this->html[$poz - 1], "\t\n\f\r ") !== false) {
            $poz--;
        }
        $text = ' ' . strtolower($nume) . ($valoare === null ? '' : '="' . $valoare . '"');
        $this->cereContext($t->start, $t->end, [[$poz, $poz, $text]], 'inserarea atributului ' . $nume . ' la <' . $t->nume . '>');
        return $this->adauga($poz, $poz, $text);
    }

    /**
     * Înlocuiește un interval oarecare (pentru unelte care își calculează singure intervalul, din tokeni).
     */
    public function inlocuieste(int $start, int $end, string $text): self
    {
        if ($start < 0 || $end < $start || $end > strlen($this->html)) {
            throw new InvalidArgumentException('interval nevalid [' . $start . ', ' . $end . ')');
        }
        $this->cereContext($start, $end, [[$start, $end, $text]], 'înlocuirea intervalului [' . $start . ', ' . $end . ')');
        return $this->adauga($start, $end, $text);
    }

    /**
     * Refuză editarea dacă schimbă tokenizarea din AFARA nodului [$a, $b): altfel, un efect al construcției dincolo de interval
     * (ieșirea din SVG/MathML, un punct de integrare, un text brut devenit viu) ar trece în tăcere. @param list<array{0:int,1:int,2:string}> $intervale
     */
    private function cereContext(int $a, int $b, array $intervale, string $ce): void
    {
        if ($this->otravit !== null) {
            throw new InvalidArgumentException('o editare refuzată mai devreme (' . $this->otravit . ') oprește toate editările');
        }
        if (!$this->pastreazaContext($a, $b, $intervale)) {
            $this->otravit = $ce . ' (contextul editării)';
            throw new InvalidArgumentException($ce . ' schimbă tokenizarea din afara nodului (spațiul de nume, textul brut sau '
                . 'perechile din afara lui) ori introduce o nesiguranță de tokenizare: editarea e refuzată');
        }
        // Nodul protejat al acestei editări intră în $noduri DOAR după ce adauga() o acceptă (nodInAsteptare): o editare
        // refuzată de adauga (suprapunere) nu mai lărgește uniunea protejată a verificării combinate (SPEC-R3-03, D). Două
        // editări fiecare bună în parte (un `<font color face>` din SVG rămâne breakout cât are un atribut) pot, împreună,
        // face viu un text brut — de aceea verificarea combinată de la aplica() rulează mereu pe rezultatul final.
        $this->nodInAsteptare = [$a, $b];
    }

    /**
     * Toate editările împreună păstrează tokenizarea din afara nodurilor lor? Verificarea pe editare (cereContext) compară cu
     * documentul de la început plus acea singură editare, deci două editări bune în parte pot, combinate, trece peste intervalele
     * lor (ștergerea lui `color` ȘI a lui `face` dintr-un `<font>` din SVG: fiecare lasă breakout-ul cât timp mai e un atribut,
     * dar amândouă îl scot și un `<style>` de după devine viu). Se compară semnătura tokenilor din afara reuniunii nodurilor,
     * pe documentul de la început și pe cel cu TOATE editările. Rulează o dată (idempotent), la materializarea octeților.
     */
    private function verificaContextCombinat(): void
    {
        // O editare deja refuzată nu lasă niciodată aplica() să întoarcă HTML neverificat (SPEC-R3-03, C): aruncă mereu.
        if ($this->otravit !== null) {
            throw new InvalidArgumentException('o editare a fost refuzată (' . $this->otravit . '); aplica() nu întoarce HTML neverificat');
        }
        // O singură editare e deja verificată autoritativ de cereContext (ori e calea rapidă sigură în sine); verificarea
        // combinată contează doar de la două editări. Rulează MEREU pe rezultatul final (fără „verificat deja”): altfel o a
        // doua aplica() după editări în plus ar sări verificarea (SPEC-R3-03, B).
        if (count($this->noduri) < 2) {
            return;
        }
        $intervale = $this->intervaleSortate();
        $nou = '';
        $poz = 0;
        foreach ($intervale as [$s, $e, $text]) {
            $nou .= substr($this->html, $poz, $s - $poz) . $text;
            $poz = $e;
        }
        $nou .= substr($this->html, $poz);
        // Marginea unui nod nu cade niciodată în interiorul unei înlocuiri (înlocuirile din interiorul unui nod se suprapun cu
        // ștergerea lui și sunt deja refuzate de adauga), deci deplasarea ei e suma delta-urilor înlocuirilor terminate până la ea.
        $map = static function (int $x) use ($intervale): int {
            $delta = 0;
            foreach ($intervale as [$s, $e, $text]) {
                if ($e <= $x) {
                    $delta += strlen($text) - ($e - $s);
                }
            }
            return $x + $delta;
        };
        $dupa = Tokenizer::analizeaza($nou);
        if ($this->introduceNesigur($dupa['nesigure'])) {
            $this->otravit = 'context combinat (nesiguranță)';
            throw new InvalidArgumentException('editările combinate introduc o nesiguranță de tokenizare pe care documentul de la '
                . 'început n-o avea (browserele l-ar tokeniza altfel): refuzate.');
        }
        $nouNoduri = array_map(static fn (array $ab): array => [$map($ab[0]), $map($ab[1])], $this->noduri);
        if (self::semnaturaInAfaraUniunii($this->tokeni, $this->html, $this->noduri)
            !== self::semnaturaInAfaraUniunii($dupa['tokeni'], $nou, $nouNoduri)) {
            $this->otravit = 'context combinat (tokenizare în afara nodurilor)';
            throw new InvalidArgumentException('editările combinate schimbă tokenizarea din afara nodurilor lor (spațiul de nume, '
                . 'textul brut sau perechile): împreună, trec peste intervalele lor. Refuzate.');
        }
    }

    /**
     * Semnătura tokenilor din afara reuniunii nodurilor (niciun nod nu-i cuprinde): ca semnaturaInAfara, dar pentru mai multe
     * noduri deodată. @param list<Token> $tokeni @param list<array{0:int,1:int}> $noduri
     * @return list<array{0:string,1:string,2:string,3:string,4:bool,5:bool,6:bool,7:string}>
     */
    private static function semnaturaInAfaraUniunii(array $tokeni, string $html, array $noduri): array
    {
        $sig = [];
        foreach ($tokeni as $t) {
            if ($t->tip === Token::TEXT && $t->brut === '') {
                continue;
            }
            $inAfara = true;
            foreach ($noduri as [$a, $b]) {
                if (!($t->end <= $a || $t->start >= $b)) {
                    $inAfara = false;
                    break;
                }
            }
            if ($inAfara) {
                $sig[] = [$t->tip, $t->nume, $t->brut, $t->spatiu, $t->ignorat, $t->mutat, $t->inchidereProprie, $t->octeti($html)];
            }
        }
        return $sig;
    }

    /**
     * Tokenii din afara nodului [$a, $b) sunt aceiași după ce se aplică doar intervalele acestei editări (pe textul de la
     * început)? Se compară semnătura fiecărui token dinainte de `$a` și de după `$b` (deplasat cu delta): tip, nume, text brut,
     * spațiul de nume, ignorat/mutat, dacă are pereche proprie și octeții lui. @param list<array{0:int,1:int,2:string}> $intervale
     */
    private function pastreazaContext(int $a, int $b, array $intervale): bool
    {
        usort($intervale, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        $nou = '';
        $poz = 0;
        $delta = 0;
        foreach ($intervale as [$s, $e, $text]) {
            if ($s < $poz || $s < 0 || $e < $s || $e > strlen($this->html)) {
                return false;
            }
            $nou .= substr($this->html, $poz, $s - $poz) . $text;
            $poz = $e;
            $delta += strlen($text) - ($e - $s);
        }
        $nou .= substr($this->html, $poz);
        $dupa = Tokenizer::analizeaza($nou);
        if ($this->introduceNesigur($dupa['nesigure'])) {
            return false;
        }
        return self::semnaturaInAfara($this->tokeni, $this->html, $a, $b)
            === self::semnaturaInAfara($dupa['tokeni'], $nou, $a, $b + $delta);
    }

    /**
     * Editarea introduce o nesiguranță de tokenizare pe care documentul de la început n-o avea (`Constructie::$nesigure`: o
     * abatere Blink, CDATA într-un punct de integrare, o stivă prea adâncă, un BOM UTF-16…)? Browserele ar tokeniza altfel
     * rezultatul, deci efectul editării nu e bine definit în afara intervalului ei, chiar dacă semnătura modelului de aici nu se
     * schimbă (modelul urmează WHATWG). Se compară șabloanele (fără octeții din mesaj): cele de după ⊆ cele de la început.
     * @param list<string> $dupaNesigure
     */
    private function introduceNesigur(array $dupaNesigure): bool
    {
        $inainte = $this->nesigureInainte ??= self::sabloaneNesigure(Tokenizer::analizeaza($this->html)['nesigure']);
        foreach (self::sabloaneNesigure($dupaNesigure) as $cheie => $n) {
            if ($n > ($inainte[$cheie] ?? 0)) {
                return true;
            }
        }
        return false;
    }

    /** Multiset-ul șabloanelor „nesigure" (octeții din mesaj scoși), ca să se compare peste deplasările de offset.
     * @param list<string> $motive @return array<string, int> */
    private static function sabloaneNesigure(array $motive): array
    {
        $sab = [];
        foreach ($motive as $m) {
            $cheie = (string) preg_replace('/\d+/', '#', $m);
            $sab[$cheie] = ($sab[$cheie] ?? 0) + 1;
        }
        return $sab;
    }

    /** @param list<Token> $tokeni @return list<array{0:string,1:string,2:string,3:string,4:bool,5:bool,6:bool,7:string}> */
    private static function semnaturaInAfara(array $tokeni, string $html, int $a, int $b): array
    {
        $sig = [];
        foreach ($tokeni as $t) {
            // Textul obișnuit (nu text brut) se poate contopi inofensiv cu vecinii la marginea intervalului, fără să fie marcaj;
            // contează tagurile, comentariile, CDATA și textul brut (care ar putea deveni marcaj viu).
            if ($t->tip === Token::TEXT && $t->brut === '') {
                continue;
            }
            if ($t->end <= $a || $t->start >= $b) {
                $sig[] = [$t->tip, $t->nume, $t->brut, $t->spatiu, $t->ignorat, $t->mutat, $t->inchidereProprie, $t->octeti($html)];
            }
        }
        return $sig;
    }

    /** @return list<array{0: int, 1: int, 2: string}> înlocuirile strânse, în ordinea textului */
    public function inlocuiri(): array
    {
        $this->verificaContextCombinat();
        return $this->intervaleSortate();
    }

    /** @return list<array{0: int, 1: int, 2: string}> înlocuirile în ordinea textului, fără verificarea combinată (uz intern). */
    private function intervaleSortate(): array
    {
        $rez = $this->inlocuiri;
        usort($rez, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $rez;
    }

    /** Textul cu toate înlocuirile aplicate; octeții din afara intervalelor lor, exact cei de la început. */
    public function aplica(): string
    {
        $rez = '';
        $poz = 0;
        foreach ($this->inlocuiri() as [$start, $end, $text]) {
            $rez .= substr($this->html, $poz, $start - $poz) . $text;
            $poz = $end;
        }
        return $rez . substr($this->html, $poz);
    }

    /** Text pentru valoarea unui atribut cu ghilimele duble: `&`, `"`, `<`, `>` scăpate (octeții UTF-8 rămân). */
    public static function scapa(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false);
    }

    private function tag(int $index): Token
    {
        $t = $this->tokeni[$index] ?? null;
        if ($t === null || !$t->esteTag()) {
            throw new InvalidArgumentException('la indexul ' . $index . ' nu e un tag');
        }
        return $t;
    }

    private function adauga(int $start, int $end, string $text): self
    {
        foreach ($this->inlocuiri as [$s, $e]) {
            // Două intervale se suprapun dacă au octeți comuni; două inserări în același punct, sau o inserare în interiorul
            // unui interval înlocuit, la fel.
            $suprapus = $start === $end || $s === $e
                ? ($start === $end && $s === $e ? $start === $s : ($start === $end ? $start > $s && $start < $e : $s > $start && $s < $end))
                : $start < $e && $s < $end;
            if ($suprapus) {
                // Editare refuzată: otrăvește tot (aplica() nu mai întoarce HTML neverificat) și nu lărgește uniunea (SPEC-R3-03, D).
                $this->otravit ??= 'înlocuire suprapusă [' . $start . ', ' . $end . ') cu [' . $s . ', ' . $e . ')';
                $this->nodInAsteptare = null;
                throw new InvalidArgumentException('înlocuirea [' . $start . ', ' . $end . ') se suprapune cu [' . $s . ', ' . $e . ')');
            }
        }
        $this->inlocuiri[] = [$start, $end, $text];
        // Nodul protejat al acestei editări intră în uniune abia acum, după ce editarea a fost acceptată.
        if ($this->nodInAsteptare !== null) {
            $this->noduri[] = $this->nodInAsteptare;
            $this->nodInAsteptare = null;
        }
        return $this;
    }
}
