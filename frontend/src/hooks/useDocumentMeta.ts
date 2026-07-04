import { useEffect } from 'react'

const BASE_TITLE = 'SccIT'
const DEFAULT_DESCRIPTION =
  'SccIT is the operations console for IT asset & service management — unified ticketing, asset lifecycle, preventive maintenance, QR tracking, interactive floor plans, grounded AI, and analytics in one calm, dense platform.'

interface DocumentMeta {
  /** Page title; composed as "<title> · SccIT" unless it already contains the brand. */
  title: string
  description?: string
}

/**
 * Sets the document title (and meta description) per route. A lightweight
 * stand-in for a head manager — enough for a handful of public routes without
 * pulling in another dependency.
 */
export function useDocumentMeta({ title, description }: DocumentMeta): void {
  useEffect(() => {
    const previousTitle = document.title
    document.title = title.includes(BASE_TITLE) ? title : `${title} · ${BASE_TITLE}`

    const meta = document.querySelector<HTMLMetaElement>('meta[name="description"]')
    const previousDescription = meta?.getAttribute('content') ?? null
    if (meta) meta.setAttribute('content', description ?? DEFAULT_DESCRIPTION)

    return () => {
      document.title = previousTitle
      if (meta && previousDescription !== null) meta.setAttribute('content', previousDescription)
    }
  }, [title, description])
}
