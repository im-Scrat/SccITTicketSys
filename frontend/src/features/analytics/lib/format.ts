/**
 * Report wording shared by the dashboard and Overall Reports, so a change is
 * described the same way everywhere: in words, neutral, with a percentage only
 * when the server gives one (a base of at least 5).
 */
export function changeText(change: number | null, pct: number | null, against: string): string {
  if (change === null) return ''
  if (change === 0) return `the same as ${against}`
  const words = `${Math.abs(change).toLocaleString()} ${change > 0 ? 'more' : 'fewer'} than ${against}`
  return pct === null ? words : `${words}, ${change > 0 ? 'up' : 'down'} ${Math.abs(pct)}%`
}

/** "1 day", "3 days". */
export function plural(count: number, noun: string): string {
  return `${count.toLocaleString()} ${noun}${count === 1 ? '' : 's'}`
}
