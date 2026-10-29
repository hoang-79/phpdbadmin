import { onMounted, ref } from 'vue';

/**
 * Breite einer Seitenleiste, die man an ihrem Rand zieht (Maus, Stift, Finger) oder mit den Pfeiltasten ändert.
 * Sie bleibt im Browser gespeichert; ein Doppelklick auf den Rand setzt sie zurück.
 */
export function useResizableWidth(options: {
    storageKey: string;
    initial: number;
    min: number;
    max: number;
}) {
    const width = ref(options.initial);
    const resizing = ref(false);
    let start: { x: number; width: number } | null = null;

    /** Nie schmaler als min, nie breiter als max — und nie mehr als 60 % des Fensters. */
    function clamp(value: number): number {
        const max = Math.min(options.max, Math.round(window.innerWidth * 0.6));

        return Math.round(Math.min(Math.max(value, options.min), max));
    }

    function save(): void {
        window.localStorage.setItem(options.storageKey, String(width.value));
    }

    onMounted(() => {
        const stored = Number(window.localStorage.getItem(options.storageKey));

        if (Number.isFinite(stored) && stored > 0) {
            width.value = clamp(stored);
        }
    });

    function onPointerDown(event: PointerEvent): void {
        if (event.button !== 0) {
            return;
        }

        event.preventDefault();
        const handle = event.currentTarget as HTMLElement;
        // preventDefault verhindert auch den Fokus — danach sollen die Pfeiltasten trotzdem wirken.
        handle.focus({ preventScroll: true });
        handle.setPointerCapture(event.pointerId);
        start = { x: event.clientX, width: width.value };
        resizing.value = true;
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
    }

    function onPointerMove(event: PointerEvent): void {
        if (start) {
            width.value = clamp(start.width + event.clientX - start.x);
        }
    }

    function onPointerUp(): void {
        if (!start) {
            return;
        }

        start = null;
        resizing.value = false;
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        save();
    }

    function onKeydown(event: KeyboardEvent): void {
        const step = event.shiftKey ? 64 : 16;
        const next = {
            ArrowLeft: width.value - step,
            ArrowRight: width.value + step,
            Home: options.min,
            End: options.max,
        }[event.key];

        if (next !== undefined) {
            event.preventDefault();
            width.value = clamp(next);
            save();
        }
    }

    function reset(): void {
        width.value = clamp(options.initial);
        save();
    }

    return {
        width,
        resizing,
        min: options.min,
        max: options.max,
        /** Für `v-on="…"` am Rand: Ereignisnamen ohne «on». */
        handlers: {
            pointerdown: onPointerDown,
            pointermove: onPointerMove,
            pointerup: onPointerUp,
            pointercancel: onPointerUp,
            keydown: onKeydown,
            dblclick: reset,
        },
    };
}
