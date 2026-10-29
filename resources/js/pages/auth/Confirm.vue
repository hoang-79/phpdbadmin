<script setup lang="ts">
import { Form, Head, router, setLayoutProps } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { KeyRound, Mail } from '@lucide/vue';
import { computed, ref } from 'vue';
import CodeInput from '@/components/CodeInput.vue';
import InputError from '@/components/InputError.vue';
import Interpolate from '@/components/Interpolate.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { passkeyMessage } from '@/lib/passkeyMessage';
import { account } from '@/routes';
import { code } from '@/routes/confirm';
import { verify } from '@/routes/confirm/code';
import { confirm, confirmOptions } from '@/routes/passkey';

/**
 * Bestätigen vor dem Verwalten von Passkeys (ersetzt die Passwortbestätigung): per Passkey oder per Code an die
 * eigene Adresse.
 */
defineProps<{
    email: string;
    hasPasskeys: boolean;
    codeSent: boolean;
}>();

setLayoutProps({ back: { href: account.url(), label: t('Zurück zum Konto') } });

const codeInput = ref<InstanceType<typeof CodeInput> | null>(null);

const passkey = usePasskeyVerify({
    routes: { options: confirmOptions.url(), submit: confirm.url() },
    onSuccess: (response) => router.visit(response.redirect ?? account.url()),
});

const passkeyError = computed(() =>
    passkeyMessage(passkey.errorInstance.value),
);
</script>

<template>
    <Head :title="t('Bestätigen')" />

    <div class="flex flex-col gap-1">
        <h1 class="text-xl font-semibold">
            {{ t('Bestätigen, dass Sie es sind') }}
        </h1>
        <p class="text-text-secondary">
            {{
                t(
                    'Bevor Sie Passkeys hinzufügen oder entfernen, bestätigen Sie kurz Ihre Anmeldung.',
                )
            }}
        </p>
    </div>

    <div
        v-if="hasPasskeys && passkey.isSupported.value"
        class="flex flex-col gap-2"
    >
        <Button
            type="button"
            size="lg"
            class="w-full text-[15px]"
            :disabled="passkey.isLoading.value"
            @click="passkey.verify"
        >
            <Spinner v-if="passkey.isLoading.value" />
            <KeyRound v-else />
            {{ t('Mit Passkey bestätigen') }}
        </Button>
        <InputError :message="passkeyError" />
        <div
            class="mt-3 flex items-center gap-3 text-[13px] text-muted-foreground before:h-px before:flex-1 before:bg-border after:h-px after:flex-1 after:bg-border"
        >
            {{ t('oder mit Code per Mail') }}
        </div>
    </div>

    <template v-if="codeSent">
        <Notice :icon="Mail">
            <Interpolate :text="t('Wir haben einen Code an :email gesendet.')">
                <template #email
                    ><b class="font-semibold wrap-break-word">{{
                        email
                    }}</b></template
                >
            </Interpolate>
        </Notice>
        <!-- Kein Knopf: Sobald alle sechs Ziffern stehen, schickt die Seite selbst ab. -->
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
                describedby="code-status"
                @complete="submit()"
            />
            <InputError :message="errors.code" />
            <p
                id="code-status"
                class="flex min-h-5 items-center gap-1.5 text-xs text-muted-foreground"
                aria-live="polite"
            >
                <template v-if="processing">
                    <Spinner class="size-3.5" />{{ t('Code wird geprüft …') }}
                </template>
                <template v-else>{{
                    t('Sobald alle sechs Ziffern stehen, bestätigen wir.')
                }}</template>
            </p>
        </Form>
    </template>

    <Form v-bind="code.form()" #default="{ errors, processing }">
        <Button
            type="submit"
            :size="codeSent ? 'default' : 'lg'"
            :variant="hasPasskeys || codeSent ? 'outline' : 'default'"
            class="w-full"
            :disabled="processing"
        >
            <Spinner v-if="processing" />
            <Mail v-else />
            {{
                codeSent
                    ? t('Neuen Code senden')
                    : t('Code an :email senden', { email })
            }}
        </Button>
        <InputError :message="errors.code" class="mt-2" />
    </Form>
</template>
