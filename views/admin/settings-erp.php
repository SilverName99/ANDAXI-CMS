<?php
$erpEnabled = (string) ($settings['erp_enabled'] ?? '0') === '1';
$stockEnabled = (string) ($settings['erp_stock_enabled'] ?? '0') === '1';
$apiKey = (string) ($settings['erp_api_key'] ?? '');
$queue = is_array($queue ?? null) ? $queue : ['pending' => 0, 'failed' => 0, 'sent' => 0];
$inCurs = (int) ($queue['cancel_pending'] ?? 0) + (int) ($queue['retur_pending'] ?? 0);
// Pragul trimiterii automate (erp_trimite_din, WP-12 E1); fără prag pagina e cea de până acum.
$pragErp = is_array($pragErp ?? null) ? $pragErp : ['stare' => 'fara', 'din' => null];
$inainteDePrag = (int) ($queue['inainte_de_prag'] ?? 0);
// Explicațiile despre AWB și puncte doar pe site-urile cu livrarea FAN, respectiv cu fidelitatea.
$cuFan = \App\Support\Plugins::vizibil('livrare_fan');
$cuFidelitate = \App\Support\Plugins::vizibil('fidelitate');
?>

<?php if (!empty($erpModulBanner)): ?>
    <section class="panel" style="border-color:#fcd34d;background:#fffbeb;color:#92400e;">
        <?= htmlspecialchars((string) $erpModulBanner, ENT_QUOTES) ?>
    </section>
<?php endif; ?>

<section class="panel">
    <h1>ERP ANDAXI</h1>
    <p>Trimite automat comenzile din magazin către ERP și citește stocul real din gestiune.</p>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">
        <div class="panel" style="flex:1;min-width:150px;margin:0;padding:12px;">
            <div style="font-size:12px;text-transform:uppercase;color:#64748b;">Trimise</div>
            <div style="font-size:22px;font-weight:700;color:#166534;"><?= (int) $queue['sent'] ?></div>
        </div>
        <div class="panel" style="flex:1;min-width:150px;margin:0;padding:12px;">
            <div style="font-size:12px;text-transform:uppercase;color:#64748b;">În așteptare</div>
            <div style="font-size:22px;font-weight:700;color:#92400e;"><?= (int) $queue['pending'] ?></div>
        </div>
        <div class="panel" style="flex:1;min-width:150px;margin:0;padding:12px;">
            <div style="font-size:12px;text-transform:uppercase;color:#64748b;">Eșuate</div>
            <div style="font-size:22px;font-weight:700;color:#991b1b;"><?= (int) $queue['failed'] ?></div>
        </div>
        <div class="panel" style="flex:1;min-width:150px;margin:0;padding:12px;"
             title="Comenzi anulate sau returnate aici, a căror anulare ori retur n-a ajuns încă în ERP. Cron-ul le reia la fiecare rulare.">
            <div style="font-size:12px;text-transform:uppercase;color:#64748b;">Anulări și retururi în curs</div>
            <div style="font-size:22px;font-weight:700;color:#92400e;"><?= $inCurs ?></div>
        </div>
    </div>
<?php if ($pragErp['stare'] === 'data'): ?>
    <p class="muted" data-prag-erp="data" style="margin:0 0 16px;">
        Comenzile plasate înainte de <strong><?= htmlspecialchars(date('d.m.Y H:i', (int) strtotime((string) $pragErp['din'])), ENT_QUOTES) ?></strong>, momentul legării site-ului cu ERP-ul, pe care ERP-ul nu le-a primit niciodată, nu se trimit automat (<?= $inainteDePrag ?> netrimise; nu intră în „În așteptare” și „Eșuate”). Una anume se trimite din Comenzi, cu 🔄; de atunci e ca oricare alta: o trimitere eșuată se reîncearcă singură.
    </p>
<?php endif; ?>
<?php if ($pragErp['stare'] === 'lipsa' || $pragErp['stare'] === 'gresit'): ?>
    <p class="muted" data-prag-erp="<?= htmlspecialchars((string) $pragErp['stare'], ENT_QUOTES) ?>" style="margin:0 0 16px;color:#92400e;">
        Pragul trimiterii (setarea <code>erp_trimite_din</code>) <?= $pragErp['stare'] === 'lipsa' ? 'nu e încă scris' : 'nu e o dată' ?>: nicio comandă netrimisă nu pleacă automat în ERP (<?= $inainteDePrag ?> așteaptă). Îl scrie pasul de activare al ERP-ului, la prima cerere după ce baza e la zi cu migrările (<code>scripts/migreaza.php --aplica</code>).
    </p>
<?php endif; ?>

    <form method="post" action="/admin/settings/erp" class="form-grid">
        <label class="form-field" style="grid-column:1 / -1;">
            <span>
                <input type="checkbox" name="erp_enabled" value="1" <?= $erpEnabled ? 'checked' : '' ?>>
                Trimite comenzile în ERP
            </span>
            <small>Cât timp e oprită, comenzile se salvează normal pe site, dar nu pleacă spre ERP.</small>
        </label>

        <label class="form-field" style="grid-column:1 / -1;">
            <span>Adresa ERP-ului</span>
            <input type="url" name="erp_url" placeholder="https://bioscem.erp.andaxi.ro"
                   value="<?= htmlspecialchars((string) ($settings['erp_url'] ?? ''), ENT_QUOTES) ?>">
            <small>Fără „/api" la final — se adaugă automat.</small>
        </label>

        <label class="form-field" style="grid-column:1 / -1;">
            <span>Cheie ERP ANDAXI</span>
            <input type="text" name="erp_api_key" autocomplete="off" spellcheck="false"
                   placeholder="<?= $apiKey !== '' ? 'Cheie salvată — lasă gol ca să o păstrezi' : 'sk_site_...' ?>"
                   value="">
            <small>
                Se copiază din ERP: Setări → Setări site → „Cheie de integrare".
                <?php if ($apiKey !== ''): ?>
                    Cheia curentă: <code><?= htmlspecialchars(substr($apiKey, 0, 12) . '…' . substr($apiKey, -4), ENT_QUOTES) ?></code>
                <?php endif; ?>
            </small>
        </label>

        <label class="form-field">
            <span>Timeout cereri (secunde)</span>
            <input type="number" name="erp_timeout" min="5" max="60"
                   value="<?= (int) ($settings['erp_timeout'] ?? 20) ?>">
        </label>

        <label class="form-field" style="grid-column:1 / -1;">
            <span>
                <input type="checkbox" name="erp_stock_enabled" value="1" <?= $stockEnabled ? 'checked' : '' ?>>
                Afișează stocul din ERP pe site
            </span>
            <small>
                Când e pornită, produsele afișează „Existent" sau „Stoc 0" după disponibilul real din
                gestiune (stoc minus rezervările comenzilor în lucru), nu după stocul din fișa produsului.
            </small>
        </label>

        <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn" type="submit" name="action" value="save">Salvează</button>
            <button class="btn btn-secondary" type="submit" name="action" value="test">Testează conexiunea</button>
            <button class="btn btn-secondary" type="submit" name="action" value="retry">
                Retrimite comenzile eșuate
            </button>
            <button class="btn btn-secondary" type="submit" name="action" value="greutati"
                    title="Ia din ERP greutatea fiecărui produs (câmpul „Greutate (g)” din fișa produsului) și o scrie pe produsele de pe site.<?= $cuFan ? ' Din ea iese kilogramul de pe AWB.' : '' ?>">
                Adu greutățile din ERP
            </button>
            <button class="btn btn-secondary" type="submit" name="action" value="retururi"
                    title="Marchează în ERP toate comenzile cu statusul „Returnată”, ca să se numere la retururile clientului. Se poate apăsa oricând: comenzile deja marcate sunt sărite.">
                Retrimite retururile în ERP
            </button>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Reîncercări automate</h2>
    <p>
        Dacă ERP-ul nu răspunde, comanda rămâne pe site și se reîncearcă singură
        (după 1 minut, 5 minute, 15 minute, 1 oră, 6 ore, apoi la 12 ore, maximum 12 încercări).
        Ca reîncercările să ruleze și fără să intri în admin, adaugă un cron pe server:
    </p>
    <pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;">*/5 * * * * php <?= htmlspecialchars(dirname(__DIR__, 2), ENT_QUOTES) ?>/scripts/erp-sync.php >/dev/null 2>&1</pre>
    <p>
        Comenzile plătite cu cardul pleacă spre ERP abia după confirmarea plății;
        cele cu ramburs pleacă imediat ce sunt plasate.
    </p>
    <p>
        Anulările și retururile care nu ajung în ERP se reiau la fiecare rulare a cron-ului,
        fiecare pe drumul ei: un retur rămâne retur și în ERP, unde se numără la client.
    </p>
    <p>
        Dacă în ERP modulul „Magazin online” e oprit sau se oprește, ERP-ul nu mai primește
        comenzi noi de pe site. Ele rămân aici „în așteptare”, fără să-și consume încercările,
        se mai întreabă o dată pe oră și pleacă singure după ce modulul e pornit din nou
        (de regulă la următoarea rulare a cron-ului, cel târziu într-o oră). Anulările și retururile merg mai departe. Starea modulului
        o vezi în bannerul de sus, aici și în lista de comenzi.
    </p>
</section>

<section class="panel">
    <h2>Ce se întâmplă la aprobarea din ERP</h2>
    <p>
        Când operatorul aprobă comanda în ERP, ERP-ul emite factura, descarcă stocul
        și anunță site-ul. Site-ul trece comanda în „În procesare", reține numărul
        facturii<?= $cuFan ? ", generează AWB-ul FAN (credențialele de curier rămân aici) și trimite\n        clientului emailul cu tracking. Numărul AWB se întoarce apoi în ERP.\n" : ".\n" ?>
    </p>
    <p>
        Dacă site-ul e indisponibil în momentul aprobării, ERP-ul păstrează
        notificarea, iar cron-ul de mai sus o preia la următoarea rulare — deci nu se
        pierde nicio aprobare. Anularea din ERP anulează comanda și pe site<?= $cuFidelitate ? " și\n        returnează punctele de fidelitate" : '' ?>; o comandă deja returnată sau rambursată
        aici rămâne așa. O aprobare care sosește pentru o comandă anulată sau
        returnată aici<?= $cuFan ? ' nu mai generează AWB' : '' ?>: site-ul trimite anularea ori returul
        înapoi în ERP, unde se anulează și factura.
    </p>
</section>
