<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Rolurile administratorilor, aduse o singură dată la forma de azi (role și roles_json).
 *
 * Înlocuiește UPDATE-ul pe care AdminController::ensureOptionalSchema și Auth::ensureSchema îl fac pe
 * fiecare rând din admins, la fiecare pagină de admin și la fiecare încercare de login. Aceeași regulă
 * ca Auth::rolesFromStorage + Auth::primaryRole: rolurile din roles_json (o listă JSON) plus cel din
 * role (NULL = administrator_general), doar cele cunoscute, fiecare o dată, în ordinea lor; dacă nu
 * rămâne niciunul, rolul din role, iar dacă nici el nu e cunoscut, administrator_general; role = primul
 * dintre ele în ordinea general, emailuri, magazin, blog. Cele patru roluri sunt scrise aici (ale lui
 * Auth::roles() la WP-03): o migrare publicată nu se mai schimbă, iar Auth::roles() întreabă și
 * licența (o etichetă), pe care o migrare n-are de ce s-o citească. Se scrie doar un rând care chiar
 * se schimbă, deci a doua rulare nu face nimic.
 */
return [
    'descriere' => 'Rolurile administratorilor, la forma de azi (role și roles_json)',
    'tip' => 'date',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->peBucati('admins', 'id', 500, static function (PDO $db, int $de, int $pana): int {
            $general = 'administrator_general';
            $cunoscute = [$general, 'administrator_magazin', 'administrator_blog', 'administrator_emailuri'];
            $prioritate = [$general, 'administrator_emailuri', 'administrator_magazin', 'administrator_blog'];
            $citeste = $db->prepare('SELECT id, role, roles_json FROM admins WHERE id > ? AND id <= ?');
            $citeste->execute([$de, $pana]);
            $scrie = $db->prepare('UPDATE admins SET role = ?, roles_json = ? WHERE id = ?');
            $schimbate = 0;
            foreach ($citeste->fetchAll(PDO::FETCH_ASSOC) as $rand) {
                $vechi = $rand['role'] === null ? $general : (string) $rand['role'];
                $roluri = [];
                if (is_string($rand['roles_json']) && trim($rand['roles_json']) !== '') {
                    $decodat = json_decode($rand['roles_json'], true);
                    if (is_array($decodat)) {
                        $roluri = $decodat;
                    }
                }
                $roluri[] = $vechi;
                $normalizate = [];
                foreach ($roluri as $rol) {
                    $rol = is_scalar($rol) ? trim((string) $rol) : '';
                    if (in_array($rol, $cunoscute, true) && !in_array($rol, $normalizate, true)) {
                        $normalizate[] = $rol;
                    }
                }
                if ($normalizate === []) {
                    $normalizate[] = in_array(trim($vechi), $cunoscute, true) ? trim($vechi) : $general;
                }
                $principal = $normalizate[0];
                foreach ($prioritate as $rol) {
                    if (in_array($rol, $normalizate, true)) {
                        $principal = $rol;
                        break;
                    }
                }
                $json = (string) json_encode($normalizate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($rand['role'] !== $principal || $rand['roles_json'] !== $json) {
                    $scrie->execute([$principal, $json, (int) $rand['id']]);
                    $schimbate += $scrie->rowCount();
                }
            }
            return $schimbate;
        }, 'admins.roluri');
    },
];
