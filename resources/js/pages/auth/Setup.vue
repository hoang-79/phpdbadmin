<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { LogIn, Rocket } from '@lucide/vue';
import Interpolate from '@/components/Interpolate.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { login } from '@/routes';

/** Ersteinrichtung über den Link aus `phpdbadmin:install` (A-06): ein Knopf meldet an, das Onboarding folgt. */
defineProps<{
    setup: {
        name: string;
        email: string;
        action: string;
    } | null;
}>();

setLayoutProps({ back: null });
</script>

<template>
    <Head :title="t('Ersteinrichtung')" />

    <template v-if="setup">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold">{{ t('Ersteinrichtung') }}</h1>
            <p class="text-text-secondary">
                <Interpolate
                    :text="t('Konto von :name (:email) als Administrator.')"
                >
                    <template #name
                        ><b class="font-semibold text-foreground">{{
                            setup.name
                        }}</b></template
                    >
                    <template #email
                        ><span class="wrap-break-word">{{
                            setup.email
                        }}</span></template
                    >
                </Interpolate>
            </p>
        </div>

        <Form :action="setup.action" method="post" #default="{ processing }">
            <Button
                type="submit"
                size="lg"
                class="w-full text-[15px]"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                <Rocket v-else />
                {{ t('Einrichtung starten') }}
            </Button>
        </Form>

        <p class="text-xs text-muted-foreground">
            {{
                t(
                    'Als Nächstes legen Sie Ihren Passkey an. Der Link gilt nur für diese erste Anmeldung.',
                )
            }}
        </p>
    </template>

    <template v-else>
        <h1 class="text-xl font-semibold">
            {{ t('Link nicht mehr gültig') }}
        </h1>
        <Notice variant="danger">
            <Interpolate
                :text="
                    t(
                        'Dieser Einrichtungslink ist abgelaufen oder wurde schon verwendet. Einen neuen erzeugt :command, solange die Ersteinrichtung nicht abgeschlossen ist.',
                    )
                "
            >
                <template #command
                    ><code class="font-mono"
                        >php artisan phpdbadmin:install</code
                    ></template
                >
            </Interpolate>
        </Notice>
        <Link
            :href="login()"
            class="inline-flex items-center gap-1.5 text-sm text-primary underline underline-offset-2"
        >
            <LogIn class="size-3.5" />{{ t('Mit Code per Mail anmelden') }}
        </Link>
    </template>
</template>
