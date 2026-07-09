<?php

namespace Modules\Nursing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Nursing\Models\VisitAttachment;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Resource;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NurseVisitAttachmentController
{
    public function __invoke(Request $request, VisitAttachment $attachment): StreamedResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->tokenCan('nurse:day-pack')) {
            abort(403);
        }

        $staffIds = StaffProfile::query()
            ->where('user_id', $user->id)
            ->where('status', StaffProfile::STATUS_ACTIVE)
            ->pluck('id');

        $canAccess = Resource::query()
            ->where('type', Resource::TYPE_PRACTITIONER)
            ->where('active', true)
            ->whereIn('staff_profile_id', $staffIds)
            ->whereKey($attachment->visit()->value('resource_id'))
            ->exists();

        if (! $canAccess) {
            abort(403);
        }

        return Storage::disk('local')->download(
            $attachment->storage_path,
            $attachment->type.'.'.pathinfo($attachment->storage_path, PATHINFO_EXTENSION),
            ['Content-Type' => $attachment->mime_type],
        );
    }
}
