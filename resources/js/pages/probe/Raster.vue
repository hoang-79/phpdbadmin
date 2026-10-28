<script setup lang="ts">
/**
 * Feasibility F-3: virtualisiertes Raster über eine Tabelle mit einer Million Zeilen.
 * Lädt Seiten zu 200 Zeilen über den Schlüssel nach, rendert nur die sichtbaren Zeilen.
 * Nur lokal; misst selbst und zeigt die Zeiten als Text.
 */
import { Head } from '@inertiajs/vue3';
import { useVirtualizer } from '@tanstack/vue-virtual';
import { computed, onMounted, ref, watch } from 'vue';

type Row = Record<string, string | number | null>;

const props = defineProps<{ columns: string[]; estimate: number }>();

const rows = ref<Row[]>([]);
const scroller = ref<HTMLElement | null>(null);
const loading = ref(false);
const firstPageMs = ref<number | null>(null);
const lastPageMs = ref<number | null>(null);
const lastServerMs = ref<number | null>(null);
const pages = ref(0);
const error = ref<string | null>(null);

const virtualizer = useVirtualizer(
    computed(() => ({
        count: rows.value.length,
        getScrollElement: () => scroller.value,
        estimateSize: () => 32,
        overscan: 10,
    })),
);
const items = computed(() => virtualizer.value.getVirtualItems());

async function loadPage(): Promise<void> {
    if (loading.value) {
        return;
    }

    loading.value = true;
    const after = Number(rows.value.at(-1)?.id ?? 0);
    const start = performance.now();

    try {
        const response = await fetch(`/probe/raster/rows?after=${after}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            error.value = `Antwort ${response.status}`;

            return;
        }

        const data = (await response.json()) as {
            rows: Row[];
            server_ms: number;
        };
        rows.value = rows.value.concat(data.rows);
        pages.value += 1;
        lastPageMs.value = Math.round(performance.now() - start);
        lastServerMs.value = data.server_ms;
        firstPageMs.value ??= lastPageMs.value;
    } catch (exception) {
        error.value = String(exception);
    } finally {
        loading.value = false;
    }
}

watch(items, (visible) => {
    const last = visible.at(-1);

    if (last !== undefined && last.index > rows.value.length - 50) {
        void loadPage();
    }
});

onMounted(() => void loadPage());
</script>

<template>
    <Head title="Probe Raster" />
    <main class="mx-auto max-w-6xl space-y-4 p-6">
        <h1 class="text-xl font-semibold">F-3 Probe: virtualisiertes Raster</h1>
        <dl
            class="grid grid-cols-2 gap-x-4 text-sm sm:grid-cols-4"
            data-probe="messung"
        >
            <dt>Zeilen (Schätzung)</dt>
            <dd>{{ estimate.toLocaleString('de-CH') }}</dd>
            <dt>Geladen</dt>
            <dd>{{ rows.length }} Zeilen in {{ pages }} Seiten</dd>
            <dt>Erste Seite</dt>
            <dd>{{ firstPageMs ?? '…' }} ms</dd>
            <dt>Letzte Seite</dt>
            <dd>
                {{ lastPageMs ?? '…' }} ms (Server {{ lastServerMs ?? '…' }} ms)
            </dd>
            <dt>Zeilen im DOM</dt>
            <dd data-probe="dom-zeilen">{{ items.length }}</dd>
            <dt>Fehler</dt>
            <dd>{{ error ?? 'keiner' }}</dd>
        </dl>
        <div
            ref="scroller"
            class="h-[60vh] overflow-auto rounded-md border border-slate-300 text-sm"
            data-probe="raster"
        >
            <div class="sticky top-0 z-10 flex bg-slate-100 font-semibold">
                <div
                    v-for="column in props.columns"
                    :key="column"
                    class="w-40 shrink-0 px-2 py-1"
                >
                    {{ column }}
                </div>
            </div>
            <div
                class="relative"
                :style="{ height: `${virtualizer.getTotalSize()}px` }"
            >
                <div
                    v-for="item in items"
                    :key="String(item.key)"
                    class="absolute left-0 flex w-full border-b border-slate-100"
                    :style="{
                        height: `${item.size}px`,
                        transform: `translateY(${item.start}px)`,
                    }"
                    data-probe="zeile"
                >
                    <div
                        v-for="column in props.columns"
                        :key="column"
                        class="w-40 shrink-0 truncate px-2 py-1"
                    >
                        {{ rows[item.index]?.[column] ?? 'NULL' }}
                    </div>
                </div>
            </div>
        </div>
    </main>
</template>
