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
