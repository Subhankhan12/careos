<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import DentalSectionNav from '@/Components/DentalSectionNav.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

interface Reading {
    id: string;
    reading: string;
    reason: string | null;
    read_by: number;
    read_at: string;
}
interface DentalImage {
    id: string;
    image_type: string;
    tooth: string | null;
    region: string | null;
    captured_at: string;
    uploaded_by: number;
    mime_type: string | null;
    original_filename: string | null;
    file_url: string;
    reading_url: string;
    readings: Reading[];
}

const props = defineProps<{
    patient: { id: string; mrn: string; name: string };
    images: DentalImage[];
    types: string[];
    teeth: { permanent: string[]; primary: string[] };
    actions: { can_manage: boolean; store_url: string };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// Upload (multipart via Inertia useForm).
const uploadForm = useForm<{ file: File | null; image_type: string; tooth: string; region: string }>({
    file: null,
    image_type: props.types[0] ?? 'bitewing',
    tooth: '',
    region: '',
});
function onFile(e: Event): void {
    const target = e.target as HTMLInputElement;
    uploadForm.file = target.files?.[0] ?? null;
}
function submitUpload(): void {
    uploadForm.post(props.actions.store_url, { forceFormData: true, preserveScroll: true, onSuccess: () => uploadForm.reset() });
}

// Viewer: select an image, zoom the raw pixels client-side (no analysis, no overlay).
const selectedId = ref<string | null>(props.images[0]?.id ?? null);
const selected = computed(() => props.images.find((i) => i.id === selectedId.value) ?? null);
const zoom = ref(1);
function select(id: string): void {
    selectedId.value = id;
    zoom.value = 1;
}
function zoomIn(): void {
    zoom.value = Math.min(4, Math.round((zoom.value + 0.25) * 100) / 100);
}
function zoomOut(): void {
    zoom.value = Math.max(1, Math.round((zoom.value - 0.25) * 100) / 100);
}

// The dentist's reading (their own written interpretation — nothing is generated).
const reading = ref('');
const reason = ref('');
function saveReading(): void {
    if (!selected.value || !reading.value.trim()) return;
    router.post(selected.value.reading_url, { reading: reading.value, reason: reason.value }, {
        preserveScroll: true,
        onSuccess: () => {
            reading.value = '';
            reason.value = '';
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('imaging.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('imaging.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('imaging.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ patient.name }} · <span class="font-mono">{{ patient.mrn }}</span></p>
                <p class="mt-1 max-w-2xl text-sm text-ink-subtle">{{ t('imaging.subtitle') }}</p>
            </div>

            <DentalSectionNav :patient-id="patient.id" active="images" />

            <p class="rounded-2xl border border-line bg-surface px-4 py-3 text-xs text-ink-subtle">{{ t('imaging.fenceNote') }}</p>

            <p v-if="flash" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">{{ t(`imaging.flash.${flash}`) }}</p>

            <!-- Upload (dentist/staff). -->
            <Card v-if="actions.can_manage" :title="t('imaging.upload.title')" :subtitle="t('imaging.upload.subtitle')">
                <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="submitUpload">
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('imaging.upload.file') }}</span>
                        <input type="file" accept="image/jpeg,image/png" class="block w-full text-sm text-ink" @change="onFile" />
                        <span v-if="uploadForm.errors.file" class="mt-1 block text-xs text-danger">{{ uploadForm.errors.file }}</span>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('imaging.upload.type') }}</span>
                        <select v-model="uploadForm.image_type" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink">
                            <option v-for="ty in types" :key="ty" :value="ty">{{ t(`imaging.types.${ty}`) }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('imaging.upload.tooth') }}</span>
                        <select v-model="uploadForm.tooth" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink">
                            <option value="">{{ t('imaging.upload.noTooth') }}</option>
                            <optgroup :label="t('imaging.dentition.permanent')">
                                <option v-for="tn in teeth.permanent" :key="tn" :value="tn">{{ tn }}</option>
                            </optgroup>
                            <optgroup :label="t('imaging.dentition.primary')">
                                <option v-for="tn in teeth.primary" :key="tn" :value="tn">{{ tn }}</option>
                            </optgroup>
                        </select>
                    </label>
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('imaging.upload.region') }}</span>
                        <input v-model="uploadForm.region" type="text" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink" />
                    </label>
                    <div class="sm:col-span-2">
                        <Button type="submit" :block="false" :disabled="uploadForm.processing || !uploadForm.file">{{ t('imaging.upload.submit') }}</Button>
                    </div>
                </form>
            </Card>

            <p v-if="!images.length" class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-muted">{{ t('imaging.empty') }}</p>

            <div v-else class="grid gap-6 lg:grid-cols-[16rem_1fr]">
                <!-- Gallery. -->
                <div class="space-y-2">
                    <button v-for="img in images" :key="img.id" type="button" class="flex w-full items-center gap-3 rounded-xl border p-2 text-left" :class="img.id === selectedId ? 'border-euca-400 bg-euca-50' : 'border-line hover:bg-surface'" @click="select(img.id)">
                        <img :src="img.file_url" :alt="img.image_type" class="h-12 w-12 rounded object-cover" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-ink">{{ t(`imaging.types.${img.image_type}`) }}<span v-if="img.tooth" class="text-ink-subtle"> · {{ img.tooth }}</span></span>
                            <span class="block text-xs text-ink-subtle">{{ new Date(img.captured_at).toLocaleDateString() }}</span>
                        </span>
                    </button>
                </div>

                <!-- Viewer + reading. -->
                <Card v-if="selected">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-semibold text-ink">{{ t(`imaging.types.${selected.image_type}`) }}<span v-if="selected.tooth" class="text-ink-subtle"> · {{ t('imaging.viewer.tooth') }} {{ selected.tooth }}</span></p>
                            <p class="text-xs text-ink-subtle">{{ new Date(selected.captured_at).toLocaleString() }}<span v-if="selected.region"> · {{ selected.region }}</span></p>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" class="rounded-lg border border-line px-2 py-1 text-sm text-ink" @click="zoomOut">−</button>
                            <span class="w-12 text-center text-xs text-ink-muted">{{ Math.round(zoom * 100) }}%</span>
                            <button type="button" class="rounded-lg border border-line px-2 py-1 text-sm text-ink" @click="zoomIn">+</button>
                        </div>
                    </div>

                    <!-- 2D viewer: raw image, client-side zoom/pan (scroll). No overlay, no annotation. -->
                    <div class="mt-3 max-h-[28rem] overflow-auto rounded-xl border border-line bg-black/90 p-2">
                        <img :src="selected.file_url" :alt="selected.image_type" class="mx-auto origin-top-left transition-transform" :style="{ transform: `scale(${zoom})` }" />
                    </div>

                    <!-- The dentist's reading. -->
                    <div class="mt-4">
                        <p class="text-sm font-semibold text-ink">{{ t('imaging.reading.title') }}</p>
                        <div v-if="selected.readings.length" class="mt-2 space-y-2">
                            <div v-for="r in selected.readings" :key="r.id" class="rounded-xl border border-line p-3">
                                <p class="whitespace-pre-line text-sm text-ink">{{ r.reading }}</p>
                                <p class="mt-1 text-xs text-ink-subtle">{{ new Date(r.read_at).toLocaleString() }}<span v-if="r.reason"> · {{ t('imaging.reading.reason') }}: {{ r.reason }}</span></p>
                            </div>
                        </div>
                        <p v-else class="mt-1 text-sm text-ink-muted">{{ t('imaging.reading.empty') }}</p>

                        <form v-if="actions.can_manage" class="mt-3 space-y-2" @submit.prevent="saveReading">
                            <textarea v-model="reading" rows="3" :placeholder="t('imaging.reading.placeholder')" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink"></textarea>
                            <input v-model="reason" type="text" :placeholder="t('imaging.reading.reasonPlaceholder')" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink" />
                            <Button type="submit" :block="false" :disabled="!reading.trim()">{{ t('imaging.reading.submit') }}</Button>
                        </form>
                    </div>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
