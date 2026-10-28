<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/types';

/** Darstellung (G-05): Farbschema hell, dunkel oder wie das System — gespeichert im Browser. */
const { appearance, resolvedAppearance, updateAppearance } = useAppearance();

// Das System-Schema kennt erst der Browser; serverseitig gerendert bliebe der Hinweis sonst falsch.
const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

const options: {
    value: Appearance;
    label: string;
    icon: typeof Sun;
}[] = [
    { value: 'light', label: 'Hell', icon: Sun },
    { value: 'dark', label: 'Dunkel', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];
</script>

<template>
    <section
        id="darstellung"
        class="scroll-mt-16 rounded-lg border bg-background"
    >
        <div class="flex items-center gap-2 border-b px-4 py-3">
            <Sun class="size-4" />
            <h2 class="text-[15px] font-semibold">Darstellung</h2>
        </div>
        <div class="flex flex-col gap-1.5 p-4">
            <span id="farbschema" class="text-[13px] font-medium"
                >Farbschema</span
            >
            <div
                role="radiogroup"
                aria-labelledby="farbschema"
                class="grid grid-cols-3 gap-2"
            >
                <button
                    v-for="option in options"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="appearance === option.value"
                    :class="[
                        'flex flex-col gap-2 rounded-md border p-2 text-left text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        appearance === option.value
                            ? 'border-primary bg-accent shadow-[inset_0_0_0_1px_var(--primary)]'
                            : 'hover:bg-muted',
                    ]"
                    @click="updateAppearance(option.value)"
                >
                    <span
                        v-if="option.value === 'system'"
                        class="flex h-11 overflow-hidden rounded-md border"
                        ><span class="flex-1 bg-white" /><span
                            class="flex-1 bg-[#0b1120]"
                    /></span>
                    <span
                        v-else
                        :class="[
                            'flex h-11 flex-col justify-center gap-1 rounded-md border px-2',
                            option.value === 'light'
                                ? 'bg-white'
                                : 'bg-[#0b1120]',
                        ]"
                    >
                        <i
                            :class="[
                                'h-1.5 w-3/5 rounded-full opacity-80',
                                option.value === 'light'
                                    ? 'bg-[#1c2534]'
                                    : 'bg-[#e5e9f0]',
                            ]"
                        />
                        <i
                            :class="[
                                'h-1.5 w-4/5 rounded-full',
                                option.value === 'light'
                                    ? 'bg-[#eceff3]'
                                    : 'bg-[#1a2438]',
                            ]"
                        />
                    </span>
                    <span class="flex items-center gap-1.5">
                        <component :is="option.icon" class="size-3.5" />{{
                            option.label
                        }}
                    </span>
                </button>
            </div>
            <span
                v-if="isMounted && appearance === 'system'"
                class="text-xs text-muted-foreground"
                >Folgt jetzt dem System:
                {{ resolvedAppearance === 'dark' ? 'dunkel' : 'hell' }}.</span
            >
        </div>
    </section>
</template>
