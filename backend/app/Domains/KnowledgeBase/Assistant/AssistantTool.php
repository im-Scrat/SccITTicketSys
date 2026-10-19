<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant;

use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Base of every lookup the assistant can make on the user's behalf
 * (pre-commit fix pass 2, Phase 2.9; SRS FR-AI-004/030/032; SDD DD-75).
 *
 * ── The contract every tool inherits ───────────────────────────────────────
 *
 *  Whose data      `$this->user` is injected by {@see AssistantToolbox} from
 *                  `$request->user()` when the agent is built. No tool declares
 *                  a user, role, owner or reporter argument, so nothing the
 *                  model emits can point a lookup at another person.
 *  Read-only       `run()` queries; it never writes. The marker interface
 *                  {@see ReadOnlyAssistantTool} and the toolbox test hold every
 *                  tool to that, which is what keeps FR-AI-032 ("advises, never
 *                  acts") true now that the agent has tools at all.
 *  Authorized      Each tool re-checks the permission/policy that gates it in
 *                  `run()`, even though the toolbox only registers it when the
 *                  gate passes — the toolbox is a courtesy, the tool is the gate.
 *  Allowlisted     Output is built field by field from named columns, never
 *                  from `toArray()` or a resource, and is bounded in size.
 *  Redacted        Tool results go from the SDK straight to the provider, so
 *                  they do not pass through SafeAgentInvoker. `handle()` applies
 *                  the same {@see PromptRedactor} here: credentials, e-mails,
 *                  phone numbers, and the names of the people connected to the
 *                  user's records.
 *  Fail soft       A tool never throws into the generation loop; a failure is
 *                  one fixed sentence the model can relay.
 */
abstract class AssistantTool implements ReadOnlyAssistantTool, Tool
{
    /** Hard cap on one tool result — a lookup is a fact sheet, not a dump. */
    protected const MAX_CHARS = 6000;

    /**
     * Extra names this tool found in the records it read (reporters, technicians).
     *
     * @var list<string>
     */
    protected array $names = [];

    public function __construct(
        protected readonly User $user,
        protected readonly PromptRedactor $redactor,
        protected readonly AssistantContext $context,
    ) {}

    /** The function name the model calls — snake_case, stable, never a class name. */
    abstract public function name(): string;

    /** The lookup itself. May throw; `handle()` turns that into a sentence. */
    abstract protected function run(Request $request): string;

    public function handle(Request $request): string
    {
        try {
            $text = $this->run($request);
        } catch (ValidationException $e) {
            $text = 'Invalid arguments: '.implode(' ', Arr::flatten($e->errors()));
        } catch (Throwable $e) {
            report($e);
            $text = 'That lookup is not available right now.';
        }

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS)."\n[truncated]";
        }

        $names = array_values(array_unique([
            ...$this->context->counterpartNames($this->user),
            ...$this->names,
        ]));

        return $this->redactor->redact($text, $names);
    }

    /** Remember a person whose name may appear in this tool's output, so it is removed. */
    protected function noteName(?User $person): void
    {
        if ($person === null) {
            return;
        }

        $name = trim(implode(' ', array_filter([$person->first_name, $person->middle_name, $person->last_name])));

        if ($name !== '') {
            $this->names[] = $name;
        }
    }

    protected function date(?Carbon $at): string
    {
        return $at?->timezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i') ?? 'unknown date';
    }

    protected function excerpt(?string $text, int $max = 400): string
    {
        $text = trim((string) $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    protected function isAdministrator(): bool
    {
        return $this->user->isAdministrator();
    }

    protected function isTechnician(): bool
    {
        return $this->user->role?->slug === 'technician';
    }
}
