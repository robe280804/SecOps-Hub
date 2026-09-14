<?php

namespace App\Jobs;

use App\Enums\EnvironmentDesiredState;
use App\Enums\EnvironmentStatus;
use App\Models\EnvironmentOperation;
use App\Models\User;
use App\Services\EnvironmentProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class StartProjectEnvironment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public function __construct(public int $operationId)
    {
        $this->onConnection(config('environments.runtime.queue_connection'));
        $this->onQueue('provisioning');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('environment-operation-'.$this->operationId))->dontRelease()->expireAfter(210)];
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(EnvironmentProvisioner $provisioner): void
    {
        $operation = EnvironmentOperation::query()->with('environment.project')->find($this->operationId);
        if ($operation === null || ! in_array($operation->status, ['pending', 'running'], true)) {
            return;
        }
        if ($operation->attempts >= 3) {
            $this->failed(null);

            return;
        }
        $environment = $operation->environment;
        $user = User::query()->find($operation->requested_by);
        if ($user === null || Gate::forUser($user)->denies('start', $environment)
            || $environment->desired_state !== EnvironmentDesiredState::Running) {
            DB::transaction(function () use ($operation, $environment): void {
                $operation->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();
                $environment->forceFill(['status' => EnvironmentStatus::Error, 'last_error' => 'Startup authorization is no longer valid.'])->save();
            });

            return;
        }
        $operation->forceFill(['status' => 'running', 'attempts' => $operation->attempts + 1])->save();
        $provisioner->start($environment);
        DB::transaction(function () use ($operation, $environment): void {
            $environment->forceFill(['status' => EnvironmentStatus::Running, 'last_error' => null])->save();
            $operation->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        $operation = EnvironmentOperation::query()->find($this->operationId);
        if ($operation === null || ! in_array($operation->status, ['pending', 'running'], true)) {
            return;
        }
        DB::transaction(function () use ($operation): void {
            $operation->forceFill(['status' => 'failed', 'completed_at' => now()])->save();
            $operation->environment->forceFill([
                'status' => EnvironmentStatus::Error,
                'last_error' => 'Environment startup failed. Check the worker logs before retrying.',
            ])->save();
        });
    }
}
