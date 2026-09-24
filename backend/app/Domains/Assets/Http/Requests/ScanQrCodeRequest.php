<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of a scan (SRS FR-QR-005).
 *
 * **There is nothing required here, and that is the point.** The code travels in
 * the path; the only accepted body fields are the optional coordinates
 * FR-QR-005 names. In particular there is deliberately **no** field for a PC
 * unit, an asset, a maintenance record or a "claimed target" — the server
 * resolves the target from the code alone, so there is no parameter for a
 * caller to forge (DD-48, FR-QR-013).
 *
 * `authorize()` returns true because this endpoint is **public**: an
 * unauthenticated scan is a legitimate, logged event that ends in a redirect to
 * sign-in (FR-QR-010). Authorization happens after resolution, in the
 * controller, and the scan is recorded either way.
 *
 * The bounds mirror `qr_scan_logs_geo_check` exactly, so a coordinate the
 * database would refuse is refused here first with a readable message rather
 * than as a 500.
 */
class ScanQrCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Coordinates as the log wants them, or an empty array when the scanner
     * offered none.
     *
     * Both or neither: a lone latitude is not a location, and storing half a
     * fix would make the column lie about what was known.
     *
     * @return array{latitude?: float, longitude?: float}
     */
    public function geolocation(): array
    {
        $latitude = $this->validated('latitude');
        $longitude = $this->validated('longitude');

        if ($latitude === null || $longitude === null) {
            return [];
        }

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }
}
