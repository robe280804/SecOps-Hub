<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProjectEnvironment;
use App\Rules\EgressTarget;
use App\Support\IpRange;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'egress_configuration' => ['sometimes', 'required', 'array:version,policy,allowed_targets,raw_sockets', 'required_array_keys:version,policy,allowed_targets,raw_sockets'],
            'egress_configuration.version' => ['required_with:egress_configuration', 'integer:strict', Rule::in([1])],
            'egress_configuration.policy' => ['required_with:egress_configuration', Rule::in(config('environments.egress.policies'))],
            'egress_configuration.allowed_targets' => ['array', 'list', 'max:'.config('environments.egress.max_allowed_targets')],
            'egress_configuration.allowed_targets.*' => ['required', 'string', 'distinct:ignore_case', new EgressTarget],
            'egress_configuration.raw_sockets' => ['required_with:egress_configuration', $this->strictBoolean()],
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
            'egress_configuration.version' => 'egress configuration version',
            'egress_configuration.policy' => 'egress policy',
            'egress_configuration.allowed_targets' => 'authorised targets',
            'egress_configuration.allowed_targets.*' => 'authorised target',
            'egress_configuration.raw_sockets' => 'raw socket access',
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
            'egress_configuration.required_array_keys' => 'The egress configuration must include version, policy, allowed_targets and raw_sockets.',
            'egress_configuration.policy.in' => 'The egress policy must be blocked or filtered.',
            'resource_limits.required_array_keys' => 'The resource limits must include version, cpus, memory_bytes, storage_bytes and pids.',
        ];
    }

    /**
     * Cross-field checks that need the environment's effective configuration,
     * which on update is only partly present in the payload.
     *
     * @return array<int, Closure>
     */
    protected function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $existing = $this->route('environment');
            $existing = $existing instanceof ProjectEnvironment ? $existing : null;

            $egress = $this->input('egress_configuration') ?? $existing?->egressSettings();
            if (($egress['policy'] ?? null) !== 'filtered') {
                return;
            }

            $network = $this->input('network_configuration') ?? $existing?->network_configuration;
            foreach ($network['dns_servers'] ?? [] as $index => $resolver) {
                if (! is_string($resolver) || ! IpRange::isRoutableTarget($resolver)) {
                    $validator->errors()->add(
                        $this->has('network_configuration') ? 'network_configuration.dns_servers.'.$index : 'egress_configuration.policy',
                        'Filtered egress requires public IPv4 DNS servers; remove the unreachable resolver or keep the list empty.',
                    );
                }
            }
        }];
    }

    /** Rejects the string and integer forms Laravel's boolean rule would accept. */
    private function strictBoolean(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_bool($value)) {
                $fail('The :attribute field must be true or false.');
            }
        };
    }
}
