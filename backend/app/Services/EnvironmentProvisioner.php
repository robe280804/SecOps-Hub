<?php

namespace App\Services;

use App\Models\ProjectEnvironment;
use RuntimeException;

class EnvironmentProvisioner
{
    public function __construct(private DockerEngine $docker, private EnvironmentFirewall $firewall) {}

    public function start(ProjectEnvironment $environment): void
    {
        $this->assertRunnable($environment);

        $limits = $environment->resource_limits;
        $network = $environment->network_configuration;
        $filtered = $environment->egressSettings()['policy'] === 'filtered';

        $sizeOption = config('environments.runtime.volume_size_option');
        if (! $sizeOption && ! (app()->environment('local', 'testing') && config('environments.runtime.allow_unlimited_storage'))) {
            throw new RuntimeException('A volume driver with workspace quota support must be configured.');
        }

        $name = $this->resourceName($environment);
        $labels = $this->labels($environment);

        $volumeName = $name.'-workspace';
        if ($environment->workspace_reference !== null && $environment->workspace_reference !== $volumeName) {
            throw new RuntimeException('The workspace belongs to a different runtime configuration.');
        }
        $volume = $this->docker->inspect('/volumes/'.$volumeName);
        if ($volume === null) {
            if ($environment->workspace_reference !== null) {
                throw new RuntimeException('The persistent workspace is missing; automatic replacement is disabled.');
            }
            $volume = $this->docker->create('/volumes/create', [
                'Name' => $volumeName,
                'Driver' => config('environments.runtime.volume_driver'),
                'DriverOpts' => (object) ($sizeOption ? [$sizeOption => (string) $limits['storage_bytes']] : []),
                'Labels' => $labels,
            ]);
        }
        $this->assertOwnership($volume['Labels'] ?? [], $labels);
        $environment->forceFill(['workspace_reference' => $volumeName])->save();

        $runtimeNetwork = $this->network($name.'-network', $labels, $filtered);

        /*
         * Filtered environments reach the outside world, so their ruleset is
         * written before the container may run. Ordering it here means an
         * interrupted start never leaves a reachable container unfiltered.
         */
        if ($filtered) {
            $this->firewall->apply($environment, $this->subnetOf($runtimeNetwork), $labels);
        }

        if ($environment->runtime_generation === 0) {
            $environment->forceFill(['runtime_generation' => 1])->save();
        }
        $labels['secops.generation'] = (string) $environment->runtime_generation;
        $containerName = $name.'-g'.$environment->runtime_generation;
        $reference = $environment->runtime_reference ?? $containerName;
        $container = $this->docker->inspect('/containers/'.rawurlencode($reference).'/json');
        if ($container === null) {
            if ($environment->runtime_reference !== null) {
                throw new RuntimeException('The runtime container is missing; explicit recreation is required.');
            }
            $this->docker->create('/containers/create?name='.$containerName, [
                'Image' => $environment->base_image,
                'WorkingDir' => '/workspace',
                'Labels' => $labels,
                'HostConfig' => [
                    'Init' => true, 'Privileged' => false,
                    'NetworkMode' => $name.'-network',
                    'Memory' => (int) $limits['memory_bytes'],
                    'MemorySwap' => (int) $limits['memory_bytes'],
                    'NanoCpus' => (int) round($limits['cpus'] * 1_000_000_000),
                    'PidsLimit' => (int) $limits['pids'],
                    /*
                     * NET_RAW is what lets nmap and masscan send crafted
                     * packets. It is granted only to environments that asked
                     * for it; NET_ADMIN never is, because the egress ruleset
                     * must not be editable from inside the environment.
                     */
                    'CapDrop' => $environment->allowsRawSockets() ? ['NET_ADMIN'] : ['NET_RAW', 'NET_ADMIN'],
                    'SecurityOpt' => ['no-new-privileges:true'],
                    'Dns' => $this->nameservers($network, $filtered),
                    'DnsSearch' => $network['search_domains'] ?? [],
                    'Mounts' => [['Type' => 'volume', 'Source' => $volumeName, 'Target' => '/workspace']],
                    'LogConfig' => ['Type' => 'json-file', 'Config' => ['max-size' => '10m', 'max-file' => '2']],
                ],
            ]);
            $container = $this->docker->inspect('/containers/'.$containerName.'/json');
        }
        $this->assertOwnership($container['Config']['Labels'] ?? [], $labels);
        $id = $container['Id'] ?? null;
        if (! is_string($id) || ! preg_match('/\A[a-f0-9]{64}\z/', $id)) {
            throw new RuntimeException('Docker returned an invalid container identity.');
        }
        $environment->forceFill(['runtime_reference' => $id])->save();
        if (($container['State']['Running'] ?? false) !== true) {
            $this->docker->create('/containers/'.$id.'/start');
        }
        $container = $this->docker->inspect('/containers/'.$id.'/json');
        if (($container['State']['Running'] ?? false) !== true) {
            throw new RuntimeException('The environment container did not remain running.');
        }
        $environment->forceFill(['runtime_status' => 'running', 'last_observed_at' => now()])->save();
    }

    public function stop(ProjectEnvironment $environment): void
    {
        if (! config('environments.runtime.enabled')) {
            throw new RuntimeException('Environment runtime is disabled.');
        }
        $id = $environment->runtime_reference;
        if (! is_string($id) || ! preg_match('/\A[a-f0-9]{64}\z/', $id)) {
            throw new RuntimeException('The environment has no valid runtime identity.');
        }
        $container = $this->docker->inspect('/containers/'.$id.'/json');
        if ($container === null) {
            throw new RuntimeException('The runtime container is missing.');
        }
        $this->assertOwnership($container['Config']['Labels'] ?? [], $this->labels($environment) + [
            'secops.generation' => (string) $environment->runtime_generation,
        ]);
        if (($container['State']['Running'] ?? false) === true) {
            $this->docker->create('/containers/'.$id.'/stop?t=20', timeout: 30);
        }
        $container = $this->docker->inspect('/containers/'.$id.'/json');
        if ($container === null || ($container['State']['Running'] ?? true) !== false) {
            throw new RuntimeException('The environment container did not stop.');
        }
        $environment->forceFill(['runtime_status' => 'stopped', 'last_observed_at' => now()])->save();
    }

    /**
     * Rewrites the ruleset of an already provisioned environment, so a change
     * to the authorised targets takes effect without restarting it.
     */
    public function reapplyEgress(ProjectEnvironment $environment): void
    {
        $this->assertRunnable($environment);

        if ($environment->egressSettings()['policy'] !== 'filtered') {
            return;
        }

        $labels = $this->labels($environment);
        $runtimeNetwork = $this->docker->inspect('/networks/'.$this->resourceName($environment).'-network');

        if ($runtimeNetwork === null) {
            throw new RuntimeException('The runtime network is missing; start the environment before changing its scope.');
        }

        $this->assertOwnership($runtimeNetwork['Labels'] ?? [], $labels);
        $this->firewall->apply($environment, $this->subnetOf($runtimeNetwork), $labels);
    }

    /**
     * Creates or recovers the environment's own network and verifies that its
     * isolation still matches the policy the environment was configured with.
     *
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function network(string $networkName, array $labels, bool $filtered): array
    {
        $policy = $filtered ? 'filtered' : 'blocked';
        $runtimeNetwork = $this->docker->inspect('/networks/'.$networkName);

        if ($runtimeNetwork === null) {
            $this->docker->create('/networks/create', [
                'Name' => $networkName,
                'Driver' => 'bridge',
                /*
                 * A blocked environment has no route off its own bridge. A
                 * filtered one is routable, and the rules written into
                 * DOCKER-USER are the only thing bounding where it can go.
                 */
                'Internal' => ! $filtered,
                'Labels' => $labels + ['secops.egress' => $policy],
                'Options' => $filtered ? (object) [] : ['com.docker.network.bridge.gateway_mode_ipv4' => 'isolated'],
            ]);
            $runtimeNetwork = $this->docker->inspect('/networks/'.$networkName);
        }

        $this->assertOwnership($runtimeNetwork['Labels'] ?? [], $labels);

        // Networks created before controlled egress existed carry no policy
        // label and were always fully isolated.
        if (($runtimeNetwork['Labels']['secops.egress'] ?? 'blocked') !== $policy) {
            throw new RuntimeException('The egress policy changed after the runtime network was created; recreating it is not implemented yet.');
        }

        $internal = $runtimeNetwork['Internal'] ?? false;
        $gateway = $runtimeNetwork['Options']['com.docker.network.bridge.gateway_mode_ipv4'] ?? null;

        if ($filtered ? $internal !== false : ($internal !== true || $gateway !== 'isolated')) {
            throw new RuntimeException('The runtime network does not match the isolation configuration.');
        }

        return $runtimeNetwork;
    }

    /**
     * The IPv4 subnet Docker assigned, which every egress rule is keyed on.
     *
     * @param  array<string, mixed>  $runtimeNetwork
     */
    private function subnetOf(array $runtimeNetwork): string
    {
        foreach ($runtimeNetwork['IPAM']['Config'] ?? [] as $entry) {
            if (is_string($entry['Subnet'] ?? null) && ! str_contains($entry['Subnet'], ':')) {
                return $entry['Subnet'];
            }
        }

        throw new RuntimeException('The runtime network reported no IPv4 subnet.');
    }

    /**
     * Filtered environments must resolve through the deny list, so they fall
     * back to the platform's public resolvers instead of the node's own.
     *
     * @param  array<string, mixed>  $network
     * @return array<int, string>
     */
    private function nameservers(array $network, bool $filtered): array
    {
        $configured = $network['dns_servers'] ?? [];

        if (! $filtered || (is_array($configured) && $configured !== [])) {
            return is_array($configured) ? $configured : [];
        }

        return config('environments.egress.resolvers');
    }

    private function assertRunnable(ProjectEnvironment $environment): void
    {
        if (! config('environments.runtime.enabled')) {
            throw new RuntimeException('Environment startup is disabled.');
        }
        if (! in_array($environment->base_image, config('environments.approved_images'), true)) {
            throw new RuntimeException('The environment image is no longer approved.');
        }

        $limits = $environment->resource_limits;
        $network = $environment->network_configuration;

        if (! is_array($limits) || ! is_array($network) || ($network['mode'] ?? null) !== 'automatic') {
            throw new RuntimeException('Environment configuration is incomplete.');
        }
        if (! in_array($environment->egressSettings()['policy'], config('environments.egress.policies'), true)) {
            throw new RuntimeException('The environment egress policy is not supported.');
        }
        foreach (['cpus', 'memory_bytes', 'storage_bytes', 'pids'] as $key) {
            if (! is_numeric($limits[$key] ?? null)
                || $limits[$key] < config('environments.min_resource_limits.'.$key)
                || $limits[$key] > config('environments.max_resource_limits.'.$key)) {
                throw new RuntimeException('Environment resource limits are no longer permitted.');
            }
        }
    }

    private function resourceName(ProjectEnvironment $environment): string
    {
        $namespace = config('environments.runtime.namespace');

        if (! is_string($namespace) || ! preg_match('/\A[a-z0-9][a-z0-9-]{0,39}\z/', $namespace)) {
            throw new RuntimeException('Invalid runtime namespace.');
        }

        return $namespace.'-project-'.$environment->project_id.'-env-'.$environment->id;
    }

    /** @return array<string, string> */
    private function labels(ProjectEnvironment $environment): array
    {
        return [
            'secops.namespace' => config('environments.runtime.namespace'),
            'secops.project' => (string) $environment->project_id,
            'secops.environment' => (string) $environment->id,
        ];
    }

    /** @param array<string, string> $actual
     * @param  array<string, string>  $expected
     */
    private function assertOwnership(array $actual, array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) !== $value) {
                throw new RuntimeException('A Docker resource has conflicting ownership labels.');
            }
        }
    }
}
