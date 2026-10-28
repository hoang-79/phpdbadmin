<script setup lang="ts">
import { CircleAlert, CircleCheck, Info, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';

/** Hinweis (Gestaltung § 6): Linie links in der Farbe der Art, Symbol, kurzer Text. */
const props = withDefaults(
    defineProps<{
        variant?: 'info' | 'success' | 'warning' | 'danger';
    }>(),
    { variant: 'info' },
);

const look = computed(
    () =>
        ({
            info: {
                icon: Info,
                box: 'border-l-primary',
                icon_: 'text-primary',
            },
            success: {
                icon: CircleCheck,
                box: 'border-l-success',
                icon_: 'text-success',
            },
            warning: {
                icon: TriangleAlert,
                box: 'border-warning border-l-warning',
                icon_: 'text-warning',
            },
            danger: {
                icon: CircleAlert,
                box: 'border-destructive bg-destructive-soft',
                icon_: 'text-destructive',
            },
        })[props.variant],
);
</script>

<template>
    <div
        :role="variant === 'danger' ? 'alert' : 'status'"
        :class="[
            'flex items-start gap-2.5 rounded-md border border-l-[3px] bg-background px-3 py-2.5 text-[13px] leading-[18px]',
            look.box,
        ]"
    >
        <component
            :is="look.icon"
            :class="['mt-px size-4 shrink-0', look.icon_]"
        />
        <div class="min-w-0"><slot /></div>
    </div>
</template>
