<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use App\Support\BugetTrimitere;
use App\Support\NewsletterEtichete;
use App\Support\NewsletterService;
use App\Support\Plugins;
use App\Support\TrimitereNeconfirmata;
use PDO;
use Throwable;

/**
 * Motorul automatizărilor: o trecere a cronului newsletterului (scripts/newsletter-campaigns.php, după campanii, sub același
 * lacăt și cu ce a rămas din același buget de trimiteri).
 *
 *  1. rulările revendicate de un proces mort (peste 15 minute) se eliberează;
 *  2. cu pluginul pornit, intră coșurile abandonate și clienții inactivi (declanșatoarele din cron);
 *  3. rulările scadente ale automatizărilor active se iau pe rând (revendicate), fiecare dusă cât se poate: un pas după altul,
 *     până la o așteptare, capătul fluxului sau o ieșire. Cel mult `lot` rulări pe trecere și `pasi_pe_rulare` pași pe rulare.
 *
 * Fiecare pas e idempotent: un email se rezervă („pending”, unic pe rulare și pas) înaintea trimiterii, deci o rulare reluată
 * după o cădere nu-l mai trimite (un „pending” rămas e tratat ca „unknown”: poate a plecat, nu se retrimite, ca la campanii);
 * codul de reducere e același la a doua încercare (din semnătura rulării); etichetele și listele sunt operații care se pot
 * repeta. Cu bugetul terminat, rularea rămâne la pasul ei, scadentă, pentru trecerea următoare.
 *
 * O automatizare oprită (sau ciornă) nu-și mișcă rulările: ele stau unde sunt până la repornire. Cât pluginul „se oprește”,
 * rulările în curs merg mai departe, dar nu mai intră nimeni.
 */
final class Motor
{
    public const LOT = 200;
    public const PASI_PE_RULARE = 30;
    public const MINUTE_REINCERCARE = 15;
    public const ESECURI_LA_RAND = 5;
    /** Cât așteaptă o comandă plătită cu cardul sau prin OP să fie confirmată, înainte să iasă din flux. */
    public const ZILE_ASTEPTARE_PLATA = 3;

    /** @var array<int, array<string, mixed>> automatizările citite în trecerea asta */
    private static array $automatizari = [];
    /** @var array<int, array<string, array<string, mixed>>> */
    private static array $pasi = [];
    private static int $esecuriLaRand = 0;
    private static bool $opreste = false;

    /**
     * O trecere. `$optiuni`: `lot`, `cad_dupa_rezervare` (doar teste: „cade” după ce rezervă un email, înainte să-l trimită
     * sau să-l marcheze, ca la un proces omorât).
     *
     * @return array<string, int>
     */
    public static function ruleaza(PDO $db, array $setari, BugetTrimitere $buget, array $optiuni = []): array
    {
        $raport = ['eliberate' => 0, 'cosuri' => 0, 'inactivi' => 0, 'rulari' => 0, 'trimise' => 0, 'esuate' => 0, 'iesite' => 0, 'terminate' => 0];
        if (!Plugins::vizibil('automatizari') || !Flux::disponibil($db)) {
            return $raport;
        }
        self::$automatizari = [];
        self::$pasi = [];
        self::$esecuriLaRand = 0;
        self::$opreste = false;

        $raport['eliberate'] = Rulari::elibereazaRevendicariVechi($db);
        if (Plugins::activ('automatizari')) {
            try {
                $raport['cosuri'] = Declansatoare::scaneazaCosuri($db);
                $raport['inactivi'] = Declansatoare::scaneazaInactivi($db);
            } catch (Throwable $e) {
                error_log('Automatizari: scanarea a eșuat (' . get_class($e) . ')');
            }
        }

        $lot = max(1, min(1000, (int) ($optiuni['lot'] ?? self::LOT)));
        $scadente = $db->prepare(
            "SELECT r.id FROM automatizari_rulari r JOIN automatizari a ON a.id = r.automatizare_id AND a.stare = 'activa'
             WHERE r.stare = 'activa' AND r.urmatoarea_la <= :acum
             ORDER BY r.urmatoarea_la ASC, r.id ASC LIMIT {$lot}"
        );
        $scadente->execute(['acum' => Ceas::sql()]);
        foreach (array_map('intval', $scadente->fetchAll(PDO::FETCH_COLUMN) ?: []) as $id) {
            if (self::$opreste || $buget->timpExpirat()) {
                break;
            }
            $jeton = Rulari::revendica($db, $id);
            if ($jeton === null) {
                continue;
            }
            $raport['rulari']++;
            try {
                self::duce($db, $id, $jeton, $setari, $buget, $raport, $optiuni);
            } catch (CadereSimulata $e) {
                throw $e;
            } catch (Throwable $e) {
                // O greșeală neașteptată: rularea rămâne la pasul ei și se reia peste 15 minute (nu în buclă).
                error_log('Automatizari: rularea ' . $id . ' a eșuat (' . get_class($e) . ' la ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
                $rulare = Rulari::citeste($db, $id);
                if ($rulare !== null) {
                    Rulari::jurnal($db, $rulare['automatizare_id'], $id, $rulare['pas_curent'] !== null ? (string) $rulare['pas_curent'] : null, 'eroare', get_class($e));
                    Rulari::lasaPana($db, $rulare, Ceas::sql(Ceas::acum()->modify('+' . self::MINUTE_REINCERCARE . ' minutes')), $jeton, true);
                }
            }
        }
        return $raport;
    }

    private static function automatizare(PDO $db, int $id): ?array
    {
        if (!array_key_exists($id, self::$automatizari)) {
            self::$automatizari[$id] = Flux::citeste($db, $id);
            self::$pasi[$id] = Flux::pasi($db, $id);
        }
        return self::$automatizari[$id];
    }

    /** Duce o rulare revendicată cât se poate. */
    private static function duce(PDO $db, int $id, string $jeton, array $setari, BugetTrimitere $buget, array &$raport, array $optiuni): void
    {
        for ($i = 0; $i < self::PASI_PE_RULARE; $i++) {
            $rulare = Rulari::citeste($db, $id);
            if ($rulare === null || (string) $rulare['stare'] !== 'in_lucru' || (string) $rulare['revendicata_de'] !== $jeton) {
                return;
            }
            $automatizare = self::automatizare($db, $rulare['automatizare_id']);
            if ($automatizare === null) {
                Rulari::iese($db, $rulare, 'automatizare_stearsa', $jeton);
                return;
            }
            $cheie = $rulare['pas_curent'] !== null ? (string) $rulare['pas_curent'] : null;
            if ($cheie === null) {
                Rulari::muta($db, $rulare, null, $jeton);
                $raport['terminate']++;
                return;
            }
            $pas = self::$pasi[$rulare['automatizare_id']][$cheie] ?? null;
            if ($pas === null) {
                Rulari::iese($db, $rulare, 'pas_sters', $jeton);
                $raport['iesite']++;
                return;
            }
            // „Comandă plasată”: comanda anulată (sau ștearsă) iese înaintea oricărui pas, oricând (nu se mulțumește pentru o
            // comandă anulată după o așteptare); cu plata încă neconfirmată (card, OP) fluxul pornește abia după confirmare.
            if ((string) $automatizare['declansator'] === 'comanda_plasata' && !empty($rulare['context']['plata_ok'])) {
                $plata = self::verificaPlata($db, $rulare);
                if ($plata === 'comanda_anulata' || $plata === 'comanda_stearsa') {
                    Rulari::iese($db, $rulare, $plata, $jeton);
                    $raport['iesite']++;
                    return;
                }
            }
            if ((string) $automatizare['declansator'] === 'comanda_plasata' && empty($rulare['context']['plata_ok'])) {
                $plata = self::verificaPlata($db, $rulare);
                if ($plata === 'asteapta') {
                    Rulari::lasaPana($db, $rulare, Ceas::sql(Ceas::acum()->modify('+' . self::MINUTE_REINCERCARE . ' minutes')), $jeton);
                    return;
                }
                if ($plata !== 'ok') {
                    Rulari::iese($db, $rulare, $plata, $jeton);
                    $raport['iesite']++;
                    return;
                }
                $rulare['context']['plata_ok'] = 1;
                Rulari::salveazaContext($db, $id, $rulare['context']);
            }

            $rezultat = self::executa($db, $automatizare, $rulare, $pas, $jeton, $setari, $buget, $raport, $optiuni);
            switch ($rezultat[0]) {
                case 'urmator':
                    Rulari::muta($db, $rulare, $rezultat[1], $jeton);
                    if ($rezultat[1] === null) {
                        $raport['terminate']++;
                        return;
                    }
                    break;
                case 'asteapta':
                    Rulari::lasaPana($db, $rulare, (string) $rezultat[1], $jeton);
                    return;
                case 'reincearca':
                    Rulari::lasaPana($db, $rulare, (string) $rezultat[1], $jeton, true);
                    return;
                case 'buget':
                    // Bugetul trecerii s-a terminat: rularea rămâne scadentă, pentru trecerea următoare.
                    Rulari::lasaPana($db, $rulare, Ceas::sql(), $jeton);
                    self::$opreste = true;
                    return;
                case 'iese':
                    Rulari::iese($db, $rulare, (string) $rezultat[1], $jeton);
                    $raport['iesite']++;
                    return;
            }
        }
        // Prea mulți pași într-o trecere (nu se întâmplă într-un flux fără bucle): restul la trecerea următoare.
        $rulare = Rulari::citeste($db, $id);
        if ($rulare !== null && (string) $rulare['revendicata_de'] === $jeton) {
            Rulari::lasaPana($db, $rulare, Ceas::sql(), $jeton);
        }
    }

    /** 'ok', 'asteapta' sau motivul ieșirii. */
    private static function verificaPlata(PDO $db, array $rulare): string
    {
        $comanda = Declansatoare::comanda($db, (int) ($rulare['comanda_id'] ?? 0));
        if ($comanda === null) {
            return 'comanda_stearsa';
        }
        if (in_array($comanda['status'], Declansatoare::COMENZI_NULE, true)) {
            return 'comanda_anulata';
        }
        if ($comanda['status'] === 'pending_payment') {
            $limita = strtotime((string) $rulare['intrata_la'] . ' +' . self::ZILE_ASTEPTARE_PLATA . ' days');
            return $limita !== false && Ceas::acum()->getTimestamp() < $limita ? 'asteapta' : 'plata_neconfirmata';
        }
        return 'ok';
    }

    /**
     * Un pas. Întoarce ['urmator', ?cheie], ['asteapta', ora], ['reincearca', ora], ['buget'] sau ['iese', motiv].
     *
     * @return array{0: string, 1?: mixed}
     */
    private static function executa(PDO $db, array $automatizare, array $rulare, array $pas, string $jeton, array $setari,
        BugetTrimitere $buget, array &$raport, array $optiuni): array
    {
        $s = $pas['setari'];
        switch ($pas['tip']) {
            case 'asteapta':
                $scadenta = self::scadentaAsteptare((string) $rulare['pas_inceput_la'], $s);
                return Ceas::acum() >= $scadenta ? ['urmator', $pas['urmator']] : ['asteapta', Ceas::sql($scadenta)];

            case 'daca':
                return ['urmator', Conditii::adevarata($db, $rulare, $s) ? $pas['da'] : $pas['nu']];

            case 'email':
                return self::pasEmail($db, $automatizare, $rulare, $pas, $setari, $buget, $raport, $optiuni);

            case 'anunta':
                return self::pasAnunt($db, $automatizare, $rulare, $pas, $setari, $buget, $raport);

            case 'cod_reducere':
                if (!Plugins::vizibil('cupoane')) {
                    Rulari::jurnal($db, $rulare['automatizare_id'], $rulare['id'], $pas['cheie'], 'sarit', 'fara_cupoane');
                    return ['urmator', $pas['urmator']];
                }
                $context = $rulare['context'];
                $context['cod_reducere'] = CoduriReducere::genereaza($db, $automatizare, $rulare, $pas['cheie'], $s);
                Rulari::salveazaContext($db, $rulare['id'], $context);
                return ['urmator', $pas['urmator']];

            case 'eticheta_adauga':
            case 'eticheta_scoate':
                $abonat = Rulari::abonatActiv($db, (string) $rulare['email']);
                $eticheta = (int) ($s['eticheta_id'] ?? 0);
                if ($abonat !== null && $eticheta > 0 && NewsletterEtichete::disponibil($db)) {
                    $pas['tip'] === 'eticheta_adauga'
                        ? NewsletterEtichete::adauga($db, $abonat['id'], $eticheta, Ceas::sql())
                        : NewsletterEtichete::scoate($db, $abonat['id'], $eticheta);
                }
                return ['urmator', $pas['urmator']];

            case 'lista_adauga':
            case 'lista_muta':
                $abonat = Rulari::abonatActiv($db, (string) $rulare['email']);
                $lista = (int) ($s['lista_id'] ?? 0);
                if ($abonat !== null && $lista > 0 && self::listaExista($db, $lista)) {
                    if ($pas['tip'] === 'lista_muta') {
                        $din = (int) ($s['din_lista_id'] ?? 0);
                        $implicita = NewsletterService::defaultListId($db);
                        if ($din > 0) {
                            $db->prepare('DELETE FROM newsletter_list_subscribers WHERE subscriber_id = :s AND list_id = :l AND list_id <> :t AND list_id <> :i')
                                ->execute(['s' => $abonat['id'], 'l' => $din, 't' => $lista, 'i' => $implicita]);
                        } else {
                            // Din toate celelalte liste, afară de lista implicită („Toți abonații”), în care e oricine.
                            $db->prepare('DELETE FROM newsletter_list_subscribers WHERE subscriber_id = :s AND list_id <> :t AND list_id <> :i')
                                ->execute(['s' => $abonat['id'], 't' => $lista, 'i' => $implicita]);
                        }
                    }
                    $db->prepare('INSERT IGNORE INTO newsletter_list_subscribers (list_id, subscriber_id) VALUES (:l, :s)')
                        ->execute(['l' => $lista, 's' => $abonat['id']]);
                }
                return ['urmator', $pas['urmator']];
        }
        return ['urmator', $pas['urmator']];
    }

    private static function listaExista(PDO $db, int $lista): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM newsletter_lists WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $lista]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Când se termină o așteptare: ora sosirii la pas + durata; cu „doar între orele …”, mutată la începutul intervalului
     * următor dacă ar cădea în afara lui (ora site-ului, Europe/Bucharest).
     */
    public static function scadentaAsteptare(string $inceput, array $cfg): \DateTimeImmutable
    {
        $scadenta = (new \DateTimeImmutable($inceput !== '' ? $inceput : Ceas::sql()))->modify('+' . Catalog::secundeAsteptare($cfg) . ' seconds');
        if (empty($cfg['doar_ziua'])) {
            return $scadenta;
        }
        $de = (int) ($cfg['ora_de'] ?? 9);
        $pana = (int) ($cfg['ora_pana'] ?? 20);
        $ora = (int) $scadenta->format('G');
        if ($ora < $de) {
            return $scadenta->setTime($de, 0);
        }
        if ($ora >= $pana) {
            return $scadenta->modify('+1 day')->setTime($de, 0);
        }
        return $scadenta;
    }

    /**
     * Rezervarea unei trimiteri: ['trimite', id] (rândul e al nostru, „pending”), ['gata'] (a plecat sau poate a plecat:
     * mai departe, fără retrimitere) sau ['iese', motiv] (încercările s-au terminat).
     */
    private static function rezerva(PDO $db, array $rulare, string $pas, ?int $emailId, ?int $abonatId): array
    {
        $citeste = $db->prepare('SELECT id, stare, incercari FROM automatizari_trimiteri WHERE rulare_id = :r AND pas_cheie = :p LIMIT 1');
        $citeste->execute(['r' => $rulare['id'], 'p' => $pas]);
        $rand = $citeste->fetch(PDO::FETCH_ASSOC);
        if (is_array($rand)) {
            $stare = (string) $rand['stare'];
            if ($stare === 'failed') {
                if ((int) $rand['incercari'] >= NewsletterService::MAX_INCERCARI) {
                    return ['iese', 'eroare_trimitere'];
                }
                $reia = $db->prepare("UPDATE automatizari_trimiteri SET stare = 'pending', incercari = incercari + 1, eroare = NULL WHERE id = :id AND stare = 'failed'");
                $reia->execute(['id' => (int) $rand['id']]);
                return $reia->rowCount() === 1 ? ['trimite', (int) $rand['id']] : ['gata'];
            }
            if ($stare === 'pending') {
                // Rămas de la o trecere oprită la jumătate: poate a plecat. Nu se retrimite.
                $db->prepare("UPDATE automatizari_trimiteri SET stare = 'unknown', eroare = :e WHERE id = :id AND stare = 'pending'")
                    ->execute(['e' => 'Trecerea s-a oprit înainte de confirmare (reluată fără retrimitere).', 'id' => (int) $rand['id']]);
            }
            return ['gata'];
        }
        $insert = $db->prepare(
            "INSERT IGNORE INTO automatizari_trimiteri (rulare_id, automatizare_id, pas_cheie, email_id, abonat_id, stare, incercari, created_at)
             VALUES (:r, :a, :p, :e, :ab, 'pending', 1, :c)"
        );
        $insert->execute(['r' => $rulare['id'], 'a' => $rulare['automatizare_id'], 'p' => $pas, 'e' => $emailId, 'ab' => $abonatId, 'c' => Ceas::sql()]);
        return $insert->rowCount() === 1 ? ['trimite', (int) $db->lastInsertId()] : ['gata'];
    }

    private static function marcheaza(PDO $db, int $trimitereId, string $stare, ?string $eroare = null): void
    {
        $db->prepare('UPDATE automatizari_trimiteri SET stare = :s, eroare = :e, trimis_la = :t WHERE id = :id')
            ->execute(['s' => $stare, 'e' => $eroare !== null ? mb_substr($eroare, 0, 1000) : null, 't' => Ceas::sql(), 'id' => $trimitereId]);
    }

    /** Rezultatul unei trimiteri eșuate: reîncercare peste 15 minute (cel mult 3 încercări), apoi ieșirea. */
    private static function dupaEsec(PDO $db, int $trimitereId, Throwable $e, array &$raport): array
    {
        $raport['esuate']++;
        if ($e instanceof TrimitereNeconfirmata) {
            // Poate a ajuns: nu se retrimite, fluxul merge mai departe.
            self::marcheaza($db, $trimitereId, 'unknown', $e->getMessage());
            return ['gata'];
        }
        self::marcheaza($db, $trimitereId, 'failed', $e->getMessage());
        self::$esecuriLaRand++;
        if (self::$esecuriLaRand >= self::ESECURI_LA_RAND) {
            // Serverul de email are o problemă, nu adresele: trecerea se oprește (ca la campanii).
            self::$opreste = true;
        }
        return ['reincearca', Ceas::sql(Ceas::acum()->modify('+' . self::MINUTE_REINCERCARE . ' minutes'))];
    }

    private static function pasEmail(PDO $db, array $automatizare, array $rulare, array $pas, array $setari, BugetTrimitere $buget,
        array &$raport, array $optiuni): array
    {
        // La fiecare email: abonatul trebuie să fie încă activ (nu dezabonat, respins, șters, pe lista „nu trimite”).
        $abonat = Rulari::abonatActiv($db, (string) $rulare['email']);
        if ($abonat === null) {
            return ['iese', 'neabonat'];
        }
        // Coș abandonat: dacă între timp a cumpărat (coșul s-a finalizat sau a plasat o comandă), fluxul se oprește.
        if ((string) $automatizare['declansator'] === 'cos_abandonat' && Conditii::aCumparatDeLaIntrare($db, $rulare)) {
            return ['iese', 'a_cumparat'];
        }
        $email = Flux::email($db, (int) ($pas['setari']['email_id'] ?? 0));
        if ($email === null || (int) $email['automatizare_id'] !== (int) $automatizare['id']) {
            Rulari::jurnal($db, $rulare['automatizare_id'], $rulare['id'], $pas['cheie'], 'sarit', 'email_lipsa');
            return ['urmator', $pas['urmator']];
        }
        if ($buget->epuizat()) {
            return ['buget'];
        }
        $rezervare = self::rezerva($db, $rulare, $pas['cheie'], (int) $email['id'], $abonat['id']);
        if ($rezervare[0] === 'iese') {
            return $rezervare;
        }
        if ($rezervare[0] === 'gata') {
            return ['urmator', $pas['urmator']];
        }
        $trimitereId = (int) $rezervare[1];
        if (!empty($optiuni['cad_dupa_rezervare'])) {
            throw new CadereSimulata('cădere simulată după rezervare');
        }
        $buget->consuma();
        try {
            Trimitere::trimite($db, $email, $abonat, $rulare, $pas['cheie'], $trimitereId, $setari);
        } catch (Throwable $e) {
            $dupa = self::dupaEsec($db, $trimitereId, $e, $raport);
            return $dupa[0] === 'gata' ? ['urmator', $pas['urmator']] : $dupa;
        }
        self::marcheaza($db, $trimitereId, 'sent');
        self::$esecuriLaRand = 0;
        $raport['trimise']++;
        return ['urmator', $pas['urmator']];
    }

    private static function pasAnunt(PDO $db, array $automatizare, array $rulare, array $pas, array $setari, BugetTrimitere $buget,
        array &$raport): array
    {
        if ($buget->epuizat()) {
            return ['buget'];
        }
        $rezervare = self::rezerva($db, $rulare, $pas['cheie'], null, null);
        if ($rezervare[0] === 'iese') {
            // Anunțul administratorului nu oprește fluxul abonatului.
            Rulari::jurnal($db, $rulare['automatizare_id'], $rulare['id'], $pas['cheie'], 'sarit', 'anunt_esuat');
            return ['urmator', $pas['urmator']];
        }
        if ($rezervare[0] === 'gata') {
            return ['urmator', $pas['urmator']];
        }
        $buget->consuma();
        try {
            Trimitere::anuntaAdmin($db, $automatizare, $rulare, $pas['setari'], $setari);
        } catch (Throwable $e) {
            $dupa = self::dupaEsec($db, (int) $rezervare[1], $e, $raport);
            return $dupa[0] === 'gata' ? ['urmator', $pas['urmator']] : $dupa;
        }
        self::marcheaza($db, (int) $rezervare[1], 'sent');
        return ['urmator', $pas['urmator']];
    }
}
