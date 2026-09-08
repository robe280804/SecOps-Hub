import { z } from 'zod'

export const registrationSchema = z
  .object({
    name: z
      .string()
      .refine((name) => name.trim().length > 0, 'Name is required.')
      .refine((name) => name.length <= 255, 'Name must not exceed 255 characters.'),
    email: z
      .string()
      .min(1, 'Email is required.')
      .max(255, 'Email must not exceed 255 characters.')
      .email('Enter a valid email address.'),
    password: z
      .string()
      .min(12, 'Password must contain at least 12 characters.')
      .regex(/[a-z]/, 'Password must contain a lowercase letter.')
      .regex(/[A-Z]/, 'Password must contain an uppercase letter.')
      .regex(/\d/, 'Password must contain a number.')
      .regex(/[^A-Za-z0-9]/, 'Password must contain a symbol.'),
    passwordConfirmation: z.string().min(1, 'Confirm your password.'),
  })
  .refine((fields) => fields.password === fields.passwordConfirmation, {
    message: 'Passwords do not match.',
    path: ['passwordConfirmation'],
  })

export type RegistrationFields = z.infer<typeof registrationSchema>
