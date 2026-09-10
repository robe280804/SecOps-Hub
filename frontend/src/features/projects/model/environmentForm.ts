import { z } from 'zod'
import { ApiError } from '../../../shared/api/client.ts'
import type { EnvironmentOptions, EnvironmentUpdate, EnvironmentWrite, ProjectEnvironment } from './environmentSchema.ts'

export const MIB = 1024 ** 2
export const GIB = 1024 ** 3
export const environmentFormFields = ['name', 'description', 'base_image', 'cpus', 'memory_mib', 'storage_gib', 'pids', 'dns_servers', 'search_domains'] as const

const formShape = z.object({
  name: z.string().trim().min(1, 'Enter an environment name.').max(255, 'Use at most 255 characters.'),
  description: z.string().max(10000, 'Use at most 10,000 characters.'),
  base_image: z.string(),
  cpus: z.number({ error: 'Enter a CPU limit.' }),
  memory_mib: z.number({ error: 'Enter a memory limit.' }),
  storage_gib: z.number({ error: 'Enter a storage limit.' }),
  pids: z.number({ error: 'Enter a process limit.' }),
  dns_servers: z.string(), search_domains: z.string(),
})
export type EnvironmentFields = z.infer<typeof formShape>

export function splitEnvironmentList(value: string): string[] {
  return value.split(/[\s,]+/).filter(Boolean)
}

export function createEnvironmentFormSchema(options: EnvironmentOptions, configure: boolean) {
  return formShape.superRefine((fields, context) => {
    if (!configure) return
    const issue = (field: keyof EnvironmentFields, message: string) => context.addIssue({ code: 'custom', path: [field], message })
    if (!options.approved_images.includes(fields.base_image)) issue('base_image', 'Choose an approved image.')
    const limits = [
      ['cpus', fields.cpus, 'cpus', 1, 'CPU'],
      ['memory_mib', fields.memory_mib, 'memory_bytes', MIB, 'MiB'],
      ['storage_gib', fields.storage_gib, 'storage_bytes', GIB, 'GiB'],
      ['pids', fields.pids, 'pids', 1, 'processes'],
    ] as const
    for (const [field, value, key, multiplier, unit] of limits) {
      const minimum = options.min_resource_limits[key] / multiplier
      const maximum = options.max_resource_limits[key] / multiplier
      if (value < minimum || value > maximum) issue(field, 'Use between ' + minimum + ' and ' + maximum + ' ' + unit + '.')
      if (key !== 'cpus' && !Number.isSafeInteger(value * multiplier)) issue(field, 'Enter a value that represents a whole number of ' + (key === 'pids' ? 'processes.' : 'bytes.'))
    }
    const servers = splitEnvironmentList(fields.dns_servers)
    if (servers.length > options.network_limits.dns_servers) issue('dns_servers', 'Use at most ' + options.network_limits.dns_servers + ' DNS servers.')
    if (new Set(servers).size !== servers.length) issue('dns_servers', 'Remove duplicate DNS servers.')
    if (servers.some((server) => !z.union([z.ipv4(), z.ipv6()]).safeParse(server).success)) issue('dns_servers', 'Enter valid IPv4 or IPv6 addresses.')
    const domains = splitEnvironmentList(fields.search_domains)
    const hostname = /^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/
    if (domains.length > options.network_limits.search_domains) issue('search_domains', 'Use at most ' + options.network_limits.search_domains + ' search domains.')
    if (domains.some((domain) => domain.length > 253 || !hostname.test(domain))) issue('search_domains', 'Enter hostnames such as lab.example.com, without a URL or wildcard.')
    if (new Set(domains.map((domain) => domain.toLowerCase())).size !== domains.length) issue('search_domains', 'Remove duplicate search domains.')
  })
}

export function environmentInitialValues(options: EnvironmentOptions, environment?: ProjectEnvironment): EnvironmentFields {
  const network = environment?.network_configuration ?? options.default_network_configuration
  const limits = environment?.resource_limits ?? options.default_resource_limits
  return {
    name: environment?.name ?? '',
    description: environment?.description ?? '',
    base_image: environment?.base_image ?? options.approved_images[0] ?? '',
    cpus: limits.cpus,
    memory_mib: limits.memory_bytes / MIB,
    storage_gib: limits.storage_bytes / GIB,
    pids: limits.pids,
    dns_servers: network.dns_servers.join('\n'),
    search_domains: network.search_domains.join('\n'),
  }
}

export function environmentPayload(fields: EnvironmentFields, options: EnvironmentOptions, configure: true): EnvironmentWrite
export function environmentPayload(fields: EnvironmentFields, options: EnvironmentOptions, configure: boolean): EnvironmentUpdate
export function environmentPayload(fields: EnvironmentFields, options: EnvironmentOptions, configure: boolean): EnvironmentUpdate {
  const validated = createEnvironmentFormSchema(options, configure).parse(fields)
  const details = { name: validated.name, description: validated.description.trim() || null }
  if (!configure) return details
  return {
    ...details,
    base_image: validated.base_image,
    network_configuration: {
      version: 1, mode: 'automatic',
      dns_servers: splitEnvironmentList(validated.dns_servers),
      search_domains: splitEnvironmentList(validated.search_domains),
    },
    resource_limits: {
      version: 1, cpus: validated.cpus,
      memory_bytes: validated.memory_mib * MIB,
      storage_bytes: validated.storage_gib * GIB,
      pids: validated.pids,
    },
  }
}

export function environmentFormError(failure: unknown): unknown {
  if (!(failure instanceof ApiError)) return failure
  if (failure.status === 409) return new ApiError('The environment or project state has changed, or the project limit has been reached. Reload environments before trying again.', 409)
  if (failure.status === 0 || failure.status >= 500) return new ApiError('The change could not be confirmed. Reload environments to check whether it was saved before trying again.', failure.status)
  if (failure.status !== 422) return failure
  const mapping: Record<string, keyof EnvironmentFields> = {
    'network_configuration.dns_servers': 'dns_servers',
    'network_configuration.search_domains': 'search_domains',
    'resource_limits.cpus': 'cpus',
    'resource_limits.memory_bytes': 'memory_mib',
    'resource_limits.storage_bytes': 'storage_gib',
    'resource_limits.pids': 'pids',
  }
  const errors: Record<string, string[]> = {}
  for (const [path, messages] of Object.entries(failure.errors)) {
    const mapped = Object.entries(mapping).find(([key]) => path === key || path.startsWith(key + '.'))?.[1] ?? path
    errors[mapped] = [...(errors[mapped] ?? []), ...messages]
  }
  return new ApiError(failure.message, failure.status, errors, failure.retryAfter)
}
