<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Căutarea în tot adminul (faza D, WP-36): `GET /admin/cauta?q=` și fereastra de la Ctrl+K (`public/assets/js/admin/cautare.js`).
 *
 * Sursele: comenzile (numărul, numele, emailul), produsele (numele, codul), clienții (numele, emailul), paginile site-ului,
 * postările blogului, paginile și tab-urile din admin (meniul, plus tab-urile setărilor) și câteva acțiuni (produs nou, pagină
 * nouă, coșurile de gunoi). Fiecare sursă apare doar dacă:
 *  - site-ul are pluginul ei (adresa listei e vizibilă după licență, `Plugins::adresaVizibila`, ca meniul și linkurile), și
 *  - rolul adminului are voie la lista ei (`Auth::canAccessPath`, aceleași prefixe ca garda paginilor).
 * Un rezultat duce doar la o pagină (GET) pe care adminul o poate deschide; nimic din căutare nu face o acțiune: o comandă se
 * deschide în panoul ei, iar orice acțiune pe ea cere tot butonul și confirmarea de până acum.
 *
 * Interogările: parametri legați (fiecare cuvânt al căutării, cu %, _ și \ scăpate), cel mult LIMITA rânduri pe sursă, fiecare
 * cu o limită de timp a serverului (MariaDB `max_statement_time`, MySQL `MAX_EXECUTION_TIME`) și un buget pentru toată
 * căutarea: o sursă care nu mai încape în buget se sare și grupul ei e marcat „incomplet”. Nu se scrie nimic în bază.
 */
final class CautareAdmin
{
    /** Cel mai scurt text căutat (mai puțin = nimic căutat). */
    public const MINIM = 2;

    /** Cel mai lung text căutat (restul se taie). */
    public const MAXIM = 80;

    /** Câte rânduri cel mult pe sursă. */
    public const LIMITA = 6;

    /** Limita fiecărei interogări, în milisecunde (o oprește serverul). */
    public const TIMP_INTEROGARE_MS = 250;

    /** Bugetul întregii căutări, în milisecunde: după el, sursele rămase se sar. */
    public const BUGET_MS = 800;

    /** Câte cuvinte ale căutării contează (fiecare trebuie găsit). */
    private const CUVINTE = 4;

    /**
     * Sursele, în ordinea afișării: eticheta grupului și lista din admin a cărei permisiune o cere (null = intrările își
     * verifică fiecare adresa).
     */
    public const SURSE = [
        'comenzi' => ['eticheta' => 'Comenzi', 'cale' => '/admin/orders'],
        'produse' => ['eticheta' => 'Produse', 'cale' => '/admin/products'],
        'clienti' => ['eticheta' => 'Clienți', 'cale' => '/admin/users'],
        'pagini' => ['eticheta' => 'Pagini', 'cale' => '/admin/pages'],
        'postari' => ['eticheta' => 'Postări blog', 'cale' => '/admin/blog/posts'],
        'setari' => ['eticheta' => 'Pagini și setări din admin', 'cale' => null],
        'actiuni' => ['eticheta' => 'Acțiuni', 'cale' => null],
    ];

    private const ETICHETE_STARE = ['pending' => 'În așteptare', 'pending_payment' => 'Plată în așteptare', 'processing' => 'În procesare',
        'completed' => 'Finalizată', 'cancelled' => 'Anulată', 'refunded' => 'Rambursată', 'returned' => 'Returnată', 'failed' => 'Eșuată'];

    /**
     * Rolurile pentru care se verifică permisiunile: null = adminul logat (sesiunea), altfel lista dată (testele).
     *
     * @param list<string>|null $roluri
     */
    private static function poate(string $adresa, ?array $roluri): bool
    {
        return Auth::canAccessPath($adresa, $roluri === null ? '' : $roluri);
    }

    /**
     * Sursele pe care le vede adminul: pluginul lor pe site și dreptul rolului pe lista lor.
     *
     * @param list<string>|null $roluri
     * @return list<string>
     */
    public static function surse(?array $roluri = null): array
    {
        $surse = [];
        foreach (self::SURSE as $cheie => $sursa) {
            $cale = $sursa['cale'];
            if ($cale !== null && (!self::poate($cale, $roluri) || !Plugins::adresaVizibila($cale))) {
                continue;
            }
            $surse[] = $cheie;
        }
        return $surse;
    }

    /**
     * Textul de sub căutare din antet: ce se poate căuta, doar din sursele vizibile („Caută comenzi, produse, clienți…”).
     *
     * @param list<string>|null $roluri
     */
    public static function indemn(?array $roluri = null): string
    {
        $nume = ['comenzi' => 'comenzi', 'produse' => 'produse', 'clienti' => 'clienți', 'pagini' => 'pagini', 'postari' => 'postări'];
        $alese = array_values(array_intersect_key($nume, array_flip(self::surse($roluri))));
        $alese = array_slice($alese, 0, 3);
        $alese[] = 'setări';
        return 'Caută ' . implode(', ', $alese) . '…';
    }

    /** Textul căutat, curățat: spațiile strânse, cel mult MAXIM caractere. */
    public static function curata(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_substr($text, 0, self::MAXIM);
    }

    /** Litere mici, fără diacritice (pentru potrivirea paginilor din admin, care nu sunt în bază). */
    public static function normalizeaza(string $text): string
    {
        $text = mb_strtolower($text);
        return strtr($text, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'é' => 'e', 'ü' => 'u', 'ö' => 'o']);
    }

    /**
     * Căutarea.
     *
     * @param list<string>|null $roluri null = adminul logat
     * @return array{q: string, grupuri: list<array{sursa: string, eticheta: string, rezultate: list<array{titlu: string, detaliu: string, url: string}>, incomplet: bool}>, ms: int}
     */
    public static function cauta(?PDO $db, string $q, ?array $roluri = null): array
    {
        $inceput = microtime(true);
        $q = self::curata($q);
        $rezultat = ['q' => $q, 'grupuri' => [], 'ms' => 0];
        if (mb_strlen($q) < self::MINIM) {
            return $rezultat;
        }
        $cuvinte = array_slice(array_values(array_filter(explode(' ', $q), static fn (string $c): bool => $c !== '')), 0, self::CUVINTE);
        foreach (self::surse($roluri) as $sursa) {
            $grup = ['sursa' => $sursa, 'eticheta' => self::SURSE[$sursa]['eticheta'], 'rezultate' => [], 'incomplet' => false];
            $trecut = (microtime(true) - $inceput) * 1000;
            if (in_array($sursa, ['setari', 'actiuni'], true)) {
                $grup['rezultate'] = self::potrivesteStatic($sursa === 'setari' ? self::paginiAdmin($roluri) : self::actiuni($roluri), $cuvinte);
            } elseif (!$db instanceof PDO || $trecut > self::BUGET_MS) {
                $grup['incomplet'] = $db instanceof PDO;
            } else {
                try {
                    $grup['rezultate'] = match ($sursa) {
                        'comenzi' => self::comenzi($db, $q, $cuvinte),
                        'produse' => self::produse($db, $q, $cuvinte),
                        'clienti' => self::clienti($db, $cuvinte),
                        'pagini' => self::pagini($db, $cuvinte),
                        'postari' => self::postari($db, $cuvinte),
                    };
                } catch (Throwable) {
                    // Interogarea oprită de limita de timp a serverului (sau o tabelă lipsă): grupul rămâne gol, marcat.
                    $grup['incomplet'] = true;
                }
            }
            if ($grup['rezultate'] !== [] || $grup['incomplet']) {
                $rezultat['grupuri'][] = $grup;
            }
        }
        $rezultat['ms'] = (int) round((microtime(true) - $inceput) * 1000);
        return $rezultat;
    }

    // ------------------------------------------------------------------ bază

    /**
     * Condiția „fiecare cuvânt în cel puțin una dintre coloane”, cu parametri legați (nume unice) și metacaracterele LIKE
     * scăpate.
     *
     * @param list<string> $coloane nume fixe din cod
     * @param list<string> $cuvinte
     * @param array<string, string> $parametri se completează
     */
    private static function conditie(array $coloane, array $cuvinte, array &$parametri, string $prefix): string
    {
        $parti = [];
        foreach ($cuvinte as $i => $cuvant) {
            $sau = [];
            foreach ($coloane as $j => $coloana) {
                $nume = $prefix . $i . '_' . $j;
                $parametri[$nume] = '%' . self::scapaLike($cuvant) . '%';
                $sau[] = $coloana . ' LIKE :' . $nume;
            }
            $parti[] = '(' . implode(' OR ', $sau) . ')';
        }
        return implode(' AND ', $parti);
    }

    private static function scapaLike(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /** SELECT-ul cu limita de timp a serverului: MariaDB (siteurile) sau MySQL. */
    private static function cuLimita(PDO $db, string $select): string
    {
        $versiune = '';
        try {
            $versiune = (string) $db->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
        }
        if (stripos($versiune, 'mariadb') !== false) {
            return 'SET STATEMENT max_statement_time = ' . sprintf('%.3F', self::TIMP_INTEROGARE_MS / 1000) . ' FOR ' . $select;
        }
        return (string) preg_replace('/^SELECT /', 'SELECT /*+ MAX_EXECUTION_TIME(' . self::TIMP_INTEROGARE_MS . ') */ ', $select, 1);
    }

    /** @return list<array<string, mixed>> */
    private static function randuri(PDO $db, string $select, array $parametri): array
    {
        $stmt = $db->prepare(self::cuLimita($db, $select));
        $stmt->execute($parametri);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function lei(mixed $suma): string
    {
        return number_format((float) $suma, 2, ',', '.') . ' lei';
    }

    private static function data(mixed $data): string
    {
        $moment = is_string($data) && $data !== '' ? strtotime($data) : false;
        return $moment === false ? '' : date('d.m.Y', $moment);
    }

    /** @param list<string> $cuvinte */
    private static function comenzi(PDO $db, string $q, array $cuvinte): array
    {
        $parametri = ['exact' => $q, 'inceput' => self::scapaLike($q) . '%'];
        $randuri = self::randuri($db,
            'SELECT id, order_number, billing_first_name, billing_last_name, billing_email, total, status, created_at FROM orders
             WHERE deleted_at IS NULL AND ' . self::conditie(['order_number', 'billing_first_name', 'billing_last_name', 'billing_email'], $cuvinte, $parametri, 'c') . '
             ORDER BY (order_number = :exact) DESC, (order_number LIKE :inceput) DESC, id DESC LIMIT ' . self::LIMITA,
            $parametri);
        return array_map(static function (array $r): array {
            $nume = trim((string) $r['billing_first_name'] . ' ' . (string) $r['billing_last_name']);
            $detaliu = array_filter([$nume !== '' ? $nume : (string) $r['billing_email'], self::data($r['created_at']), self::lei($r['total']),
                self::ETICHETE_STARE[(string) $r['status']] ?? (string) $r['status']], static fn (string $p): bool => $p !== '');
            return ['titlu' => 'Comanda ' . (string) $r['order_number'], 'detaliu' => implode(' · ', $detaliu),
                'url' => '/admin/orders?q=' . rawurlencode((string) $r['order_number'])];
        }, $randuri);
    }

    /** @param list<string> $cuvinte */
    private static function produse(PDO $db, string $q, array $cuvinte): array
    {
        $parametri = ['exact' => $q, 'inceput' => self::scapaLike($q) . '%'];
        $randuri = self::randuri($db,
            'SELECT id, name, sku, is_active FROM products
             WHERE deleted_at IS NULL AND ' . self::conditie(['name', 'sku'], $cuvinte, $parametri, 'p') . '
             ORDER BY (sku = :exact) DESC, (name LIKE :inceput) DESC, name, id LIMIT ' . self::LIMITA,
            $parametri);
        return array_map(static fn (array $r): array => [
            'titlu' => (string) $r['name'],
            'detaliu' => implode(' · ', array_filter([trim((string) $r['sku']) !== '' ? 'Cod ' . trim((string) $r['sku']) : '',
                (int) $r['is_active'] === 1 ? '' : 'inactiv'], static fn (string $p): bool => $p !== '')),
            // Lista produselor deschide editorul produsului din adresă (WP-35, „#editeaza-<id>”).
            'url' => '/admin/products#editeaza-' . (int) $r['id'],
        ], $randuri);
    }

    /** @param list<string> $cuvinte */
    private static function clienti(PDO $db, array $cuvinte): array
    {
        $parametri = [];
        $randuri = self::randuri($db,
            'SELECT id, first_name, last_name, email FROM users
             WHERE ' . self::conditie(['first_name', 'last_name', 'email'], $cuvinte, $parametri, 'u') . '
             ORDER BY last_name, first_name, id LIMIT ' . self::LIMITA,
            $parametri);
        return array_map(static function (array $r): array {
            $nume = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']);
            return ['titlu' => $nume !== '' ? $nume : (string) $r['email'], 'detaliu' => $nume !== '' ? (string) $r['email'] : '',
                'url' => '/admin/users/' . (int) $r['id']];
        }, $randuri);
    }

    /** @param list<string> $cuvinte */
    private static function pagini(PDO $db, array $cuvinte): array
    {
        $parametri = [];
        // Câteva în plus: paginile pluginurilor pe care site-ul nu le are (coș, blog…) se scot după, ca în lista paginilor.
        $randuri = self::randuri($db,
            'SELECT id, title, slug, is_published FROM pages
             WHERE deleted_at IS NULL AND ' . self::conditie(['title', 'slug'], $cuvinte, $parametri, 'g') . '
             ORDER BY title, id LIMIT ' . (self::LIMITA * 3),
            $parametri);
        $randuri = array_values(array_filter($randuri,
            static fn (array $r): bool => Plugins::adresaVizibila('/' . ltrim((string) $r['slug'], '/'))));
        return array_map(static fn (array $r): array => [
            'titlu' => (string) $r['title'],
            'detaliu' => '/' . ltrim((string) $r['slug'], '/') . ' · ' . ((int) $r['is_published'] === 1 ? 'publicată' : 'nepublicată'),
            'url' => '/admin/pages/' . (int) $r['id'] . '/edit',
        ], array_slice($randuri, 0, self::LIMITA));
    }

    /** @param list<string> $cuvinte */
    private static function postari(PDO $db, array $cuvinte): array
    {
        $parametri = [];
        $randuri = self::randuri($db,
            'SELECT id, title, is_published, published_at FROM blog_posts
             WHERE deleted_at IS NULL AND ' . self::conditie(['title', 'slug'], $cuvinte, $parametri, 'b') . '
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT ' . self::LIMITA,
            $parametri);
        return array_map(static fn (array $r): array => [
            'titlu' => (string) $r['title'],
            'detaliu' => (int) $r['is_published'] === 1 ? trim('publicată ' . self::data($r['published_at'])) : 'ciornă',
            'url' => '/admin/blog/posts/editor?post=' . (int) $r['id'],
        ], $randuri);
    }

    // ------------------------------------------------------------------ paginile adminului și acțiunile

    /**
     * Paginile din admin: intrările meniului (din catalog: doar ale pluginurilor site-ului, cu etichetele lor de pe site) și
     * tab-urile paginilor de setări, fiecare doar cu pluginul ei și cu dreptul rolului pe adresa ei.
     *
     * @param list<string>|null $roluri
     * @return list<array{titlu: string, detaliu: string, url: string, cuvinte: string}>
     */
    public static function paginiAdmin(?array $roluri = null): array
    {
        $intrari = [];
        foreach (Plugins::meniuAdmin() as $slot) {
            foreach ($slot['intrari'] as $intrare) {
                $adresa = (string) ($intrare['permisiune'] ?? $intrare['href']);
                if (!self::poate($adresa, $roluri)) {
                    continue;
                }
                $intrari[] = ['titlu' => (string) $intrare['eticheta'], 'detaliu' => $slot['tip'] === 'link' ? '' : (string) $slot['eticheta'],
                    'url' => (string) $intrare['href'], 'cuvinte' => ''];
            }
        }
        $magazin = Plugins::vizibil('magazin');
        $setari = $magazin ? 'Setări magazin' : 'Setări site';
        $tab = static fn (string $titlu, string $pagina, string $url, string $cuvinte): array
            => ['titlu' => $titlu, 'detaliu' => $pagina, 'url' => $url, 'cuvinte' => $cuvinte];
        $taburi = [
            $tab('General', $setari, '/admin/settings/store?tab=general', 'nume site titluri favicon iconita mentenanta revenim in curand culoarea adminului accent'),
            Plugins::oricareVizibil(['catalog', 'magazin', 'oferte_bbd'])
                ? $tab($magazin ? 'Magazin' : 'Produse', $setari, '/admin/settings/store?tab=magazin',
                    implode(' ', array_filter([Plugins::vizibil('catalog') ? 'tva cota' : '', $magazin ? 'numerotarea comenzilor numar comanda cantitate' : '',
                        Plugins::vizibil('oferte_bbd') ? 'sertar oferte' : '']))) : null,
            $tab('SEO și Google', $setari, '/admin/settings/store?tab=seo', 'seo sitemap harta site clarity google analytics tag manager ads verificare proprietate'),
            $tab('Cookie și popup', $setari, '/admin/settings/store?tab=cookies', 'cookie banner acord popup anunt fereastra'),
            $tab('Viteză', $setari, '/admin/settings/store?tab=viteza', 'viteza cache memorie rapida'),
            Plugins::vizibil('chat_live') ? $tab('Chat live', $setari, '/admin/settings/store?tab=chat', 'chat live') : null,
            $magazin ? $tab('Ordin de plată', 'Setări plăți', '/admin/settings/payments?tab=op', 'ordin plata transfer bancar iban op') : null,
            Plugins::vizibil('plata_euplatesc') ? $tab('Card – EuPlătesc', 'Setări plăți', '/admin/settings/payments?tab=euplatesc', 'card euplatesc') : null,
            Plugins::vizibil('plata_stripe') ? $tab($magazin ? 'Card – Stripe' : 'Stripe', 'Setări plăți', '/admin/settings/payments?tab=stripe', 'card stripe webhook') : null,
            Plugins::vizibil('plata_bt') ? $tab('Banca Transilvania', 'Setări plăți', '/admin/settings/payments?tab=bt', 'card bt ipay') : null,
            Plugins::vizibil('livrare_fan') ? $tab('Livrare', 'Setări livrare', '/admin/settings/shipping?tab=livrare', 'pret transport gratuit reguli ridicare personala') : null,
            Plugins::vizibil('livrare_fan') ? $tab('Curier FAN', 'Setări livrare', '/admin/settings/shipping?tab=fan', 'fan courier cont awb localitati strazi fanbox') : null,
            Plugins::vizibil('fidelitate') ? $tab('Reguli promo', 'Fidelitate', '/admin/users/points?tab=promo', 'puncte reguli promo') : null,
            Plugins::vizibil('fidelitate') ? $tab('Puncte clienți', 'Fidelitate', '/admin/users/points?tab=clienti', 'puncte clienti sold') : null,
            Plugins::vizibil('fidelitate') ? $tab('Toate tranzacțiile', 'Fidelitate', '/admin/users/points?tab=istoric', 'puncte tranzactii istoric') : null,
            Plugins::vizibil('fidelitate') ? $tab('Import', 'Fidelitate', '/admin/users/points?tab=import', 'puncte import') : null,
            Plugins::vizibil('catalog') ? $tab('Câmpuri suplimentare', 'Câmpuri și șabloane', '/admin/products/fields', 'campuri produse') : null,
            Plugins::vizibil('catalog') ? $tab('Șabloane produse', 'Câmpuri și șabloane', '/admin/products/templates', 'sabloane template produse') : null,
            Plugins::vizibil('cupoane') ? $tab('Coduri unice', 'Cupoane', '/admin/coupons/unique', 'cupoane coduri unice') : null,
        ];
        foreach ($taburi as $intrare) {
            if ($intrare !== null && self::poate($intrare['url'], $roluri) && Plugins::adresaVizibila($intrare['url'])) {
                $intrari[] = $intrare;
            }
        }
        return self::faraDubluri($intrari);
    }

    /**
     * Acțiunile: doar adrese care deschid o pagină (un formular gol, o listă); niciuna nu schimbă ceva singură.
     *
     * @param list<string>|null $roluri
     * @return list<array{titlu: string, detaliu: string, url: string, cuvinte: string}>
     */
    public static function actiuni(?array $roluri = null): array
    {
        $lista = [
            ['titlu' => 'Produs nou', 'detaliu' => 'Produse', 'url' => '/admin/products/new', 'cuvinte' => 'adauga creeaza produs nou'],
            ['titlu' => 'Pagină nouă', 'detaliu' => 'Pagini', 'url' => '/admin/pages/new', 'cuvinte' => 'adauga creeaza pagina noua'],
            ['titlu' => 'Postare nouă', 'detaliu' => 'Postări blog', 'url' => '/admin/blog/posts/new', 'cuvinte' => 'adauga creeaza scrie articol postare noua'],
            ['titlu' => 'Comenzi șterse', 'detaliu' => 'Coșul de gunoi', 'url' => '/admin/orders/trash', 'cuvinte' => 'cos gunoi sterse comenzi'],
            ['titlu' => 'Produse șterse', 'detaliu' => 'Coșul de gunoi', 'url' => '/admin/products/trash', 'cuvinte' => 'cos gunoi sterse produse'],
            ['titlu' => 'Pagini șterse', 'detaliu' => 'Coșul de gunoi', 'url' => '/admin/pages/trash', 'cuvinte' => 'cos gunoi sterse pagini'],
            ['titlu' => 'Postări șterse', 'detaliu' => 'Coșul de gunoi', 'url' => '/admin/blog/posts/trash', 'cuvinte' => 'cos gunoi sterse postari articole'],
        ];
        return array_values(array_filter($lista, static fn (array $a): bool => self::poate($a['url'], $roluri) && Plugins::adresaVizibila($a['url'])));
    }

    /** @param list<array{url: string}> $intrari */
    private static function faraDubluri(array $intrari): array
    {
        $vazute = [];
        $unice = [];
        foreach ($intrari as $intrare) {
            if (isset($vazute[$intrare['url']])) {
                continue;
            }
            $vazute[$intrare['url']] = true;
            $unice[] = $intrare;
        }
        return $unice;
    }

    /**
     * Potrivirea intrărilor fără bază: fiecare cuvânt căutat (fără diacritice) în titlu, pagina din care face parte sau
     * cuvintele ei; întâi cele al căror titlu începe cu textul căutat.
     *
     * @param list<array{titlu: string, detaliu: string, url: string, cuvinte: string}> $intrari
     * @param list<string> $cuvinte
     * @return list<array{titlu: string, detaliu: string, url: string}>
     */
    private static function potrivesteStatic(array $intrari, array $cuvinte): array
    {
        $cautate = array_map([self::class, 'normalizeaza'], $cuvinte);
        $gasite = [];
        foreach ($intrari as $pozitie => $intrare) {
            $titlu = self::normalizeaza($intrare['titlu']);
            $tot = $titlu . ' ' . self::normalizeaza($intrare['detaliu']) . ' ' . self::normalizeaza($intrare['cuvinte']);
            $toate = true;
            foreach ($cautate as $cuvant) {
                if (!str_contains($tot, $cuvant)) {
                    $toate = false;
                    break;
                }
            }
            if (!$toate) {
                continue;
            }
            $scor = str_starts_with($titlu, implode(' ', $cautate)) ? 0 : (preg_match('/\b' . preg_quote($cautate[0], '/') . '/u', $titlu) === 1 ? 1 : 2);
            $gasite[] = [$scor, $pozitie, ['titlu' => $intrare['titlu'], 'detaliu' => $intrare['detaliu'], 'url' => $intrare['url']]];
        }
        usort($gasite, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_map(static fn (array $g): array => $g[2], array_slice($gasite, 0, self::LIMITA + 2));
    }

    // ------------------------------------------------------------------ scurtăturile „G”

    /**
     * Literele navigării „G <literă>” (lista de la „?”): fiecare duce la prima pagină a unui grup din meniu, cea pe care o
     * vede adminul (după licență și rol), deci o scurtătură a unui grup gol pe site lipsește cu tot cu rândul ei din listă.
     */
    public const LITERE_GRUPURI = ['acasa' => 'a', 'comenzi' => 'c', 'produse' => 'p', 'clienti' => 'l', 'marketing' => 'm',
        'continut' => 't', 'rapoarte' => 'r', 'setari' => 's'];

    /**
     * @param list<string>|null $roluri
     * @return list<array{litera: string, eticheta: string, pagina: string, url: string}>
     */
    public static function salturi(?array $roluri = null): array
    {
        $salturi = [];
        foreach (Plugins::meniuAdmin() as $slot) {
            $litera = self::LITERE_GRUPURI[$slot['cheie']] ?? null;
            if ($litera === null) {
                continue;
            }
            $permise = array_values(array_filter($slot['intrari'],
                static fn (array $intrare): bool => self::poate((string) ($intrare['permisiune'] ?? $intrare['href']), $roluri)));
            if ($permise === []) {
                continue;
            }
            // Ca în meniu: un grup cu o singură pagină vizibilă se numește ca pagina (pe un catalog fără magazin „Cereri de ofertă”).
            $grup = $slot['tip'] === 'link' || count($permise) === 1 ? (string) $permise[0]['eticheta'] : (string) $slot['eticheta'];
            $salturi[] = ['litera' => $litera, 'eticheta' => $grup, 'pagina' => (string) $permise[0]['eticheta'], 'url' => (string) $permise[0]['href']];
        }
        return $salturi;
    }
}
