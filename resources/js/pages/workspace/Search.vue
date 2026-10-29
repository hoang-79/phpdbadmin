<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Search, Table2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { titlePrefix } from '@/lib/environment';
import { formatNumber, t, tc } from '@/lib/i18n';
import { dataHref } from '@/lib/workspace';
import { search as searchRoute } from '@/routes/workspace';
import type { SchemaSearchOutcome, Workspace } from '@/types';

/**
 * Suche über ein ganzes Schema (D-08): nur lesen, in den Textspalten aller Tabellen, mit Zeit- und Treffergrenze.
 * Jede Tabelle mit Treffern führt ins Raster mit derselben Suche — dort lässt sich weiter filtern und ersetzen.
 */
const props = defineProps<{
    workspace: Workspace;
    term: string;
    outcome: SchemaSearchOutcome | null;
    limits: { seconds: number; hits: number };
}>();

const draft = ref(props.term);
const searching = ref(false);

const schemaWord = computed(() =>
    props.workspace.schemaKind === 'schema' ? t('Schema') : t('Datenbank'),
);
const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${t('Suchen')} · ${props.workspace.connection.name}`,
);

function query(schema: string | null, term: string): Record<string, string> {
    return Object.fromEntries(
        Object.entries({
            schema: schema ?? '',
            q: term,
            db: props.workspace.database ?? '',
        }).filter(([, value]) => value !== ''),
    );
}

function submit(): void {
    router.get(
        searchRoute.url(props.workspace.connection.id, {
            query: query(props.workspace.schema, draft.value.trim()),
        }),
        {},
        {
            preserveState: true,
            onStart: () => (searching.value = true),
            onFinish: () => (searching.value = false),
        },
    );
}

function chooseSchema(event: Event): void {
    router.get(
        searchRoute.url(props.workspace.connection.id, {
            query: query((event.target as HTMLSelectElement).value, props.term),
        }),
    );
}

const selectClass =
    'h-9 w-full min-w-0 rounded-md border bg-background px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:w-60';
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <div class="flex flex-col gap-1">
            <h1 class="flex items-center gap-2 text-xl font-semibold">
                <Search class="size-5 text-text-secondary" />{{
                    t('Suchen in allen Tabellen')
                }}
            </h1>
            <p class="text-sm text-text-secondary">
                {{
                    t(
                        'Nur lesen: Text-, Auswahl-, JSON- und Kennungsspalten, ohne Rücksicht auf Gross- und Kleinschreibung — wie die Suche im Raster. Höchstens :seconds s und :hits Treffer.',
                        {
                            seconds: limits.seconds,
                            hits: formatNumber(limits.hits),
                        },
                    )
                }}
            </p>
        </div>

        <form
            class="flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-end"
            role="search"
            @submit.prevent="submit"
        >
            <div
                v-if="workspace.schemas.length > 1"
                class="flex flex-col gap-1.5"
            >
                <Label for="search-schema" class="text-[13px]">{{
                    schemaWord
                }}</Label>
                <select
                    id="search-schema"
                    :class="[selectClass, 'font-mono']"
                    :value="workspace.schema ?? ''"
                    @change="chooseSchema"
                >
                    <option
                        v-for="schema in workspace.schemas"
                        :key="schema"
                        :value="schema"
                    >
                        {{ schema }}
                    </option>
                </select>
            </div>
            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                <Label for="search-term" class="text-[13px]">{{
                    t('Suchtext')
                }}</Label>
                <Input
                    id="search-term"
                    v-model="draft"
                    type="search"
                    maxlength="1000"
                    autocomplete="off"
                    :placeholder="
                        t('z. B. eine E-Mail-Adresse oder einen Namen')
                    "
                />
            </div>
            <Button type="submit" :disabled="searching || !draft.trim()">
                <Spinner v-if="searching" /><Search v-else />{{ t('Suchen') }}
            </Button>
        </form>

        <template v-if="outcome">
            <p class="text-sm text-text-secondary" role="status">
                {{ tc(':count Treffer|:count Treffer', outcome.hits) }}
                ·
                {{
                    t(':searched von :total Tabellen durchsucht in :ms ms', {
                        searched: outcome.searched,
                        total: outcome.total,
                        ms: formatNumber(outcome.durationMs),
                    })
                }}
            </p>

            <Notice v-if="outcome.stoppedBy" variant="warning">
                <b class="font-semibold">{{
                    outcome.stoppedBy === 'time'
                        ? t('Zeitgrenze erreicht.')
                        : t('Treffergrenze erreicht.')
                }}</b>
                {{
                    tc(
                        ':count Tabelle blieb ungesucht: :tables. Suchen Sie genauer oder in einer Tabelle.|:count Tabellen blieben ungesucht: :tables. Suchen Sie genauer oder in einer Tabelle.',
                        outcome.unsearched.length,
                        { tables: outcome.unsearched.join(', ') },
                    )
                }}
            </Notice>

            <Notice
                v-for="failure in outcome.failed"
                :key="failure.table"
                variant="danger"
            >
                <b class="font-mono font-semibold">{{ failure.table }}</b>
                {{ failure.message }}
            </Notice>

            <ul v-if="outcome.tables.length" class="flex flex-col gap-2">
                <li v-for="hit in outcome.tables" :key="hit.table">
                    <Link
                        :href="
                            dataHref(workspace, hit.table, {
                                q: term.trim(),
                            })
                        "
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-3 py-2.5 text-sm outline-none hover:border-text-secondary focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <Table2 class="size-4 shrink-0 text-text-secondary" />
                        <span class="min-w-0 truncate font-mono font-medium">{{
                            hit.table
                        }}</span>
                        <span class="tabular-nums">{{
                            hit.more
                                ? t('über :count Treffer', {
                                      count: formatNumber(hit.hits),
                                  })
                                : tc(':count Treffer|:count Treffer', hit.hits)
                        }}</span>
                        <span
                            class="min-w-0 basis-full truncate text-xs text-muted-foreground sm:basis-auto"
                            >{{
                                t('in :columns', {
                                    columns: hit.columns.join(', '),
                                })
                            }}</span
                        >
                        <span
                            class="ml-auto inline-flex items-center gap-1 text-primary"
                            >{{ t('im Raster zeigen')
                            }}<ArrowRight class="size-3.5"
                        /></span>
                    </Link>
                </li>
            </ul>

            <p
                v-else-if="!outcome.stoppedBy"
                class="rounded-lg border border-dashed px-6 py-10 text-center text-sm text-text-secondary"
            >
                {{ t('Keine Tabelle enthält diesen Text.') }}
            </p>
        </template>
    </div>
</template>
