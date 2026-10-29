<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleX,
    Download,
    Lock,
    Search,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, reactive } from 'vue';
import AdminHeader from '@/components/admin/AdminHeader.vue';
import ConnectionBadge from '@/components/connections/ConnectionBadge.vue';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import { formatNumber, t, tc } from '@/lib/i18n';
import { exportMethod as exportCsv, index } from '@/routes/admin/audit';
import type {
    AdminTabs,
    AuditFilters,
    AuditPage,
    AuditResult,
    FilterOption,
} from '@/types';

/**
 * Verwaltung · Protokoll (B-04, Wireframe 14): wer, wann, Verbindung, Art, Anweisung mit Platzhaltern, betroffene
 * Zeilen, Ergebnis. Filter und Seite stehen in der Adresse.
 */
const props = defineProps<{
    tabs: AdminTabs;
    filters: AuditFilters;
    entries: AuditPage;
    options: {
        accounts: FilterOption<number>[];
        connections: FilterOption[];
        categories: FilterOption[];
    };
    retentionDays: number;
}>();

const form = reactive({
    account: props.filters.account?.toString() ?? '',
    connection: props.filters.connection ?? '',
    category: props.filters.category ?? '',
    period: props.filters.period,
    search: props.filters.search ?? '',
});

const periods = computed<FilterOption[]>(() => [
    { value: '1', label: t('Letzte 24 Stunden') },
    { value: '7', label: t('Letzte 7 Tage') },
    { value: '30', label: t('Letzte 30 Tage') },
    { value: '365', label: t('Letztes Jahr') },
    { value: 'all', label: t('Ganzer Zeitraum') },
]);

const query = computed(() =>
    Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== ''),
    ),
);

function apply(): void {
    router.get(index.url(), query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const results: Record<
    AuditResult,
    { icon: Component; label: () => string; class: string }
> = {
    ok: {
        icon: CircleCheck,
        label: () => t('ausgeführt'),
        class: 'text-success',
    },
    rejected: {
        icon: CircleX,
        label: () => t('abgelehnt'),
        class: 'text-destructive',
    },
    error: {
        icon: TriangleAlert,
        label: () => t('Fehler'),
        class: 'text-destructive',
    },
    read_only: {
        icon: Lock,
        label: () => t('schreibgeschützt'),
        class: 'text-destructive',
    },
};

const selectClass =
    'h-9 rounded-md border bg-background px-2.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring';
</script>

<template>
    <Head :title="t('Protokoll')" />

    <div class="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-8">
        <AdminHeader :tabs="tabs" active="audit" />

        <form
            class="flex flex-wrap items-center gap-2"
            role="search"
            @submit.prevent="apply"
        >
            <select
                v-model="form.period"
                :class="selectClass"
                :aria-label="t('Zeitraum')"
                @change="apply"
            >
                <option
                    v-for="period in periods"
                    :key="period.value"
                    :value="period.value"
                >
                    {{ period.label }}
                </option>
            </select>
            <select
                v-model="form.account"
                :class="selectClass"
                :aria-label="t('Konto')"
                @change="apply"
            >
                <option value="">{{ t('Konto: alle') }}</option>
                <option
                    v-for="account in options.accounts"
                    :key="account.value"
                    :value="account.value.toString()"
                >
                    {{ account.label }}
                </option>
            </select>
            <select
                v-model="form.connection"
                :class="selectClass"
                :aria-label="t('Verbindung')"
                @change="apply"
            >
                <option value="">{{ t('Verbindung: alle') }}</option>
                <option
                    v-for="connection in options.connections"
                    :key="connection.value"
                    :value="connection.value"
                >
                    {{ connection.label }}
                </option>
            </select>
            <select
                v-model="form.category"
                :class="selectClass"
                :aria-label="t('Art')"
                @change="apply"
            >
                <option value="">{{ t('Art: alle') }}</option>
                <option
                    v-for="category in options.categories"
                    :key="category.value"
                    :value="category.value"
                >
                    {{ category.label }}
                </option>
            </select>
            <div class="relative w-full sm:w-72">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="form.search"
                    type="search"
                    maxlength="200"
                    class="pl-8"
                    :placeholder="t('Anweisung oder Tabelle suchen')"
                    :aria-label="t('Anweisung oder Tabelle suchen')"
                    @search="apply"
                />
            </div>
            <a
                :href="exportCsv.url({ query: query })"
                class="inline-flex h-9 items-center gap-2 rounded-md border px-3 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring sm:ml-auto"
                ><Download class="size-4" />{{ t('Als CSV exportieren') }}</a
            >
        </form>

        <div
            class="flex items-start gap-2.5 rounded-md border border-l-[3px] border-l-primary px-3 py-2.5 text-[13px] leading-[18px]"
        >
            <ShieldCheck class="mt-px size-4 shrink-0 text-primary" />
            <p>
                {{
                    t(
                        'Jede schreibende Aktion steht hier genau einmal — mit wer, wann, Verbindung, SQL und betroffenen Zeilen. Werte erscheinen nie, nur Platzhalter. Fehlversuche bei der Anmeldung stehen mit gehashter Adresse.',
                    )
                }}
                <b v-if="retentionDays > 0">{{
                    tc(
                        'Einträge werden nach :count Tag gelöscht (Einstellung der Installation).|Einträge werden nach :count Tagen gelöscht (Einstellung der Installation).',
                        retentionDays,
                    )
                }}</b>
                <b v-else>{{
                    t(
                        'Einträge bleiben ohne Frist (Einstellung der Installation).',
                    )
                }}</b>
            </p>
        </div>

        <div
            v-if="entries.data.length"
            class="overflow-hidden rounded-lg border bg-background"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="hidden bg-muted text-[13px] text-text-secondary md:table-header-group"
                >
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ t('Zeit') }}</th>
                        <th class="px-3 py-2 font-medium">{{ t('Konto') }}</th>
                        <th class="px-3 py-2 font-medium">
                            {{ t('Verbindung') }}
                        </th>
                        <th class="px-3 py-2 font-medium">{{ t('Art') }}</th>
                        <th class="px-3 py-2 font-medium">
                            {{ t('Anweisung (Werte als ?)') }}
                        </th>
                        <th class="px-3 py-2 text-right font-medium">
                            {{ t('Zeilen') }}
                        </th>
                        <th class="px-3 py-2 font-medium">
                            {{ t('Ergebnis') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="entry in entries.data"
                        :key="entry.id"
                        class="flex flex-col gap-1 px-3 py-2.5 md:table-row"
                    >
                        <td
                            class="text-[13px] whitespace-nowrap text-text-secondary tabular-nums md:px-3 md:py-2 md:text-sm md:text-foreground"
                        >
                            {{ formatDateTime(entry.createdAt) }}
                        </td>
                        <td class="md:px-3 md:py-2">{{ entry.account }}</td>
                        <td class="md:px-3 md:py-2">
                            <template v-if="entry.connection">
                                <ConnectionBadge
                                    v-if="entry.connection.environment"
                                    :name="entry.connection.name"
                                    :environment="entry.connection.environment"
                                    :read-only="entry.connection.readOnly"
                                />
                                <span v-else class="text-text-secondary"
                                    >{{ entry.connection.name }}
                                    <span class="text-xs"
                                        >({{ t('entfernt') }})</span
                                    ></span
                                >
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="md:px-3 md:py-2">{{ entry.label }}</td>
                        <td class="min-w-0 md:max-w-md md:px-3 md:py-2">
                            <code
                                v-if="entry.sql"
                                class="font-mono text-[13px] wrap-break-word text-primary"
                                >{{ entry.sql }}</code
                            >
                            <span
                                v-if="entry.detail"
                                :class="[
                                    'text-[13px] wrap-break-word text-text-secondary',
                                    entry.sql && 'block',
                                ]"
                                >{{ entry.detail }}</span
                            >
                        </td>
                        <td class="tabular-nums md:px-3 md:py-2 md:text-right">
                            <span v-if="entry.affectedRows !== null"
                                >{{ formatNumber(entry.affectedRows) }}
                                <span class="text-text-secondary md:hidden">{{
                                    t('Zeilen')
                                }}</span></span
                            >
                            <span
                                v-else
                                class="hidden text-muted-foreground md:inline"
                                >—</span
                            >
                        </td>
                        <td class="md:px-3 md:py-2">
                            <span
                                :class="[
                                    'inline-flex items-center gap-1.5 whitespace-nowrap',
                                    results[entry.result].class,
                                ]"
                            >
                                <component
                                    :is="results[entry.result].icon"
                                    class="size-4"
                                />{{ results[entry.result].label() }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-else
            class="rounded-lg border border-dashed px-6 py-16 text-center text-sm text-text-secondary"
        >
            {{ t('Kein Eintrag passt zu den Filtern.') }}
        </p>

        <nav
            v-if="entries.total"
            class="flex flex-wrap items-center justify-center gap-3 text-[13px] text-text-secondary"
            :aria-label="t('Seiten')"
        >
            <Link
                v-if="entries.previousUrl"
                :href="entries.previousUrl"
                preserve-scroll
                class="text-primary underline"
                >{{ t('neuere') }}</Link
            >
            <span>{{
                t(':from–:to von :total Einträgen', {
                    from: formatNumber(entries.from ?? 0),
                    to: formatNumber(entries.to ?? 0),
                    total: formatNumber(entries.total),
                })
            }}</span>
            <Link
                v-if="entries.nextUrl"
                :href="entries.nextUrl"
                preserve-scroll
                class="text-primary underline"
                >{{ t('ältere') }}</Link
            >
        </nav>
    </div>
</template>
