<?php

declare(strict_types=1);

namespace App\Support\MagazinWc;

/**
 * Ce face magazinul după ce o comandă a fost plătită (mutarea unui site WooCommerce; docs/MAGAZIN-WC.md, „Rutele” și pista H din
 * specificația plății): plata Stripe (pista H) își face partea ei, banii (starea plății, notele, clientul Stripe, cardul salvat), apoi
 * cheamă `ruleaza()`; consecințele le scriu pistele L și I: emailurile stărilor, permisiunile de descărcare, lista 2 a newsletterului,
 * evenimentul comenzii, stocul.
 *
 * Contractul: H poate chema `ruleaza()` de mai multe ori pentru aceeași comandă, și în paralel (întoarcerea clientului și
 * webhook-ul). Fiecare efect se întâmplă o singură dată pe comandă, păzit de propria lui revendicare (eliberată la eșec, ca
 * `stripe_events.in_lucru_la`): un efect care pică se reia la apelul următor (și se notează pentru admin), unul terminat nu se
 * repetă. `$sursa`: de unde vine apelul (`webhook`, `retur`, `admin`).
 *
 * Pista A a înregistrat doar locul: până la pistele L și I nu face nimic.
 */
final class DupaPlata
{
    public static function ruleaza(\PDO $db, int $comanda, string $sursa): void
    {
    }
}
