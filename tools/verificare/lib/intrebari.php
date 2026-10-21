<?php

/**
 * Întrebările din terminal ale uneltelor de pe server (docs/VERIFICARE.md): un răspuns pe un rând sau o parolă
 * scrisă fără ecou. Le folosesc tools/verificare/configureaza.php (baza, emailurile, parola porții) și
 * tools/verificare/incarca.php (confirmarea dosarului sursei, contul de admin al proprietarului, parola
 * personalului pe copia demonstrativă). Mutate aici din configureaza.php în WP-09, neschimbate.
 *
 * Scriptul care le încarcă definește `verificare_opreste($mesaj)` (mesajul pe stderr și codul lui de ieșire): o
 * întrebare fără răspuns, cu caractere de control sau o parolă care nu se poate ascunde opresc scriptul prin ea.
 *
 * Doar sintaxa PHP 7.0: se încarcă înaintea codului aplicației, ca un PHP prea vechi să primească mesajul
 * scriptului, nu o eroare de sintaxă.
 */

/** Rulează o comandă de shell cu ce funcție e pornită pe server; null dacă niciuna. */
function verificare_comanda($comanda)
{
    $oprite = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (function_exists('shell_exec') && !in_array('shell_exec', $oprite, true)) {
        return (string) shell_exec($comanda);
    }
    if (function_exists('exec') && !in_array('exec', $oprite, true)) {
        $linii = array();
        exec($comanda, $linii);
        return implode("\n", $linii);
    }
    return null;
}

function verificare_e_terminal()
{
    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }
    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDIN);
    }
    return false;
}

// Starea terminalului dinaintea unei parole (`stty -g`), cât timp parola se scrie.
$GLOBALS['verificare_stty'] = '';

/** Terminalul înapoi cum era înaintea parolei (dacă e cazul). */
function verificare_reda_terminalul()
{
    if ($GLOBALS['verificare_stty'] !== '') {
        verificare_comanda('stty ' . escapeshellarg($GLOBALS['verificare_stty']) . ' 2>/dev/null');
        $GLOBALS['verificare_stty'] = '';
    }
}
// Și dacă scriptul se oprește altfel în mijlocul unei parole (o eroare, un exit).
register_shutdown_function('verificare_reda_terminalul');

function verificare_semnal($semnal)
{
    verificare_reda_terminalul();
    fwrite(STDOUT, "\n");
    exit(128 + (int) $semnal);
}

/**
 * Cât se scrie o parolă: SIGTERM, SIGHUP (de exemplu găzduirea care oprește un proces lung)
 * și un SIGINT trimis din altă parte pun terminalul înapoi înainte de oprire (dacă există
 * pcntl). În rest semnalele rămân cum sunt: Ctrl-C la o întrebare obișnuită oprește scriptul
 * pe loc, ecoul fiind oricum pornit acolo.
 */
function verificare_prinde_semnale($da)
{
    if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals') || !defined('SIGTERM')) {
        return;
    }
    pcntl_async_signals(true);
    foreach (array(SIGTERM, SIGHUP, SIGINT) as $semnal) {
        pcntl_signal($semnal, $da ? 'verificare_semnal' : SIG_DFL, false);
    }
}

function verificare_nu_pot_ascunde()
{
    fwrite(STDOUT, "\n");
    verificare_opreste('nu pot ascunde ce scrii (comanda stty nu merge pe acest server), deci nu cer parola. Trimite-ne textul acesta.');
}

/**
 * O parolă scrisă în terminal, fără ecou. Cât se scrie, terminalul dă scriptului fiecare tastă
 * (`-icanon`), iar Ctrl-C nu mai e un semnal (`-isig`): scriptul îl citește ca pe o tastă, pune
 * singur terminalul înapoi și abia apoi se oprește (cod 130). Oprit de semnal în mijlocul
 * parolei, PHP-ul ar lăsa terminalul fără ecou: nimic din ce scrie proprietarul după aceea nu
 * s-ar mai vedea. Backspace șterge ultima literă, Ctrl-U tot ce s-a scris, Enter încheie.
 *
 * @return string|false false dacă intrarea s-a încheiat (Ctrl-D la început)
 */
function verificare_citeste_ascuns()
{
    $stare = trim((string) verificare_comanda('stty -g 2>/dev/null'));
    if ($stare === '') {
        verificare_nu_pot_ascunde();
    }
    $GLOBALS['verificare_stty'] = $stare;
    verificare_prinde_semnale(true);
    if (trim((string) verificare_comanda('stty -echo -icanon -isig min 1 time 0 2>/dev/null && echo DA')) !== 'DA') {
        verificare_reda_terminalul();
        verificare_prinde_semnale(false);
        verificare_nu_pot_ascunde();
    }
    $text = '';
    $ctrlC = false;
    $sfarsit = false;
    $erori = 0;
    while (true) {
        $citire = array(STDIN);
        $scriere = null;
        $exceptii = null;
        // Așteptări de o secundă, ca un semnal prins mai sus să fie tratat pe loc.
        $gata = @stream_select($citire, $scriere, $exceptii, 1);
        if ($gata === false && ++$erori > 30) {
            $sfarsit = true;
            break;
        }
        if (!$gata) {
            continue;
        }
        $c = fread(STDIN, 1);
        if ($c === false || $c === '') {
            $sfarsit = true;
            break;
        }
        if ($c === "\n" || $c === "\r") {
            break;
        }
        if ($c === "\x03") {
            $ctrlC = true;
            break;
        }
        if ($c === "\x04") {
            if ($text === '') {
                $sfarsit = true;
                break;
            }
            continue;
        }
        if ($c === "\x7f" || $c === "\x08") {
            // Ultima literă, cu toți octeții ei (UTF-8).
            $i = strlen($text) - 1;
            while ($i > 0 && (ord($text[$i]) & 0xC0) === 0x80) {
                $i--;
            }
            $text = $i > 0 ? substr($text, 0, $i) : '';
            continue;
        }
        if ($c === "\x15") {
            $text = '';
            continue;
        }
        // Alt caracter de control (o săgeată, Tab) rămâne în text: după Enter, verificare_intreaba
        // oprește scriptul, ca la un rând obișnuit.
        $text .= $c;
    }
    verificare_reda_terminalul();
    verificare_prinde_semnale(false);
    if ($ctrlC) {
        fwrite(STDOUT, "\n");
        fwrite(STDERR, "Oprit (Ctrl-C). Nu am scris nimic.\n");
        exit(130);
    }
    return $sfarsit ? false : $text;
}

/** O întrebare; cu $ascuns, ce scrii nu apare pe ecran. Fără intrare: oprire. */
function verificare_intreaba($intrebare, $ascuns)
{
    fwrite(STDOUT, $intrebare);
    fflush(STDOUT);
    if ($ascuns && verificare_e_terminal()) {
        $rand = verificare_citeste_ascuns();
        fwrite(STDOUT, "\n");
    } else {
        $rand = fgets(STDIN);
    }
    if ($rand === false) {
        verificare_opreste('intrarea s-a încheiat înainte de răspuns; nu am scris nimic.');
    }
    $rand = rtrim($rand, "\r\n");
    if (preg_match('/[\x00-\x1f\x7f]/', $rand) === 1) {
        verificare_opreste('răspunsul are caractere de control; nu am scris nimic.');
    }
    return $rand;
}
