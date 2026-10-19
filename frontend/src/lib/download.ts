/**
 * Browser downloads of files the API returns as blobs (users export, report
 * documents). Shared so every download names its file from the server's own
 * `Content-Disposition` and cleans up its object URL the same way.
 */

/** Save a blob under the server's filename (or a fallback). */
export function triggerBlobDownload(blob: Blob, disposition: string, fallback: string): void {
  const match = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)
  const filename = match?.[1] ? decodeURIComponent(match[1]) : fallback
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

/**
 * The `message` of a JSON error that arrived as a blob — a download request
 * asks for `responseType: 'blob'`, so a 409 or 410 explanation comes back as
 * bytes, not as parsed JSON.
 */
export async function readBlobError(data: unknown): Promise<string | null> {
  if (!(data instanceof Blob)) return null
  try {
    const parsed = JSON.parse(await data.text()) as { message?: unknown }
    return typeof parsed.message === 'string' ? parsed.message : null
  } catch {
    return null
  }
}
