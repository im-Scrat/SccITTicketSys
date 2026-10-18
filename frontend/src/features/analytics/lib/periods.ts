/**
 * Calendar stepping for report periods (‹ › beside the period picker).
 *
 * Works on plain `YYYY-MM-DD` strings with UTC date arithmetic: the anchor is a
 * school-local *date*, and treating it as a date — never as an instant in the
 * browser's zone — is what keeps "the previous month" the same month for an
 * administrator abroad. `today` always comes from the server (Asia/Manila).
 */

export const CALENDAR_PERIODS = ['day', 'week', 'month', 'year'] as const
export type CalendarPeriod = (typeof CALENDAR_PERIODS)[number]

export function isCalendarPeriod(period: string): period is CalendarPeriod {
  return (CALENDAR_PERIODS as readonly string[]).includes(period)
}

function parse(ymd: string): Date {
  const [y, m, d] = ymd.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d))
}

function format(date: Date): string {
  return date.toISOString().slice(0, 10)
}

/** The first date of the calendar period containing `ymd` (weeks start Monday). */
export function periodStart(period: CalendarPeriod, ymd: string): string {
  const date = parse(ymd)

  switch (period) {
    case 'week': {
      const weekday = (date.getUTCDay() + 6) % 7 // Monday = 0
      date.setUTCDate(date.getUTCDate() - weekday)
      return format(date)
    }
    case 'month':
      return format(new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1)))
    case 'year':
      return format(new Date(Date.UTC(date.getUTCFullYear(), 0, 1)))
    default:
      return format(date)
  }
}

/** The start of the next (`+1`) or previous (`-1`) calendar period. */
export function stepPeriod(period: CalendarPeriod, ymd: string, direction: 1 | -1): string {
  const start = parse(periodStart(period, ymd))

  switch (period) {
    case 'week':
      start.setUTCDate(start.getUTCDate() + 7 * direction)
      break
    case 'month':
      start.setUTCMonth(start.getUTCMonth() + direction)
      break
    case 'year':
      start.setUTCFullYear(start.getUTCFullYear() + direction)
      break
    default:
      start.setUTCDate(start.getUTCDate() + direction)
  }

  return format(start)
}

/** Whether the period after `ymd`'s has begun yet (there is nothing to show in the future). */
export function canStepForward(period: CalendarPeriod, ymd: string, today: string): boolean {
  return stepPeriod(period, ymd, 1) <= today
}

/** "month" → "Previous month" / "Next month", for the stepper buttons' names. */
export function stepLabel(period: CalendarPeriod, direction: 1 | -1): string {
  return `${direction === 1 ? 'Next' : 'Previous'} ${period}`
}
