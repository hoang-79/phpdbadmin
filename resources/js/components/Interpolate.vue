<script setup lang="ts">
import { computed, useSlots } from 'vue';

/**
 * Übersetzter Satz mit Auszeichnung darin (N-06): `text` kommt aus `t('…')`; jeder Platzhalter `:name`, zu dem es
 * einen gleichnamigen Slot gibt, wird durch diesen Slot ersetzt. So bleibt der Satz ein einziger Schlüssel, auch wenn
 * ein Teil fett oder als Code erscheint. Platzhalter ohne Slot bleiben stehen — Werte ersetzt schon `t()`.
 */
const props = defineProps<{
    text: string;
}>();

const slots = useSlots();

const parts = computed(() =>
    props.text
        .split(/(:[a-z_]+)/i)
        .filter((part) => part !== '')
        .map((part) => {
            const name = part.startsWith(':') ? part.slice(1) : null;

            return name !== null && slots[name]
                ? { slot: name, text: '' }
                : { slot: null, text: part };
        }),
);
</script>

<template>
    <span
        ><template v-for="(part, index) in parts" :key="index"
            ><slot v-if="part.slot" :name="part.slot" /><template v-else>{{
                part.text
            }}</template></template
        ></span
    >
</template>
