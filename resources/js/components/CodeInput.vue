<script setup lang="ts">
import { PinInputInput, PinInputRoot } from 'reka-ui';
import { nextTick, onMounted, ref } from 'vue';
import { t } from '@/lib/i18n';

/**
 * Code aus der Mail (A-02): ein Feld je Ziffer. Einfügen und der Vorschlag des Geräts (`one-time-code`) verteilen
 * den Code auf alle Felder; Rücktaste und Pfeiltasten springen zwischen den Feldern. Sind alle Felder gefüllt, meldet
 * `complete` — die Seite schickt dann selbst ab, ein Knopf «Anmelden» braucht es nicht. Abgeschickt wird der Code
 * über ein verstecktes Feld mit dem Namen `name`.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        name?: string;
        length?: number;
        invalid?: boolean;
        disabled?: boolean;
        describedby?: string;
        autofocus?: boolean;
    }>(),
    { name: 'code', length: 6, autofocus: true },
);

const emit = defineEmits<{ complete: [code: string] }>();

const digits = ref<(number | undefined)[]>([]);
const group = ref<HTMLElement | null>(null);

function focusFirst(): void {
    group.value?.querySelector<HTMLInputElement>('input[data-ziffer]')?.focus();
}

/** Nach einem falschen Code: Felder leeren und vorne neu beginnen. */
function clear(): void {
    digits.value = [];
    void nextTick(focusFirst);
}

function onComplete(value: (number | undefined)[]): void {
    // Das versteckte Feld übernimmt den Wert erst beim nächsten Zeichnen.
    void nextTick(() => emit('complete', value.join('')));
}

onMounted(() => {
    if (props.autofocus) {
        focusFirst();
    }
});

defineExpose({ clear, focus: focusFirst });
</script>

<template>
    <div ref="group">
        <PinInputRoot
            :id="props.id"
            v-model="digits"
            :name="props.name"
            type="number"
            otp
            required
            :disabled="props.disabled"
            class="flex items-center justify-between gap-2"
            @complete="onComplete"
        >
            <PinInputInput
                v-for="(_, index) in props.length"
                :key="index"
                :index="index"
                data-ziffer
                :aria-label="
                    t('Ziffer :position von :count', {
                        position: index + 1,
                        count: props.length,
                    })
                "
                :aria-invalid="props.invalid ? true : undefined"
                :aria-describedby="props.describedby"
                class="h-14 w-full min-w-0 rounded-md border bg-background text-center font-mono text-2xl font-semibold tabular-nums caret-primary outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20"
            />
        </PinInputRoot>
    </div>
</template>
