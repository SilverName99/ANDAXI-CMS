<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

/**
 * Textele paginilor vechi MailPoet (pista E), în română, exact cum le arăta site-ul WordPress: catalogul ro_RO pe care MailPoet îl
 * instala de pe translate.wordpress.com (nu cel de pe wordpress.org, care diferă la mai multe texte). Textele sunt interfața
 * paginilor (titluri, butoane, etichete), citate doar ca să iasă octet cu octet la fel; niciun cod MailPoet.
 */
final class Texte
{
    public const CONFIRMAT_TITLU = 'Te-ai abonat la %s';
    public const CONFIRMAT_TITLU_FARA_NUME = 'Acum ești abonat!';
    public const CONFIRMAT_CORP = 'Dap, te-am adăugat în lista noastră de emailuri. Vei auzi vești de la noi în curând.';

    public const REANGAJARE_TITLU = 'Mulțumim că ne-ai anunțat!';
    public const REANGAJARE_CORP = 'Îți apreciem interesul continuu legat de actualizările noastre. Vei primi din nou vești de la noi, în curând!';

    public const DEZABONAT_TITLU = 'Acum ești dezabonat.';
    public const DEZABONAT_DIN_GRESEALA = 'Te-ai dezabonat din greșeală?';
    public const LEGATURA_DOAR_ABONATI = 'Legătura către pagina de administrare a abonării este disponibilă doar abonaților din listele de corespondență.';
    public const ADMINISTREAZA = 'Administrează-ți abonamentul';

    public const CONFIRMA_DEZABONARE_TITLU = 'Confirmă că vrei să te dezabonezi';
    public const CONFIRMA_DEZABONARE_ADRESA = 'Ești pe punctul de a te dezabona cu această adresă de email:';
    public const CONFIRMA_DEZABONARE_CORP = 'Pur și simplu dă clic pe această legătură pentru a opri primirea emailurilor de la noi.';
    public const DA_DEZABONEAZA = 'Da, dezabonează-mă';

    public const URMARIRE_TITLU = 'Dezactivează urmărirea activității prin email';
    public const URMARIRE_CORP = 'Dacă confirmi, urmărirea deschiderilor de emailuri și a accesărilor legăturilor va înceta. Aceasta este o procedură '
        . 'distinctă de dezabonare: vei continua să primești emailuri.';
    public const URMARIRE_BUTON = 'Nu-mi urmări activitatea';
    public const URMARIRE_OPRITA_TITLU = 'Ai dezactivat urmărirea activității prin email.';
    public const URMARIRE_OPRITA_CORP = 'Urmărirea deschiderilor de emailuri și a accesărilor legăturilor este acum dezactivată. Vei continua să primești '
        . 'emailuri ca de obicei.';

    public const NECUNOSCUT_TITLU = 'Hmmm... nu avem o înregistrare despre tine.';
    public const NECUNOSCUT_CORP = 'Adresa ta de email nu mai apare în listele noastre. Înregistrează-te din nou sau contactează-ne dacă acest lucru pare o greșeală.';

    public const GESTIONARE_DOAR_ABONATI = 'Formularul de administrare al abonării este disponibil doar abonaților din listele de corespondență.';
    public const SCHIMBA_ADRESA = 'Ai nevoie să-ți schimbi adresa de email? Dezabonează-te aici, apoi pur și simplu înregistrează-te din nou.';
    /** `[link]…[/link]` devine legătura spre cont (VC-MP-06: `/my-account/`, nu `/wp-login.php`). */
    public const AUTENTIFICA_TE = '[link]Autentifică-te în contul tău[/link] pentru a-ți actualiza emailul.';
    public const PRENUME = 'Prenume';
    public const NUME = 'Nume';
    public const STARE = 'Stare';
    public const LISTELE_TALE = 'Listele tale';
    public const SALVEAZA = 'Salvează';
    public const NUME_INVALID = 'Te rog specifică un nume valid.';
    public const ADRESA_IN_NUME = 'Adresele în nume nu sunt permite, te rog adaugă-ți numele în loc.';
    public const PREFERINTE_SALVATE = 'Preferințele tale au fost salvate.';

    /** Opțiunile stării din formularul de gestionare (în ordinea lor). */
    public const STARI = ['subscribed' => 'Abonat', 'unsubscribed' => 'Dezabonat', 'bounced' => 'Sărit', 'inactive' => 'Inactiv'];

    public const SONDAJ_INTREBARE = 'Te rog să ne spui de ce te-ai dezabonat:';
    public const SONDAJ_MAI_MULTE = 'Spune-ne mai multe';
    public const SONDAJ_TRIMITE = 'Trimite';
    public const SONDAJ_MULTUMIM = 'Îți mulțumim că ne-ai anunțat de ce te-ai dezabonat.';
    /** Motivele sondajului, în ordinea lor (cheile lui MailPoet). */
    public const MOTIVE = [
        'normal' => 'Nu mai doresc să primesc aceste emailuri',
        'nosignup' => 'Nu m-am înscris niciodată pe această listă de corespondență',
        'inappropriate' => 'Emailurile sunt nepotrivite',
        'spam' => 'Emailurile sunt spam și ar trebui raportate',
        'other' => 'Altele',
    ];

    public const VERIFICARE_ESUATA = 'Verificarea de securitate a eșuat.';

    /** Subiectul emailului către administrator la o abonare confirmată (cu numele site-ului). */
    public const ABONAT_NOU = 'Abonat nou la %s';
}
