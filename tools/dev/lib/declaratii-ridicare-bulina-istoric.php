<?php

declare(strict_types=1);

/**
 * WP-R din 8 nov 2026 (Bioscem 807058e … c47f270): ce schimbă porturile față de f5ae5ea pe paginile datelor demo, pentru
 * comparația istorică (tools/dev/compara-cu-baza.php --baza=f5ae5ea, lista $porturiBioscemRidicare), cu baza în forma
 * Bioscem (înghețarea Bioscem: adresa sediului scrisă și bulina cu reducerea pornită): checkout-ul are stilurile, atributele
 * și scriptul ridicării personale și cu bifa oprită, ca în codul Bioscem (`RidicarePersonala::configurata`), iar pagina
 * produsului scriptul bulinei cu reducerea (`produs_bulina_reducere`) și spațiile blocului ei de lângă preț. Fiecare intrare:
 * „nou” = textul scris acum (cu atâtea rânduri neschimbate în jur cât să fie unic în pagină), „vechi” = ce scrie f5ae5ea în
 * locul lui, „ori” = de câte ori apare pe fiecare pagină, „din” = commit-ul Bioscem, „ce” = schimbarea. Scrise din diferența
 * dintre pagini, după celelalte declarații ale comparației istorice. Față de codul Bioscem însuși (G-cmp) nu se aplică: acolo
 * paginile ies octet cu octet.
 *
 * @return list<array{cale: string|list<string>, nou: string, vechi: string, ori: int, din: string, ce: string}>
 */
$produse = ['/produs/ceai-de-menta', '/produs/ceai-de-musetel', '/produs/seminte-de-chia', '/produs/zinc-15-mg'];

return [
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: stilurile ridicării personale',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
        .bv-checkout-v3__fanbox{border:1px solid #d5e5dc;border-radius:12px;padding:12px 14px;background:#f6faf8;}
        .bv-checkout-v3__ridicare{border:1px solid #d5e5dc;border-radius:12px;padding:12px 14px;background:#f6faf8;}
        .bv-checkout-v3__ridicare-info{display:none;margin:8px 0 0;font:500 13px/1.45 "DM Sans",Arial,sans-serif;color:#315542;}
        .bv-checkout-v3__ridicare-info.is-visible{display:block;}
        .bv-checkout-v3__ridicare-info p{margin:0 0 4px;}
        .bv-checkout-v3__ridicare-nota{display:none;margin:-3px 0 0;text-align:right;font:500 12px/1.35 "DM Sans",Arial,sans-serif;color:#2b6d49;}
        .bv-checkout-v3__ridicare-nota.is-visible{display:block;}
        .bv-checkout-v3__is-hidden{display:none !important;}
        .bv-checkout-v3__fanbox-pick{display:none;margin-top:10px;}

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
        .bv-checkout-v3__fanbox{border:1px solid #d5e5dc;border-radius:12px;padding:12px 14px;background:#f6faf8;}
        .bv-checkout-v3__fanbox-pick{display:none;margin-top:10px;}

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: câmpul „aceeași adresă” cu atributul ridicării (și spațiile casetei ei, oprită)',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
                        </div>
                                                                                                <input type="hidden" name="has_shipping_toggle" value="1">
                        <div class="bv-checkout-v3__field bv-checkout-v3__field--full" data-shipping-same-field>
                            <label class="bv-checkout-v3__checkbox">

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
                        </div>
                                                                        <input type="hidden" name="has_shipping_toggle" value="1">
                        <div class="bv-checkout-v3__field bv-checkout-v3__field--full">
                            <label class="bv-checkout-v3__checkbox">

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: titlul plății ramburs cu atributul ridicării',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
                                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 7.5h18M3 12h18M3 16.5h18" stroke="currentColor" stroke-width="1.7"/><rect x="2.5" y="5" width="19" height="14" rx="2.3" stroke="currentColor" stroke-width="1.7"/></svg>
                                        <span><strong data-ramburs-titlu>Ramburs</strong><br><span data-ramburs-sub>Plata la livrare
HTML_CMP,
        'vechi' => <<<'HTML_CMP'
                                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 7.5h18M3 12h18M3 16.5h18" stroke="currentColor" stroke-width="1.7"/><rect x="2.5" y="5" width="19" height="14" rx="2.3" stroke="currentColor" stroke-width="1.7"/></svg>
                                        <span><strong>Ramburs</strong><br><span data-ramburs-sub>Plata la livrare
HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: spațiile notei ridicării din sumar (oprită)',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
                    <div class="bv-checkout-v3__row"><span>Transport</span><strong data-checkout-shipping>15.00 lei</strong></div>
                                                                                
HTML_CMP,
        'vechi' => <<<'HTML_CMP'
                    <div class="bv-checkout-v3__row"><span>Transport</span><strong data-checkout-shipping>15.00 lei</strong></div>
                                                            
HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: bifa ridicării în script',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
        const shippingRequiredFields = Array.from(form.querySelectorAll('[data-shipping-required]'));
        const ridicareToggle = form.querySelector('[data-ridicare-toggle]');
        const ridicareActiva = () => ridicareToggle instanceof HTMLInputElement && ridicareToggle.checked;
        const syncShippingFields = () => {

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
        const shippingRequiredFields = Array.from(form.querySelectorAll('[data-shipping-required]'));
        const syncShippingFields = () => {

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: adresa de livrare: nu la ridicare',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
        const syncShippingFields = () => {
            // La ridicarea de la sediu nu există adresă de livrare.
            const different = !ridicareActiva() && shippingToggle instanceof HTMLInputElement && !shippingToggle.checked;
            if (shippingFieldsWrap instanceof HTMLElement) {

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
        const syncShippingFields = () => {
            const different = shippingToggle instanceof HTMLInputElement && !shippingToggle.checked;
            if (shippingFieldsWrap instanceof HTMLElement) {

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: titlul plății ramburs, în script',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
        const rambursSub = form.querySelector('[data-ramburs-sub]');
        const rambursTitlu = form.querySelector('[data-ramburs-titlu]');


HTML_CMP,
        'vechi' => <<<'HTML_CMP'
        const rambursSub = form.querySelector('[data-ramburs-sub]');


HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: „Plată la ridicare”, în script',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
            }
            if (rambursTitlu instanceof HTMLElement) {
                rambursTitlu.textContent = ridicareActiva() ? 'Plată la ridicare' : 'Ramburs';
            }
            if (rambursSub instanceof HTMLElement) {

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
            }
            if (rambursSub instanceof HTMLElement) {

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: subtitlul plății ramburs, în script',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
            if (rambursSub instanceof HTMLElement) {
                rambursSub.textContent = ridicareActiva()
                    ? '(numerar sau card)'
                    : (activ ? 'Plata la ridicarea din FANbox' : 'Plata la livrare');
            }

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
            if (rambursSub instanceof HTMLElement) {
                rambursSub.textContent = activ
                    ? 'Plata la ridicarea din FANbox'
                    : 'Plata la livrare';
            }

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: FANbox și ridicarea se exclud',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
            fanboxToggle.addEventListener('change', () => {
                // FANbox și ridicarea de la sediu se exclud.
                if (fanboxToggle.checked && ridicareToggle instanceof HTMLInputElement && ridicareToggle.checked) {
                    ridicareToggle.checked = false;
                    sincronizeazaRidicare();
                }
                sincronizeazaFanbox();

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
            fanboxToggle.addEventListener('change', () => {
                sincronizeazaFanbox();

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: sincronizarea ridicării',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
                // Prețul transportului depinde de alegere, deci recalculăm.
                void requestShippingQuote();
            });
        }

        // ── Ridicare personală de la sediu: fără transport, fără FANbox și
        // fără adresă de livrare separată (se cer doar datele de facturare). ──
        const ridicareInfo = form.querySelector('[data-ridicare-info]');
        const ridicareNota = container.querySelector('[data-ridicare-nota]');
        const fanboxBlock = form.querySelector('[data-fanbox-block]');
        const shippingSameField = form.querySelector('[data-shipping-same-field]');
        function sincronizeazaRidicare() {
            const activ = ridicareActiva();
            if (ridicareInfo instanceof HTMLElement) ridicareInfo.classList.toggle('is-visible', activ);
            if (ridicareNota instanceof HTMLElement) ridicareNota.classList.toggle('is-visible', activ);
            if (fanboxBlock instanceof HTMLElement) fanboxBlock.classList.toggle('bv-checkout-v3__is-hidden', activ);
            if (shippingSameField instanceof HTMLElement) shippingSameField.classList.toggle('bv-checkout-v3__is-hidden', activ);
            if (activ) {
                if (fanboxToggle instanceof HTMLInputElement && fanboxToggle.checked) {
                    fanboxToggle.checked = false;
                }
                if (shippingToggle instanceof HTMLInputElement) shippingToggle.checked = true;
            }
            syncShippingFields();
            sincronizeazaFanbox();
        }
        if (ridicareToggle instanceof HTMLInputElement) {
            ridicareToggle.addEventListener('change', () => {
                sincronizeazaRidicare();
                void requestShippingQuote();

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
                // Prețul transportului depinde de alegere, deci recalculăm.
                void requestShippingQuote();

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: cotarea transportului fără adresă la ridicare',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
            if (typeof shippingQuoteEndpoint !== 'string' || shippingQuoteEndpoint.trim() === '') return;
            // La ridicarea de la sediu transportul e 0 și fără adresă completă:
            // serverul răspunde pe loc (și ține minte alegerea).
            if (!ridicareActiva() && !hasCompleteShippingAddress()) {
                setShippingPendingUi();

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
            if (typeof shippingQuoteEndpoint !== 'string' || shippingQuoteEndpoint.trim() === '') return;
            if (!hasCompleteShippingAddress()) {
                setShippingPendingUi();

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: ridicarea în cererea de cotare',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
                        // acolo, nu abia după ce se alege lockerul.
                        ridicare_personala: ridicareActiva() ? 1 : 0,
                        livrare_fanbox: !ridicareActiva() && fanboxToggle instanceof HTMLInputElement && fanboxToggle.checked ? 1 : 0,
                        fan_locker_id: !ridicareActiva() && fanboxToggle instanceof HTMLInputElement && fanboxToggle.checked
                            && fanboxSelect instanceof HTMLSelectElement

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
                        // acolo, nu abia după ce se alege lockerul.
                        livrare_fanbox: fanboxToggle instanceof HTMLInputElement && fanboxToggle.checked ? 1 : 0,
                        fan_locker_id: fanboxToggle instanceof HTMLInputElement && fanboxToggle.checked
                            && fanboxSelect instanceof HTMLSelectElement

HTML_CMP,
    ],
    [
        'cale' => '/checkout',
        'din' => '807058e, c47f270',
        'ce' => 'checkout: sincronizarea de la încărcare',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
        }
        sincronizeazaRidicare();

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
        }
        sincronizeazaFanbox();

HTML_CMP,
    ],
    [
        'cale' => $produse,
        'din' => '1ca7148',
        'ce' => 'pagina produsului: scriptul bulinei cu reducerea',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'

    // Bulina de reducere lângă prețul din template (pagina cu template-ul
    // produsului își desenează singură prețul, deci o punem din JS).
    const injectTemplateDiscountBubble = (scope = document) => {
        const text = "";
        if (text === '') {
            return;
        }
        scope.querySelectorAll('.product-template-render').forEach((root) => {
            if (!(root instanceof HTMLElement) || root.querySelector('.pdp-discount-bubble')) {
                return;
            }
            const pret = root.querySelector('.pdp-price, .product-price, [data-product-price], .price');
            if (!(pret instanceof HTMLElement)) {
                return;
            }
            const bulina = document.createElement('span');
            bulina.className = 'pdp-discount-bubble';
            bulina.textContent = text;
            pret.insertAdjacentElement('afterend', bulina);
        });
    };

    const injectTemplateOldPrice = (scope = document) => {

HTML_CMP,
        'vechi' => <<<'HTML_CMP'

    const injectTemplateOldPrice = (scope = document) => {

HTML_CMP,
    ],
    [
        'cale' => $produse,
        'din' => '1ca7148',
        'ce' => 'pagina produsului: apelul scriptului bulinei la încărcare',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
    injectTemplateOldPrice(document);
    injectTemplateDiscountBubble(document);
    if (typeof window.MutationObserver === 'function') {

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
    injectTemplateOldPrice(document);
    if (typeof window.MutationObserver === 'function') {

HTML_CMP,
    ],
    [
        'cale' => $produse,
        'din' => '1ca7148',
        'ce' => 'pagina produsului: apelul scriptului bulinei la schimbările paginii',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
            injectTemplateOldPrice(document);
            injectTemplateDiscountBubble(document);
        });

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
            injectTemplateOldPrice(document);
        });

HTML_CMP,
    ],
    [
        'cale' => '/produs/ceai-de-musetel',
        'din' => '1ca7148',
        'ce' => 'pagina produsului fără șablon: spațiile blocului bulinei de lângă preț (fără reducere)',
        'ori' => 1,
        'nou' => <<<'HTML_CMP'
                        <span class="price-current">13.90 lei</span>
                    </p>

HTML_CMP,
        'vechi' => <<<'HTML_CMP'
                        <span class="price-current">13.90 lei</span>
        </p>

HTML_CMP,
    ],
];
