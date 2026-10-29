<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Laptop, LogOut, Smartphone } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatRecent } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import { destroy, destroyOthers } from '@/routes/account/sessions';
import type { ActiveSession } from '@/types';

/**
 * Aktive Sitzungen (A-08): wo dieses Konto angemeldet ist, mit Browser, System, Adresse und letzter Aktivität.
 * Fremde Sitzungen lassen sich einzeln oder alle auf einmal beenden; die eigene beendet «Abmelden».
 */
const props = defineProps<{
    sessions: ActiveSession[];
}>();

const busy = ref<string | null>(null);

function end(session: ActiveSession): void {
    busy.value = session.key;
    router.delete(destroy.url(session.key), {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
}

function endOthers(): void {
    busy.value = 'andere';
    router.delete(destroyOthers.url(), {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
}

function device(session: ActiveSession): string {
    return (
        [session.browser, session.os].filter(Boolean).join(' · ') ||
        t('Unbekanntes Gerät')
    );
}

const others = computed(() =>
    props.sessions.filter((session) => !session.current),
);
</script>

<template>
    <section class="rounded-lg border bg-background">
        <div class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
            <Laptop class="size-4" />
            <h2 class="text-[15px] font-semibold">{{ t('Sitzungen') }}</h2>
            <span
                class="rounded-full bg-muted px-2 text-xs font-medium text-text-secondary tabular-nums"
                >{{ sessions.length }}</span
            >
            <Button
                v-if="others.length > 0"
                size="sm"
                variant="outline"
                class="ml-auto"
                :disabled="busy !== null"
                @click="endOthers"
            >
                <LogOut />{{
                    tc(
                        'Die andere Sitzung beenden|Alle :count anderen beenden',
                        others.length,
                    )
                }}
            </Button>
        </div>

        <ul class="divide-y">
            <li
                v-for="session in sessions"
                :key="session.key"
                class="flex items-center gap-3 px-4 py-3"
            >
                <component
                    :is="
                        session.os === 'iPhone' || session.os === 'Android'
                            ? Smartphone
                            : Laptop
                    "
                    class="size-5 shrink-0 text-text-secondary"
                    aria-hidden="true"
                />
                <div class="flex min-w-0 flex-col">
                    <span class="flex flex-wrap items-center gap-2">
                        <b class="font-medium">{{ device(session) }}</b>
                        <span
                            v-if="session.current"
                            class="rounded-full border border-primary px-2 py-px text-xs font-medium text-primary"
                            >{{ t('diese Sitzung') }}</span
                        >
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ session.ip ?? t('Adresse unbekannt') }} ·
                        {{
                            session.current
                                ? t('jetzt aktiv')
                                : t('zuletzt aktiv :when', {
                                      when: formatRecent(session.lastActiveAt),
                                  })
                        }}
                    </span>
                </div>
                <Button
                    v-if="!session.current"
                    size="sm"
                    variant="ghost"
                    class="ml-auto"
                    :disabled="busy !== null"
                    :aria-label="
                        t('Sitzung :device beenden', {
                            device: device(session),
                        })
                    "
                    @click="end(session)"
                >
                    {{ t('Beenden') }}
                </Button>
            </li>
        </ul>
    </section>
</template>
