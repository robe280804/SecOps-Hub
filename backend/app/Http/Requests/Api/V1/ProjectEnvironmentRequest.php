<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ProjectEnvironmentRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'base_image' => ['sometimes', 'required', 'string', 'max:255', Rule::in(config('environments.approved_images'))],
            'network_configuration' => ['sometimes', 'required', 'array:version,mode,dns_servers,search_domains', 'required_array_keys:version,mode,dns_servers,search_domains'],
            'network_configuration.version' => ['required_with:network_configuration', 'integer:strict', Rule::in([1])],
            'network_configuration.mode' => ['required_with:network_configuration', Rule::in(['automatic'])],
            'network_configuration.dns_servers' => ['array', 'list', 'max:'.config('environments.network_limits.dns_servers')],
            'network_configuration.dns_servers.*' => ['required', 'ip', 'distinct'],
            'network_configuration.search_domains' => ['array', 'list', 'max:'.config('environments.network_limits.search_domains')],
            'network_configuration.search_domains.*' => [
                'required', 'string', 'max:253', 'distinct:ignore_case',
                'regex:/\A[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/',
            ],
            'resource_limits' => ['sometimes', 'required', 'array:version,cpus,memory_bytes,storage_bytes,pids', 'required_array_keys:version,cpus,memory_bytes,storage_bytes,pids'],
            'resource_limits.version' => ['required_with:resource_limits', 'integer:strict', Rule::in([1])],
            'resource_limits.cpus' => ['required_with:resource_limits', 'numeric:strict', 'min:'.config('environments.min_resource_limits.cpus'), 'max:'.config('environments.max_resource_limits.cpus')],
            'resource_limits.memory_bytes' => ['required_with:resource_limits', 'integer:strict', 'min:'.config('environments.min_resource_limits.memory_bytes'), 'max:'.config('environments.max_resource_limits.memory_bytes')],
            'resource_limits.storage_bytes' => ['required_with:resource_limits', 'integer:strict', 'min:'.config('environments.min_resource_limits.storage_bytes'), 'max:'.config('environments.max_resource_limits.storage_bytes')],
            'resource_limits.pids' => ['required_with:resource_limits', 'integer:strict', 'min:'.config('environments.min_resource_limits.pids'), 'max:'.config('environments.max_resource_limits.pids')],
            'id' => ['missing'],
            'project_id' => ['missing'],
            'user_id' => ['missing'],
            'desired_state' => ['missing'],
            'status' => ['missing'],
            'runtime_reference' => ['missing'],
            'runtime_generation' => ['missing'],
            'runtime_status' => ['missing'],
            'workspace_reference' => ['missing'],
            'last_error' => ['missing'],
            'last_observed_at' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'network_configuration.version' => 'network configuration version',
            'network_configuration.mode' => 'network addressing mode',
            'network_configuration.dns_servers' => 'DNS servers',
            'network_configuration.dns_servers.*' => 'DNS server',
            'network_configuration.search_domains' => 'search domains',
            'network_configuration.search_domains.*' => 'search domain',
            'resource_limits.version' => 'resource configuration version',
            'resource_limits.cpus' => 'CPU limit',
            'resource_limits.memory_bytes' => 'memory limit',
            'resource_limits.storage_bytes' => 'storage limit',
            'resource_limits.pids' => 'process limit',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'base_image.in' => 'The selected base image is not approved by the platform.',
            'network_configuration.mode.in' => 'Only automatic network addressing is supported.',
            'network_configuration.required_array_keys' => 'The network configuration must include version, mode, dns_servers and search_domains.',
            'resource_limits.required_array_keys' => 'The resource limits must include version, cpus, memory_bytes, storage_bytes and pids.',
        ];
    }
}
