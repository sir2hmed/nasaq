<?php

namespace App\Http\Controllers;

use App\Http\Requests\Workflow\StoreWorkflowRequest;
use App\Http\Requests\Workflow\UpdateWorkflowRequest;
use App\Http\Resources\WorkflowResource;
use App\Models\Workflow;
use App\Services\WorkflowGraphSynchronizer;
use App\Services\WorkflowGraphValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowGraphSynchronizer $synchronizer) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Workflow::class);

        $perPage = min(max($request->integer('per_page', 20), 1), 50);
        $workflows = $request->user()
            ->workflows()
            ->with('latestRun')
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'workflows' => WorkflowResource::collection($workflows->items())->resolve($request),
            ],
            'message' => 'Workflows retrieved.',
            'errors' => null,
            'meta' => [
                'current_page' => $workflows->currentPage(),
                'last_page' => $workflows->lastPage(),
                'per_page' => $workflows->perPage(),
                'total' => $workflows->total(),
            ],
        ]);
    }

    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        Gate::authorize('create', Workflow::class);

        $workflow = DB::transaction(function () use ($request): Workflow {
            $workflow = $request->user()->workflows()->create($this->payload($request->validated()));
            $this->synchronizer->sync($workflow);

            return $workflow;
        });

        return $this->workflowResponse($request, $workflow, 'Workflow created.', 201);
    }

    public function show(Request $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('view', $workflow);

        return $this->workflowResponse($request, $workflow, 'Workflow retrieved.');
    }

    public function update(UpdateWorkflowRequest $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('update', $workflow);

        DB::transaction(function () use ($request, $workflow): void {
            $workflow->update($this->payload($request->validated()));
            $this->synchronizer->sync($workflow);
        });

        return $this->workflowResponse($request, $workflow->refresh(), 'Workflow updated.');
    }

    public function destroy(Request $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('delete', $workflow);
        $workflow->delete();

        return response()->json([
            'data' => null,
            'message' => 'Workflow deleted.',
            'errors' => null,
        ]);
    }

    public function duplicate(Request $request, Workflow $workflow): JsonResponse
    {
        Gate::authorize('view', $workflow);
        Gate::authorize('create', Workflow::class);

        $copy = DB::transaction(function () use ($request, $workflow): Workflow {
            $name = $workflow->name.' (Copy)';
            $graph = $workflow->graph_json;
            $graph['name'] = $name;

            $copy = $request->user()->workflows()->create([
                'name' => $name,
                'description' => $workflow->description,
                'graph_json' => $graph,
                'status' => 'draft',
                'version' => $workflow->version,
                'last_run_at' => null,
            ]);
            $this->synchronizer->sync($copy);

            return $copy;
        });

        return $this->workflowResponse($request, $copy, 'Workflow duplicated.', 201);
    }

    public function validateGraph(
        Request $request,
        Workflow $workflow,
        WorkflowGraphValidator $validator,
    ): JsonResponse {
        Gate::authorize('view', $workflow);

        return response()->json([
            'data' => [
                'validation' => $validator->validate($workflow->graph_json),
            ],
            'message' => 'Workflow validation completed.',
            'errors' => null,
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function payload(array $validated): array
    {
        $graph = $validated['graph_json'];
        $graph['version'] = 1;
        $graph['name'] = $validated['name'];
        $graph['description'] = $validated['description'];

        return [
            'name' => $validated['name'],
            'description' => $validated['description'],
            'graph_json' => $graph,
            'status' => $validated['status'],
            'version' => 1,
        ];
    }

    private function workflowResponse(
        Request $request,
        Workflow $workflow,
        string $message,
        int $status = 200,
    ): JsonResponse {
        $workflow->loadMissing('latestRun');

        return response()->json([
            'data' => [
                'workflow' => WorkflowResource::make($workflow)->resolve($request),
            ],
            'message' => $message,
            'errors' => null,
        ], $status);
    }
}
