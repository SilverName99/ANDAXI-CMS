
    (function () {
        'use strict';

        var buton = document.querySelector('.sus');
        if (!buton) {
            return;
        }

        // Pragul e o înălțime de ecran, nu un număr fix de pixeli: pe un telefon 600px înseamnă o pagină întreagă
        // derulată, pe un monitor mare abia jumătate de ecran.
        function potriveste() {
            var arata = window.scrollY > window.innerHeight;
            buton.hidden = !arata;
        }

        potriveste();
        window.addEventListener('scroll', potriveste, { passive: true });
        window.addEventListener('resize', potriveste);

        // Derularea lină o face „scroll-behavior” din CSS, care ascultă și de „prefers-reduced-motion”. Aici rămâne doar
        // mutarea focusului: o ancoră duce privirea sus, dar focusul îl duce numai dacă ținta îl poate primi.
        buton.addEventListener('click', function () {
            var inceput = document.getElementById('inceput-pagina');
            if (inceput) {
                window.setTimeout(function () {
                    inceput.setAttribute('tabindex', '-1');
                    inceput.focus({ preventScroll: true });
                }, 0);
            }
        });
    })();
