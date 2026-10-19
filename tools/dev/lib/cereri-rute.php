<?php

declare(strict_types=1);

/**
 * Lista cererilor pentru garda rutelor, comună testului pluginurilor (secțiunea B,
 * cu cereri HTTP) și instantaneului rutelor (tools/dev/instantaneu-rute.php, fără
 * HTTP): fiecare rută din router, plus variantele rutelor împărțite (o cerere pentru
 * fiecare regulă din `rute_impartite`) și paginile din bază ale pluginurilor
 * (servite de ruta generală `/{slug*}`).
 *
 * $valoare(tipar, numeParametru, metoda) dă valoarea fiecărui parametru de rută:
 * testul ia date reale din bază pentru GET și valori inexistente pentru POST.
 *
 * Fiecare cerere: metoda, tipar, cale (gata de cerut), parametri (ai rutei), get,
 * post (ai variantei), din_ruta (parametrii de rută puși de variantă, de exemplu
 * section=automation) și pagina (slug-ul, pentru paginile pluginurilor).
 *
 * @param callable(string, string, string): string $valoare
 * @return list<array{metoda: string, tipar: string, cale: string, parametri: array<string, string>,
 *     get: array<string, string>, post: array<string, string>, din_ruta: array<string, string>, pagina: ?string}>
 */
function cereri_rute(\App\Http\Router $router, callable $valoare): array
{
    $cereri = [];
    foreach ($router->rute() as $ruta) {
        $metoda = $ruta['metoda'];
        $tipar = $ruta['tipar'];
        $parametri = [];
        $cale = (string) preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(\*?)\}#', static function (array $m) use ($tipar, $metoda, $valoare, &$parametri): string {
            $v = $valoare($tipar, $m[1], $metoda);
            $parametri[$m[1]] = $v;
            return $m[2] === '*' ? implode('/', array_map('rawurlencode', explode('/', $v))) : rawurlencode($v);
        }, str_replace('\\.', '.', $tipar));
        $variante = [['get' => [], 'post' => [], 'parametri' => $parametri, 'cale' => $cale, 'din_ruta' => [], 'pagina' => null]];
        $impartita = \App\Support\Plugins::impartitaNormalizata($metoda, $tipar);
        if ($impartita !== null) {
            $variante = [];
            foreach ($impartita as [$conditii]) {
                $v = ['get' => [], 'post' => [], 'parametri' => $parametri, 'cale' => $cale, 'din_ruta' => [], 'pagina' => null];
                foreach ((array) $conditii as $cheie => $valori) {
                    [$sursa, $nume] = explode('.', (string) $cheie, 2);
                    $primul = rtrim((string) ((array) $valori)[0], '*');
                    if ($sursa === 'ruta') {
                        $v['parametri'][$nume] = $primul;
                        $v['din_ruta'][$nume] = $primul;
                        $v['cale'] = str_replace('/' . rawurlencode($parametri[$nume]), '/' . rawurlencode($primul), $v['cale']);
                    } else {
                        $v[$sursa][$nume] = $primul . (str_ends_with((string) ((array) $valori)[0], '*') ? 'save' : '');
                    }
                }
                $variante[] = $v;
            }
        }
        if ($tipar === '/{slug*}') {
            foreach (\App\Support\Plugins::chei() as $cheie) {
                foreach ((array) (\App\Support\Plugins::definitie($cheie)['pagini'] ?? []) as $pagina) {
                    $variante[] = ['get' => [], 'post' => [], 'parametri' => ['slug' => $pagina], 'cale' => '/' . $pagina,
                        'din_ruta' => [], 'pagina' => (string) $pagina];
                }
            }
        }
        foreach ($variante as $v) {
            $cereri[] = ['metoda' => $metoda, 'tipar' => $tipar] + $v;
        }
    }
    return $cereri;
}
