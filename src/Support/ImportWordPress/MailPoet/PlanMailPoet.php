<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\MailPoet;

use App\Support\Brand;
use App\Support\ImportWordPress\Plan;
use App\Support\ImportWordPress\PlanCapturat;
use App\Support\ImportWordPress\SursaWordPress;
use App\Support\MailPoetCompat\Arhiva;
use App\Support\MailPoetCompat\Date;
use App\Support\MailPoetCompat\Jetoane;
use App\Support\MailPoetCompat\Shortcoduri;
use App\Support\MailPoetCompat\Sincronizare;
use App\Support\NewsletterService;
use App\Support\Settings;
use App\Support\WpCompat;
use PDO;

/**
 * Planul părții `newsletter` a importului WordPress pentru un site cu MailPoet (pista E; specificația mailpoet §4.2, docs/PLUGINURI.md
 * „Newsletter”): ce ajunge în pluginul newsletter, citit din baza WordPress doar pentru citire (SursaMailPoet), fără nicio scriere.
 *
 *  - abonații (cheia: `mailpoet_id`, apoi adresa), cu starea (subscribed → active, unconfirmed → pending, unsubscribed, inactive,
 *    bounced), dovada consimțământului (`subscribed_ip`, `confirmed_ip`, datele) și amprenta jetonului (doar sha256, lungimea și sha256-ul
 *    primelor 6 caractere ale unui jeton actualizat: jetonul nu se scrie nicăieri); cei din coș nu se importă, iar cei dezabonați sau
 *    săriți dintre ei ajung pe lista „nu trimite” (`newsletter_suprimate`, motivul `mailpoet_cos`);
 *  - listele (fără cele din coș; `wp_users`, `woocommerce_users`, statice), cu `afisare_gestionare`, și apartenența cu starea pe listă;
 *  - campaniile standard trimise (arhiva, `sursa` = `mailpoet`, doar citire), cu HTML-ul păstrat în coadă sau randat de exportator,
 *    trimiterile procesate, deschiderile, clicurile, cozile (și ale emailurilor automatizărilor) și linkurile lor;
 *  - dezabonările (`newsletter_dezabonari`) și setările MailPoet de care au nevoie paginile vechi, abonarea și bugetul trimiterilor.
 *
 * NU (importul nu poate porni): o listă dinamică, un cod MailPoet necunoscut într-un email arhivat, un pas de automatizare necunoscut,
 * automatizări cât importul lor (pista J) lipsește (afară de --fara-automatizari), un câmp personalizat, două adrese egale fără
 * majuscule, o stare necunoscută, câmpurile cadrului R lipsă, un email fără HTML, o pagină de abonare care nu e pagina MailPoet, dubla
 * confirmare oprită, o trimitere în curs, autorul paginilor MailPoet capturate într-o formă necunoscută (CadreMailPoet); cu baza
 * site-ului (verificaSite()), și o adresă din MailPoet care e deja a altui abonat al site-ului. MailPoet instalat, dar fără niciun
 * abonat, nu e un NU (INR-E-01): nimic de importat. Ieșirea planului are doar numere și id-uri, niciodată o adresă sau un jeton.
 */
final class PlanMailPoet
{
    /** Cheile pașilor automatizărilor folosite pe site-ul mutat (specificația §5.1); altă cheie = NU. */
    public const CHEI_AUTOMATIZARE = ['core:root', 'mailpoet:someone-subscribes', 'mailpoet:wp-user-registered', 'woocommerce:buys-a-product', 'core:delay',
        'mailpoet:send-email'];

    public const STARI = ['subscribed' => 'active', 'unconfirmed' => 'pending', 'unsubscribed' => 'unsubscribed', 'inactive' => 'inactive', 'bounced' => 'bounced'];

    public const TIPURI_LISTA = ['default' => 'static', 'wp_users' => 'wp_users', 'woocommerce_users' => 'woocommerce_users'];

    public const MOTIVE = ['normal', 'nosignup', 'inappropriate', 'spam', 'other', 'unspecified'];

    /** Setările păstrate doar ca dovadă a configurației MailPoet (codul nu le citește): dubla confirmare, zilele până la „inactiv”. */
    public const CHEIE_DUBLA_CONFIRMARE = 'mailpoet_dubla_confirmare';

    public const CHEIE_ZILE_INACTIV = 'mailpoet_zile_inactiv';

    /** @var list<string> */
    public array $probleme = [];
    /** @var list<string> */
    public array $avertismente = [];
    /** @var list<string> */
    public array $info = [];
    /** @var array<int, array<string, mixed>> mailpoet_id → abonatul */
    public array $abonati = [];
    /** @var list<string> amprentele adreselor din coș (dezabonate sau sărite) */
    public array $suprimate = [];
    /** @var array<int, array<string, mixed>> mailpoet_id → lista */
    public array $liste = [];
    /** @var list<array{abonat: int, lista: int, status: string, created_at: ?int, updated_at: ?string}> id-uri MailPoet (created_at: timp unix, TIMESTAMP) */
    public array $apartenente = [];
    /** @var array<int, array<string, mixed>> mailpoet_id → campania (cu trimiterile ei) */
    public array $campanii = [];
    /** @var array<int, array<string, mixed>> coada → rândul ei */
    public array $cozi = [];
    /** @var list<array{coada: int, hash: string, url: string}> */
    public array $linkuri = [];
    /** @var list<array<string, mixed>> */
    public array $dezabonari = [];
    /** @var array<string, string> setările CMS (fără listele bifei din checkout, care se leagă la scriere) */
    public array $setari = [];
    /** @var array{activ: bool, eticheta: string, liste: list<int>}|null bifa din checkout, cu id-urile MailPoet ale listelor */
    public ?array $optin = null;
    /** @var array{capacitate: int, perioada_s: int}|null */
    public ?array $buget = null;
    /** Câte nume (abonați: prenume + nume; campanii) au mai mult de 190 de caractere și se taie (`name` e VARCHAR(190) în CMS). */
    public int $numeTaiate = 0;
    /** Câte valori `subscribed_ip` / `confirmed_ip` nu erau adrese IP și nu se importă (E-LEG-10; doar numărul). */
    public int $ipuriRefuzate = 0;
    /** @var array{nume: string, adresa: string, raspuns: string} expeditorul din setările MailPoet (doar pentru comparația cu site-ul; nu se tipărește) */
    public array $expeditor = ['nume' => '', 'adresa' => '', 'raspuns' => ''];

    /** Amintirea expeditorului, cât nu se știe că diferă de cel al site-ului (verificaSite() o înlocuiește cu ce diferă). */
    public const AMINTIRE_EXPEDITOR = 'expeditorul emailurilor rămâne cel din Admin → Emailuri: verifică-l înainte de comutare (MailPoet trimitea de la expeditorul din setările lui)';

    /** Adaugă planul MailPoet la planul importului (problemele, avertismentele și informațiile lui; datele în `$plan->newsletter`). */
    public static function adauga(Plan $plan, SursaMailPoet $m, array $fara): void
    {
        // Autorul din paginile MailPoet capturate (cadrele P și R; E-LEG-08), și cu --fara-newsletter: routerul le servește cât
        // wp-compat.json are mailpoet.
        foreach (CadreMailPoet::ascundeAutorul($plan->capturata) as $t) {
            $plan->probleme[] = 'newsletter (MailPoet): ' . $t;
        }
        $p = self::fa($m, $plan->capturata, $plan->capturat, $fara);
        foreach ($p->probleme as $t) {
            $plan->probleme[] = 'newsletter (MailPoet): ' . $t;
        }
        foreach ($p->avertismente as $t) {
            $plan->avertismente[] = 'newsletter (MailPoet): ' . $t;
        }
        foreach ($p->info as $t) {
            $plan->info[] = 'newsletter (MailPoet): ' . $t;
        }
        $plan->newsletter = in_array('newsletter', $fara, true) ? null : $p;
    }

    /** @param list<string> $fara părțile lăsate deoparte de proprietar (`newsletter`, `automatizari`) */
    public static function fa(SursaMailPoet $m, ?PlanCapturat $capturata, bool $capturat, array $fara = []): self
    {
        $p = new self();
        $abonati = $m->abonati();
        if (in_array('newsletter', $fara, true)) {
            $p->avertismente[] = 'NU se importă (ai ales --fara-newsletter): ' . count($abonati) . ' abonați MailPoet, cu listele, campaniile și setările lor';
            return $p;
        }
        if (!$capturat || !WpCompat::mailpoet()) {
            if ($abonati === []) {
                // MailPoet instalat, dar fără niciun abonat (INR-E-01): nimic de importat, deci nici adresele vechi de servit.
                $p->info[] = 'MailPoet e instalat, dar n-are niciun abonat: nimic de importat';
                return $p;
            }
            $p->probleme[] = 'importul MailPoet cere drumul capturat și wp-compat.json → mailpoet (adresele vechi, paginile, formularele de abonare): fără ele, '
                . 'adoptarea doar cu --fara-newsletter';
            return $p;
        }
        $p->verificaSetari($m);
        $p->planificaListe($m);
        $p->planificaAbonati($m, $abonati);
        $p->planificaEmailuri($m);
        $p->planificaDezabonari($m);
        $p->planificaCadre($capturata);
        $p->verificaAutomatizari($m, $fara);
        if ($m->campuriPersonalizate() > 0) {
            $p->probleme[] = 'MailPoet are câmpuri personalizate (' . $m->campuriPersonalizate() . '): pagina de administrare a abonamentului le-ar arăta, iar '
                . 'codul comun nu le are';
        }
        if (($n = $m->trimiteriInCurs()) > 0) {
            $p->probleme[] = $n . ' trimiteri MailPoet sunt încă în curs (programate, în pauză sau pornite): importul după ce se termină';
        }
        $p->info[] = sprintf('abonați %d (%s), pe lista „nu trimite” %d; liste %d; apartenențe %d; campanii %d; cozi %d; linkuri %d; dezabonări %d',
            count($p->abonati), implode(', ', array_map(static fn (string $s, int $n): string => $s . ' ' . $n, array_keys($p->numarPeStare()), $p->numarPeStare())),
            count($p->suprimate), count($p->liste), count($p->apartenente), count($p->campanii), count($p->cozi), count($p->linkuri), count($p->dezabonari));
        $p->info[] = self::AMINTIRE_EXPEDITOR;
        if ($p->ipuriRefuzate > 0) {
            $p->avertismente[] = $p->ipuriRefuzate . ' adrese IP din dovada acordului (subscribed_ip, confirmed_ip) nu sunt adrese IP valide: nu se importă, '
                . 'câmpul rămâne gol';
        }
        if ($p->numeTaiate > 0) {
            $p->avertismente[] = $p->numeTaiate . ' nume (ale abonaților sau ale campaniilor) au peste 190 de caractere: se taie la 190 (prenumele și numele '
                . 'abonaților rămân întregi)';
        }
        return $p;
    }

    /** @return array<string, int> */
    public function numarPeStare(): array
    {
        $rez = [];
        foreach ($this->abonati as $a) {
            $rez[(string) $a['status']] = ($rez[(string) $a['status']] ?? 0) + 1;
        }
        ksort($rez);
        return $rez;
    }

    /** Ce se raportează (doar numere). @return array<string, mixed> */
    public function raport(): array
    {
        return ['abonati' => count($this->abonati), 'pe_stare' => $this->numarPeStare(), 'suprimate' => count($this->suprimate), 'liste' => count($this->liste),
            'apartenente' => count($this->apartenente), 'campanii' => count($this->campanii), 'cozi' => count($this->cozi), 'linkuri' => count($this->linkuri),
            'dezabonari' => count($this->dezabonari), 'setari' => array_keys($this->setari), 'buget' => $this->buget];
    }

    private function verificaSetari(SursaMailPoet $m): void
    {
        $bool = static fn (mixed $v): bool => $v === true || $v === 1 || in_array((string) $v, ['1', 'true', 'yes', 'on'], true);
        // Expeditorul MailPoet (numele, adresa, Reply-To), doar pentru comparația cu setările site-ului (verificaSite).
        $this->expeditor = ['nume' => trim((string) $m->setare('sender.name', '')), 'adresa' => strtolower(trim((string) $m->setare('sender.address', ''))),
            'raspuns' => strtolower(trim((string) $m->setare('reply_to.address', '')))];
        if (!$bool($m->setare('signup_confirmation.enabled', '1'))) {
            $this->probleme[] = 'dubla confirmare e oprită în MailPoet: abonarea pe site-ul nou o folosește mereu';
        }
        $this->setari[self::CHEIE_DUBLA_CONFIRMARE] = '1';
        // Pagina abonamentelor: MailPoet face fiecare adresă cu pagina din setări; codul comun răspunde doar pe `/` (pagina MailPoet).
        $pagini = [];
        foreach (['manage', 'unsubscribe', 'confirmation', 'confirm_unsubscribe'] as $k) {
            $pagini[] = (int) $m->setare('subscription.pages.' . $k, 0);
        }
        $pagini[] = (int) $m->setare('reEngagement.page', $pagini[0]);
        $pagini = array_values(array_unique(array_filter($pagini, static fn (int $i): bool => $i > 0)));
        $cale = null;
        foreach ($pagini as $id) {
            $pg = $m->postare($id);
            if ($pg === null || (string) $pg['post_type'] !== 'mailpoet_page' || (string) $pg['post_status'] !== 'publish'
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,190}$/D', (string) $pg['post_name']) !== 1) {
                $this->probleme[] = 'pagina de abonare MailPoet (postarea ' . $id . ') nu e o pagină MailPoet publicată: adresele vechi ar sta pe altă cale decât `/` '
                    . '(contractul A↔E, specificația §2)';
                continue;
            }
            $c = '/?mailpoet_page=' . $pg['post_name'];
            if ($cale !== null && $cale !== $c) {
                $this->probleme[] = 'paginile de abonare MailPoet sunt mai multe pagini diferite: codul comun are una singură';
            }
            $cale = $c;
        }
        $this->setari['mailpoet_pagina_abonare'] = $cale ?? '/?mailpoet_page=subscriptions';
        $this->setari['mailpoet_jetoane_vechi_pana_la'] = (string) max(0, (int) $m->setare('obsolete_link_tokens_accepted_until', 0));
        $sondaj = (array) $m->setare('subscription.unsubscribe_survey', []);
        $this->setari['mailpoet_sondaj_dezabonare'] = (string) json_encode(['activ' => $bool($sondaj['enabled'] ?? true),
            'text_liber' => $bool($sondaj['allow_other_text'] ?? false)]);
        $this->setari['mailpoet_abonare_la_cont'] = $bool($m->setare('subscribe.on_register.enabled', '')) ? '1' : '0';
        $this->setari[Sincronizare::CHEIE_CLIENTI_VECHI] = $bool($m->setare('mailpoet_subscribe_old_woocommerce_customers.enabled', '')) ? '1' : '0';
        // Emailul „Abonat nou” către administrator: ca NewSubscriberNotificationMailer::isDisabled, pornit doar cu setarea `enabled` și o
        // adresă (fără setare sau fără adresă, oprit); o adresă care nu e una validă îl oprește și aici.
        $adresa = trim((string) $m->setare('subscriber_email_notification.address', ''));
        $adresaBuna = $adresa !== '' && strlen($adresa) <= 190 && filter_var($adresa, FILTER_VALIDATE_EMAIL) !== false;
        $this->setari['mailpoet_notificare_abonat_nou'] = $adresaBuna && $bool($m->setare('subscriber_email_notification.enabled', '')) ? '1' : '0';
        $this->setari['mailpoet_notificare_adresa'] = $adresaBuna ? $adresa : '';
        // Nivelul urmăririi (TrackingConfig al lui MailPoet: full, partial, basic; implicit full): cu „basic”, campaniile site-ului pleacă
        // fără pixel și fără linkuri urmărite (E-LEG-01; NewsletterService::faraUrmarireMailPoet). O valoare necunoscută e NU.
        $nivel = strtolower(trim((string) $m->setare('tracking.level', 'full')));
        if (!in_array($nivel, ['full', 'partial', 'basic'], true)) {
            $this->probleme[] = 'tracking.level are o valoare necunoscută (MailPoet știe doar full, partial și basic)';
            $nivel = 'basic';
        }
        $this->setari[NewsletterService::CHEIE_NIVEL_URMARIRE] = $nivel;
        $zile = (string) $m->setare('deactivate_subscriber_after_inactive_days', '365');
        $this->setari[self::CHEIE_ZILE_INACTIV] = ctype_digit($zile) ? $zile : '365';
        $sterge = (string) $m->setare('delete_unconfirmed_subscribers_after_days', '');
        if (!in_array($sterge, ['', '30'], true)) {
            $this->probleme[] = 'delete_unconfirmed_subscribers_after_days are o valoare necunoscută (MailPoet știe doar „” și „30”)';
        }
        $this->setari['newsletter_sterge_neconfirmati_zile'] = $sterge === '30' ? '30' : '0';
        $nume = $m->s->optiune('blogname', '');
        $this->setari['mailpoet_nume_site'] = is_string($nume) ? html_entity_decode($nume, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $optin = (array) $m->setare('woocommerce.optin_on_checkout', []);
        $liste = [];
        foreach ((array) ($optin['segments'] ?? []) as $l) {
            if (is_numeric($l) && (int) $l > 0) {
                $liste[] = (int) $l;
            }
        }
        $this->optin = ['activ' => $bool($optin['enabled'] ?? false), 'eticheta' => (string) ($optin['message'] ?? ''), 'liste' => $liste];
        $emailuri = (int) $m->setare('mta.frequency.emails', 0);
        $minute = (int) $m->setare('mta.frequency.interval', 0);
        $this->buget = $emailuri > 0 && $minute > 0 ? ['capacitate' => $emailuri, 'perioada_s' => $minute * 60] : null;
        // Emailul de confirmare: cel din editorul MailPoet (randat de exportator) sau, în modul simplu, subiectul și corpul din setări, pe
        // care codul comun le face ca ConfirmationEmailMailer::getMailBody (NewsletterService::emailConfirmareMailPoet, `mod` = `simplu`).
        // Fără cheia `use_mailpoet_editor` e modul simplu: MailPoet o citește cu implicitul false (ConfirmationEmailMailer.php:363), iar
        // instalarea scrie doar `enabled` (Populator).
        $conf = $m->confirmare();
        if ($bool($m->setare('signup_confirmation.use_mailpoet_editor', false))) {
            if ($conf === null) {
                $this->probleme[] = 'emailul de confirmare e făcut în editorul MailPoet, iar exportul (PLAN §7.1) nu are randarea lui: '
                    . 'php scripts/importa-wordpress.php … --export-mailpoet=<fișierul exportatorului>';
            } elseif (trim($conf['subiect']) === '') {
                // Ca getMailBodyWithCustomizer: fără subiect în randare, subiectul din setări, apoi textul implicit.
                $subiect = (string) $m->setare('signup_confirmation.subject', '');
                $conf['subiect'] = $subiect !== '' ? $subiect : 'Confirmă-ți abonamentul';
            }
        } else {
            $conf = ['mod' => 'simplu', 'subiect' => (string) $m->setare('signup_confirmation.subject', ''),
                'html' => (string) $m->setare('signup_confirmation.body', ''), 'text' => ''];
        }
        if ($conf !== null) {
            $this->verificaCoduri('emailul de confirmare', $conf['subiect'] . "\n" . $conf['html'] . "\n" . $conf['text'], true);
            $this->verificaEtichete('emailul de confirmare', $conf['subiect'] . "\n" . $conf['html'] . "\n" . $conf['text']);
            $this->setari['mailpoet_email_confirmare'] = (string) json_encode($conf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    private function planificaListe(SursaMailPoet $m): void
    {
        $coada = 0;
        foreach ($m->liste() as $l) {
            if ($l['deleted_at'] !== null) {
                $coada++;
                continue;
            }
            $tip = self::TIPURI_LISTA[(string) $l['type']] ?? null;
            if ($tip === null) {
                $this->probleme[] = 'lista MailPoet ' . (int) $l['id'] . ' are tipul „' . mb_substr((string) $l['type'], 0, 30) . '” (o listă dinamică sau necunoscută): '
                    . 'codul comun are doar liste statice';
                continue;
            }
            if ((int) ($l['confirmation_email_id'] ?? 0) > 0 || (int) ($l['confirmation_page_id'] ?? 0) > 0) {
                $this->probleme[] = 'lista MailPoet ' . (int) $l['id'] . ' are emailul sau pagina de confirmare proprii: codul comun are unul pentru tot site-ul';
            }
            $this->liste[(int) $l['id']] = ['mailpoet_id' => (int) $l['id'], 'name' => mb_substr((string) $l['name'], 0, 190), 'tip' => $tip,
                'description' => mb_substr((string) $l['description'], 0, 255), 'afisare_gestionare' => (int) ($l['display_in_manage_subscription_page'] ?? 0) === 1 ? 1 : 0,
                'created_at' => SursaMailPoet::epoca($l['created_at'])];
        }
        if ($coada > 0) {
            $this->info[] = $coada . ' liste MailPoet din coș nu se importă';
        }
    }

    /** @param list<array<string, mixed>> $randuri */
    private function planificaAbonati(SursaMailPoet $m, array $randuri): void
    {
        $adrese = [];
        $cos = 0;
        $scurte = 0;
        foreach ($randuri as $a) {
            $id = (int) $a['id'];
            $email = strtolower(trim((string) $a['email']));
            if ($a['deleted_at'] !== null) {
                $cos++;
                if (in_array((string) $a['status'], ['unsubscribed', 'bounced'], true) && $email !== '') {
                    $this->suprimate[] = NewsletterService::amprentaAdresa($email);
                }
                continue;
            }
            $stare = self::STARI[(string) $a['status']] ?? null;
            if ($stare === null) {
                $this->probleme[] = 'abonatul MailPoet ' . $id . ' are o stare necunoscută';
                continue;
            }
            if ($email === '' || strlen($email) > 190 || !mb_check_encoding($email, 'UTF-8')) {
                $this->probleme[] = 'abonatul MailPoet ' . $id . ' are o adresă goală sau mai lungă de 190 de caractere';
                continue;
            }
            if (isset($adrese[$email])) {
                $this->probleme[] = 'abonații MailPoet ' . $adrese[$email] . ' și ' . $id . ' au aceeași adresă (diferă doar majusculele)';
                continue;
            }
            $adrese[$email] = $id;
            $jeton = $a['link_token'];
            $jeton = is_string($jeton) && $jeton !== '' ? $jeton : null;
            $scurte += $jeton !== null && strlen($jeton) < Jetoane::LUNGIME_SIGURA ? 1 : 0;
            $prenume = mb_substr((string) $a['first_name'], 0, 255);
            $nume = mb_substr((string) $a['last_name'], 0, 255);
            if (mb_strlen(trim($prenume . ' ' . $nume)) > 190) {
                $this->numeTaiate++;
            }
            $this->abonati[$id] = ['mailpoet_id' => $id, 'email' => $email, 'first_name' => $prenume, 'last_name' => $nume,
                'name' => mb_substr(trim($prenume . ' ' . $nume), 0, 190), 'status' => $stare,
                'subscribed_ip' => $this->ip($a['subscribed_ip']), 'confirmed_ip' => $this->ip($a['confirmed_ip']),
                'confirmed_at' => SursaMailPoet::utc($a['confirmed_at']), 'last_subscribed_at' => SursaMailPoet::utc($a['last_subscribed_at']),
                'created_at' => SursaMailPoet::epoca($a['created_at']), 'updated_at' => SursaMailPoet::epoca($a['updated_at']),
                // Data după care curățarea lui MailPoet îl socotește (SubscribersRepository: ultimul email de confirmare, altfel
                // COALESCE(last_subscribed_at, created_at)).
                'pending_at' => $stare === 'pending' ? (SursaMailPoet::utc($a['last_confirmation_email_sent_at'] ?? null)
                    ?? SursaMailPoet::utc($a['last_subscribed_at']) ?? SursaMailPoet::utc($a['created_at'])) : null,
                'source' => mb_substr((string) ($a['source'] ?? 'unknown'), 0, 30), 'wp_user_id' => is_numeric($a['wp_user_id']) && (int) $a['wp_user_id'] > 0 ? (int) $a['wp_user_id'] : null,
                'is_woocommerce_user' => (int) $a['is_woocommerce_user'] === 1 ? 1 : 0,
                'mailpoet_token_hash' => $jeton !== null ? hash('sha256', $jeton) : null, 'mailpoet_token_len' => $jeton !== null ? min(255, strlen($jeton)) : null,
                'mailpoet_token6_hash' => $jeton !== null && strlen($jeton) === 32 && $jeton[6] === '-' ? hash('sha256', substr($jeton, 0, 6)) : null,
                'urmarire_refuzata' => (string) ($a['tracking_consent'] ?? '') === 'denied' ? 1 : 0,
                'last_engagement_at' => SursaMailPoet::utc($a['last_engagement_at']), 'last_sending_at' => SursaMailPoet::utc($a['last_sending_at'] ?? null),
                'email_count' => max(0, (int) $a['email_count'])];
        }
        if ($cos > 0) {
            $this->info[] = $cos . ' abonați MailPoet din coș nu se importă (' . count($this->suprimate) . ' dintre ei, dezabonați sau săriți, pe lista „nu trimite”)';
        }
        if ($scurte > 0) {
            // Jetoanele mai scurte de 32 de caractere se ghicesc mai ușor: le apără numărul de nepotriviri (Jetoane, SEC-E-02).
            $this->avertismente[] = $scurte . ' abonați au jetonul MailPoet mai scurt de ' . Jetoane::LUNGIME_SIGURA . ' de caractere: linkurile lor vechi trec doar cât nu '
                . 'se strâng ' . Jetoane::MAX_NEPOTRIVIRI . ' jetoane greșite în 24 de ore (apoi, 24 de ore, nici cel bun)';
        }
        foreach ($m->apartenente() as $r) {
            $ab = (int) $r['subscriber_id'];
            $li = (int) $r['segment_id'];
            if (!isset($this->abonati[$ab], $this->liste[$li])) {
                continue;
            }
            if (!in_array((string) $r['status'], ['subscribed', 'unsubscribed'], true)) {
                $this->probleme[] = 'apartenența abonatului MailPoet ' . $ab . ' la lista ' . $li . ' are o stare necunoscută';
                continue;
            }
            $this->apartenente[] = ['abonat' => $ab, 'lista' => $li, 'status' => (string) $r['status'], 'created_at' => SursaMailPoet::epoca($r['created_at']),
                'updated_at' => SursaMailPoet::utc($r['updated_at'])];
        }
    }

    private function planificaEmailuri(SursaMailPoet $m): void
    {
        $emailuri = [];
        foreach ($m->emailuri() as $e) {
            $emailuri[(int) $e['id']] = $e;
        }
        $listeEmail = $m->listeEmail();
        $cozi = [];
        foreach ($m->cozi() as $q) {
            $nl = (int) ($q['newsletter_id'] ?? 0);
            if (!isset($emailuri[$nl])) {
                continue;
            }
            $e = $emailuri[$nl];
            $standard = (string) $e['type'] === 'standard';
            if ($standard && (string) $e['status'] !== 'sent') {
                continue;
            }
            $cozi[(int) $q['id']] = $q + ['standard' => $standard];
        }
        // Destinatarii procesați (scheduled_task_subscribers) și statisticile, pe sarcină / coadă.
        $destinatari = [];
        foreach ($m->destinatari() as $d) {
            $destinatari[(int) $d['task_id']][] = $d;
        }
        $trimise = [];
        foreach ($m->trimise() as $t) {
            $trimise[(int) $t['queue_id']][(int) $t['subscriber_id']] = SursaMailPoet::utc($t['sent_at']);
        }
        $deschideri = [];
        foreach ($m->deschideri() as $o) {
            $deschideri[(int) $o['queue_id']][(int) $o['subscriber_id']][] = SursaMailPoet::utc($o['created_at']);
        }
        $linkuriDupaId = [];
        $hashuriCoada = [];
        $lungi = 0;
        foreach ($m->linkuri() as $l) {
            $linkuriDupaId[(int) $l['id']] = $l;
            if (isset($cozi[(int) $l['queue_id']])) {
                $this->linkuri[] = ['coada' => (int) $l['queue_id'], 'hash' => mb_substr((string) $l['hash'], 0, 20), 'url' => (string) $l['url']];
                $hashuriCoada[(int) $l['queue_id']][(string) $l['hash']] = true;
                // Etichetele editorului nou păstrate în locul adresei (`[mailpoet/…]`, rezolvate de MailPoet la clic): NU, ca în corp.
                $this->verificaEtichete('linkul urmărit ' . (int) $l['id'] . ' al cozii MailPoet ' . (int) $l['queue_id'], (string) $l['url']);
                // Statisticile clicurilor (`newsletter_campaign_clicks.url`) au VARCHAR(2048), MailPoet 2083: o adresă mai lungă nu se taie
                // pe tăcute (specificația §1.9), e NU.
                $lungi += mb_strlen((string) $l['url']) > 2048 ? 1 : 0;
            }
        }
        if ($lungi > 0) {
            $this->probleme[] = $lungi . ' linkuri urmărite au adrese mai lungi de 2048 de caractere, cât păstrează statisticile clicurilor';
        }
        $clicuri = [];
        foreach ($m->clicuri() as $c) {
            $clicuri[(int) $c['queue_id']][(int) $c['subscriber_id']][] = ['url' => (string) ($linkuriDupaId[(int) $c['link_id']]['url'] ?? ''),
                'numar' => max(1, (int) $c['count']), 'la' => SursaMailPoet::utc($c['created_at'])];
        }
        $gazde = [];
        $fonturiGoogle = 0;
        foreach ($cozi as $qid => $q) {
            $nl = (int) $q['newsletter_id'];
            $e = $emailuri[$nl];
            $procesati = array_values(array_filter($destinatari[(int) $q['task_id']] ?? [], static fn (array $d): bool => (int) $d['processed'] === 1));
            if (!$q['standard']) {
                // Coada unui email de automatizare: un singur destinatar (AutomationEmailScheduler); emailul îl leagă pista J.
                $ab = count($procesati) === 1 && isset($this->abonati[(int) $procesati[0]['subscriber_id']]) ? (int) $procesati[0]['subscriber_id'] : null;
                $this->cozi[$qid] = ['coada_id' => $qid, 'task_id' => (int) $q['task_id'], 'newsletter_mailpoet_id' => $nl, 'tip' => 'automatizare', 'campanie' => null,
                    'abonat' => $ab, 'trimis_la' => SursaMailPoet::utc($q['processed_at'])];
                continue;
            }
            if (isset($this->campanii[$nl])) {
                $this->probleme[] = 'campania MailPoet ' . $nl . ' are mai multe cozi de trimitere: o campanie standard are una singură';
                continue;
            }
            [$html, $text] = self::corp($q['newsletter_rendered_body']);
            if ($html === null) {
                $r = $m->randare($nl);
                if ($r === null) {
                    $this->probleme[] = 'campania MailPoet ' . $nl . ' n-are HTML-ul păstrat în coadă, iar exportul (PLAN §7.1) n-are randarea ei'
                        . ($m->areExport() ? '' : ': --export-mailpoet=<fișierul exportatorului>');
                    continue;
                }
                [$html, $text] = [$r['html'], $r['text']];
            }
            $this->verificaCoduri('campania MailPoet ' . $nl, (string) $e['subject'] . "\n" . $html . "\n" . $text, false);
            $this->verificaEtichete('campania MailPoet ' . $nl, (string) $e['subject'] . "\n" . $html . "\n" . $text);
            // Linkurile urmărite din HTML fără rândul lor în coadă: „vezi în browser” și clicul pe ele duc la prima pagină (doar numărul).
            preg_match_all('/\[mailpoet_click_data\]-(\w+)/', $html . "\n" . $text, $mh);
            $faraAdresa = count(array_diff_key(array_flip($mh[1]), $hashuriCoada[$qid] ?? []));
            if ($faraAdresa > 0) {
                $this->avertismente[] = 'campania MailPoet ' . $nl . ': ' . $faraAdresa . ' linkuri urmărite fără adresa lor în coadă (duc la prima pagină)';
            }
            foreach (self::gazde($html) as $g) {
                $gazde[$g] = true;
            }
            if (Arhiva::faraFonturiGoogle($html) !== $html) {
                $fonturiGoogle++;
            }
            $trimiteri = [];
            $deschise = 0;
            $clicTotal = 0;
            $esuate = 0;
            foreach ($procesati as $d) {
                $ab = (int) $d['subscriber_id'];
                $esuate += (int) $d['failed'] === 1 ? 1 : 0;
                $o = $deschideri[$qid][$ab] ?? [];
                $c = $clicuri[$qid][$ab] ?? [];
                $deschise += count($o);
                $clicTotal += array_sum(array_column($c, 'numar'));
                if (!isset($this->abonati[$ab])) {
                    continue; // un abonat din coș: în totaluri, fără rând (adresa lui nu se importă)
                }
                $laC = array_values(array_filter(array_column($c, 'la')));
                $trimiteri[] = ['abonat' => $ab, 'status' => (int) $d['failed'] === 1 ? 'failed' : 'sent',
                    'error_message' => (int) $d['failed'] === 1 ? mb_substr((string) ($d['error'] ?? ''), 0, 1000) : null,
                    'sent_at' => $trimise[$qid][$ab] ?? SursaMailPoet::utc($q['processed_at']), 'deschideri' => array_values(array_filter($o)),
                    'clicuri' => $c, 'clicked_at' => $laC !== [] ? min($laC) : null, 'click_count' => array_sum(array_column($c, 'numar'))];
            }
            $liste = array_values(array_filter($listeEmail[$nl] ?? [], fn (int $l): bool => isset($this->liste[$l])));
            $nume = $e['wp_post_id'] !== null ? $m->titluPostare((int) $e['wp_post_id']) : null;
            if (mb_strlen($nume ?? (string) $e['subject']) > 190) {
                $this->numeTaiate++;
            }
            $this->campanii[$nl] = ['mailpoet_id' => $nl, 'mailpoet_hash' => (string) ($e['hash'] ?? ''), 'subject' => mb_substr((string) $e['subject'], 0, 255),
                'name' => mb_substr($nume ?? (string) $e['subject'], 0, 190), 'sent_at' => SursaMailPoet::utc($e['sent_at']) ?? SursaMailPoet::utc($q['processed_at']),
                'liste' => $liste, 'html' => $html, 'text' => $text, 'total_recipients' => (int) $q['count_total'], 'total_sent' => count($trimise[$qid] ?? []),
                'total_failed' => $esuate, 'total_opens' => $deschise, 'total_clicks' => $clicTotal, 'trimiteri' => $trimiteri,
                'partajare' => $m->partajare($nl, (string) ($e['hash'] ?? '')),
                'creat_la' => SursaMailPoet::epoca($e['created_at'])];
            if ((string) $e['hash'] === '') {
                $this->avertismente[] = 'campania MailPoet ' . $nl . ' n-are hash: „vezi în browser” nu o poate găsi';
            }
            $this->cozi[$qid] = ['coada_id' => $qid, 'task_id' => (int) $q['task_id'], 'newsletter_mailpoet_id' => $nl, 'tip' => 'campanie', 'campanie' => $nl,
                'abonat' => null, 'trimis_la' => SursaMailPoet::utc($q['processed_at'])];
        }
        // Linkurile cozilor sărite (o campanie fără HTML) nu se scriu.
        $this->linkuri = array_values(array_filter($this->linkuri, fn (array $l): bool => isset($this->cozi[$l['coada']])));
        $this->setari['mailpoet_arhiva_gazde'] = (string) json_encode(array_keys($gazde));
        if ($fonturiGoogle > 0) {
            $this->avertismente[] = $fonturiGoogle . ' campanii MailPoet folosesc fonturi Google: „vezi în browser” le arată cu fonturile de rezervă (VC-MP-13)';
        }
    }

    private function planificaDezabonari(SursaMailPoet $m): void
    {
        foreach ($m->dezabonari() as $d) {
            $ab = (int) $d['subscriber_id'];
            if (!isset($this->abonati[$ab])) {
                continue;
            }
            $nl = (int) ($d['newsletter_id'] ?? 0);
            $q = (int) ($d['queue_id'] ?? 0);
            $motiv = in_array((string) $d['reason'], self::MOTIVE, true) ? (string) $d['reason'] : null;
            $this->dezabonari[] = ['mailpoet_id' => (int) $d['id'], 'abonat' => $ab, 'campanie' => isset($this->campanii[$nl]) ? $nl : null,
                'coada' => isset($this->cozi[$q]) ? $q : null,
                'sursa' => mb_substr((string) ($d['source'] ?? 'unknown'), 0, 20), 'metoda' => mb_substr((string) ($d['method'] ?? 'unknown'), 0, 12),
                'motiv' => $motiv, 'motiv_text' => is_string($d['reason_text']) && $d['reason_text'] !== '' ? mb_substr($d['reason_text'], 0, 500) : null,
                'motiv_la' => SursaMailPoet::utc($d['reason_submitted_at']), 'created_at' => SursaMailPoet::utc($d['created_at'])];
        }
    }

    /**
     * Cadrele capturate ale paginilor MailPoet (specificația §3.4.1): P (pagina simplă) și R (administrarea în previzualizare), cu
     * regiunea `mailpoet:pagina` pe H1 și pe conținut. Din R: sufixul titlului (`<title>` minus H1) și numele câmpurilor formularului
     * de administrare (`form_field_…`, §3.4.4); fără ele, NU.
     */
    private function planificaCadre(?PlanCapturat $c): void
    {
        $pagina = $this->setari['mailpoet_pagina_abonare'];
        $caleR = $pagina . '&mailpoet_router&endpoint=subscription&action=manage&data=' . Date::codifica(['preview' => 1]);
        $doc = static function (string $cale) use ($c): ?array {
            foreach ($c?->documente ?? [] as $d) {
                if ((string) ($d['valori_import']['cale'] ?? $d['cale'] ?? '') === $cale) {
                    return $d;
                }
            }
            return null;
        };
        if ($doc($pagina) === null) {
            $this->probleme[] = 'captura n-are pagina MailPoet simplă (cadrul P, ' . $pagina . ')';
        }
        $r = $doc($caleR);
        if ($r === null) {
            $this->probleme[] = 'captura n-are pagina de administrare în previzualizare (cadrul R, ' . $caleR . '): paginile vechi ale MailPoet nu au cadrul lor';
            return;
        }
        $t = $r['taiere'];
        $h1 = null;
        $continut = null;
        foreach ($t->regiuni as $reg) {
            if ((string) $reg['regiune'] === 'mailpoet:pagina') {
                if ((int) $reg['n'] === 0) {
                    $h1 = (string) $reg['octeti'];
                } elseif ((int) $reg['n'] === 1) {
                    $continut = (string) $reg['octeti'];
                }
            }
        }
        $titlu = $t->valori['titlu'] ?? null;
        if ($h1 === null || $continut === null || !is_string($titlu)) {
            $this->probleme[] = 'cadrul R n-are regiunea mailpoet:pagina pe H1 și pe conținut și jetonul titlului (regulile din import-wordpress.json)';
            return;
        }
        $h1Text = html_entity_decode(trim($h1), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $titluText = html_entity_decode($titlu, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($h1Text === '' || !str_starts_with($titluText, $h1Text)) {
            $this->probleme[] = 'titlul cadrului R nu începe cu titlul paginii (H1): sufixul titlului nu se poate lua';
        } else {
            $this->setari['mailpoet_sufix_titlu'] = substr($titluText, strlen($h1Text));
        }
        $campuri = [];
        if (preg_match_all('/name="data\[(form_field_[A-Za-z0-9+\/=]{4,120})\]/', $continut, $mm)) {
            foreach (array_unique($mm[1]) as $f) {
                $dec = base64_decode(substr($f, strlen('form_field_')), true);
                if (is_string($dec) && preg_match('/^[0-9a-f]{12}_(first_name|last_name|status|segments)$/D', $dec, $x) === 1) {
                    $campuri[$x[1]] = $f;
                }
            }
        }
        $lipsa = array_diff(['first_name', 'last_name', 'status', 'segments'], array_keys($campuri));
        if ($lipsa !== []) {
            $this->probleme[] = 'formularul de administrare din cadrul R n-are numele câmpurilor ' . implode(', ', $lipsa) . ' (§3.4.4)';
            return;
        }
        ksort($campuri);
        $this->setari['mailpoet_campuri_gestionare'] = (string) json_encode($campuri, JSON_UNESCAPED_SLASHES);
    }

    /** @param list<string> $fara */
    private function verificaAutomatizari(SursaMailPoet $m, array $fara): void
    {
        $aut = $m->automatizari();
        if ($aut === []) {
            return;
        }
        $necunoscute = [];
        foreach ($aut as $a) {
            foreach (is_array($a['pasi']) ? $a['pasi'] : [] as $pas) {
                $k = is_array($pas) ? (string) ($pas['key'] ?? '') : '';
                if (!in_array($k, self::CHEI_AUTOMATIZARE, true)) {
                    $necunoscute[mb_substr($k, 0, 60)] = true;
                }
            }
            if (!is_array($a['pasi'])) {
                $necunoscute['(pași ilizibili, automatizarea ' . $a['id'] . ')'] = true;
            }
        }
        if (in_array('automatizari', $fara, true)) {
            $this->avertismente[] = 'NU se importă (ai ales --fara-automatizari): ' . count($aut) . ' automatizări MailPoet'
                . ($necunoscute !== [] ? ', cu pași necunoscuți: ' . implode(', ', array_keys($necunoscute)) : '');
            return;
        }
        if ($necunoscute !== []) {
            $this->probleme[] = 'automatizările MailPoet au pași pe care codul comun nu-i are: ' . implode(', ', array_keys($necunoscute));
        }
        $this->probleme[] = 'site-ul are ' . count($aut) . ' automatizări MailPoet, iar importul lor (pista J) nu e făcut încă; fără ele adoptarea doar cu '
            . '--fara-automatizari';
    }

    /** Codurile MailPoet necunoscute (§4.6.3) dintr-un text arhivat: NU (doar categoria și acțiunea lor). */
    private function verificaCoduri(string $ce, string $text, bool $confirmare): void
    {
        $nec = [];
        foreach (Shortcoduri::necunoscute($text) as $sc) {
            $cod = strtolower(trim((string) preg_replace('/[\s|].*$/s', '', trim($sc, '[]'))));
            if ($confirmare && in_array($cod, ['activation_link', '/activation_link', 'lists_to_confirm'], true)) {
                continue;
            }
            $nec[mb_substr($cod, 0, 60)] = true;
        }
        if ($nec !== []) {
            $this->probleme[] = $ce . ' are coduri MailPoet necunoscute: ' . implode(', ', array_keys($nec));
        }
    }

    /**
     * Etichetele de personalizare ale editorului nou al lui MailPoet (`[mailpoet/subscriber-firstname]`, `[woocommerce/…]`, cu sau fără
     * `<!--…-->`, și scrise `%5B…%5D` sau `&#91;…&#93;`, formele pe care le decodează PersonalizationTagLinkResolver): codul comun nu le
     * rezolvă încă, deci oricare înseamnă NU (specificația §4.6.3: nimic nu pleacă cu un `[…]` netradus).
     */
    private function verificaEtichete(string $ce, string $text): void
    {
        $gasite = self::etichete($text);
        if ($gasite !== []) {
            $this->probleme[] = $ce . ' are etichete ale editorului nou MailPoet, pe care site-ul mutat nu le rezolvă încă: ' . implode(', ', $gasite);
        }
    }

    /** Numele etichetelor editorului nou dintr-un text (`mailpoet/…`, `woocommerce/…`), fără dubluri. @return list<string> */
    public static function etichete(string $text): array
    {
        $text = str_ireplace(['%5B', '%5D', '&#91;', '&#93;', '&#x5b;', '&#x5d;'], ['[', ']', '[', ']', '[', ']'], $text);
        if (preg_match_all('#\[((?:mailpoet|woocommerce)/[A-Za-z0-9-]+(?:/[A-Za-z0-9-]+)*)(?=[\s\]])#i', $text, $m) < 1) {
            return [];
        }
        return array_values(array_unique(array_map(static fn (string $x): string => mb_substr(strtolower($x), 0, 60), $m[1])));
    }

    /** @return array{0: ?string, 1: string} HTML-ul și textul unui corp păstrat în coadă (JSON sau serializat), sau [null, ''] */
    public static function corp(mixed $corp): array
    {
        if (!is_string($corp) || trim($corp) === '') {
            return [null, ''];
        }
        $v = json_decode($corp, true);
        if (!is_array($v)) {
            $v = SursaWordPress::desface($corp);
        }
        if (!is_array($v) || !is_string($v['html'] ?? null) || $v['html'] === '') {
            return [null, ''];
        }
        return [(string) $v['html'], is_string($v['text'] ?? null) ? (string) $v['text'] : ''];
    }

    /** Gazdele externe de fonturi și foi de stil din HTML-ul unui email (pentru CSP-ul arhivei). @return list<string> */
    public static function gazde(string $html): array
    {
        $rez = [];
        if (preg_match_all('#<link\b[^>]*\bhref\s*=\s*["\']?https://([a-z0-9.\-]+)[/"\'\s>]#i', $html, $m)) {
            array_push($rez, ...$m[1]);
        }
        if (preg_match_all('#@import\s+(?:url\()?["\']?https://([a-z0-9.\-]+)[/"\')\s]#i', $html, $m)) {
            array_push($rez, ...$m[1]);
        }
        return array_values(array_unique(array_map('strtolower', $rez)));
    }

    /** O adresă IP a dovezii acordului, sau null; o valoare care nu e o adresă IP se numără (E-LEG-10), fără să se tipărească. */
    private function ip(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false) {
            return $v;
        }
        $this->ipuriRefuzate++;
        return null;
    }

    /**
     * Verificările care cer baza site-ului nou (scripts/importa-wordpress.php, după plan; doar citesc), în planul importului:
     *  - expeditorul (E-PAR-06): numele, adresa și Reply-To din MailPoet față de Admin → Emailuri (`order_email_from_name`, altfel numele
     *    site-ului; `order_email_from_address`, care e și Reply-To-ul emailurilor site-ului): ce diferă e un avertisment, cu numele
     *    câmpului, fără nicio adresă; doar fără nicio diferență rămâne amintirea;
     *  - adresele (E-LEG-06): o adresă din MailPoet care e deja a altui abonat al site-ului e NU, cu id-urile;
     *  - abonații păstrați la reaplicare (E-LEG-03, ScriereMailPoet::pastrati): un avertisment cu perechile de id-uri.
     */
    public static function verificaSite(Plan $plan, PDO $db): void
    {
        $p = $plan->newsletter;
        if (!$p instanceof self) {
            return;
        }
        $setari = Settings::all($db);
        $diferite = [];
        if ($p->expeditor['nume'] !== '' && $p->expeditor['nume'] !== trim(Brand::numeExpeditor($setari))) {
            $diferite[] = 'numele expeditorului';
        }
        $adresaSite = strtolower(trim((string) ($setari['order_email_from_address'] ?? '')));
        if ($p->expeditor['adresa'] !== '' && $p->expeditor['adresa'] !== $adresaSite) {
            $diferite[] = 'adresa expeditorului';
        }
        if ($p->expeditor['raspuns'] !== '' && $p->expeditor['raspuns'] !== ($adresaSite !== '' ? $adresaSite : $p->expeditor['adresa'])) {
            $diferite[] = 'adresa de răspuns (Reply-To)';
        }
        if ($diferite !== []) {
            $plan->info = array_values(array_filter($plan->info, static fn (string $t): bool => $t !== 'newsletter (MailPoet): ' . self::AMINTIRE_EXPEDITOR));
            $plan->avertismente[] = 'newsletter (MailPoet): ' . implode(', ', $diferite) . ' din MailPoet diferă de Admin → Emailuri → Setări trimitere: '
                . 'emailurile site-ului ar pleca altfel decât cele ale MailPoet; potrivește-le înainte de comutare';
        }
        foreach (ScriereMailPoet::coliziuni($db, $p) as $c) {
            $plan->probleme[] = 'newsletter (MailPoet): ' . $c . '; importul nu poate scrie peste';
        }
        $pastrati = ScriereMailPoet::pastrati($db, $p);
        if ($pastrati !== []) {
            $plan->avertismente[] = 'newsletter (MailPoet): ' . count($pastrati) . ' abonați s-au dezabonat, și-au dat acordul ori s-au schimbat (ei sau listele lor) '
                . 'pe site-ul nou după datele lor din MailPoet (sau s-au abonat acolo cu aceeași adresă): își păstrează starea, acordul și listele de pe site-ul nou; '
                . 'din MailPoet primesc doar legătura cu '
                . 'adresele vechi și o retragere mai nouă (id MailPoet→site: ' . implode(', ', array_map(static fn (int $mp, int $id): string => $mp . '→' . $id,
                    array_keys($pastrati), $pastrati)) . ')';
        }
    }
}
