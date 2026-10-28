<script setup lang="ts">
import { Code, Plus, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { filterLabel, totalLabel } from '@/lib/grid';
import { t } from '@/lib/i18n';
import type {
    FilterOperatorOption,
    GridColumn,
    GridFilter,
    GridQueryState,
} from '@/types';

/**
 * Filter (D-02, Wireframe 05): geführt als Chips (Spalte, Operator, Wert, und/oder), als freies WHERE und als Suche
 * über die Textspalten. Rechts die Zeilenzahl — genau oder geschätzt, «genau zählen» auf Wunsch (D-06).
 */
const props = defineProps<{
    columns: GridColumn[];
    query: GridQueryState;
    operators: FilterOperatorOption[];
    total: { count: number | null; exact: boolean } | null;
    counting: boolean;
}>();

const emit = defineEmits<{
    apply: [state: GridQueryState];
    count: [];
}>();

const filters = computed(() => props.query.f ?? []);
const adding = ref(false);
const draft = ref<GridFilter>({ c: '', o: 'eq', v: '' });
const whereOpen = ref(!!props.query.where);
const where = ref(props.query.where ?? '');
const search = ref(props.query.q ?? '');

watch(
    () => props.query,
    (query) => {
        where.value = query.where ?? '';
        search.value = query.q ?? '';
        whereOpen.value = whereOpen.value || !!query.where;
    },
);

const draftOperator = computed(() =>
    props.operators.find((operator) => operator.value === draft.value.o),
);

function openAdd(open: boolean): void {
    adding.value = open;

    if (open) {
        draft.value = { c: props.columns[0]?.name ?? '', o: 'eq', v: '' };
    }
}

function addFilter(): void {
    if (!draft.value.c) {
        return;
    }

    const filter: GridFilter = { c: draft.value.c, o: draft.value.o };

    if (draftOperator.value?.needsValue) {
        filter.v = draft.value.v ?? '';
    }

    if (draft.value.b === 'or' && filters.value.length) {
        filter.b = 'or';
    }

    adding.value = false;
    emit('apply', { ...props.query, f: [...filters.value, filter] });
}

function removeFilter(index: number): void {
    emit('apply', {
        ...props.query,
        f: filters.value.filter((_, position) => position !== index),
    });
}

function applyWhere(): void {
    emit('apply', { ...props.query, where: where.value.trim() || undefined });
}

function toggleWhere(): void {
    whereOpen.value = !whereOpen.value;

    if (!whereOpen.value && props.query.where) {
        emit('apply', { ...props.query, where: undefined });
    }
}

function applySearch(): void {
    if ((search.value.trim() || undefined) !== props.query.q) {
        emit('apply', { ...props.query, q: search.value.trim() || undefined });
    }
}

function clearSearch(): void {
    search.value = '';
    applySearch();
}
</script>

<template>
    <div class="flex flex-col gap-2 border-b px-4 py-2.5 sm:px-6">
        <div class="flex flex-wrap items-center gap-2">
            <template v-for="(filter, index) in filters" :key="index">
                <span
                    v-if="index > 0"
                    class="text-xs text-text-secondary uppercase"
                    >{{ filter.b === 'or' ? t('oder') : t('und') }}</span
                >
                <span
                    class="inline-flex h-8 max-w-full items-center gap-1.5 rounded-full border bg-background pr-1 pl-3 font-mono text-[13px]"
                >
                    <span class="truncate">{{
                        filterLabel(filter, operators)
                    }}</span>
                    <button
                        type="button"
                        class="inline-grid size-6 shrink-0 place-items-center rounded-full text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-label="
                            t('Filter :filter entfernen', {
                                filter: filterLabel(filter, operators),
                            })
                        "
                        @click="removeFilter(index)"
                    >
                        <X class="size-3.5" />
                    </button>
                </span>
            </template>

            <Popover :open="adding" @update:open="openAdd">
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1 rounded-full border border-dashed px-3 text-[13px] text-primary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <Plus class="size-3.5" />{{ t('Filter') }}
                    </button>
                </PopoverTrigger>
                <PopoverContent class="w-[min(22rem,calc(100vw-2rem))]">
                    <form
                        class="flex flex-col gap-3"
                        @submit.prevent="addFilter"
                    >
                        <div
                            v-if="filters.length"
                            role="radiogroup"
                            :aria-label="t('Verknüpfung')"
                            class="grid grid-cols-2 gap-1 rounded-md bg-muted p-1 text-[13px]"
                        >
                            <button
                                v-for="option in [
                                    { value: undefined, label: t('und') },
                                    { value: 'or' as const, label: t('oder') },
                                ]"
                                :key="option.label"
                                type="button"
                                role="radio"
                                :aria-checked="draft.b === option.value"
                                :class="[
                                    'h-7 rounded-sm',
                                    draft.b === option.value &&
                                        'bg-background font-semibold shadow-sm',
                                ]"
                                @click="draft.b = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                        <label class="flex flex-col gap-1 text-[13px]">
                            <span class="font-medium">{{ t('Spalte') }}</span>
                            <select
                                v-model="draft.c"
                                class="h-9 rounded-md border bg-background px-2 font-mono text-sm"
                            >
                                <option
                                    v-for="column in columns"
                                    :key="column.name"
                                    :value="column.name"
                                >
                                    {{ column.name }}
                                </option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-[13px]">
                            <span class="font-medium">{{
                                t('Bedingung')
                            }}</span>
                            <select
                                v-model="draft.o"
                                class="h-9 rounded-md border bg-background px-2 text-sm"
                            >
                                <option
                                    v-for="operator in operators"
                                    :key="operator.value"
                                    :value="operator.value"
                                >
                                    {{ operator.symbol }}
                                </option>
                            </select>
                        </label>
                        <label
                            v-if="draftOperator?.needsValue"
                            class="flex flex-col gap-1 text-[13px]"
                        >
                            <span class="font-medium">{{ t('Wert') }}</span>
                            <input
                                v-model="draft.v"
                                v-focus
                                type="text"
                                class="h-9 rounded-md border bg-background px-2 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                        </label>
                        <Button type="submit" size="sm" class="self-end">{{
                            t('Filtern')
                        }}</Button>
                    </form>
                </PopoverContent>
            </Popover>

            <button
                type="button"
                :class="[
                    'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-[13px] outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring',
                    whereOpen && 'border-primary bg-accent font-medium',
                ]"
                :aria-pressed="whereOpen"
                @click="toggleWhere"
            >
                <Code class="size-3.5" />{{ t('WHERE frei') }}
            </button>

            <form
                class="relative w-full sm:w-64"
                role="search"
                @submit.prevent="applySearch"
            >
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('In Textspalten suchen')"
                    :aria-label="t('In Textspalten suchen')"
                    class="h-8 w-full rounded-md border bg-background pr-7 pl-8 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @blur="applySearch"
                />
                <button
                    v-if="query.q"
                    type="button"
                    class="absolute top-1/2 right-1 inline-grid size-6 -translate-y-1/2 place-items-center rounded-sm text-text-secondary hover:bg-muted"
                    :aria-label="t('Suche leeren')"
                    @click="clearSearch"
                >
                    <X class="size-3.5" />
                </button>
            </form>

            <span
                v-if="total"
                class="ml-auto flex items-center gap-1.5 text-[13px] text-text-secondary"
                aria-live="polite"
            >
                <b class="font-semibold text-foreground tabular-nums">{{
                    totalLabel(total)
                }}</b>
                <template v-if="total.exact"
                    >· {{ t('genau gezählt') }}</template
                >
                <template v-else>
                    <template v-if="total.count !== null"
                        >· {{ t('geschätzt') }}</template
                    >
                    ·
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-primary underline outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                        :disabled="counting"
                        @click="emit('count')"
                    >
                        <Spinner v-if="counting" class="size-3" />{{
                            t('genau zählen')
                        }}
                    </button>
                </template>
            </span>
        </div>

        <form
            v-if="whereOpen"
            class="flex items-center gap-2"
            @submit.prevent="applyWhere"
        >
            <label class="flex flex-1 items-center gap-2">
                <span class="font-mono text-[13px] text-text-secondary"
                    >WHERE</span
                >
                <input
                    v-model="where"
                    v-focus
                    type="text"
                    spellcheck="false"
                    autocomplete="off"
                    :placeholder="
                        t('z. B. total > 100 and status <> \'storniert\'')
                    "
                    :aria-label="t('Freie Bedingung')"
                    class="h-8 min-w-0 flex-1 rounded-md border bg-background px-2 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </label>
            <Button type="submit" size="sm" variant="outline">{{
                t('Anwenden')
            }}</Button>
        </form>
    </div>
</template>
