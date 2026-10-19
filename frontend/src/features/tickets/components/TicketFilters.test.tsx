import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { API_CATEGORIES } from '../categoryFixtures'
import type { TicketOptions } from '../types'
import { TicketFilters } from './TicketFilters'

const OPTIONS = {
  categories: API_CATEGORIES,
  statuses: [],
  tags: [],
  priorities: [],
  technicians: [],
} as TicketOptions

function renderFilters(query = '', categories = API_CATEGORIES) {
  const patch = vi.fn()
  const onClear = vi.fn()

  render(
    <TicketFilters
      search=""
      onSearch={vi.fn()}
      params={new URLSearchParams(query)}
      patch={patch}
      options={{ ...OPTIONS, categories }}
      show={['status', 'category']}
      onClear={onClear}
    />,
  )

  return { patch, onClear }
}

/** The label wraps the control, so the accessible name starts with "Category". */
const categorySelect = () =>
  screen.getByRole('combobox', { name: /^category/i }) as HTMLSelectElement

/** Each `<optgroup>`'s heading and the option labels beneath it, in DOM order. */
const groupsOf = (select: HTMLSelectElement) =>
  Array.from(select.querySelectorAll('optgroup')).map((group) => ({
    heading: group.getAttribute('label'),
    labels: Array.from(group.querySelectorAll('option')).map((option) => option.textContent),
  }))

describe('TicketFilters — category dropdown', () => {
  it('is one dropdown, not a set of radio chips or buttons', () => {
    renderFilters()

    expect(categorySelect().tagName).toBe('SELECT')
    expect(screen.queryAllByRole('radio')).toHaveLength(0)
    expect(screen.queryAllByRole('button')).toHaveLength(0)
  })

  it('lists every requested category under its heading, in order', () => {
    renderFilters()

    expect(groupsOf(categorySelect())).toEqual([
      {
        heading: 'Common Problems',
        labels: [
          'Hardware',
          'Software',
          'Network',
          'Performance & System Slowness',
          'Operating System Updates',
          'Power Issue',
          'Audio & Video Output',
          'Browser & Web Issues',
        ],
      },
      {
        heading: 'Advanced / Uncommon Problems',
        labels: [
          'Peripheral',
          'Account & Access',
          'BIOS / Firmware errors',
          'Blue Screen / Kernel Panic',
          'Missing Software',
        ],
      },
      { heading: 'Other Categories', labels: ['Other'] },
    ])
  })

  it('starts with "All Categories", then has no duplicate or missing option', () => {
    renderFilters()

    const options = Array.from(categorySelect().options)
    expect(options[0]).toHaveTextContent('All Categories')
    expect(options[0].value).toBe('')
    // All + 15 requested + the legacy Other, each value once.
    expect(options).toHaveLength(15)
    expect(new Set(options.map((option) => option.value)).size).toBe(15)
  })

  it('shows "All Categories" when closed and no category is chosen', () => {
    renderFilters()

    expect(categorySelect().value).toBe('')
    expect(categorySelect().selectedOptions[0]).toHaveTextContent('All Categories')
  })

  it('shows the category already in the URL as the closed value', () => {
    renderFilters('category=network')

    expect(categorySelect().value).toBe('network')
    expect(categorySelect().selectedOptions[0]).toHaveTextContent('Network')
  })

  it('still selects the legacy Other category from the URL', () => {
    renderFilters('category=other')

    expect(categorySelect().value).toBe('other')
    expect(categorySelect().selectedOptions[0]).toHaveTextContent('Other')
  })

  it('selecting a category patches the API slug and resets paging', async () => {
    const { patch } = renderFilters()

    await userEvent.selectOptions(categorySelect(), 'power-issue')
    expect(patch).toHaveBeenLastCalledWith({ category: 'power-issue', page: null })

    await userEvent.selectOptions(categorySelect(), 'Blue Screen / Kernel Panic')
    expect(patch).toHaveBeenLastCalledWith({ category: 'blue-screen', page: null })
  })

  it('every category can be selected and sends its own distinct value', async () => {
    const { patch } = renderFilters()

    for (const category of API_CATEGORIES) {
      await userEvent.selectOptions(categorySelect(), category.value)
      expect(patch).toHaveBeenLastCalledWith({ category: category.value, page: null })
    }
  })

  it('"All Categories" clears the filter', async () => {
    const { patch } = renderFilters('category=hardware')
    expect(categorySelect().value).toBe('hardware')

    await userEvent.selectOptions(categorySelect(), 'All Categories')
    expect(patch).toHaveBeenLastCalledWith({ category: null, page: null })
  })

  it('is reachable from the keyboard', async () => {
    renderFilters()

    await userEvent.tab() // search box
    await userEvent.tab() // status select
    await userEvent.tab() // category select
    expect(categorySelect()).toHaveFocus()
    // Arrow-key and type-ahead selection are the browser's own behaviour on a
    // native <select> (jsdom does not implement them); they are exercised in a
    // real browser, not here.
  })

  it('omits a group the API returned nothing for', () => {
    renderFilters('', [{ value: 'peripheral', label: 'Peripheral' }])

    expect(groupsOf(categorySelect())).toEqual([
      { heading: 'Advanced / Uncommon Problems', labels: ['Peripheral'] },
    ])
  })

  it('shows only "All Categories" while the options are still loading', () => {
    renderFilters('', [])

    expect(Array.from(categorySelect().options).map((option) => option.textContent)).toEqual([
      'All Categories',
    ])
  })

  it('renders no category control when the surface does not offer it', () => {
    render(
      <TicketFilters
        search=""
        onSearch={vi.fn()}
        params={new URLSearchParams()}
        patch={vi.fn()}
        options={OPTIONS}
        show={['status']}
        onClear={vi.fn()}
      />,
    )

    expect(screen.queryByRole('combobox', { name: /^category/i })).not.toBeInTheDocument()
  })
})
