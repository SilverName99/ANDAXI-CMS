<?php

/**
 * Începutul comun al uneltelor care citesc un site live (tools/verificare/agregate.php,
 * tools/sonda/amprenta-schema.php, WP-10), încărcat înaintea verificării versiunii, deci doar
 * sintaxa PHP 7.0. PHP-ul nu mai tipărește erorile: mesajul lui are calea contului
 * (/home/u…/domains/…) și poate avea o valoare. În locul lor, texte fixe, cu felul erorii și
 * locul ei în codul uneltelor (calea de sub tools/, sau doar numele fișierului):
 * - o eroare care nu oprește rularea (în afara grupurilor, unde unealta o prinde singură și o
 *   scrie ca EROARE) → „AVERTISMENT PHP: <fel> în <loc>” pe stderr;
 * - o eroare fatală (memoria, timpul) → Unelte\Verificare\Rulare::opritDeEroare() scrie ce s-a
 *   strâns până acolo și „OPRIT: …”; dacă s-a oprit înainte de citire, doar „OPRIT: …” (cod 2).
 */

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('html_errors', '0');

$motivEroare = function ($nivel, $mesaj, $fisier, $linie) {
    $nume = array(E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE', E_NOTICE => 'E_NOTICE', E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING', E_COMPILE_ERROR => 'E_COMPILE_ERROR', E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING', E_USER_NOTICE => 'E_USER_NOTICE', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED');
    $fel = isset($nume[$nivel]) ? $nume[$nivel] : 'E_' . (int) $nivel;
    if (strpos((string) $mesaj, 'Allowed memory size') !== false) {
        $fel = 'memoria PHP s-a terminat (memory_limit)';
    } elseif (strpos((string) $mesaj, 'Maximum execution time') !== false) {
        $fel = 'timpul PHP s-a terminat (max_execution_time)';
    } elseif ($nivel === E_ERROR || $nivel === E_CORE_ERROR || $nivel === E_COMPILE_ERROR || $nivel === E_USER_ERROR || $nivel === E_PARSE) {
        $fel = 'eroare PHP fatală (' . $fel . ')';
    }
    $unelte = dirname(__DIR__, 2) . '/';
    $fisier = (string) $fisier;
    $loc = strpos($fisier, $unelte) === 0 ? substr($fisier, strlen($unelte)) . ':' . (int) $linie : basename($fisier);
    return $fel . (preg_match('/^[A-Za-z0-9._\/:-]{1,200}$/', $loc) === 1 ? ' în ' . $loc : '');
};

set_error_handler(function ($nivel, $mesaj, $fisier, $linie) use ($motivEroare) {
    if ((error_reporting() & $nivel) === 0) {
        return false; // o funcție chemată cu @
    }
    fwrite(STDERR, 'AVERTISMENT PHP: ' . $motivEroare($nivel, $mesaj, $fisier, $linie) . "\n");
    return true;
});

register_shutdown_function(function () use ($motivEroare) {
    $eroare = error_get_last();
    if (!is_array($eroare)) {
        return;
    }
    $text = $motivEroare($eroare['type'], $eroare['message'], $eroare['file'], $eroare['line']);
    if (!in_array($eroare['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        // Avertismentele de la compilare nu trec prin set_error_handler.
        if (in_array($eroare['type'], array(E_CORE_WARNING, E_COMPILE_WARNING), true)) {
            fwrite(STDERR, 'AVERTISMENT PHP: ' . $text . "\n");
        }
        return;
    }
    if (class_exists('Unelte\\Verificare\\Rulare', false)) {
        exit(\Unelte\Verificare\Rulare::opritDeEroare($text));
    }
    fwrite(STDERR, 'OPRIT: ' . $text . '; mesajul PHP nu se tipărește (are calea contului). Nimic citit, nimic scris.' . "\n");
    exit(2);
});
