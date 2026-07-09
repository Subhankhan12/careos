<?php

namespace Modules\Nursing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Nursing\Exceptions\AssignmentValidationException;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Services\VisitAssignmentService;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Resource;

class DispatchActionController
{
    public function assign(Request $request, VisitAssignmentService $assignments): RedirectResponse
    {
        $data = $request->validate([
            'planned_visit_id' => ['required', 'string'],
            'resource_id' => ['required', 'string'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $assignments->assign(
                PlannedVisit::query()->findOrFail($data['planned_visit_id']),
                Resource::query()->findOrFail($data['resource_id']),
                $actor,
            );
        } catch (AssignmentValidationException $exception) {
            return back()->withErrors(['assignment' => implode(', ', $exception->reasons())]);
        }

        return back();
    }

    public function unassign(Request $request, VisitAssignmentService $assignments): RedirectResponse
    {
        $data = $request->validate([
            'planned_visit_id' => ['required', 'string'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $assignments->unassign(PlannedVisit::query()->findOrFail($data['planned_visit_id']), $actor);

        return back();
    }
}
