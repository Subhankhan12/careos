<?php

namespace Modules\Clinical\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\ClinicalNote;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;

class UnsignedNotesWorklist
{
    /**
     * @return Collection<int, ClinicalNote>
     */
    public function olderThan(User $actor, int $thresholdDays): Collection
    {
        $query = ClinicalNote::query()
            ->where('status', ClinicalNote::STATUS_DRAFT)
            ->where('created_at', '<=', now()->subDays($thresholdDays))
            ->orderBy('created_at');

        if (! Gate::forUser($actor)->allows('note.supervise')) {
            $authorIds = StaffProfile::query()
                ->where('user_id', $actor->id)
                ->pluck('id')
                ->all();

            $query->whereIn('author_id', $authorIds);
        }

        return $query->get();
    }
}
