<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

interface Batch {
    id: string;
    status: string;
    original_filename: string;
    row_count: number;
    headers: string[];
    mapping: Record<string, string>;
    date_format: string | null;
    duplicate_policy: string | null;
    summary: {
        counts?: Record<string, number>;
        ignored_columns?: string[];
        commit?: { counts?: Record<string, number>; policy?: string };
    } | null;
    policies: string[];
    rows: Array<{
        row_number: number;
        status: string;
        errors: Record<string, string> | null;
        matched_patient_id: string | null;
        match: { score: number; confidence: string; reasons: string[] } | null;
        raw: Record<string, string>;
    }>;
    urls: { mapping: string; validate: string; commit: string };
}

const props = defineProps<{
    batch: Batch | null;
    fieldCatalog: Array<{ key: string; group: string; required: boolean }>;
    dateFormats: string[];
    storeUrl: string;
}>();

// --- Upload (create) ---
const uploadForm = useForm<{ file: File | null }>({ file: null });
function submitUpload(): void {
    uploadForm.post(props.storeUrl, { forceFormData: true });
}
function onFile(event: Event): void {
    const target = event.target as HTMLInputElement;
    uploadForm.file = target.files?.[0] ?? null;
}

// --- Mapping ---
const initialMapping: Record<string, string> = {};
if (props.batch) {
    for (const header of props.batch.headers) {
        initialMapping[header] = props.batch.mapping?.[header] ?? '';
    }
}
const mappingForm = useForm<{ mapping: Record<string, string>; date_format: string }>({
    mapping: initialMapping,
    date_format: props.batch?.date_format ?? props.dateFormats[0],
});
function saveMapping(): void {
    if (!props.batch) return;
    mappingForm.post(props.batch.urls.mapping);
}

// --- Dry-run ---
const validateForm = useForm({});
function runDryRun(): void {
    if (!props.batch) return;
    // Save the CURRENT column mapping first, then validate against it — so the dry-run always
    // reflects the mapping on screen. Otherwise it validates the last SAVED mapping and can
    // report the required fields as unmapped even though the dropdowns are set (the audit trap).
    const mappingUrl = props.batch.urls.mapping;
    const validateUrl = props.batch.urls.validate;
    mappingForm.post(mappingUrl, {
        preserveScroll: true,
        onSuccess: () => validateForm.post(validateUrl, { preserveScroll: true }),
    });
}

// --- Commit ---
const commitForm = useForm<{ duplicate_policy: string }>({
    duplicate_policy: props.batch?.duplicate_policy ?? 'skip',
});
function commit(): void {
    if (!props.batch) return;
    commitForm.post(props.batch.urls.commit);
}

const counts = computed(() => props.batch?.summary?.counts ?? null);
const commitCounts = computed(() => props.batch?.summary?.commit?.counts ?? null);
const isValidated = computed(() => props.batch?.status === 'validated');
const isCommitted = computed(() => props.batch?.status === 'committed');
const canMap = computed(() => props.batch && props.batch.headers.length > 0 && !isCommitted.value);
</script>

<template>
    <AppLayout>
        <Head :title="t('import.upload.title')" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ t('import.upload.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('import.upload.subtitle') }}</p>
            </div>

            <!-- Step 1: upload -->
            <Card v-if="!batch" :title="t('import.upload.file')">
                <form class="space-y-4" @submit.prevent="submitUpload">
                    <input type="file" accept=".csv,text/csv" class="block w-full text-sm text-ink" @change="onFile" />
                    <p v-if="uploadForm.errors.file" class="text-sm text-danger">{{ uploadForm.errors.file }}</p>
                    <div class="w-48">
                        <Button type="submit" :disabled="uploadForm.processing || !uploadForm.file">{{ t('import.upload.upload') }}</Button>
                    </div>
                </form>
            </Card>

            <template v-if="batch">
                <p class="text-sm text-ink-muted">
                    {{ batch.original_filename }} · {{ batch.row_count }} {{ t('import.index.rows') }} · {{ batch.status }}
                </p>

                <!-- Step 2: mapping -->
                <Card v-if="canMap" :title="t('import.upload.mapTitle')">
                    <p class="mb-4 text-sm text-ink-muted">{{ t('import.upload.mapHint') }}</p>
                    <form class="space-y-3" @submit.prevent="saveMapping">
                        <div v-for="header in batch.headers" :key="header" class="flex items-center gap-3">
                            <span class="w-1/3 truncate text-sm text-ink">{{ header }}</span>
                            <select v-model="mappingForm.mapping[header]" class="w-2/3 rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                <option value="">{{ t('import.upload.ignore') }}</option>
                                <option v-for="field in fieldCatalog" :key="field.key" :value="field.key">
                                    {{ field.key }}<template v-if="field.required"> *</template>
                                </option>
                            </select>
                        </div>
                        <div class="flex items-center gap-3 pt-2">
                            <span class="w-1/3 text-sm font-medium text-ink">{{ t('import.upload.dateFormat') }}</span>
                            <select v-model="mappingForm.date_format" class="w-2/3 rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                <option v-for="format in dateFormats" :key="format" :value="format">{{ format }}</option>
                            </select>
                        </div>
                        <p v-if="mappingForm.errors.mapping" class="text-sm text-danger">{{ mappingForm.errors.mapping }}</p>
                        <div class="flex gap-3 pt-2">
                            <div class="w-48"><Button type="submit" variant="secondary" :disabled="mappingForm.processing">{{ t('import.upload.saveMapping') }}</Button></div>
                            <div class="w-64"><Button type="button" :disabled="validateForm.processing" @click="runDryRun">{{ t('import.upload.dryRun') }}</Button></div>
                        </div>
                        <p class="text-xs text-ink-subtle">{{ t('import.upload.dryRunNote') }}</p>
                        <p v-if="validateForm.errors.validation" class="text-sm text-danger">{{ validateForm.errors.validation }}</p>
                    </form>
                </Card>

                <!-- Step 3: dry-run summary -->
                <Card v-if="counts" :title="t('import.upload.summary')">
                    <div class="flex flex-wrap gap-6 text-sm">
                        <div><span class="text-ink-muted">{{ t('import.upload.valid') }}:</span> <span class="font-semibold text-ink">{{ counts.valid }}</span></div>
                        <div><span class="text-ink-muted">{{ t('import.upload.invalid') }}:</span> <span class="font-semibold text-ink">{{ counts.invalid }}</span></div>
                        <div><span class="text-ink-muted">{{ t('import.upload.duplicate') }}:</span> <span class="font-semibold text-ink">{{ counts.duplicate }}</span></div>
                    </div>
                    <p v-if="batch.summary?.ignored_columns?.length" class="mt-3 text-xs text-ink-subtle">
                        {{ t('import.upload.ignoredColumns') }}: {{ batch.summary.ignored_columns.join(', ') }}
                    </p>
                </Card>

                <!-- Row preview -->
                <Card v-if="batch.rows.length">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-ink-muted">
                                <tr class="border-b border-line">
                                    <th class="py-2 pr-4 font-medium">{{ t('import.upload.rowNumber') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ t('import.upload.rowStatus') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ t('import.upload.rowErrors') }}</th>
                                    <th class="py-2 font-medium">{{ t('import.upload.rowMatch') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in batch.rows" :key="row.row_number" class="border-b border-line/60 align-top">
                                    <td class="py-2 pr-4 text-ink">{{ row.row_number }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">{{ row.status }}</td>
                                    <td class="py-2 pr-4 text-danger">
                                        <span v-for="(message, field) in (row.errors ?? {})" :key="field">{{ field }}: {{ message }}<br /></span>
                                    </td>
                                    <td class="py-2 text-ink-muted">
                                        <span v-if="row.match">{{ row.match.confidence }} ({{ row.match.score }})</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <!-- Step 4: commit -->
                <Card v-if="isValidated" :title="t('import.upload.commit')">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                        <label class="block text-sm">
                            <span class="mb-1.5 block font-medium text-ink">{{ t('import.upload.duplicatePolicy') }}</span>
                            <select v-model="commitForm.duplicate_policy" class="w-72 rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                <option value="skip">{{ t('import.upload.policySkip') }}</option>
                                <option value="import_as_new">{{ t('import.upload.policyImportAsNew') }}</option>
                                <option value="merge">{{ t('import.upload.policyMerge') }}</option>
                            </select>
                        </label>
                        <div class="w-48"><Button type="button" :disabled="commitForm.processing" @click="commit">{{ t('import.upload.commit') }}</Button></div>
                    </div>
                    <p v-if="commitForm.errors.commit" class="mt-2 text-sm text-danger">{{ commitForm.errors.commit }}</p>
                </Card>

                <!-- Committed -->
                <Card v-if="isCommitted">
                    <p class="text-sm font-medium text-ink">{{ t('import.upload.committed') }}</p>
                    <div v-if="commitCounts" class="mt-3 flex flex-wrap gap-6 text-sm">
                        <div><span class="text-ink-muted">{{ t('import.upload.imported') }}:</span> <span class="font-semibold text-ink">{{ commitCounts.imported }}</span></div>
                        <div><span class="text-ink-muted">{{ t('import.upload.skipped') }}:</span> <span class="font-semibold text-ink">{{ commitCounts.skipped }}</span></div>
                        <div><span class="text-ink-muted">{{ t('import.upload.invalid') }}:</span> <span class="font-semibold text-ink">{{ commitCounts.invalid }}</span></div>
                    </div>
                </Card>

                <Link href="/imports" class="inline-block text-sm font-medium text-brand-600 hover:text-brand-700">← {{ t('import.index.title') }}</Link>
            </template>
        </div>
    </AppLayout>
</template>
