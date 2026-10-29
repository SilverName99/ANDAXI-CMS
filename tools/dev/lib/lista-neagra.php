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
 */

const LISTA_NEAGRA_FIRMA = ['bioscem', 'amalbo', 'neurocare', 'rațiu', 'ratiu', '19166493', 'j2006017724407', 'quinton', 'vivomixx',
    'siliciu organic', 'info pacienți', 'info medici', 'vitalitate și protecție', 'jurnalul bioscem'];

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

/** @return list<string> toată lista: termenii firmei, apoi cei personali (din privat/, dacă există). */
function lista_neagra(string $radacina): array
{
    return array_values(array_unique(array_merge(LISTA_NEAGRA_FIRMA, lista_neagra_private($radacina))));
}
