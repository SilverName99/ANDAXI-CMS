<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Punctele de legătură dintre nucleu și pluginuri (WP-24: VT-X2, port-vertij §4.2). Nucleul cheamă un punct
 * (`ruleaza`, `html`, `oricare`); un plugin se agață de el dintr-un singur loc, `config/hooks.php` (fără dosare de
 * plugin încă, ANALIZA §6). O agățare contează doar cât pluginul ei e vizibil (`Plugins::vizibil`: pornit sau se
 * oprește) sau, cu `'stare' => 'activ'`, doar cât e pornit (de exemplu ce începe ceva nou, cum e o înregistrare).
 * Fără nicio agățare, fiecare punct întoarce valoarea „nimic”, deci nucleul se poartă exact ca înainte.
 *
 * Punctele (cine le cheamă → cine se agață, în Etapa 4):
 *  - `inregistrare.formular` (HTML): panoul de înregistrare din contul clientului și `{{auth_form}}` → `membri`
 *    (alegerea profesiei și câmpurile ei);
 *  - `inregistrare.valideaza($db, $setari)` → `['error' => ?string, 'data' => array, 'cere_campuri' => list<string>]`:
 *    înainte de crearea contului → `membri`;
 *  - `inregistrare.cont_existent($db, $email, $date)` → adresa unei redirecționări sau null: adresa are deja cont
 *    (de exemplu reluarea unei înregistrări neconfirmate) → `membri`;
 *  - `inregistrare.dupa_creare($db, $idUtilizator, $date)` → adresa unei redirecționări sau null (cu o adresă, fără
 *    logare automată) → `membri`;
 *  - `cont.taburi($db, $utilizator)` → listă de `{cheie, eticheta, icon, insigna, render}`: tab-urile contului (WP-25)
 *    → `donatii`, `membri`;
 *  - `cont.antet($db, $utilizator)` → statistici în antetul contului (WP-25) → `membri`;
 *  - `continut.membru_activ($db, $utilizator)` → bool: are cont activ? (App\Support\AccesMembri) → `membri`;
 *  - `continut.nota($db, $utilizator, $titlu)` → HTML-ul paginii „acces restricționat” (AccesMembri) → `membri`;
 *  - `editor.pagina.campuri`, `editor.articol.campuri` (HTML, cu rândul editat): câmpuri în editorul paginii și al
 *    articolului; „Doar cont activ” trimite și `requires_active_account_present=1` → `membri`;
 *  - `utilizator.sters($db, int[] $iduri, 'admin'|'cont')`: conturi șterse din admin sau de client → `membri`
 *    (documente, rânduri, poza de profil), `donatii` (abonamentele active oprite, `user_id` dezlegat);
 *  - `layout.final_body` (HTML): înainte de `</body>` pe paginile publice → `donatii` (fereastra și scripturile ei);
 *  - `plugin.lucruri_in_curs($db)` → int: ce are pluginul în curs (codul lucrurilor în curs, WP-27, Etapa 2);
 *  - `acasa.de_facut($db, $setari)` → listă de `{numar, text, href, actiune, ton?}`: intrările listei „De făcut” de pe
 *    pagina Acasă a adminului (WP-32, App\Support\AcasaAdmin::deFacut; una cu `numar` 0 nu se arată) → `magazin`,
 *    `livrare_fan`, `erp`, `recenzii`, `precomenzi`, `link_plata` (config/hooks.php, din Etapa 1);
 *  - `acasa.sanatate($db, $setari)` → listă de `{text, ton?, href?}`: bucățile rândului de sănătate de pe Acasă (WP-32)
 *    → `erp`, `livrare_fan`.
 */
final class Hooks
{
    /** Punctele cunoscute; un punct necunoscut într-o agățare e o greșeală de scriere (refuzată). */
    public const PUNCTE = [
        'inregistrare.formular', 'inregistrare.valideaza', 'inregistrare.cont_existent', 'inregistrare.dupa_creare',
        'cont.taburi', 'cont.antet', 'continut.membru_activ', 'continut.nota', 'editor.pagina.campuri',
        'editor.articol.campuri', 'utilizator.sters', 'layout.final_body', 'plugin.lucruri_in_curs',
        // WP-32: pagina Acasă a adminului.
        'acasa.de_facut', 'acasa.sanatate',
    ];

    /** @var array<string, list<array{plugin: string, fn: callable, stare: string}>>|null */
    private static ?array $agatari = null;

    /** Agață `$fn` de `$punct`, pentru pluginul `$plugin`. `$stare`: „vizibil” (implicit) sau „activ”. */
    public static function adauga(string $punct, string $plugin, callable $fn, string $stare = 'vizibil'): void
    {
        if (!in_array($punct, self::PUNCTE, true)) {
            throw new \InvalidArgumentException('Hooks: punctul „' . $punct . '” nu există (Hooks::PUNCTE).');
        }
        if (Plugins::definitie($plugin) === null) {
            throw new \InvalidArgumentException('Hooks: pluginul „' . $plugin . '” nu e în catalog.');
        }
        self::incarca();
        self::$agatari[$punct][] = ['plugin' => $plugin, 'fn' => $fn, 'stare' => $stare === 'activ' ? 'activ' : 'vizibil'];
    }

    /**
     * Rezultatul fiecărei agățări care contează acum, în ordinea agățării, cu cheia pluginului.
     *
     * @return array<string, mixed>
     */
    public static function ruleaza(string $punct, mixed ...$argumente): array
    {
        $rezultate = [];
        foreach (self::active($punct) as $agatare) {
            $rezultate[$agatare['plugin']] = ($agatare['fn'])(...$argumente);
        }
        return $rezultate;
    }

    /** HTML-ul tuturor agățărilor (lipite); fără niciuna, gol. */
    public static function html(string $punct, mixed ...$argumente): string
    {
        $html = '';
        foreach (self::ruleaza($punct, ...$argumente) as $bucata) {
            if (is_string($bucata)) {
                $html .= $bucata;
            }
        }
        return $html;
    }

    /** Măcar o agățare a întors true? (De exemplu `continut.membru_activ`.) */
    public static function oricare(string $punct, mixed ...$argumente): bool
    {
        foreach (self::ruleaza($punct, ...$argumente) as $rezultat) {
            if ($rezultat === true) {
                return true;
            }
        }
        return false;
    }

    /** Prima valoare care nu e null (de exemplu o redirecționare din `inregistrare.dupa_creare`), altfel null. */
    public static function primul(string $punct, mixed ...$argumente): mixed
    {
        foreach (self::active($punct) as $agatare) {
            $rezultat = ($agatare['fn'])(...$argumente);
            if ($rezultat !== null) {
                return $rezultat;
            }
        }
        return null;
    }

    /** Are punctul vreo agățare care contează acum? */
    public static function are(string $punct): bool
    {
        return self::active($punct) !== [];
    }

    /** Doar pentru teste: agățările din config/hooks.php se citesc din nou la următoarea întrebare. */
    public static function reseteaza(): void
    {
        self::$agatari = null;
    }

    /** @return list<array{plugin: string, fn: callable, stare: string}> */
    private static function active(string $punct): array
    {
        self::incarca();
        $active = [];
        foreach (self::$agatari[$punct] ?? [] as $agatare) {
            $merge = $agatare['stare'] === 'activ' ? Plugins::activ($agatare['plugin']) : Plugins::vizibil($agatare['plugin']);
            if ($merge) {
                $active[] = $agatare;
            }
        }
        return $active;
    }

    private static function incarca(): void
    {
        if (self::$agatari !== null) {
            return;
        }
        self::$agatari = [];
        $fisiere = [Paths::radacina() . '/config/hooks.php'];
        // Doar în dezvoltare (APP_ENV=local): agățările de probă ale unui test (tools/dev/test-porturi-vt-gr.php),
        // dintr-un fișier dat de CMS_DEV_HOOKS; pe un site nu se citește nimic în afară de config/hooks.php.
        $deProba = trim((string) Env::get('CMS_DEV_HOOKS', ''));
        if ($deProba !== '' && (string) Env::get('APP_ENV', '') === 'local') {
            $fisiere[] = $deProba;
        }
        foreach ($fisiere as $fisier) {
            if (!is_file($fisier)) {
                continue;
            }
            $lista = require $fisier;
            foreach (is_array($lista) ? $lista : [] as $agatare) {
                if (!is_array($agatare) || !isset($agatare['punct'], $agatare['plugin'], $agatare['fn']) || !is_callable($agatare['fn'])) {
                    throw new \InvalidArgumentException(basename($fisier) . ': fiecare agățare are „punct”, „plugin” și „fn” (o funcție).');
                }
                self::adauga((string) $agatare['punct'], (string) $agatare['plugin'], $agatare['fn'], (string) ($agatare['stare'] ?? 'vizibil'));
            }
        }
    }
}
