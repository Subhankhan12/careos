<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const { t, te } = useI18n();

const props = defineProps<{
    consents: Array<{
        id: string;
        template_key: string;
        title: string;
        scope_keys: string[];
        status: string;
        granted_at: string | null;
        withdrawn_at: string | null;
    }>;
    actions: { withdrawUrl: string };
}>();

const withdrawReasons = reactive<Record<string, string>>({});
const confirmingId = ref<string | null>(null);

const scopeMap: Record<string, string> = {
    'portal.access': 'portalAccess',
    'comms.email': 'commsEmail',
    'documents.read': 'documentsRead',
    'messages.write': 'messagesWrite',
    'research.share': 'researchShare',
};

function scopeLabel(key: string): string {
    const camel = scopeMap[key];
    const i = `portal.consents.scope.${camel}`;
    return camel && te(i) ? t(i) : key;
}

function isSerious(scopeKeys: string[]): boolean {
    return scopeKeys.includes('portal.access');
}

function withdraw(consentId: string): void {
    const reason = withdrawReasons[consentId]?.trim();
    if (!reason) {
        return;
    }

    router.post(props.actions.withdrawUrl, { consent_id: consentId, reason });
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.consents.title')" />

        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('portal.consents.eyebrow') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{{ t('portal.consents.title') }}</h1>
        <p class="mt-1 max-w-2xl text-ink-muted">{{ t('portal.consents.subtitle') }}</p>

        <div v-if="consents.length" class="mt-6 space-y-4">
            <div v-for="consent in consents" :key="consent.id" class="glass-card p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-ink">{{ consent.title }}</h2>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="consent.status === 'granted' ? 'bg-euca-100 text-euca-800' : 'bg-surface-2 text-ink-muted'"
                    >
                        {{ te(`portal.consents.status.${consent.status}`) ? t(`portal.consents.status.${consent.status}`) : consent.status }}
                    </span>
                </div>
                <p v-if="consent.granted_at" class="mt-1 text-sm text-ink-subtle">
                    {{ t('portal.consents.grantedOn', { date: consent.granted_at }) }}
                </p>

                <ul class="mt-4 space-y-2">
                    <li v-for="scope in consent.scope_keys" :key="scope" class="flex items-start gap-2 text-sm">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-euca-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M5 12.5l4 4 10-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span>
                            <span class="text-ink">{{ scopeLabel(scope) }}</span>
                            <span class="ml-1.5 font-mono text-xs text-ink-subtle">{{ scope }}</span>
                        </span>
                    </li>
                </ul>

                <div v-if="consent.status === 'granted'" class="mt-5">
                    <button
                        v-if="confirmingId !== consent.id"
                        type="button"
                        class="rounded-xl border border-line bg-surface/70 px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                        @click="confirmingId = consent.id"
                    >
                        {{ t('portal.consents.withdraw') }}
                    </button>

                    <div v-else class="rounded-xl border border-danger/30 bg-danger-soft p-4">
                        <p class="text-sm text-ink">
                            {{ isSerious(consent.scope_keys) ? t('portal.consents.withdrawWarningStrong') : t('portal.consents.withdrawWarning') }}
                        </p>
                        <label class="mt-3 block text-sm font-medium text-ink">
                            {{ t('portal.consents.withdrawReasonLabel') }}
                            <input
                                v-model="withdrawReasons[consent.id]"
                                class="mt-1.5 block w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink shadow-sm transition placeholder:text-ink-subtle focus:border-danger focus:outline-none focus:ring-2 focus:ring-danger/30"
                                :placeholder="t('portal.consents.withdrawReason')"
                            />
                        </label>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-xl border border-line bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                                @click="confirmingId = null"
                            >
                                {{ t('portal.consents.keepAccess') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-xl bg-danger px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!withdrawReasons[consent.id]?.trim()"
                                @click="withdraw(consent.id)"
                            >
                                {{ t('portal.consents.withdrawConfirm') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <p v-else class="mt-6 text-ink-muted">{{ t('portal.consents.empty') }}</p>
    </PortalLayout>
</template>
