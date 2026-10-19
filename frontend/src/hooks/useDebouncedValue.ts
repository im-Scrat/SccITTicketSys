import { useEffect, useState } from 'react'

/**
 * `value`, once it has stopped changing for `delay` ms.
 *
 * For anything that turns a keystroke or an arrow-key press into a request: a
 * keyboard user arrowing through twenty radio buttons should cause one lookup
 * for where they stop, not twenty for everywhere they passed.
 */
export function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])

  return debounced
}
