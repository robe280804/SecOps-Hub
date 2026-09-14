<?php

namespace App\Console\Commands;

use App\Services\DockerEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

#[Signature('environments:check {--worker : Also require a running Horizon supervisor}')]
#[Description('Check the database, queue, Docker daemon and approved runtime images')]
class CheckEnvironmentRuntime extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DockerEngine $docker): int
    {
        $stage = 'configuration';

        try {
            if (! config('environments.runtime.enabled')) {
                throw new RuntimeException('Environment startup is disabled.');
            }
            $stage = 'database and startup migrations';
            DB::connection()->getPdo();
            if (! Schema::hasTable('environment_operations')) {
                throw new RuntimeException('Run the pending migrations.');
            }
            $stage = 'Redis queue';
            $connection = config('environments.runtime.queue_connection');
            if (config('queue.connections.'.$connection.'.driver') !== 'redis') {
                throw new RuntimeException('The local worker requires a Redis queue.');
            }
            Redis::connection(config('queue.connections.'.$connection.'.connection', 'default'))->ping();

            $stage = 'Docker daemon';
            if (($docker->inspect('/info')['OSType'] ?? null) !== 'linux') {
                throw new RuntimeException('Docker must use Linux containers.');
            }
            $stage = 'approved images';
            foreach (config('environments.approved_images') as $image) {
                if ($docker->inspect('/images/'.rawurlencode($image).'/json') === null) {
                    throw new RuntimeException('Build or pull the approved runtime images first.');
                }
            }
            $stage = 'Horizon worker';
            if ($this->option('worker') && $this->callSilent('horizon:status') !== self::SUCCESS) {
                throw new RuntimeException('Horizon is not running.');
            }
        } catch (Throwable $exception) {
            $this->error('Runtime check failed: '.$stage.'.');

            return self::FAILURE;
        }

        $this->info('Environment runtime is ready.');

        return self::SUCCESS;
    }
}
