<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Poarta conținutului pentru membri (WP-24: VT-X2, din vertij): `pages.requires_active_account` și
 * `blog_posts.requires_active_account`, 1 = pagina sau articolul se vede doar cu un cont activ (App\Support\AccesMembri;
 * cu pluginul `membri` oprit, un astfel de rând răspunde 404 și lipsește din liste și din sitemap).
 *
 * Coloanele sunt ale nucleului, nu ale pluginului: decizia e luată de nucleu în orice stare a pluginului. Adoptivă: codul
 * vertij le face cu aceleași definiții (SiteController::ensureOptionalPageSchema), deci pe baza lui migrarea le găsește
 * și nu face nimic. Pe celelalte site-uri coloanele vin cu 0 peste tot (nimic restricționat), deci nu se schimbă nimic din
 * ce se vede; codul de dinainte merge pe schema nouă (coloane la coadă, cu valoare implicită).
 */
return [
    'descriere' => 'Paginile și articolele doar pentru membri (requires_active_account)',
    'tip' => 'schema',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        $s->coloana('pages', 'requires_active_account', 'TINYINT(1) NOT NULL DEFAULT 0');
        $s->coloana('blog_posts', 'requires_active_account', 'TINYINT(1) NOT NULL DEFAULT 0');
    },
];
