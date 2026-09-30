<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Controllers;

use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Http\Requests\AssistantMessageRequest;
use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Domains\KnowledgeBase\Services\AssistantService;
use App\Enums\AiSender;
use App\Http\Controllers\Controller;
use App\Models\AiConversationLog;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The assistant's HTTP surface (WP-Q; SRS FR-AI-004; SDD §35.3, DD-75).
 *
 * `can:ai.view` is the floor — all three roles hold it, so it is *not* what
 * separates them. What does is inside the service (whose tickets, which
 * knowledge) and here (every conversation is found by `(conversation_id,
 * user_id)`, never by id alone, so there is no route that reads or deletes
 * another person's history and no error that tells "not yours" from "does not
 * exist").
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantService $assistant,
        private readonly AiSettings $settings,
    ) {}

    /**
     * Is the assistant usable right now? Lets the page show the non-blocking
     * "unavailable" state up front instead of after a failed send (FR-AI-020).
     * The reason is deliberately coarse: why AI is off is an administrator's
     * concern and is on their screen, not a teacher's.
     */
    public function status(): JsonResponse
    {
        $available = false;

        if ($this->settings->assistantEnabled() && $this->settings->hasProviderKey('gemini')) {
            try {
                $this->settings->activeModel();
                $available = true;
            } catch (AiUnavailableException) {
                $available = false;
            }
        }

        return response()->json(['data' => ['available' => $available]]);
    }

    public function message(AssistantMessageRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ticket = null;

        if ($request->filled('ticket')) {
            $ticket = Ticket::query()->where('uuid', (string) $request->input('ticket'))->first();

            // Unknown and forbidden answer identically: the caller learns only
            // that they may not use that ticket, never whether it exists.
            abort_if($ticket === null || ! $user->can('viewFull', $ticket), 403, 'You cannot use that ticket with the assistant.');
        }

        try {
            $reply = $this->assistant->reply(
                $user,
                (string) $request->validated('message'),
                $request->validated('conversation_id'),
                $ticket,
            );
        } catch (AiUnavailableException|AiProviderException) {
            // Both carry only this codebase's static messages. The client gets
            // one sentence it can show, and the way forward that never depended
            // on AI: report the problem as a ticket.
            return response()->json([
                'message' => 'The AI assistant is not available right now. You can still report the problem as a ticket.',
            ], 503);
        }

        return response()->json(['data' => $reply]);
    }

    /** The caller's conversations, newest first, one row each. */
    public function conversations(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rows = DB::table('ai_conversation_logs')
            ->select('conversation_id', DB::raw('max(created_at) as last_at'), DB::raw('count(*) as turns'))
            ->where('user_id', $user->getKey())
            ->groupBy('conversation_id')
            ->orderByDesc('last_at')
            ->limit(30)
            ->get();

        $data = $rows->map(function (\stdClass $row) use ($user): array {
            $first = AiConversationLog::query()
                ->where('conversation_id', $row->conversation_id)
                ->where('user_id', $user->getKey())
                ->where('sender', AiSender::User->value)
                ->oldest('id')
                ->value('message');

            return [
                'id' => $row->conversation_id,
                'title' => mb_substr((string) $first, 0, 80),
                'turns' => (int) $row->turns,
                'last_at' => $row->last_at,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function conversation(Request $request, string $conversation): JsonResponse
    {
        abort_unless(Str::isUuid($conversation), 404);

        /** @var User $user */
        $user = $request->user();

        $messages = AiConversationLog::query()
            ->where('conversation_id', $conversation)
            ->where('user_id', $user->getKey())
            ->orderBy('id')
            ->get();

        abort_if($messages->isEmpty(), 404);

        return response()->json(['data' => [
            'id' => $conversation,
            'messages' => $messages->map(fn (AiConversationLog $row): array => [
                'sender' => $row->sender->value,
                'text' => $row->message,
                'citations' => $row->metadata['citations'] ?? [],
                'at' => $row->created_at?->toIso8601String(),
            ])->all(),
        ]]);
    }

    public function destroyConversation(Request $request, string $conversation): JsonResponse
    {
        abort_unless(Str::isUuid($conversation), 404);

        /** @var User $user */
        $user = $request->user();

        $deleted = AiConversationLog::query()
            ->where('conversation_id', $conversation)
            ->where('user_id', $user->getKey())
            ->delete();

        abort_if($deleted === 0, 404);

        return response()->json(['message' => 'Conversation deleted.']);
    }
}
