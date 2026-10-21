<?php

declare(strict_types=1);

/**
 * „Gaura neagră cu contoare” a testelor (tools/dev/test-verificare.php): ascultă pe câteva porturi locale și notează
 * fiecare conexiune, fără să lase nimic să plece mai departe. Așa un test arată nu doar că o cerere spre internet
 * eșuează (ca la 127.0.0.1:9, pe care nu ascultă nimic), ci și câte au încercat să plece și spre ce adresă.
 *
 *   php tools/dev/lib/gaura-neagra.php --dosar=<dosar> --http=<port>[,<port>…] [--smtp=<port>]
 *
 * - porturile `--http` (proxy-ul din http_proxy/https_proxy, sau o adresă de API scrisă direct): primul rând al
 *   cererii (`CONNECT api.stripe.com:443 HTTP/1.1`, `POST /login?… HTTP/1.1`) intră în `<dosar>/conexiuni-<port>.txt`,
 *   răspunsul e mereu `502` și conexiunea se închide;
 * - portul `--smtp`: un server SMTP fără TLS (EHLO, AUTH LOGIN, MAIL, RCPT, DATA, QUIT) care păstrează fiecare mesaj în
 *   `<dosar>/smtp-<n>.eml`, cu plicul în față (`X-Plic-Autentificare`, `X-Plic-De-La`, `X-Plic-Catre`); fiecare
 *   conexiune se notează tot în `conexiuni-<port>.txt`.
 *
 * Scrie `<dosar>/gata` după ce ascultă. Se oprește cu SIGTERM (testul o oprește la final). Doar date de test.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dosar = '';
$porturiHttp = [];
$portSmtp = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--dosar=')) {
        $dosar = rtrim(substr($argument, 8), '/');
    } elseif (str_starts_with($argument, '--http=')) {
        $porturiHttp = array_values(array_filter(array_map('intval', explode(',', substr($argument, 7)))));
    } elseif (str_starts_with($argument, '--smtp=')) {
        $portSmtp = (int) substr($argument, 7);
    } else {
        fwrite(STDERR, "argument necunoscut: {$argument}\n");
        exit(2);
    }
}
if ($dosar === '' || !is_dir($dosar) || ($porturiHttp === [] && $portSmtp === 0)) {
    fwrite(STDERR, "folosire: gaura-neagra.php --dosar=<dosar existent> --http=<porturi> [--smtp=<port>]\n");
    exit(2);
}

$servere = [];
foreach (array_merge($porturiHttp, $portSmtp > 0 ? [$portSmtp] : []) as $port) {
    $server = @stream_socket_server('tcp://127.0.0.1:' . $port, $cod, $mesaj);
    if ($server === false) {
        fwrite(STDERR, "nu pot asculta pe 127.0.0.1:{$port}: {$mesaj}\n");
        exit(1);
    }
    $servere[$port] = $server;
}
file_put_contents($dosar . '/gata', (string) getmypid());

$noteaza = static function (int $port, string $rand) use ($dosar): void {
    $rand = substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $rand), 0, 300);
    file_put_contents($dosar . '/conexiuni-' . $port . '.txt', $rand . "\n", FILE_APPEND | LOCK_EX);
};

$mesajeSmtp = 0;
$smtp = static function ($client) use ($dosar, &$mesajeSmtp): string {
    $scrie = static function (string $text) use ($client): void {
        @fwrite($client, $text . "\r\n");
    };
    $citeste = static function () use ($client): ?string {
        $rand = @fgets($client, 65536);
        return $rand === false ? null : rtrim($rand, "\r\n");
    };
    $scrie('220 gaura-neagra ESMTP');
    $plic = ['autentificare' => '', 'de_la' => '', 'catre' => []];
    $primul = '';
    $asteaptaUtilizator = false;
    $asteaptaParola = false;
    while (($rand = $citeste()) !== null) {
        if ($primul === '') {
            $primul = $rand;
        }
        if ($asteaptaUtilizator) {
            $plic['autentificare'] = (string) base64_decode($rand, true);
            $asteaptaUtilizator = false;
            $asteaptaParola = true;
            $scrie('334 UGFzc3dvcmQ6');
            continue;
        }
        if ($asteaptaParola) {
            $asteaptaParola = false;
            $scrie('235 2.7.0 Autentificat');
            continue;
        }
        $comanda = strtoupper((string) strtok($rand, ' '));
        if ($comanda === 'EHLO' || $comanda === 'HELO') {
            $scrie('250-gaura-neagra');
            $scrie('250 AUTH LOGIN');
        } elseif ($comanda === 'AUTH') {
            $asteaptaUtilizator = true;
            $scrie('334 VXNlcm5hbWU6');
        } elseif ($comanda === 'MAIL') {
            $plic['de_la'] = (string) preg_replace('/^MAIL FROM:\s*/i', '', $rand);
            $scrie('250 2.1.0 Ok');
        } elseif ($comanda === 'RCPT') {
            $plic['catre'][] = (string) preg_replace('/^RCPT TO:\s*/i', '', $rand);
            $scrie('250 2.1.5 Ok');
        } elseif ($comanda === 'DATA') {
            $scrie('354 Trimite');
            $corp = '';
            while (($linie = $citeste()) !== null && $linie !== '.') {
                $corp .= (str_starts_with($linie, '..') ? substr($linie, 1) : $linie) . "\r\n";
            }
            $mesajeSmtp++;
            file_put_contents($dosar . '/smtp-' . $mesajeSmtp . '-' . getmypid() . '.eml',
                'X-Plic-Autentificare: ' . $plic['autentificare'] . "\r\n"
                . 'X-Plic-De-La: ' . $plic['de_la'] . "\r\n"
                . 'X-Plic-Catre: ' . implode(', ', $plic['catre']) . "\r\n" . $corp);
            $plic['catre'] = [];
            $scrie('250 2.0.0 Primit');
        } elseif ($comanda === 'QUIT') {
            $scrie('221 2.0.0 La revedere');
            break;
        } elseif ($comanda === 'STARTTLS') {
            $scrie('454 4.7.0 Fără TLS aici');
        } else {
            $scrie('250 Ok');
        }
    }
    return $primul;
};

while (true) {
    $citire = array_values($servere);
    $scriere = null;
    $exceptii = null;
    if (@stream_select($citire, $scriere, $exceptii, 5) === false) {
        usleep(100000);
        continue;
    }
    foreach ($citire as $server) {
        $port = (int) array_search($server, $servere, true);
        $client = @stream_socket_accept($server, 1);
        if ($client === false) {
            continue;
        }
        stream_set_timeout($client, 5);
        if ($port === $portSmtp) {
            $noteaza($port, 'SMTP ' . $smtp($client));
        } else {
            $rand = (string) @fgets($client, 8192);
            // Restul antetelor, ca clientul să primească răspunsul abia după ce a trimis cererea.
            while (($linie = @fgets($client, 8192)) !== false && trim($linie) !== '') {
            }
            $noteaza($port, trim($rand));
            @fwrite($client, "HTTP/1.1 502 Gaura neagra\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        }
        @fclose($client);
    }
}
