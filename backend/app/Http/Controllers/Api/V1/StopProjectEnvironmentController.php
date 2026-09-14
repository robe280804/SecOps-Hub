<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnvironmentDesiredState;
use App\Enums\EnvironmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProjectEnvironmentResource;
use App\Jobs\StopProjectEnvironment;
use App\Models\EnvironmentOperation;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StopProjectEnvironmentController extends Controller
{
    public function __invoke(Request $request, Project $project, ProjectEnvironment $environment): JsonResponse
    {
        $environment->setRelation('project', $project);
        Gate::authorize('stop', $environment);
        [$environment, $operation] = DB::transaction(function () use ($request, $project, $environment): array {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $environment = $project->environments()->lockForUpdate()->findOrFail($environment->id);
            $environment->setRelation('project', $project);
            Gate::authorize('stop', $environment);
            $operation = $environment->operations()->whereIn('status', ['pending', 'running'])->first();
            if ($operation !== null) {
                abort_unless($operation->action === 'stop', 409, 'Wait for the current operation to finish.');

                return [$environment, $operation];
            }
            if ($environment->status === EnvironmentStatus::Stopped) {
                return [$environment, null];
            }
            abort_unless(config('environments.runtime.enabled'), 503, 'Environment runtime is not configured.');
            $operation = new EnvironmentOperation;
            $operation->requested_by = $request->user()->id;
            $operation->action = 'stop';
            $operation->status = 'pending';
            $environment->operations()->save($operation);
            $environment->forceFill([
                'desired_state' => EnvironmentDesiredState::Stopped,
                'status' => EnvironmentStatus::Stopping,
                'last_error' => null,
            ])->save();
            StopProjectEnvironment::dispatch($operation->id)->afterCommit();

            return [$environment, $operation];
        }, 3);

        return ProjectEnvironmentResource::make($environment)
            ->additional(['operation_id' => $operation?->id])
            ->response()->setStatusCode($operation === null ? 200 : 202);
    }
}
