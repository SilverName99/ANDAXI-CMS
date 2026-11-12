<?php
/**
 * Pagina „Contul meu” cu antet (tema site-ului: `tema.json` → `"pagina_cont": "antet"`; docs/PACHETE.md). Aceeași pagină ca
 * views/site/account.php (aceleași date de la SiteController::account, aceleași formulare și adrese, tab-urile și statisticile
 * pluginurilor), în forma paginii contului din codul vertij (9ab7999): antetul cu poza, numele, adresa, starea și statisticile,
 * meniul cu iconițe, cardurile; vizitatorul vede tab-urile Login / Înregistrare fără panoul și titlul paginii nucleului.
 *
 * Față de vertij: fiecare secțiune e o pagină (linkurile meniului o deschid; vertij le comuta din JavaScript, cu toate panourile
 * în pagină), comenzile și adresele (secțiunile și statistica) apar doar cu magazinul pe site, punctele doar cu pluginul lor,
 * poza de profil e a pluginului `membri` (`cont.profil`, cu „Șterge poza”), starea membrului vine din `cont.stare`.
 * Culorile și forma (clasele `.acc-*`) le dă foaia temei; vederea nu are `<style>`.
 */
$customer = is_array($customer ?? null) ? $customer : null;
$orders = is_array($orders ?? null) ? $orders : [];
$activeView = in_array((string) ($activeView ?? 'login'), ['login', 'register'], true) ? (string) $activeView : 'login';
$taburiPlugin = is_array($taburiPlugin ?? null) ? $taburiPlugin : [];
$tabPluginHtml = (string) ($tabPluginHtml ?? '');
$statisticiPlugin = is_array($statisticiPlugin ?? null) ? $statisticiPlugin : [];
$comenziAscunse = max(0, (int) ($comenziAscunse ?? 0));
$textComenziAscunse = (string) ($textComenziAscunse ?? '');
$confirmareInAsteptare = !empty($confirmareInAsteptare);
$stareCont = is_array($stareCont ?? null) ? $stareCont : null;
$magazinPeSite = !empty($magazinPeSite);
$dbReady = (bool) ($dbReady ?? false);
$next = (string) ($next ?? '/contul-meu');
$ordersCount = max(0, (int) ($ordersCount ?? count($orders)));
$addresses = is_array($addresses ?? null) ? $addresses : [];
$registrationFields = is_array($registrationFields ?? null) ? $registrationFields : [
    'first_name' => true, 'last_name' => true, 'email' => true, 'phone' => true,
    'birth_date' => false, 'gender' => false, 'password' => true, 'password_confirm' => true,
];
$registerAntiBot = is_array($registerAntiBot ?? null) ? $registerAntiBot : [];
$registerAntiBotToken = trim((string) ($registerAntiBot['token'] ?? ''));
$registerAntiBotRenderedAt = (int) ($registerAntiBot['rendered_at'] ?? 0);
$latestBillingOrder = is_array($latestBillingOrder ?? null) ? $latestBillingOrder : null;
$loyaltyTransactions = is_array($loyaltyTransactions ?? null) ? $loyaltyTransactions : [];
$loyaltyConfig = is_array($loyaltyConfig ?? null) ? $loyaltyConfig : ['enabled' => false];
$loyaltyEnabled = !empty($loyaltyConfig['enabled']);

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$formatDate = static function (mixed $value, string $format = 'd.m.Y'): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts !== false ? date($format, $ts) : $raw;
};

// Meniul: doar secțiunile care au sens pe site (comenzile și adresele cu magazinul, punctele cu pluginul lor), tab-urile
// pluginurilor imediat după profil (în vertij „Activează contul” / „Cotizația mea” era al doilea).
$menu = ['profile' => ['eticheta' => 'Profilul meu', 'icon' => 'profile', 'insigna' => '']];
foreach ($taburiPlugin as $cheieTab => $tabPlugin) {
    $menu[(string) $cheieTab] = ['eticheta' => (string) $tabPlugin['eticheta'], 'icon' => (string) $tabPlugin['icon'], 'insigna' => (string) $tabPlugin['insigna']];
}
if ($magazinPeSite) {
    $menu['orders'] = ['eticheta' => 'Comenzile mele', 'icon' => 'orders', 'insigna' => ''];
    $menu['addresses'] = ['eticheta' => 'Adrese', 'icon' => 'addresses', 'insigna' => ''];
}
if ($loyaltyEnabled) {
    $menu['points'] = ['eticheta' => 'Punctele mele', 'icon' => 'points', 'insigna' => ''];
}
$menu['settings'] = ['eticheta' => 'Setări', 'icon' => 'settings', 'insigna' => ''];
$accountSection = (string) ($accountSection ?? 'profile');
if (!array_key_exists($accountSection, $menu)) {
    $accountSection = 'profile';
}

$menuIcons = [
    'profile' => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.4 0-8 2-8 4.5V21h16v-2.5C20 16 16.4 14 12 14Z"/>',
    'activare' => '<path d="M12 3 5 6v6c0 5 3.4 7.9 7 9 3.6-1.1 7-4 7-9V6l-7-3Z"/><path d="m9.5 12 1.8 1.8L15 10.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
    'orders' => '<path d="M12 3 4 7l8 4 8-4-8-4Zm-8 6 8 4v8l-8-4V9Zm16 0-8 4v8l8-4V9Z"/>',
    'addresses' => '<path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11Zm0-8a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z"/>',
    'points' => '<path d="m12 3 2.8 5.6 6.2.9-4.5 4.4 1.1 6.2L12 17.2l-5.6 2.9 1.1-6.2L3 9.5l6.2-.9L12 3Z"/>',
    'donations' => '<path d="M12 20.4 4.6 13a4.6 4.6 0 0 1 6.5-6.5l.9.9.9-.9A4.6 4.6 0 1 1 19.4 13L12 20.4Z"/>',
    'settings' => '<path d="M12 8.6a3.4 3.4 0 1 0 0 6.8 3.4 3.4 0 0 0 0-6.8Z"/><path d="M20 13.2v-2.4l-2.1-.5a6 6 0 0 0-.6-1.3l1.2-1.8-1.7-1.7-1.8 1.2a6 6 0 0 0-1.3-.6L13.2 4h-2.4l-.5 2.1a6 6 0 0 0-1.3.6L7.2 5.5 5.5 7.2l1.2 1.8a6 6 0 0 0-.6 1.3L4 10.8v2.4l2.1.5a6 6 0 0 0 .6 1.3l-1.2 1.8 1.7 1.7 1.8-1.2a6 6 0 0 0 1.3.6l.5 2.1h2.4l.5-2.1a6 6 0 0 0 1.3-.6l1.8 1.2 1.7-1.7-1.2-1.8a6 6 0 0 0 .6-1.3l2.1-.5Z"/>',
];
$icon = static fn (string $key): string => '<svg class="acc-ico" viewBox="0 0 24 24" aria-hidden="true">' . ($menuIcons[$key] ?? '') . '</svg>';

if (is_array($customer)) {
    $fullName = \App\Support\CustomerDisplay::nume((string) ($customer['first_name'] ?? ''), (string) ($customer['last_name'] ?? ''), (string) ($customer['email'] ?? ''));
    $avatarInitials = \App\Support\CustomerDisplay::initiale((string) ($customer['first_name'] ?? ''), (string) ($customer['last_name'] ?? ''), (string) ($customer['email'] ?? ''));
    $gender = (string) ($customer['gender'] ?? '');
    $birthDate = (string) ($customer['birth_date'] ?? '');
    // Poza de profil (pluginul membri); fără el, inițialele.
    $pozaProfil = \App\Support\Hooks::html('cont.profil', $customer);
    // Codul de membru (statistica `cod_membru` a lui cont.antet) are caseta lui sub nume; restul statisticilor, în rând.
    $codMembru = '';
    $statisticiAntet = [];
    foreach ($statisticiPlugin as $statisticaPlugin) {
        if (($statisticaPlugin['cheie'] ?? '') === 'cod_membru') {
            $codMembru = (string) $statisticaPlugin['valoare'];
        } else {
            $statisticiAntet[] = $statisticaPlugin;
        }
    }
    $tonInsigna = ['ok' => 'is-ok', 'asteapta' => 'is-wait', 'oprit' => 'is-off'][(string) ($stareCont['ton'] ?? '')] ?? 'is-wait';
    $cardStare = is_array($stareCont['card'] ?? null) ? $stareCont['card'] : null;
}
?>
<section class="acc-wrap">
<?php if (!$dbReady): ?>
    <p class="acc-empty">
        <strong>Contul nu este disponibil momentan</strong>
        Conturile client nu sunt disponibile momentan deoarece conexiunea la baza de date nu este activă.
    </p>
<?php elseif (is_array($customer)): ?>
    <header class="acc-hero">
        <div class="acc-hero-top">
<?php if ($pozaProfil !== ''): ?>
<?= $pozaProfil ?>
<?php else: ?>
            <span class="acc-avatar acc-avatar--fara-poza"><span class="acc-avatar-initials"><?= $esc($avatarInitials) ?></span></span>
<?php endif; ?>
            <div class="acc-hero-id">
                <h1><?= $esc($fullName !== '' ? $fullName : 'Contul meu') ?></h1>
                <p><?= $esc((string) ($customer['email'] ?? '')) ?></p>
<?php if (trim((string) ($stareCont['insigna'] ?? '')) !== ''): ?>
                <span class="acc-pill <?= $esc($tonInsigna) ?>"><?= $esc($stareCont['insigna']) ?></span>
<?php endif; ?>
            </div>
        </div>
<?php if ($codMembru !== ''): ?>
        <div class="acc-code">
            <span>Cod de membru</span>
            <strong><?= $esc($codMembru) ?></strong>
        </div>
<?php endif; ?>
<?php if ($statisticiAntet !== [] || $magazinPeSite || $loyaltyEnabled): ?>
        <div class="acc-stats">
<?php foreach ($statisticiAntet as $statisticaPlugin): ?>
<?php $cheieStat = preg_match('/^[a-z0-9_-]{1,40}$/', (string) ($statisticaPlugin['cheie'] ?? '')) === 1 ? ' acc-stat--' . $statisticaPlugin['cheie'] : ''; ?>
            <div class="acc-stat<?= $esc($cheieStat) ?>">
                <strong><?= $esc($statisticaPlugin['valoare']) ?></strong>
                <span><?= $esc($statisticaPlugin['eticheta']) ?></span>
            </div>
<?php endforeach; ?>
<?php if ($magazinPeSite): ?>
            <div class="acc-stat">
                <strong><?= $ordersCount ?></strong>
                <span>Comenzi</span>
            </div>
<?php endif; ?>
<?php if ($loyaltyEnabled): ?>
            <div class="acc-stat">
                <strong><?= (int) ($customer['loyalty_points'] ?? 0) ?></strong>
                <span>Puncte fidelitate</span>
            </div>
<?php endif; ?>
        </div>
<?php endif; ?>
    </header>

    <div class="acc-grid">
        <aside class="acc-side">
            <nav class="acc-nav">
<?php foreach ($menu as $key => $item): ?>
                <a class="<?= $accountSection === $key ? 'is-active' : '' ?>" href="/contul-meu?section=<?= urlencode((string) $key) ?>"<?= $accountSection === $key ? ' aria-current="page"' : '' ?>>
                    <?= $icon($item['icon']) ?>
                    <span><?= $esc($item['eticheta']) ?></span>
<?php if ($item['insigna'] !== ''): ?>
                    <span class="acc-badge"><?= $esc($item['insigna']) ?></span>
<?php endif; ?>
                </a>
<?php endforeach; ?>
            </nav>
            <form method="post" action="/contul-meu/logout">
                <button class="acc-logout" type="submit">Deconectare</button>
            </form>
        </aside>

        <div class="acc-main">
<?php if ($comenziAscunse > 0): ?>
            <article class="acc-card acc-card--nota">
                <p class="acc-lead"><?= $esc($textComenziAscunse) ?></p>
                <form method="post" action="/contul-meu/confirmare-email"><button class="acc-btn acc-btn--ghost" type="submit">Trimite linkul</button></form>
            </article>
<?php endif; ?>
            <section class="acc-panel is-active" data-acc-panel="<?= $esc($accountSection) ?>">
<?php if (isset($taburiPlugin[$accountSection])): ?>
<?= $tabPluginHtml ?>
<?php elseif ($accountSection === 'orders'): ?>
                <article class="acc-card">
                    <h2>Comenzile mele</h2>
                    <p class="acc-lead">Toate comenzile plasate cu acest cont.</p>
<?php if ($orders === []): ?>
                    <p class="acc-empty">
                        <strong>Nicio comandă deocamdată</strong>
                        Comenzile pe care le plasezi vor apărea aici, cu status și istoric de plată.
                    </p>
<?php else: ?>
                    <div class="acc-table-wrap">
                        <table class="acc-table">
                            <thead>
                            <tr><th>Comandă</th><th>Data</th><th>Status</th><th>Plată</th><th>Total</th></tr>
                            </thead>
                            <tbody>
<?php foreach ($orders as $order): ?>
<?php $paid = (string) ($order['payment_status'] ?? '') === 'paid'; ?>
                            <tr>
                                <td><strong><?= $esc($order['order_number'] ?? '') ?></strong></td>
                                <td><?= $esc($formatDate($order['created_at'] ?? '')) ?></td>
                                <td><span class="acc-tag"><?= $esc($order['status'] ?? '') ?></span></td>
                                <td><span class="acc-tag <?= $paid ? 'is-ok' : 'is-off' ?>"><?= $esc($order['payment_status'] ?? '') ?></span></td>
                                <td><strong><?= number_format((float) ($order['total'] ?? 0), 2, ',', '.') ?> lei</strong></td>
                            </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
<?php endif; ?>
                </article>
<?php elseif ($accountSection === 'addresses'): ?>
                <article class="acc-card">
                    <div class="acc-head">
                        <h2>Adresele mele</h2>
                        <button class="acc-btn acc-btn--ghost" type="button" id="address-add-toggle">+ Adaugă adresă</button>
                    </div>
                    <p class="acc-lead">Adresele salvate se completează automat la finalizarea comenzilor.</p>
                    <form method="post" action="/contul-meu/adrese" class="acc-form" id="address-add-form" style="display:none;">
                        <div><label>Etichetă adresă</label><input type="text" name="label" placeholder="Acasă, Cabinet..."></div>
                        <div><label>Nume complet</label><input type="text" name="full_name" required></div>
                        <div><label>Telefon</label><input type="text" name="phone"></div>
                        <div><label>Cod poștal</label><input type="text" name="postcode"></div>
                        <div class="acc-full"><label>Adresă linia 1</label><input type="text" name="address_line1" required></div>
                        <div class="acc-full"><label>Adresă linia 2</label><input type="text" name="address_line2"></div>
                        <div><label>Oraș</label><input type="text" name="city" required></div>
                        <div><label>Județ</label><input type="text" name="county" required></div>
                        <div class="acc-full">
                            <label class="acc-check"><input type="checkbox" name="is_default" value="1"> Setează ca adresă implicită</label>
                        </div>
                        <div class="acc-full"><button class="acc-btn" type="submit">Salvează adresa</button></div>
                    </form>
<?php if ($addresses === []): ?>
                    <p class="acc-empty">
                        <strong>Nicio adresă salvată</strong>
                        Adaugă una ca să nu mai completezi datele la fiecare comandă.
                    </p>
<?php else: ?>
                    <div class="acc-address-list">
<?php foreach ($addresses as $address): ?>
                        <article class="acc-address">
                            <div class="acc-address-head">
                                <strong><?= $esc($address['label'] ?? 'Adresă') ?></strong>
<?php if ((int) ($address['is_default'] ?? 0) === 1): ?>
                                <span class="acc-tag is-ok">Implicită</span>
<?php endif; ?>
                            </div>
                            <p><?= $esc($address['full_name'] ?? '') ?><?= !empty($address['phone']) ? ' · ' . $esc($address['phone']) : '' ?></p>
                            <p><?= $esc(trim((string) ($address['address_line1'] ?? '') . ' ' . (string) ($address['address_line2'] ?? ''))) ?></p>
                            <p><?= $esc(trim((string) ($address['city'] ?? '') . ', ' . (string) ($address['county'] ?? ''), ', ')) ?><?= !empty($address['postcode']) ? ' · ' . $esc($address['postcode']) : '' ?></p>
                        </article>
<?php endforeach; ?>
                    </div>
<?php endif; ?>
                </article>
<?php if (is_array($latestBillingOrder)): ?>
                <article class="acc-card">
                    <h2>Date de facturare</h2>
                    <p class="acc-lead">Preluate din ultima ta comandă.</p>
                    <div class="acc-info-grid">
                        <div class="acc-info"><span>Nume</span><strong><?= $esc(trim((string) ($latestBillingOrder['billing_first_name'] ?? '') . ' ' . (string) ($latestBillingOrder['billing_last_name'] ?? ''))) ?: '—' ?></strong></div>
                        <div class="acc-info"><span>Telefon</span><strong><?= $esc($latestBillingOrder['billing_phone'] ?? '') ?: '—' ?></strong></div>
                        <div class="acc-info"><span>Adresă</span><strong><?= $esc(trim((string) ($latestBillingOrder['billing_address_line1'] ?? '') . ' ' . (string) ($latestBillingOrder['billing_address_line2'] ?? ''))) ?: '—' ?></strong></div>
                        <div class="acc-info"><span>Localitate</span><strong><?= $esc(trim((string) ($latestBillingOrder['billing_city'] ?? '') . ', ' . (string) ($latestBillingOrder['billing_county'] ?? ''), ', ')) ?: '—' ?></strong></div>
                    </div>
                </article>
<?php endif; ?>
<?php elseif ($accountSection === 'points'): ?>
                <article class="acc-card">
                    <h2>Punctele mele</h2>
                    <p class="acc-lead">
                        Sold curent: <strong><?= (int) ($customer['loyalty_points'] ?? 0) ?> puncte</strong>
                        · 1 punct = <?= number_format((float) ($loyaltyConfig['redeem_value'] ?? 0.10), 2, ',', '.') ?> lei
                    </p>
                    <div class="acc-info-grid">
                        <div class="acc-info"><span>Rată de acumulare</span><strong><?= number_format((float) ($loyaltyConfig['earn_rate'] ?? 1), 2, ',', '.') ?> puncte / leu</strong></div>
<?php if (!empty($loyaltyConfig['promo_multiplier']) && (float) $loyaltyConfig['promo_multiplier'] > 1): ?>
                        <div class="acc-info"><span>Promoție activă</span><strong>×<?= number_format((float) $loyaltyConfig['promo_multiplier'], 2, ',', '.') ?></strong></div>
<?php endif; ?>
<?php if (!empty($loyaltyConfig['promo_weekend_enabled']) && (float) ($loyaltyConfig['promo_weekend_multiplier'] ?? 1) > 1): ?>
                        <div class="acc-info"><span>Bonus de weekend</span><strong>×<?= number_format((float) $loyaltyConfig['promo_weekend_multiplier'], 2, ',', '.') ?></strong></div>
<?php endif; ?>
                    </div>
                    <h3>Istoric puncte</h3>
<?php if ($loyaltyTransactions === []): ?>
                    <p class="acc-empty">
                        <strong>Niciun punct acumulat încă</strong>
                        Punctele apar aici după prima comandă.
                    </p>
<?php else: ?>
                    <div class="acc-table-wrap">
                        <table class="acc-table">
                            <thead>
                            <tr><th>Data</th><th>Tip</th><th>Modificare</th><th>Sold după</th><th>Detalii</th></tr>
                            </thead>
                            <tbody>
<?php foreach ($loyaltyTransactions as $tx): ?>
<?php $delta = (int) ($tx['points_delta'] ?? 0); ?>
                            <tr>
                                <td><?= $esc($formatDate($tx['created_at'] ?? '', 'd.m.Y H:i')) ?></td>
                                <td><span class="acc-tag"><?= $esc($tx['tx_type'] ?? '') ?></span></td>
                                <td class="<?= $delta >= 0 ? 'acc-plus' : 'acc-minus' ?>"><?= $esc(($delta > 0 ? '+' : '') . $delta) ?></td>
                                <td><?= (int) ($tx['balance_after'] ?? 0) ?></td>
                                <td><?= $esc($tx['reason'] ?? '—') ?></td>
                            </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
<?php endif; ?>
                </article>
<?php elseif ($accountSection === 'settings'): ?>
                <article class="acc-card">
                    <h2>Setări cont</h2>
                    <p class="acc-lead">Securitatea contului și opțiuni avansate.</p>
                    <h3>Schimbă parola</h3>
                    <form method="post" action="/contul-meu/parola" class="acc-form">
                        <div class="acc-full"><label>Parola curentă</label><input type="password" name="current_password" required></div>
                        <div><label>Parolă nouă</label><input type="password" name="new_password" minlength="8" required></div>
                        <div><label>Confirmă parola nouă</label><input type="password" name="new_password_confirm" minlength="8" required></div>
                        <div class="acc-full"><button class="acc-btn" type="submit">Schimbă parola</button></div>
                    </form>
                    <div class="acc-danger">
                        <h3>Șterge contul</h3>
                        <p>
                            Ștergerea contului este permanentă. Vei pierde accesul la conținutul dedicat membrilor,
                            iar cotizația achitată nu se restituie.
                        </p>
                        <form method="post" action="/contul-meu/sterge" onsubmit="return confirm('Sigur vrei să ștergi contul? Acțiunea nu poate fi anulată.');">
                            <input type="hidden" name="confirm_delete" value="1">
                            <button class="acc-btn acc-btn--danger" type="submit">Șterge contul definitiv</button>
                        </form>
                    </div>
                </article>
<?php else: ?>
                <article class="acc-card">
                    <h2>Profilul meu</h2>
                    <p class="acc-lead">Datele tale de contact. Le poți actualiza oricând.</p>
                    <form method="post" action="/contul-meu/profil" class="acc-form">
                        <div><label>Prenume</label><input type="text" name="first_name" required value="<?= $esc($customer['first_name'] ?? '') ?>"></div>
                        <div><label>Nume</label><input type="text" name="last_name" required value="<?= $esc($customer['last_name'] ?? '') ?>"></div>
                        <div><label>Email</label><input type="email" name="email" required value="<?= $esc($customer['email'] ?? '') ?>"></div>
                        <div><label>Telefon</label><input type="text" name="phone" value="<?= $esc($customer['phone'] ?? '') ?>"></div>
                        <div><label>Data nașterii</label><?php $birthDateValue = $birthDate; include __DIR__ . '/components/birth-date-field.php'; ?></div>
                        <div>
                            <label>Gen</label>
                            <select name="gender">
                                <option value="">Selectează</option>
                                <option value="feminin" <?= $gender === 'feminin' ? 'selected' : '' ?>>Feminin</option>
                                <option value="masculin" <?= $gender === 'masculin' ? 'selected' : '' ?>>Masculin</option>
                            </select>
                        </div>
                        <div class="acc-full"><button class="acc-btn" type="submit">Salvează modificările</button></div>
                    </form>
                </article>
<?php if ($cardStare !== null): ?>
                <article class="acc-card">
                    <div class="acc-head">
                        <h2><?= $esc($cardStare['titlu'] ?? '') ?></h2>
<?php if (is_array($cardStare['legatura'] ?? null) && str_starts_with((string) ($cardStare['legatura']['href'] ?? ''), '/')): ?>
                        <a class="acc-btn acc-btn--ghost" href="<?= $esc($cardStare['legatura']['href']) ?>"><?= $esc($cardStare['legatura']['text'] ?? '') ?></a>
<?php endif; ?>
                    </div>
                    <div class="acc-info-grid">
<?php foreach ((array) ($cardStare['randuri'] ?? []) as $rand): ?>
                        <div class="acc-info"><span><?= $esc($rand['eticheta'] ?? '') ?></span><strong><?= $esc($rand['valoare'] ?? '') ?></strong></div>
<?php endforeach; ?>
                    </div>
                </article>
<?php endif; ?>
<?php endif; ?>
            </section>
        </div>
    </div>
<?php else: ?>
    <div class="account-auth-tabs">
        <a class="<?= $activeView === 'login' ? 'active' : '' ?>" href="/login">Login</a>
        <a class="<?= $activeView === 'register' ? 'active' : '' ?>" href="/register">Înregistrare</a>
    </div>

    <div class="account-auth-grid">
        <article class="panel" style="<?= $activeView === 'login' ? '' : 'display:none;' ?>" data-auth-view="login">
            <h3 style="margin-top:0;">Autentificare</h3>
            <form method="post" action="/login" class="field">
                <input type="hidden" name="next" value="<?= $esc($next) ?>">
                <label>Email</label>
                <input type="email" name="email" required>
                <label>Parolă</label>
                <input type="password" name="password" required>
                <button class="btn" type="submit">Login</button>
            </form>
            <p style="margin-top:10px;"><a href="/contul-meu/resetare-parola">Ai uitat parola?</a></p>
<?php if ($confirmareInAsteptare): ?>
            <form method="post" action="/contul-meu/confirmare-email" style="margin-top:10px;">
                <button class="btn btn-secondary" type="submit">Trimite din nou linkul de confirmare</button>
            </form>
<?php endif; ?>
        </article>

        <article class="panel" style="<?= $activeView === 'register' ? '' : 'display:none;' ?>" data-auth-view="register">
            <h3 style="margin-top:0;">Creează cont</h3>
<?php $campuriPlugin = \App\Support\Hooks::oricare('inregistrare.inlocuieste_campurile'); /* membri: tipul de cont aduce câmpurile (vertij) */ ?>
            <form method="post" action="/register" class="form-grid"<?= $campuriPlugin ? ' enctype="multipart/form-data"' : '' ?>>
                <input type="hidden" name="next" value="<?= $esc($next) ?>">
                <input type="hidden" name="register_form_token" value="<?= $esc($registerAntiBotToken) ?>">
                <input type="hidden" name="register_form_rendered_at" value="<?= $registerAntiBotRenderedAt > 0 ? $registerAntiBotRenderedAt : time() ?>">
                <div class="field" style="display:none;" aria-hidden="true">
                    <label for="register-hp-website">Website</label>
                    <input id="register-hp-website" type="text" name="register_hp_website" value="" tabindex="-1" autocomplete="off">
                </div>
<?php if (!$campuriPlugin): ?>
<?php if (!empty($registrationFields['first_name'])): ?>
                <div class="field"><label>Prenume</label><input type="text" name="first_name" required></div>
<?php endif; ?>
<?php if (!empty($registrationFields['last_name'])): ?>
                <div class="field"><label>Nume</label><input type="text" name="last_name" required></div>
<?php endif; ?>
<?php if (!empty($registrationFields['email'])): ?>
                <div class="field"><label>Email</label><input type="email" name="email" required></div>
<?php endif; ?>
<?php if (!empty($registrationFields['phone'])): ?>
                <div class="field"><label>Telefon</label><input type="text" name="phone"></div>
<?php endif; ?>
<?php if (!empty($registrationFields['birth_date'])): ?>
                <div class="field"><label>Data nașterii</label><?php $birthDateValue = ''; include __DIR__ . '/components/birth-date-field.php'; ?></div>
<?php endif; ?>
<?php if (!empty($registrationFields['gender'])): ?>
                <div class="field">
                    <label>Gen</label>
                    <select name="gender">
                        <option value="">Selectează</option>
                        <option value="feminin">Feminin</option>
                        <option value="masculin">Masculin</option>
                    </select>
                </div>
<?php endif; ?>
<?php if (!empty($registrationFields['password'])): ?>
                <div class="field"><label>Parolă</label><input type="password" name="password" minlength="8" required></div>
<?php endif; ?>
<?php if (!empty($registrationFields['password_confirm'])): ?>
                <div class="field"><label>Confirmă parola</label><input type="password" name="password_confirm" minlength="8" required></div>
<?php endif; ?>
<?php endif; ?>
<?= \App\Support\Hooks::html('inregistrare.formular') ?>
                <div style="grid-column:1/-1;">
                    <button class="btn" type="submit">Creează cont</button>
                </div>
            </form>
        </article>
    </div>
<?php endif; ?>
</section>
<?php if (is_array($customer) && $accountSection === 'addresses'): ?>
<script>
(() => {
    const toggle = document.getElementById('address-add-toggle');
    const form = document.getElementById('address-add-form');
    if (!toggle || !form) return;
    toggle.addEventListener('click', () => {
        const isOpen = form.style.display !== 'none';
        form.style.display = isOpen ? 'none' : 'grid';
        toggle.textContent = isOpen ? '+ Adaugă adresă' : 'Renunță';
    });
})();
</script>
<?php endif; ?>
