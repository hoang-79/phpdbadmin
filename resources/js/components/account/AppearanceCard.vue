<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { locale as currentLocale, t } from '@/lib/i18n';
import type { Locale } from '@/lib/i18n';
import { locale as updateLocale } from '@/routes/account';
import type { Appearance } from '@/types';

/**
 * Darstellung (G-05): Farbschema hell, dunkel oder wie das System — gespeichert im Browser. Sprache (N-06) im Konto,
 * damit auch Mails sie kennen.
 */
const { appearance, resolvedAppearance, updateAppearance } = useAppearance();

// Das System-Schema kennt erst der Browser; serverseitig gerendert bliebe der Hinweis sonst falsch.
const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

const options = computed<
    {
        value: Appearance;
        label: string;
        icon: typeof Sun;
    }[]
>(() => [
    { value: 'light', label: t('Hell'), icon: Sun },
    { value: 'dark', label: t('Dunkel'), icon: Moon },
    { value: 'system', label: t('System'), icon: Monitor },
]);

// Jede Sprache in ihrer eigenen Schreibweise — wer die andere nicht liest, findet so zurück.
const languages: { value: Locale; label: string }[] = [
    { value: 'de', label: 'Deutsch' },
    { value: 'en', label: 'English' },
];
const isSavingLocale = ref(false);

function chooseLocale(value: Locale): void {
    if (value === currentLocale()) {
        return;
    }

    isSavingLocale.value = true;
    router.put(
        updateLocale.url(),
        { locale: value },
        {
            preserveScroll: true,
            onFinish: () => {
                isSavingLocale.value = false;
            },
        },
    );
}
</script>

<template>
    <section
        id="darstellung"
        class="scroll-mt-16 rounded-lg border bg-background"
    >
        <div class="flex items-center gap-2 border-b px-4 py-3">
            <Sun class="size-4" />
            <h2 class="text-[15px] font-semibold">{{ t('Darstellung') }}</h2>
        </div>
        <div class="flex flex-col gap-1.5 p-4">
            <span id="farbschema" class="text-[13px] font-medium">{{
                t('Farbschema')
            }}</span>
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
                >{{
                    resolvedAppearance === 'dark'
                        ? t('Folgt jetzt dem System: dunkel.')
                        : t('Folgt jetzt dem System: hell.')
                }}</span
            >
        </div>
        <div class="flex flex-col gap-1.5 border-t p-4">
            <span id="sprache" class="text-[13px] font-medium">{{
                t('Sprache')
            }}</span>
            <div
                role="radiogroup"
                aria-labelledby="sprache"
                class="grid grid-cols-2 gap-2 sm:max-w-xs"
            >
                <button
                    v-for="language in languages"
                    :key="language.value"
                    type="button"
                    role="radio"
                    :lang="language.value"
                    :aria-checked="currentLocale() === language.value"
                    :disabled="isSavingLocale"
                    :class="[
                        'h-9 rounded-md border px-3 text-left text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60',
                        currentLocale() === language.value
                            ? 'border-primary bg-accent font-semibold shadow-[inset_0_0_0_1px_var(--primary)]'
                            : 'hover:bg-muted',
                    ]"
                    @click="chooseLocale(language.value)"
                >
                    {{ language.label }}
                </button>
            </div>
            <span class="text-xs text-muted-foreground">{{
                t('Gilt für die Oberfläche und für Mails an Sie.')
            }}</span>
        </div>
    </section>
</template>
