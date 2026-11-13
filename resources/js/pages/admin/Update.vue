<script setup lang="ts">
import { Deferred, Form, Head, router } from '@inertiajs/vue3';
import {
    Circle,
    CircleArrowUp,
    CircleCheck,
    CircleX,
    ExternalLink,
    RefreshCw,
} from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import AdminHeader from '@/components/admin/AdminHeader.vue';
import HelpLink from '@/components/HelpLink.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { runUpdateStep, UPDATE_STEPS, wait } from '@/lib/update';
import type { UpdateStep } from '@/lib/update';
import { check } from '@/routes/admin/update';
import type {
    AdminTabs,
    UpdateBlocker,
    UpdateState,
    UpdateStatus,
} from '@/types';

/**
 * Verwaltung · Aktualisierung (B-09): installierte und neueste Version, was hier dagegen spricht, und die vier Schritte
 * mit Fortschritt. Der Stand der Versionen kommt nachgeladen — die Seite wartet nicht auf GitHub.
 */
const props = defineProps<{
    tabs: AdminTabs;
    current: string;
    /** Automatisch nachsehen (PHPDBADMIN_UPDATE_CHECK). */
    automatic: boolean;
    /** Unabhängig vom Release: Git, Schreibrechte, Erweiterungen, OPcache. */
    blockers: UpdateBlocker[];
    state: UpdateState | null;
    status?: UpdateStatus | null;
}>();

type Progress = 'pending' | 'running' | 'done' | 'failed';

const labels: Record<UpdateStep, string> = {
    download: t('Herunterladen und Signatur prüfen'),
    extract: t('Entpacken und prüfen'),
    replace: t('Programm austauschen'),
    finish: t('App-Datenbank anpassen und abschliessen'),
};

const confirming = ref(false);
const running = ref(false);
const failure = ref<string | null>(null);
const updatedTo = ref<string | null>(null);
const progress = reactive<Record<UpdateStep, Progress>>({
    download: 'pending',
    extract: 'pending',
    replace: 'pending',
    finish: 'pending',
});
const started = computed(() =>
    UPDATE_STEPS.some((step) => progress[step] !== 'pending'),
);
const pending = computed(() => props.state?.step === 'replaced');
const allBlockers = computed(() => [
    ...props.blockers,
    ...(props.status?.blockers ?? []),
]);

// Mitten im Austausch nicht wegnavigieren — der Browser fragt nach.
function keepOpen(event: BeforeUnloadEvent): void {
    event.preventDefault();
}

async function run(steps: UpdateStep[]): Promise<void> {
    confirming.value = false;
    failure.value = null;
    running.value = true;
    window.addEventListener('beforeunload', keepOpen);

    for (const step of UPDATE_STEPS) {
        progress[step] = steps.includes(step) ? 'pending' : 'done';
    }

    try {
        for (const step of steps) {
            progress[step] = 'running';
            const result = await runUpdateStep(step);

            if (!result.ok) {
                progress[step] = 'failed';
                failure.value = result.message ?? t('Unbekannter Fehler.');

                return;
            }

            progress[step] = 'done';

            if (step === 'replace' && result.wait) {
                await wait(result.wait * 1000);
            }

            if (step === 'finish') {
                updatedTo.value = result.version ?? null;
            }
        }
    } finally {
        running.value = false;
        window.removeEventListener('beforeunload', keepOpen);
    }

    // Neuer Code, neue Oberfläche: die Seite ganz neu laden.
    await wait(1500);
    window.location.reload();
}

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', keepOpen);
});

function reload(): void {
    window.location.reload();
}
</script>

<template>
    <Head :title="t('Aktualisierung')" />

    <div class="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-8">
        <AdminHeader :tabs="tabs" active="update" />

        <Notice v-if="pending && !started" variant="warning">
            <p>
                {{
                    t(
                        'Die Aktualisierung auf :version ist noch nicht abgeschlossen: Das Programm ist ausgetauscht, die App-Datenbank noch nicht angepasst. Bis dahin ist phpdbadmin für alle anderen gesperrt.',
                        { version: state?.to ?? '' },
                    )
                }}
            </p>
            <Button
                class="mt-2"
                size="sm"
                :disabled="running"
                @click="run(['finish'])"
                >{{ t('Jetzt abschliessen') }}</Button
            >
        </Notice>

        <Notice
            v-else-if="state?.step === 'failed' && !started"
            variant="danger"
        >
            <p>
                {{
                    t(
                        'Die Aktualisierung von :from auf :to ist gescheitert; :from läuft weiter.',
                        { from: state.from, to: state.to },
                    )
                }}
            </p>
            <p v-if="state.message" class="mt-1 text-text-secondary">
                {{ state.message }}
            </p>
        </Notice>

        <section class="rounded-lg border bg-background">
            <div class="flex items-center gap-2 border-b px-4 py-3">
                <CircleArrowUp class="size-4" />
                <h2 class="text-[15px] font-semibold">
                    {{ t('Version') }}
                </h2>
                <span class="ml-auto text-sm text-text-secondary"
                    >{{ t('installiert') }}
                    <b class="font-semibold text-foreground tabular-nums">{{
                        current
                    }}</b></span
                >
            </div>

            <div class="flex flex-col gap-3 p-4 text-sm">
                <Deferred data="status">
                    <template #fallback>
                        <div class="flex flex-col gap-2" aria-busy="true">
                            <Skeleton class="h-5 w-64" />
                            <Skeleton class="h-9 w-48" />
                        </div>
                    </template>

                    <template v-if="!status">
                        <p class="text-text-secondary">
                            {{
                                t(
                                    'Noch nicht nachgesehen — die automatische Prüfung ist ausgeschaltet (PHPDBADMIN_UPDATE_CHECK).',
                                )
                            }}
                        </p>
                    </template>

                    <Notice v-else-if="status.error" variant="warning">
                        <p>
                            {{
                                t(
                                    'Ob es eine neue Version gibt, liess sich nicht feststellen.',
                                )
                            }}
                        </p>
                        <p class="mt-1 text-text-secondary">
                            {{ status.error }}
                        </p>
                    </Notice>

                    <template v-else-if="status.available && status.latest">
                        <div
                            class="flex flex-wrap items-baseline gap-x-3 gap-y-1"
                        >
                            <p class="text-[15px]">
                                {{
                                    t('Version :version ist erschienen', {
                                        version: status.latest,
                                    })
                                }}<template v-if="status.published">
                                    ·
                                    {{ formatDate(status.published) }}</template
                                >
                            </p>
                            <a
                                v-if="status.notesUrl"
                                :href="status.notesUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline"
                                >{{ t('Was ist neu')
                                }}<ExternalLink class="size-3.5"
                            /></a>
                        </div>

                        <Notice v-if="allBlockers.length" variant="warning">
                            <p>
                                {{
                                    t(
                                        'Über die Oberfläche lässt sich phpdbadmin hier nicht aktualisieren:',
                                    )
                                }}
                            </p>
                            <ul class="mt-1 list-disc pl-5">
                                <li
                                    v-for="blocker in allBlockers"
                                    :key="blocker.key"
                                >
                                    {{ blocker.message }}
                                </li>
                            </ul>
                        </Notice>

                        <div
                            v-else-if="!pending && !started"
                            class="flex flex-wrap items-center gap-3"
                        >
                            <Button @click="confirming = true">
                                <CircleArrowUp />{{
                                    t('Auf :version aktualisieren', {
                                        version: status.latest,
                                    })
                                }}
                            </Button>
                            <span class="text-text-secondary">{{
                                t(
                                    'Signatur und Prüfsumme werden vorher geprüft; scheitert ein Schritt, läuft :version weiter.',
                                    { version: current },
                                )
                            }}</span>
                        </div>
                    </template>

                    <p v-else class="flex items-center gap-2">
                        <CircleCheck class="size-4 text-success" />{{
                            t('phpdbadmin ist aktuell.')
                        }}
                    </p>

                    <div
                        class="flex flex-wrap items-center gap-3 text-text-secondary"
                    >
                        <span v-if="status">{{
                            t('Zuletzt nachgesehen :date', {
                                date: formatDateTime(status.checkedAt),
                            })
                        }}</span>
                        <span v-if="!automatic">{{
                            t('Automatisch nachsehen: aus')
                        }}</span>
                        <Form
                            v-bind="check.form()"
                            :options="{ preserveScroll: true }"
                            #default="{ processing }"
                        >
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                :disabled="processing || running"
                            >
                                <Spinner v-if="processing" /><RefreshCw
                                    v-else
                                />{{ t('Jetzt nachsehen') }}
                            </Button>
                        </Form>
                    </div>
                </Deferred>
            </div>
        </section>

        <section
            v-if="started"
            class="rounded-lg border bg-background"
            aria-live="polite"
        >
            <div class="flex items-center gap-2 border-b px-4 py-3">
                <h2 class="text-[15px] font-semibold">
                    {{ t('Fortschritt') }}
                </h2>
            </div>
            <ol class="flex flex-col gap-2 p-4 text-sm">
                <li
                    v-for="step in UPDATE_STEPS"
                    :key="step"
                    class="flex items-center gap-2"
                    :class="{
                        'text-text-secondary': progress[step] === 'pending',
                    }"
                >
                    <Spinner
                        v-if="progress[step] === 'running'"
                        class="size-4"
                    />
                    <CircleCheck
                        v-else-if="progress[step] === 'done'"
                        class="size-4 text-success"
                    />
                    <CircleX
                        v-else-if="progress[step] === 'failed'"
                        class="size-4 text-destructive"
                    />
                    <Circle v-else class="size-4" />
                    {{ labels[step] }}
                </li>
            </ol>
            <div class="flex flex-col gap-3 px-4 pb-4">
                <Notice v-if="failure" variant="danger">
                    <p>{{ failure }}</p>
                    <Button
                        class="mt-2"
                        size="sm"
                        variant="outline"
                        @click="reload"
                        >{{ t('Seite neu laden') }}</Button
                    >
                </Notice>
                <Notice v-else-if="updatedTo" variant="success">
                    <p>
                        {{
                            t(
                                'phpdbadmin ist auf :version aktualisiert. Die Seite lädt neu.',
                                { version: updatedTo },
                            )
                        }}
                    </p>
                </Notice>
            </div>
        </section>

        <section class="flex flex-col gap-2 text-sm text-text-secondary">
            <p>
                {{
                    t(
                        'phpdbadmin lädt die neue Version von GitHub, prüft ihre Signatur und tauscht das Programm aus. Während des Austauschs ist phpdbadmin für alle anderen einige Sekunden nicht erreichbar. Einstellungen (.env), App-Datenbank und Dateien unter storage bleiben, wie sie sind; die bisherige Version bleibt als Sicherung liegen.',
                    )
                }}
                <HelpLink anchor="aktualisieren" />
            </p>
            <p v-if="state?.step === 'finished'">
                {{
                    t('Zuletzt aktualisiert von :from auf :to, :date.', {
                        from: state.from,
                        to: state.to,
                        date: formatDateTime(state.finishedAt),
                    })
                }}
                <template v-if="state.backup">
                    {{
                        t('Sicherung der bisherigen Version: :path', {
                            path: state.backup,
                        })
                    }}
                </template>
            </p>
        </section>

        <Dialog v-model:open="confirming">
            <DialogContent>
                <DialogTitle>{{
                    t('Auf Version :version aktualisieren?', {
                        version: status?.latest ?? '',
                    })
                }}</DialogTitle>
                <DialogDescription class="flex flex-col gap-2">
                    <span>{{
                        t(
                            'Während das Programm ausgetauscht wird, ist phpdbadmin für alle anderen Konten nicht erreichbar — meist wenige Sekunden. Ein Export oder Import, der gerade im Hintergrund läuft, kann dabei abbrechen.',
                        )
                    }}</span>
                    <span>{{
                        t(
                            'Lassen Sie diese Seite offen, bis alle vier Schritte erledigt sind.',
                        )
                    }}</span>
                </DialogDescription>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">{{
                            t('Abbrechen')
                        }}</Button>
                    </DialogClose>
                    <Button type="button" @click="run(UPDATE_STEPS)"
                        ><CircleArrowUp />{{ t('Aktualisieren') }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
