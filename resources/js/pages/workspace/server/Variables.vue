<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import Notice from '@/components/Notice.vue';
import ServerHeader from '@/components/server/ServerHeader.vue';
import { Input } from '@/components/ui/input';
import { formatNumber, t } from '@/lib/i18n';
import type { ServerMeta, ServerSetting, Workspace } from '@/types';

/** Variablen und Status (W-03): nur lesen, mit Suche über Name und Wert. */
const props = defineProps<{
    workspace: Workspace;
    server: ServerMeta;
    variables: ServerSetting[];
    status: ServerSetting[];
}>();

const kind = ref<'variables' | 'status'>('variables');
const search = ref('');

const source = computed(() =>
    kind.value === 'variables' ? props.variables : props.status,
);
const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term === ''
        ? source.value
        : source.value.filter(
              (setting) =>
                  setting.name.toLowerCase().includes(term) ||
                  setting.value.toLowerCase().includes(term) ||
                  (setting.description?.toLowerCase().includes(term) ?? false),
          );
});

const kinds = computed(() => [
    {
        key: 'variables' as const,
        label: props.server.hasServer ? t('Variablen') : t('PRAGMAs'),
        count: props.variables.length,
    },
    {
        key: 'status' as const,
        label: props.server.hasServer
            ? t('Status')
            : t('Optionen der Bibliothek'),
        count: props.status.length,
    },
]);
</script>

<template>
    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <ServerHeader
            :workspace="workspace"
            :server="server"
            :title="t('Variablen und Status')"
        />

        <div class="flex flex-wrap items-center gap-2">
            <div
                class="flex gap-1 rounded-lg border bg-muted p-0.5"
                role="radiogroup"
                :aria-label="t('Anzeigen')"
            >
                <button
                    v-for="option in kinds"
                    :key="option.key"
                    type="button"
                    role="radio"
                    :aria-checked="kind === option.key"
                    :class="[
                        'rounded-md px-3 py-1 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        kind === option.key
                            ? 'bg-background font-semibold shadow-xs'
                            : 'text-text-secondary hover:text-foreground',
                    ]"
                    @click="kind = option.key"
                >
                    {{ option.label }}
                    <span
                        class="font-normal text-muted-foreground tabular-nums"
                        >{{ formatNumber(option.count) }}</span
                    >
                </button>
            </div>
            <div class="relative w-full sm:ml-auto sm:w-72">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    :placeholder="t('Name oder Wert suchen')"
                    :aria-label="t('Name oder Wert suchen')"
                    class="pl-8"
                />
            </div>
        </div>

        <Notice v-if="!server.hasServer && kind === 'status'">
            {{
                t(
                    'SQLite hat keinen laufenden Server und damit keinen Status — hier steht, wie die Bibliothek gebaut ist.',
                )
            }}
        </Notice>

        <p class="text-xs text-text-secondary tabular-nums" aria-live="polite">
            {{
                search.trim() === ''
                    ? t(':count Einträge, nur lesen', {
                          count: formatNumber(source.length),
                      })
                    : t(':shown von :count Einträgen', {
                          shown: formatNumber(visible.length),
                          count: formatNumber(source.length),
                      })
            }}
        </p>

        <dl
            v-if="visible.length"
            class="flex flex-col rounded-lg border text-sm"
        >
            <div
                v-for="setting in visible"
                :key="setting.name"
                class="grid gap-x-4 gap-y-0.5 border-b px-4 py-2 last:border-b-0 md:grid-cols-[minmax(12rem,2fr)_3fr]"
            >
                <dt class="font-mono font-medium wrap-break-word">
                    {{ setting.name }}
                </dt>
                <dd class="flex min-w-0 flex-col gap-0.5">
                    <span class="font-mono wrap-break-word">{{
                        setting.value === '' ? '—' : setting.value
                    }}</span>
                    <span
                        v-if="setting.description"
                        class="text-xs text-text-secondary"
                        >{{ setting.description }}</span
                    >
                </dd>
            </div>
        </dl>
        <p
            v-else
            class="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-text-secondary"
        >
            {{ t('Nichts gefunden.') }}
        </p>
    </div>
</template>
