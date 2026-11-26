<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\MailPoet;

use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;
use App\Support\ImportWordPress\Continut\Divi4Taiere;
use App\Support\ImportWordPress\Continut\Taiere;
use App\Support\ImportWordPress\PlanCapturat;
use Throwable;

/**
 * Autorul din paginile MailPoet capturate (pista E, E-LEG-08; VC-MP-25): pe un site WordPress cu tema Divi, pagina MailPoet (un articol
 * de tipul `mailpoet_page`, creat de contul care a instalat pluginul) are sub titlu linia `p.post-meta` a temei, cu numele afișat al
 * acelui cont, adesea chiar adresa lui de email, și o legătură spre pagina lui de autor (`/author/…/`). Cadrele P și R sunt servite
 * oricui vine dintr-un email vechi, deci importul scoate autorul din ele și păstrează data, exact ca tema cu autorul oprit din
 * opțiuni: `<p class="post-meta"><span class="published">…</span></p>`.
 *
 * Regula, pe octeții documentului servit (T), pentru fiecare document capturat a cărui adresă începe cu `/?mailpoet_page=`:
 *  - fiecare `<p class="post-meta">` (clasa scrisă exact așa) care începe cu un spațiu, un cuvânt („de”), un spațiu și
 *    `<span class="author vcard">`, în care stă doar o legătură `<a>` spre `/author/…` cu un text simplu: dispar cuvântul, spanul
 *    autorului și separatorul ` | ` de după el; dacă după autor nu mai rămâne nimic, dispare tot paragraful;
 *  - paragraful trebuie să stea, cu aceiași octeți, o singură dată în T și o singură dată în bucățile documentului (`inainte`,
 *    `continut`, `dupa`), în afara regiunilor; ancorele scripturilor, începuturile regiunilor și ale formularelor de după el se mută
 *    cu diferența de lungime;
 *  - dovada: bucățile tăierii noi refac exact T-ul nou (Divi4Taiere::refa), iar în T nu mai rămâne niciun `/author/` și niciun
 *    `class="author vcard"`. Orice altă formă (un autor într-un loc neașteptat, o legătură spre autor în afara liniei) e un NU, cu
 *    adresa documentului: importul nu scrie o pagină care ar arăta adresa unui cont.
 *
 * P1 la import (ScriereCapturata) verifică apoi tăierea nouă de la cap la coadă, ca pe oricare alta.
 */
final class CadreMailPoet
{
    public const PREFIX_CALE = '/?mailpoet_page=';
    public const FEL_EDITARE = 'autor-ascuns';

    /**
     * Scoate autorul din toate documentele MailPoet ale capturii (în loc, în `$pc->documente`). Întoarce problemele (NU-uri), câte
     * una pe document; [] fără documente MailPoet sau fără autor în ele.
     *
     * @return list<string>
     */
    public static function ascundeAutorul(?PlanCapturat $pc): array
    {
        $probleme = [];
        foreach ($pc?->documente ?? [] as $cheie => $doc) {
            $cale = (string) ($doc['valori_import']['cale'] ?? $doc['cale'] ?? '');
            if (!str_starts_with($cale, self::PREFIX_CALE) || !($doc['taiere'] ?? null) instanceof Taiere) {
                continue;
            }
            $rez = self::dinTaiere($doc['taiere']);
            if (is_string($rez)) {
                $probleme[] = 'pagina MailPoet capturată ' . $cale . ': ' . $rez;
                continue;
            }
            $pc->documente[$cheie]['taiere'] = $rez;
        }
        return $probleme;
    }

    /** Tăierea fără autor (aceeași, dacă nu are autor), sau motivul refuzului. */
    public static function dinTaiere(Taiere $t): Taiere|string
    {
        $editate = 0;
        while (($gasit = self::urmatorulAutor($t->html)) !== null) {
            if (is_string($gasit)) {
                return $gasit;
            }
            $t = self::inlocuieste($t, $gasit[0], $gasit[1], $gasit[2]);
            if (is_string($t)) {
                return $t;
            }
            if (++$editate > 20) {
                return 'prea multe linii cu autorul (eroare internă)';
            }
        }
        if (str_contains($t->html, '/author/') || str_contains($t->html, 'class="author vcard"')) {
            return 'are o legătură spre pagina unui autor în afara liniei p.post-meta (forma ei nu e cunoscută): autorul nu se poate ascunde, documentul nu se scrie';
        }
        return $t;
    }

    /**
     * Primul paragraf `p.post-meta` cu autor din T: [început, sfârșit, octeții noi ai paragrafului], null dacă nu mai e niciunul, sau
     * motivul refuzului (o formă necunoscută).
     *
     * @return array{0: int, 1: int, 2: string}|string|null
     */
    private static function urmatorulAutor(string $html): array|string|null
    {
        try {
            $tk = Tokenizer::tokenizeaza($html);
        } catch (Throwable $e) {
            return 'documentul nu se poate citi (' . get_class($e) . ')';
        }
        foreach ($tk as $i => $p) {
            if ($p->tip !== Token::DESCHIDERE || $p->nume !== 'p' || $p->valoare($html, 'class') !== 'post-meta') {
                continue;
            }
            $sfarsitP = Tokenizer::pereche($tk, $i);
            $cuvant = $tk[$i + 1] ?? null;
            $span = $tk[$i + 2] ?? null;
            if ($span === null || $span->tip !== Token::DESCHIDERE || $span->nume !== 'span' || $span->valoare($html, 'class') !== 'author vcard') {
                continue; // o linie fără autor (doar data, categoriile): rămâne cum e
            }
            if ($sfarsitP === null || $cuvant === null || $cuvant->tip !== Token::TEXT
                || preg_match('/^ [^\s<]+ $/uD', $cuvant->octeti($html)) !== 1) {
                return 'linia p.post-meta are autorul într-o formă necunoscută (înaintea lui trebuie să stea „ <cuvânt> ”): nu se poate ascunde';
            }
            $sfarsitSpan = Tokenizer::pereche($tk, $i + 2);
            $a = $tk[$i + 3] ?? null;
            $text = $tk[$i + 4] ?? null;
            $inchideA = $tk[$i + 5] ?? null;
            if ($sfarsitSpan !== $i + 6 || $a === null || $a->tip !== Token::DESCHIDERE || $a->nume !== 'a'
                || !str_contains((string) $a->valoare($html, 'href'), '/author/') || $text === null || $text->tip !== Token::TEXT
                || $inchideA === null || $inchideA->tip !== Token::INCHIDERE || $inchideA->nume !== 'a') {
                return 'în spanul autorului din linia p.post-meta nu stă doar legătura spre pagina autorului: forma nu e cunoscută, nu se poate ascunde';
            }
            $start = $p->start;
            $end = $tk[$sfarsitP]->end;
            $taieDe = $cuvant->start;
            $taiePana = $tk[$sfarsitSpan]->end;
            if (substr($html, $taiePana, 3) === ' | ') {
                $taiePana += 3;
            }
            $rest = substr($html, $taiePana, $tk[$sfarsitP]->start - $taiePana);
            $nou = trim($rest) === '' ? '' : substr($html, $start, $taieDe - $start) . substr($html, $taiePana, $end - $taiePana);
            return [$start, $end, $nou];
        }
        return null;
    }

    /** Tăierea cu paragraful `[start, end)` din T înlocuit cu `$nou`, în T și în bucata lui, cu dovada. */
    private static function inlocuieste(Taiere $t, int $start, int $end, string $nou): Taiere|string
    {
        $vechi = substr($t->html, $start, $end - $start);
        if (substr_count($t->html, $vechi) !== 1) {
            return 'linia p.post-meta cu autorul apare de mai multe ori în document: nu se poate ascunde';
        }
        $bucati = ['inainte' => $t->inainte, 'continut' => $t->continut, 'dupa' => $t->dupa];
        $unde = [];
        foreach ($bucati as $nume => $b) {
            $n = substr_count($b, $vechi);
            if ($n > 0) {
                $unde[$nume] = $n;
            }
        }
        if (array_sum($unde) !== 1) {
            return 'linia p.post-meta cu autorul nu stă o singură dată, cu aceiași octeți, într-o bucată a documentului (e într-un fragment sau poartă '
                . 'un jeton): nu se poate ascunde';
        }
        $delta = strlen($nou) - strlen($vechi);
        $muta = static fn (int $poz): int => $poz >= $end ? $poz + $delta : $poz;
        $ancore = $t->ancore;
        foreach ($ancore as $loc => $poz) {
            if ($poz > $start && $poz < $end) {
                return 'o ancoră a scripturilor stă în linia p.post-meta (eroare internă)';
            }
            $ancore[$loc] = $muta((int) $poz);
        }
        $regiuni = [];
        foreach ($t->regiuni as $r) {
            $rs = (int) $r['start'];
            if ($rs < $end && $rs + strlen((string) $r['octeti']) > $start) {
                return 'linia p.post-meta cu autorul stă într-o regiune dinamică (' . $r['regiune'] . '): nu se poate ascunde';
            }
            $r['start'] = $muta($rs);
            $regiuni[] = $r;
        }
        $formulare = [];
        foreach ($t->formulare as $f) {
            foreach (['start', 'end'] as $k) {
                if (isset($f[$k]) && is_int($f[$k])) {
                    $f[$k] = $muta($f[$k]);
                }
            }
            $formulare[] = $f;
        }
        $bucataNume = (string) array_key_first($unde);
        $bucati[$bucataNume] = str_replace($vechi, $nou, $bucati[$bucataNume]);
        $html = substr($t->html, 0, $start) . $nou . substr($t->html, $end);
        $editari = $t->editari;
        // Intervalul editării e în T-ul de dinaintea ei (celelalte editări sunt în documentul dialectului; doar numărul pe fel contează).
        $editari[] = ['fel' => self::FEL_EDITARE, 'start' => $start, 'end' => $end, 'text' => $nou];
        $noua = new Taiere($html, $editari, $ancore, $t->htmlAtribute, $t->cap, $t->bodyAtribute, $bucati['inainte'], $bucati['continut'], $bucati['dupa'],
            $t->fragmente, $t->valori, $t->meniu, $t->date, $t->seo, $t->seoMotiv, $t->sectiuni, $formulare, $t->galerii, $t->probleme, $t->avertismente,
            $regiuni);
        try {
            $refacut = Divi4Taiere::refa($noua);
        } catch (Throwable $e) {
            return 'dovada după ascunderea autorului: ' . $e->getMessage();
        }
        if ($refacut !== $html) {
            return 'dovada după ascunderea autorului: bucățile nu refac documentul';
        }
        foreach ($regiuni as $r) {
            if (substr($html, (int) $r['start'], strlen((string) $r['octeti'])) !== (string) $r['octeti']) {
                return 'dovada după ascunderea autorului: regiunea ' . $r['regiune'] . ':' . $r['n'] . ' nu mai e la locul ei (eroare internă)';
            }
        }
        return $noua;
    }
}
