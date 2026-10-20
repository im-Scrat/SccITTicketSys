import { z } from 'zod'
import { PC_STATUS_VALUES } from '@/lib/pcStatus'

/**
 * Client-side mirrors of the backend FormRequest rules (SRS FR-AST-002,
 * FR-PC-001/003/005) so the form answers before a round trip. The server remains
 * authoritative — it also owns uniqueness, which cannot be decided here.
 */

const optionalText = (max: number) => z.string().max(max).optional().or(z.literal(''))

/** An ISO date string or nothing. Empty string means "cleared". */
const optionalDate = z
  .string()
  .refine((value) => value === '' || !Number.isNaN(Date.parse(value)), 'Enter a valid date.')
  .optional()
  .or(z.literal(''))

export const assetStatusValues = [
  'new',
  'in_stock',
  'reserved',
  'deployed',
  'in_repair',
  'out_of_service',
  'in_transit',
  'retired',
  'disposed',
] as const

export const conditionValues = ['working', 'faulty', 'for_repair', 'decommissioned'] as const

/** Phase 2.11 (DD-100) — the three repair statuses, from the shared table. */
export const pcStatusValues = PC_STATUS_VALUES

/** The catalog-model select's "none chosen" and "Other — describe it" values. */
export const NO_MODEL_VALUE = 0
export const OTHER_MODEL_VALUE = -1

/**
 * "Other" in the Category and Supplier selects (owner request 2026-10-20,
 * DD-114). Category's is the real `other` component type; Supplier's is a
 * sentinel no supplier name can collide with, since names come from the server.
 */
export const OTHER_CATEGORY_VALUE = 'other'
export const OTHER_SUPPLIER_VALUE = '__other__'
export const CATEGORY_DETAIL_MIN = 3
export const CATEGORY_DETAIL_MAX = 120

/*
 * Every field is optional (owner request, 2026-10-19). There is no asset tag:
 * the server generates one internally and it is not shown anywhere. With no
 * catalog model the asset is filed under "Unspecified model"; "Other" describes
 * a model that is not in the catalog yet, and the server files it there.
 */
export const assetSchema = z
  .object({
    name: optionalText(255),
    hardware_model: z.number().int().min(OTHER_MODEL_VALUE).catch(NO_MODEL_VALUE),
    hardware_model_other: optionalText(255),
    category: optionalText(64),
    category_detail: optionalText(CATEGORY_DETAIL_MAX),
    supplier: optionalText(255),
    supplier_other: optionalText(255),
    room: optionalText(64),
    technician: optionalText(64),
    serial_number: optionalText(255),
    barcode: optionalText(255),
    status: z.enum(assetStatusValues),
    condition: z.enum(conditionValues),
    purchase_price: z
      .number()
      .min(0, 'Price cannot be negative.')
      .max(99999999.99)
      .nullable()
      .optional(),
    purchase_date: optionalDate,
    warranty_expiration: optionalDate,
    notes: optionalText(5000),
  })
  // Mirrors the `assets_warranty_check` database constraint, so the operator
  // sees the problem on the field rather than as a failed save.
  .refine(
    (value) =>
      !value.purchase_date ||
      !value.warranty_expiration ||
      Date.parse(value.warranty_expiration) >= Date.parse(value.purchase_date),
    { message: 'Warranty cannot expire before the purchase date.', path: ['warranty_expiration'] },
  )
  // "Other" asks for words; the server refuses it without them (DD-114).
  .refine(
    (value) =>
      value.category !== OTHER_CATEGORY_VALUE ||
      (value.category_detail ?? '').trim().length >= CATEGORY_DETAIL_MIN,
    { message: 'Describe the category in a few words.', path: ['category_detail'] },
  )
  .refine(
    (value) =>
      value.supplier !== OTHER_SUPPLIER_VALUE || (value.supplier_other ?? '').trim().length > 0,
    { message: 'Type the supplier’s name.', path: ['supplier_other'] },
  )
export type AssetForm = z.infer<typeof assetSchema>

export const specificationSchema = z.object({
  cpu: optionalText(255),
  motherboard: optionalText(255),
  ram: optionalText(255),
  gpu: optionalText(255),
  storage_primary: optionalText(255),
  storage_secondary: optionalText(255),
  power_supply: optionalText(255),
  monitor: optionalText(255),
  keyboard: optionalText(255),
  mouse: optionalText(255),
  operating_system: optionalText(255),
  bios_version: optionalText(255),
  network_adapter: optionalText(255),
})
export type SpecificationForm = z.infer<typeof specificationSchema>

export const pcUnitSchema = z
  .object({
    unit_code: z
      .string()
      .min(1, 'Unit code is required.')
      .max(255)
      .regex(
        /^[A-Za-z0-9][A-Za-z0-9 _/-]*$/,
        'Use letters, numbers, spaces, hyphens, slashes or underscores.',
      ),
    pc_name: z.string().min(1, 'PC name is required.').max(255),
    hostname: optionalText(255),
    serial_number: optionalText(255),
    brand: optionalText(255),
    model: optionalText(255),
    room: optionalText(64),
    ip_address: z
      .string()
      .refine(
        (value) =>
          value === '' || /^(\d{1,3}\.){3}\d{1,3}$/.test(value) || /^[0-9a-fA-F:]+$/.test(value),
        'Enter a valid IP address.',
      )
      .optional()
      .or(z.literal('')),
    mac_address: z
      .string()
      .refine(
        (value) => value === '' || /^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/.test(value),
        'Enter a MAC address like 00:1B:44:11:3A:B7.',
      )
      .optional()
      .or(z.literal('')),
    status: z.enum(pcStatusValues),
    current_condition: z.enum(conditionValues),
    purchase_date: optionalDate,
    warranty_expiration: optionalDate,
    notes: optionalText(5000),
  })
  .refine(
    (value) =>
      !value.purchase_date ||
      !value.warranty_expiration ||
      Date.parse(value.warranty_expiration) >= Date.parse(value.purchase_date),
    { message: 'Warranty cannot expire before the purchase date.', path: ['warranty_expiration'] },
  )
export type PcUnitForm = z.infer<typeof pcUnitSchema>
