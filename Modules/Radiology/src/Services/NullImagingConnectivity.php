<?php

namespace Modules\Radiology\Services;

use Modules\Clinical\Models\Order;
use Modules\Radiology\Contracts\ImagingConnectivity;
use RuntimeException;

/**
 * The ONLY {@see ImagingConnectivity} implementation CareOS ships — a null-object that performs NO PACS/DICOM
 * integration. It is the exact analogue of Clinical's `ManualLabConnectivity` (a no-op behind the
 * `LabConnectivity` seam) and ED's `NullTriageAcuityProvider`.
 *
 * WHY IT IS EMPTY (and stays empty): native DICOM study storage, the DICOM Modality Worklist push, a
 * diagnostic viewer, and PACS retrieval are the DEFINING RIS value and are PARTNER-GATED — a homemade DICOM/PACS
 * stack is a PERMANENT non-goal (docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §3/§7). This null-object therefore does
 * nothing on transmit (there is no modality worklist to push to; the study is worked manually) and reports that
 * automated ingestion is unavailable (studies are recorded manually; images are uploaded via `DocumentService`,
 * the dental recipe). Swap the binding for a certified PACS partner later, with NO consumer changes.
 *
 * THE FENCE: even a real partner's `ingestStudy` only RECORDS an imported study/report — it never interprets
 * the image. CareOS computes NO image finding / CAD / abnormality flag; "AI radiology" is a hard non-goal.
 */
class NullImagingConnectivity implements ImagingConnectivity
{
    /** No PACS/modality to transmit to — a no-op (the study is worked manually). */
    public function transmitOrder(Order $order): void
    {
        // Intentionally empty: no DICOM Modality Worklist push until a certified partner is bound.
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingestStudy(array $payload): void
    {
        throw new RuntimeException('Automated study/image ingestion is not available; studies are recorded manually and images are uploaded.');
    }
}
