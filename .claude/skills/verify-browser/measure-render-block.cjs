// Reproduksi & ukur dampak resource eksternal render-blocking (fonts.bunny.net,
// fonts.googleapis.com, cdn.tailwindcss.com, dst) saat domain itu "hang" (tidak
// pernah dijawab) + throttle Slow 4G. Dipakai SEBELUM dan SESUDAH fix (brief
// "APLIKASI TIDAK BOLEH TERTAHAN OLEH SERVER LUAR", poin 2 & 5).
//
// Cara kerja "hang": context.route() pada domain target TIDAK PERNAH memanggil
// route.fulfill/continue/abort() — request itu menggantung selamanya persis
// seperti server luar yang paketnya didiamkan (bukan di-RST/di-reject), yang di
// lapangan berujung timeout OS 60-120 detik. Kita cap pengamatan di CAP_MS (lihat
// di bawah) karena menunggu literal 60-120 detik per halaman tidak praktis untuk
// suite otomatis — yang dibuktikan adalah MEKANISME-nya (halaman tak pernah
// tampil selama resource itu menggantung), bukan meniru angka detik pastinya.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const BASE = process.env.BASE || 'http://127.0.0.1:8123';
const PW = process.env.LOGIN_PW || 'password';
const PHASE = process.env.PHASE || 'before';
const CAP_MS = Number(process.env.CAP_MS || 10000); // batas amat generous vs target <3s
const OUT = path.join(__dirname, `measure-${PHASE}.json`);

// Profil throttle "Slow 4G"-equivalent via CDP Network.emulateNetworkConditions
// (bukan preset bernama — CDP cuma terima angka mentah). Nilai ini meniru
// preset "Slow 4G" DevTools: latency RTT 400ms, downlink/uplink ~400kbps.
const SLOW_4G = {
    offline: false,
    latency: 400, // ms round-trip
    downloadThroughput: (400 * 1024) / 8, // bytes/s
    uploadThroughput: (400 * 1024) / 8,
};

// Watchdog generik: kalau sebuah promise (mis. page.evaluate/CDP command) macet
// karena renderer-nya sendiri terblokir oleh resource yang di-hang, kita JANGAN
// ikut menunggu tanpa batas — lempar timeout dan lanjut (nilai yg lama tetap
// "berjalan" di background tapi diabaikan, bukan masalah untuk proses sekali pakai ini).
function withTimeout(promise, ms, label) {
    let timer;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error(`TIMEOUT(${label}) after ${ms}ms`)), ms);
    });
    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

const BROWSER_CANDIDATES = [
    process.env.BROWSER_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].filter(Boolean);

function findBrowser() {
    for (const p of BROWSER_CANDIDATES) if (fs.existsSync(p)) return p;
    throw new Error('No system Chrome/Edge found. Set BROWSER_PATH=/c/path/to/chrome.exe');
}

// Halaman representatif — satu per layout/head markup berbeda. Halaman lain yang
// memakai layout BYTE-IDENTIK (dicek via grep, lihat docs/UI_INVENTORY.md) tidak
// perlu diulang di sini.
const PAGES = [
    {
        label: 'Landing (/, guest)',
        path: '/',
        role: null,
        hangHosts: ['fonts.bunny.net', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.tailwindcss.com'],
        readySelector: 'nav#navbar',
    },
    {
        label: 'Login (/login, guest — kontrol, HARUS tak terpengaruh)',
        path: '/login',
        role: null,
        hangHosts: ['fonts.bunny.net', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.tailwindcss.com'],
        readySelector: 'button[type=submit]',
    },
    {
        label: 'Operator Beranda (/operator/dashboard)',
        path: '/operator/dashboard',
        role: 'operator',
        hangHosts: ['fonts.bunny.net'],
        readySelector: 'body',
    },
    {
        label: 'Owner Dashboard (/owner/dashboard)',
        path: '/owner/dashboard',
        role: 'owner',
        hangHosts: ['fonts.bunny.net'],
        readySelector: 'body',
    },
    {
        label: 'Owner — Manajemen Kios (Filament, /owner-panel/kiosks)',
        path: '/owner-panel/kiosks',
        role: 'owner',
        hangHosts: ['fonts.bunny.net'],
        readySelector: 'body',
    },
    {
        label: 'Admin panel (Filament, /admin)',
        path: '/admin',
        role: 'admin',
        hangHosts: ['fonts.bunny.net'],
        readySelector: 'body',
    },
];

const CREDS = {
    admin: 'admin@cemilanqontas.id',
    owner: 'owner@cemilanqontas.id',
    operator: 'operator@cemilanqontas.id',
};

async function loginAndGetStorageState(browser, email) {
    console.error(`[login] start ${email}`);
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle', timeout: 20000 });
    console.error(`[login] /login loaded for ${email}`);
    await page.fill('#email', email);
    await page.fill('#password', PW);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle', timeout: 20000 }).catch(() => {}),
        page.click('button[type=submit]'),
    ]);
    await page.waitForTimeout(800);
    console.error(`[login] done ${email} -> ${page.url()}`);
    const state = await ctx.storageState();
    await ctx.close();
    return state;
}

// Timer frame-by-frame: first-contentful-paint via Paint Timing API, dipoll dari
// LUAR (evaluate di page yang baru dibuat SEBELUM goto, supaya listener terpasang
// sebelum navigasi; kalau page sudah macet, polling eksternal inilah yang dipakai).
async function measurePage(browser, def, storageState) {
    console.error(`[measure] start ${def.label}`);
    const ctx = await browser.newContext({
        storageState,
        viewport: { width: 390, height: 844 },
    });
    const page = await ctx.newPage();
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Network.emulateNetworkConditions', SLOW_4G);
    console.error(`[measure] throttle armed ${def.label}`);

    await ctx.route('**/*', (route) => {
        const url = new URL(route.request().url());
        if (def.hangHosts.includes(url.hostname)) {
            // SENGAJA tidak pernah fulfill/abort/continue -> request menggantung.
            return;
        }
        return route.continue();
    });

    const t0 = Date.now();
    const navPromise = page.goto(`${BASE}${def.path}`, { waitUntil: 'commit', timeout: CAP_MS + 5000 }).catch((e) => ({ error: String(e) }));
    console.error(`[measure] goto fired ${def.label}`);

    // Poll FCP tiap 150ms sampai muncul atau CAP_MS lewat.
    let fcpMs = null;
    const deadline = t0 + CAP_MS;
    while (Date.now() < deadline) {
        try {
            // evaluate() sendiri dibatasi 1s: kalau renderer macet total karena
            // resource yang di-hang memblokir main thread-nya, CDP round-trip bisa
            // tak pernah balas — jangan ikut menunggu, coba lagi di iterasi berikut.
            const fcp = await withTimeout(page.evaluate(() => {
                const entries = performance.getEntriesByType('paint').filter((e) => e.name === 'first-contentful-paint');
                return entries.length ? entries[0].startTime : null;
            }), 1000, 'evaluate-fcp');
            if (fcp !== null) {
                fcpMs = Date.now() - t0;
                break;
            }
        } catch (_) {
            // page belum siap evaluate (masih di navigasi awal) / evaluate timeout -> abaikan, coba lagi.
        }
        await new Promise((r) => setTimeout(r, 150));
    }

    // Selector "bisa dipakai": coba tunggu visible, cap sisa waktu. Dibungkus
    // withTimeout juga sebagai jaring pengaman kalau waitFor sendiri ikut macet.
    let selectorMs = null;
    const remain = Math.max(0, deadline - Date.now());
    try {
        await withTimeout(
            page.locator(def.readySelector).first().waitFor({ state: 'visible', timeout: remain }),
            remain + 2000,
            'selector-wait'
        );
        selectorMs = Date.now() - t0;
    } catch (_) {
        selectorMs = null; // tidak tampil dalam CAP_MS
    }

    console.error(`[measure] fcp loop done ${def.label} fcpMs=${fcpMs} selectorMs=${selectorMs}`);
    await withTimeout(navPromise, 5000, 'navPromise-settle').catch((e) => {
        console.error(`[measure] navPromise belum settle untuk ${def.label}: ${String(e).slice(0, 150)}`);
    });
    console.error(`[measure] navPromise settled ${def.label}`);
    const screenshotPath = path.join(__dirname, 'shots', `render-block-${PHASE}-${def.path.replace(/\W+/g, '_') || 'root'}.png`);
    await withTimeout(page.screenshot({ path: screenshotPath }), 5000, 'screenshot').catch(() => {});
    await withTimeout(ctx.close(), 5000, 'ctx.close').catch((e) => {
        console.error(`[measure] ctx.close gagal untuk ${def.label}: ${String(e).slice(0, 150)}`);
    });

    return {
        label: def.label,
        path: def.path,
        hangHosts: def.hangHosts,
        fcpMs,
        selectorMs,
        cappedAt: CAP_MS,
        screenshot: screenshotPath,
    };
}

// Jaring pengaman terakhir: kalau SEMUA mekanisme timeout di atas tetap gagal
// (mis. browser process-nya sendiri hang total), proses ini wajib tetap mati
// sendiri daripada menggantung diam-diam tanpa pernah melapor apa-apa.
const ABSOLUTE_TIMEOUT_MS = Number(process.env.ABSOLUTE_TIMEOUT_MS || 240000);
const absoluteWatchdog = setTimeout(() => {
    console.error(`[fatal] absolute watchdog (${ABSOLUTE_TIMEOUT_MS}ms) terpicu — force exit`);
    process.exit(99);
}, ABSOLUTE_TIMEOUT_MS);

(async () => {
    fs.mkdirSync(path.join(__dirname, 'shots'), { recursive: true });
    const loginBrowser = await chromium.launch({ executablePath: findBrowser(), headless: true });

    const storageStates = {};
    for (const role of ['admin', 'owner', 'operator']) {
        storageStates[role] = await loginAndGetStorageState(loginBrowser, CREDS[role]);
    }
    await loginBrowser.close();

    const results = [];
    for (const def of PAGES) {
        const storageState = def.role ? storageStates[def.role] : undefined;
        // Browser BARU per halaman: kalau satu halaman berhasil mewedge renderer-nya
        // (persis skenario yang kita paksakan — resource hang + throttle), itu tidak
        // ikut merusak pengukuran halaman-halaman berikutnya.
        let pageBrowser;
        try {
            pageBrowser = await chromium.launch({ executablePath: findBrowser(), headless: true });
            const result = await withTimeout(
                measurePage(pageBrowser, def, storageState),
                CAP_MS + 15000,
                `measurePage:${def.label}`
            );
            results.push(result);
        } catch (e) {
            console.error(`[run] ${def.label} GAGAL/TIMEOUT: ${String(e).slice(0, 200)}`);
            results.push({ label: def.label, path: def.path, hangHosts: def.hangHosts, fcpMs: null, selectorMs: null, cappedAt: CAP_MS, error: String(e).slice(0, 300) });
        } finally {
            if (pageBrowser) {
                await withTimeout(pageBrowser.close(), 5000, 'pageBrowser.close').catch((e) => {
                    console.error(`[run] pageBrowser.close gagal untuk ${def.label}: ${String(e).slice(0, 150)}`);
                });
            }
        }
    }

    clearTimeout(absoluteWatchdog);
    console.log(`\n=== render-block measurement — PHASE=${PHASE}, throttle=Slow4G-equiv, cap=${CAP_MS}ms ===`);
    for (const r of results) {
        if (r.error) { console.log(`✗ ${r.label}: ERROR ${r.error}`); continue; }
        console.log(`${r.label}`);
        console.log(`    hang domains : ${r.hangHosts.join(', ')}`);
        console.log(`    FCP          : ${r.fcpMs === null ? `> ${r.cappedAt}ms (BELUM tampil)` : `${r.fcpMs}ms`}`);
        console.log(`    usable (${PAGES.find(p=>p.path===r.path)?.readySelector ?? '?'}) : ${r.selectorMs === null ? `> ${r.cappedAt}ms (BELUM bisa dipakai)` : `${r.selectorMs}ms`}`);
    }
    fs.writeFileSync(OUT, JSON.stringify(results, null, 2));
    console.log(`\nHasil tersimpan: ${OUT}`);
})();
