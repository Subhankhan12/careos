<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t, te } = useI18n();

type Line = {
    id: string;
    code: string | null;
    description: string;
    quantity: number;
    unit_price_minor: number;
    line_total_minor: number;
};
type CreditNote = {
    id: string;
    number: string | null;
    status: string;
    payer_name: string | null;
    payer_type: string;
    patient: { name: string; mrn: string | null } | null;
    issue_date: string | null;
    currency: string;
    subtotal_minor: number;
    vat_total_minor: number;
    total_minor: number;
    lines: Line[];
    against_invoice: { number: string | null; total_minor: number; show_url: string } | null;
};

const props = defineProps<{
    creditNote: CreditNote;
    creditNotesUrl: string;
}>();

function money(minor: number, currency = props.creditNote.currency): string {
    return `${(minor / 100).toFixed(2)} ${currency}`;
}
</script>

<template>
    <AppLayout>
        <Head :title="creditNote.number ?? t('billing.creditNotes.title')" />
        <div class="space-y-5">
            <!-- Breadcrumb -->
            <nav class="flex items-center gap-2 text-sm text-ink-muted">
                <Link :href="creditNotesUrl" class="hover:text-ink">{{ t('billing.creditNotes.title') }}</Link>
                <span aria-hidden="true">/</span>
                <span class="font-medium text-ink">{{ creditNote.number ?? '—' }}</span>
            </nav>

            <!-- Header tile -->
            <div class="euca-tile-dark flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight text-euca-50">{{ creditNote.number ?? '—' }}</h1>
                        <span class="rounded-full bg-danger-soft px-2.5 py-0.5 text-xs font-semibold text-danger">{{ t('billing.creditNotes.badge') }}</span>
                    </div>
                    <p class="mt-1 text-sm text-euca-200">{{ creditNote.payer_name ?? '—' }}</p>
                </div>
                <div class="text-left lg:text-right">
                    <p class="text-xs font-semibold uppercase tracking-wide text-euca-200">{{ t('billing.creditNotes.totalCredit') }}</p>
                    <p class="text-2xl font-semibold text-euca-50">{{ money(creditNote.total_minor) }}</p>
                </div>
            </div>

            <div class="glass-card space-y-6 p-6">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.creditNotes.creditedTo') }}</p>
                        <p class="mt-1 font-medium text-ink">{{ creditNote.payer_name ?? '—' }}</p>
                        <p v-if="creditNote.patient" class="text-sm text-ink-muted">{{ creditNote.patient.name }} · {{ creditNote.patient.mrn ?? '—' }}</p>
                    </div>
                    <div v-if="creditNote.against_invoice">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.creditNotes.against') }}</p>
                        <Link :href="creditNote.against_invoice.show_url" class="mt-1 inline-flex items-center gap-1 font-mono font-medium text-euca-700 hover:underline">
                            {{ creditNote.against_invoice.number ?? '—' }}
                        </Link>
                        <p class="text-sm text-ink-muted">{{ money(creditNote.against_invoice.total_minor) }}</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="py-2 pr-3 font-semibold">{{ t('billing.lines.code') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.lines.description') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('billing.lines.qty') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('billing.lines.unit') }}</th>
                                <th class="py-2 pl-3 text-right font-semibold">{{ t('billing.lines.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="line in creditNote.lines" :key="line.id">
                                <td class="py-3 pr-3 font-mono text-ink-muted">{{ line.code ?? '—' }}</td>
                                <td class="px-3 py-3 text-ink">{{ line.description }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink-muted">{{ line.quantity }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink-muted">{{ money(line.unit_price_minor) }}</td>
                                <td class="py-3 pl-3 text-right tabular-nums text-ink">{{ money(line.line_total_minor) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="ml-auto w-full max-w-xs space-y-1.5 text-sm">
                    <div class="flex justify-between text-ink-muted">
                        <span>{{ t('billing.totals.subtotal') }}</span>
                        <span class="tabular-nums">{{ money(creditNote.subtotal_minor) }}</span>
                    </div>
                    <div class="flex justify-between text-ink-muted">
                        <span>{{ t('billing.totals.vat') }}</span>
                        <span class="tabular-nums">{{ money(creditNote.vat_total_minor) }}</span>
                    </div>
                    <div class="flex justify-between border-t border-line pt-1.5 text-base font-semibold text-ink">
                        <span>{{ t('billing.totals.total') }}</span>
                        <span class="tabular-nums">{{ money(creditNote.total_minor) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
