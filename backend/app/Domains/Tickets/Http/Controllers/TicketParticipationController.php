<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Controllers;

use App\Domains\Tickets\Actions\AttachTicketFile;
use App\Domains\Tickets\Actions\ManageTicketComment;
use App\Domains\Tickets\Actions\ToggleTicketVote;
use App\Domains\Tickets\Http\Requests\StoreTicketAttachmentRequest;
use App\Domains\Tickets\Http\Requests\StoreTicketCommentRequest;
use App\Domains\Tickets\Http\Resources\TicketAttachmentResource;
use App\Domains\Tickets\Http\Resources\TicketCommentResource;
use App\Domains\Tickets\Services\TicketVisibility;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Participation in a ticket: comments, votes and attachments
 * (SRS FR-TKT-007/008/009/010).
 *
 * The security-critical line here is in {@see comments()}: internal notes are
 * excluded **by the query**, not by the resource. Filtering them out on the way
 * to the client would mean an internal note had already been loaded into a
 * requester's response object, one refactor away from being serialized.
 */
class TicketParticipationController extends Controller
{
    public function __construct(private readonly TicketVisibility $visibility) {}

    /* ---------------------------------------------------------- comments */

    public function comments(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();

        $comments = $ticket->comments()
            ->with(['user.role', 'editedBy', 'deletedBy'])
            ->withTrashed()
            // Internal notes never enter a requester's result set at all.
            ->when(
                ! $this->visibility->canSeeInternal($user, $ticket),
                fn ($query) => $query->where('is_internal', false),
            )
            // Oldest first: a conversation reads forward.
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(25);

        return TicketCommentResource::collection($comments);
    }

    public function storeComment(
        StoreTicketCommentRequest $request,
        Ticket $ticket,
        ManageTicketComment $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $comment = $action->create(
            $ticket,
            (string) $request->validated('body'),
            $request->isInternal(),
            $actor,
            $request,
            $request->parentComment(),
        );

        return (new TicketCommentResource($comment))
            ->additional(['message' => $request->isInternal() ? 'Internal note added.' : 'Comment posted.'])
            ->response()
            ->setStatusCode(201);
    }

    public function updateComment(
        Request $request,
        TicketComment $comment,
        ManageTicketComment $action,
    ): JsonResponse {
        $this->authorize('update', $comment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $updated = $action->update($comment, (string) $validated['body'], $actor, $request);

        return (new TicketCommentResource($updated))
            ->additional(['message' => 'Comment updated.'])
            ->response();
    }

    public function destroyComment(
        Request $request,
        TicketComment $comment,
        ManageTicketComment $action,
    ): JsonResponse {
        $this->authorize('delete', $comment);

        /** @var User $actor */
        $actor = $request->user();

        $action->delete($comment, $actor, $request);

        return response()->json(['message' => 'Comment removed.']);
    }

    /* ------------------------------------------------------------- votes */

    /**
     * Toggle this caller's upvote (FR-TKT-009).
     *
     * Idempotent by construction — `ToggleTicketVote` treats a lost race as the
     * outcome the user wanted — so a double click or a second tab is not an
     * error the client has to handle.
     */
    public function vote(Request $request, Ticket $ticket, ToggleTicketVote $action): JsonResponse
    {
        $this->authorize('vote', $ticket);

        /** @var User $user */
        $user = $request->user();

        $result = $action->handle($ticket, $user);

        return response()->json([
            'data' => $result,
            'message' => $result['voted'] ? 'Upvoted.' : 'Upvote removed.',
        ]);
    }

    /* ------------------------------------------------------- attachments */

    public function attachments(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        // Attachments are evidence, so they follow the *full* projection: a
        // community reader sees that a ticket exists, not what was photographed.
        $this->authorize('viewAttachments', $ticket);

        return TicketAttachmentResource::collection(
            $ticket->attachments()->with('uploadedBy')->latest('created_at')->get()
        );
    }

    public function storeAttachment(
        StoreTicketAttachmentRequest $request,
        Ticket $ticket,
        AttachTicketFile $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $attachment = $action->handle($ticket, $request->file('file'), $actor, $request);

        return (new TicketAttachmentResource($attachment))
            ->additional(['message' => 'Attachment uploaded.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Stream an attachment.
     *
     * Authorization is resolved from the attachment's **owning ticket**, so a
     * uuid guessed from anywhere still fails the visibility check — the file
     * being on a private disk is what makes this the only way in.
     */
    public function downloadAttachment(Request $request, Attachment $attachment): StreamedResponse
    {
        $ticket = $attachment->ticket;

        if ($ticket === null) {
            abort(404);
        }

        $this->authorize('viewAttachments', $ticket);

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->storage_path)) {
            abort(404);
        }

        return $disk->response(
            $attachment->storage_path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream'],
        );
    }

    public function destroyAttachment(
        Request $request,
        Attachment $attachment,
        AttachTicketFile $action,
    ): JsonResponse {
        $ticket = $attachment->ticket;

        if ($ticket === null) {
            abort(404);
        }

        $this->authorize('manageAttachments', $ticket);

        /** @var User $actor */
        $actor = $request->user();

        $action->detach($ticket, $attachment, $actor, $request);

        return response()->json(['message' => 'Attachment removed.']);
    }
}
