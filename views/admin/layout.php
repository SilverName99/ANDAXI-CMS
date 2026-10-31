<!doctype html>
<?php
// Butoanele, formularele și linkurile spre paginile pluginurilor pe care
// site-ul nu le are se scot din pagină (Plugins::filtreazaLinkuri); cu toate
// pluginurile pe site, HTML-ul rămâne neatins.
$adminFiltruLinkuri = !\App\Support\Plugins::nimicAscuns();
if ($adminFiltruLinkuri) {
    ob_start();
}
$adminAppConfig = require __DIR__ . '/../../config/app.php';
$adminDb = \App\Support\Database::connection($adminAppConfig['db']);
$adminSettings = \App\Support\Settings::all($adminDb);
$adminAssetVersionToken = \App\Support\ResponseCache::assetVersionToken($adminSettings);
$adminAssetVersionQuery = $adminAssetVersionToken !== '' ? '&v=' . rawurlencode($adminAssetVersionToken) : '';
$adminFavicon = trim((string) ($adminSettings['store_favicon_url'] ?? ''));
if ($adminFavicon === '') {
    $adminFavicon = '/assets/img/product-placeholder.svg';
}
$adminNumeSite = \App\Support\Brand::numeSite($adminSettings);
// Versiunea fișierelor statice ale adminului: data lor pe disc (ca app.css).
$adminVersiune = static fn (string $fisier): int => (int) (@filemtime(\App\Support\Paths::public($fisier)) ?: 1);
$adminAccent = \App\Support\AdminAspect::accent($adminSettings);
require_once __DIR__ . '/componente/_incarca.php';
?>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(($title ?? 'Admin') . ' | Admin ' . $adminNumeSite, ENT_QUOTES) ?></title>
    <link rel="icon" href="<?= htmlspecialchars($adminFavicon, ENT_QUOTES) ?>">
    <?php $cssVersion = @filemtime(\App\Support\Paths::public('assets/css/app.css')) ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVersion ?><?= $adminAssetVersionQuery ?>">
<?php
// Adminul „B — Compact” (faza D, WP-30): fonturile IBM Plex din nucleu și admin.css (variabilele de aspect, cadrul,
// componentele), după app.css. app.css rămâne încărcat: paginile încă nerefăcute își au regulile acolo, iar o regulă de
// admin iese din app.css doar cu dovada G-M3 (docs/DEZVOLTARE.md, „Adminul refăcut”).
?>
    <link rel="stylesheet" href="/assets/vendor/fonturi/ibm-plex/ibm-plex.css?v=<?= $adminVersiune('assets/vendor/fonturi/ibm-plex/ibm-plex.css') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $adminVersiune('assets/css/admin.css') ?>">
<?php
// CodeMirror și js-beautify, doar în cele patru editoare de cod (WP-12 C6, din Biovitality c64fc2d; „Erori moștenite” 6
// din docs/DEZVOLTARE.md). Din WP-30 din nucleu (public/assets/vendor/codemirror, …/js-beautify, aceleași versiuni), nu de
// pe CDN: adminul nu mai cere nimic de pe alt server.
$adminEditoareDeCod = ['admin/design-site', 'admin/page-editor', 'admin/product-template-editor', 'admin/blog-template-editor'];
?>
<?php if (in_array(\App\Support\View::vedereaCurenta(), $adminEditoareDeCod, true)): ?>
<?php foreach (['lib/codemirror.css', 'theme/material-darker.css', 'addon/dialog/dialog.css'] as $adminFisierCm): ?>
    <link rel="stylesheet" href="/assets/vendor/codemirror/<?= $adminFisierCm ?>?v=5.65.18">
<?php endforeach; ?>
<?php foreach (['lib/codemirror.js', 'mode/javascript/javascript.js', 'mode/css/css.js', 'mode/xml/xml.js', 'mode/htmlmixed/htmlmixed.js',
    'addon/dialog/dialog.js', 'addon/search/searchcursor.js', 'addon/search/search.js', 'addon/edit/closebrackets.js',
    'addon/edit/matchbrackets.js', 'addon/selection/active-line.js'] as $adminFisierCm): ?>
    <script src="/assets/vendor/codemirror/<?= $adminFisierCm ?>?v=5.65.18"></script>
<?php endforeach; ?>
<?php foreach (['beautify.js', 'beautify-css.js', 'beautify-html.js'] as $adminFisierJb): ?>
    <script src="/assets/vendor/js-beautify/<?= $adminFisierJb ?>?v=1.15.1"></script>
<?php endforeach; ?>
<?php endif; ?>
</head>
<?php // Clasa corpului: cadrul nou doar cu adminul logat. Rândul bannerului de mai jos rămâne exact cum îl caută garda 14 din
// tools/verificare/stare.php. ?>
<body class="<?= \App\Support\Auth::esteLogatFaraReimprospatare() ? 'ax-admin' : 'ax-admin-login' ?>" style="--ax-accent:<?= $adminAccent ?>;--ax-accent-text:<?= \App\Support\AdminAspect::textPeAccent($adminAccent) ?>;">
    <?php $adminLogat = \App\Support\Auth::check(); echo \App\Support\Verificare::banner($adminLogat ? 'admin' : 'site'); /* bannerul copiei de verificare (adresa întreagă doar cu adminul logat); gol în rest */ if ($adminLogat): ?>
        <?php
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH) ?: '/admin';
        $isActive = static function (array $prefixes) use ($path): bool {
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return true;
                }
            }
            return false;
        };
        $can = static fn (string $route): bool => \App\Support\Auth::canAccessPath($route);
        $icon = static function (string $name): string {
            return match ($name) {
                'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>',
                'store' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16l-1.5 11H5.5L4 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>',
                'products' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>',
                'categories' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7v7H4z"/><path d="M13 5h7v7h-7z"/><path d="M4 14h7v7H4z"/><path d="M13 14h7v7h-7z"/></svg>',
                'orders' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 13h4l2 3h4l2-3h4"/></svg>',
                'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 18a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9" r="2.3"/><path d="M14.5 18a4.2 4.2 0 0 1 6 0"/></svg>',
                'shield' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v6c0 5 3.4 7.9 7 9 3.6-1.1 7-4 7-9V6l-7-3Z"/><path d="m9.5 12 1.8 1.8L15 10.2"/></svg>',
                'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.8a3.2 3.2 0 1 0 0 6.4 3.2 3.2 0 0 0 0-6.4Z"/><path d="M20 13.2v-2.4l-2.1-.5a6 6 0 0 0-.5-1.1l1.2-1.8-1.7-1.7-1.8 1.2a6 6 0 0 0-1.1-.5L13.2 4h-2.4l-.5 2.1a6 6 0 0 0-1.1.5L7.4 5.4 5.7 7.1l1.2 1.8a6 6 0 0 0-.5 1.1l-2.1.5v2.4l2.1.5a6 6 0 0 0 .5 1.1l-1.2 1.8 1.7 1.7 1.8-1.2a6 6 0 0 0 1.1.5l.5 2.1h2.4l.5-2.1a6 6 0 0 0 1.1-.5l1.8 1.2 1.7-1.7-1.2-1.8a6 6 0 0 0 .5-1.1l2.1-.5Z"/></svg>',
                'shipping' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h12v10H3z"/><path d="M15 9h4l2 2v5h-6z"/><circle cx="7" cy="18" r="1.7"/><circle cx="17" cy="18" r="1.7"/></svg>',
                'payments' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
                'emails' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>',
                'automation' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>',
                'newsletter' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="m4 7 8 6 8-6"/></svg>',
                'stats' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16"/><rect x="6" y="11" width="3" height="6" rx="1"/><rect x="11" y="8" width="3" height="9" rx="1"/><rect x="16" y="5" width="3" height="12" rx="1"/></svg>',
                'star' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.9 5.88 6.5.95-4.7 4.58 1.1 6.49L12 17.8 6.2 20.9l1.1-6.49L2.6 9.83l6.5-.95L12 3z"/></svg>',
                'coupon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8z"/><path d="M12 7v10"/></svg>',
                'pages' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h9l4 4v14H7z"/><path d="M16 3v5h5"/></svg>',
                'gallery' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m5 16 4-3 3 2 3-4 4 5"/></svg>',
                'design' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4 4 14l-1 5 5-1L18 8l-4-4Z"/><path d="m13 5 4 4"/><path d="M3 21h18"/></svg>',
                'blog' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M7 8h10M7 12h10M7 16h6"/></svg>',
                'site' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
                'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
                'keyboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/></svg>',
                'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
                'caret' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
                default => '',
            };
        };

        // Meniul (WP-30): cele opt grupuri din config/plugins.php → meniu_admin, cu intrările din catalogul pluginurilor.
        // Intrările pluginurilor pe care site-ul nu le are lipsesc, un grup rămas fără intrări nu apare, iar unul cu o
        // singură intrare (după licență și rolul adminului, $can) apare ca link (docs/ARHITECTURA-ADMIN.md).
        $tab = is_scalar($_GET['tab'] ?? null) ? trim((string) $_GET['tab']) : null;
        $esteActiva = static fn (mixed $regula): bool => \App\Support\Plugins::intrareActiva($regula, $isActive, $path, $tab);
        $poateIntrarea = static fn (array $intrare): bool => $can((string) ($intrare['permisiune'] ?? $intrare['href']));
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
        $linkMeniu = static function (array $intrare, string $iconita, string $clasa) use ($esteActiva, $icon, $e): string {
            $activa = $esteActiva($intrare['activ'] ?? []);
            $eticheta = '<span class="ax-meniu-eticheta">' . $e((string) $intrare['eticheta']) . '</span>';
            // Bulina unei intrări (WP-21: recenziile care așteaptă aprobare), din catalog („insigna”: o funcție care
            // întoarce un număr); 0 = nimic.
            $numarInsigna = is_callable($intrare['insigna'] ?? null) ? (int) call_user_func($intrare['insigna']) : 0;
            if ($numarInsigna > 0) {
                $eticheta .= '<span class="nav-badge ax-meniu-numar" title="' . $numarInsigna . ' în așteptare">'
                    . ($numarInsigna > 99 ? '99+' : (string) $numarInsigna) . '</span>';
            }
            return '<a class="' . $clasa . ($activa ? ' active' : '') . '" href="' . $e((string) $intrare['href']) . '"'
                . ($activa ? ' aria-current="page"' : '') . '>'
                . ($iconita !== '' ? '<span class="nav-icon">' . $icon($iconita) . '</span>' : '') . $eticheta . '</a>';
        };
        $meniuHtml = '';
        foreach (\App\Support\Plugins::meniuAdmin() as $slot) {
            $permise = array_values(array_filter($slot['intrari'], $poateIntrarea));
            if ($permise === []) {
                continue;
            }
            if ($slot['tip'] === 'link' || count($permise) === 1) {
                foreach ($permise as $intrare) {
                    $iconita = $slot['tip'] === 'link' ? (string) ($intrare['icon'] ?? '') : (string) $slot['icon'];
                    $meniuHtml .= '<li>' . $linkMeniu($intrare, $iconita, 'ax-meniu-link') . '</li>' . "\n";
                }
                continue;
            }
            $areActiva = false;
            foreach ($permise as $intrare) {
                $areActiva = $areActiva || $esteActiva($intrare['activ'] ?? []);
            }
            $deschis = $areActiva || $isActive($slot['deschis']);
            $idGrup = 'ax-grup-' . $slot['cheie'];
            $meniuHtml .= '<li class="ax-meniu-grup' . ($deschis ? ' deschis' : '') . ($areActiva ? ' are-activa' : '') . '">'
                . '<button type="button" class="ax-meniu-link ax-meniu-buton" aria-expanded="' . ($deschis ? 'true' : 'false')
                . '" aria-controls="' . $idGrup . '"><span class="nav-icon">' . $icon($slot['icon']) . '</span>'
                . '<span class="ax-meniu-eticheta">' . $e($slot['eticheta']) . '</span>'
                . '<span class="ax-meniu-sageata">' . $icon('caret') . '</span></button>'
                . '<ul class="ax-meniu-sublista" id="' . $idGrup . '"' . ($deschis ? '' : ' hidden') . '>';
            foreach ($permise as $intrare) {
                $meniuHtml .= '<li>' . $linkMeniu($intrare, '', 'ax-meniu-sublink') . '</li>';
            }
            $meniuHtml .= '</ul></li>' . "\n";
        }
        $adminEmail = '';
        if ($adminDb instanceof PDO && ($adminId = \App\Support\Auth::id()) !== null) {
            $interogare = $adminDb->prepare('SELECT email FROM admins WHERE id = ? LIMIT 1');
            $interogare->execute([$adminId]);
            $adminEmail = (string) ($interogare->fetchColumn() ?: '');
        }
        $adminRol = (string) (\App\Support\Auth::roles()[\App\Support\Auth::role()] ?? 'Administrator');
        // Editorul de emailuri își ocupă singur tot spațiul de sub antet (views/admin/emails/builder.php).
        $continutPlin = \App\Support\View::vedereaCurenta() === 'admin/emails/builder';
        ?>
        <a class="ax-sari" href="#continut">Sari la conținut</a>
        <div class="ax-app" id="admin-shell">
            <aside class="ax-bara" id="ax-bara" aria-label="Meniul adminului">
                <div class="ax-marca">
                    <span class="ax-marca-semn" aria-hidden="true">A</span>
                    <span class="ax-marca-text">
                        <span class="ax-marca-nume">ANDAXI CMS</span>
                        <span class="ax-marca-site"><?= $e($adminNumeSite) ?></span>
                    </span>
                </div>
                <nav class="ax-meniu" aria-label="Meniu principal">
                    <ul class="ax-meniu-lista">
<?= $meniuHtml ?>
                    </ul>
                </nav>
                <div class="ax-bara-subsol">
                    <a class="ax-meniu-link" href="/" target="_blank" rel="noreferrer"><span class="nav-icon"><?= $icon('site') ?></span><span class="ax-meniu-eticheta">Vezi site-ul</span></a>
                    <div class="ax-utilizator">
                        <span class="ax-avatar" aria-hidden="true"><?= $e(\App\Support\AdminAspect::initiale($adminEmail)) ?></span>
                        <span class="ax-utilizator-text">
                            <span class="ax-utilizator-nume" title="<?= $e($adminEmail) ?>"><?= $e($adminEmail !== '' ? $adminEmail : 'Administrator') ?></span>
                            <span class="ax-utilizator-rol"><?= $e($adminRol) ?></span>
                        </span>
                        <form method="post" action="/admin/logout">
                            <button type="submit" class="ax-iesire">Ieșire</button>
                        </form>
                    </div>
                </div>
            </aside>
            <div class="ax-fundal-bara" hidden></div>

            <div class="ax-principal">
                <header class="ax-antet">
                    <button type="button" class="ax-buton-icon" id="sidebar-toggle-btn" aria-controls="ax-bara" aria-expanded="true" title="Ascunde sau arată meniul">
                        <span class="nav-icon"><?= $icon('menu') ?></span><span class="adm-ascuns">Meniul</span>
                    </button>
                    <div class="ax-cautare" title="Căutarea în tot adminul (comenzi, produse, clienți) vine în curând">
                        <span class="nav-icon"><?= $icon('search') ?></span>
                        <span class="ax-cautare-text">Căutarea în tot adminul vine în curând</span><span class="ax-cautare-scurt">Caută (în curând)</span>
                        <span class="ax-taste" aria-hidden="true"><kbd>Ctrl</kbd><kbd>K</kbd></span>
                    </div>
                    <button type="button" class="ax-buton-text" id="ax-buton-taste" aria-haspopup="dialog" aria-controls="ax-taste" aria-keyshortcuts="Shift+?">
                        <span class="nav-icon"><?= $icon('keyboard') ?></span><span class="ax-ascuns-mic">Scurtături</span><kbd>?</kbd>
                    </button>
                </header>

                <main class="admin-content ax-continut<?= $continutPlin ? ' ax-continut--plin' : '' ?>" id="continut" tabindex="-1">
<?php if (\App\Support\Plugins::licentaLipseste()): ?>
                <section class="panel" style="border-color:#fcd34d;background:#fffbeb;color:#92400e;">
                    <strong>Licența lipsește.</strong> Site-ul funcționează doar cu funcțiile de bază. Vă rugăm să contactați ANDAXI WEB SOLUTIONS.
                </section>
<?php endif; ?>
<?php if (($notaLicenta = \App\Support\Plugins::notaLicentaAdmin()) !== null): ?>
                <section class="panel" style="border-color:#fcd34d;background:#fffbeb;color:#92400e;">
                    <?= htmlspecialchars($notaLicenta, ENT_QUOTES) ?>
                </section>
<?php endif; ?>
<?php if (\App\Support\Auth::isGeneralAdmin() && ($stareSchema = \App\Support\Migrari\Garda::stare($adminDb)) !== \App\Support\Migrari\Garda::LA_ZI): ?>
                <section class="panel" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b;" data-schema="<?= htmlspecialchars($stareSchema, ENT_QUOTES) ?>">
                    <strong>Baza de date nu e la zi cu codul.</strong> <?= htmlspecialchars(\App\Support\Migrari\Garda::explicatie($stareSchema), ENT_QUOTES) ?>
                </section>
<?php endif; ?>
                <?php // Mesajul de după o salvare (reușită sau eroare): componenta mesaj (WP-31). ?>
                <?php if (($message = \App\Support\Flash::get('success')) !== null): ?>
                    <?php admin_componenta('mesaj', ['tip' => 'succes', 'text' => $message, 'id' => 'ax-mesaj-succes']); ?>
                <?php endif; ?>
                <?php if (($message = \App\Support\Flash::get('error')) !== null): ?>
                    <?php admin_componenta('mesaj', ['tip' => 'eroare', 'text' => $message, 'id' => 'ax-mesaj-eroare']); ?>
                <?php endif; ?>
                <?= $content ?>
                </main>
            </div>
        </div>
        <dialog class="ax-dialog" id="ax-taste" aria-labelledby="ax-taste-titlu">
            <div class="ax-dialog-antet">
                <h2 id="ax-taste-titlu">Scurtături de tastatură</h2>
                <button type="button" class="ax-buton-icon ax-inchide-dialog" title="Închide (Esc)">×<span class="adm-ascuns">Închide</span></button>
            </div>
            <dl class="ax-taste-lista">
                <div><dt><kbd>?</kbd></dt><dd>Arată lista aceasta</dd></div>
                <div><dt><kbd>Esc</kbd></dt><dd>Închide fereastra, panoul sau meniul deschis</dd></div>
                <div><dt><kbd>Tab</kbd></dt><dd>Treci la următorul buton sau câmp (cel ales are un contur colorat)</dd></div>
            </dl>
            <p class="ax-dialog-nota">Căutarea în tot adminul (<kbd>Ctrl</kbd> <kbd>K</kbd>) și scurtăturile paginilor de comenzi vin odată cu paginile refăcute.</p>
        </dialog>
<?php // Componentele (WP-31) întâi: panoul deschis își prinde Esc-ul înaintea cadrului. ?>
<?php foreach (['panou', 'componente', 'tabel', 'filtre', 'cadru'] as $adminScript): ?>
        <script src="/assets/js/admin/<?= $adminScript ?>.js?v=<?= $adminVersiune('assets/js/admin/' . $adminScript . '.js') ?>" defer></script>
<?php endforeach; ?>
    <?php else: ?>
        <main class="container">
            <?= $content ?>
        </main>
    <?php endif; ?>
</body>
</html>
<?php
if ($adminFiltruLinkuri) {
    echo \App\Support\Plugins::filtreazaLinkuri((string) ob_get_clean());
}
?>
