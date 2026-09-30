<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\Agents\AssistantAgent;
use App\Domains\KnowledgeBase\DTOs\KnowledgeMatch;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Enums\AiSender;
use App\Enums\KnowledgeStatus;
use App\Models\AiConversationLog;
use App\Models\AiKnowledgeArticle;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The authenticated troubleshooting assistant (WP-Q; SRS FR-AI-004/007/031/032;
 * SDD §22, §35.3, DD-75).
 *
 * One question in, one grounded answer out, with citations — and two audit rows
 * in `ai_conversation_logs`, one per turn, carrying token and latency metrics.
 *
 * ── Three boundaries, all enforced here in code rather than in the prompt ──
 *
 *  1. **Whose data.** Knowledge comes from `KnowledgeRetriever` — Source A, the
 *     published articles, which no ticket can be reached through (DD-71). The
 *     caller's own tickets come from {@see AssistantTicketLookup}, constrained
 *     by the same visibility rules as every list endpoint. Nothing else is read.
 *  2. **Which ticket.** A ticket may be attached for context, but the
 *     *controller* has already required `TicketPolicy::viewFull` for the caller.
 *  3. **Whose history.** A conversation is found by `(conversation_id, user_id)`;
 *     an id belonging to somebody else behaves exactly like an unused one.
 *
 * ── What it will not do ────────────────────────────────────────────────────
 *
 * It advises; it does not act (FR-AI-032). Nothing in this class calls a write
 * path on any ticket, asset or account. Every reply is flagged `ai_generated`
 * and `advisory` so the client labels it (FR-AI-031). Personal data is removed
 * from the prompt by the invoker on the way out (FR-AI-030).
 *
 * If retrieval fails the assistant still answers — from the question, the
 * attached ticket and its own tickets — and says when it has nothing to go on.
 */
class AssistantService
{
    /** Turns of history replayed to the model — enough to follow up, bounded for cost. */
    private const HISTORY_TURNS = 6;

    public function __construct(
        private readonly SafeAgentInvoker $invoker,
        private readonly AiSettings $settings,
        private readonly KnowledgeRetriever $retriever,
        private readonly AssistantTicketLookup $tickets,
    ) {}

    /**
     * @return array{conversation_id: string, answer: string, citations: list<array<string, mixed>>, ai_generated: bool, advisory: bool}
     *
     * @throws AiUnavailableException when the assistant is off, unconfigured or unavailable
     * @throws AiProviderException when the provider fails after the invoker's own retries
     */
    public function reply(User $user, string $message, ?string $conversationId = null, ?Ticket $attached = null): array
    {
        if (! $this->settings->assistantEnabled()) {
            throw AiUnavailableException::assistantDisabled();
        }

        $model = $this->settings->activeModel();
        $conversationId ??= (string) Str::uuid();

        $matches = $this->knowledge($message);
        $tickets = $this->tickets->relevantTo($user, $message);

        $isStaff = $user->isAdministrator() || $user->role?->slug === 'technician';

        $agent = new AssistantAgent(
            $isStaff
                ? 'The user is IT staff: you may suggest more technical checks, but still nothing destructive.'
                : 'The user is a teacher, not a technician: suggest only simple, safe steps. Never tell them to open the computer, change firmware settings, run commands or install software.',
        );

        $prompt = $this->prompt($message, $this->history($user, $conversationId), $matches, $tickets, $attached);

        $names = array_values(array_filter([$user->fullName(), $attached?->reporter?->fullName()]));
        $invocation = $this->invoker->invoke($agent, $prompt, $model, $names);

        $citations = $this->citations($matches, $tickets, $attached);
        $answer = trim($invocation->text);

        AiConversationLog::query()->create([
            'conversation_id' => $conversationId,
            'ticket_id' => $attached?->getKey(),
            'user_id' => $user->getKey(),
            'ai_model_id' => $model->getKey(),
            'sender' => AiSender::User->value,
            'message' => $message,
            'created_at' => now(),
        ]);

        AiConversationLog::query()->create([
            'conversation_id' => $conversationId,
            'ticket_id' => $attached?->getKey(),
            'user_id' => $user->getKey(),
            'ai_model_id' => $model->getKey(),
            'sender' => AiSender::Assistant->value,
            'message' => $answer,
            'prompt_tokens' => $invocation->promptTokens,
            'completion_tokens' => $invocation->completionTokens,
            'latency_ms' => $invocation->latencyMs,
            'metadata' => ['citations' => $citations],
            'created_at' => now()->addMillisecond(),
        ]);

        return [
            'conversation_id' => $conversationId,
            'answer' => $answer,
            'citations' => $citations,
            'ai_generated' => true,
            'advisory' => true,
        ];
    }

    /**
     * Published knowledge relevant to the question. A retrieval failure is not an
     * assistant failure: the answer is simply ungrounded, and the agent has been
     * told what to do when the material does not cover the question.
     *
     * @return list<KnowledgeMatch>
     */
    private function knowledge(string $message): array
    {
        try {
            return $this->retriever->search(
                $message,
                (int) config('ai.sccit.assistant_top_k', 4),
                (float) config('ai.sccit.assistant_min_similarity', 0.55),
            );
        } catch (AiUnavailableException|AiProviderException) {
            return [];
        }
    }

    /**
     * The previous turns of a conversation this user owns, oldest first, as a
     * plain transcript. An id belonging to somebody else yields an empty
     * transcript — indistinguishable from a new conversation.
     */
    private function history(User $user, string $conversationId): string
    {
        $rows = AiConversationLog::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->limit(self::HISTORY_TURNS)
            ->get()
            ->reverse();

        return $rows
            ->map(fn (AiConversationLog $row): string => ($row->sender === AiSender::Assistant ? 'Assistant: ' : 'User: ').$row->message)
            ->implode("\n");
    }

    /**
     * @param  list<KnowledgeMatch>  $matches
     * @param  iterable<Ticket>  $tickets
     */
    private function prompt(string $question, string $history, array $matches, iterable $tickets, ?Ticket $attached): string
    {
        $blocks = [];

        if ($attached !== null) {
            $attached->loadMissing(['status', 'category']);

            $blocks[] = PromptGuard::wrapUntrustedData('ticket the user is asking about', implode("\n", array_filter([
                "Reference: {$attached->ticket_number}",
                "Title: {$attached->title}",
                'Status: '.($attached->statusRow()->name ?? 'unknown'),
                $attached->category !== null ? "Category: {$attached->category->name}" : null,
                "Description: {$attached->description}",
                $attached->ai_summary !== null ? "Earlier AI summary: {$attached->ai_summary}" : null,
            ])));
        }

        $others = collect($tickets)->reject(fn (Ticket $t): bool => $attached !== null && $t->is($attached));

        if ($others->isNotEmpty()) {
            $blocks[] = PromptGuard::wrapUntrustedData('tickets the user may be referring to', $others
                ->map(fn (Ticket $t): string => sprintf('- %s (%s, %s): %s', $t->ticket_number, $t->statusRow()->name ?? 'unknown status', $t->created_at?->toDateString() ?? 'unknown date', $t->title))
                ->implode("\n"));
        }

        if ($matches !== []) {
            $articles = $this->articles($matches);

            $blocks[] = PromptGuard::wrapUntrustedData('reference material from the knowledge base', collect($matches)
                ->map(fn (KnowledgeMatch $match, int $index): string => sprintf(
                    "[%d] %s\n%s",
                    $index + 1,
                    $match->title,
                    // The retriever returns the *best-matching chunk* — usually
                    // the problem description, which is what a question looks
                    // like. An answer needs the solution beside it, so the whole
                    // article is offered, not just the chunk that was found.
                    $articles[$match->articleUuid] ?? $match->content,
                ))
                ->implode("\n\n"));
        } else {
            $blocks[] = 'No reference material matched this question.';
        }

        if ($history !== '') {
            $blocks[] = PromptGuard::wrapUntrustedData('earlier turns of this conversation', $history);
        }

        $blocks[] = PromptGuard::wrapUntrustedData('the user\'s question', $question);

        $prompt = implode("\n\n", $blocks);

        PromptGuard::assertWithinLimit($prompt);

        return $prompt;
    }

    /**
     * The full text of each matched article, keyed by uuid.
     *
     * Re-reads the articles with the `published` filter as a second line, so even
     * if retrieval ever returned a stale match an unpublished article could not
     * reach the prompt through here. Each field is bounded: one long article must
     * not crowd out the others or the user's question.
     *
     * @param  list<KnowledgeMatch>  $matches
     * @return array<string, string>
     */
    private function articles(array $matches): array
    {
        return AiKnowledgeArticle::query()
            ->where('status', KnowledgeStatus::Published->value)
            ->whereIn('uuid', array_map(fn (KnowledgeMatch $m): string => $m->articleUuid, $matches))
            ->get()
            ->mapWithKeys(fn (AiKnowledgeArticle $article): array => [$article->uuid => implode("\n", array_filter([
                $article->problem_signature !== null ? 'Problem: '.mb_substr($article->problem_signature, 0, 1200) : null,
                $article->root_cause !== null ? 'Cause: '.mb_substr($article->root_cause, 0, 1200) : null,
                $article->verified_solution !== null ? 'Verified solution: '.mb_substr($article->verified_solution, 0, 2400) : null,
            ]))])
            ->all();
    }

    /**
     * Citations in the order the model was shown them: knowledge first (numbered
     * as the prompt numbers it), then tickets. Only records that were actually in
     * the prompt, and — for tickets — only ones the lookup's visibility admitted.
     *
     * @param  list<KnowledgeMatch>  $matches
     * @param  iterable<Ticket>  $tickets
     * @return list<array<string, mixed>>
     */
    private function citations(array $matches, iterable $tickets, ?Ticket $attached): array
    {
        $out = [];
        $seen = [];

        foreach ($matches as $index => $match) {
            if (isset($seen['k:'.$match->articleUuid])) {
                continue;
            }

            $seen['k:'.$match->articleUuid] = true;
            $out[] = [
                'ref' => $index + 1,
                'type' => 'knowledge_article',
                'title' => $match->title,
                'label' => 'Knowledge article',
                'uuid' => $match->articleUuid,
                'url' => "/app/knowledge/{$match->articleUuid}",
            ];
        }

        $candidates = collect($tickets)->when($attached !== null, fn ($c) => $c->prepend($attached))->unique('id');

        foreach ($candidates as $ticket) {
            $out[] = [
                'ref' => null,
                'type' => 'ticket',
                'title' => $ticket->title,
                'label' => $ticket->ticket_number,
                'uuid' => $ticket->uuid,
                'url' => "/app/tickets/{$ticket->uuid}",
            ];
        }

        return $out;
    }
}
