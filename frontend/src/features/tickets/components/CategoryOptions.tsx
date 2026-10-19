import { groupCategories } from '../categoryGroups'
import type { OptionItem } from '../types'

/**
 * The `<option>`s of a category `<select>`, under their group headings.
 *
 * Drop it inside a `<select>`, after the placeholder option. The values are the
 * API's slugs, so what a form submits is exactly what it did before the grouping;
 * only the presentation changed. A category outside both groups — the legacy
 * "Other" — still appears, under "Other Categories", never hidden.
 *
 * Shared by the report form and the edit drawer so the two cannot drift into
 * showing different lists for the same field.
 */
export function CategoryOptions({ categories }: { categories: OptionItem[] }) {
  return (
    <>
      {groupCategories(categories).map((group) => (
        <optgroup key={group.key} label={group.heading}>
          {group.options.map((category) => (
            <option key={category.value} value={category.value}>
              {category.label}
            </option>
          ))}
        </optgroup>
      ))}
    </>
  )
}
