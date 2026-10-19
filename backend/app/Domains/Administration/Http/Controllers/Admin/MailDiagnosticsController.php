<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers\Admin;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Mail\MailDiagnostics;
use App\Support\Mail\MailFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Outgoing-mail diagnostics for Administrators — the in-app equivalent of
 * `php artisan mail:check`, needed where there is no shell (Render's free
 * instances, render.com/docs/free).
 *
 *  - `GET  /admin/system/mail`       configuration; credentials as set / not set
 *  - `POST /admin/system/mail/check` connect, TLS, authenticate — sends nothing
 *  - `POST /admin/system/mail/test`  one fixed message to **the caller's own
 *                                    address**; there is no recipient field
 *
 * The test message cannot be aimed at anyone else, carries no text from the
 * request, and is throttled and audited — so this route is not a way to send
 * mail, only a way to prove mail can be sent. Results are a
 * {@see MailFailure} name and an SMTP code: never the relay's
 * own text. "accepted" means the relay took the message, not that it arrived.
 */
class MailDiagnosticsController extends Controller
{
    public function __construct(
        private readonly MailDiagnostics $diagnostics,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->diagnostics->configuration()]);
    }

    public function check(Request $request): JsonResponse
    {
        $result = $this->diagnostics->probe();

        $this->audit->activity(
            ActivityAction::MailConnectionChecked,
            $this->actor($request),
            null,
            ['outcome' => $result['outcome'], 'smtp_code' => $result['smtp_code']],
            $request,
            'administration',
        );

        return response()->json(['data' => $result]);
    }

    public function test(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $result = $this->diagnostics->sendTest((string) $actor->email, 'an administrator ('.$actor->fullName().')');

        $this->audit->activity(
            ActivityAction::MailTestSent,
            $actor,
            null,
            ['outcome' => $result['outcome'], 'smtp_code' => $result['smtp_code']],
            $request,
            'administration',
        );

        return response()->json(['data' => [...$result, 'sent_to' => (string) $actor->email]]);
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
