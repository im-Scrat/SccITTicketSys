import { z } from 'zod'

/**
 * Client-side mirrors of the backend FormRequest rules (SRS FR-LOC-001..003) so
 * the form answers before a round trip. The server remains authoritative — it
 * also owns uniqueness, which cannot be decided here.
 */

const optionalText = (max: number) => z.string().max(max).optional().or(z.literal(''))

export const buildingSchema = z.object({
  name: z.string().min(1, 'Name is required.').max(255),
  code: z
    .string()
    .min(1, 'Code is required.')
    .max(50, 'Use 50 characters or fewer.')
    .regex(/^[A-Za-z0-9][A-Za-z0-9 _-]*$/, 'Use letters, numbers, spaces, hyphens or underscores.'),
  description: optionalText(2000),
  address: optionalText(2000),
})
export type BuildingForm = z.infer<typeof buildingSchema>

export const floorSchema = z.object({
  floor_number: z
    .number({ message: 'Enter a floor number.' })
    .int('Use a whole number.')
    // Negatives are valid: basements are floors too.
    .min(-10, 'Floor numbers start at -10.')
    .max(200, 'Floor numbers stop at 200.'),
  name: z.string().min(1, 'Name is required.').max(255),
  description: optionalText(2000),
})
export type FloorForm = z.infer<typeof floorSchema>

export const roomTypeValues = [
  'laboratory',
  'office',
  'storage',
  'server_room',
  'faculty_room',
  'library',
  'other',
] as const

export const roomSchema = z.object({
  floor: z.string().min(1, 'Choose a floor.'),
  name: z.string().min(1, 'Name is required.').max(255),
  code: z
    .string()
    .min(1, 'Code is required.')
    .max(50, 'Use 50 characters or fewer.')
    .regex(/^[A-Za-z0-9][A-Za-z0-9 _-]*$/, 'Use letters, numbers, spaces, hyphens or underscores.'),
  room_number: optionalText(50),
  room_type: z.enum(roomTypeValues),
  capacity: z
    .number({ message: 'Enter a number.' })
    .int('Use a whole number.')
    .min(0, 'Capacity cannot be negative.')
    .max(100000)
    .nullable(),
  description: optionalText(2000),
})
export type RoomForm = z.infer<typeof roomSchema>
