
        /* Bulina de chat vine cu un z-index pe care nu-l putem depăși cinstit
           din CSS, așa că o stingem prin API-ul lor cât ține ceva deschis peste
           ea — sertarul coșului sau meniul de pe telefon.

           Ținem motivele într-o listă, nu un simplu „ascuns/nu": dacă închizi
           meniul cât coșul e încă deschis, bulina nu trebuie să reapară peste
           coș. Și o punem la loc doar dacă noi am stins-o, ca să nu reaprindem
           un widget stins de altcineva. */
        window.bvChatWidget = (function () {
            var motive = [];
            var stinsDeNoi = false;
            var timer = null;
            var incercari = 0;

            function api() {
                var a = window.Tawk_API;
                return (a && typeof a.hideWidget === "function" && typeof a.showWidget === "function") ? a : null;
            }

            function opreste() {
                if (timer) { clearTimeout(timer); timer = null; }
                incercari = 0;
            }

            function aplica() {
                var a = api();

                if (motive.length === 0) {
                    opreste();
                    if (a && stinsDeNoi) {
                        try { a.showWidget(); } catch (e) { /* widgetul a dispărut între timp */ }
                    }
                    stinsDeNoi = false;
                    return;
                }

                if (a) {
                    opreste();
                    try { a.hideWidget(); stinsDeNoi = true; } catch (e) { /* încă nu e gata */ }
                    return;
                }

                // Widgetul lor se încarcă asincron. Dacă sertarul s-a deschis
                // înaintea lui, mai încercăm câteva secunde, apoi renunțăm —
                // mai bine o bulină în plus decât un cronometru în gol.
                if (incercari >= 20) { opreste(); return; }
                incercari += 1;
                timer = setTimeout(function () { timer = null; aplica(); }, 250);
            }

            return {
                ascunde: function (motiv) {
                    if (motive.indexOf(motiv) === -1) { motive.push(motiv); }
                    aplica();
                },
                arata: function (motiv) {
                    var i = motive.indexOf(motiv);
                    if (i !== -1) { motive.splice(i, 1); }
                    aplica();
                }
            };
        })();
    