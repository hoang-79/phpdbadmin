/**
 * Scroll-Hinweise statt Scrollbalken (Gestaltung §8): Jeder Bereich, der scrollt, blendet an dem Rand aus, an dem es
 * weitergeht, und zeigt dort einen Chevron — oben, unten, links, rechts. Die Scrollbalken selbst blendet app.css aus.
 *
 * Kein Bauteil muss mitmachen: Ein Beobachter findet Bereiche mit `overflow-auto`, `overflow-y-auto`,
 * `overflow-x-auto` (oder `data-scroll-hint`) und den Editor (`.cm-scroller`) und hängt sich an. Sticky Kopfzeilen und
 * Spalten bleiben unberührt: `data-scroll-hint-top` bzw. `data-scroll-hint-left` nennt einen Selektor, dessen Höhe
 * bzw. Breite vom Ausblenden ausgenommen ist.
 *
 * Die Chevrons liegen in einer eigenen Ebene über allem und lassen Klicks durch. Einer erscheint nur, wenn der Bereich
 * an seiner Stelle zuoberst liegt — unter einem Dialog oder ausserhalb des Fensters bleibt er weg.
 */
const SELECTOR =
    '.overflow-auto, .overflow-y-auto, .overflow-x-auto, .overflow-y-scroll, .cm-scroller, [data-scroll-hint]';

type Direction = 'up' | 'down' | 'left' | 'right';

const DIRECTIONS: Direction[] = ['up', 'down', 'left', 'right'];

/** Durchmesser des Chevrons und Abstand zum Rand, in Pixeln. */
const CHEVRON_SIZE = 24;
const CHEVRON_GAP = 6;

type Tracked = {
    dirty: boolean;
    observed: Set<Element>;
    open: Record<Direction, boolean>;
    offsetTop: number;
    offsetLeft: number;
    chevrons: Partial<Record<Direction, HTMLElement>>;
};

const tracked = new Map<HTMLElement, Tracked>();
let resizeObserver: ResizeObserver | null = null;
let layer: HTMLElement | null = null;
let frame = 0;

function schedule(entry?: Tracked): void {
    if (entry) {
        entry.dirty = true;
    }

    if (frame === 0) {
        frame = window.requestAnimationFrame(flush);
    }
}

function flush(): void {
    frame = 0;

    for (const [element, entry] of tracked) {
        if (entry.dirty) {
            entry.dirty = false;
            measure(element, entry);
        }
    }

    place();
}

function measure(element: HTMLElement, entry: Tracked): void {
    const style = window.getComputedStyle(element);
    const vertical = /(auto|scroll)/.test(style.overflowY);
    // Im Editor bleibt die Zeilennummern-Spalte links stehen — dort nur oben und unten.
    const horizontal =
        /(auto|scroll)/.test(style.overflowX) &&
        !element.classList.contains('cm-scroller');
    const top = element.scrollTop;
    const left = Math.abs(element.scrollLeft);

    entry.open = {
        up: vertical && top > 1,
        down: vertical && top + element.clientHeight < element.scrollHeight - 1,
        left: horizontal && left > 1,
        right:
            horizontal && left + element.clientWidth < element.scrollWidth - 1,
    };

    const header = element.dataset.scrollHintTop
        ? element.querySelector<HTMLElement>(element.dataset.scrollHintTop)
        : null;
    const column = element.dataset.scrollHintLeft
        ? element.querySelector<HTMLElement>(element.dataset.scrollHintLeft)
        : null;
    entry.offsetTop = header?.offsetHeight ?? 0;
    entry.offsetLeft = column?.offsetWidth ?? 0;

    for (const direction of DIRECTIONS) {
        // 0 blendet aus, 1 nicht (app.css, [data-scroll-hint]).
        element.style.setProperty(
            `--scroll-hint-${direction}`,
            entry.open[direction] ? '0' : '1',
        );
    }

    element.style.setProperty(
        '--scroll-hint-offset-top',
        `${entry.offsetTop}px`,
    );
    element.style.setProperty(
        '--scroll-hint-offset-left',
        `${entry.offsetLeft}px`,
    );
}

/** Mitte des Chevrons am Rand — oder null, wenn der Bereich dafür zu klein ist. */
function pointFor(
    direction: Direction,
    rect: DOMRect,
    entry: Tracked,
): { x: number; y: number } | null {
    const inset = CHEVRON_SIZE / 2 + CHEVRON_GAP;

    if (direction === 'up' || direction === 'down') {
        if (rect.height < 80 || rect.width < 60) {
            return null;
        }

        return {
            x:
                rect.left +
                entry.offsetLeft +
                (rect.width - entry.offsetLeft) / 2,
            y:
                direction === 'up'
                    ? rect.top + entry.offsetTop + inset
                    : rect.bottom - inset,
        };
    }

    if (rect.width < 120 || rect.height < CHEVRON_SIZE) {
        return null;
    }

    return {
        x:
            direction === 'left'
                ? rect.left + entry.offsetLeft + inset
                : rect.right - inset,
        y: rect.top + entry.offsetTop + (rect.height - entry.offsetTop) / 2,
    };
}

function createChevron(entry: Tracked, direction: Direction): HTMLElement {
    const existing = entry.chevrons[direction];

    if (existing) {
        return existing;
    }

    const badge = document.createElement('div');
    badge.className = 'scroll-hint-chevron';
    badge.dataset.direction = direction;

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'm6 9 6 6 6-6');
    svg.append(path);
    badge.append(svg);

    layer?.append(badge);
    entry.chevrons[direction] = badge;

    return badge;
}

function place(): void {
    for (const [element, entry] of tracked) {
        const rect = element.getBoundingClientRect();

        for (const direction of DIRECTIONS) {
            const point = entry.open[direction]
                ? pointFor(direction, rect, entry)
                : null;
            // Zuoberst? Die Chevrons selbst lassen Klicks durch und zählen hier nicht.
            const hit = point
                ? document.elementFromPoint(point.x, point.y)
                : null;

            if (!point || !hit || !element.contains(hit)) {
                entry.chevrons[direction]?.removeAttribute('data-visible');

                continue;
            }

            const badge = createChevron(entry, direction);
            const transform = `translate(${Math.round(point.x - CHEVRON_SIZE / 2)}px, ${Math.round(point.y - CHEVRON_SIZE / 2)}px)`;

            if (badge.style.transform !== transform) {
                badge.style.transform = transform;
            }

            badge.setAttribute('data-visible', '');
        }
    }
}

function attach(element: HTMLElement): void {
    if (tracked.has(element)) {
        return;
    }

    element.setAttribute(
        'data-scroll-hint',
        element.getAttribute('data-scroll-hint') ?? '',
    );

    const entry: Tracked = {
        dirty: true,
        observed: new Set(),
        open: { up: false, down: false, left: false, right: false },
        offsetTop: 0,
        offsetLeft: 0,
        chevrons: {},
    };
    tracked.set(element, entry);
    observe(element, entry);
    schedule(entry);
}

/** Grösse des Bereichs und seiner direkten Kinder — wächst der Inhalt, ändert sich, ob es weitergeht. */
function observe(element: HTMLElement, entry: Tracked): void {
    if (!resizeObserver) {
        return;
    }

    const targets = [element, ...Array.from(element.children).slice(0, 50)];

    for (const target of targets) {
        if (!entry.observed.has(target)) {
            resizeObserver.observe(target);
            entry.observed.add(target);
        }
    }
}

function scan(root: ParentNode): void {
    if (root instanceof HTMLElement && root.matches(SELECTOR)) {
        attach(root);
    }

    root.querySelectorAll<HTMLElement>(SELECTOR).forEach(attach);
}

function forgetDetached(): void {
    for (const [element, entry] of tracked) {
        if (!element.isConnected) {
            entry.observed.forEach((target) =>
                resizeObserver?.unobserve(target),
            );
            Object.values(entry.chevrons).forEach((badge) => badge.remove());
            tracked.delete(element);
        }
    }
}

export function startScrollHints(): void {
    if (
        typeof window === 'undefined' ||
        typeof ResizeObserver === 'undefined'
    ) {
        return;
    }

    layer = document.createElement('div');
    layer.className = 'scroll-hint-layer';
    layer.setAttribute('aria-hidden', 'true');
    document.body.append(layer);

    resizeObserver = new ResizeObserver((entries) => {
        for (const { target } of entries) {
            const host =
                target instanceof HTMLElement && tracked.has(target)
                    ? target
                    : target.parentElement;
            schedule(host ? tracked.get(host) : undefined);
        }
    });

    let pending = 0;
    const mutations = new MutationObserver((records) => {
        for (const record of records) {
            if (
                record.type !== 'childList' ||
                !(record.target instanceof HTMLElement) ||
                layer?.contains(record.target)
            ) {
                continue;
            }

            // Neuer Inhalt in einem beobachteten Bereich: neu messen, neue Kinder mitbeobachten.
            const host =
                record.target.closest<HTMLElement>('[data-scroll-hint]');
            const entry = host ? tracked.get(host) : undefined;

            if (host && entry) {
                observe(host, entry);
                schedule(entry);
            }
        }

        if (pending === 0) {
            pending = window.requestAnimationFrame(() => {
                pending = 0;
                scan(document.body);
                forgetDetached();
                schedule();
            });
        }
    });

    mutations.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class'],
    });
    scan(document.body);

    // Scrollen irgendwo (ein äusserer Bereich verschiebt die inneren), Fenstergrösse, Schubladen, die einfahren.
    document.addEventListener(
        'scroll',
        (event) =>
            schedule(
                event.target instanceof HTMLElement
                    ? tracked.get(event.target)
                    : undefined,
            ),
        { capture: true, passive: true },
    );
    window.addEventListener(
        'resize',
        () => tracked.forEach((entry) => schedule(entry)),
        { passive: true },
    );
    document.addEventListener('transitionend', () => schedule(), {
        capture: true,
        passive: true,
    });
    document.addEventListener('animationend', () => schedule(), {
        capture: true,
        passive: true,
    });
}
