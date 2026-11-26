<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use App\Support\Brand;
use App\Support\Plugins;
use App\Support\Tema;
use PDO;

/**
 * Șabloanele automatizărilor (fereastra „Automatizare nouă”): fluxurile obișnuite, gata făcute, cu emailurile lor scrise
 * (texte de pornire, de schimbat), în culorile temei site-ului. O automatizare făcută dintr-un șablon e o ciornă: se
 * citește, se schimbă și abia apoi se pornește.
 *
 * Emailurile au cheia lor locală (`e1`, `e2`): Flux::creeaza le face și pune id-urile adevărate în pașii „Trimite email”.
 */
final class Sabloane
{
    /** @return array<string, array{titlu: string, descriere: string, declansator: string}> */
    public static function lista(): array
    {
        $toate = [
            'gol' => ['titlu' => 'Flux gol', 'descriere' => 'Alegi tu declanșatorul și pașii.', 'declansator' => 'abonat_confirmat'],
            'bun_venit' => ['titlu' => 'Bun venit', 'descriere' => 'Abonat nou confirmat → email de bun venit → după 3 zile, un al doilea email.',
                'declansator' => 'abonat_confirmat'],
            'cos_abandonat' => ['titlu' => 'Coș abandonat', 'descriere' => 'După o oră: amintirea coșului; după o zi, dacă n-a cumpărat, un cod de reducere.',
                'declansator' => 'cos_abandonat'],
            'multumire' => ['titlu' => 'Mulțumire după prima comandă', 'descriere' => 'Prima comandă finalizată → după 3 zile, mulțumire și un cod pentru următoarea.',
                'declansator' => 'comanda_finalizata'],
            'recastigare' => ['titlu' => 'Recâștigă clienții inactivi', 'descriere' => '90 de zile fără comandă → cod de reducere; după 7 zile, dacă nu a apăsat, încă o amintire.',
                'declansator' => 'client_inactiv'],
        ];
        $disponibile = Catalog::declansatoareDisponibile();
        return array_filter($toate, static fn (array $s): bool => array_key_exists($s['declansator'], $disponibile));
    }

    private static function culoare(string $nume): string
    {
        return (string) (Tema::newsletter()['culori'][$nume] ?? Tema::CULORI_NEWSLETTER[$nume]);
    }

    /** Blocurile unui email simplu: logo, titlu, text, (cod de reducere), buton. */
    public static function blocuriEmail(?PDO $db, array $setari, string $titlu, string $text, string $buton, string $adresa, bool $cuCod = false): array
    {
        $blocuri = [
            ['type' => 'logo', 'image_url' => '', 'width' => 180, 'align' => 'center', 'link_url' => '/', 'background' => '#ffffff',
                'text_color' => self::culoare('principala'), 'font_size' => 26, 'padding_top' => 20, 'padding_bottom' => 16],
            ['type' => 'header', 'content' => $titlu, 'align' => 'center', 'background' => self::culoare('principala'), 'text_color' => '#ffffff',
                'font_size' => 28, 'font_size_mobile' => 22, 'padding_top' => 26, 'padding_bottom' => 26, 'padding_left' => 20, 'padding_right' => 20],
            ['type' => 'text', 'content' => $text, 'align' => 'left', 'background' => '#ffffff', 'text_color' => self::culoare('text'),
                'font_size' => 16, 'font_size_mobile' => 15, 'padding_top' => 20, 'padding_bottom' => 12, 'padding_left' => 28, 'padding_right' => 28],
        ];
        if ($cuCod) {
            $blocuri[] = ['type' => 'coupon', 'code' => '{{cod_reducere}}', 'content' => 'Codul tău de reducere ({{cod_reducere_valoare}}):',
                'note' => 'Valabil o singură dată, până la {{cod_reducere_expira}}.', 'align' => 'center', 'background' => '#ffffff',
                'text_color' => self::culoare('text'), 'font_size' => 24, 'border_color' => self::culoare('principala'),
                'code_background' => self::culoare('fundal_deschis'), 'code_color' => self::culoare('inchisa'), 'padding_top' => 8, 'padding_bottom' => 18];
        }
        $blocuri[] = ['type' => 'button', 'label' => $buton, 'url' => $adresa, 'align' => 'center', 'background' => '#ffffff',
            'button_color' => self::culoare('principala'), 'text_color' => '#ffffff', 'border_radius' => 6, 'font_size' => 16,
            'btn_pad_v' => 13, 'btn_pad_h' => 28, 'padding_top' => 16, 'padding_bottom' => 26];
        return $blocuri;
    }

    /**
     * Un șablon, gata de pus în bază: nume, declanșator, setări, pașii (arborele editorului) și emailurile.
     *
     * @return array{nume: string, declansator: string, setari: array, pasi: list<array>, emailuri: array<string, array>}|null
     */
    public static function sablon(string $cheie, ?PDO $db, array $setari): ?array
    {
        if (!array_key_exists($cheie, self::lista())) {
            return null;
        }
        $magazin = Brand::numeSite($setari);
        $spreMagazin = Plugins::vizibil('catalog') ? '/magazin' : '/';
        $email = static fn (string $nume, string $subiect, string $titlu, string $text, string $buton, string $adresa, bool $cod = false): array
            => ['nume' => $nume, 'subiect' => $subiect, 'blocuri' => self::blocuriEmail($db, $setari, $titlu, $text, $buton, $adresa, $cod)];
        switch ($cheie) {
            case 'gol':
                return ['nume' => 'Automatizare nouă', 'declansator' => 'abonat_confirmat', 'setari' => [], 'pasi' => [], 'emailuri' => []];
            case 'bun_venit':
                return [
                    'nume' => 'Bun venit abonaților noi', 'declansator' => 'abonat_confirmat', 'setari' => ['lista_id' => 0],
                    'emailuri' => [
                        'e1' => $email('Bun venit', 'Bine ai venit, {{prenume}}!', 'Bine ai venit!',
                            "Salut, {{prenume}}!\n\nMulțumim că te-ai abonat la noutățile {{magazin}}. Îți scriem rar și doar cu lucruri utile: produse noi, sfaturi și oferte pentru abonați.",
                            'Vezi magazinul', $spreMagazin),
                        'e2' => $email('Bun venit — al doilea email', 'Ce îți recomandăm, {{prenume}}', 'Câteva recomandări',
                            "Salut din nou, {{prenume}}!\n\nAm pregătit pentru tine câteva dintre produsele preferate ale clienților noștri.",
                            'Descoperă produsele', $spreMagazin),
                    ],
                    'pasi' => [
                        ['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']],
                        ['cheie' => 'p2', 'tip' => 'asteapta', 'setari' => ['cat' => 3, 'unitate' => 'zile', 'doar_ziua' => true, 'ora_de' => 9, 'ora_pana' => 20]],
                        ['cheie' => 'p3', 'tip' => 'email', 'setari' => ['email' => 'e2']],
                    ],
                ];
            case 'cos_abandonat':
                $cuCod = Plugins::vizibil('cupoane');
                $pasiNu = $cuCod
                    ? [['cheie' => 'p5', 'tip' => 'cod_reducere', 'setari' => ['tip' => 'percent', 'valoare' => 10, 'zile' => 3, 'prefix' => 'COS']],
                        ['cheie' => 'p6', 'tip' => 'email', 'setari' => ['email' => 'e2']]]
                    : [['cheie' => 'p6', 'tip' => 'email', 'setari' => ['email' => 'e2']]];
                return [
                    'nume' => 'Coș abandonat', 'declansator' => 'cos_abandonat', 'setari' => ['dupa' => 1, 'unitate' => 'ore'],
                    'emailuri' => [
                        'e1' => $email('Amintirea coșului', 'Ai uitat ceva în coș, {{prenume}}?', 'Produsele te așteaptă',
                            "Salut, {{prenume}}!\n\nAi lăsat câteva produse în coș. Le-am păstrat pentru tine: termini comanda în câteva clicuri.",
                            'Înapoi la coș', '/cos'),
                        'e2' => $email('Coș abandonat — cu reducere', $cuCod ? 'O reducere pentru coșul tău' : 'Coșul tău încă te așteaptă',
                            $cuCod ? '{{cod_reducere_valoare}} reducere pentru tine' : 'Coșul tău încă te așteaptă',
                            "Salut, {{prenume}}!\n\nCoșul tău e încă aici." . ($cuCod ? ' Ca să-ți fie mai ușor, ai o reducere valabilă câteva zile.' : ''),
                            'Termină comanda', '/cos', $cuCod),
                    ],
                    'pasi' => [
                        ['cheie' => 'p1', 'tip' => 'email', 'setari' => ['email' => 'e1']],
                        ['cheie' => 'p2', 'tip' => 'asteapta', 'setari' => ['cat' => 1, 'unitate' => 'zile']],
                        ['cheie' => 'p3', 'tip' => 'daca', 'setari' => ['conditie' => 'a_cumparat', 'de_la_intrare' => true],
                            'da' => [], 'nu' => $pasiNu],
                    ],
                ];
            case 'multumire':
                $cuCod = Plugins::vizibil('cupoane');
                $pasi = [['cheie' => 'p1', 'tip' => 'asteapta', 'setari' => ['cat' => 3, 'unitate' => 'zile', 'doar_ziua' => true, 'ora_de' => 9, 'ora_pana' => 20]]];
                if ($cuCod) {
                    $pasi[] = ['cheie' => 'p2', 'tip' => 'cod_reducere', 'setari' => ['tip' => 'percent', 'valoare' => 10, 'zile' => 30, 'prefix' => 'MULTUMIM']];
                }
                $pasi[] = ['cheie' => 'p3', 'tip' => 'email', 'setari' => ['email' => 'e1']];
                return [
                    'nume' => 'Mulțumire după prima comandă', 'declansator' => 'comanda_finalizata', 'setari' => ['doar_prima' => true],
                    'emailuri' => [
                        'e1' => $email('Mulțumire', 'Mulțumim pentru prima comandă, {{prenume}}!', 'Mulțumim!',
                            "Salut, {{prenume}}!\n\nÎți mulțumim pentru prima comandă la {{magazin}}. Sperăm că te bucuri de produse."
                            . ($cuCod ? ' Pentru următoarea comandă ai o reducere doar pentru tine.' : ''),
                            'Vezi magazinul', $spreMagazin, $cuCod),
                    ],
                    'pasi' => $pasi,
                ];
            case 'recastigare':
                $cuCod = Plugins::vizibil('cupoane');
                $pasi = [];
                if ($cuCod) {
                    $pasi[] = ['cheie' => 'p1', 'tip' => 'cod_reducere', 'setari' => ['tip' => 'percent', 'valoare' => 15, 'zile' => 14, 'prefix' => 'REVINO']];
                }
                $pasi[] = ['cheie' => 'p2', 'tip' => 'email', 'setari' => ['email' => 'e1']];
                $pasi[] = ['cheie' => 'p3', 'tip' => 'asteapta', 'setari' => ['cat' => 7, 'unitate' => 'zile']];
                $pasi[] = ['cheie' => 'p4', 'tip' => 'daca', 'setari' => ['conditie' => 'a_dat_clic', 'email_pas' => 'p2'],
                    'da' => [['cheie' => 'p5', 'tip' => 'eticheta_adauga', 'setari' => ['eticheta_id' => $db instanceof PDO
                        && \App\Support\NewsletterEtichete::disponibil($db) ? \App\Support\NewsletterEtichete::idDupaNume($db, 'Reactivat') : 0]]],
                    'nu' => [['cheie' => 'p6', 'tip' => 'email', 'setari' => ['email' => 'e2']]]];
                return [
                    'nume' => 'Recâștigă clienții inactivi', 'declansator' => 'client_inactiv', 'setari' => ['zile' => 90],
                    'emailuri' => [
                        'e1' => $email('Ne e dor de tine', 'Ne e dor de tine, {{prenume}}', 'Ne e dor de tine!',
                            "Salut, {{prenume}}!\n\nA trecut ceva vreme de la ultima ta comandă. Avem produse noi pe care credem că le-ai plăcea."
                            . ($cuCod ? ' Și o reducere, ca să revii mai ușor.' : ''),
                            'Vezi noutățile', $spreMagazin, $cuCod),
                        'e2' => $email('Ultima amintire', $cuCod ? 'Reducerea ta expiră curând' : 'Te așteptăm înapoi', 'Te așteptăm înapoi',
                            "Salut, {{prenume}}!\n\n" . ($cuCod ? 'Codul tău {{cod_reducere}} e valabil până la {{cod_reducere_expira}}.' : 'Ne-ar plăcea să te revedem.'),
                            'Mergi la magazin', $spreMagazin),
                    ],
                    'pasi' => $pasi,
                ];
        }
        return null;
    }
}
