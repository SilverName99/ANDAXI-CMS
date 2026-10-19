<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ajutoare pentru uneltele de dezvoltare, care pe un site nu fac nimic.
 *
 * Marcajele porturilor: o bucată de pagină adusă dintr-un site vechi (Biovitality,
 * vertij, grafoanaytis) se scrie între două marcaje, ca `tools/dev/compara-cu-baza.php`
 * s-o scoată explicit din pagina codului curent și să compare restul octet cu octet
 * cu codul de bază (Bioscem):
 *
 *   <?= Dev::marcaj('bv:tab-spam') ?>…bucata nouă…<?= Dev::marcaj('bv:tab-spam', true) ?>
 *
 * dă `<!--port:bv:tab-spam-->…<!--/port:bv:tab-spam-->`, dar doar când variabila de
 * mediu `CMS_DEV_MARCAJE_PORT=1` e pusă (o pune doar `tools/dev/comun.sh`). Pe site-uri
 * variabila nu există, deci marcajul e un șir gol și HTML-ul nu are niciun comentariu în
 * plus. Numele (`<sursa>:<NUME>`: bv, vt, gr, deb) se declară o singură dată, cu pagina
 * lui, în `$marcajePort` din compara-cu-baza.php. Marcajul se scrie la mijlocul rândului:
 * un `?>` de la capăt de rând înghite sfârșitul de rând.
 */
final class Dev
{
    public static function marcaj(string $nume, bool $sfarsit = false): string
    {
        if ((string) Env::get('CMS_DEV_MARCAJE_PORT', '') !== '1') {
            return '';
        }
        return '<!--' . ($sfarsit ? '/' : '') . 'port:' . $nume . '-->';
    }
}
