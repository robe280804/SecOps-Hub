import { z } from 'zod'

export const accessLevels = ['viewer', 'contributor'] as const
export const accessLevelSchema = z.enum(accessLevels)
export type AccessLevel = z.infer<typeof accessLevelSchema>

export const collaboratorUserSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  email: z.string(),
})
export type CollaboratorUser = z.infer<typeof collaboratorUserSchema>

export const membershipSchema = z.object({
  id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  access_level: accessLevelSchema,
  user: collaboratorUserSchema,
  created_at: z.string(),
  updated_at: z.string(),
})
export type Membership = z.infer<typeof membershipSchema>

export const membershipPageSchema = z.object({
  data: z.array(membershipSchema),
  meta: z.object({
    current_page: z.number().int().positive(),
    last_page: z.number().int().positive(),
    total: z.number().int().nonnegative(),
  }),
})

export const collaboratorFormSchema = z.object({
  email: z.string().trim().min(1, 'Enter the user’s email address.').email('Enter a valid email address.').max(255, 'Use at most 255 characters.'),
  access_level: accessLevelSchema,
})
export type CollaboratorFields = z.infer<typeof collaboratorFormSchema>
