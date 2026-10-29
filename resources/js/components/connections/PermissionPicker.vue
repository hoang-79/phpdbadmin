<script setup lang="ts">
import { computed, useId } from 'vue';
import { t } from '@/lib/i18n';
import type { ConnectionPermission } from '@/types';

/**
 * Recht eines Kontos an einer Verbindung (A-07, Wireframe 14): «kein Zugriff · lesen · schreiben · verwalten» als
 * Gruppe echter Optionsfelder — Pfeiltasten wechseln, wie bei jedem Optionsfeld.
 */
const props = defineProps<{
    label: string;
    disabled?: boolean;
}>();

const model = defineModel<ConnectionPermission | null>({ required: true });
const name = useId();

const options = computed<
    { value: ConnectionPermission | null; label: string }[]
>(() => [
    { value: null, label: t('kein Zugriff') },
    { value: 'read', label: t('lesen') },
    { value: 'write', label: t('schreiben') },
    { value: 'manage', label: t('verwalten') },
]);
</script>

<template>
    <fieldset
        :disabled="props.disabled"
        class="inline-flex overflow-hidden rounded-md border disabled:opacity-60"
    >
        <legend class="sr-only">{{ label }}</legend>
        <label
            v-for="option in options"
            :key="option.value ?? 'none'"
            :class="[
                'relative cursor-pointer border-r px-2.5 py-1 text-[13px] whitespace-nowrap last:border-r-0 has-focus-visible:ring-2 has-focus-visible:ring-ring has-focus-visible:ring-inset',
                model === option.value
                    ? 'bg-accent font-semibold shadow-[inset_0_-2px_0_var(--primary)]'
                    : 'text-text-secondary hover:bg-muted',
            ]"
        >
            <input
                v-model="model"
                type="radio"
                class="sr-only"
                :name="name"
                :value="option.value"
            />
            {{ option.label }}
        </label>
    </fieldset>
</template>
