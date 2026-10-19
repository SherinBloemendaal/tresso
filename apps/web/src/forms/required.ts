import { inject, provide, type InjectionKey } from 'vue'
import { z } from 'zod'

const REQUIRED: InjectionKey<ReadonlySet<string>> = Symbol('required fields')

function unwrap(schema: z.ZodTypeAny): z.ZodTypeAny {
  let current = schema
  for (;;) {
    if (current instanceof z.ZodEffects) current = current.innerType()
    else if (current instanceof z.ZodPipeline) current = current._def.in
    else if (current instanceof z.ZodDefault) current = current._def.innerType
    else return current
  }
}

/** Whether a value must be given: not optional or nullable, and a string must have at least one character. */
function isRequired(schema: z.ZodTypeAny): boolean {
  if (schema.isOptional() || schema.isNullable()) return false
  const inner = unwrap(schema)
  if (inner instanceof z.ZodString) return (inner.minLength ?? 0) >= 1
  if (inner instanceof z.ZodArray) return (inner._def.minLength?.value ?? 0) >= 1
  return inner instanceof z.ZodNumber || inner instanceof z.ZodEnum || inner instanceof z.ZodBoolean
}

/** Every field path ("imap.host") the schema requires. */
export function requiredPaths(schema: z.ZodTypeAny, prefix = ''): Set<string> {
  const paths = new Set<string>()
  const inner = unwrap(schema)
  if (!(inner instanceof z.ZodObject)) return paths
  for (const [key, field] of Object.entries(inner.shape as Record<string, z.ZodTypeAny>)) {
    const path = prefix === '' ? key : `${prefix}.${key}`
    if (unwrap(field) instanceof z.ZodObject) {
      for (const nested of requiredPaths(field, path)) paths.add(nested)
    } else if (isRequired(field)) {
      paths.add(path)
    }
  }
  return paths
}

/** Lets the field labels under a component know which fields its form schemas require. */
export function provideRequiredFields(...schemas: z.ZodTypeAny[]): void {
  const paths = new Set<string>()
  for (const schema of schemas) {
    for (const path of requiredPaths(schema)) paths.add(path)
  }
  provide(REQUIRED, paths)
}

export function useRequired(path: string | undefined): boolean {
  const required = inject(REQUIRED, new Set<string>())
  return path !== undefined && required.has(path)
}
