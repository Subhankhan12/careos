<?php

use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Services\NullMedicationSafetyProvider;
use Modules\Pharmacy\Support\MedicationReference;
use Modules\Pharmacy\Support\SafetyAlert;
use Modules\Pharmacy\Support\SafetyContext;
use Modules\Pharmacy\Support\SafetyResult;

/*
 * PHARMACY.G1 — the MEDICATION-SAFETY SEAM. Built EMPTY (a null-object), mirroring ManualLabConnectivity.
 * CareOS performs NO drug-interaction / dose / contraindication / duplicate-therapy checking — that is
 * certified-partner / medical-device territory and a PERMANENT homemade non-goal (it would contradict the
 * clinical-safety eval). These tests prove the seam EXISTS + is EMPTY (asserts no judgment) + is SWAPPABLE.
 */

function seamContext(): SafetyContext
{
    // A classic interacting pair — a real engine would flag it; CareOS's null-object asserts nothing.
    return new SafetyContext('patient-1', [
        new MedicationReference('MED-WARFARIN-5', 'Warfarin', '5 mg', 'oral'),
        new MedicationReference('MED-ASPIRIN-100', 'Aspirin', '100 mg', 'oral'),
    ]);
}

test('the medication-safety seam is bound to the null-object and asserts no safety judgment (no alerts)', function () {
    $provider = app(MedicationSafetyProvider::class);

    // The shipped implementation is the null-object — the ONLY implementation CareOS ships (no homemade checker).
    expect($provider)->toBeInstanceOf(NullMedicationSafetyProvider::class);

    // It asserts nothing about safety: every check returns no alerts, even for a classic interacting pair.
    $order = $provider->checkOrder(seamContext());
    $admin = $provider->checkAdministration(seamContext());
    expect($order)->toBeInstanceOf(SafetyResult::class)
        ->and($order->hasAlerts())->toBeFalse()
        ->and($order->alerts)->toBe([])
        ->and($admin->hasAlerts())->toBeFalse()
        ->and($admin->alerts)->toBe([]);
});

test('SafetyResult::none() is the empty, no-judgment default', function () {
    expect(SafetyResult::none()->hasAlerts())->toBeFalse()
        ->and(SafetyResult::none()->alerts)->toBe([]);
});

test('the seam is real and swappable: a certified-partner double can be bound in place of the null-object', function () {
    // A stand-in for a future CERTIFIED PARTNER engine — proving the interface is a genuine integration
    // point a partner implements WITHOUT touching any consumer. CareOS itself ships only the null-object.
    $partner = new class implements MedicationSafetyProvider
    {
        public function checkOrder(SafetyContext $context): SafetyResult
        {
            return new SafetyResult([new SafetyAlert('PARTNER-INT-001', 'Advisory: interaction (partner)', 'certified-partner')]);
        }

        public function checkAdministration(SafetyContext $context): SafetyResult
        {
            return SafetyResult::none();
        }
    };

    app()->instance(MedicationSafetyProvider::class, $partner);

    $resolved = app(MedicationSafetyProvider::class);
    expect($resolved)->toBe($partner)
        ->and($resolved->checkOrder(seamContext())->hasAlerts())->toBeTrue()
        ->and($resolved->checkOrder(seamContext())->alerts[0]->source)->toBe('certified-partner');

    // Restore the shipped default — the null-object.
    app()->forgetInstance(MedicationSafetyProvider::class);
    expect(app(MedicationSafetyProvider::class))->toBeInstanceOf(NullMedicationSafetyProvider::class);
});
