<script setup lang="ts">
import { Form, Head, router, setLayoutProps } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { KeyRound, Mail } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
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

setLayoutProps({ back: { href: account.url(), label: 'Zurück zum Konto' } });

const passkey = usePasskeyVerify({
    routes: { options: confirmOptions.url(), submit: confirm.url() },
    onSuccess: (response) => router.visit(response.redirect ?? account.url()),
});

const passkeyError = computed(() =>
    passkeyMessage(passkey.errorInstance.value),
);
</script>

<template>
    <Head title="Bestätigen" />

    <div class="flex flex-col gap-1">
        <h1 class="text-xl font-semibold">Bestätigen, dass Sie es sind</h1>
        <p class="text-text-secondary">
            Bevor Sie Passkeys hinzufügen oder entfernen, bestätigen Sie kurz
            Ihre Anmeldung.
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
            Mit Passkey bestätigen
        </Button>
        <InputError :message="passkeyError" />
        <div
            class="mt-3 flex items-center gap-3 text-[13px] text-muted-foreground before:h-px before:flex-1 before:bg-border after:h-px after:flex-1 after:bg-border"
        >
            oder mit Code per Mail
        </div>
    </div>

    <template v-if="codeSent">
        <Notice>
            Wir haben einen Code an
            <b class="font-semibold break-all">{{ email }}</b> gesendet.
        </Notice>
        <Form
            v-bind="verify.form()"
            class="flex flex-col gap-5"
            #default="{ errors, processing }"
        >
            <div class="flex flex-col gap-1.5">
                <Label for="code" class="text-[13px]">Code aus der Mail</Label>
                <Input
                    id="code"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="9"
                    required
                    autofocus
                    class="h-11 font-mono text-xl tracking-[0.3em]"
                    :aria-invalid="errors.code ? true : undefined"
                />
                <InputError :message="errors.code" />
            </div>
            <Button
                type="submit"
                size="lg"
                :variant="hasPasskeys ? 'outline' : 'default'"
                class="w-full text-[15px]"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                Bestätigen
            </Button>
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
            {{ codeSent ? 'Neuen Code senden' : `Code an ${email} senden` }}
        </Button>
        <InputError :message="errors.code" class="mt-2" />
    </Form>
</template>
