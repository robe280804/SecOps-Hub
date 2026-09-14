<?php

namespace App\Services;

use App\Models\ProjectEnvironment;
use App\Support\IpRange;
use RuntimeException;
use Throwable;

/**
 * Enforces an environment's controlled egress on the runtime node.
 *
 * The rules live in the host's netfilter tables, which the provisioning worker
 * cannot reach through the Docker API alone, so a short-lived helper container
 * runs in the host network namespace and writes them. The helper is never
 * reachable from an environment and exits as soon as the ruleset is verified.
 */
class EnvironmentFirewall
{
    public function __construct(private DockerEngine $docker) {}

    /**
     * Rewrites the environment's ruleset for the subnet its network was given.
     *
     * @param  array<string, string>  $labels
     */
    public function apply(ProjectEnvironment $environment, string $subnet, array $labels): void
    {
        if (IpRange::parse($subnet) === null) {
            throw new RuntimeException('The runtime network reported an unusable subnet.');
        }

        $targets = $this->authorisedTargets($environment);
        $resolvers = $this->resolvers($environment);
        $blocked = config('environments.egress.blocked_destinations');

        if ($blocked === []) {
            throw new RuntimeException('The platform deny list for controlled egress is empty.');
        }

        $image = config('environments.egress.helper_image');

        if ($this->docker->inspect('/images/'.rawurlencode($image).'/json') === null) {
            throw new RuntimeException('The egress helper image is not available on the runtime node.');
        }

        $name = config('environments.runtime.namespace').'-egress-env-'.$environment->id;
        $this->discard($name);

        $this->docker->create('/containers/create?name='.$name, [
            'Image' => $image,
            'Env' => [
                'SECOPS_CHAIN='.config('environments.egress.chain_prefix').$environment->id,
                'SECOPS_ROOT_CHAIN='.config('environments.egress.root_chain'),
                'SECOPS_SUBNET='.$subnet,
                'SECOPS_RESOLVERS='.implode(',', $resolvers),
                'SECOPS_BLOCKED='.implode(',', $blocked),
                'SECOPS_ALLOWED='.implode(',', $targets),
            ],
            'Labels' => ['secops.role' => 'egress'] + $labels,
            'HostConfig' => [
                'NetworkMode' => 'host',
                'Privileged' => false,
                'CapAdd' => ['NET_ADMIN', 'NET_RAW'],
                'RestartPolicy' => ['Name' => 'no'],
                'LogConfig' => ['Type' => 'json-file', 'Config' => ['max-size' => '1m', 'max-file' => '1']],
            ],
        ]);

        $status = null;

        try {
            $this->docker->create('/containers/'.$name.'/start');
            $status = $this->docker->create('/containers/'.$name.'/wait', timeout: 60)['StatusCode'] ?? null;
        } finally {
            $this->discard($name);
        }

        if ($status !== 0) {
            throw new RuntimeException(match ($status) {
                10 => 'The runtime node does not expose the DOCKER-USER chain, so controlled egress cannot be enforced.',
                11 => 'The generated egress ruleset was rejected as malformed.',
                12 => 'The egress ruleset could not be read back after being applied.',
                default => 'Applying the controlled egress ruleset failed.',
            });
        }
    }

    /**
     * The scan targets recorded for the environment, revalidated here because
     * the platform deny list can grow after an environment was configured.
     *
     * @return array<int, string>
     */
    private function authorisedTargets(ProjectEnvironment $environment): array
    {
        $targets = $environment->egressSettings()['allowed_targets'];

        if (! is_array($targets) || array_is_list($targets) === false) {
            throw new RuntimeException('The authorised target list is malformed.');
        }

        foreach ($targets as $target) {
            if (! is_string($target) || ! IpRange::isRoutableTarget($target)) {
                throw new RuntimeException('An authorised target is no longer permitted by the platform.');
            }
        }

        return array_values($targets);
    }

    /**
     * Filtered egress reaches DNS through the deny list, so a resolver on a
     * private network would be unreachable. Failing here is clearer than
     * handing the environment a container that cannot resolve anything.
     *
     * @return array<int, string>
     */
    private function resolvers(ProjectEnvironment $environment): array
    {
        $configured = $environment->network_configuration['dns_servers'] ?? [];
        $resolvers = is_array($configured) && $configured !== []
            ? $configured
            : config('environments.egress.resolvers');

        foreach ($resolvers as $resolver) {
            if (! is_string($resolver) || ! IpRange::isRoutableTarget($resolver)) {
                throw new RuntimeException('Filtered egress requires public IPv4 DNS servers.');
            }
        }

        if ($resolvers === []) {
            throw new RuntimeException('No DNS resolver is configured for filtered egress.');
        }

        return array_values($resolvers);
    }

    /**
     * Removes a helper left behind by an interrupted run. A failure here is not
     * fatal: the next apply removes it again, and the helper holds no state.
     */
    private function discard(string $name): void
    {
        try {
            $this->docker->remove('/containers/'.rawurlencode($name).'?force=true&v=true');
        } catch (Throwable) {
            // Reported by the next apply if it genuinely blocks the helper.
        }
    }
}
