<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use App\Support\Plugins;

/**
 * Ce poate face o automatizare (docs/PLUGINURI.md, „Automatizări newsletter”): declanșatoarele, pașii și condițiile pașului
 * „Dacă / altfel”, cu numele lor în română și curățarea setărilor venite din editor. Orice setare care ajunge în bază trece
 * pe aici: un tip necunoscut e refuzat, un număr e adus între limitele lui, o listă de id-uri rămâne doar cu numere.
 */
final class Catalog
{
    /**
     * Declanșatoarele, în ordinea din editor (macheta). `pluginuri`: ce trebuie să fie pe site ca declanșatorul să se poată
     * alege (fără magazin, nicio comandă; fără conturi de clienți, niciun cont nou).
     */
    public const DECLANSATOARE = [
        'comanda_finalizata' => ['eticheta' => 'Comanda e livrată', 'lung' => 'Comanda e livrată / finalizată', 'pluginuri' => ['magazin']],
        'comanda_plasata' => ['eticheta' => 'Comandă plasată', 'lung' => 'Comandă plasată', 'pluginuri' => ['magazin']],
        'abonat_confirmat' => ['eticheta' => 'Abonat nou (confirmat)', 'lung' => 'Abonat nou, după confirmarea adresei', 'pluginuri' => []],
        'cos_abandonat' => ['eticheta' => 'Coș abandonat', 'lung' => 'Coș abandonat', 'pluginuri' => ['magazin']],
        'client_inactiv' => ['eticheta' => 'Client inactiv de X zile', 'lung' => 'Client fără comandă de X zile', 'pluginuri' => ['magazin']],
        'cont_creat' => ['eticheta' => 'Cont nou creat', 'lung' => 'Cont nou de client', 'pluginuri' => ['conturi_clienti']],
        'eticheta_primita' => ['eticheta' => 'Primește o etichetă', 'lung' => 'Abonatul primește o etichetă', 'pluginuri' => []],
    ];

    /** Pașii, pe grupurile ferestrei „+” (macheta: Timp și condiții, Mesaje, Abonat). */
    public const PASI = [
        'asteapta' => ['eticheta' => 'Așteaptă', 'grup' => 'timp'],
        'daca' => ['eticheta' => 'Dacă / altfel', 'grup' => 'timp'],
        'email' => ['eticheta' => 'Trimite email', 'grup' => 'mesaje'],
        'anunta' => ['eticheta' => 'Anunță-mă pe email', 'grup' => 'mesaje'],
        'cod_reducere' => ['eticheta' => 'Cod de reducere', 'grup' => 'mesaje'],
        'eticheta_adauga' => ['eticheta' => 'Adaugă etichetă', 'grup' => 'abonat'],
        'eticheta_scoate' => ['eticheta' => 'Scoate eticheta', 'grup' => 'abonat'],
        'lista_muta' => ['eticheta' => 'Mută în listă', 'grup' => 'abonat'],
        'lista_adauga' => ['eticheta' => 'Adaugă în listă', 'grup' => 'abonat'],
    ];

    public const GRUPURI = [
        'timp' => 'Timp și condiții',
        'mesaje' => 'Mesaje',
        'abonat' => 'Abonat',
    ];

    /** Condițiile pașului „Dacă / altfel” (puține, ca fiecare să fie testată). */
    public const CONDITII = [
        'a_deschis' => 'A deschis emailul anterior',
        'a_dat_clic' => 'A apăsat un link din email',
        'a_cumparat' => 'A cumpărat',
        'total_minim' => 'Valoarea comenzii cel puțin…',
        'are_eticheta' => 'Are eticheta…',
        'in_lista' => 'E în lista…',
    ];

    public const UNITATI = ['minute' => 60, 'ore' => 3600, 'zile' => 86400, 'saptamani' => 604800];

    /** Codurile care se pot pune în subiectul și în textul unui email al fluxului (Trimitere::inlocuiesteCodurile). */
    public const CODURI_EMAIL = [
        '{{prenume}}' => 'prenumele (sau „acolo”, dacă nu-l știm)',
        '{{email}}' => 'adresa abonatului',
        '{{cod_reducere}}' => 'codul de reducere generat mai sus în flux',
        '{{cod_reducere_valoare}}' => 'reducerea codului (ex. 10% sau 20 lei)',
        '{{cod_reducere_expira}}' => 'data până la care e valabil codul',
        '{{numar_comanda}}' => 'numărul comenzii care a pornit fluxul',
        '{{total_comanda}}' => 'totalul comenzii',
        '{{magazin}}' => 'numele magazinului',
    ];

    public const MAX_PASI = 100;
    public const MAX_ADANCIME = 12;

    /** Declanșatoarele care se pot alege acum pe site (pluginurile lor sunt pe site). @return array<string, array> */
    public static function declansatoareDisponibile(): array
    {
        $disponibile = [];
        foreach (self::DECLANSATOARE as $cheie => $definitie) {
            $ok = true;
            foreach ($definitie['pluginuri'] as $plugin) {
                if (!Plugins::vizibil($plugin)) {
                    $ok = false;
                }
            }
            if ($ok) {
                $disponibile[$cheie] = $definitie;
            }
        }
        return $disponibile;
    }

    /** Pașii care se pot alege acum (codul de reducere doar cu pluginul cupoanelor). @return array<string, array> */
    public static function pasiDisponibili(): array
    {
        $pasi = self::PASI;
        if (!Plugins::vizibil('cupoane')) {
            unset($pasi['cod_reducere']);
        }
        return $pasi;
    }

    /** @return list<int> numere pozitive, fără dubluri, cel mult `$max` */
    public static function iduri(mixed $valoare, int $max = 50): array
    {
        if (is_string($valoare)) {
            $valoare = preg_split('/[\s,]+/', $valoare) ?: [];
        }
        if (!is_array($valoare)) {
            return [];
        }
        $iduri = [];
        foreach ($valoare as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $n = (int) $v;
                if ($n > 0 && !in_array($n, $iduri, true)) {
                    $iduri[] = $n;
                }
            }
            if (count($iduri) >= $max) {
                break;
            }
        }
        return $iduri;
    }

    private static function intre(mixed $valoare, int $min, int $max, int $implicit): int
    {
        if (!is_numeric($valoare)) {
            return $implicit;
        }
        return max($min, min($max, (int) $valoare));
    }

    private static function text(mixed $valoare, int $max): string
    {
        $text = is_scalar($valoare) ? (string) $valoare : '';
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text) ?? '';
        return mb_substr(trim($text), 0, $max);
    }

    private static function bifa(mixed $valoare): bool
    {
        return $valoare === true || $valoare === 1 || $valoare === '1' || $valoare === 'on' || $valoare === 'true';
    }

    /** Setările unui declanșator, curățate. */
    public static function setariDeclansator(string $declansator, array $cfg): array
    {
        return match ($declansator) {
            'abonat_confirmat' => ['lista_id' => self::intre($cfg['lista_id'] ?? 0, 0, PHP_INT_MAX, 0)],
            'comanda_plasata' => [
                'produse' => self::iduri($cfg['produse'] ?? []),
                'categorii' => self::iduri($cfg['categorii'] ?? []),
            ],
            'comanda_finalizata' => [
                'produse' => self::iduri($cfg['produse'] ?? []),
                'categorii' => self::iduri($cfg['categorii'] ?? []),
                'doar_prima' => self::bifa($cfg['doar_prima'] ?? false),
            ],
            'cos_abandonat' => (static function () use ($cfg): array {
                $unitate = in_array($cfg['unitate'] ?? '', ['minute', 'ore'], true) ? (string) $cfg['unitate'] : 'minute';
                $max = $unitate === 'ore' ? 168 : 10080;
                return ['dupa' => self::intre($cfg['dupa'] ?? 60, $unitate === 'ore' ? 1 : 15, $max, $unitate === 'ore' ? 1 : 60),
                    'unitate' => $unitate];
            })(),
            'client_inactiv' => ['zile' => self::intre($cfg['zile'] ?? 90, 7, 730, 90)],
            'cont_creat' => [],
            'eticheta_primita' => ['eticheta_id' => self::intre($cfg['eticheta_id'] ?? 0, 0, PHP_INT_MAX, 0)],
            default => [],
        };
    }

    /** Minutele după care un coș e „abandonat” (setările declanșatorului). */
    public static function minuteCos(array $cfg): int
    {
        $dupa = (int) ($cfg['dupa'] ?? 60);
        return ($cfg['unitate'] ?? 'minute') === 'ore' ? $dupa * 60 : $dupa;
    }

    /**
     * Setările unui pas, curățate (tipul e deja verificat).
     *
     * @return array<string, mixed>
     */
    public static function setariPas(string $tip, array $cfg): array
    {
        switch ($tip) {
            case 'asteapta':
                $unitate = array_key_exists((string) ($cfg['unitate'] ?? ''), self::UNITATI) ? (string) $cfg['unitate'] : 'zile';
                $de = self::intre($cfg['ora_de'] ?? 9, 0, 23, 9);
                $pana = self::intre($cfg['ora_pana'] ?? 20, 1, 24, 20);
                if ($pana <= $de) {
                    [$de, $pana] = [9, 20];
                }
                return ['cat' => self::intre($cfg['cat'] ?? 1, 1, 1000, 1), 'unitate' => $unitate,
                    'doar_ziua' => self::bifa($cfg['doar_ziua'] ?? false), 'ora_de' => $de, 'ora_pana' => $pana];
            case 'daca':
                $conditie = array_key_exists((string) ($cfg['conditie'] ?? ''), self::CONDITII) ? (string) $cfg['conditie'] : 'a_deschis';
                $curat = ['conditie' => $conditie];
                if (in_array($conditie, ['a_deschis', 'a_dat_clic'], true)) {
                    $pas = (string) ($cfg['email_pas'] ?? '');
                    $curat['email_pas'] = preg_match('/^[a-z0-9_]{1,20}$/', $pas) === 1 ? $pas : '';
                } elseif ($conditie === 'a_cumparat') {
                    $curat['produse'] = self::iduri($cfg['produse'] ?? []);
                    $curat['categorii'] = self::iduri($cfg['categorii'] ?? []);
                    $curat['de_la_intrare'] = self::bifa($cfg['de_la_intrare'] ?? true);
                } elseif ($conditie === 'total_minim') {
                    $valoare = is_numeric($cfg['valoare'] ?? null) ? round((float) $cfg['valoare'], 2) : 0.0;
                    $curat['valoare'] = max(0.0, min(1000000.0, $valoare));
                } elseif ($conditie === 'are_eticheta') {
                    $curat['eticheta_id'] = self::intre($cfg['eticheta_id'] ?? 0, 0, PHP_INT_MAX, 0);
                } elseif ($conditie === 'in_lista') {
                    $curat['lista_id'] = self::intre($cfg['lista_id'] ?? 0, 0, PHP_INT_MAX, 0);
                }
                return $curat;
            case 'email':
                return ['email_id' => self::intre($cfg['email_id'] ?? 0, 0, PHP_INT_MAX, 0)];
            case 'anunta':
                $adrese = [];
                foreach (preg_split('/[\s,;]+/', self::text($cfg['destinatari'] ?? '', 500)) ?: [] as $adresa) {
                    if ($adresa !== '' && filter_var($adresa, FILTER_VALIDATE_EMAIL) && count($adrese) < 5) {
                        $adrese[] = strtolower($adresa);
                    }
                }
                return ['destinatari' => implode(', ', array_unique($adrese)),
                    'subiect' => self::text($cfg['subiect'] ?? '', 190),
                    'mesaj' => self::text($cfg['mesaj'] ?? '', 2000)];
            case 'cod_reducere':
                $tipCod = in_array($cfg['tip'] ?? '', ['percent', 'fixed'], true) ? (string) $cfg['tip'] : 'percent';
                $valoare = is_numeric($cfg['valoare'] ?? null) ? round((float) $cfg['valoare'], 2) : 10.0;
                $valoare = $tipCod === 'percent' ? max(1.0, min(90.0, $valoare)) : max(1.0, min(10000.0, $valoare));
                $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($cfg['prefix'] ?? '')) ?? '');
                return ['tip' => $tipCod, 'valoare' => $valoare, 'zile' => self::intre($cfg['zile'] ?? 14, 1, 365, 14),
                    'prefix' => substr($prefix, 0, 8), 'cumulabil' => self::bifa($cfg['cumulabil'] ?? false)];
            case 'eticheta_adauga':
            case 'eticheta_scoate':
                return ['eticheta_id' => self::intre($cfg['eticheta_id'] ?? 0, 0, PHP_INT_MAX, 0)];
            case 'lista_adauga':
                return ['lista_id' => self::intre($cfg['lista_id'] ?? 0, 0, PHP_INT_MAX, 0)];
            case 'lista_muta':
                return ['lista_id' => self::intre($cfg['lista_id'] ?? 0, 0, PHP_INT_MAX, 0),
                    'din_lista_id' => self::intre($cfg['din_lista_id'] ?? 0, 0, PHP_INT_MAX, 0)];
        }
        return [];
    }

    /** Câte secunde are o așteptare. */
    public static function secundeAsteptare(array $cfg): int
    {
        return max(60, (int) ($cfg['cat'] ?? 1) * (self::UNITATI[(string) ($cfg['unitate'] ?? 'zile')] ?? 86400));
    }

    /** Textul scurt al unei reduceri („10%”, „20 lei”). */
    public static function textReducere(string $tip, float $valoare): string
    {
        $numar = rtrim(rtrim(number_format($valoare, 2, ',', '.'), '0'), ',');
        return $tip === 'percent' ? $numar . '%' : $numar . ' lei';
    }
}
