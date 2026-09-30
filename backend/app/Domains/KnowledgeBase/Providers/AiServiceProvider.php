<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Providers;

use App\Domains\KnowledgeBase\Listeners\AnalyzeTicketOnCreated;
use App\Domains\KnowledgeBase\Listeners\RecordMaintenanceLearningEvent;
use App\Domains\KnowledgeBase\Observers\AiKnowledgeArticleObserver;
use App\Domains\Maintenance\Events\MaintenanceCompleted;
use App\Domains\Tickets\Events\TicketCreated;
use App\Models\AiKnowledgeArticle;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The AI domain's event map, registered explicitly rather than discovered —
 * the same convention `NotificationServiceProvider` established (see that
 * class's own docblock for why: an explicit map is a readable statement of
 * which triggers exist, which type-hint discovery cannot give a reader).
 *
 * WP-I added ticket pre-screening; WP-L adds maintenance completion for the
 * learning loop; WP-P observes knowledge articles so the vector index follows
 * them. Later AI work packages add lines here rather than a second provider.
 */
class AiServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const LISTENERS = [
        TicketCreated::class => AnalyzeTicketOnCreated::class,
        MaintenanceCompleted::class => RecordMaintenanceLearningEvent::class,
    ];

    public function boot(): void
    {
        foreach (self::LISTENERS as $event => $listener) {
            Event::listen($event, $listener);
        }

        // WP-P: an article's index state follows every save, delete and restore.
        AiKnowledgeArticle::observe(AiKnowledgeArticleObserver::class);
    }
}
