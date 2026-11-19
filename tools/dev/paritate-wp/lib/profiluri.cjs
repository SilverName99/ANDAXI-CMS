// Profilurile de browser ale harness-ului de paritate (P3; DECIZIE §6.1, §6.5): cele șase UA ale capturii
// (src/Support/ImportWordPress/Captura/Cereri.php::UA), fiecare cu platforma și furnizorul browserului real, plus
// lățimile și înălțimile ferestrelor. Referința și candidatul se iau cu exact aceleași profiluri (același harness).
//
// În Chromium se emulează doar ce se poate emula ieftin și determinist: antetul și navigator.userAgent, navigator.platform,
// navigator.vendor, atingerea (isMobile/hasTouch, deci maxTouchPoints) și, pe iPhone, navigator.standalone === false (Safari
// pe iOS îl are; Chromium nu). Restul diferențelor dintre motoare (WebKit, Gecko) țin de P4, pe dispozitive reale.

'use strict';

const UA = {
    'chrome-win': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    'chrome-mac': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    'safari-iphone': 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
    'chrome-android': 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
    'firefox': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
    'safari-mac': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
};

// Ce expune browserul real al fiecărui UA (navigator.*). `mobil` = isMobile + hasTouch în Playwright.
const PROFILURI = {
    'chrome-win': { ua: UA['chrome-win'], platforma: 'Win32', furnizor: 'Google Inc.', mobil: false },
    'chrome-mac': { ua: UA['chrome-mac'], platforma: 'MacIntel', furnizor: 'Google Inc.', mobil: false },
    'safari-iphone': { ua: UA['safari-iphone'], platforma: 'iPhone', furnizor: 'Apple Computer, Inc.', mobil: true, standalone: false },
    'chrome-android': { ua: UA['chrome-android'], platforma: 'Linux armv8l', furnizor: 'Google Inc.', mobil: true },
    'firefox': { ua: UA.firefox, platforma: 'Win32', furnizor: '', mobil: false },
    'safari-mac': { ua: UA['safari-mac'], platforma: 'MacIntel', furnizor: 'Apple Computer, Inc.', mobil: false },
    // iPad cu iPadOS: același UA ca Safari pe Mac (deci aceleași răspunsuri de la server), dar cu atingere. Că iPadOS expune
    // navigator.standalone === false ca iPhone-ul e o presupunere de verificat pe dispozitiv (P4).
    'safari-ipad': { ua: UA['safari-mac'], platforma: 'MacIntel', furnizor: 'Apple Computer, Inc.', mobil: true, standalone: false },
};

// Lățimile (DECIZIE §6.5) cu înălțimea ferestrei folosită la fiecare: telefoanele și tabletele la dimensiunile lor obișnuite,
// desktopul la 900 (ca lentilele) sau la perechile pinilor. Peisajul telefonului: 844×390.
const FERESTRE = {
    320: [320, 568], 360: [360, 800], 390: [390, 844], 430: [430, 932],
    768: [768, 1024], 820: [820, 1180], 980: [980, 900], 1024: [1024, 768],
    1280: [1280, 800], 1366: [1366, 768], 1440: [1440, 900], 1536: [1536, 864], 1920: [1920, 1080],
};

/** „1440” → {latime:1440, inaltime:900}; „844x390” → {latime:844, inaltime:390}. */
function fereastra(text) {
    const s = String(text).trim();
    let m = /^(\d{3,4})x(\d{3,4})$/.exec(s);
    if (m) {
        return { latime: Number(m[1]), inaltime: Number(m[2]), nume: s };
    }
    m = /^(\d{3,4})$/.exec(s);
    if (m && FERESTRE[Number(m[1])]) {
        const [l, i] = FERESTRE[Number(m[1])];
        return { latime: l, inaltime: i, nume: l + 'x' + i };
    }
    throw new Error('fereastră necunoscută: ' + s + ' (o lățime din ' + Object.keys(FERESTRE).join(',') + ' sau LxI)');
}

function profil(nume) {
    const p = PROFILURI[nume];
    if (!p) {
        throw new Error('profil necunoscut: ' + nume + ' (' + Object.keys(PROFILURI).join(', ') + ')');
    }
    return { nume, ...p };
}

/** Opțiunile contextului Playwright pentru un profil și o fereastră. */
function optiuniContext(p, f, extra) {
    return {
        viewport: { width: f.latime, height: f.inaltime },
        screen: { width: f.latime, height: f.inaltime },
        deviceScaleFactor: 1,
        isMobile: p.mobil,
        hasTouch: p.mobil,
        userAgent: p.ua,
        locale: 'ro-RO',
        timezoneId: 'Europe/Bucharest',
        colorScheme: 'light',
        reducedMotion: 'no-preference',
        serviceWorkers: 'block',
        acceptDownloads: false,
        ...(extra || {}),
    };
}

/** Scriptul de inițializare care dă paginii navigatorul browserului real (rulează în fiecare cadru, înaintea paginii). */
function scriptNavigator(p) {
    return '(' + function (platforma, furnizor, standalone) {
        const def = (obiect, nume, valoare) => {
            try {
                Object.defineProperty(obiect, nume, { get: () => valoare, configurable: true });
            } catch (e) { /* proprietate neconfigurabilă: rămâne cea a motorului */ }
        };
        def(Navigator.prototype, 'platform', platforma);
        def(Navigator.prototype, 'vendor', furnizor);
        if (standalone !== null) {
            def(Navigator.prototype, 'standalone', standalone);
        }
    }.toString() + ')(' + JSON.stringify(p.platforma) + ',' + JSON.stringify(p.furnizor) + ','
        + JSON.stringify(Object.prototype.hasOwnProperty.call(p, 'standalone') ? p.standalone : null) + ');';
}

module.exports = { UA, PROFILURI, FERESTRE, fereastra, profil, optiuniContext, scriptNavigator };
