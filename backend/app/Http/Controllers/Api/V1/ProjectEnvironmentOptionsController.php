<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProjectEnvironmentOptionsController extends Controller
{
    public function __invoke(Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [ProjectEnvironment::class, $project]);

        return response()->json(['data' => [
            'approved_images' => config('environments.approved_images'),
            'max_per_project' => config('environments.max_per_project'),
            'default_network_configuration' => config('environments.default_network_configuration'),
            'default_egress_configuration' => config('environments.egress.default'),
            'egress_policies' => config('environments.egress.policies'),
            'egress_limits' => [
                'allowed_targets' => config('environments.egress.max_allowed_targets'),
                'blocked_destinations' => config('environments.egress.blocked_destinations'),
            ],
            'default_resource_limits' => config('environments.default_resource_limits'),
            'min_resource_limits' => config('environments.min_resource_limits'),
            'max_resource_limits' => config('environments.max_resource_limits'),
            'network_limits' => config('environments.network_limits'),
            'can_create' => Gate::allows('create', [ProjectEnvironment::class, $project]),
        ]])->header('Cache-Control', 'no-store, private');
    }
}
