<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Http\Requests\Api\V1\UpdateProjectRequest;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Project::class);

        $projects = Project::query()
            ->where(function (Builder $query) use ($request): void {
                $query->where('user_id', $request->user()->id)
                    ->orWhereHas('memberships', function (Builder $memberships) use ($request): void {
                        $memberships->where('user_id', $request->user()->id);
                    });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);

        return ProjectResource::collection($projects)->response()
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $request->user()->projects()->create($request->validated());

        return ProjectResource::make($project)->response()->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return ProjectResource::make($project);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $validated = $request->validated();

        $project = DB::transaction(function () use ($project, $validated): Project {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::authorize('update', $lockedProject);

            if ($lockedProject->status === ProjectStatus::Archived) {
                abort_unless(
                    count($validated) === 1
                        && in_array($validated['status'] ?? null, [ProjectStatus::Inactive->value, ProjectStatus::Active->value], true),
                    409,
                    'Archived projects are read-only. Reactivate the project with a status-only update first.',
                );
            }

            $lockedProject->update($validated);

            return $lockedProject;
        });

        return ProjectResource::make($project);
    }

    public function destroy(Project $project): Response
    {
        Gate::authorize('delete', $project);

        DB::transaction(function () use ($project): void {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::authorize('delete', $lockedProject);
            $lockedProject->update(['status' => ProjectStatus::Archived]);
        });

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
