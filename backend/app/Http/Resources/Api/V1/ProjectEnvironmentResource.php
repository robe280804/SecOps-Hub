<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class ProjectEnvironmentResource extends JsonResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'description' => $this->description,
            'base_image' => $this->base_image,
            'desired_state' => $this->desired_state,
            'status' => $this->status,
            'runtime_generation' => $this->runtime_generation,
            'runtime_status' => $this->runtime_status,
            'last_observed_at' => $this->last_observed_at,
            'network_configuration' => $this->network_configuration,
            'egress_configuration' => $this->resource->egressSettings(),
            'resource_limits' => $this->resource_limits,
            'capabilities' => [
                'shell' => config('environments.terminal.enabled') && Gate::allows('shell', $this->resource),
                'stop' => config('environments.runtime.enabled') && Gate::allows('stop', $this->resource)
                    && in_array($this->status->value, ['running', 'ready', 'error'], true),
                'start' => config('environments.runtime.enabled') && Gate::allows('start', $this->resource)
                    && in_array($this->status->value, ['inactive', 'stopped', 'error'], true),
                'update' => Gate::allows('update', $this->resource),
                'configure' => Gate::allows('update', $this->resource) && $this->resource->isUnprovisioned(),
                'delete' => Gate::allows('delete', $this->resource),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
