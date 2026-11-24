<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /**
     * Condițiile unei rute (lista închisă): o rută cu o condiție se potrivește doar când condiția e îndeplinită pe site-ul acesta;
     * altfel routerul o sare ca și cum n-ar fi înregistrată (cererea ajunge la ruta de după, de obicei `/{slug*}`), deci un site
     * fără condiție răspunde exact ca înainte. Uneltele care listează rutele (verifica-rute.php, instantaneu-rute.php) le văd pe
     * toate, cu condiția lor.
     *  - `magazin_wc`: magazinul în forma WooCommerce (`wp-compat.json` → `magazin_wc`; App\Support\MagazinWc\Mod, pista A).
     */
    public const CONDITII = [
        'magazin_wc' => [\App\Support\MagazinWc\Mod::class, 'activ'],
    ];

    private array $routes = [];

    /** Condiția fiecărei rute (CONDITII), după metodă și locul ei în lista metodei; lipsă = fără condiție. @var array<string, array<int, string>> */
    private array $conditii = [];

    /**
     * @param (callable(string, string, array): bool)|null $garda chemată după ce
     *   s-a găsit ruta și înainte de controller, cu metoda, tiparul rutei și
     *   parametrii ei. Dacă întoarce false, garda a răspuns deja (403/404/redirect)
     *   și controllerul nu mai rulează. Vezi `GardaPluginuri`.
     */
    public function __construct(private mixed $garda = null)
    {
    }

    /** @param string|null $conditie o cheie din CONDITII: ruta se potrivește doar cu condiția îndeplinită */
    public function get(string $pattern, callable|array $handler, ?string $conditie = null): void
    {
        $this->addRoute('GET', $pattern, $handler, $conditie);
    }

    /** @param string|null $conditie o cheie din CONDITII: ruta se potrivește doar cu condiția îndeplinită */
    public function post(string $pattern, callable|array $handler, ?string $conditie = null): void
    {
        $this->addRoute('POST', $pattern, $handler, $conditie);
    }

    /** Condiția e îndeplinită pe site-ul acesta? (null = ruta fără condiție: mereu) */
    public static function conditieIndeplinita(?string $conditie): bool
    {
        if ($conditie === null) {
            return true;
        }
        $f = self::CONDITII[$conditie] ?? null;
        return $f !== null && (bool) $f();
    }

    /**
     * Toate rutele înregistrate, în ordinea în care se încearcă.
     *
     * Cu `conditie`: cheia din CONDITII a unei rute condiționate (null = fără condiție).
     *
     * @return list<array{metoda: string, tipar: string, handler: callable|array, conditie: ?string}>
     */
    public function rute(): array
    {
        $lista = [];
        foreach ($this->routes as $metoda => $rute) {
            foreach ($rute as $i => $ruta) {
                $lista[] = ['metoda' => (string) $metoda, 'tipar' => $ruta[2], 'handler' => $ruta[1], 'conditie' => $this->conditii[$metoda][$i] ?? null];
            }
        }
        return $lista;
    }

    public function dispatch(string $method, string $uri): void
    {
        $gasita = $this->gaseste($method, $uri);
        if ($gasita === null) {
            http_response_code(404);
            echo 'Pagina nu a fost găsită.';
            return;
        }
        ['handler' => $handler, 'tipar' => $pattern, 'parametri' => $params] = $gasita;

        // Ruta e a unui plugin pe care site-ul nu-l are? Garda răspunde în locul controllerului.
        if ($this->garda !== null && !($this->garda)($method, $pattern, $params)) {
            return;
        }

        if (is_array($handler)) {
            [$class, $action] = $handler;
            $controller = new $class();
            if ($params === []) {
                $controller->{$action}();
            } else {
                $controller->{$action}($params);
            }
            return;
        }

        if ($params === []) {
            $handler();
        } else {
            $handler($params);
        }
    }

    /**
     * Prima rută care se potrivește cu metoda și adresa (ordinea înregistrării
     * contează: ruta generală `/{slug*}` e ultima), cu parametrii ei.
     *
     * @return array{handler: callable|array, tipar: string, parametri: array<string, string>}|null
     */
    public function gaseste(string $method, string $uri): ?array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        $methodRoutes = $this->routes[$method] ?? [];

        foreach ($methodRoutes as $i => $route) {
            [$regex, $handler, $pattern] = $route;
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }
            // O rută condiționată (CONDITII) pe un site fără condiția ei nu există: cererea merge la ruta de după.
            $conditie = $this->conditii[$method][$i] ?? null;
            if ($conditie !== null && !self::conditieIndeplinita($conditie)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    // Adresa vine percent-encodată de browser. Fără decodare,
                    // un slug cu diacritice („kit-vacanță" → „kit-vacan%C8%9B%C4%83")
                    // nu s-ar potrivi niciodată cu cel din baza de date.
                    $decodat = rawurldecode($value);
                    // Octeții nuli nu au ce căuta într-un parametru de rută.
                    $params[$key] = str_replace("\0", '', $decodat);
                }
            }

            return ['handler' => $handler, 'tipar' => $pattern, 'parametri' => $params];
        }

        return null;
    }

    private function addRoute(string $method, string $pattern, callable|array $handler, ?string $conditie = null): void
    {
        if ($conditie !== null && !isset(self::CONDITII[$conditie])) {
            throw new \InvalidArgumentException('condiție de rută necunoscută: ' . $conditie);
        }
        $regexPattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\*\}#', '(?P<$1>.+)', $pattern);
        if (!is_string($regexPattern)) {
            $regexPattern = $pattern;
        }
        $regexPattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $regexPattern);
        if (!is_string($regexPattern)) {
            $regexPattern = $pattern;
        }
        $regex = '#^' . $regexPattern . '$#';
        $this->routes[$method][] = [$regex, $handler, $pattern];
        if ($conditie !== null) {
            $this->conditii[$method][array_key_last($this->routes[$method])] = $conditie;
        }
    }
}
