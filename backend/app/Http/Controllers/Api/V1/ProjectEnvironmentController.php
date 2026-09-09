<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProjectEnvironmentRequest;
use App\Http\Requests\Api\V1\UpdateProjectEnvironmentRequest;
use App\Http\Resources\Api\V1\ProjectEnvironmentResource;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectEnvironmentController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [ProjectEnvironment::class, $project]);

        $environments = $project->environments()->orderBy('name')->orderBy('id')->paginate(15);
        $environments->getCollection()->each->setRelation('project', $project);

        return ProjectEnvironmentResource::collection($environments)->response()
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreProjectEnvironmentRequest $request, Project $project): JsonResponse
    {
        $validated = $request->validated();

        $environment = DB::transaction(function () use ($project, $validated): ProjectEnvironment {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::authorize('create', [ProjectEnvironment::class, $lockedProject]);

            abort_if(
                $lockedProject->environments()->count() >= config('environments.max_per_project'),
                409,
                'The environment limit for this project has been reached.',
            );

            $this->ensureUniqueName($lockedProject, $validated['name']);

            return $lockedProject->environments()->create($validated)->refresh();
        }, 3);

        return ProjectEnvironmentResource::make($environment)->response()->setStatusCode(201);
    }

    public function show(Project $project, ProjectEnvironment $environment): ProjectEnvironmentResource
    {
        $environment->setRelation('project', $project);
        Gate::authorize('view', $environment);

        return ProjectEnvironmentResource::make($environment);
    }

    public function update(UpdateProjectEnvironmentRequest $request, Project $project, ProjectEnvironment $environment): ProjectEnvironmentResource
    {
        $validated = $request->validated();

        $environment = DB::transaction(function () use ($project, $environment, $validated): ProjectEnvironment {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $lockedEnvironment = $lockedProject->environments()->lockForUpdate()->findOrFail($environment->id);
            $lockedEnvironment->setRelation('project', $lockedProject);
            Gate::authorize('update', $lockedEnvironment);

            $lockedEnvironment->fill($validated);

            abort_if(
                ! $lockedEnvironment->isUnprovisioned()
                    && $lockedEnvironment->isDirty(['base_image', 'network_configuration', 'resource_limits']),
                409,
                'Runtime configuration can only be changed before provisioning.',
            );

            if ($lockedEnvironment->isDirty('name')) {
                $this->ensureUniqueName($lockedProject, $lockedEnvironment->name, $lockedEnvironment->id);
            }

            $lockedEnvironment->save();

            return $lockedEnvironment;
        }, 3);

        return ProjectEnvironmentResource::make($environment);
    }

    public function destroy(Project $project, ProjectEnvironment $environment): Response
    {
        $environment->setRelation('project', $project);
        Gate::authorize('delete', $environment);

        DB::transaction(function () use ($project, $environment): void {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $lockedEnvironment = $lockedProject->environments()->lockForUpdate()->findOrFail($environment->id);
            $lockedEnvironment->setRelation('project', $lockedProject);
            Gate::authorize('delete', $lockedEnvironment);
            $lockedEnvironment->delete();
        }, 3);

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    private function ensureUniqueName(Project $project, string $name, ?int $exceptId = null): void
    {
        $environments = $project->environments()->where('name', $name);

        if ($exceptId !== null) {
            $environments->whereKeyNot($exceptId);
        }

        if ($environments->exists()) {
            throw ValidationException::withMessages([
                'name' => ['An environment with this name already exists in this project.'],
            ]);
        }
    }
}
