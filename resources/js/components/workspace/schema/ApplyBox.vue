<script setup lang="ts">
import { Check, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import type { SchemaPlanPreview } from '@/types';

/**
 * Bestätigung einer Strukturänderung (Gestaltung § 6, Wireframe 9): Was dabei verloren geht, verlangt den Namen der
 * Tabelle bzw. des Schemas; auf Produktion zusätzlich den Namen der Verbindung. Der Knopf wird erst aktiv, wenn beides
 * stimmt — geprüft wird es noch einmal auf dem Server, bevor eine Anweisung läuft.
 */
const props = withDefaults(
    defineProps<{
        plan: SchemaPlanPreview | null;
        connectionName: string | null;
        applyLabel: string;
        processing?: boolean;
        disabled?: boolean;
        errors?: Record<string, string | undefined>;
        discardLabel?: string | null;
    }>(),
    {
        processing: false,
        disabled: false,
        errors: () => ({}),
        discardLabel: null,
    },
);

const emit = defineEmits<{
    apply: [confirmation: { confirmName: string; confirmation: string }];
    discard: [];
}>();

const typedName = ref('');
const typedConnection = ref('');

watch(
    () => props.plan?.confirmName,
    () => {
        typedName.value = '';
    },
);

const nameMatches = computed(
    () =>
        !props.plan?.confirmName || typedName.value === props.plan.confirmName,
);
const connectionMatches = computed(
    () =>
        props.connectionName === null ||
        typedConnection.value === props.connectionName,
);
const hasWork = computed(
    () =>
        !!props.plan &&
        (props.plan.statements.length > 0 || props.plan.file !== null),
);
const ready = computed(
    () =>
        hasWork.value &&
        !props.disabled &&
        !props.processing &&
        nameMatches.value &&
        connectionMatches.value,
);
const destructive = computed(() => (props.plan?.losses.length ?? 0) > 0);

function apply(): void {
    if (ready.value) {
        emit('apply', {
            confirmName: typedName.value,
            confirmation: typedConnection.value,
        });
    }
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            v-if="plan && plan.losses.length"
            class="flex flex-col gap-2 rounded-lg border border-destructive bg-destructive-soft px-3.5 py-3"
        >
            <span class="flex items-center gap-2 font-semibold text-destructive"
                ><TriangleAlert class="size-4 shrink-0" />{{
                    t('Diese Änderung löscht Daten')
                }}</span
            >
            <ul class="flex flex-col gap-0.5 text-[13px] leading-[18px]">
                <li v-for="loss in plan.losses" :key="loss">{{ loss }}</li>
            </ul>
            <template v-if="plan.confirmName">
                <label
                    for="confirm-object-name"
                    class="text-[13px] leading-[18px]"
                    >{{
                        t('Geben Sie zur Bestätigung den Namen ein: :name', {
                            name: plan.confirmName,
                        })
                    }}</label
                >
                <div class="relative">
                    <Input
                        id="confirm-object-name"
                        v-model="typedName"
                        autocomplete="off"
                        spellcheck="false"
                        class="bg-background pr-8 font-mono"
                        :aria-invalid="errors.confirm_name ? true : undefined"
                        @keydown.enter.prevent="apply"
                    />
                    <Check
                        v-if="typedName && nameMatches"
                        class="absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-success"
                        aria-hidden="true"
                    />
                </div>
                <InputError :message="errors.confirm_name" />
            </template>
        </div>

        <div
            v-if="connectionName !== null && hasWork"
            class="flex flex-col gap-2 rounded-lg border border-destructive bg-destructive-soft px-3.5 py-3"
        >
            <span class="flex items-center gap-2 font-semibold text-destructive"
                ><TriangleAlert class="size-4 shrink-0" />{{
                    t('Änderung auf Produktion')
                }}</span
            >
            <label
                for="confirm-connection-name"
                class="text-[13px] leading-[18px]"
                >{{
                    t(
                        'Geben Sie zur Bestätigung den Namen der Verbindung ein: :name',
                        { name: connectionName },
                    )
                }}</label
            >
            <Input
                id="confirm-connection-name"
                v-model="typedConnection"
                autocomplete="off"
                spellcheck="false"
                class="bg-background font-mono"
                :aria-invalid="errors.confirmation ? true : undefined"
                @keydown.enter.prevent="apply"
            />
            <InputError :message="errors.confirmation" />
        </div>

        <p
            v-if="hasWork && (!nameMatches || !connectionMatches)"
            class="text-xs text-text-secondary"
        >
            {{ t('Der Knopf wird aktiv, sobald der Name stimmt.') }}
        </p>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <Button
                v-if="discardLabel"
                type="button"
                variant="ghost"
                @click="emit('discard')"
                >{{ discardLabel }}</Button
            >
            <Button
                type="button"
                :variant="destructive ? 'destructive' : 'default'"
                :disabled="!ready"
                @click="apply"
            >
                <Spinner v-if="processing" />{{ applyLabel }}
            </Button>
        </div>
    </div>
</template>
