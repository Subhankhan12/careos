<?php

namespace Modules\Radiology\Contracts;

use Modules\Clinical\Models\Order;
use Modules\Radiology\Services\NullImagingConnectivity;

/**
 * THE IMAGING-CONNECTIVITY (PACS / DICOM) SEAM (RAD.G1) — the clean integration point where a CERTIFIED
 * PACS/DICOM/modality partner attaches (per docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §3).
 *
 * This is DELIBERATELY an interface with only a null-object implementation ({@see NullImagingConnectivity}) —
 * EXACTLY the shape of Clinical's `LabConnectivity` → `ManualLabConnectivity`, Pharmacy's
 * `MedicationSafetyProvider` → `NullMedicationSafetyProvider`, and ED's `TriageAcuityProvider` →
 * `NullTriageAcuityProvider` (referenced by name — Radiology does not depend on a peer vertical). CareOS builds
 * the SEAM, NOT the integration:
 *
 *  - **Native DICOM study storage, the DICOM Modality Worklist (MWL) push to the machines, a diagnostic
 *    multi-series/slice VIEWER, and PACS query/retrieve** are the DEFINING value of a real RIS and are
 *    regulated, capital-heavy PARTNER territory. A homemade DICOM/PACS stack is a PERMANENT non-goal (§3/§7 of
 *    the map): CareOS ships the seam + the record-keeping (order / study-metadata / report / billing / an
 *    optional uploaded still via `DocumentService`), never the image pipeline.
 *  - When a certified PACS/DICOM partner is contracted, its implementation is bound here WITHOUT touching any
 *    consumer: {@see transmitOrder()} pushes the DICOM MWL entry for a placed imaging order, and
 *    {@see ingestStudy()} records an imported study/report the partner delivered.
 *
 * THE FENCE (§4): the seam NEVER interprets an image. An imported study is RECORDED (the image is the
 * partner's, the report is a human's) — CareOS computes NO image finding, NO CAD/auto-read, NO abnormality
 * flag, NO confidence score. "AI radiology" is a hard medical-device non-goal (the dental-imaging line), and
 * this seam does not cross it.
 *
 * Until a partner is bound, the shipped {@see NullImagingConnectivity} is a no-op: `transmitOrder` does
 * nothing (no MWL to push to), `ingestStudy` reports that automated ingestion is unavailable (studies are
 * recorded manually; images are uploaded). CareOS asserts nothing about images.
 */
interface ImagingConnectivity
{
    /**
     * Push a placed imaging {@see Order} to the modality worklist (the future DICOM MWL entry) — a
     * certified-partner call site. The shipped null-object ignores it (no-op): there is no PACS/modality to
     * transmit to today; the study is worked manually.
     */
    public function transmitOrder(Order $order): void;

    /**
     * Record an imported study/report a certified PACS partner delivered (the future inbound path) — it
     * RECORDS metadata/documents; it NEVER interprets the image (the fence). The shipped null-object throws:
     * automated ingestion is not available today (studies are recorded manually, images uploaded).
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingestStudy(array $payload): void;
}
