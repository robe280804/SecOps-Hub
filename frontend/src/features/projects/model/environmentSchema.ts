import { z } from 'zod'

export const environmentStatuses = ['inactive', 'provisioning', 'stopped', 'starting', 'ready', 'stopping', 'error', 'deleting'] as const
export const environmentStatusLabels: Record<typeof environmentStatuses[number], string> = {
  inactive: 'Not provisioned', provisioning: 'Provisioning', stopped: 'Stopped', starting: 'Starting',
  ready: 'Ready', stopping: 'Stopping', error: 'Error', deleting: 'Deleting',
}
const identifier = z.number().int().positive().max(Number.MAX_SAFE_INTEGER)
export const networkConfigurationSchema = z.object({
  version: z.literal(1), mode: z.literal('automatic'),
  dns_servers: z.array(z.string()), search_domains: z.array(z.string()),
})
const resourceValuesSchema = z.object({
  cpus: z.number().nonnegative(),
  memory_bytes: z.number().int().nonnegative().max(Number.MAX_SAFE_INTEGER),
  storage_bytes: z.number().int().nonnegative().max(Number.MAX_SAFE_INTEGER),
  pids: z.number().int().nonnegative(),
})
export const resourceLimitsSchema = resourceValuesSchema.extend({ version: z.literal(1) })
export const environmentSchema = z.object({
  id: identifier, project_id: identifier,
  name: z.string(), description: z.string().nullable(), base_image: z.string(),
  desired_state: z.enum(['stopped', 'running', 'deleted']),
  status: z.enum(environmentStatuses),
  runtime_generation: z.number().int().nonnegative(),
  runtime_status: z.string().nullable(),
  last_observed_at: z.string().nullable(),
  network_configuration: networkConfigurationSchema.nullable(),
  resource_limits: resourceLimitsSchema.nullable(),
  capabilities: z.object({ update: z.boolean(), configure: z.boolean(), delete: z.boolean() }),
  created_at: z.string(), updated_at: z.string(),
})
export type ProjectEnvironment = z.infer<typeof environmentSchema>

export const environmentPageSchema = z.object({
  data: z.array(environmentSchema),
  meta: z.object({
    current_page: identifier, last_page: identifier, total: z.number().int().nonnegative(),
  }),
})
export const environmentOptionsSchema = z.object({
  approved_images: z.array(z.string().min(1)),
  max_per_project: z.number().int().nonnegative(),
  default_network_configuration: networkConfigurationSchema,
  default_resource_limits: resourceLimitsSchema,
  min_resource_limits: resourceValuesSchema,
  max_resource_limits: resourceValuesSchema,
  network_limits: z.object({ dns_servers: z.number().int().nonnegative(), search_domains: z.number().int().nonnegative() }),
  can_create: z.boolean(),
})
export type EnvironmentOptions = z.infer<typeof environmentOptionsSchema>

export const environmentWriteSchema = z.object({
  name: z.string().trim().min(1).max(255),
  description: z.string().trim().max(10000).nullable(),
  base_image: z.string().min(1).max(255),
  network_configuration: networkConfigurationSchema,
  resource_limits: resourceLimitsSchema,
})
export const environmentUpdateSchema = environmentWriteSchema.partial()
export type EnvironmentWrite = z.infer<typeof environmentWriteSchema>
export type EnvironmentUpdate = z.infer<typeof environmentUpdateSchema>
