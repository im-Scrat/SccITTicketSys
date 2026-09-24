<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Domains\Assets\Actions\AttachAssetFile;
use App\Domains\Maintenance\Actions\AttachRepairImage;
use App\Domains\Tickets\Actions\AttachTicketFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **The single trust boundary for every uploaded file** (SDD DD-45;
 * SRS NFR-SEC-007/008).
 *
 * Assets and Tickets each grew their own upload path, and they drifted: both
 * validated the *content* server-side but then persisted and re-served
 * `getClientMimeType()` — a string the uploader controls and nothing ever
 * checks. Served back `inline`, that turned a permitted upload into stored XSS
 * (a real `text/plain` file, declared `text/html`, executing in the app's own
 * origin when an Administrator opened it).
 *
 * The lesson is not "patch two call sites". It is that **two upload designs
 * means one of them is the weak one**, so every rule that makes a file safe now
 * lives here and both modules call it:
 *
 *  - the MIME allow-list ({@see PROFILES}),
 *  - the validation rules built from that allow-list ({@see rules()}),
 *  - trusted server-side detection ({@see detect()}),
 *  - the image/document classification ({@see kindFor()}),
 *  - and the response that hands a file back ({@see stream()}).
 *
 * ── Why detection and validation are both here ─────────────────────────────
 *
 * Laravel's `mimetypes:` rule already guesses from content, so validation was
 * never the hole. The hole was that the *stored* value came from a different,
 * unvalidated source. {@see detect()} closes that by re-deriving the type from
 * the file itself and refusing anything outside the profile — so the value that
 * reaches the database is the same one validation approved, by construction
 * rather than by coincidence.
 *
 * ── Why the response is here too ───────────────────────────────────────────
 *
 * A safe value in the database is worth nothing if the download controller
 * re-introduces the problem. {@see stream()} therefore never trusts the stored
 * string either: it re-checks it against the allow-list and falls back to
 * `application/octet-stream`. That also **neutralises rows written before this
 * fix** — a legacy `text/html` label is not in any profile, so it degrades to an
 * opaque download with no migration required.
 *
 * @see AttachAssetFile
 * @see AttachTicketFile
 * @see AttachRepairImage
 */
final class AttachmentSecurity
{
    /** Asset and PC-unit evidence: photographs and documentation. */
    public const PROFILE_ASSET = 'asset';

    /** Ticket evidence: photographs, documents, and pasted log text. */
    public const PROFILE_TICKET = 'ticket';

    /**
     * Repair evidence on a maintenance record: photographs and documentation
     * (SDD DD-53).
     *
     * A third *profile*, deliberately not a third upload *path*. DD-45 exists
     * because two upload designs had already drifted until one of them was the
     * weak one; adding a third design would repeat exactly that mistake, so
     * Maintenance calls this class like everyone else and merely brings its own
     * allow-list.
     *
     * Narrower than the ticket profile on purpose: repair evidence is pictures
     * of hardware and the occasional service document, so `text/plain` and
     * `image/gif` are absent. A narrower allow-list is the entire point of
     * having profiles rather than one shared list.
     */
    public const PROFILE_MAINTENANCE = 'maintenance';

    /**
     * The allow-list, as `detected MIME => permitted extensions`.
     *
     * Deliberately expressed as a map rather than two parallel lists: the
     * extension rule and the MIME rule must never be able to drift apart, and a
     * new type cannot be added to one without the other.
     *
     * These are exactly the types each module accepted before this change —
     * widening the surface while fixing a vulnerability would be the wrong
     * trade. Tickets keep `text/plain` because pasting a log file is a genuine
     * part of reporting a fault.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const PROFILES = [
        self::PROFILE_ASSET => [
            'image/png' => ['png'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
        ],
        self::PROFILE_TICKET => [
            'image/png' => ['png'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/webp' => ['webp'],
            'image/gif' => ['gif'],
            'application/pdf' => ['pdf'],
            'text/plain' => ['txt', 'log'],
        ],
        self::PROFILE_MAINTENANCE => [
            'image/png' => ['png'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
        ],
    ];

    /**
     * Types that may be returned with their real `Content-Type` *and* rendered
     * inline by a browser.
     *
     * Raster images only. A PDF is excluded on purpose even though it is an
     * allowed upload: PDF is a scripting format, and an inline PDF viewer runs
     * in the app's origin. Text is excluded because a text/* response is the
     * classic sniffing target. Both are still downloadable — see {@see stream()}.
     *
     * @var list<string>
     */
    private const INLINE_SAFE = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

    public const KIND_IMAGE = 'image';

    public const KIND_DOCUMENT = 'document';

    /**
     * Validation rules for the uploaded file field.
     *
     * `mimetypes:` guesses from content and `extensions:` checks the filename;
     * both are kept because they fail differently — content validation stops a
     * disguised payload, extension validation stops a file whose name would
     * mislead a human or a downstream tool.
     *
     * @return list<string>
     */
    public static function rules(string $profile): array
    {
        $maxKb = (int) config('security.uploads.max_kb', 10240);

        return [
            'required',
            'file',
            "max:{$maxKb}",
            'mimetypes:'.implode(',', self::allowedMimes($profile)),
            'extensions:'.implode(',', self::allowedExtensions($profile)),
        ];
    }

    /**
     * The **trusted** MIME type of an upload.
     *
     * Reads the type from the file's own bytes (`getMimeType()` → finfo), never
     * from the request. The client-supplied `getClientMimeType()` is not
     * consulted anywhere in this class, and must not be consulted anywhere else.
     *
     * Re-checks the allow-list rather than assuming the FormRequest ran, so an
     * Action stays safe if it is ever called from a command, a job or a test
     * that has no request behind it.
     *
     * @throws ValidationException when the detected type is outside the profile
     */
    public static function detect(UploadedFile $file, string $profile): string
    {
        $mime = $file->getMimeType();

        if ($mime === null || ! in_array($mime, self::allowedMimes($profile), true)) {
            throw ValidationException::withMessages([
                'file' => 'That file type is not accepted.',
            ]);
        }

        return $mime;
    }

    /**
     * Classify a **trusted** MIME type for display purposes.
     *
     * Only ever call this with a value from {@see detect()} or one already
     * re-checked by {@see isAllowed()}; classifying an untrusted string is how
     * a document ends up presenting itself as an image in a gallery.
     */
    public static function kindFor(?string $mime): string
    {
        return $mime !== null && str_starts_with($mime, 'image/')
            ? self::KIND_IMAGE
            : self::KIND_DOCUMENT;
    }

    /** Is this stored MIME type one the profile actually permits? */
    public static function isAllowed(?string $mime, string $profile): bool
    {
        return $mime !== null && in_array($mime, self::allowedMimes($profile), true);
    }

    /**
     * Stream a stored attachment back to an authorized caller.
     *
     * Every header here is decided by the server:
     *
     *  - **`Content-Type`** is the stored type only if it is still in the
     *    profile's allow-list; anything else — including a row poisoned before
     *    this class existed — becomes `application/octet-stream`.
     *  - **`Content-Disposition`** is `inline` only for raster images. PDFs,
     *    text and unknown types are forced to `attachment`, so the browser saves
     *    them instead of rendering them in this origin. The SPA is unaffected:
     *    it fetches attachments as blobs over XHR and builds its own object
     *    URLs, and `Content-Disposition` has no bearing on that.
     *  - **`X-Content-Type-Options: nosniff`** stops the browser from sniffing
     *    its way back to a dangerous type when the declared one is boring.
     *  - **`Content-Security-Policy: default-src 'none'; sandbox`** is the
     *    backstop: even if some future change served the wrong type, a sandboxed
     *    document with no permitted sources cannot execute script or reach the
     *    session. Applied per-response because this is the one route that hands
     *    back bytes a user supplied.
     */
    public static function stream(
        FilesystemAdapter $disk,
        string $storagePath,
        string $downloadName,
        ?string $storedMime,
        string $profile,
    ): StreamedResponse {
        $safe = self::isAllowed($storedMime, $profile);

        $contentType = $safe ? (string) $storedMime : 'application/octet-stream';

        $disposition = $safe && in_array($storedMime, self::INLINE_SAFE, true)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        return $disk->response(
            $storagePath,
            $downloadName,
            [
                'Content-Type' => $contentType,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Referrer-Policy' => 'no-referrer',
            ],
            $disposition,
        );
    }

    /**
     * The accepted MIME types for a profile.
     *
     * @return list<string>
     */
    public static function allowedMimes(string $profile): array
    {
        return array_keys(self::profile($profile));
    }

    /**
     * The accepted filename extensions for a profile.
     *
     * @return list<string>
     */
    public static function allowedExtensions(string $profile): array
    {
        return array_values(array_unique(array_merge(...array_values(self::profile($profile)))));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function profile(string $profile): array
    {
        return self::PROFILES[$profile]
            ?? throw new \InvalidArgumentException("Unknown attachment profile [{$profile}].");
    }
}
