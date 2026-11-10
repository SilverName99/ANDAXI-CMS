/*
 * Admin → Comenzi, fereastra comenzii (pluginul link_plata): linkurile de plată ale comenzii, cu starea fiecăruia
 * („trimis”, „plătit”, „anulat”, „expirat”), butonul „Trimite link de plată” pentru TOATĂ comanda când plata cu
 * cardul a eșuat sau n-a fost terminată, și nota „Plătită de două ori”. Datele vin de la
 * GET /admin/orders/{id}/payment-link; trimiterea e POST pe aceeași adresă, cu tip=integrala și jetonul anti-CSRF
 * (window.LP_CSRF). Serverul decide ce se poate (PaymentLink::plataIntegrala); aici doar se arată. În lista comenzilor, rândurile
 * comenzilor cu cardul cu plata eșuată sau neterminată (window.LP_DE_TRIMIS) primesc butonul „Trimite din nou link de plată”.
 */
(function () {
    'use strict';

    function esc(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function lei(suma) {
        const n = Number(suma) || 0;
        return n.toFixed(2).replace('.', ',') + ' lei';
    }

    const CULORI_STARE = {
        'trimis': '#92400e',
        'plătit': '#15803d',
        'anulat': '#64748b',
        'expirat': '#64748b'
    };

    function tabelLinkuri(linkuri) {
        if (!Array.isArray(linkuri) || linkuri.length === 0) {
            return '';
        }
        const randuri = linkuri.map(function (l) {
            const culoare = CULORI_STARE[l.stare] || '#334155';
            const cand = l.stare === 'plătit' && l.platit_la ? l.platit_la : l.creat_la;
            const copiaza = l.url
                ? ' <button type="button" data-lp-copiaza="' + esc(l.url) + '" style="padding:1px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;cursor:pointer;font-size:11px;">Copiază linkul</button>'
                : '';
            return '<tr>'
                + '<td style="padding:4px 6px;">' + esc(l.referinta) + '</td>'
                + '<td style="padding:4px 6px;white-space:nowrap;">' + esc(lei(l.suma)) + '</td>'
                + '<td style="padding:4px 6px;">' + (l.tip === 'integrala' ? 'toată comanda' : 'diferență') + '</td>'
                + '<td style="padding:4px 6px;color:' + culoare + ';font-weight:700;">' + esc(l.stare) + copiaza + '</td>'
                + '<td style="padding:4px 6px;">' + esc(l.procesator) + '</td>'
                + '<td style="padding:4px 6px;color:#64748b;white-space:nowrap;">' + esc(cand) + '</td>'
                + '</tr>';
        }).join('');
        return '<div style="margin-top:8px;overflow-x:auto;">'
            + '<p style="margin:0 0 4px;font-size:12px;color:#475569;font-weight:700;">Linkuri de plată trimise</p>'
            + '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
            + '<thead><tr style="text-align:left;color:#64748b;"><th style="padding:4px 6px;">Link</th><th style="padding:4px 6px;">Suma</th>'
            + '<th style="padding:4px 6px;">Pentru</th><th style="padding:4px 6px;">Stare</th><th style="padding:4px 6px;">Procesator</th>'
            + '<th style="padding:4px 6px;">Data</th></tr></thead><tbody>' + randuri + '</tbody></table></div>';
    }

    function randeaza(orderId, date) {
        const zona = document.getElementById('lp-zona-' + orderId);
        if (!zona || !date || !date.ok) {
            return;
        }
        let html = '';
        if (date.platita_de_doua_ori) {
            html += '<div role="alert" style="margin:0 0 8px;padding:10px 12px;background:#fef2f2;border:1px solid #fca5a5;border-left:4px solid #dc2626;border-radius:6px;color:#7f1d1d;font-size:13px;">'
                + '<strong>Plătită de două ori.</strong> ' + esc(String(date.nota || '').replace(/^Plătită de două ori[:?]?\s*/, '')) + '</div>';
        }
        const integrala = date.integrala || {};
        const linkuri = Array.isArray(date.linkuri) ? date.linkuri : [];
        if (integrala.arata) {
            const areTrimis = linkuri.some(function (l) { return l.tip === 'integrala' && l.stare === 'trimis'; });
            html += '<div style="margin:0 0 8px;padding:10px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;">'
                + '<p style="margin:0 0 8px;color:#9a3412;font-size:13px;">Plata cu cardul a comenzii nu a fost finalizată. '
                + 'Poți trimite clientului un link de plată pentru toată comanda; plătit, comanda devine plătită ca după checkout.</p>';
            if (integrala.poate) {
                html += '<button type="button" data-lp-trimite="' + orderId + '" style="padding:6px 12px;background:#1a7a5e;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:13px;">'
                    + (areTrimis ? 'Trimite din nou link de plată' : 'Trimite link de plată') + ' (' + esc(lei(integrala.suma)) + ')</button>';
            } else if (integrala.motiv) {
                html += '<p style="margin:0;color:#7c2d12;font-size:12px;">Linkul nu se poate trimite acum: ' + esc(integrala.motiv) + '</p>';
            }
            html += '<span id="lp-status-' + orderId + '" role="status" style="font-size:13px;margin-left:8px;"></span></div>';
        }
        html += tabelLinkuri(linkuri);
        zona.innerHTML = html;
        zona.hidden = html === '';
    }

    function incarca(orderId) {
        return fetch('/admin/orders/' + orderId + '/payment-link', {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (date) { randeaza(orderId, date); })
            .catch(function () { /* fără listă: restul ferestrei merge */ });
    }

    /** Zona din fereastra comenzii (o cheamă șablonul ferestrei din views/admin/orders.php). */
    window.lpZonaLinkuri = function (order) {
        const orderId = Number(order && order.id) || 0;
        if (orderId <= 0) {
            return '';
        }
        setTimeout(function () { incarca(orderId); }, 0);
        return '<div id="lp-zona-' + orderId + '" data-lp-zona hidden style="margin:8px 0 0;"></div>';
    };

    function trimite(orderId, buton) {
        const dinLista = buton && buton.hasAttribute('data-lp-din-lista') && buton.closest('.order-actions');
        const status = dinLista ? dinLista.querySelector('[data-lp-status-rand]') : document.getElementById('lp-status-' + orderId);
        if (status) { status.style.color = '#6b7280'; status.textContent = 'Se trimite...'; }
        if (buton) { buton.disabled = true; }
        const date = new URLSearchParams();
        date.append('tip', 'integrala');
        date.append('_csrf', window.LP_CSRF || '');
        fetch('/admin/orders/' + orderId + '/payment-link', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.LP_CSRF || '', 'Accept': 'application/json'},
            body: date.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (raspuns) {
                if (raspuns.ok && dinLista) {
                    buton.disabled = false;
                    if (status) {
                        status.style.color = '#16a34a';
                        status.textContent = (raspuns.refolosit ? 'Retrimis' : 'Trimis') + ' ✓';
                        status.title = 'Pe ' + (raspuns.email || 'emailul clientului') + (raspuns.procesator ? ', plată prin ' + raspuns.procesator : '');
                    }
                    return;
                }
                if (raspuns.ok) {
                    incarca(orderId).then(function () {
                        const s = document.getElementById('lp-status-' + orderId);
                        if (s) {
                            s.style.color = '#16a34a';
                            s.textContent = (raspuns.refolosit ? 'Link retrimis' : 'Link trimis') + ' pe ' + (raspuns.email || 'emailul clientului')
                                + (raspuns.procesator ? ' (plată prin ' + raspuns.procesator + ')' : '') + ' ✓';
                        }
                    });
                    return;
                }
                if (status) { status.style.color = '#dc2626'; status.textContent = raspuns.error || 'Eroare'; }
                if (buton) { buton.disabled = false; }
                if (raspuns.url) { window.prompt('Trimite manual acest link clientului:', raspuns.url); }
            })
            .catch(function () {
                if (status) { status.style.color = '#dc2626'; status.textContent = 'Eroare server'; }
                if (buton) { buton.disabled = false; }
            });
    }

    /** Lista comenzilor: butonul „Trimite din nou link de plată” pe rândurile comenzilor cu plata eșuată. */
    function butoaneInLista() {
        const deTrimis = window.LP_DE_TRIMIS && typeof window.LP_DE_TRIMIS === 'object' ? window.LP_DE_TRIMIS : {};
        Object.keys(deTrimis).forEach(function (id) {
            const reper = document.querySelector('.order-actions [data-order-id="' + CSS.escape(id) + '"]');
            const actiuni = reper ? reper.closest('.order-actions') : null;
            if (!actiuni || actiuni.querySelector('[data-lp-trimite]')) {
                return;
            }
            const buton = document.createElement('button');
            buton.type = 'button';
            buton.className = 'order-action-btn';
            buton.setAttribute('data-lp-trimite', id);
            buton.setAttribute('data-lp-din-lista', '');
            buton.title = 'Trimite din nou link de plată (' + lei(deTrimis[id]) + '): plata cu cardul nu a fost finalizată';
            buton.setAttribute('aria-label', buton.title);
            buton.textContent = '💳';
            const stare = document.createElement('small');
            stare.setAttribute('data-lp-status-rand', '');
            stare.setAttribute('role', 'status');
            actiuni.appendChild(buton);
            actiuni.appendChild(stare);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', butoaneInLista);
    } else {
        butoaneInLista();
    }

    document.addEventListener('click', function (ev) {
        const tinta = ev.target instanceof Element ? ev.target : null;
        if (!tinta) {
            return;
        }
        const buton = tinta.closest('[data-lp-trimite]');
        if (buton) {
            ev.preventDefault();
            const orderId = Number(buton.getAttribute('data-lp-trimite')) || 0;
            if (orderId > 0 && window.confirm('Trimiți clientului un link de plată pentru toată comanda?')) {
                trimite(orderId, buton);
            }
            return;
        }
        const copiaza = tinta.closest('[data-lp-copiaza]');
        if (copiaza) {
            ev.preventDefault();
            const url = copiaza.getAttribute('data-lp-copiaza') || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () { copiaza.textContent = 'Copiat ✓'; }, function () { window.prompt('Linkul de plată:', url); });
            } else {
                window.prompt('Linkul de plată:', url);
            }
        }
    });
}());
