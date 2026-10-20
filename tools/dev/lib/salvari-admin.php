<?php

declare(strict_types=1);

/**
 * Salvările din admin comparate pe două servere care împart aceeași bază de test
 * (tools/dev/compara-cu-baza.php, doar față de codul Bioscem).
 *
 * Un caz = câteva rânduri pregătite în bază, una sau mai multe cereri (POST-ul unei
 * salvări; GET-ul editorului, unde contează ce e bifat) și starea rândurilor atinse.
 * Cazul rulează întâi pe codul de bază, apoi pe codul curent; înainte de fiecare
 * server baza e pusă la loc exact cum era (instantaneul tabelelor cazului), iar după
 * ultimul, la fel. Se compară răspunsurile (cod, Location, pagina) și starea. Ce trebuie
 * să arate rezultatul codului de bază (salvarea ajunsă în bază; schimbarea commit-ului sau,
 * la un caz de tip „regresie”, ramura pe care commit-ul nu o schimbă) scrie la $salvari.
 *
 * Funcțiile de aici doar citesc și pun la loc rânduri; cererile și normalizarea
 * paginilor sunt ale lui compara-cu-baza.php.
 */

/**
 * Rândurile tabelelor unui caz, exact cum sunt, ca să poată fi puse la loc.
 *
 * @param list<array{tabel: string, unde: string, param?: array<string, mixed>, cheie?: string|null}> $tabele
 *        „cheie”: coloana de identitate a rândului (rândurile în plus se șterg după ea, cele
 *        existente se rescriu fără DELETE); fără cheie, rândurile tabelului se rescriu de la zero.
 * @return list<list<array<string, mixed>>>
 */
function salvari_instantaneu(PDO $db, array $tabele): array
{
    $rezultat = [];
    foreach ($tabele as $t) {
        $cheie = $t['cheie'] ?? null;
        $stmt = $db->prepare('SELECT * FROM `' . $t['tabel'] . '` WHERE ' . $t['unde']
            . ($cheie !== null ? ' ORDER BY `' . $cheie . '`' : ''));
        $stmt->execute($t['param'] ?? []);
        $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($cheie === null) {
            // Fără cheie: ordinea nu contează, deci se compară sortat.
            usort($randuri, static fn (array $a, array $b): int => strcmp(json_encode($a) ?: '', json_encode($b) ?: ''));
        }
        $rezultat[] = $randuri;
    }
    return $rezultat;
}

/**
 * Pune la loc rândurile din instantaneu. Un rând cu cheie se rescrie cu
 * INSERT … ON DUPLICATE KEY UPDATE (niciodată DELETE + INSERT: ștergerea unui produs
 * ar șterge în cascadă recenziile lui), iar rândurile apărute între timp (de exemplu
 * copia unei postări duplicate) se șterg după cheie.
 *
 * @param list<array{tabel: string, unde: string, param?: array<string, mixed>, cheie?: string|null}> $tabele
 * @param list<list<array<string, mixed>>> $instantaneu
 */
function salvari_pune_la_loc(PDO $db, array $tabele, array $instantaneu): void
{
    foreach ($tabele as $i => $t) {
        $tabel = '`' . $t['tabel'] . '`';
        $cheie = $t['cheie'] ?? null;
        $randuri = $instantaneu[$i] ?? [];
        if ($cheie === null) {
            $db->prepare('DELETE FROM ' . $tabel . ' WHERE ' . $t['unde'])->execute($t['param'] ?? []);
        } else {
            $pastrate = array_map('strval', array_column($randuri, $cheie));
            $acum = $db->prepare('SELECT `' . $cheie . '` FROM ' . $tabel . ' WHERE ' . $t['unde']);
            $acum->execute($t['param'] ?? []);
            foreach ($acum->fetchAll(PDO::FETCH_COLUMN) as $valoare) {
                if (!in_array((string) $valoare, $pastrate, true)) {
                    $db->prepare('DELETE FROM ' . $tabel . ' WHERE `' . $cheie . '` = :v')->execute(['v' => $valoare]);
                }
            }
        }
        foreach ($randuri as $rand) {
            $coloane = array_keys($rand);
            $lista = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $coloane));
            $valori = implode(', ', array_map(static fn (int $n): string => ':c' . $n, array_keys($coloane)));
            $sql = 'INSERT INTO ' . $tabel . ' (' . $lista . ') VALUES (' . $valori . ')';
            if ($cheie !== null) {
                $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
                    static fn (string $c): string => '`' . $c . '` = VALUES(`' . $c . '`)', $coloane));
            }
            $param = [];
            foreach (array_values($rand) as $n => $valoare) {
                $param['c' . $n] = $valoare;
            }
            $db->prepare($sql)->execute($param);
        }
    }
}

/**
 * Un rând fără coloanele care țin de moment (created_at, updated_at) și fără cele cerute
 * în plus (de exemplu id-ul unei copii, care crește la fiecare rulare).
 *
 * @param array<string, mixed> $rand
 * @param list<string> $fara
 * @return array<string, mixed>
 */
function salvari_fara_coloane(array $rand, array $fara = []): array
{
    foreach (array_merge(['created_at', 'updated_at'], $fara) as $coloana) {
        unset($rand[$coloana]);
    }
    return $rand;
}

/**
 * Diferențele dintre două stări, pe rânduri, ca text scurt (cheie: valoare în bază → acum).
 *
 * @param array<string, mixed> $baza
 * @param array<string, mixed> $curent
 * @return list<string>
 */
function salvari_diferente(array $baza, array $curent, string $prefix = ''): array
{
    $rezultat = [];
    foreach (array_unique(array_merge(array_keys($baza), array_keys($curent))) as $cheie) {
        $a = $baza[$cheie] ?? null;
        $b = $curent[$cheie] ?? null;
        if (is_array($a) && is_array($b)) {
            $rezultat = array_merge($rezultat, salvari_diferente($a, $b, $prefix . $cheie . '.'));
        } elseif ($a !== $b) {
            $rezultat[] = $prefix . $cheie . ': ' . json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' → ' . json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    return $rezultat;
}
