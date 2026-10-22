// Tema demo (tools/dev/site-demo-tema): scriptul temei (tema.json → js_final), încărcat cu defer, după Bootstrap.
// Testul temei verifică doar că a rulat: pune data-tema-demo="da" pe <html>.
(function () {
    'use strict';
    document.documentElement.setAttribute('data-tema-demo', 'da');
})();
