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
?>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(($title ?? 'Admin') . ' | Admin ' . \App\Support\Brand::numeSite($adminSettings), ENT_QUOTES) ?></title>
    <link rel="icon" href="<?= htmlspecialchars($adminFavicon, ENT_QUOTES) ?>">
    <?php $cssVersion = @filemtime(\App\Support\Paths::public('assets/css/app.css')) ?: time(); ?>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVersion ?><?= $adminAssetVersionQuery ?>">
<?php
// CodeMirror și js-beautify sunt 15 fișiere de pe un CDN, fără defer: pagina aștepta să vină toate, pe
// FIECARE pagină de admin, deși doar cele patru editoare de cod le folosesc (WP-12 C6, din Biovitality
// c64fc2d; „Erori moștenite” 6 din docs/DEZVOLTARE.md). Pe celelalte pagini nu se mai cer.
$adminEditoareDeCod = ['admin/design-site', 'admin/page-editor', 'admin/product-template-editor', 'admin/blog-template-editor'];
?>
<?php if (in_array(\App\Support\View::vedereaCurenta(), $adminEditoareDeCod, true)): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/lib/codemirror.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/theme/material-darker.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/dialog/dialog.min.css">
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/lib/codemirror.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/mode/javascript/javascript.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/mode/css/css.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/mode/xml/xml.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/dialog/dialog.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/search/searchcursor.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/search/search.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/edit/closebrackets.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/edit/matchbrackets.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.18/addon/selection/active-line.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/js-beautify@1.15.1/js/lib/beautify.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/js-beautify@1.15.1/js/lib/beautify-css.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/js-beautify@1.15.1/js/lib/beautify-html.min.js"></script>
<?php endif; ?>
</head>
<body>
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
                'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
                'store' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16l-1.5 11H5.5L4 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>',
                'products' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7l8 4 8-4-8-4Z"/><path d="M4 7v10l8 4 8-4V7"/></svg>',
                'categories' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h7v7H4z"/><path d="M13 5h7v7h-7z"/><path d="M4 14h7v7H4z"/><path d="M13 14h7v7h-7z"/></svg>',
                'orders' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="19" r="1.6"/><circle cx="17" cy="19" r="1.6"/><path d="M4 5h2l2.1 9.2h9.9l2-7.2H7.2"/></svg>',
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
                'coupon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8z"/><path d="M12 7v10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" fill="none"/></svg>',
                'pages' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h9l4 4v14H7z"/><path d="M16 3v5h5"/></svg>',
                'gallery' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m5 16 4-3 3 2 3-4 4 5"/></svg>',
                'design' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4 4 14l-1 5 5-1L18 8l-4-4Z"/><path d="m13 5 4 4"/><path d="M3 21h18"/></svg>',
                'blog' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M7 8h10M7 12h10M7 16h6"/></svg>',
                default => '',
            };
        };

        // Meniul se construiește din catalogul pluginurilor (config/plugins.php):
        // intrările pluginurilor pe care site-ul nu le are lipsesc, iar un grup
        // rămas fără intrări nu apare deloc. Peste asta, fiecare intrare se vede
        // doar dacă rolul adminului are voie la ea ($can).
        $tab = is_scalar($_GET['tab'] ?? null) ? trim((string) $_GET['tab']) : null;
        $esteActiva = static fn (mixed $regula): bool => \App\Support\Plugins::intrareActiva($regula, $isActive, $path, $tab);
        $poateIntrarea = static fn (array $intrare): bool => $can((string) ($intrare['permisiune'] ?? $intrare['href']));

        // HTML-ul meniului se scrie exact cum îl scria șablonul de dinainte de
        // pluginuri, cu aceleași spații și rânduri goale: cu toate pluginurile
        // pornite, paginile adminului sunt identice cu cele de atunci
        // (tools/dev/compara-cu-baza.sh). De aici cele două particularități:
        // după primele cinci blocuri vine un rând gol, iar în grupurile
        // Configurare și Blog fiecare intrare își are propria condiție de rol.
        $randGolDupa = ['dashboard', 'utilizatori', 'magazin', 'configurare', 'jurnal'];
        $conditiePeIntrare = ['configurare', 'blog'];
        $sp = static fn (int $n): string => str_repeat(' ', $n);
        $linkMeniu = static function (array $intrare, int $indent, bool $etichetaPeRand = false) use ($sp, $esteActiva, $icon): string {
            $html = $sp($indent) . '<a class="' . ($esteActiva($intrare['activ'] ?? []) ? 'active' : '') . '" href="'
                . htmlspecialchars((string) $intrare['href'], ENT_QUOTES) . '">' . "\n"
                . $sp($indent + 4) . '<span class="nav-icon">' . $icon((string) ($intrare['icon'] ?? '')) . '</span>';
            $eticheta = htmlspecialchars((string) $intrare['eticheta'], ENT_QUOTES);
            // Bulina unei intrări (WP-21: recenziile care așteaptă aprobare), din catalog („insigna”: o funcție care
            // întoarce un număr); 0 = nimic, ca înainte.
            $numarInsigna = is_callable($intrare['insigna'] ?? null) ? (int) call_user_func($intrare['insigna']) : 0;
            if ($numarInsigna > 0) {
                $eticheta .= '<span class="nav-badge" title="' . $numarInsigna . ' în așteptare"'
                    . ' style="display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;margin-left:8px;'
                    . 'padding:0 5px;border-radius:999px;background:#dc2626;color:#fff;font-size:11px;font-weight:700;line-height:1;'
                    . 'vertical-align:middle;">' . ($numarInsigna > 99 ? '99+' : (string) $numarInsigna) . '</span>';
            }
            $html .= $etichetaPeRand ? "\n" . $sp($indent + 4) . $eticheta . "\n" : ' ' . $eticheta . "\n";
            return $html . $sp($indent) . '</a>' . "\n";
        };
        $meniuHtml = '';
        foreach (\App\Support\Plugins::meniuAdmin() as $slot) {
            $permise = array_values(array_filter($slot['intrari'], $poateIntrarea));
            $meniuHtml .= $sp(20);
            if ($permise !== []) {
                if ($slot['tip'] === 'link') {
                    foreach ($permise as $intrare) {
                        $meniuHtml .= $linkMeniu($intrare, 20, $slot['cheie'] === 'dashboard');
                    }
                } else {
                    $grupDeschis = $isActive($slot['deschis']);
                    $meniuHtml .= $sp(20) . '<div class="admin-nav-group toggle-group ' . ($grupDeschis ? 'open' : '') . '" data-toggle-group="'
                        . htmlspecialchars($slot['cheie'], ENT_QUOTES) . '">' . "\n"
                        . $sp(24) . '<button type="button" class="group-toggle ' . ($grupDeschis ? 'active' : '') . '">' . "\n"
                        . $sp(28) . '<span class="left">' . "\n"
                        . $sp(32) . '<span class="nav-icon">' . $icon($slot['icon']) . '</span> ' . htmlspecialchars($slot['eticheta'], ENT_QUOTES) . "\n"
                        . $sp(28) . '</span>' . "\n"
                        . $sp(28) . '<span class="caret">' . ($grupDeschis ? '↑' : '↓') . '</span>' . "\n"
                        . $sp(24) . '</button>' . "\n"
                        . $sp(24) . '<div class="group-items">' . "\n";
                    if (in_array($slot['cheie'], $conditiePeIntrare, true)) {
                        foreach ($slot['intrari'] as $intrare) {
                            $meniuHtml .= $sp(28) . ($poateIntrarea($intrare) ? $linkMeniu($intrare, 28) . $sp(28) : '');
                        }
                    } else {
                        foreach ($permise as $intrare) {
                            $meniuHtml .= $linkMeniu($intrare, 28);
                        }
                    }
                    $meniuHtml .= $sp(24) . '</div>' . "\n" . $sp(20) . '</div>' . "\n";
                }
                $meniuHtml .= $sp(20);
            }
            if (in_array($slot['cheie'], $randGolDupa, true)) {
                $meniuHtml .= "\n";
            }
        }
        ?>
        <div class="admin-shell" id="admin-shell">
            <aside class="admin-sidebar">
                <div class="admin-brand">
                    <strong>Admin Panel</strong>
                </div>

                <nav class="admin-nav">
<?= $meniuHtml ?>
                </nav>

                <div class="admin-sidebar-footer">
                    <a href="/" target="_blank" rel="noreferrer">Vezi site</a>
                    <form method="post" action="/admin/logout">
                        <button type="submit" class="btn btn-secondary">Logout</button>
                    </form>
                </div>
            </aside>

            <main class="admin-content">
                <div class="admin-topbar">
                    <button type="button" class="icon-btn" id="sidebar-toggle-btn" title="Toggle meniu">☰</button>
                </div>
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
                <?php if (($message = \App\Support\Flash::get('success')) !== null): ?>
                    <section class="panel" style="border-color:#34d399;background:#ecfdf5;color:#065f46;">
                        <?= htmlspecialchars($message, ENT_QUOTES) ?>
                    </section>
                <?php endif; ?>
                <?php if (($message = \App\Support\Flash::get('error')) !== null): ?>
                    <section class="panel" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b;">
                        <?= htmlspecialchars($message, ENT_QUOTES) ?>
                    </section>
                <?php endif; ?>
                <?= $content ?>
            </main>
        </div>
        <script>
            (() => {
                const groups = document.querySelectorAll('.toggle-group');
                const syncCaret = (group) => {
                    const caret = group.querySelector('.caret');
                    if (!caret) return;
                    caret.textContent = group.classList.contains('open') ? '↑' : '↓';
                };
                groups.forEach((group) => {
                    const key = 'admin_toggle_' + group.dataset.toggleGroup;
                    const button = group.querySelector('.group-toggle');
                    const saved = window.localStorage.getItem(key);
                    if (saved === 'open') group.classList.add('open');
                    if (saved === 'closed') group.classList.remove('open');
                    syncCaret(group);
                    button.addEventListener('click', () => {
                        group.classList.toggle('open');
                        syncCaret(group);
                        window.localStorage.setItem(key, group.classList.contains('open') ? 'open' : 'closed');
                    });
                });

                const shell = document.getElementById('admin-shell');
                const toggleBtn = document.getElementById('sidebar-toggle-btn');
                const hidden = window.localStorage.getItem('admin_sidebar_hidden');
                if (hidden === '1') shell.classList.add('sidebar-hidden');
                toggleBtn?.addEventListener('click', () => {
                    shell.classList.toggle('sidebar-hidden');
                    window.localStorage.setItem('admin_sidebar_hidden', shell.classList.contains('sidebar-hidden') ? '1' : '0');
                });
            })();
        </script>
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
