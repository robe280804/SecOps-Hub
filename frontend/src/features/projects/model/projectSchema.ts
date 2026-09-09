import { z } from 'zod'

export const projectTypes = ['bug_bounty', 'personal', 'company', 'ctf'] as const
export const projectTypeLabels: Record<typeof projectTypes[number], string> = {
  bug_bounty: 'Bug bounty', personal: 'Personal', company: 'Company', ctf: 'CTF',
}
export const projectSchema = z.object({
  id: z.number().int().positive(),
  user_id: z.number().int().positive(),
  name: z.string(),
  description: z.string().nullable(),
  type: z.enum(projectTypes),
  status: z.enum(['inactive', 'active', 'archived']),
  created_at: z.string(),
  updated_at: z.string(),
})
export type Project = z.infer<typeof projectSchema>

export const projectTextLimits = { name: 255, description: 10000 } as const

export const projectFormSchema = z.object({
  name: z.string().trim().min(1, 'Enter a project name.').max(projectTextLimits.name, 'Use at most 255 characters.'),
  description: z.string().max(projectTextLimits.description, 'Use at most 10,000 characters.'),
  type: z.enum(projectTypes),
  status: z.enum(['inactive', 'active']),
})
export type ProjectFields = z.infer<typeof projectFormSchema>

export const projectPageSchema = z.object({
  data: z.array(projectSchema),
  meta: z.object({
    current_page: z.number().int().positive(),
    last_page: z.number().int().positive(),
    total: z.number().int().nonnegative(),
  }),
})
export type ProjectPage = z.infer<typeof projectPageSchema>
