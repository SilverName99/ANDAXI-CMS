<?php

declare(strict_types=1);

/**
 * Semnele unui plugin în textul vizibil (ce vede omul pe ecran, după JavaScript): etichete și explicații ale pluginului,
 * nu date. Datele vechi rămân (de exemplu „Reducere cupon (COD)” pe o comandă veche, ca totalul să se lege).
 *
 * Le folosesc tools/dev/test-pluginuri.php (partea C: niciun semn al unui plugin oprit pe paginile site-ului și ale
 * adminului) și, din WP-36, tools/dev/test-admin-faza-d.php (partea E: rezultatele căutării în tot adminul și lista
 * scurtăturilor de la „?”). Un text nou al unui plugin se adaugă aici.
 *
 * @return array<string, list<string>> pluginul => expresii regulate
 */
return [
    // WP-19: câmpurile catalogului din editorul produsului, sortarea „Ordinea din catalog”, bara de ciornă, banda simplă.
    'catalog' => ['/Categorii produse/u', '/Vezi toate produsele/u', '/Câmpuri suplimentare/u', '/Ascuns din lista magazinului/u',
        '/Poziție în liste/u', '/Link de ciornă/u', '/Ordinea din catalog/u', '/Pagina se vede doar prin acest link/u', '/Produse asemănătoare/u'],
    // Avertismentul pe produs (Bioscem 333ba9e, fa76c4f): cardul din editorul produsului și codul din emailuri.
    'magazin' => ['/Adaugă în coș/u', '/Finalizează comanda/u', '/Comenzi recente/u', '/Numerotare comenzi/u', '/Coș flotant/u',
        '/Avertisment la coș/u', '/Avertismentele confirmate/u', '/Comandă online pe/u',
        // WP-32: cifrele zilei și rândurile magazinului din „De făcut” pe Acasă.
        '/Vânzări azi/u', '/Coșuri abandonate/u', '/Stoc scăzut/u', '/de verificat în bancă/u', '/no(i|uă) de confirmat/u',
        // WP-35 (IA-01, IA-10): emailurile comenzii, în Setări.
        '/Emailuri de comandă/u'],
    'livrare_fan' => ['/FAN Courier/u', '/FANbox/u', '/\bAWB\b/u', '/Setări livrare/u'],
    // Procesatorii: textele lor, nu datele comenzilor vechi („euplatesc” ca metodă,
    // „ID tranzacție EuPlătesc”, „Card BT” rămân pe comenzile plătite așa).
    'plata_stripe' => ['/prin Stripe/u', '/Stripe \\(rezervă\\)/u', '/Stripe Publishable/u', '/Card bancar — Stripe/u'],
    'plata_euplatesc' => ['/prin EuPlătesc/u', '/pe EuPlătesc/u', '/procesatorului EuPlătesc/u', '/Card bancar — EuPlătesc/u',
        '/comerciant EuPlătesc/u'],
    'plata_bt' => ['/Banca Transilvania/u', '/\bBT iPay\b/u', '/\bSTAR\b/u'],
    'link_plata' => ['/Trimite link de plată/u', '/diferență de încasat/u'],
    'erp' => ['/\bERP\b/u'],
    'cupoane' => ['/Cod cupon/u', '/Cupon folosit/u', '/Coduri unice/u'],
    'fidelitate' => ['/\bFidelitate\b/u', '/Puncte fidelit/u', '/Puncte fidelizare/u', '/Punctele mele/u', '/Reducere puncte/u', '/puncte la această comandă/u'],
    // WP-32: rândul lor din „De făcut” pe Acasă.
    'recenzii' => ['/Lasă o recenzie/u', '/\bRecenzii\b/u', '/recenzi[ei] no(i|uă) de aprobat/u'],
    'precomenzi' => ['/Precomand[ăa]/u', '/Precomenzi/u'],
    'oferte_bbd' => ['/\bBBD\b/u', '/Sertar Oferte/u', '/Sertar lateral „Oferte”/u'],
    'produse_promo' => ['/[Pp]romoțional/u'],
    'conturi_clienti' => ['/Contul meu/u', '/Creează cont/u', '/Conturi clienți/u'],
    // WP-22, WP-23: căutarea din listă și articolele similare ale blogului avansat; editorul postării.
    'blog' => ['/\bBlog\b/u', '/Articole recente/u', '/Pornește videoclipul/u', '/Caută în articole/u', '/Articole similare/u',
        '/Salvează ca draft/u', '/Referințe \(bibliografie\)/u'],
    'newsletter' => ['/[Nn]ewsletter/u', '/[Aa]bonaț/u', '/opt-in/u'],
    'chat_live' => ['/tawk/iu', '/Chat live/u'],
    'manechin' => ['/[Mm]anechin/u'],
    'acorduri_gdpr' => ['/Acorduri GDPR/iu'],
    'migrare_wordpress' => ['/backup WordPress/u', '/UpdraftPlus/u'],
    // WP-24 (VT-X2): nota „acces restricționat” a nucleului apare doar cu pluginul membri (fără el, 404).
    'membri' => ['/Conținut pentru membri/u', '/Doar cont activ/u'],
    // WP-26 (GR-15): lista din admin, butonul (textul implicit și cel înghețat al grafoanaytis) și linkul cardurilor simple.
    'cereri_oferta' => ['/Cereri de ofertă/u', '/ofertă de preț/u', '/Cere ofertă/u'],
];
