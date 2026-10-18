<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    private array $routes = [];

    /**
     * @param (callable(string, string, array): bool)|null $garda chemată după ce
     *   s-a găsit ruta și înainte de controller, cu metoda, tiparul rutei și
     *   parametrii ei. Dacă întoarce false, garda a răspuns deja (403/404/redirect)
     *   și controllerul nu mai rulează. Vezi `GardaPluginuri`.
     */
    public function __construct(private mixed $garda = null)
    {
    }

    public function get(string $pattern, callable|array $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * Toate rutele înregistrate, în ordinea în care se încearcă.
     *
     * @return list<array{metoda: string, tipar: string, handler: callable|array}>
     */
    public function rute(): array
    {
        $lista = [];
        foreach ($this->routes as $metoda => $rute) {
            foreach ($rute as $ruta) {
                $lista[] = ['metoda' => (string) $metoda, 'tipar' => $ruta[2], 'handler' => $ruta[1]];
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

        foreach ($methodRoutes as $route) {
            [$regex, $handler, $pattern] = $route;
            if (!preg_match($regex, $path, $matches)) {
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

    private function addRoute(string $method, string $pattern, callable|array $handler): void
    {
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
    }
}
