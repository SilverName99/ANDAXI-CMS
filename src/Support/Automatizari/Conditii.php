<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use App\Support\NewsletterEtichete;
use PDO;

/**
 * Condițiile pasului „Dacă / altfel” (Catalog::CONDITII): puține și clare. Fiecare se evaluează la momentul pasului, pe
 * datele de atunci; o condiție care nu se poate afla (fără comandă, fără urmărire) e „Nu”.
 */
final class Conditii
{
    public static function adevarata(PDO $db, array $rulare, array $cfg): bool
    {
        $abonat = Rulari::abonatActiv($db, (string) $rulare['email']);
        switch ((string) ($cfg['conditie'] ?? '')) {
            case 'in_lista':
                return $abonat !== null && (int) ($cfg['lista_id'] ?? 0) > 0
                    && Declansatoare::inLista($db, $abonat['id'], (int) $cfg['lista_id']);
            case 'are_eticheta':
                return $abonat !== null && (int) ($cfg['eticheta_id'] ?? 0) > 0 && NewsletterEtichete::disponibil($db)
                    && NewsletterEtichete::are($db, $abonat['id'], (int) $cfg['eticheta_id']);
            case 'a_deschis':
            case 'a_dat_clic':
                // Fără urmărire (abonatul a refuzat-o) nu știm nimic: „Nu”.
                if ($abonat !== null && $abonat['fara_urmarire']) {
                    return false;
                }
                $coloana = $cfg['conditie'] === 'a_deschis' ? 'deschis_la' : 'clic_la';
                $pas = (string) ($cfg['email_pas'] ?? '');
                $sql = "SELECT 1 FROM automatizari_trimiteri WHERE rulare_id = :r AND email_id IS NOT NULL AND {$coloana} IS NOT NULL"
                    . ($pas !== '' ? ' AND pas_cheie = :p' : '') . ' LIMIT 1';
                $stmt = $db->prepare($sql);
                $stmt->execute($pas !== '' ? ['r' => $rulare['id'], 'p' => $pas] : ['r' => $rulare['id']]);
                return (bool) $stmt->fetchColumn();
            case 'total_minim':
                $comanda = Declansatoare::comanda($db, (int) ($rulare['comanda_id'] ?? 0));
                return $comanda !== null && $comanda['total'] + 0.0001 >= (float) ($cfg['valoare'] ?? 0);
            case 'a_cumparat':
                return self::aCumparat($db, $rulare, Catalog::iduri($cfg['produse'] ?? []), Catalog::iduri($cfg['categorii'] ?? []),
                    !empty($cfg['de_la_intrare']));
        }
        return false;
    }

    /**
     * Adresa are o comandă (nu anulată, nu cea care a pornit fluxul), opțional plasată după intrarea în flux și cu un produs
     * sau o categorie dintre cele alese?
     *
     * @param list<int> $produse
     * @param list<int> $categorii
     */
    public static function aCumparat(PDO $db, array $rulare, array $produse, array $categorii, bool $deLaIntrare): bool
    {
        $nule = "'" . implode("', '", Declansatoare::COMENZI_NULE) . "'";
        $unde = "o.billing_email = ? AND o.deleted_at IS NULL AND o.status NOT IN ({$nule}) AND o.id <> ?";
        $parametri = [(string) $rulare['email'], (int) ($rulare['comanda_id'] ?? 0)];
        if ($deLaIntrare) {
            $unde .= ' AND o.created_at >= ?';
            $parametri[] = (string) $rulare['intrata_la'];
        }
        if ($produse !== [] || $categorii !== []) {
            return Declansatoare::produseInComenzi($db, $unde, $parametri, $produse, $categorii);
        }
        $stmt = $db->prepare('SELECT 1 FROM orders o WHERE ' . $unde . ' LIMIT 1');
        $stmt->execute($parametri);
        return (bool) $stmt->fetchColumn();
    }

    /** Coș abandonat: coșul s-a finalizat sau adresa a plasat o comandă de la intrarea în flux? */
    public static function aCumparatDeLaIntrare(PDO $db, array $rulare): bool
    {
        $cos = (int) ($rulare['context']['cos_id'] ?? 0);
        if ($cos > 0) {
            $stmt = $db->prepare('SELECT converted_at FROM cart_abandonments WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $cos]);
            if ((string) ($stmt->fetchColumn() ?: '') !== '') {
                return true;
            }
        }
        return self::aCumparat($db, $rulare, [], [], true);
    }
}
