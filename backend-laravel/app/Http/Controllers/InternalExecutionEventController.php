<?php

namespace App\Http\Controllers;

use App\Http\Requests\Execution\InternalExecutionEventRequest;
use App\Models\WorkflowRun;
use App\Services\ExecutionEventService;
use Illuminate\Http\JsonResponse;

class InternalExecutionEventController extends Controller
{
    public function __invoke(
        InternalExecutionEventRequest $request,
        WorkflowRun $run,
        ExecutionEventService $events,
    ): JsonResponse {
        $applied = $events->apply($run, $request->validated());

        return response()->json([
            'data' => [
                'accepted' => true,
                'duplicate' => ! $applied,
            ],
            'message' => $applied ? 'Execution event persisted.' : 'Execution event already persisted.',
            'errors' => null,
        ]);
    }
}
