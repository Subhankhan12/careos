<?php

namespace Modules\Hospital\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Hospital\Models\Handover;
use Modules\Hospital\Models\Stay;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Nursing shift handover (HOSPITAL.G5) — record + read a stay's SBAR handovers. The handover is a
 * structured record the OUTGOING NURSE authors; this service has NO interpretation/scoring logic —
 * it stores what the nurse wrote (record-not-judge) and returns the shift-by-shift history raw.
 * Append-only (model + DB triggers). Gated on `note.write` (the nursing roles hold it — no new
 * permission); tenant + patient scoped, fail-closed; the write is audited (app-layer Handover hook).
 */
class HandoverService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Record a shift handover for a stay — nurse-authored SBAR, append-only. The model validates the
     * shift + a non-empty Situation; nothing here computes or auto-populates any field.
     *
     * @param  array{situation?: string, background?: string|null, assessment?: string|null, recommendation?: string|null}  $sbar
     */
    public function record(User $actor, Stay $stay, string $shift, array $sbar, ?string $reason = null): Handover
    {
        Gate::forUser($actor)->authorize('note.write');

        if ($stay->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('stay_id', (string) $stay->id);
        }

        return Handover::query()->create([
            'patient_id' => $stay->patient_id,
            'stay_id' => $stay->id,
            'authored_by' => $actor->id,
            'shift' => $shift,
            'situation' => $sbar['situation'] ?? '',
            'background' => $sbar['background'] ?? null,
            'assessment' => $sbar['assessment'] ?? null,       // the nurse's OWN words — never computed
            'recommendation' => $sbar['recommendation'] ?? null,
            'reason' => $reason,
            'handed_over_at' => now(),
        ]);
    }

    /**
     * A stay's shift handover history, newest first — the raw shift trail (no interpretation).
     *
     * @return Collection<int, Handover>
     */
    public function history(Stay $stay): Collection
    {
        return Handover::query()
            ->where('stay_id', $stay->id)
            ->orderByDesc('handed_over_at')
            ->orderByDesc('id')
            ->get();
    }
}
