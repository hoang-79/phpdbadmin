<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CircleStop, OctagonX, RefreshCw, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import Notice from '@/components/Notice.vue';
import ConfirmChange from '@/components/server/ConfirmChange.vue';
import ServerHeader from '@/components/server/ServerHeader.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber, t } from '@/lib/i18n';
import { formatDuration } from '@/lib/server';
import { end } from '@/routes/workspace/server/processes';
import type { ServerMeta, ServerProcess, Workspace } from '@/types';

/**
 * Prozesse und Sitzungen (W-02): Kennung, Konto, Herkunft, Datenbank, Zustand, Dauer, laufende Anweisung (gekürzt).
 * Beenden nur nach Bestätigung — die laufende Anweisung oder die ganze Sitzung; die eigene Sitzung nie.
 */
const props = defineProps<{
    workspace: Workspace;
    server: ServerMeta;
    processes: ServerProcess[] | null;
}>();

const search = ref('');
const hideIdle = ref(false);
const refreshing = ref(false);
const chosen = ref<{ process: ServerProcess; wholeSession: boolean } | null>(
    null,
);
const form = useForm({ mode: 'session', confirmation: '' });
// «process» (eigene Sitzung) und «server» (Meldung des Servers) kommen nur vom Server, nicht aus dem Formular.
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const idleCommands = ['sleep', 'idle', 'daemon'];

function isIdle(process: ServerProcess): boolean {
    return idleCommands.includes((process.command ?? '').toLowerCase());
}

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (props.processes ?? []).filter(
        (process) =>
            !(hideIdle.value && isIdle(process) && !process.own) &&
            (term === '' ||
                [
                    String(process.id),
                    process.user,
                    process.host,
                    process.program,
                    process.database,
                    process.command,
                    process.state,
                    process.statement,
                ].some((value) => value?.toLowerCase().includes(term))),
    );
});

const dialogOpen = computed({
    get: () => chosen.value !== null,
    set: (open: boolean) => {
        if (!open) {
            chosen.value = null;
            form.clearErrors();
        }
    },
});

function choose(process: ServerProcess, wholeSession: boolean): void {
    form.clearErrors();
    chosen.value = { process, wholeSession };
}

function confirm(confirmation: string): void {
    if (!chosen.value) {
        return;
    }

    form.mode = chosen.value.wholeSession ? 'session' : 'query';
    form.confirmation = confirmation;
    form.post(
        end.url({
            connection: props.workspace.connection.id,
            process: chosen.value.process.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                chosen.value = null;
            },
        },
    );
}

function refresh(): void {
    router.reload({
        only: ['processes'],
        onStart: () => {
            refreshing.value = true;
        },
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

function blockedReason(process: ServerProcess): string | undefined {
    if (process.own) {
        return t('Das ist die Sitzung, über die phpdbadmin gerade liest.');
    }

    if (!props.server.canChange) {
        return props.server.blocked === 'read_only'
            ? t('Die Verbindung ist schreibgeschützt.')
            : t('Dafür braucht es das Recht «verwalten».');
    }

    return undefined;
}
</script>

<template>
    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <ServerHeader
            :workspace="workspace"
            :server="server"
            :title="t('Prozesse und Sitzungen')"
        />

        <Notice v-if="processes === null">
            <b class="font-semibold">{{
                t('SQLite hat keine Serverprozesse.')
            }}</b>
            {{
                t(
                    'Die Datei wird für jede Anfrage direkt geöffnet und gleich wieder geschlossen — es gibt keine Sitzungen, die man beenden könnte.',
                )
            }}
        </Notice>

        <template v-else>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div class="relative w-full sm:w-72">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        :placeholder="t('Konto, Datenbank, Anweisung …')"
                        :aria-label="t('Prozesse durchsuchen')"
                        class="pl-8"
                    />
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox v-model="hideIdle" />{{ t('Ruhende ausblenden') }}
                </label>
                <span
                    class="text-xs text-text-secondary tabular-nums sm:ml-auto"
                    >{{
                        t(':shown von :count', {
                            shown: formatNumber(visible.length),
                            count: formatNumber(processes.length),
                        })
                    }}</span
                >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <Spinner v-if="refreshing" /><RefreshCw v-else />{{
                        t('Aktualisieren')
                    }}
                </Button>
            </div>

            <ul class="flex flex-col gap-2">
                <li
                    v-for="process in visible"
                    :key="process.id"
                    :class="[
                        'flex flex-col gap-2 rounded-lg border px-4 py-3',
                        process.own && 'border-primary bg-accent',
                    ]"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-mono font-semibold tabular-nums"
                            >#{{ process.id }}</span
                        >
                        <span class="font-mono text-sm wrap-break-word"
                            >{{ process.user
                            }}<span
                                v-if="process.host"
                                class="text-text-secondary"
                                >@{{ process.host }}</span
                            ></span
                        >
                        <span
                            v-if="process.own"
                            class="rounded-full border border-primary px-2 py-px text-xs font-medium text-primary"
                            >{{ t('phpdbadmin · diese Sitzung') }}</span
                        >
                        <span
                            v-else-if="process.program"
                            class="rounded-sm border px-1.5 py-px text-xs text-text-secondary"
                            >{{ process.program }}</span
                        >
                        <span
                            class="ml-auto text-xs text-text-secondary tabular-nums"
                            :title="t('Dauer im aktuellen Zustand')"
                            >{{ formatDuration(process.seconds) }}</span
                        >
                    </div>
                    <div
                        class="flex flex-wrap gap-x-4 gap-y-0.5 text-[13px] text-text-secondary"
                    >
                        <span
                            >{{ t('Datenbank') }}
                            <span class="font-mono text-foreground">{{
                                process.database ?? '—'
                            }}</span></span
                        >
                        <span
                            >{{ t('Zustand') }}
                            <span class="font-mono text-foreground">{{
                                [process.command, process.state]
                                    .filter(Boolean)
                                    .join(' · ') || '—'
                            }}</span></span
                        >
                    </div>
                    <code
                        v-if="process.statement"
                        class="line-clamp-3 rounded-md bg-muted px-2.5 py-1.5 font-mono text-xs leading-[18px] wrap-break-word"
                        :title="process.statement"
                        >{{ process.statement }}</code
                    >
                    <div class="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                blockedReason(process) !== undefined ||
                                isIdle(process)
                            "
                            :title="
                                blockedReason(process) ??
                                (isIdle(process)
                                    ? t('Gerade läuft keine Anweisung.')
                                    : undefined)
                            "
                            @click="choose(process, false)"
                        >
                            <CircleStop />{{ t('Anweisung abbrechen') }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="text-destructive"
                            :disabled="blockedReason(process) !== undefined"
                            :title="blockedReason(process)"
                            @click="choose(process, true)"
                        >
                            <OctagonX />{{ t('Sitzung beenden') }}
                        </Button>
                    </div>
                </li>
            </ul>
            <p
                v-if="!visible.length"
                class="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-text-secondary"
            >
                {{ t('Kein Prozess passt zur Suche.') }}
            </p>
        </template>

        <ConfirmChange
            v-model:open="dialogOpen"
            :title="
                chosen?.wholeSession
                    ? t('Sitzung :id beenden?', {
                          id: chosen?.process.id ?? '',
                      })
                    : t('Anweisung in Sitzung :id abbrechen?', {
                          id: chosen?.process.id ?? '',
                      })
            "
            :description="
                chosen?.wholeSession
                    ? t(
                          'Die Verbindung wird getrennt; eine offene Transaktion wird zurückgerollt.',
                      )
                    : t(
                          'Nur die laufende Anweisung bricht ab; die Sitzung bleibt bestehen.',
                      )
            "
            :statements="
                chosen
                    ? [
                          chosen.wholeSession
                              ? chosen.process.endStatements.session
                              : chosen.process.endStatements.query,
                      ]
                    : []
            "
            :confirm-name="server.confirmName"
            :confirm-label="
                chosen?.wholeSession
                    ? t('Sitzung beenden')
                    : t('Anweisung abbrechen')
            "
            :destructive="chosen?.wholeSession ?? false"
            :processing="form.processing"
            :error="errors.process ?? errors.server ?? null"
            :confirmation-error="errors.confirmation ?? null"
            @confirm="confirm"
        />
    </div>
</template>
