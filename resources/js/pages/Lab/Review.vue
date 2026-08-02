<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// The lab "results to review" worklist (LAB.G5) — PRESENTATIONAL. Closes the order → result → review loop. The
// ordering clinician's resulted lab orders, shown as FACTS (patient, test, raw result + displayed range, the
// recorded STAT flag, resulted-time). Reviewing REUSES the existing resulted → reviewed endpoint. THE FENCE:
// no computed priority/urgency ranking, no computed critical flag — staff MAY sort by the recorded flag or time
// (a fact), and the result stays raw value + displayed range (no colour-by-abnormal).
const { t, locale } = useI18n();

type ResultRow = { value: string | null; entered_at: string };
type OrderRow = {
    order_id: string | null;
    patient: string | null;
    patient_id: string;
    test: string | null;
    code: string | null;
    priority: string;
    reference: { unit: string | null; reference_range: string | null };
    results: ResultRow[];
    resulted_at: string | null;
    results_url: string;
};

const props = defineProps<{
    orders: OrderRow[];
    options: { priorities: string[] };
    reviewUrl: string;
}>();

// Client-side sort by a RECORDED FACT only — resulted-time (default) or the recorded STAT flag. Never a
// computed priority. The flag rank is a fixed presentation order of the recorded flag, not a computed urgency.
const sortKey = ref<'resulted' | 'stat'>('resulted');
const flagRank: Record<string, number> = { stat: 0, urgent: 1, routine: 2 };

const sorted = computed<OrderRow[]>(() => {
    const rows = [...props.orders];
    if (sortKey.value === 'stat') {
        rows.sort((a, b) => (flagRank[a.priority] ?? 9) - (flagRank[b.priority] ?? 9));
    } else {
        rows.sort((a, b) => (b.resulted_at ?? '').localeCompare(a.resulted_at ?? ''));
    }
    return rows;
});

function fmt(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function review(orderId: string | null): void {
    if (!orderId) return;
    router.post(props.reviewUrl, { order_id: orderId }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('lab.review.title')" />
        <div class="mx-auto max-w-5xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('lab.review.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('lab.review.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('lab.review.subtitle') }}</p>
            </div>

            <div class="glass-card p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-xs font-semibold text-ink-subtle">
                        {{ t('lab.review.sortBy') }}
                        <select v-model="sortKey" class="rounded-lg border border-euca-200 px-3 py-1.5 text-sm">
                            <option value="resulted">{{ t('lab.review.sortResulted') }}</option>
                            <option value="stat">{{ t('lab.review.sortStat') }}</option>
                        </select>
                    </label>
                </div>
                <p class="mt-2 text-xs text-ink-muted">{{ t('lab.review.sortHint') }}</p>

                <p v-if="!sorted.length" class="mt-4 text-sm text-ink-muted">{{ t('lab.review.empty') }}</p>
                <ul v-else class="mt-4 space-y-3">
                    <li v-for="o in sorted" :key="o.order_id ?? ''" class="rounded-lg border border-euca-100 p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <div>
                                <span class="font-semibold text-ink">{{ o.patient ?? '—' }}</span>
                                <span class="ml-2 text-sm text-ink-subtle">{{ o.code }} — {{ o.test }}</span>
                            </div>
                            <span class="rounded-full bg-ink/5 px-2.5 py-1 text-xs font-semibold text-ink">{{ t(`lab.orders.priorityValue.${o.priority}`) }}</span>
                        </div>

                        <!-- The RAW result beside the DISPLAYED range — no flag/colour-by-abnormal (G4's fence). -->
                        <div class="mt-2 flex flex-wrap items-baseline gap-4 text-sm">
                            <span class="font-semibold text-ink">{{ t('lab.review.result') }}: {{ o.results[0]?.value ?? t('lab.review.noResult') }}<span v-if="o.reference.unit" class="ml-1 text-xs font-normal text-ink-subtle">{{ o.reference.unit }}</span></span>
                            <span v-if="o.reference.reference_range" class="text-xs text-ink-subtle">{{ t('lab.review.reference') }}: {{ o.reference.reference_range }}</span>
                            <span class="text-xs text-ink-subtle">{{ t('lab.review.resultedAt') }}: {{ fmt(o.resulted_at) }}</span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" class="rounded-full bg-euca-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-euca-700" @click="review(o.order_id)">{{ t('lab.review.reviewBtn') }}</button>
                            <Link :href="o.results_url" class="rounded-full border border-euca-200 px-4 py-1.5 text-xs font-semibold text-ink transition hover:bg-euca-50">{{ t('lab.review.openResults') }}</Link>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
