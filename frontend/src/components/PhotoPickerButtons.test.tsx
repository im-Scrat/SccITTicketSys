import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { PHOTO_ACCEPT } from '@/lib/images'
import { PhotoPickerButtons } from './PhotoPickerButtons'

const originalMatchMedia = window.matchMedia

/** jsdom has no matchMedia; this answers "is the pointer a finger?". */
function pointerIsCoarse(coarse: boolean) {
  window.matchMedia = vi.fn((query: string) => ({
    matches: query === '(pointer: coarse)' ? coarse : false,
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })) as unknown as typeof window.matchMedia
}

afterEach(() => {
  window.matchMedia = originalMatchMedia
})

const photo = (name: string) => new File(['bytes'], name, { type: 'image/jpeg' })

describe('PhotoPickerButtons', () => {
  it('offers the camera and the gallery on a touch screen', () => {
    pointerIsCoarse(true)
    render(<PhotoPickerButtons onPick={vi.fn()} />)

    expect(screen.getByRole('button', { name: 'Take a photo' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Choose from gallery' })).toBeInTheDocument()
  })

  it('offers one honest button on a desktop, where capture would be ignored', () => {
    pointerIsCoarse(false)
    render(<PhotoPickerButtons onPick={vi.fn()} />)

    expect(screen.queryByRole('button', { name: 'Take a photo' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Choose photos' })).toBeInTheDocument()
    expect(screen.queryByTestId('photo-camera-input')).not.toBeInTheDocument()
  })

  it('works where matchMedia does not exist at all', () => {
    // @ts-expect-error — simulating an engine without the API
    window.matchMedia = undefined
    render(<PhotoPickerButtons onPick={vi.fn()} />)

    expect(screen.getByRole('button', { name: 'Choose photos' })).toBeInTheDocument()
  })

  it('asks the camera for the rear lens, and lets the gallery pick several photos', () => {
    pointerIsCoarse(true)
    render(<PhotoPickerButtons onPick={vi.fn()} />)

    const camera = screen.getByTestId('photo-camera-input')
    expect(camera).toHaveAttribute('capture', 'environment')
    expect(camera).toHaveAttribute('accept', PHOTO_ACCEPT)
    expect(camera).not.toHaveAttribute('multiple')

    const gallery = screen.getByTestId('photo-gallery-input')
    expect(gallery).toHaveAttribute('multiple')
    expect(gallery).toHaveAttribute('accept', PHOTO_ACCEPT)
    expect(gallery).not.toHaveAttribute('capture')
  })

  it('keeps HEIC out of the filter, so an iPhone converts to JPEG before handing over', () => {
    expect(PHOTO_ACCEPT).not.toMatch(/heic|heif/i)
  })

  it('hides the inputs from the keyboard and from screen readers — the buttons are the controls', () => {
    pointerIsCoarse(true)
    render(<PhotoPickerButtons onPick={vi.fn()} />)

    for (const input of [
      screen.getByTestId('photo-camera-input'),
      screen.getByTestId('photo-gallery-input'),
    ]) {
      expect(input).toHaveAttribute('tabindex', '-1')
      expect(input).toHaveAttribute('aria-hidden', 'true')
    }
  })

  it('hands over every picked file, then clears the input so the same photo can be picked again', async () => {
    pointerIsCoarse(false)
    const onPick = vi.fn()
    render(<PhotoPickerButtons onPick={onPick} />)

    const gallery = screen.getByTestId('photo-gallery-input') as HTMLInputElement
    const files = [photo('a.jpg'), photo('b.jpg')]
    await userEvent.upload(gallery, files)

    expect(onPick).toHaveBeenCalledWith(files)
    expect(gallery.value).toBe('')
  })

  it('opens the hidden picker from its button', async () => {
    pointerIsCoarse(false)
    render(<PhotoPickerButtons onPick={vi.fn()} />)
    const gallery = screen.getByTestId('photo-gallery-input') as HTMLInputElement
    const click = vi.spyOn(gallery, 'click')

    await userEvent.click(screen.getByRole('button', { name: 'Choose photos' }))

    expect(click).toHaveBeenCalled()
  })

  it('can be disabled', () => {
    pointerIsCoarse(true)
    render(<PhotoPickerButtons onPick={vi.fn()} disabled />)

    expect(screen.getByRole('button', { name: 'Take a photo' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Choose from gallery' })).toBeDisabled()
  })
})
