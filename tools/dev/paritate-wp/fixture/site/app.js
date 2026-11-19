// Scriptul inventat al fixture-ului: mutațiile de la încărcare pe care harness-ul trebuie să le vadă la fel pe ambele servere.
(function () {
    'use strict';
    var tarziu = document.getElementById('tarziu-css');
    if (tarziu) { tarziu.rel = 'stylesheet'; }
    document.documentElement.className = 'js';
    document.body.classList.add('incarcat');
    var carduri = document.querySelector('.carduri');
    if (carduri) {
        var nou = document.createElement('div');
        nou.className = 'card card-adaugat';
        nou.textContent = 'Adăugat din script';
        carduri.appendChild(nou);
    }
    // Ceasul: conținut care se schimbă de la o încărcare la alta (mascat în paritate.json).
    var ceas = document.querySelector('.ceas');
    if (ceas) { ceas.textContent = new Date().toISOString(); ceas.style.color = '#' + (Math.random() * 0xffffff | 0).toString(16).padStart(6, '0'); }
    /*PLANTAT-JS*/
}());
