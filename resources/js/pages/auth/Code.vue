<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { Mail, RefreshCw } from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { ref } from 'vue';
import CodeInput from '@/components/CodeInput.vue';
import InputError from '@/components/InputError.vue';
import Interpolate from '@/components/Interpolate.vue';
import Notice from '@/components/Notice.vue';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCountdown, formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { login } from '@/routes';
import { resend, verify } from '@/routes/login/code';

/** Wireframe 02: Code aus der Mail eingeben. Dieselbe Seite für bekannte und unbekannte Adressen. */
const props = defineProps<{
    email: string;
    validMinutes: number;
    expiresAt: string;
    resendInSeconds: number;
}>();

setLayoutProps({
    back: { href: login.url(), label: t('Zurück zur Anmeldung') },
});

const waitSeconds = ref(props.resendInSeconds);
const codeInput = ref<InstanceType<typeof CodeInput> | null>(null);
useIntervalFn(() => {
    waitSeconds.value = Math.max(0, waitSeconds.value - 1);
}, 1000);
</script>

<template>
    <Head :title="t('Code eingeben')" />

    <h1 class="text-xl font-semibold">{{ t('Code eingeben') }}</h1>

    <Notice :icon="Mail">
        <Interpolate
            :text="
                t(
                    'Wir haben einen Code an :email gesendet, sofern es dazu ein Konto gibt. Er ist :minutes Minuten gültig, bis :time Uhr.',
                    { minutes: validMinutes, time: formatTime(expiresAt) },
                )
            "
        >
            <template #email
                ><b class="font-semibold wrap-break-word">{{
                    email
                }}</b></template
            >
        </Interpolate>
    </Notice>

    <!-- Kein Knopf: Sobald alle sechs Ziffern stehen, schickt die Seite selbst ab (Wunsch Hoang, 29.10.2026). -->
    <Form
        v-bind="verify.form()"
        class="flex flex-col gap-1.5"
        #default="{ errors, processing, submit }"
        @error="codeInput?.clear()"
    >
        <Label for="code" class="text-[13px]">{{
            t('Code aus der Mail')
        }}</Label>
        <CodeInput
            id="code"
            ref="codeInput"
            :invalid="!!errors.code"
            describedby="code-hint"
            @complete="submit()"
        />
        <InputError :message="errors.code" />
        <p
            id="code-hint"
            class="flex min-h-5 items-center gap-1.5 text-xs text-muted-foreground"
            aria-live="polite"
        >
            <template v-if="processing">
                <Spinner class="size-3.5" />{{ t('Code wird geprüft …') }}
            </template>
            <template v-else>{{
                t(
                    'Sechs Ziffern. Sie können den Code auch aus der Mail einfügen — Ihr Gerät schlägt ihn oft schon vor. Sobald alle Ziffern stehen, melden wir Sie an.',
                )
            }}</template>
        </p>
    </Form>

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <Form
            v-bind="resend.form()"
            #default="{ processing }"
            @success="waitSeconds = resendInSeconds"
        >
            <button
                type="submit"
                class="inline-flex items-center gap-1.5 text-primary underline underline-offset-2 disabled:text-muted-foreground disabled:no-underline"
                :disabled="processing || waitSeconds > 0"
            >
                <RefreshCw class="size-3.5" />
                {{ t('Code erneut senden') }}
                <span v-if="waitSeconds > 0" class="tabular-nums">{{
                    t('(in :time)', { time: formatCountdown(waitSeconds) })
                }}</span>
            </button>
        </Form>
        <Link
            :href="login()"
            class="inline-flex items-center gap-1.5 text-primary underline underline-offset-2"
        >
            <Mail class="size-3.5" />{{ t('Andere Adresse verwenden') }}
        </Link>
    </div>
</template>
