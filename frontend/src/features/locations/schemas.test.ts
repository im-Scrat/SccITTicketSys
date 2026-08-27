import { describe, expect, it } from 'vitest'
import { buildingSchema, floorSchema, roomSchema } from './schemas'

describe('buildingSchema', () => {
  it('accepts a valid building', () => {
    const result = buildingSchema.safeParse({
      name: 'Science Hall',
      code: 'SCI-1',
      description: '',
      address: '14 Fields Road',
    })
    expect(result.success).toBe(true)
  })

  it('requires a name and a code', () => {
    const result = buildingSchema.safeParse({ name: '', code: '' })
    expect(result.success).toBe(false)
  })

  it('rejects a code with punctuation that would not survive a label', () => {
    const result = buildingSchema.safeParse({ name: 'Hall', code: 'SCI/1' })
    expect(result.success).toBe(false)
  })
})

describe('floorSchema', () => {
  it('accepts a basement as a negative number', () => {
    const result = floorSchema.safeParse({ floor_number: -1, name: 'Basement', description: '' })
    expect(result.success).toBe(true)
  })

  it('rejects a fractional floor number', () => {
    const result = floorSchema.safeParse({ floor_number: 1.5, name: 'Mezzanine' })
    expect(result.success).toBe(false)
  })

  it('rejects a missing name', () => {
    const result = floorSchema.safeParse({ floor_number: 2, name: '' })
    expect(result.success).toBe(false)
  })
})

describe('roomSchema', () => {
  const valid = {
    floor: '7c9e6679-7425-40de-944b-e07fc1f90ae7',
    name: 'Computer Lab 1',
    code: 'LAB-1',
    room_number: '204',
    room_type: 'laboratory' as const,
    capacity: 30,
    description: '',
  }

  it('accepts a valid room', () => {
    expect(roomSchema.safeParse(valid).success).toBe(true)
  })

  it('accepts a null capacity (not every room seats people)', () => {
    expect(roomSchema.safeParse({ ...valid, capacity: null }).success).toBe(true)
  })

  it('rejects a negative capacity, mirroring the database CHECK', () => {
    expect(roomSchema.safeParse({ ...valid, capacity: -1 }).success).toBe(false)
  })

  it('rejects an unknown room type', () => {
    expect(roomSchema.safeParse({ ...valid, room_type: 'dungeon' }).success).toBe(false)
  })

  it('requires a floor to be chosen', () => {
    expect(roomSchema.safeParse({ ...valid, floor: '' }).success).toBe(false)
  })
})
