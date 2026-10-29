<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Ban,
    CircleAlert,
    CircleCheck,
    Download,
    FileUp,
    Layers,
    LoaderCircle,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { formatBytes, formatDateTime, formatTime } from '@/lib/format';
import { formatNumber, t, tc } from '@/lib/i18n';
import type { TransferSummary } from '@/types';

/**
 * Aufträge im Hintergrund (Wireframe 11): Fortschritt mit Zeilen, Abbrechen, Herunterladen bis zur Frist, Fehler in
 * Klartext. Die Liste kommt aus der Seite und wird abgefragt, solange etwas läuft (useTransferPolling).
 */
defineProps<{
    transfers: TransferSummary[];
}>();

function until(iso: string): string {
    const value = new Date(iso);
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);

    if (value.toDateString() === new Date().toDateString()) {
        return t('heute :time', { time: formatTime(iso) });
    }

    if (value.toDateString() === tomorrow.toDateString()) {
        return t('morgen :time', { time: formatTime(iso) });
    }

    return formatDateTime(iso);
}

function description(transfer: TransferSummary): string {
    const kind =
        transfer.kind === 'import' && transfer.dryRun
            ? t('Probelauf')
            : transfer.kindLabel;

    return [kind, transfer.format.toUpperCase(), transfer.target]
        .filter(Boolean)
        .join(' · ');
}

function cancel(transfer: TransferSummary): void {
    if (transfer.cancelUrl) {
        router.post(transfer.cancelUrl, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <section
        class="flex flex-col overflow-hidden rounded-lg border bg-background"
        :aria-label="t('Aufträge')"
    >
        <h2
            class="flex items-center gap-2 border-b px-4 py-3 text-[15px] font-semibold"
        >
            <Layers class="size-4 text-text-secondary" />{{ t('Aufträge') }}
            <span class="text-xs font-normal text-muted-foreground">{{
                transfers.length
            }}</span>
        </h2>

        <p
            v-if="transfers.length === 0"
            class="px-4 py-6 text-center text-sm text-text-secondary"
        >
            {{
                t(
                    'Noch keine Aufträge. Exporte und Importe stehen hier 24 Stunden lang.',
                )
            }}
        </p>

        <ul v-else class="divide-y">
            <li
                v-for="transfer in transfers"
                :key="transfer.id"
                class="flex flex-col gap-1.5 px-4 py-3"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <LoaderCircle
                        v-if="transfer.active"
                        class="size-4 shrink-0 animate-spin text-primary"
                        :aria-label="transfer.statusLabel"
                    />
                    <CircleCheck
                        v-else-if="transfer.status === 'done'"
                        class="size-4 shrink-0 text-success"
                        :aria-label="transfer.statusLabel"
                    />
                    <CircleAlert
                        v-else-if="transfer.status === 'failed'"
                        class="size-4 shrink-0 text-destructive"
                        :aria-label="transfer.statusLabel"
                    />
                    <Ban
                        v-else-if="transfer.status === 'cancelled'"
                        class="size-4 shrink-0 text-muted-foreground"
                        :aria-label="transfer.statusLabel"
                    />
                    <FileUp
                        v-else
                        class="size-4 shrink-0 text-muted-foreground"
                        :aria-label="transfer.statusLabel"
                    />
                    <span class="min-w-0 truncate font-mono text-sm">{{
                        transfer.fileName
                    }}</span>
                    <span
                        v-if="transfer.active"
                        class="ml-auto shrink-0 text-xs text-text-secondary tabular-nums"
                        >{{ transfer.progress }} %</span
                    >
                    <Button
                        v-else-if="transfer.downloadUrl"
                        variant="outline"
                        size="sm"
                        class="ml-auto"
                        as-child
                    >
                        <a :href="transfer.downloadUrl" download
                            ><Download />{{ t('Herunterladen') }}</a
                        >
                    </Button>
                    <Button
                        v-else-if="transfer.showUrl"
                        variant="outline"
                        size="sm"
                        class="ml-auto"
                        as-child
                    >
                        <Link :href="transfer.showUrl">{{ t('Öffnen') }}</Link>
                    </Button>
                </div>

                <p class="truncate text-xs text-text-secondary">
                    {{ description(transfer) }}
                </p>

                <template v-if="transfer.active">
                    <div
                        class="h-1.5 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="transfer.progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="
                            t('Fortschritt von :file', {
                                file: transfer.fileName ?? '',
                            })
                        "
                    >
                        <div
                            class="h-full rounded-full bg-primary transition-[width]"
                            :style="{ width: `${transfer.progress}%` }"
                        />
                    </div>
                    <div
                        class="flex items-center gap-2 text-xs text-text-secondary"
                    >
                        <span class="tabular-nums">
                            <template v-if="transfer.totalRows">{{
                                t(':rows von ≈ :total Zeilen', {
                                    rows: formatNumber(transfer.rows),
                                    total: formatNumber(transfer.totalRows),
                                })
                            }}</template>
                            <template
                                v-else-if="transfer.status === 'queued'"
                                >{{ transfer.statusLabel }}</template
                            >
                            <template v-else>{{
                                tc(':count Zeile|:count Zeilen', transfer.rows)
                            }}</template>
                        </span>
                        <button
                            v-if="transfer.cancelUrl"
                            type="button"
                            class="ml-auto underline underline-offset-2 hover:text-foreground"
                            @click="cancel(transfer)"
                        >
                            {{ t('Abbrechen') }}
                        </button>
                    </div>
                    <p
                        v-if="transfer.waitingForWorker"
                        class="text-xs text-warning"
                    >
                        {{
                            t(
                                'Wartet seit über einer Minute — läuft der Worker (php artisan queue:work)?',
                            )
                        }}
                    </p>
                </template>

                <p
                    v-else-if="transfer.status === 'done'"
                    class="text-xs text-text-secondary"
                >
                    {{
                        [
                            t('fertig :time', {
                                time: formatTime(transfer.finishedAt),
                            }),
                            transfer.fileSize !== null &&
                            transfer.kind === 'export'
                                ? formatBytes(transfer.fileSize)
                                : null,
                            tc(':count Zeile|:count Zeilen', transfer.rows),
                            transfer.downloadUrl
                                ? t('bereit bis :time', {
                                      time: until(transfer.expiresAt),
                                  })
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </p>

                <div
                    v-else-if="transfer.error"
                    class="flex flex-col gap-1 text-xs"
                >
                    <p class="text-destructive">{{ transfer.error.text }}</p>
                    <details v-if="transfer.error.detail">
                        <summary class="cursor-pointer text-text-secondary">
                            {{ t('Meldung des Servers') }}
                        </summary>
                        <p
                            class="mt-1 font-mono break-words text-text-secondary"
                        >
                            {{ transfer.error.detail }}
                        </p>
                    </details>
                </div>

                <p
                    v-else-if="transfer.status !== 'draft'"
                    class="text-xs text-text-secondary"
                >
                    {{ transfer.statusLabel }}
                </p>
            </li>
        </ul>
    </section>
</template>
