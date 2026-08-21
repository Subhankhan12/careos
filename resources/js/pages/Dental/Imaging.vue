<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import DentalSectionNav from '@/Components/DentalSectionNav.vue';
import PatientClinicalHeader from '@/Components/Clinical/PatientClinicalHeader.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

interface Reading {
    id: string;
    reading: string;
    reason: string | null;
    read_by: number;
    read_by_name: string | null;
    read_at: string;
}
interface DentalImage {
    id: string;
    image_type: string;
    tooth: string | null;
    region: string | null;
    captured_at: string;
    uploaded_by: number;
    uploaded_by_name: string | null;
    mime_type: string | null;
    original_filename: string | null;
    size_bytes: number | null;
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

/*
 * LIBRARY filters — REAL recorded attributes only (modality and tooth), plus newest/oldest
 * ordering on the recorded capture time. There is deliberately no "coverage", no "quality" and no
 * computed grouping: the backend records none of those, and each would be a verdict about the
 * pixels, which is the one thing this surface must never produce.
 */
const filterType = ref<string>('');
const filterTooth = ref<string>('');
const newestFirst = ref(true);

// The teeth that ACTUALLY appear on this patient's images — not the whole FDI universe.
const toothOptions = computed(() =>
    [...new Set(props.images.map((i) => i.tooth).filter((x): x is string => x !== null))].sort(),
);

const visibleImages = computed(() => {
    const rows = props.images.filter(
        (i) => (filterType.value === '' || i.image_type === filterType.value) && (filterTooth.value === '' || i.tooth === filterTooth.value),
    );
    // Order by the RECORDED capture time. Ordering is not ranking: nothing is scored.
    return [...rows].sort((a, b) =>
        newestFirst.value ? b.captured_at.localeCompare(a.captured_at) : a.captured_at.localeCompare(b.captured_at),
    );
});

function fileSize(bytes: number | null): string {
    // A plain byte count from the stored document, shown in KB. Storage housekeeping, not a
    // statement about the image.
    return bytes === null ? '' : `${Math.round(bytes / 1024)} kB`;
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

/*
 * PAN — drag the zoomed image inside its frame. Pure optics, exactly like zoom: it changes which
 * part of the stored pixels is on screen and records nothing. No mark is ever placed on the image.
 */
const panning = ref(false);
let panStartX = 0;
let panStartY = 0;
let panScrollX = 0;
let panScrollY = 0;
const frame = ref<HTMLElement | null>(null);

function startPan(e: MouseEvent): void {
    if (!frame.value) return;
    panning.value = true;
    panStartX = e.clientX;
    panStartY = e.clientY;
    panScrollX = frame.value.scrollLeft;
    panScrollY = frame.value.scrollTop;
}
function movePan(e: MouseEvent): void {
    if (!panning.value || !frame.value) return;
    frame.value.scrollLeft = panScrollX - (e.clientX - panStartX);
    frame.value.scrollTop = panScrollY - (e.clientY - panStartY);
}
function endPan(): void {
    panning.value = false;
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
            <!-- P1's shared S1 header (DENTAL-B.P6 adoption). -->
            <PatientClinicalHeader
                :patient="{ name: patient.name, mrn: patient.mrn }"
                :eyebrow="t('imaging.eyebrow')"
                :context="t('imaging.subtitle')"
            />

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
                <!-- Library: the REAL stored images, filtered by real recorded attributes. -->
                <div class="space-y-3">
                    <div class="space-y-2 rounded-2xl border border-line p-3">
                        <label class="block text-xs">
                            <span class="mb-1 block font-medium text-ink-muted">{{ t('imaging.library.type') }}</span>
                            <select v-model="filterType" class="w-full rounded-md border border-line bg-surface px-2 py-1.5 text-sm text-ink">
                                <option value="">{{ t('imaging.library.allTypes') }}</option>
                                <option v-for="ty in types" :key="ty" :value="ty">{{ t(`imaging.types.${ty}`) }}</option>
                            </select>
                        </label>
                        <label v-if="toothOptions.length" class="block text-xs">
                            <span class="mb-1 block font-medium text-ink-muted">{{ t('imaging.library.tooth') }}</span>
                            <select v-model="filterTooth" class="w-full rounded-md border border-line bg-surface px-2 py-1.5 text-sm text-ink">
                                <option value="">{{ t('imaging.library.allTeeth') }}</option>
                                <option v-for="tn in toothOptions" :key="tn" :value="tn">{{ tn }}</option>
                            </select>
                        </label>
                        <button type="button" class="w-full rounded-lg border border-line px-2 py-1.5 text-xs font-semibold text-ink hover:bg-euca-50" @click="newestFirst = !newestFirst">
                            {{ newestFirst ? t('imaging.library.newestFirst') : t('imaging.library.oldestFirst') }}
                        </button>
                        <p class="text-xs text-ink-subtle">{{ t('imaging.library.showing', { shown: visibleImages.length, total: images.length }) }}</p>
                    </div>

                    <p v-if="!visibleImages.length" class="rounded-xl border border-line p-3 text-sm text-ink-muted">{{ t('imaging.library.noMatch') }}</p>

                    <button v-for="img in visibleImages" :key="img.id" type="button" class="flex w-full items-start gap-3 rounded-xl border p-2 text-left" :class="img.id === selectedId ? 'border-euca-400 bg-euca-50' : 'border-line hover:bg-surface'" @click="select(img.id)">
                        <img :src="img.file_url" :alt="img.image_type" class="h-14 w-14 shrink-0 rounded object-cover" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-ink">{{ t(`imaging.types.${img.image_type}`) }}<span v-if="img.tooth" class="text-ink-subtle"> · {{ img.tooth }}</span></span>
                            <span class="block text-xs text-ink-subtle">{{ new Date(img.captured_at).toLocaleDateString() }}</span>
                            <span v-if="img.uploaded_by_name" class="block truncate text-xs text-ink-subtle">{{ t('imaging.library.capturedBy', { name: img.uploaded_by_name }) }}</span>
                            <span class="block truncate text-[0.65rem] text-ink-subtle">{{ img.original_filename }}<span v-if="img.size_bytes"> · {{ fileSize(img.size_bytes) }}</span></span>
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

                    <!-- 2D viewer: the raw stored image with client-side zoom + drag-to-pan.
                         OPTICS ONLY — no overlay, no system-generated mark, no measurement. -->
                    <div
                        ref="frame"
                        class="mt-3 max-h-[28rem] overflow-auto rounded-xl border border-line bg-black/90 p-2"
                        :class="panning ? 'cursor-grabbing' : 'cursor-grab'"
                        @mousedown.prevent="startPan"
                        @mousemove="movePan"
                        @mouseup="endPan"
                        @mouseleave="endPan"
                    >
                        <img :src="selected.file_url" :alt="selected.image_type" class="mx-auto origin-top-left select-none transition-transform" :style="{ transform: `scale(${zoom})` }" draggable="false" />
                    </div>
                    <p class="mt-1 text-xs text-ink-subtle">{{ t('imaging.viewer.opticsNote') }}</p>

                    <!-- The dentist's reading. -->
                    <div class="mt-4">
                        <p class="text-sm font-semibold text-ink">{{ t('imaging.reading.title') }}</p>
                        <div v-if="selected.readings.length" class="mt-2 space-y-2">
                            <div v-for="r in selected.readings" :key="r.id" class="rounded-xl border border-line p-3">
                                <p class="whitespace-pre-line text-sm text-ink">{{ r.reading }}</p>
                                <p class="mt-1 text-xs text-ink-subtle">{{ new Date(r.read_at).toLocaleString() }}<span v-if="r.read_by_name"> · {{ t('imaging.reading.by', { name: r.read_by_name }) }}</span><span v-if="r.reason"> · {{ t('imaging.reading.reason') }}: {{ r.reason }}</span></p>
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

            <!-- What this surface deliberately does NOT do, stated on the page rather than
                 quietly missing (the P5 precedent). -->
            <div class="rounded-2xl border border-line bg-surface-2/60 p-4">
                <p class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-subtle">{{ t('imaging.notes.title') }}</p>
                <ul class="mt-2 space-y-1.5 text-xs text-ink-muted">
                    <li>{{ t('imaging.notes.noAnalysis') }}</li>
                    <li>{{ t('imaging.notes.no3d') }}</li>
                    <li>{{ t('imaging.notes.noCapture') }}</li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
