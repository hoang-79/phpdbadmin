// Bildschirmfotos der App für die Website: Chrome ohne Fenster, gesteuert über das DevTools-Protokoll.
//
// Läuft gegen die Sichtprüfung in Herd (Demo-Datenbank phpdbadmin_demo auf MariaDB, MySQL und PostgreSQL); die
// Kennungen der Verbindungen unten stammen von dort. Die Sitzung ist ein Cookie eines angemeldeten Kontos als JSON
// ({"name": …, "value": …}). Aufruf: node website/werkzeuge/fotos.mjs [name …], danach werkzeuge/bilder.py.
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const BASE = process.env.FOTO_BASIS ?? 'https://sichtpruefung.phpdbadmin.test';
const OUT = process.env.FOTO_ZIEL ?? '/tmp/phpdbadmin-werkzeug/fotos';
const SITZUNG =
    process.env.FOTO_SITZUNG ?? '/tmp/phpdbadmin-werkzeug/sitzung.json';
const MARIADB = '01m62e2k2m7x5hdnf6g23yyg79';
const PG = '01m62e2k2m7x5hdnf6g23yyg7a';
const PG_SERVER = '01m62e2k2n81tbaqmyq37qbntx';
const only = process.argv.slice(2);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const chrome = spawn(
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    [
        '--headless=new',
        '--remote-debugging-port=9333',
        '--user-data-dir=/tmp/pda-chrome',
        '--hide-scrollbars',
        '--no-first-run',
        '--no-default-browser-check',
        '--force-color-profile=srgb',
        'about:blank',
    ],
    { stdio: 'ignore' },
);
let targets = [];
for (let attempt = 0; attempt < 60 && targets.length === 0; attempt++) {
    await sleep(500);
    targets = await fetch('http://127.0.0.1:9333/json')
        .then((r) => r.json())
        .catch(() => []);
}
const ws = new WebSocket(
    targets.find((t) => t.type === 'page').webSocketDebuggerUrl,
);
await new Promise((r) => ws.addEventListener('open', r));
let next = 0;
const pending = new Map();
ws.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
        pending.get(message.id)(message);
        pending.delete(message.id);
    }
});
const send = (method, params = {}) =>
    new Promise((resolve, reject) => {
        const id = ++next;
        pending.set(id, (m) =>
            m.error
                ? reject(new Error(method + ': ' + JSON.stringify(m.error)))
                : resolve(m.result),
        );
        ws.send(JSON.stringify({ id, method, params }));
    });
const run = async (expression) =>
    (
        await send('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        })
    ).result?.value;

mkdirSync(OUT, { recursive: true });
const cookie = JSON.parse(readFileSync(SITZUNG, 'utf8'));
await send('Page.enable');
await send('Network.enable');
await send('Network.setCookie', {
    name: cookie.name,
    value: cookie.value,
    domain: new URL(BASE).hostname,
    path: '/',
    secure: true,
    httpOnly: true,
    sameSite: 'Lax',
});

// Herd blendet in lokalen Sites «Edit with Herd» ein — für die Fotos weg damit, ebenso die Schreibmarke.
const tidy = `(() => {
    document.querySelectorAll('#herd-ai-root, #herd-ai-host').forEach((el) => el.remove());
    const css = document.createElement('style');
    css.textContent = '* { caret-color: transparent !important; } [data-sonner-toaster] { display: none !important; }';
    document.head.appendChild(css);
})()`;

async function shot(
    name,
    url,
    { width = 1440, height = 900, scheme = 'dark', wait = 2500, before } = {},
) {
    if (only.length && !only.includes(name)) return;
    await send('Emulation.setDeviceMetricsOverride', {
        width,
        height,
        deviceScaleFactor: 2,
        mobile: width < 768,
    });
    await send('Emulation.setEmulatedMedia', {
        features: [
            { name: 'prefers-color-scheme', value: scheme },
            { name: 'prefers-reduced-motion', value: 'reduce' },
        ],
    });
    await send('Page.navigate', { url: BASE + url });
    await sleep(wait);
    await run(tidy);
    if (before) {
        await before();
    }
    await run(tidy);
    await sleep(400);
    const { data } = await send('Page.captureScreenshot', {
        format: 'png',
        captureBeyondViewport: false,
    });
    writeFileSync(`${OUT}/${name}.png`, Buffer.from(data, 'base64'));
    console.log('✓', name);
}

const clickText = (text) =>
    run(
        `[...document.querySelectorAll('button, a')].find((el) => el.textContent.trim().startsWith(${JSON.stringify(text)}))?.click()`,
    );
const key = async (k, code, modifiers = 0, keyCode = 0) => {
    await send('Input.dispatchKeyEvent', {
        type: 'keyDown',
        key: k,
        code,
        modifiers,
        windowsVirtualKeyCode: keyCode,
    });
    await send('Input.dispatchKeyEvent', {
        type: 'keyUp',
        key: k,
        code,
        modifiers,
        windowsVirtualKeyCode: keyCode,
    });
};

for (const scheme of ['dark', 'light']) {
    await shot(
        `raster-${scheme}`,
        `/c/${MARIADB}/s/phpdbadmin_demo/o/order_items`,
        { scheme },
    );
}
await shot(
    'sql',
    `/c/${PG}/sql?tab=11&vorlage=${encodeURIComponent('select c.name, count(o.id) as bestellungen, sum(o.total) as umsatz\nfrom customers c\njoin orders o on o.customer_id = c.id\nwhere c.name is not null\ngroup by c.name\norder by umsatz desc\nlimit 20;')}`,
    {
        wait: 3000,
        before: async () => {
            await clickText('Ausführen');
            await sleep(2500);
        },
    },
);
await shot(
    'explain',
    `/c/${PG}/sql?tab=12&vorlage=${encodeURIComponent("select c.name, o.total\nfrom orders o\njoin customers c on c.id = o.customer_id\nwhere o.status = 'offen' and o.total > 100;")}`,
    {
        wait: 3000,
        before: async () => {
            await clickText('Erklären');
            await sleep(2500);
        },
    },
);
await shot('aendern', `/c/${MARIADB}/s/phpdbadmin_demo/o/orders/alter`, {
    wait: 3000,
});
await shot('diagramm', `/c/${MARIADB}/s/phpdbadmin_demo/diagram`, {
    wait: 3500,
});
await shot('palette', `/c/${MARIADB}/s/phpdbadmin_demo/o/customers`, {
    before: async () => {
        await key('k', 'KeyK', 4, 75);
        await sleep(800);
    },
});
await shot('mobil', `/c/${MARIADB}/s/phpdbadmin_demo/o/customers`, {
    width: 390,
    height: 844,
    wait: 3000,
});
await shot(
    'vergleich',
    `/compare?source=${MARIADB}&source_schema=phpdbadmin_demo&target=${PG_SERVER}&target_db=postgres&target_schema=public&run=1`,
    { wait: 4000 },
);
await shot(
    'mobil-sql',
    `/c/${PG}/sql?tab=13&vorlage=${encodeURIComponent('select name, city\nfrom customers\norder by name\nlimit 10;')}`,
    { width: 390, height: 844, wait: 3000 },
);

ws.close();
chrome.kill();
