<?php

namespace App\Console\Commands;

use App\Jobs\StartProjectEnvironment;
use App\Jobs\StopProjectEnvironment;
use App\Models\EnvironmentOperation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('environments:recover')]
#[Description('Requeue environment operations interrupted before dispatch or completion')]
class RecoverEnvironmentOperations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        EnvironmentOperation::query()->whereIn('status', ['pending', 'running'])
            ->where('updated_at', '<', now()->subMinutes(5))
            ->eachById(function (EnvironmentOperation $operation): void {
                $job = $operation->action === 'stop' ? StopProjectEnvironment::class : StartProjectEnvironment::class;
                if ($operation->attempts >= 3) {
                    (new $job($operation->id))->failed(null);

                    return;
                }
                $job::dispatch($operation->id);
                $operation->touch();
            }, 100);

        return self::SUCCESS;
    }
}
