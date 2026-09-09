<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LookupProjectCollaboratorRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class ProjectCollaboratorLookupController extends Controller
{
    public function __invoke(LookupProjectCollaboratorRequest $request, Project $project): JsonResponse
    {
        $users = User::query()
            ->select(['id', 'name', 'email'])
            ->where('email', $request->validated('email'))
            ->where('id', '!=', $project->user_id)
            ->whereDoesntHave('projectMemberships', function (Builder $query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->limit(1)
            ->get();

        return response()->json(['data' => $users->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ])])->header('Cache-Control', 'no-store, private');
    }
}
