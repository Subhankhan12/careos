<?php

arch('Platform does not depend on Audit, AiCore, People, Patients, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\Platform')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Audit does not depend on Platform, AiCore, People, Patients, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\Audit')
    ->not->toUse([
        'Modules\Platform',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('AiCore may depend on Platform but not Audit, People, Patients, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\AiCore')
    ->not->toUse([
        'Modules\Audit',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('People does not depend on Audit, AiCore, Patients, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\People')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Patients does not depend on Audit models, AiCore, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\Patients')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Scheduling does not depend on Audit models, AiCore, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\Scheduling')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Clinical may use care modules but not Audit models, AiCore, Nursing, Billing, or Comms')
    ->expect('Modules\Clinical')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Nursing may use care modules but not Audit models, AiCore, Billing, or Comms')
    ->expect('Modules\Nursing')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Billing may use care modules but not Audit models, AiCore, or Comms')
    ->expect('Modules\Billing')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Comms',
    ]);

arch('Comms may use care modules but not Audit models or AiCore')
    ->expect('Modules\Comms')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
    ]);

arch('Import may use Patients + Audit services but not Audit models, AiCore, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\Import')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('FrontDesk may use Patients + Scheduling + Audit services but not Audit models, AiCore, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\FrontDesk')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
        'Modules\Import',
    ]);

// Reporting is a READ-ONLY aggregation layer: it may read care modules' data
// through their query surfaces but never writes, and never touches Audit models,
// AiCore, Comms, Import, or FrontDesk (check-in data lives on appointments).
arch('Reporting may read care modules but not Audit models, AiCore, Comms, Import, or FrontDesk')
    ->expect('Modules\Reporting')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Comms',
        'Modules\Import',
        'Modules\FrontDesk',
    ]);

// Dental is a clinical vertical: it may use Patients/Scheduling/Clinical/Billing +
// Audit SERVICES (LogsReads / AuditService), but never Audit models directly, AiCore,
// Nursing, or Comms. Cross-module guards that need another module live in app/.
arch('Dental may use care modules + Audit services but not Audit models, AiCore, Nursing, or Comms')
    ->expect('Modules\Dental')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Comms',
    ]);

// Hospital is the inpatient/ADT vertical (HOSPITAL.G1): it may use Platform +
// care modules (Patients/Scheduling/Clinical/Billing) + Audit SERVICES, but never
// Audit models directly, AiCore, the peer Nursing vertical, or Comms. Cross-module
// composition (audit of bed/ward changes) lives in app/, so Hospital stays free of
// Audit — the same posture as Dental.
arch('Hospital may use care modules + Audit services but not Audit models, AiCore, Nursing, or Comms')
    ->expect('Modules\Hospital')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Comms',
    ]);

// Pharmacy is the medication-management vertical (PHARMACY.G1 — Phase 2). It may use Platform + care
// modules (Patients/Clinical/Billing) + Audit SERVICES, but never Audit models directly, AiCore, the peer
// verticals (Nursing/Dental/Hospital — the inpatient stay-link is composed at the app layer, not by a
// direct dependency), or Comms. Cross-module audit composition lives in app/, so Pharmacy stays free of
// Audit — the Dental/Hospital posture.
arch('Pharmacy may use care modules + Audit services but not Audit models, AiCore, peer verticals, or Comms')
    ->expect('Modules\Pharmacy')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Dental',
        'Modules\Hospital',
        'Modules\Comms',
    ]);

// Surgery is the operating-theatre / peri-operative vertical (SURGERY.G1 — Phase 5). It may use Platform +
// care modules (Patients/People/Clinical/Billing/Scheduling) + Audit SERVICES, but never Audit models
// directly, AiCore, the peer verticals (Nursing/Dental/Hospital/Pharmacy — the inpatient stay-link is a soft
// app-layer id, not a direct dependency), or Comms. Cross-module audit composition lives in app/, so Surgery
// stays free of Audit — the Dental/Hospital/Pharmacy posture.
arch('Surgery may use care modules + Audit services but not Audit models, AiCore, peer verticals, or Comms')
    ->expect('Modules\Surgery')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Dental',
        'Modules\Hospital',
        'Modules\Pharmacy',
        'Modules\Comms',
    ]);

// The Emergency Department is the ED patient-flow vertical (ED.G1 — Phase 6). It may use Platform + care
// modules (Patients/People/Clinical/Billing/Scheduling) + Audit SERVICES, but never Audit models directly,
// AiCore, or Comms. It is a PEER vertical: the ED→ADT admit handoff (G5) is a soft app-layer id (a future
// soft `stay_id`), NOT a direct Hospital dependency, so ED stays independent of the peer verticals
// (Nursing/Dental/Hospital/Pharmacy/Surgery). Its own `TriageAcuityProvider` seam MIRRORS Pharmacy's
// `MedicationSafetyProvider` but references it by name only (no import — no peer dependency). Cross-module
// audit composition lives in app/, so ED stays free of Audit — the Dental/Hospital/Pharmacy/Surgery posture.
arch('ED may use care modules + Audit services but not Audit models, AiCore, peer verticals, or Comms')
    ->expect('Modules\ED')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Dental',
        'Modules\Hospital',
        'Modules\Pharmacy',
        'Modules\Surgery',
        'Modules\Comms',
    ]);

// The Laboratory / LIS is the lab vertical (LAB.G1 — Phase 3). It REUSES Clinical heavily — a lab test IS a
// Clinical `OrderableItem` (overlaid), a lab order IS a Clinical `Order`, a lab result IS a Clinical
// `OrderResult`, and the `LabConnectivity` seam lives in Clinical (consumed, not re-created). So it MAY use
// Platform + care modules (Clinical/Patients/Billing) + Audit SERVICES — but never Audit models directly,
// AiCore, or the PEER verticals (Nursing/Dental/Hospital/Pharmacy/Surgery/ED). Cross-module audit composition
// lives in app/, so Lab stays free of Audit — the ED/Surgery/Pharmacy posture.
arch('Lab may use care modules + Audit services but not Audit models, AiCore, peer verticals, or Comms')
    ->expect('Modules\Lab')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Dental',
        'Modules\Hospital',
        'Modules\Pharmacy',
        'Modules\Surgery',
        'Modules\ED',
        'Modules\Comms',
    ]);

// The Radiology / RIS is the radiology vertical (RAD.G1 — Phase 4). It REUSES Clinical heavily — an imaging
// exam IS a Clinical `OrderableItem` (overlaid), an imaging order IS a Clinical `Order`, a report IS a Clinical
// `ClinicalNote`, an uploaded still IS a Clinical `Document` — and it OWNS the created `ImagingConnectivity`
// (PACS/DICOM) seam. So it MAY use Platform + care modules (Clinical/Patients/Billing) + Audit SERVICES — but
// never Audit models directly, AiCore, or the PEER verticals (Nursing/Dental/Hospital/Pharmacy/Surgery/ED/Lab).
// Cross-module audit composition lives in app/, so Radiology stays free of Audit — the ED/Surgery/Lab posture.
arch('Radiology may use care modules + Audit services but not Audit models, AiCore, peer verticals, or Comms')
    ->expect('Modules\Radiology')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Dental',
        'Modules\Hospital',
        'Modules\Pharmacy',
        'Modules\Surgery',
        'Modules\ED',
        'Modules\Lab',
        'Modules\Comms',
    ]);
