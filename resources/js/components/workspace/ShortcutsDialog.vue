<script setup lang="ts">
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import HelpLink from '@/components/HelpLink.vue';
import { t } from '@/lib/i18n';

/** Kürzel-Übersicht (O-05): alles, was sich mit der Tastatur bedienen lässt. */
defineProps<{ open: boolean }>();
const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
const command = isMac ? '⌘' : 'Ctrl';

const groups = computed(() => [
    {
        title: t('Überall'),
        items: [
            { keys: [command, 'K'], label: t('Befehlspalette') },
            { keys: ['?'], label: t('Diese Übersicht') },
            { keys: ['/'], label: t('Objekte im Navigator filtern') },
            { keys: ['Esc'], label: t('Dialog oder Auswahl schliessen') },
        ],
    },
    {
        title: t('Datenraster'),
        items: [
            { keys: ['↑', '↓', '←', '→'], label: t('Zelle wechseln') },
            { keys: [t('Bild ↑'), t('Bild ↓')], label: t('Eine Seite weiter') },
            {
                keys: [t('Pos1'), t('Ende')],
                label: t('Anfang und Ende der Zeile'),
            },
            { keys: [command, t('Pos1')], label: t('Erste Zelle') },
            {
                keys: ['↵', 'F2'],
                label: t('Zelle bearbeiten (wenn erlaubt), sonst Zeile öffnen'),
            },
            { keys: [t('Leertaste')], label: t('Zeile im Detail öffnen') },
            { keys: ['⇥'], label: t('Beim Bearbeiten: übernehmen und weiter') },
            {
                keys: [t('Umschalt'), t('Klick')],
                label: t('Weitere Spalte zur Sortierung'),
            },
        ],
    },
    {
        title: t('SQL-Editor'),
        items: [
            {
                keys: [command, '↵'],
                label: t(
                    'Anweisung an der Schreibmarke oder Auswahl ausführen',
                ),
            },
            {
                keys: [t('Umschalt'), command, '↵'],
                label: t('Alles ausführen'),
            },
            { keys: [command, 'E'], label: t('Anweisung erklären (EXPLAIN)') },
            { keys: ['Ctrl', t('Leertaste')], label: t('Vervollständigen') },
        ],
    },
]);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-lg">
            <DialogTitle>{{ t('Tastenkürzel') }}</DialogTitle>
            <DialogDescription class="sr-only">{{
                t('Alle Tastenkürzel von phpdbadmin')
            }}</DialogDescription>
            <div class="flex flex-col gap-4">
                <section v-for="group in groups" :key="group.title">
                    <h3
                        class="mb-1.5 text-[13px] font-semibold text-text-secondary"
                    >
                        {{ group.title }}
                    </h3>
                    <dl class="flex flex-col divide-y rounded-md border">
                        <div
                            v-for="item in group.items"
                            :key="item.label"
                            class="flex items-center gap-3 px-3 py-2 text-sm"
                        >
                            <dt class="flex shrink-0 gap-1">
                                <kbd
                                    v-for="key in item.keys"
                                    :key="key"
                                    class="min-w-6 rounded border bg-muted px-1.5 text-center font-mono text-xs leading-5"
                                    >{{ key }}</kbd
                                >
                            </dt>
                            <dd class="text-text-secondary">
                                {{ item.label }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>
            <HelpLink anchor="kuerzel" class="self-start" />
        </DialogContent>
    </Dialog>
</template>
