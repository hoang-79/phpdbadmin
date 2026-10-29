<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Download,
    ListChecks,
    Maximize,
    RotateCcw,
    Search,
    Waypoints,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import RelationDiagram from '@/components/workspace/diagram/RelationDiagram.vue';
import { downloadSvg } from '@/lib/diagram';
import { titlePrefix } from '@/lib/environment';
import { formatNumber, t, tc } from '@/lib/i18n';
import { diagramHref, structureHref, withDb } from '@/lib/workspace';
import { svg as svgRoute } from '@/routes/workspace/diagram';
import type { Diagram, DiagramCatalogEntry, Workspace } from '@/types';

/**
 * Beziehungsdiagramm eines Schemas (S-07): Tabellen als Kästen mit Spalten, Fremdschlüssel als Linien — angeordnet
 * nach Abhängigkeiten, verschiebbar und zoombar, als SVG-Datei herunterzuladen. Bei mehr als 60 Tabellen zeigt es
 * zuerst die mit den meisten Beziehungen; weitere wählt man im Auswahlfenster.
 */
const props = defineProps<{
    workspace: Workspace;
    diagram: Diagram;
    catalog: DiagramCatalogEntry[];
    selection: string[];
    limits: { allUpTo: number; max: number };
}>();

const canvas = ref<InstanceType<typeof RelationDiagram> | null>(null);
const choosing = ref(false);
const search = ref('');
const chosen = ref<string[]>([...props.selection]);
const downloading = ref(false);

watch(
    () => props.selection,
    (selection) => {
        chosen.value = [...selection];
    },
);

const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${t('Beziehungsdiagramm')} · ${props.workspace.schema ?? ''} · ${props.workspace.connection.name}`,
);
const partial = computed(() => props.selection.length < props.catalog.length);
const matches = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term
        ? props.catalog.filter((entry) =>
              entry.name.toLowerCase().includes(term),
          )
        : props.catalog;
});

function choose(names: string[], add: boolean): void {
    const set = new Set(chosen.value);
    names.forEach((name) => (add ? set.add(name) : set.delete(name)));
    chosen.value = props.catalog
        .map((entry) => entry.name)
        .filter((name) => set.has(name));
}

function toggle(name: string, add: boolean): void {
    choose([name], add);
}

function addNeighbours(): void {
    const neighbours = props.catalog
        .filter((entry) => chosen.value.includes(entry.name))
        .flatMap((entry) => entry.neighbours);
    choose(neighbours, true);
}

function show(): void {
    choosing.value = false;
    router.get(diagramHref(props.workspace, chosen.value));
}

function openTable(name: string): void {
    router.visit(structureHref(props.workspace, name));
}

async function download(): Promise<void> {
    downloading.value = true;

    try {
        await downloadSvg(
            svgRoute.url(
                [
                    props.workspace.connection.id,
                    encodeURIComponent(props.workspace.schema ?? ''),
                ],
                withDb(props.workspace),
            ),
            {
                tables: props.selection,
                positions: canvas.value?.positions() ?? [],
            },
            `${props.workspace.schema ?? 'schema'}.svg`,
        );
    } catch {
        toast.error(
            t(
                'Die SVG-Datei liess sich nicht erstellen — laden Sie die Seite neu und versuchen Sie es noch einmal.',
            ),
        );
    } finally {
        downloading.value = false;
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="flex h-full min-h-0 flex-col">
        <div
            class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b px-4 py-3 sm:px-6"
        >
            <h1 class="flex min-w-0 items-center gap-2 text-lg font-semibold">
                <Waypoints class="size-5 shrink-0 text-text-secondary" />{{
                    t('Beziehungsdiagramm')
                }}
                <span class="truncate font-mono text-base font-normal">{{
                    workspace.schema
                }}</span>
            </h1>
            <p class="text-sm text-text-secondary">
                {{
                    tc(':count Tabelle|:count Tabellen', diagram.tables.length)
                }}
                ·
                {{
                    tc(
                        ':count Beziehung|:count Beziehungen',
                        diagram.relations.length,
                    )
                }}
                <template v-if="partial">
                    ·
                    {{
                        t('Auswahl aus :count', {
                            count: formatNumber(catalog.length),
                        })
                    }}</template
                >
            </p>
            <div class="flex flex-wrap items-center gap-1.5 sm:ml-auto">
                <Button size="sm" variant="outline" @click="choosing = true">
                    <ListChecks />{{ t('Tabellen wählen') }}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :aria-label="t('Verkleinern')"
                    :title="t('Verkleinern (−)')"
                    @click="canvas?.zoomBy(1 / 1.2)"
                >
                    <ZoomOut />
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :aria-label="t('Vergrössern')"
                    :title="t('Vergrössern (+)')"
                    @click="canvas?.zoomBy(1.2)"
                >
                    <ZoomIn />
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :aria-label="t('Einpassen')"
                    :title="t('Einpassen (0)')"
                    @click="canvas?.fit()"
                >
                    <Maximize />
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :title="t('Kästen wieder nach Abhängigkeiten anordnen')"
                    @click="canvas?.rearrange()"
                >
                    <RotateCcw />{{ t('Anordnen') }}
                </Button>
                <Button
                    size="sm"
                    :disabled="downloading || !diagram.tables.length"
                    @click="download"
                >
                    <Spinner v-if="downloading" /><Download v-else />{{
                        t('Als SVG')
                    }}
                </Button>
            </div>
        </div>

        <div
            v-if="partial && catalog.length > limits.allUpTo"
            class="px-4 pt-3 sm:px-6"
        >
            <Notice variant="info">
                {{
                    t(
                        'Das Schema hat :count Tabellen — gezeigt wird eine Auswahl. Weitere wählen Sie unter «Tabellen wählen».',
                        { count: formatNumber(catalog.length) },
                    )
                }}
            </Notice>
        </div>

        <div
            v-if="!diagram.tables.length"
            class="m-4 rounded-lg border border-dashed px-4 py-10 text-center text-sm text-text-secondary sm:m-6"
        >
            {{
                catalog.length
                    ? t('Keine Tabelle gewählt.')
                    : t('Dieses Schema hat keine Tabellen.')
            }}
        </div>
        <div v-else class="min-h-0 flex-1">
            <RelationDiagram
                ref="canvas"
                :tables="diagram.tables"
                :relations="diagram.relations"
                @open="openTable"
            />
        </div>

        <details
            v-if="diagram.relations.length"
            class="max-h-[40%] shrink-0 overflow-y-auto border-t px-4 py-2 text-sm sm:px-6"
        >
            <summary class="cursor-pointer py-1 font-medium">
                {{
                    tc(
                        ':count Beziehung als Liste|:count Beziehungen als Liste',
                        diagram.relations.length,
                    )
                }}
            </summary>
            <ul class="flex flex-col gap-1 py-2 font-mono text-[13px]">
                <li
                    v-for="relation in diagram.relations"
                    :key="`${relation.table}:${relation.name}`"
                    class="wrap-break-word"
                >
                    <Link
                        :href="structureHref(workspace, relation.table)"
                        class="text-primary underline-offset-2 hover:underline"
                        >{{ relation.table }}</Link
                    >({{ relation.columns.join(', ') }}) →
                    <Link
                        :href="structureHref(workspace, relation.foreignTable)"
                        class="text-primary underline-offset-2 hover:underline"
                        >{{ relation.foreignTable }}</Link
                    >({{ relation.foreignColumns.join(', ') }})
                </li>
            </ul>
        </details>

        <Sheet v-model:open="choosing">
            <SheetContent
                side="right"
                class="flex w-[340px] max-w-full flex-col gap-3 p-4"
            >
                <SheetTitle>{{ t('Tabellen wählen') }}</SheetTitle>
                <SheetDescription>{{
                    t(
                        'Höchstens :max Tabellen auf einmal. «Nachbarn dazu» nimmt die Tabellen mit, die mit der Auswahl verbunden sind.',
                        { max: limits.max },
                    )
                }}</SheetDescription>
                <label class="relative block">
                    <span class="sr-only">{{ t('Tabellen filtern') }}</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('Tabellen filtern')"
                        class="h-9 w-full rounded-md border bg-background pr-2 pl-8 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </label>
                <div class="flex flex-wrap gap-1.5">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="
                            choose(
                                matches.map((entry) => entry.name),
                                true,
                            )
                        "
                        >{{ t('Treffer wählen') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="addNeighbours"
                        >{{ t('Nachbarn dazu') }}</Button
                    >
                    <Button size="sm" variant="outline" @click="chosen = []">{{
                        t('Keine')
                    }}</Button>
                </div>
                <ul class="min-h-0 flex-1 overflow-y-auto text-sm">
                    <li v-for="entry in matches" :key="entry.name">
                        <label
                            class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-muted"
                        >
                            <input
                                type="checkbox"
                                class="size-4 accent-[var(--primary)]"
                                :checked="chosen.includes(entry.name)"
                                @change="
                                    toggle(
                                        entry.name,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            <span class="min-w-0 flex-1 truncate font-mono">{{
                                entry.name
                            }}</span>
                            <span
                                class="shrink-0 text-xs text-muted-foreground tabular-nums"
                                :title="t('Beziehungen')"
                                >{{ entry.relations }}</span
                            >
                        </label>
                    </li>
                </ul>
                <p
                    v-if="chosen.length > limits.max"
                    class="text-[13px] text-destructive"
                >
                    {{
                        t('Zu viele: :count gewählt, höchstens :max.', {
                            count: chosen.length,
                            max: limits.max,
                        })
                    }}
                </p>
                <Button
                    :disabled="!chosen.length || chosen.length > limits.max"
                    @click="show"
                >
                    {{
                        tc(
                            ':count Tabelle zeigen|:count Tabellen zeigen',
                            chosen.length,
                        )
                    }}
                </Button>
            </SheetContent>
        </Sheet>
    </div>
</template>
