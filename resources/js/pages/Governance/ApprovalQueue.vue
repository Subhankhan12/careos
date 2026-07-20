<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

interface PendingAction {
    id: string;
    agent: string;
    feature: string;
    toolKey: string;
    toolName: string | null;
    category: string | null;
    autonomyLevel: string;
    why: string;
    proposedOutput: Record<string, unknown> | null;
    diff: Record<string, unknown> | null;
    canReview: boolean;
    approveUrl: string;
    rejectUrl: string;
}

defineProps<{
    pending: PendingAction[];
    resolved: Array<{ id: string; agent: string; toolKey: string; status: string; reviewedBy: string | null; rejectionReason: string | null; resolvedAt: string | null }>;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// One reject reason box open at a time. Approve/reject POST straight to the existing path.
const rejectingId = ref<string | null>(null);
const rejectForm = useForm({ reason: '' });

function approve(action: PendingAction): void {
    router.post(action.approveUrl, {}, { preserveScroll: true });
}

function openReject(id: string): void {
    rejectingId.value = id;
    rejectForm.reason = '';
    rejectForm.clearErrors();
}

function confirmReject(action: PendingAction): void {
    rejectForm.post(action.rejectUrl, {
        preserveScroll: true,
        onSuccess: () => {
            rejectingId.value = null;
        },
    });
}

function pretty(value: Record<string, unknown> | null): string {
    return value ? JSON.stringify(value, null, 2) : '—';
}

function dateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}
</script>

<template>
    <AppLayout>
        <Head :title="t('aiQueue.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('aiQueue.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('aiQueue.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('aiQueue.subtitle') }}</p>
            </div>

            <p v-if="flash === 'approved' || flash === 'rejected'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t(`aiQueue.flash.${flash}`) }}
            </p>

            <!-- Pending queue: each item is approved/rejected ONLY through the existing service path. -->
            <p v-if="!pending.length" class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-muted">{{ t('aiQueue.empty') }}</p>

            <div v-for="action in pending" :key="action.id" class="glass-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-ink">{{ action.toolName ?? action.toolKey }}</span>
                            <span v-if="action.category" class="inline-flex items-center rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-medium text-euca-700">{{ action.category }}</span>
                            <span class="inline-flex items-center rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-medium text-euca-700">{{ t('aiQueue.card.autonomy', { level: action.autonomyLevel }) }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-ink-subtle">{{ t('aiQueue.card.agent', { agent: action.agent }) }} · <span class="font-mono">{{ action.feature }}</span></p>
                    </div>
                    <!-- Fence discipline: AI content is always badged, never presented as authoritative judgment. -->
                    <span class="inline-flex items-center rounded-full border border-warning/30 bg-warning-soft px-2.5 py-1 text-xs font-semibold text-warning">{{ t('aiQueue.badge') }}</span>
                </div>

                <div class="mt-4 space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('aiQueue.card.why') }}</p>
                        <p class="mt-1 text-ink">{{ action.why }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('aiQueue.card.grounding') }}</p>
                        <pre class="mt-1 max-h-56 overflow-auto rounded-xl border border-line bg-euca-50/60 p-3 text-xs text-ink">{{ pretty(action.proposedOutput) }}</pre>
                    </div>
                </div>

                <!-- Act-through-existing-path controls. Hidden when this reviewer lacks the tool's
                     permission — a UX hint; the server denies regardless (the cap binds server-side). -->
                <div v-if="action.canReview" class="mt-4 flex flex-wrap items-center gap-2">
                    <template v-if="rejectingId !== action.id">
                        <button type="button" class="btn-glow rounded-xl px-4 py-2 text-sm font-semibold" @click="approve(action)">{{ t('aiQueue.actions.approve') }}</button>
                        <button type="button" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink hover:bg-euca-50" @click="openReject(action.id)">{{ t('aiQueue.actions.reject') }}</button>
                    </template>
                    <div v-else class="w-full space-y-2">
                        <textarea
                            v-model="rejectForm.reason"
                            :placeholder="t('aiQueue.actions.reasonPlaceholder')"
                            rows="2"
                            class="block w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink"
                        ></textarea>
                        <p v-if="rejectForm.errors.reason" class="text-xs text-danger">{{ rejectForm.errors.reason }}</p>
                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded-xl bg-danger px-4 py-2 text-sm font-semibold text-white hover:opacity-90" :disabled="rejectForm.processing" @click="confirmReject(action)">{{ t('aiQueue.actions.confirmReject') }}</button>
                            <button type="button" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink hover:bg-euca-50" @click="rejectingId = null">{{ t('aiQueue.actions.cancel') }}</button>
                        </div>
                    </div>
                </div>
                <p v-else class="mt-4 text-xs text-ink-subtle">{{ t('aiQueue.actions.noPermission') }}</p>
            </div>

            <!-- Recently resolved (read-only context). -->
            <Card :title="t('aiQueue.resolved.title')" :subtitle="t('aiQueue.resolved.subtitle')">
                <table v-if="resolved.length" class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('aiQueue.resolved.tool') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('aiQueue.resolved.status') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('aiQueue.resolved.reason') }}</th>
                            <th class="py-2 font-medium">{{ t('aiQueue.resolved.when') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="action in resolved" :key="action.id" class="border-b border-line/60">
                            <td class="py-2 pr-4 font-mono text-ink">{{ action.toolKey }}</td>
                            <td class="py-2 pr-4">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="action.status === 'executed' ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
                                >
                                    {{ t(`aiQueue.status.${action.status}`) }}
                                </span>
                            </td>
                            <td class="py-2 pr-4 text-ink-muted">{{ action.rejectionReason ?? '—' }}</td>
                            <td class="py-2 text-ink-subtle">{{ dateTime(action.resolvedAt) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-sm text-ink-muted">{{ t('aiQueue.resolved.empty') }}</p>
            </Card>
        </div>
    </AppLayout>
</template>
