<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Providers;

use App\Domains\KnowledgeBase\Listeners\AnalyzeTicketOnCreated;
use App\Domains\Tickets\Events\TicketCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The AI domain's event map, registered explicitly rather than discovered —
 * the same convention `NotificationServiceProvider` established (see that
 * class's own docblock for why: an explicit map is a readable statement of
 * which triggers exist, which type-hint discovery cannot give a reader).
 *
 * One entry today (WP-I). Later AI work packages (ticket status change for
 * FIXED/NOT-FIXED analytics, maintenance completion for learning events, …)
 * add lines here rather than a second provider.
 */
class AiServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const LISTENERS = [
        TicketCreated::class => AnalyzeTicketOnCreated::class,
    ];

    public function boot(): void
    {
        foreach (self::LISTENERS as $event => $listener) {
            Event::listen($event, $listener);
        }
    }
}
