<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProjectMembershipRequest;
use App\Http\Requests\Api\V1\UpdateProjectMembershipRequest;
use App\Http\Resources\Api\V1\ProjectMembershipResource;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectMembershipController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('viewMembers', $project);

        $memberships = $project->memberships()->with('user:id,name,email')->orderBy('id')->paginate(15);

        return ProjectMembershipResource::collection($memberships)->response()
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(StoreProjectMembershipRequest $request, Project $project): JsonResponse
    {
        $validated = $request->validated();

        $membership = DB::transaction(function () use ($project, $validated): ProjectMembership {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::authorize('manageMembers', $lockedProject);

            $user = User::query()->lockForUpdate()->find($validated['user_id']);

            if ($user === null) {
                throw ValidationException::withMessages(['user_id' => ['The selected user id is invalid.']]);
            }

            if ($lockedProject->memberships()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => ['This user is already a collaborator.']]);
            }

            $membership = new ProjectMembership;
            $membership->project()->associate($lockedProject);
            $membership->user()->associate($user);
            $membership->fill(Arr::only($validated, ['access_level']));
            $membership->save();

            return $membership->load('user:id,name,email');
        });

        return ProjectMembershipResource::make($membership)->response()->setStatusCode(201);
    }

    public function update(UpdateProjectMembershipRequest $request, Project $project, ProjectMembership $membership): ProjectMembershipResource
    {
        $validated = $request->validated();

        $membership = DB::transaction(function () use ($project, $membership, $validated): ProjectMembership {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::authorize('manageMembers', $lockedProject);

            $lockedMembership = $lockedProject->memberships()->lockForUpdate()->findOrFail($membership->id);
            $lockedMembership->update($validated);

            return $lockedMembership->load('user:id,name,email');
        });

        return ProjectMembershipResource::make($membership);
    }

    public function destroy(Project $project, ProjectMembership $membership): Response
    {
        Gate::authorize('revokeMembers', $project);

        DB::transaction(function () use ($project, $membership): void {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::authorize('revokeMembers', $lockedProject);
            $lockedProject->memberships()->lockForUpdate()->findOrFail($membership->id)->delete();
        });

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
