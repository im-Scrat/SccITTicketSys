import { describe, expect, it } from 'vitest'
import { API_CATEGORIES } from './categoryFixtures'
import { groupCategories } from './categoryGroups'
import type { OptionItem } from './types'

const labels = (options: OptionItem[]) => options.map((option) => option.label)

describe('groupCategories', () => {
  it('puts the requested categories in each group, in the requested order', () => {
    const [common, advanced] = groupCategories(API_CATEGORIES)

    expect(common.heading).toBe('Common Problems')
    expect(labels(common.options)).toEqual([
      'Hardware',
      'Software',
      'Network',
      'Performance & System Slowness',
      'Operating System Updates',
      'Power Issue',
      'Audio & Video Output',
      'Browser & Web Issues',
    ])

    expect(advanced.heading).toBe('Advanced / Uncommon Problems')
    expect(labels(advanced.options)).toEqual([
      'Peripheral',
      'Account & Access',
      'BIOS / Firmware errors',
      'Blue Screen / Kernel Panic',
      'Missing Software',
    ])
  })

  it('orders by the defined list, not by the order the API happened to return', () => {
    const shuffled = [...API_CATEGORIES].reverse()
    const [common, advanced] = groupCategories(shuffled)

    expect(common.options[0].value).toBe('hardware')
    expect(common.options.at(-1)?.value).toBe('browser-web')
    expect(advanced.options[0].value).toBe('peripheral')
  })

  it('offers one "Other", under "Other Categories" — no group has a catch-all of its own', () => {
    const groups = groupCategories(API_CATEGORIES)
    const others = groups.flatMap((group) =>
      group.options
        .filter((o) => o.label.startsWith('Other'))
        .map((o) => `${group.heading}: ${o.label}`),
    )

    expect(others).toEqual(['Other Categories: Other'])
  })

  it('puts a retired group catch-all under "Other Categories" if the API ever returns it again', () => {
    const groups = groupCategories([
      ...API_CATEGORIES,
      { value: 'other-common', label: 'Other Common Issues' },
    ])

    expect(
      groups.find((group) => group.key === 'common')?.options.map((o) => o.value),
    ).not.toContain('other-common')
    expect(groups.find((group) => group.key === 'other')?.options.map((o) => o.value)).toContain(
      'other-common',
    )
  })

  it('keeps the API value untouched — the group never remaps a slug', () => {
    const [common] = groupCategories(API_CATEGORIES)

    expect(common.options.find((o) => o.label === 'Power Issue')?.value).toBe('power-issue')
  })

  it('never drops a category: one in neither group lands in a trailing group', () => {
    const groups = groupCategories(API_CATEGORIES)

    expect(groups.map((group) => group.key)).toEqual(['common', 'advanced', 'other'])
    expect(labels(groups[2].options)).toEqual(['Other'])

    const everyValue = groups.flatMap((group) => group.options.map((o) => o.value))
    expect(everyValue).toHaveLength(API_CATEGORIES.length)
    expect(new Set(everyValue).size).toBe(API_CATEGORIES.length)
  })

  it('does not invent a category the API did not return', () => {
    const groups = groupCategories([
      { value: 'hardware', label: 'Hardware' },
      { value: 'peripheral', label: 'Peripheral' },
    ])

    expect(groups.map((group) => group.key)).toEqual(['common', 'advanced'])
    expect(labels(groups[0].options)).toEqual(['Hardware'])
    expect(labels(groups[1].options)).toEqual(['Peripheral'])
  })

  it('shows the API label, so a database rename is never masked', () => {
    const [common] = groupCategories([{ value: 'hardware', label: 'Hardware (renamed)' }])

    expect(labels(common.options)).toEqual(['Hardware (renamed)'])
  })

  it('omits empty groups and returns nothing for an empty list', () => {
    expect(groupCategories([])).toEqual([])
    expect(
      groupCategories([{ value: 'peripheral', label: 'Peripheral' }]).map((g) => g.key),
    ).toEqual(['advanced'])
  })
})
