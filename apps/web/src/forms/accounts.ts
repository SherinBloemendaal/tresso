import { z } from 'zod'

export const serverSchema = z.object({
  host: z
    .string()
    .trim()
    .min(1, 'Enter the server name.')
    .max(253, 'The server name is too long.')
    .regex(/^[a-z0-9.-]+$/i, 'Use a server name like mail.example.com.'),
  port: z.number({ invalid_type_error: 'Enter a port number.' }).int('Enter a whole number.').min(1, 'Ports run from 1 to 65535.').max(65535, 'Ports run from 1 to 65535.'),
  security: z.enum(['tls', 'starttls', 'none']),
})

export const addressSchema = z.object({
  emailAddress: z.string().trim().min(1, 'Enter the email address.').email('Enter a valid email address.').max(320),
  displayName: z.string().trim().min(1, 'Enter the name people see.').max(120, 'Use at most 120 characters.'),
})

export const connectSchema = z.object({
  username: z.string().trim().min(1, 'Enter the username.').max(320),
  password: z.string().min(1, 'Enter the password or app password.').max(1024),
  imap: serverSchema,
  smtp: serverSchema,
})

export const accountSchema = z.object({
  displayName: z.string().trim().min(1, 'Enter the name people see.').max(120, 'Use at most 120 characters.'),
  username: z.string().trim().min(1, 'Enter the username.').max(320),
  /** Empty keeps the current password. */
  password: z.string().max(1024),
  imap: serverSchema,
  smtp: serverSchema,
})

export type AddressValues = z.infer<typeof addressSchema>
export type ConnectValues = z.infer<typeof connectSchema>
export type AccountValues = z.infer<typeof accountSchema>

/** Validation messages for one server, from a form's flat error map ("imap.host" and so on). */
export function serverErrors(errors: Partial<Record<string, string | undefined>>, prefix: 'imap' | 'smtp'): Record<string, string> {
  const result: Record<string, string> = {}
  for (const [path, message] of Object.entries(errors)) {
    if (message !== undefined && path.startsWith(`${prefix}.`)) result[path.slice(prefix.length + 1)] = message
  }
  return result
}
