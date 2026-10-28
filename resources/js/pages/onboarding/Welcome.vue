<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { ArrowRight, KeyRound, Plus } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import Interpolate from '@/components/Interpolate.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { passkeyMessage, suggestedPasskeyName } from '@/lib/passkeyMessage';
import { create } from '@/routes/connections';
import { registrationOptions, store } from '@/routes/passkey';
import { confirm } from '@/routes/password';

/**
 * Onboarding nach der ersten Anmeldung ohne Passkey (A-03): gleich einen Passkey anlegen. Das erste Konto der
 * Installation sieht die Ersteinrichtung mit den nächsten Schritten.
 */
const props = defineProps<{
    name: string;
    email: string;
    isFirstUser: boolean;
    hasPasskey: boolean;
    canRegister: boolean;
    continueUrl: string;
}>();

setLayoutProps({ back: null });

const passkeyName = ref('');
const created = ref<string | null>(null);

const registration = usePasskeyRegister({
    routes: { options: registrationOptions.url(), submit: store.url() },
    onSuccess: () => {
        created.value = passkeyName.value.trim();
    },
});

const error = computed(() => passkeyMessage(registration.errorInstance.value));
const done = computed(() => props.hasPasskey || created.value !== null);
const firstName = computed(() => props.name.trim().split(/\s+/u)[0] ?? '');

onMounted(() => {
    passkeyName.value = suggestedPasskeyName(navigator.userAgent);
});
</script>

<template>
    <Head :title="isFirstUser ? t('Ersteinrichtung') : t('Willkommen')" />

    <template v-if="!done">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">
                {{
                    isFirstUser
                        ? t('Ersteinrichtung')
                        : t('Willkommen, :name', { name: firstName })
                }}
            </h1>
            <p class="text-text-secondary">
                <template v-if="isFirstUser"
                    >{{
                        t(
                            'Sie sind das erste Konto dieser Installation und damit Administrator.',
                        )
                    }}
                </template>
                {{
                    t(
                        'Legen Sie jetzt Ihren Passkey an — danach melden Sie sich per Fingerabdruck, Gesicht oder Geräte-PIN an, ohne Code per Mail.',
                    )
                }}
            </p>
        </div>

        <template v-if="!canRegister">
            <Notice>
                <Interpolate
                    :text="
                        t(
                            'Zum Anlegen bestätigen Sie zuerst kurz, dass Sie es sind — mit einem Code an :email.',
                        )
                    "
                >
                    <template #email
                        ><span class="wrap-break-word">{{
                            email
                        }}</span></template
                    >
                </Interpolate>
            </Notice>
            <Button size="lg" class="w-full text-[15px]" as-child>
                <Link :href="confirm()"
                    ><KeyRound />{{ t('Bestätigen und Passkey anlegen') }}</Link
                >
            </Button>
        </template>

        <form
            v-else-if="registration.isSupported.value"
            class="flex flex-col gap-4"
            @submit.prevent="registration.register(passkeyName.trim())"
        >
            <div class="flex flex-col gap-1.5">
                <Label for="passkey-name" class="text-[13px]">{{
                    t('Name des Passkeys')
                }}</Label>
                <Input
                    id="passkey-name"
                    v-model="passkeyName"
                    required
                    maxlength="255"
                    aria-describedby="passkey-name-hint"
                />
                <p id="passkey-name-hint" class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Damit Sie ihn später wiedererkennen, z. B. «MacBook» oder «iPhone».',
                        )
                    }}
                </p>
            </div>
            <InputError :message="error" />
            <Button
                type="submit"
                size="lg"
                class="w-full text-[15px]"
                :disabled="registration.isLoading.value || !passkeyName.trim()"
            >
                <Spinner v-if="registration.isLoading.value" />
                <KeyRound v-else />
                {{ t('Passkey jetzt anlegen') }}
            </Button>
        </form>

        <Notice v-else variant="warning">
            <Interpolate
                :text="
                    t(
                        'Dieser Browser unterstützt keine Passkeys. Sie melden sich weiter mit einem Code an :email an und können den Passkey später unter «Konto» anlegen.',
                    )
                "
            >
                <template #email
                    ><span class="wrap-break-word">{{ email }}</span></template
                >
            </Interpolate>
        </Notice>

        <Link
            :href="continueUrl"
            class="self-center text-sm text-text-secondary underline underline-offset-2 hover:text-foreground"
        >
            {{ t('Später — weiter mit Code per Mail') }}
        </Link>
    </template>

    <template v-else>
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">
                {{ isFirstUser ? t('Eingerichtet') : t('Alles bereit') }}
            </h1>
            <p class="text-text-secondary">
                {{ t('Ab jetzt melden Sie sich mit Ihrem Passkey an.') }}
            </p>
        </div>

        <Notice v-if="created" variant="success">
            <Interpolate :text="t('Passkey «:name» angelegt.')">
                <template #name
                    ><b class="font-semibold">{{ created }}</b></template
                >
            </Interpolate>
        </Notice>

        <template v-if="isFirstUser">
            <ol class="flex flex-col gap-2 text-sm">
                <li class="flex gap-2">
                    <span
                        class="inline-grid size-5 shrink-0 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground"
                        >1</span
                    >
                    <span>{{
                        t(
                            'Erste Verbindung anlegen — MariaDB, MySQL, PostgreSQL oder eine SQLite-Datei.',
                        )
                    }}</span>
                </li>
                <li class="flex gap-2">
                    <span
                        class="inline-grid size-5 shrink-0 place-items-center rounded-full border text-xs font-semibold text-text-secondary"
                        >2</span
                    >
                    <span
                        >{{ t('Weitere Personen einladen:') }}
                        <code class="font-mono text-xs"
                            >php artisan phpdbadmin:invite name@example.ch</code
                        ></span
                    >
                </li>
            </ol>
            <Button size="lg" class="w-full text-[15px]" as-child>
                <Link :href="create()"
                    ><Plus />{{ t('Erste Verbindung anlegen') }}</Link
                >
            </Button>
            <Link
                :href="continueUrl"
                class="self-center text-sm text-text-secondary underline underline-offset-2 hover:text-foreground"
            >
                {{ t('Zur Verbindungsübersicht') }}
            </Link>
        </template>

        <Button v-else size="lg" class="w-full text-[15px]" as-child>
            <Link :href="continueUrl"
                >{{ t('Weiter zu den Verbindungen') }}<ArrowRight
            /></Link>
        </Button>
    </template>
</template>
