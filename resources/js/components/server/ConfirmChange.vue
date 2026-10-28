<script setup lang="ts">
import { CircleX, Layers, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import InputError from '@/components/InputError.vue';
import { t } from '@/lib/i18n';

/**
 * Bestätigung einer Änderung am Server (W-02, W-04, W-05; Wireframe 06): zuerst genau die Anweisungen, die laufen
 * werden (Passwörter maskiert), Hinweise je Treiber, auf Produktion der Name der Verbindung — der Knopf wird erst
 * aktiv, wenn er stimmt (G-08). Fehler des Servers bleiben im Dialog stehen.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string | null;
        statements: string[];
        notes?: string[];
        confirmName: string | null;
        confirmLabel: string;
        destructive?: boolean;
        loading?: boolean;
        processing?: boolean;
        error?: string | null;
        confirmationError?: string | null;
    }>(),
    {
        description: null,
        notes: () => [],
        destructive: false,
        loading: false,
        processing: false,
        error: null,
        confirmationError: null,
    },
);

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: [confirmation: string] }>();

const typed = ref('');
const nameMatches = computed(
    () => props.confirmName === null || typed.value === props.confirmName,
);
const ready = computed(
    () =>
        nameMatches.value &&
        !props.loading &&
        !props.processing &&
        props.statements.length > 0,
);

watch(open, (isOpen) => {
    if (isOpen) {
        typed.value = '';
    }
});

function confirm(): void {
    if (ready.value) {
        emit('confirm', typed.value);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[90svh] flex-col gap-4 sm:max-w-2xl">
            <DialogTitle>{{ title }}</DialogTitle>
            <DialogDescription v-if="description">{{
                description
            }}</DialogDescription>

            <div
                v-if="loading"
                class="flex items-center gap-2 text-sm text-text-secondary"
            >
                <Spinner />{{ t('Vorschau wird erstellt …') }}
            </div>

            <template v-else>
                <div
                    class="flex min-h-0 flex-col gap-3 overflow-y-auto"
                    tabindex="0"
                    :aria-label="t('SQL-Vorschau')"
                >
                    <p
                        class="flex items-center gap-2 text-[13px] font-semibold"
                    >
                        <Layers class="size-4 text-primary" />{{
                            t('SQL-Vorschau')
                        }}
                    </p>
                    <ol
                        class="flex flex-col rounded-md border bg-muted font-mono text-[13px] leading-5"
                    >
                        <li
                            v-for="(statement, index) in statements"
                            :key="index"
                            class="flex gap-3 border-b px-3 py-2 last:border-b-0"
                        >
                            <span
                                class="shrink-0 text-muted-foreground tabular-nums"
                                aria-hidden="true"
                                >{{ index + 1 }}</span
                            >
                            <code class="min-w-0 wrap-break-word"
                                >{{ statement }};</code
                            >
                        </li>
                    </ol>
                    <ul
                        v-if="notes.length"
                        class="flex flex-col gap-1 text-[13px] leading-[18px] text-text-secondary"
                    >
                        <li v-for="note in notes" :key="note">{{ note }}</li>
                    </ul>
                </div>

                <div
                    v-if="error"
                    class="flex flex-col gap-1 rounded-lg border border-destructive bg-destructive-soft px-3.5 py-3"
                    role="alert"
                >
                    <span
                        class="flex items-center gap-2 font-semibold text-destructive"
                        ><CircleX class="size-4 shrink-0" />{{
                            t('Der Server hat die Änderung abgelehnt.')
                        }}</span
                    >
                    <span
                        class="font-mono text-xs wrap-break-word text-text-secondary"
                        >{{ error }}</span
                    >
                </div>

                <div
                    v-if="confirmName !== null"
                    class="flex flex-col gap-2 rounded-lg border border-destructive bg-destructive-soft px-3.5 py-3"
                >
                    <span
                        class="flex items-center gap-2 font-semibold text-destructive"
                        ><TriangleAlert class="size-4 shrink-0" />{{
                            t('Änderung auf Produktion')
                        }}</span
                    >
                    <label
                        for="confirm-name"
                        class="text-[13px] leading-[18px]"
                        >{{
                            t(
                                'Geben Sie zur Bestätigung den Namen der Verbindung ein: :name',
                                { name: confirmName },
                            )
                        }}</label
                    >
                    <Input
                        id="confirm-name"
                        v-model="typed"
                        autocomplete="off"
                        spellcheck="false"
                        class="bg-background font-mono"
                        :aria-invalid="confirmationError ? true : undefined"
                        @keydown.enter.prevent="confirm"
                    />
                    <InputError :message="confirmationError" />
                    <span
                        v-if="!nameMatches"
                        class="text-xs text-text-secondary"
                        >{{
                            t('Der Knopf wird aktiv, sobald der Name stimmt.')
                        }}</span
                    >
                </div>
            </template>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button type="button" variant="outline">{{
                        t('Abbrechen')
                    }}</Button>
                </DialogClose>
                <Button
                    type="button"
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="!ready"
                    @click="confirm"
                >
                    <Spinner v-if="processing" />{{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
