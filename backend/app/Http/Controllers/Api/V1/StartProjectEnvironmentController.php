<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnvironmentDesiredState;
use App\Enums\EnvironmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProjectEnvironmentResource;
use App\Jobs\StartProjectEnvironment;
use App\Models\EnvironmentOperation;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StartProjectEnvironmentController extends Controller
{
    public function __invoke(Request $request, Project $project, ProjectEnvironment $environment): JsonResponse
    {
        $environment->setRelation('project', $project);
        Gate::authorize('start', $environment);

        [$environment, $operation] = DB::transaction(function () use ($request, $project, $environment): array {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $environment = $project->environments()->lockForUpdate()->findOrFail($environment->id);
            $environment->setRelation('project', $project);
            Gate::authorize('start', $environment);

            $operation = $environment->operations()->whereIn('status', ['pending', 'running'])->first();
            if ($operation !== null || in_array($environment->status, [EnvironmentStatus::Running, EnvironmentStatus::Ready], true)) {
                return [$environment, $operation];
            }

            abort_unless(in_array($environment->base_image, config('environments.approved_images'), true), 409, 'The environment image is no longer approved.');
            abort_unless(config('environments.runtime.enabled'), 503, 'Environment startup is not configured.');

            $operation = new EnvironmentOperation;
            $operation->requested_by = $request->user()->id;
            $operation->action = 'start';
            $operation->status = 'pending';
            $environment->operations()->save($operation);

            $environment->forceFill([
                'desired_state' => EnvironmentDesiredState::Running,
                'status' => $environment->runtime_reference === null ? EnvironmentStatus::Provisioning : EnvironmentStatus::Starting,
                'last_error' => null,
            ])->save();

            StartProjectEnvironment::dispatch($operation->id)->afterCommit();

            return [$environment, $operation];
        }, 3);

        return ProjectEnvironmentResource::make($environment)
            ->additional(['operation_id' => $operation?->id])
            ->response()->setStatusCode($operation === null ? 200 : 202);
    }
}
