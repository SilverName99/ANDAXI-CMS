/**
 * Runtime-ul compat, divi4/v1: conținutul și atributele Divi pe dispozitiv și la hover (`data-et-multi-view`; adoptarea unui site
 * WordPress, felia 2; decizia D1: reprodus). Scris clean-room, din specificația de comportament `multi-view.md` și din DOM-ul de
 * după scripturi al referinței (inventarul mutațiilor, rularea de paritate din 23.11), fără niciun cod de furnizor.
 *
 * Fiecare element cu `data-et-multi-view` poartă JSON-ul `{"schema": {"content" | "attrs": {"desktop", "tablet", "phone",
 * "hover"}}, "slug", "hover_selector"?}` (în captură; politica HTML-ului capturat i-a validat deja variantele HTML și adresele).
 * Modurile, ca media queries-urile CSS-ului capturat: desktop ≥ 981 px, tablet 768–980 px, phone ≤ 767 px; o valoare lipsă pentru
 * phone se ia de la tablet, apoi de la desktop.
 *
 * La pornire (momentul 'dom', doar cu ax-date valid), pentru fiecare element, în ordinea documentului:
 *  1. ținta de hover primește `et_multi_view__hover_selector`: elementele prinse de `hover_selector` care sunt elementul însuși
 *     sau un strămoș al lui (logo-ul meniului: imaginea însăși); altfel cel mai apropiat strămoș cu clasa `slug`
 *     (`div.et_pb_toggle`, `div.et_pb_image`), altfel elementul. La toate lățimile;
 *  2. pe desktop nu se schimbă nimic (valoarea desktop e deja în pagină). Pe tablet/phone, dacă modul are o valoare proprie
 *     (`tablet`/`phone`): conținutul (`content`) se înlocuiește cu varianta și elementul primește `et_multi_view_swapped` (CSS-ul
 *     capturat ascunde până atunci elementele `data-et-multi-view-load-*-hidden`). O variantă refuzată de cititor (mai jos) lasă
 *     conținutul original al paginii (cel verificat de politica serverului), dar elementul primește tot `et_multi_view_swapped`,
 *     deci rămâne afișat. Atributele (`attrs`) se pun pe o copie a imaginii ascunse `img.et_multi_view_hidden_image`, care o
 *     înlocuiește în DOM (ca pe live: nod scos, nod adăugat), fără `et_multi_view_hidden_image` și cu `et_multi_view_swapped
 *     et_multi_view_image__loaded` la sfârșit; o altă imagine își schimbă atributele pe loc, ca la hover. O valoare de imagine fără
 *     `srcset`/`sizes` le pune goale (observat pe live). Atributele de imagine (src, srcset, sizes, alt, title, width, height) se
 *     pun doar pe un `IMG`; pe alt element, doar clasele.
 * Hover (valoarea `hover`, de exemplu imaginile de pe Obiective): `mouseenter` pe țintă pune atributele valorii hover pe imagine
 * (`et_multi_view_image__loading` + `et_multi_view_swapped`, deci ascunsă de CSS), iar la `load`-ul imaginii `__loading` devine
 * `__loaded` (CSS-ul capturat o estompează în 0,5 s); `mouseleave` întoarce, la fel, valoarea modului curent. Ultima stare
 * câștigă. Resize peste un prag: valoarea modului nou; înapoi într-un mod fără valoare proprie (desktop), ce face live-ul
 * (observat offline pe Camere, 1366 → 820 → 1366 → 390 → 1366): conținutul desktop — aici nodurile originale — cu
 * `et_multi_view_swapped` păstrat; imaginea ascunsă a logo-ului — aici nodul original — cu `et_multi_view_hidden_image`, src gol,
 * plus `et_multi_view_swapped et_multi_view_image__loading` (ascunsă, fără cutie goală); o imagine schimbată pe loc primește
 * valoarea desktop. O nouă copie a logo-ului pe tabletă/telefon nu ia `__loading`.
 *
 * Variantele HTML nu trec prin parserul browserului (fără innerHTML/DOMParser): le citește `citesteFragment`, un cititor strict
 * pe o listă închisă de etichete și atribute, care construiește nodurile cu createElement/createTextNode într-un document inert
 * (`document.implementation.createHTMLDocument`, fără context de navigare: nicio imagine cerută) și le adoptă în pagină abia după
 * ce a citit totul fără refuz. Refuză tot ce ar face parserul HTML să construiască alt arbore (închideri implicite — inclusiv
 * dd/dt, li și p —, tbody implicit, text în tabel, etichete necunoscute, `<?…>`, `<!…>` în afara comentariilor, închideri cu
 * spații neobișnuite, NUL, peste 64 de niveluri) și tot ce iese din politică: atribute `on*`, adrese nesigure, `rel` cu
 * `opener`, `id`/`name` care încep cu `ax-`, `data-ax-*`, un `data-et-multi-view` imbricat, stiluri care nu trec declarație cu
 * declarație (url() doar spre originea paginii sau imagini `data:`, fără `position: fixed`, fără expression/behavior/
 * -moz-binding/image-set, fără escape-uri și comentarii CSS), referințe de caractere necunoscute (tabelul: toate entitățile
 * HTML 4, Latin-1 întreg, Latin Extended-A cu literele românești, punctuația generală; o referință fără „;” care ar putea fi
 * una veche e refuzată, altfel rămâne text, ca în parser). Un refuz lasă un rând în diagnostic. Adresele de imagine doar prin
 * `axCompat.adresaSigura`.
 *
 * `axCompat.multiView.aplica()` nu dublează nimic (un element deja văzut doar își reevaluează modul); `citesteFragment` e expus
 * pentru teste. O a doua includere nu face nimic.
 */
(function (w, d) {
    'use strict';

    var ax = w.axCompat;
    if (!ax || !ax.versiune || ax.multiView) {
        return;
    }

    var are = Object.prototype.hasOwnProperty;
    var SLUG = /^et_pb_[a-z0-9_]{1,60}$/;
    var SELECTOR_SIGUR = /^[A-Za-z0-9_#.\->\s]{1,200}$/;
    var MODURI = ['desktop', 'tablet', 'phone'];
    var ATR_IMAGINE = ['src', 'srcset', 'sizes', 'alt', 'title', 'width', 'height'];
    var ADANCIME = 64;

    function lista(text) {
        var o = {};
        text.split(' ').forEach(function (t) {
            o[t] = 1;
        });
        return o;
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Cititorul fragmentelor HTML (lista închisă)
    // ---------------------------------------------------------------------------------------------------------------------
    var VIDE = lista('br hr img col wbr');
    var ETICHETE = lista('p br hr h1 h2 h3 h4 h5 h6 strong b em i u s del ins sub sup small mark abbr time span div pre ul ol li dl dt '
        + 'dd blockquote figure figcaption table caption colgroup col thead tbody tfoot tr th td a img cite q code wbr');
    // Etichetele care, deschise cu un <p> deschis (în domeniul „button”), l-ar închide implicit în parserul HTML (refuzate aici).
    var INCHID_P = lista('address article aside blockquote div dl fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 header hr '
        + 'main nav ol p pre section table ul li dd dt');
    // Domeniul „button” al parserului pentru <p>, și markerii listei de formatare pentru <a>, din etichetele acceptate aici.
    var TABEL_BARIERA = ['table', 'td', 'th', 'caption'];
    // Elementele „speciale” ale parserului (din cele acceptate aici, fără address/div/p): opresc căutarea unui li/dd/dt deschis.
    var SPECIALE = lista('blockquote caption col colgroup dd dl dt figcaption figure h1 h2 h3 h4 h5 h6 hr img li ol pre table tbody td '
        + 'tfoot th thead tr ul wbr br');
    var TITLURI = lista('h1 h2 h3 h4 h5 h6');
    var COPII_TABEL = { table: { caption: 1, colgroup: 1, thead: 1, tbody: 1, tfoot: 1 }, thead: { tr: 1 }, tbody: { tr: 1 }, tfoot: { tr: 1 },
        tr: { td: 1, th: 1 }, colgroup: { col: 1 } };
    var IN_TABEL = lista('tr td th caption thead tbody tfoot colgroup col');
    var ATRIBUTE = lista('class style title lang dir role id name tabindex align valign width height border cellpadding cellspacing '
        + 'colspan rowspan scope span start type reversed value datetime alt loading decoding fetchpriority sizes target rel href src srcset');
    // Referințele de caractere cu nume („nume:cod hex”; un subset al tabelului HTML: HTML 4 întreg, Latin-1, Latin Extended-A,
    // punctuația generală) și cele vechi, recunoscute de parser și fără „;”.
    var ENTITATI = {};
    ('AElig:c6 AMP:26 Aacute:c1 Abreve:102 Acirc:c2 Agrave:c0 Alpha:391 Amacr:100 Aogon:104 ApplyFunction:2061 Aring:c5 '
        + 'Atilde:c3 Auml:c4 Beta:392 COPY:a9 Cacute:106 Ccaron:10c Ccedil:c7 Ccirc:108 Cdot:10a Cedilla:b8 CenterDot:b7 Chi:3a7 '
        + 'CloseCurlyDoubleQuote:201d CloseCurlyQuote:2019 Dagger:2021 Dcaron:10e Delta:394 DiacriticalAcute:b4 Dot:a8 '
        + 'DoubleDot:a8 Dstrok:110 ENG:14a ETH:d0 Eacute:c9 Ecaron:11a Ecirc:ca Edot:116 Egrave:c8 Emacr:112 Eogon:118 '
        + 'Epsilon:395 Eta:397 Euml:cb GT:3e Gamma:393 Gbreve:11e Gcedil:122 Gcirc:11c Gdot:120 Hcirc:124 Hstrok:126 IJlig:132 '
        + 'Iacute:cd Icirc:ce Idot:130 Igrave:cc Imacr:12a InvisibleComma:2063 InvisibleTimes:2062 Iogon:12e Iota:399 Itilde:128 '
        + 'Iuml:cf Jcirc:134 Kappa:39a Kcedil:136 LT:3c Lacute:139 Lambda:39b Lcaron:13d Lcedil:13b Lmidot:13f Lstrok:141 '
        + 'MediumSpace:205f Mu:39c Nacute:143 Ncaron:147 Ncedil:145 NegativeMediumSpace:200b NegativeThickSpace:200b '
        + 'NegativeThinSpace:200b NegativeVeryThinSpace:200b NoBreak:2060 NonBreakingSpace:a0 Ntilde:d1 Nu:39d OElig:152 '
        + 'Oacute:d3 Ocirc:d4 Odblac:150 Ograve:d2 Omacr:14c Omega:3a9 Omicron:39f OpenCurlyDoubleQuote:201c OpenCurlyQuote:2018 '
        + 'Oslash:d8 Otilde:d5 Ouml:d6 OverBar:203e Phi:3a6 Pi:3a0 PlusMinus:b1 Prime:2033 Psi:3a8 QUOT:22 REG:ae Racute:154 '
        + 'Rcaron:158 Rcedil:156 Rho:3a1 Sacute:15a Scaron:160 Scedil:15e Scirc:15c Sigma:3a3 THORN:de Tau:3a4 Tcaron:164 '
        + 'Tcedil:162 Theta:398 ThinSpace:2009 Tstrok:166 Uacute:da Ubreve:16c Ucirc:db Udblac:170 Ugrave:d9 Umacr:16a Uogon:172 '
        + 'Upsilon:3a5 Uring:16e Utilde:168 Uuml:dc Verbar:2016 Vert:2016 VeryThinSpace:200a Wcirc:174 Xi:39e Yacute:dd Ycirc:176 '
        + 'Yuml:178 Zacute:179 Zcaron:17d Zdot:17b ZeroWidthSpace:200b Zeta:396 aacute:e1 abreve:103 acirc:e2 acute:b4 aelig:e6 '
        + 'af:2061 agrave:e0 alefsym:2135 alpha:3b1 amacr:101 amp:26 and:2227 ang:2220 angst:c5 aogon:105 apos:27 aring:e5 '
        + 'asymp:2248 atilde:e3 auml:e4 backprime:2035 bdquo:201e beta:3b2 bprime:2035 brvbar:a6 bsemi:204f bull:2022 bullet:2022 '
        + 'cacute:107 cap:2229 caret:2041 ccaron:10d ccedil:e7 ccirc:109 cdot:10b cedil:b8 cent:a2 centerdot:b7 chi:3c7 circ:2c6 '
        + 'circledR:ae clubs:2663 cong:2245 copy:a9 crarr:21b5 cup:222a curren:a4 dArr:21d3 dagger:2020 darr:2193 dash:2010 '
        + 'dcaron:10f ddagger:2021 deg:b0 delta:3b4 diams:2666 die:a8 div:f7 divide:f7 dstrok:111 eacute:e9 ecaron:11b ecirc:ea '
        + 'edot:117 egrave:e8 emacr:113 empty:2205 emsp:2003 emsp13:2004 emsp14:2005 eng:14b ensp:2002 eogon:119 epsilon:3b5 '
        + 'equiv:2261 eta:3b7 eth:f0 euml:eb euro:20ac exist:2203 fnof:192 forall:2200 frac12:bd frac14:bc frac34:be frasl:2044 '
        + 'gamma:3b3 gbreve:11f gcirc:11d gdot:121 ge:2265 gt:3e hArr:21d4 hairsp:200a half:bd harr:2194 hcirc:125 hearts:2665 '
        + 'hellip:2026 horbar:2015 hstrok:127 hybull:2043 hyphen:2010 iacute:ed ic:2063 icirc:ee iexcl:a1 igrave:ec ijlig:133 '
        + 'imacr:12b image:2111 imath:131 infin:221e inodot:131 int:222b iogon:12f iota:3b9 iquest:bf isin:2208 it:2062 '
        + 'itilde:129 iuml:ef jcirc:135 kappa:3ba kcedil:137 kgreen:138 lArr:21d0 lacute:13a lambda:3bb lang:27e8 laquo:ab '
        + 'larr:2190 lcaron:13e lcedil:13c lceil:2308 ldquo:201c ldquor:201e le:2264 lfloor:230a lmidot:140 lowast:2217 loz:25ca '
        + 'lrm:200e lsaquo:2039 lsquo:2018 lsquor:201a lstrok:142 lt:3c macr:af mdash:2014 micro:b5 middot:b7 minus:2212 '
        + 'mldr:2026 mu:3bc nabla:2207 nacute:144 napos:149 nbsp:a0 ncaron:148 ncedil:146 ndash:2013 ne:2260 ni:220b nldr:2025 '
        + 'not:ac notin:2209 nsub:2284 ntilde:f1 nu:3bd numsp:2007 oacute:f3 ocirc:f4 odblac:151 oelig:153 ograve:f2 oline:203e '
        + 'omacr:14d omega:3c9 omicron:3bf oplus:2295 or:2228 ordf:aa ordm:ba oslash:f8 otilde:f5 otimes:2297 ouml:f6 para:b6 '
        + 'part:2202 permil:2030 perp:22a5 pertenk:2031 phi:3c6 pi:3c0 piv:3d6 plusmn:b1 pm:b1 pound:a3 prime:2032 prod:220f '
        + 'prop:221d psi:3c8 puncsp:2008 qprime:2057 quot:22 rArr:21d2 racute:155 radic:221a rang:27e9 raquo:bb rarr:2192 '
        + 'rcaron:159 rcedil:157 rceil:2309 rdquo:201d rdquor:201d real:211c reg:ae rfloor:230b rho:3c1 rlm:200f rsaquo:203a '
        + 'rsquo:2019 rsquor:2019 sacute:15b sbquo:201a scaron:161 scedil:15f scirc:15d sdot:22c5 sect:a7 shy:ad sigma:3c3 '
        + 'sigmaf:3c2 sim:223c spades:2660 strns:af sub:2282 sube:2286 sum:2211 sup:2283 sup1:b9 sup2:b2 sup3:b3 supe:2287 '
        + 'szlig:df tau:3c4 tcaron:165 tcedil:163 there4:2234 theta:3b8 thetasym:3d1 thinsp:2009 thorn:fe tilde:2dc times:d7 '
        + 'tprime:2034 trade:2122 tstrok:167 uArr:21d1 uacute:fa uarr:2191 ubreve:16d ucirc:fb udblac:171 ugrave:f9 umacr:16b '
        + 'uml:a8 uogon:173 upsih:3d2 upsilon:3c5 uring:16f utilde:169 uuml:fc wcirc:175 weierp:2118 xi:3be yacute:fd ycirc:177 '
        + 'yen:a5 yuml:ff zacute:17a zcaron:17e zdot:17c zeta:3b6 zwj:200d zwnj:200c').split(' ').forEach(function (p) {
        var k = p.indexOf(':');
        ENTITATI[p.slice(0, k)] = String.fromCodePoint(parseInt(p.slice(k + 1), 16));
    });
    var VECHI = ('AElig AMP Aacute Acirc Agrave Aring Atilde Auml COPY Ccedil ETH Eacute Ecirc Egrave Euml GT Iacute Icirc Igrave Iuml '
        + 'LT Ntilde Oacute Ocirc Ograve Oslash Otilde Ouml QUOT REG THORN Uacute Ucirc Ugrave Uuml Yacute aacute acirc acute '
        + 'aelig agrave amp aring atilde auml brvbar ccedil cedil cent copy curren deg divide eacute ecirc egrave eth euml frac12 '
        + 'frac14 frac34 gt iacute icirc iexcl igrave iquest iuml laquo lt macr micro middot nbsp not ntilde oacute ocirc ograve '
        + 'ordf ordm oslash otilde ouml para plusmn pound quot raquo reg sect shy sup1 sup2 sup3 szlig thorn times uacute ucirc '
        + 'ugrave uml uuml yacute yen yuml').split(' ');
    var NUL = String.fromCharCode(0);

    function refuz(motiv) {
        var e = new Error(motiv);
        e.refuzFragment = true;
        throw e;
    }

    /** O referință fără „;”: refuzată dacă parserul ar putea-o citi ca pe una veche (un nume vechi e prefixul ei), altfel text. */
    function faraFinal(nume, tot) {
        for (var k = 0; k < VECHI.length; k++) {
            if (nume.indexOf(VECHI[k]) === 0) {
                refuz('referință de caracter fără „;”: &' + nume);
            }
        }
        return tot;
    }

    /** Referințele de caractere dintr-un text sau dintr-o valoare de atribut; una necunoscută (cu „;”) e refuzată. */
    function decodeaza(s) {
        return s.replace(/&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});?/g, function (tot, ref) {
            var areFinal = tot.charAt(tot.length - 1) === ';';
            if (ref.charAt(0) === '#') {
                if (!areFinal) {
                    refuz('referință numerică fără „;”');
                }
                var n = ref.charAt(1) === 'x' || ref.charAt(1) === 'X' ? parseInt(ref.slice(2), 16) : parseInt(ref.slice(1), 10);
                if (!(n > 0 && n <= 0x10FFFF) || (n >= 0xD800 && n <= 0xDFFF) || (n >= 0x80 && n <= 0x9F)) {
                    refuz('referință numerică în afara listei');
                }
                return String.fromCodePoint(n);
            }
            if (!areFinal) {
                return faraFinal(ref, tot);
            }
            if (!are.call(ENTITATI, ref)) {
                refuz('referință de caracter necunoscută: &' + ref);
            }
            return ENTITATI[ref];
        });
    }

    function adresaBuna(nume, v) {
        if (nume === 'href') {
            return /^#[A-Za-z0-9_:.-]*$/.test(v) || /^(mailto|tel):[^\s<>"']+$/i.test(v) || ax.adresaSigura(v);
        }
        if (nume === 'src') {
            return ax.adresaSigura(v);
        }
        if (nume === 'srcset') {
            var parti = v.split(',');
            for (var i = 0; i < parti.length; i++) {
                var u = parti[i].trim().split(/\s+/)[0];
                if (u === '' || !ax.adresaSigura(u)) {
                    return false;
                }
            }
            return true;
        }
        return true;
    }

    /** O adresă din url(): o imagine `data:` sau una care rămâne pe originea paginii. */
    function urlStilBun(u) {
        if (/^data:image\/[a-z0-9.+-]+[;,]/i.test(u)) {
            return true;
        }
        if (u === '' || /^[a-z][a-z0-9+.-]*:/i.test(u) && !/^https?:/i.test(u)) {
            return false;
        }
        try {
            var a = new URL(u, d.baseURI || w.location.href);
            return a.origin === w.location.origin && /^https?:$/.test(a.protocol);
        } catch (e) {
            return false;
        }
    }

    /** Împarte un `style` în declarații (la „;” din afara ghilimelelor și parantezelor); null dacă nu e echilibrat. */
    function declaratii(css) {
        var rez = [];
        var ghilimea = '';
        var adanc = 0;
        var inceput = 0;
        for (var k = 0; k < css.length; k++) {
            var c = css.charAt(k);
            if (ghilimea) {
                if (c === ghilimea) {
                    ghilimea = '';
                } else if (c === '\n') {
                    return null;
                }
            } else if (c === '"' || c === '\'') {
                ghilimea = c;
            } else if (c === '(') {
                adanc++;
            } else if (c === ')') {
                if (--adanc < 0) {
                    return null;
                }
            } else if (c === ';' && adanc === 0) {
                rez.push(css.slice(inceput, k));
                inceput = k + 1;
            }
        }
        if (ghilimea || adanc !== 0) {
            return null;
        }
        rez.push(css.slice(inceput));
        return rez;
    }

    var POZITII = /^(static|relative|absolute|sticky|inherit|initial|unset|revert)([\t\n\f\r ]*![\t\n\f\r ]*important)?$/;
    var FUNCTII_IMAGINE = /(^|[^a-z0-9_-])(-webkit-)?(image-set|image|cross-fade|element|paint|src)[\t\n\f\r ]*\(/;

    /** Atributul `style`, declarație cu declarație, după politica serverului, mai strict: url() doar spre originea paginii. */
    function stilBun(css) {
        if (/[\\<>{}@]|\/\*/.test(css)) {
            return false;
        }
        var lista = declaratii(css);
        if (lista === null) {
            return false;
        }
        for (var k = 0; k < lista.length; k++) {
            var dec = lista[k].trim();
            if (dec === '') {
                continue;
            }
            var m = /^(-{0,2}[A-Za-z][A-Za-z0-9-]*)[\t\n\f\r ]*:([\s\S]*)$/.exec(dec);
            if (!m) {
                return false;
            }
            var prop = m[1].toLowerCase();
            var val = m[2].trim().toLowerCase();
            if (/^(behaviou?r|-moz-binding)$/.test(prop) || /expression[\t\n\f\r ]*\(|javascript:|vbscript:|-moz-binding|behaviou?r[\t\n\f\r ]*:/.test(val)
                || FUNCTII_IMAGINE.test(val)) {
                return false;
            }
            if (prop === 'position' && !POZITII.test(val)) {
                return false;
            }
            var urluri = val.split(/url[\t\n\f\r ]*\(/).length - 1;
            var gasite = 0;
            var rx = /url\([\t\n\f\r ]*(?:"([^"]*)"|'([^']*)'|([^)'"\t\n\f\r ]*))[\t\n\f\r ]*\)/gi;
            var u;
            while ((u = rx.exec(m[2])) !== null) {
                gasite++;
                if (!urlStilBun((u[1] !== undefined ? u[1] : (u[2] !== undefined ? u[2] : u[3])).trim())) {
                    return false;
                }
            }
            if (gasite !== urluri) {
                return false;
            }
        }
        return true;
    }

    function atributBun(nume, v, eticheta) {
        if (/^on/.test(nume)) {
            return false;
        }
        if (!(ATRIBUTE[nume] === 1 || /^aria-[a-z]{1,30}$/.test(nume)
            || (/^data-[a-z0-9-]{1,60}$/.test(nume) && nume.indexOf('data-ax-') !== 0 && nume !== 'data-et-multi-view'))) {
            return false;
        }
        if ((nume === 'id' || nume === 'name') && (!/^[A-Za-z][A-Za-z0-9_:.-]{0,80}$/.test(v) || /^ax-/i.test(v) || /^axcompat$/i.test(v))) {
            return false;
        }
        if (nume === 'name' && eticheta !== 'a') {
            return false;
        }
        if (nume === 'style' && !stilBun(v)) {
            return false;
        }
        if (nume === 'target' && !/^_(blank|self|parent|top)$/.test(v)) {
            return false;
        }
        if (nume === 'rel' && /(^|[\t\n\f\r ])opener([\t\n\f\r ]|$)/i.test(v)) {
            return false;
        }
        if ((nume === 'tabindex' && !/^-?[0-9]{1,4}$/.test(v)) || (nume === 'loading' && !/^(lazy|eager)$/i.test(v))
            || (nume === 'decoding' && !/^(sync|async|auto)$/i.test(v)) || (nume === 'fetchpriority' && !/^(high|low|auto)$/i.test(v))) {
            return false;
        }
        return adresaBuna(nume, v);
    }

    /**
     * Citește o variantă HTML într-un DocumentFragment, cu noduri create unul câte unul într-un document inert și adoptate în
     * pagină doar la sfârșit; aruncă (refuzFragment) la orice ieșire din lista închisă sau la o construcție pe care parserul
     * HTML ar reface-o altfel.
     */
    function citesteFragment(sursa) {
        if (typeof sursa !== 'string' || sursa.length > 200000) {
            refuz('varianta nu e text');
        }
        if (sursa.indexOf(NUL) >= 0) {
            refuz('caracter NUL');
        }
        var s = sursa.replace(/\r\n?/g, '\n');
        var doc = d.implementation.createHTMLDocument('');
        var frag = doc.createDocumentFragment();
        var stiva = [{ nume: '#frag', el: frag }];
        var i = 0;
        var n = s.length;
        function curent() {
            return stiva[stiva.length - 1];
        }
        function inDomeniu(nume, barieri) {
            for (var k = stiva.length - 1; k > 0; k--) {
                if (stiva[k].nume === nume) {
                    return true;
                }
                if (barieri.indexOf(stiva[k].nume) >= 0) {
                    return false;
                }
            }
            return false;
        }
        // Un li (sau un dd/dt) deschis pe care parserul l-ar închide: căutarea se oprește la un element special (nu address/div/p).
        function articolDeschis(nume) {
            var cauta = nume === 'li' ? { li: 1 } : { dd: 1, dt: 1 };
            for (var k = stiva.length - 1; k > 0; k--) {
                if (cauta[stiva[k].nume] === 1) {
                    return true;
                }
                if (SPECIALE[stiva[k].nume] === 1) {
                    return false;
                }
            }
            return false;
        }
        function text(t) {
            if (t === '') {
                return;
            }
            var c = curent().nume;
            if (COPII_TABEL[c] && /[^\t\n\f\r ]/.test(t)) {
                refuz('text în ' + c);
            }
            curent().el.appendChild(doc.createTextNode(decodeaza(t)));
        }
        while (i < n) {
            var lt = s.indexOf('<', i);
            if (lt < 0) {
                text(s.slice(i));
                break;
            }
            var urm = s.charAt(lt + 1);
            if (urm === '?') {
                refuz('construcție <? (parserul o face comentariu)');
            }
            if (!/[A-Za-z\/!]/.test(urm)) {
                // „<” urmat de altceva decât o literă, „/”, „!” sau „?”: text, ca în parserul HTML
                text(s.slice(i, lt + 1));
                i = lt + 1;
                continue;
            }
            text(s.slice(i, lt));
            if (s.substr(lt, 4) === '<!--') {
                var sf = s.indexOf('-->', lt + 4);
                if (sf < 0 || /^>|^->/.test(s.slice(lt + 4)) || s.slice(lt + 4, sf).indexOf('--') >= 0) {
                    refuz('comentariu neobișnuit');
                }
                if (COPII_TABEL[curent().nume]) {
                    refuz('comentariu în tabel');
                }
                curent().el.appendChild(doc.createComment(s.slice(lt + 4, sf)));
                i = sf + 3;
                continue;
            }
            if (urm === '!') {
                refuz('construcție <! necunoscută');
            }
            var m;
            if (urm === '/') {
                m = /^<\/([A-Za-z][A-Za-z0-9]*)[\t\n\f ]*>/.exec(s.slice(lt, lt + 80));
                if (!m) {
                    refuz('etichetă de închidere invalidă');
                }
                var numeInchis = m[1].toLowerCase();
                if (curent().nume !== numeInchis) {
                    refuz('închiderea lui ' + numeInchis + ' nu e a elementului curent');
                }
                stiva.pop();
                i = lt + m[0].length;
                continue;
            }
            m = /^<([A-Za-z][A-Za-z0-9]*)/.exec(s.slice(lt, lt + 40));
            var nume = m[1].toLowerCase();
            if (ETICHETE[nume] !== 1) {
                refuz('etichetă în afara listei: ' + nume);
            }
            var j = lt + m[0].length;
            var atribute = [];
            var vazute = {};
            var autoInchis = false;
            for (;;) {
                var spatiu = /^[\t\n\f ]*/.exec(s.slice(j, j + 1000))[0].length;
                j += spatiu;
                if (j >= n) {
                    refuz('etichetă neterminată');
                }
                if (s.charAt(j) === '>') {
                    j++;
                    break;
                }
                if (s.substr(j, 2) === '/>') {
                    autoInchis = true;
                    j += 2;
                    break;
                }
                if (spatiu === 0 && atribute.length > 0) {
                    refuz('atribute lipite');
                }
                var ma = /^([a-zA-Z][a-zA-Z0-9-]*)(?:[\t\n\f ]*=[\t\n\f ]*(?:"([^"]*)"|'([^']*)'|([^\t\n\f "'=<>`]+)))?/.exec(s.slice(j, j + 100000));
                if (!ma) {
                    refuz('atribut invalid în ' + nume);
                }
                var na = ma[1].toLowerCase();
                var va = ma[2] !== undefined ? ma[2] : (ma[3] !== undefined ? ma[3] : (ma[4] !== undefined ? ma[4] : ''));
                j += ma[0].length;
                if (are.call(vazute, na)) {
                    continue; // ca în parserul HTML: primul câștigă
                }
                vazute[na] = 1;
                va = decodeaza(va);
                if (!atributBun(na, va, nume)) {
                    refuz('atribut refuzat: ' + na + ' pe ' + nume);
                }
                atribute.push([na, va]);
            }
            if (autoInchis && VIDE[nume] !== 1) {
                refuz('„/>” pe un element care nu e vid: ' + nume);
            }
            var par = curent().nume;
            if (COPII_TABEL[par] && COPII_TABEL[par][nume] !== 1) {
                refuz(nume + ' direct în ' + par);
            }
            if (IN_TABEL[nume] === 1 && !COPII_TABEL[par]) {
                refuz(nume + ' în afara unui tabel');
            }
            if (INCHID_P[nume] === 1 && inDomeniu('p', TABEL_BARIERA)) {
                refuz(nume + ' într-un <p> deschis');
            }
            if ((nume === 'li' || nume === 'dd' || nume === 'dt') && articolDeschis(nume)) {
                refuz(nume + ' cu un ' + (nume === 'li' ? 'li' : 'dd/dt') + ' deschis');
            }
            if ((nume === 'a' || nume === 'p') && inDomeniu(nume, TABEL_BARIERA)) {
                refuz(nume + ' în ' + nume);
            }
            if ((nume === 'tr' || nume === 'td' || nume === 'th') && inDomeniu(nume, ['table'])) {
                refuz(nume + ' în ' + nume);
            }
            if (TITLURI[nume] === 1 && TITLURI[par] === 1) {
                refuz(nume + ' direct în ' + par);
            }
            var el = doc.createElement(nume);
            for (var k = 0; k < atribute.length; k++) {
                el.setAttribute(atribute[k][0], atribute[k][1]);
            }
            curent().el.appendChild(el);
            i = j;
            if (VIDE[nume] !== 1) {
                if (stiva.length > ADANCIME) {
                    refuz('peste ' + ADANCIME + ' de niveluri');
                }
                stiva.push({ nume: nume, el: el });
                if (nume === 'pre' && s.charAt(i) === '\n') {
                    i++; // ca în parserul HTML: primul salt de rând de după <pre> nu face parte din conținut
                } else if (nume === 'pre' && s.charAt(i) === '&') {
                    refuz('referință de caracter imediat după <pre>'); // parserul ar sări și un salt de rând scris ca referință
                }
            }
        }
        return d.adoptNode(frag);
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Modurile și valorile
    // ---------------------------------------------------------------------------------------------------------------------
    function modAcum() {
        try {
            if (w.matchMedia('(min-width: 981px)').matches) {
                return 'desktop';
            }
            return w.matchMedia('(min-width: 768px)').matches ? 'tablet' : 'phone';
        } catch (e) {
            var l = w.innerWidth || 0;
            return l >= 981 ? 'desktop' : (l >= 768 ? 'tablet' : 'phone');
        }
    }

    function revizor(cheie, valoare) {
        return cheie === '__proto__' || cheie === 'constructor' || cheie === 'prototype' ? undefined : valoare;
    }

    function esteObiect(o) {
        return o !== null && typeof o === 'object' && Object.prototype.toString.call(o) === '[object Object]';
    }

    /** Valoarea unui mod: phone → tablet → desktop. `proprie` = modul are o valoare a lui (nu moștenită de la desktop). */
    function valoare(fel, mod) {
        var ordine = mod === 'phone' ? ['phone', 'tablet', 'desktop'] : (mod === 'tablet' ? ['tablet', 'desktop'] : ['desktop']);
        for (var k = 0; k < ordine.length; k++) {
            if (are.call(fel, ordine[k])) {
                return { v: fel[ordine[k]], proprie: ordine[k] !== 'desktop' };
            }
        }
        return null;
    }

    var intrari = [];

    function intrareDin(el) {
        for (var k = 0; k < intrari.length; k++) {
            if (intrari[k].el === el) {
                return intrari[k];
            }
        }
        return null;
    }

    function stramos(el, clasa) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            if (n.classList && n.classList.contains(clasa)) {
                return n;
            }
        }
        return null;
    }

    function tinteHover(el, cfg) {
        var rez = [];
        if (typeof cfg.hover_selector === 'string' && SELECTOR_SIGUR.test(cfg.hover_selector)) {
            var gasite = [];
            try {
                gasite = d.querySelectorAll(cfg.hover_selector);
            } catch (e) {
                gasite = [];
            }
            for (var k = 0; k < gasite.length; k++) {
                if (gasite[k] === el || gasite[k].contains(el)) {
                    rez.push(gasite[k]);
                }
            }
            if (rez.length) {
                return rez;
            }
        }
        var s = typeof cfg.slug === 'string' && SLUG.test(cfg.slug) ? stramos(el, cfg.slug) : null;
        return [s || el];
    }

    function goleste(el) {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
    }

    /** Varianta citită, sau null (refuzată, cu un rând în diagnostic). */
    function fragmentDin(v) {
        if (typeof v !== 'string') {
            ax.noteaza('multi-view: variantă refuzată (nu e text)');
            return null;
        }
        try {
            return citesteFragment(v);
        } catch (e) {
            ax.noteaza('multi-view: variantă refuzată (' + (e && e.refuzFragment ? e.message : 'eroare') + ')');
            return null;
        }
    }

    /** Pune înapoi nodurile originale ale conținutului (cele din pagină, verificate de politica serverului). */
    function restaureazaContinut(intr) {
        if (intr.originale === null) {
            return;
        }
        goleste(intr.el);
        for (var k = 0; k < intr.originale.length; k++) {
            intr.el.appendChild(intr.originale[k]);
        }
        intr.originale = null;
    }

    function puneContinut(intr, v) {
        var frag = fragmentDin(v);
        if (frag === null) {
            restaureazaContinut(intr);
            return;
        }
        if (intr.originale === null) {
            intr.originale = Array.prototype.slice.call(intr.el.childNodes);
        }
        goleste(intr.el);
        intr.el.appendChild(frag);
    }

    /** Atributele de imagine ale unei valori, verificate; null dacă nu e o listă bună. */
    function atributeDin(v) {
        if (!esteObiect(v)) {
            return null;
        }
        var rez = [];
        for (var k in v) {
            if (!are.call(v, k)) {
                continue;
            }
            var x = v[k];
            if (typeof x !== 'string') {
                continue;
            }
            if (k === 'class') {
                rez.push(['class', x]);
            } else if (ATR_IMAGINE.indexOf(k) >= 0) {
                if ((k === 'src' && x !== '' && !adresaBuna('src', x)) || (k === 'srcset' && x !== '' && !adresaBuna('srcset', x))) {
                    ax.noteaza('multi-view: adresă refuzată în ' + k);
                    return null;
                }
                rez.push([k, x]);
            } else {
                ax.noteaza('multi-view: atribut ignorat: ' + k);
            }
        }
        return rez;
    }

    function aplicaAtribute(el, lista) {
        for (var k = 0; k < lista.length; k++) {
            if (lista[k][0] === 'class') {
                var clase = lista[k][1].split(/\s+/);
                for (var c = 0; c < clase.length; c++) {
                    if (/^[A-Za-z0-9_-]{1,80}$/.test(clase[c])) {
                        el.classList.add(clase[c]);
                    }
                }
            } else if (el.nodeName === 'IMG' && el.getAttribute(lista[k][0]) !== lista[k][1]) {
                el.setAttribute(lista[k][0], lista[k][1]);
            }
        }
    }

    /** Schimbarea pe loc a unei imagini (hover, imaginile vizibile): ascunsă până la load, apoi estompată de CSS. */
    function schimbaPeLoc(intr, lista) {
        var el = intr.el;
        intr.generatie++;
        var g = intr.generatie;
        el.classList.remove('et_multi_view_image__loaded');
        el.classList.add('et_multi_view_image__loading');
        el.classList.add('et_multi_view_swapped');
        aplicaAtribute(el, lista);
        function gata() {
            if (intr.generatie !== g) {
                return;
            }
            el.classList.remove('et_multi_view_image__loading');
            el.classList.add('et_multi_view_image__loaded');
        }
        if (!el.complete) {
            el.addEventListener('load', gata, { once: true });
            el.addEventListener('error', gata, { once: true });
        } else {
            w.setTimeout(gata, 0);
        }
    }

    /** Imaginea ascunsă a logo-ului pe tablet/phone: o copie cu atributele modului îi ia locul (ca pe live); originalul se păstrează. */
    function inlocuiesteImaginea(intr, lista) {
        var vechi = intr.el;
        var nou = vechi.cloneNode(false);
        aplicaAtribute(nou, lista);
        nou.classList.remove('et_multi_view_hidden_image');
        nou.classList.remove('et_multi_view_image__loading');
        nou.classList.add('et_multi_view_swapped');
        nou.classList.add('et_multi_view_image__loaded');
        if (vechi.parentNode) {
            vechi.parentNode.replaceChild(nou, vechi);
        }
        if (intr.imagineOriginala === null) {
            intr.imagineOriginala = vechi;
        }
        intr.el = nou;
    }

    function aplicaValoare(intr, v, pePrag) {
        var el = intr.el;
        if (intr.fel === 'content') {
            puneContinut(intr, v);
            el.classList.add('et_multi_view_swapped'); // și la refuz: conținutul original rămâne afișat
            return;
        }
        var lista = atributeDin(v);
        if (el.nodeName !== 'IMG') {
            if (lista !== null) {
                aplicaAtribute(el, lista); // doar clasele
            }
            el.classList.add('et_multi_view_swapped');
            return;
        }
        var ascunsa = el.classList.contains('et_multi_view_hidden_image');
        if (lista === null) {
            if (!ascunsa) {
                el.classList.add('et_multi_view_swapped');
            }
            return;
        }
        // ca pe live: o valoare fără srcset/sizes le golește (altfel ar rămâne cele vechi ale imaginii)
        var puse = {};
        for (var k = 0; k < lista.length; k++) {
            puse[lista[k][0]] = 1;
        }
        if (!puse.srcset) {
            lista.push(['srcset', '']);
        }
        if (!puse.sizes) {
            lista.push(['sizes', '']);
        }
        if (pePrag && ascunsa) {
            inlocuiesteImaginea(intr, lista);
        } else {
            schimbaPeLoc(intr, lista);
        }
    }

    /** Înapoi într-un mod fără valoare proprie (desktop): conținutul și imaginea de la încărcare, cu clasele lăsate de live. */
    function restaureaza(intr, x) {
        if (intr.fel === 'content') {
            restaureazaContinut(intr);
        } else if (intr.imagineOriginala !== null) {
            if (intr.el.parentNode) {
                intr.el.parentNode.replaceChild(intr.imagineOriginala, intr.el);
            }
            intr.el = intr.imagineOriginala;
            intr.imagineOriginala = null;
            intr.el.classList.add('et_multi_view_swapped');
            intr.el.classList.add('et_multi_view_image__loading');
        } else if (intr.el.nodeName === 'IMG' && x !== null) {
            aplicaValoare(intr, x.v, false);
        } else {
            intr.el.classList.remove('et_multi_view_swapped');
        }
        intr.schimbat = false;
    }

    /** Valoarea modului curent, doar dacă diferă de cea pusă deja (la pornire: desktop e deja în pagină). */
    function reevalueaza(intr) {
        var mod = modAcum();
        if (intr.mod === mod) {
            return;
        }
        intr.mod = mod;
        var x = valoare(intr.schema, mod);
        if (x !== null && x.proprie) {
            aplicaValoare(intr, x.v, true);
            intr.schimbat = true;
        } else if (intr.schimbat) {
            restaureaza(intr, x);
        }
    }

    function laIntrare(intr) {
        return function () {
            if (!are.call(intr.schema, 'hover')) {
                return;
            }
            intr.inHover = true;
            aplicaValoare(intr, intr.schema.hover, false);
        };
    }

    function laIesire(intr) {
        return function () {
            if (!intr.inHover) {
                return;
            }
            intr.inHover = false;
            var x = valoare(intr.schema, modAcum());
            if (intr.fel === 'content' && !(x !== null && x.proprie)) {
                restaureaza(intr, x);
            } else if (x !== null) {
                aplicaValoare(intr, x.v, false);
            }
        };
    }

    function citesteConfig(el) {
        var cfg;
        try {
            cfg = JSON.parse(el.getAttribute('data-et-multi-view') || '', revizor);
        } catch (e) {
            return null;
        }
        if (!esteObiect(cfg) || !esteObiect(cfg.schema)) {
            return null;
        }
        return cfg;
    }

    function aplica() {
        var gasite = d.querySelectorAll('[data-et-multi-view]');
        for (var k = 0; k < gasite.length; k++) {
            var el = gasite[k];
            var intr = intrareDin(el);
            if (intr) {
                reevalueaza(intr);
                continue;
            }
            var cfg = citesteConfig(el);
            if (cfg === null) {
                ax.noteaza('multi-view: data-et-multi-view invalid (poziția ' + k + ')');
                continue;
            }
            var fel = esteObiect(cfg.schema.content) ? 'content' : (esteObiect(cfg.schema.attrs) ? 'attrs' : null);
            if (fel === null) {
                continue;
            }
            intr = { el: el, fel: fel, schema: cfg.schema[fel], mod: null, schimbat: false, inHover: false, generatie: 0, originale: null,
                imagineOriginala: null };
            intrari.push(intr);
            var tinte = tinteHover(el, cfg);
            for (var t = 0; t < tinte.length; t++) {
                tinte[t].classList.add('et_multi_view__hover_selector');
                if (are.call(intr.schema, 'hover')) {
                    tinte[t].addEventListener('mouseenter', laIntrare(intr));
                    tinte[t].addEventListener('mouseleave', laIesire(intr));
                }
            }
            reevalueaza(intr);
        }
    }

    var asculta = false;

    function porneste() {
        aplica();
        if (!asculta) {
            asculta = true;
            w.addEventListener('resize', function () {
                for (var k = 0; k < intrari.length; k++) {
                    reevalueaza(intrari[k]);
                }
            });
        }
    }

    ax.multiView = { aplica: aplica, citesteFragment: citesteFragment, mod: modAcum, moduri: MODURI.slice() };
    ax.modul('multi-view', 'dom', porneste);
}(window, document));
