<?php

namespace Modules\Scheduling\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\AppointmentSeries;
use Modules\Scheduling\Services\AppointmentSeriesService;

class AppointmentSeriesController
{
    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'patient_id' => ['required', 'string'],
            'service_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'resource_ids' => ['required', 'array', 'min:1'],
            'resource_ids.*' => ['required', 'string'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'interval' => ['nullable', 'integer', 'min:1', 'max:52'],
            'byday' => ['nullable', 'array'],
            'byday.*' => ['string', 'in:MO,TU,WE,TH,FR,SA,SU'],
            'start_time' => ['required', 'string', 'max:5'],
            'starts_on' => ['required', 'date'],
            'end_type' => ['required', 'string', 'in:count,until'],
            'count' => ['nullable', 'integer', 'min:1', 'max:104'],
            'ends_on' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function preview(Request $request, AppointmentSeriesService $series): JsonResponse
    {
        $data = $request->validate($this->rules());
        Gate::authorize('appointment.manage', ['branch_id' => $data['branch_id']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return response()->json($series->preview($data, $actor));
    }

    public function store(Request $request, AppointmentSeriesService $series): RedirectResponse
    {
        $data = $request->validate($this->rules());
        Gate::authorize('appointment.manage', ['branch_id' => $data['branch_id']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $result = $series->create($data, $actor);

        return back()->with('series_result', [
            'series_id' => $result['series']->id,
            'booked' => $result['booked'],
            'failures' => $result['failures'],
        ]);
    }

    public function end(Request $request, AppointmentSeriesService $series): RedirectResponse
    {
        $data = $request->validate(['series_id' => ['required', 'string']]);
        $model = AppointmentSeries::query()->findOrFail($data['series_id']);
        Gate::authorize('appointment.manage', ['branch_id' => $model->branch_id]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $series->end($model, $actor);

        return back();
    }
}
