<?php

declare(strict_types=1);

namespace App\Domains\Administration\Support;

use App\Domains\Administration\Policies\OutgoingMailPolicy;

/**
 * Authorization marker for the outgoing-mail diagnostics surface
 * (`/api/admin/system/mail`). Not a model: it exists so the routes can be
 * gated by a policy ability — `can:manage,OutgoingMail` — rather than by a
 * permission string, which `Gate::before` would let any per-user grant open.
 *
 * @see OutgoingMailPolicy
 */
final class OutgoingMail {}
