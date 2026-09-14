<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class EnvironmentTerminalController extends Controller
{
    public function store(Request $request, Project $project, ProjectEnvironment $environment): JsonResponse
    {
        $environment->setRelation('project', $project);
        Gate::authorize('shell', $environment);
        abort_unless(config('environments.terminal.enabled'), 503, 'The terminal gateway is not configured.');
        $ticket = Str::random(64);
        $expires = now()->addSeconds(config('environments.terminal.lifetime_seconds'));
        Cache::put('environment-terminal:'.hash('sha256', $ticket), [
            'user_id' => $request->user()->id,
            'environment_id' => $environment->id,
            'runtime_reference' => $environment->runtime_reference,
            'generation' => $environment->runtime_generation,
            'observed_at' => $environment->last_observed_at?->toIso8601String(),
            'operation_id' => (int) $environment->operations()->max('id'),
            'expires_at' => $expires->timestamp,
            'identity' => $this->identity($request),
        ], $expires);
        Log::info('Environment terminal session issued.', [
            'user_id' => $request->user()->id, 'environment_id' => $environment->id,
        ]);

        return response()->json(['data' => [
            'url' => '/terminal/'.$ticket.'/',
            'expires_at' => $expires->toIso8601String(),
        ]], 201)->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request): JsonResponse
    {
        abort_unless(config('environments.terminal.enabled'), 503);
        $ticket = $request->header('X-Terminal-Ticket', '');
        abort_unless(is_string($ticket) && preg_match('/\A[a-zA-Z0-9]{64}\z/', $ticket), 403);
        $session = Cache::get('environment-terminal:'.hash('sha256', $ticket));
        abort_unless(is_array($session) && $session['user_id'] === $request->user()->id
            && $session['expires_at'] > now()->timestamp
            && hash_equals($session['identity'], $this->identity($request)), 403);
        $environment = ProjectEnvironment::query()->with('project')->findOrFail($session['environment_id']);
        Gate::authorize('shell', $environment);
        abort_unless($environment->runtime_reference === $session['runtime_reference']
            && $environment->runtime_generation === $session['generation']
            && $environment->last_observed_at?->toIso8601String() === $session['observed_at']
            && ! $environment->operations()->where('id', '>', $session['operation_id'])->exists(), 403);

        return response()->json(['data' => [
            'container' => $environment->runtime_reference,
            'labels' => [
                'secops.namespace' => config('environments.runtime.namespace'),
                'secops.project' => (string) $environment->project_id,
                'secops.environment' => (string) $environment->id,
                'secops.generation' => (string) $environment->runtime_generation,
            ],
        ]])->header('Cache-Control', 'no-store, private');
    }

    private function identity(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            return hash('sha256', 'token:'.$token->id);
        }

        return hash('sha256', 'session:'.($request->hasSession() ? $request->session()->getId() : ''));
    }
}
