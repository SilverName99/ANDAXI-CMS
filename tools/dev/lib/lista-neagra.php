<?php

declare(strict_types=1);

/**
 * Lista neagră a numelor de client (de-bioscem §7.4): termenii care nu au voie să apară în nucleu (verificarea 11 din
 * tools/dev/verifica-rute.php) și în ce arată pachetul neutru (tools/dev/test-pachet-site.php neutru).
 *
 * Termenii firmei (numele, marca, conferința, sediul, CUI-ul, numărul de la Registrul Comerțului, produsele) sunt scriși
 * aici. Datele unei persoane (reprezentantul și telefonul operatorului de pe acordul GDPR) nu intră în git (OD-49): se citesc
 * la rulare din valorile private ale înghețării, `site-uri/<site>/privat/setari-inghetate.json`, scrise local de
 * tools/inghetare/genereaza-setari.php; fără ele (o clonă fără privat/) se verifică doar termenii firmei.
 *
 * Lista explicită (termenii lista neagră ai unui site, ținuți în `privat/` ca datele lui personale): numele, domeniile,
 * telefoanele și adresele de email ale clienților care încă n-au pachet sau ale site-urilor care se adoptă, câte unul pe rând
 * (`#` începe un comentariu), în `site-uri/<site>/privat/lista-neagra.txt` (ignorat de git) și în fișierele din
 * `CMS_DEV_LISTA_NEAGRA` (căi în afara repo-ului, despărțite prin „:”). Termenii ei nu au voie să apară nicăieri în git
 * (verificarea 11b din tools/dev/verifica-rute.php), nu doar în nucleu, nici în mesajele commiturilor nepublicate.
 *
 * Fail-closed (IG-01): fiecare pachet din `site-uri/` al unui site care se adoptă (toate în afara celor cu numele public,
 * LISTA_NEAGRA_PACHETE_PUBLICE) cere o listă care să-l acopere (lista_neagra_acoperire); fără ea 11b pică.
 */

const LISTA_NEAGRA_FIRMA = ['bioscem', 'amalbo', 'neurocare', 'rațiu', 'ratiu', '19166493', 'j2006017724407', 'quinton', 'vivomixx',
    'siliciu organic', 'info pacienți', 'info medici', 'vitalitate și protecție', 'jurnalul bioscem'];

/**
 * Pachetele site-urilor al căror nume e public (ale proprietarului platformei, numite deja în repo: `site-uri/bioscem/`, …): nu
 * cer o listă neagră privată. Orice alt pachet din `site-uri/` e al unui site care se adoptă, cu numele lui privat: 11b cere lista
 * lui (`site-uri/<site>/privat/lista-neagra.txt` sau un fișier din `CMS_DEV_LISTA_NEAGRA` cu rândul `# site: <site>`).
 */
const LISTA_NEAGRA_PACHETE_PUBLICE = ['bioscem', 'biovitality', 'vertij', 'grafoanaytis'];

/** Cheile private ale înghețării din care se iau termenii personali. */
const LISTA_NEAGRA_CHEI_PRIVATE = ['gdpr_operator_reprezentant', 'gdpr_operator_telefon'];

/** Cuvintele unei funcții, nu ale unui nume (din „Director General <Nume> <Prenume>”). */
const LISTA_NEAGRA_FUNCTII = ['director', 'general', 'generală', 'administrator', 'manager', 'președinte', 'presedinte', 'reprezentant', 'legal'];

/**
 * Termenii personali din privat/ (fiecare cuvânt al numelui, cu și fără diacritice; telefonul întreg), cu litere mici.
 *
 * @return list<string>
 */
function lista_neagra_private(string $radacina): array
{
    $termeni = [];
    foreach (glob($radacina . '/site-uri/*/privat/setari-inghetate.json') ?: [] as $fisier) {
        $date = json_decode((string) @file_get_contents($fisier), true);
        foreach (LISTA_NEAGRA_CHEI_PRIVATE as $cheie) {
            $valoare = trim((string) ($date['setari'][$cheie] ?? ''));
            if ($valoare === '') {
                continue;
            }
            if ($cheie === 'gdpr_operator_telefon') {
                $termeni[] = mb_strtolower($valoare);
                continue;
            }
            foreach (preg_split('/[\s,]+/u', $valoare) ?: [] as $cuvant) {
                $cuvant = mb_strtolower($cuvant);
                if (mb_strlen($cuvant) < 3 || in_array($cuvant, LISTA_NEAGRA_FUNCTII, true)) {
                    continue;
                }
                $termeni[] = $cuvant;
                $termeni[] = strtr($cuvant, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
            }
        }
    }
    return array_values(array_unique($termeni));
}

/**
 * Fișierele listei explicite: `site-uri/<site>/privat/lista-neagra.txt` și căile din `CMS_DEV_LISTA_NEAGRA` (despărțite prin „:”).
 *
 * @return list<string>
 */
function lista_neagra_fisiere_explicite(string $radacina): array
{
    $fisiere = glob($radacina . '/site-uri/*/privat/lista-neagra.txt') ?: [];
    foreach (explode(':', (string) getenv('CMS_DEV_LISTA_NEAGRA')) as $cale) {
        if (trim($cale) !== '') {
            $fisiere[] = trim($cale);
        }
    }
    return array_values(array_unique($fisiere));
}

/**
 * Pachetele din `site-uri/` care cer o listă neagră privată (toate în afara LISTA_NEAGRA_PACHETE_PUBLICE) → fișierele listei
 * explicite care le acoperă: un fișier acoperă pachetul X dacă stă la `…/site-uri/X/privat/lista-neagra.txt` (în clona asta sau în
 * alta) ori are un rând de comentariu `# site: X` (mai multe pachete, despărțite prin virgulă). Un pachet cu lista goală = neacoperit.
 *
 * @return array<string, list<string>>
 */
function lista_neagra_acoperire(string $radacina): array
{
    $rez = [];
    foreach (glob($radacina . '/site-uri/*', GLOB_ONLYDIR) ?: [] as $dosar) {
        if (!in_array(basename($dosar), LISTA_NEAGRA_PACHETE_PUBLICE, true)) {
            $rez[basename($dosar)] = [];
        }
    }
    foreach (lista_neagra_fisiere_explicite($radacina) as $fisier) {
        $pachete = [];
        if (preg_match('#(?:^|/)site-uri/([^/]+)/privat/lista-neagra\.txt$#', $fisier, $m) === 1) {
            $pachete[] = $m[1];
        }
        $text = @file_get_contents($fisier);
        foreach (is_string($text) ? (preg_split('/\R/u', $text) ?: []) : [] as $rand) {
            if (preg_match('/^#\s*site:\s*(.+)$/iu', trim($rand), $m) === 1) {
                foreach (explode(',', $m[1]) as $p) {
                    $pachete[] = trim($p);
                }
            }
        }
        foreach (array_unique($pachete) as $p) {
            if (isset($rez[$p])) {
                $rez[$p][] = $fisier;
            }
        }
    }
    ksort($rez, SORT_STRING);
    return $rez;
}

/**
 * Termenii listei explicite (lista_neagra_fisiere_explicite), cu litere mici: câte unul pe rând, fără rândurile goale și fără
 * comentarii; un termen sub 3 caractere se ignoră (ar prinde orice). Un fișier dat care nu se poate citi oprește verificarea:
 * o listă privată lipsă nu trebuie să treacă drept „nicio potrivire”.
 *
 * @return list<string>
 */
function lista_neagra_explicita(string $radacina): array
{
    $termeni = [];
    foreach (lista_neagra_fisiere_explicite($radacina) as $fisier) {
        $text = @file_get_contents($fisier);
        if (!is_string($text)) {
            throw new RuntimeException('lista neagră privată ' . $fisier . ' nu se poate citi (CMS_DEV_LISTA_NEAGRA sau privat/lista-neagra.txt)');
        }
        foreach (preg_split('/\R/u', $text) ?: [] as $rand) {
            $rand = trim($rand);
            if ($rand === '' || str_starts_with($rand, '#') || mb_strlen($rand) < 3) {
                continue;
            }
            $termeni[] = mb_strtolower($rand);
        }
    }
    return array_values(array_unique($termeni));
}

/** @return list<string> toată lista: termenii firmei, apoi cei personali (din privat/ și din lista explicită, dacă există). */
function lista_neagra(string $radacina): array
{
    return array_values(array_unique(array_merge(LISTA_NEAGRA_FIRMA, lista_neagra_private($radacina), lista_neagra_explicita($radacina))));
}

/** Arhivele și PDF-urile noi pe ramură primite după o privire de om (verificarea 11b nu le poate citi). */
const LISTA_NEAGRA_BINARE_PERMISE = [];

/** Un text fără litere mari și fără spații, puncte, cratime și liniuțe jos (forma în care se caută numele și domeniile, 11b). */
function lista_neagra_normal(string $t): string
{
    return (string) preg_replace('/[\s._\-]+/u', '', mb_strtolower($t));
}

/** Partea națională a unui număr de telefon (cifrele, fără +40, 0040 sau 0 în față), sau null dacă nu arată a telefon. */
function lista_neagra_telefon(string $t): ?string
{
    if (preg_match('/^[+\d][\d\s.\-()\/]*$/', trim($t)) !== 1) {
        return null;
    }
    $c = (string) preg_replace('/\D/', '', $t);
    $c = (string) preg_replace('/^(?:0040|40|0)/', '', $c);
    return strlen($c) >= 8 ? $c : null;
}

/**
 * Potrivirile termenilor listei explicite într-un text (verificarea 11b, recenzia 2, R3-1): ca un cuvânt întreg; numele și
 * domeniile și în forma normală (fără litere mari, spații, puncte, cratime, liniuțe jos: „Vila X”, „www.x.ro”, „site-uri/x/”),
 * ca subșir; telefoanele după cifre (orice separatori; +40, 0040 și 0 echivalente).
 *
 * @param list<string> $termeni
 * @return array<string, int> termenul → câte potriviri
 */
function lista_neagra_gaseste(string $text, array $termeni): array
{
    // Și formele decodate (recenzia 3, R3-1; recenzia 4): escape-urile JSON (`\u0070`), entitățile HTML și codarea procent
    // (`%20`, `&#112;`, `&period;`) nu ascund un termen, nici codate de două ori (`%2570`, `&amp;#112;`).
    $forma = $text;
    for ($i = 0; $i < 2; $i++) {
        $decodat = rawurldecode(html_entity_decode(lista_neagra_json_u($forma), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($decodat === $forma) {
            break;
        }
        $text .= "\n" . $decodat;
        $forma = $decodat;
    }
    $rez = [];
    $normal = null;
    $telefoane = null;
    foreach ($termeni as $termen) {
        $n = (int) preg_match_all(lista_neagra_tipar($termen), $text);
        $tel = lista_neagra_telefon($termen);
        if ($tel !== null) {
            if ($telefoane === null) {
                preg_match_all('/[+\d][\d\s.\-()\/]{6,}\d/', $text, $m);
                $telefoane = array_values(array_filter(array_map('lista_neagra_telefon', $m[0])));
            }
            foreach ($telefoane as $t) {
                $n += str_contains($t, $tel) ? 1 : 0;
            }
        } else {
            $tn = lista_neagra_normal($termen);
            if (mb_strlen($tn) >= 4) {
                $normal ??= lista_neagra_normal($text);
                $n = max($n, mb_substr_count($normal, $tn));
            }
        }
        if ($n > 0) {
            $rez[$termen] = $n;
        }
    }
    return $rez;
}

/** Expresia unui termen ca un cuvânt întreg, fără deosebire de litere mari (aceeași în verificarea 11 și 11b). */
/** Escape-urile `\uXXXX` din JSON (cu perechile surogat), decodate; restul textului neatins. */
function lista_neagra_json_u(string $t): string
{
    if (!str_contains($t, '\\u')) {
        return $t;
    }
    return (string) preg_replace_callback('/\\\\u(d[89ab][0-9a-f]{2})\\\\u(d[c-f][0-9a-f]{2})|\\\\u([0-9a-f]{4})/i', static function (array $m): string {
        $cod = ($m[3] ?? '') !== '' ? hexdec($m[3]) : 0x10000 + ((hexdec($m[1]) - 0xD800) << 10) + (hexdec($m[2]) - 0xDC00);
        return $cod >= 0xD800 && $cod <= 0xDFFF ? '' : (string) mb_chr((int) $cod, 'UTF-8');
    }, $t);
}

function lista_neagra_tipar(string $termen): string
{
    return '/(?<![\p{L}\p{N}])' . preg_quote($termen, '/') . '(?![\p{L}\p{N}])/iu';
}
