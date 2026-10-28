<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { LogIn, UserPlus } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import Interpolate from '@/components/Interpolate.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { login } from '@/routes';
import { accept } from '@/routes/invitation';

/** Einladung annehmen (A-06): Name angeben, Konto entsteht, danach Passkey im Konto anlegen. */
defineProps<{
    token: string;
    invitation: {
        email: string;
        role: string;
        expiresAt: string;
    } | null;
}>();

setLayoutProps({ back: null });
</script>

<template>
    <Head :title="t('Einladung')" />

    <template v-if="invitation">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">{{ t('Einladung annehmen') }}</h1>
            <p class="text-text-secondary">
                <Interpolate
                    :text="
                        t('Konto für :email als :role.', {
                            role: invitation.role,
                        })
                    "
                >
                    <template #email
                        ><b
                            class="font-semibold wrap-break-word text-foreground"
                            >{{ invitation.email }}</b
                        ></template
                    >
                </Interpolate>
            </p>
        </div>

        <Form
            v-bind="accept.form(token)"
            class="flex flex-col gap-5"
            #default="{ errors, processing }"
        >
            <div class="flex flex-col gap-1.5">
                <Label for="name" class="text-[13px]">{{
                    t('Ihr Name')
                }}</Label>
                <Input
                    id="name"
                    name="name"
                    autocomplete="name"
                    required
                    autofocus
                    :aria-invalid="errors.name ? true : undefined"
                />
                <InputError :message="errors.name" />
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'So sehen andere Sie in der Verwaltung und im Protokoll.',
                        )
                    }}
                </p>
            </div>
            <Button
                type="submit"
                size="lg"
                class="w-full text-[15px]"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                <UserPlus v-else />
                {{ t('Konto anlegen') }}
            </Button>
        </Form>

        <p class="text-xs text-muted-foreground">
            {{
                t(
                    'Gültig bis :time. Ein Passwort gibt es nicht: Sie melden sich mit einem Passkey oder einem Code per Mail an.',
                    { time: formatDateTime(invitation.expiresAt) },
                )
            }}
        </p>
    </template>

    <template v-else>
        <h1 class="text-xl font-semibold">
            {{ t('Einladung nicht gültig') }}
        </h1>
        <Notice variant="danger">
            {{
                t(
                    'Dieser Link ist abgelaufen oder wurde schon verwendet. Bitten Sie um eine neue Einladung.',
                )
            }}
        </Notice>
        <Link
            :href="login()"
            class="inline-flex items-center gap-1.5 text-sm text-primary underline underline-offset-2"
        >
            <LogIn class="size-3.5" />{{
                t('Haben Sie schon ein Konto? Anmelden')
            }}
        </Link>
    </template>
</template>
