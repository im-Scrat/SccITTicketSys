<?php

use App\Domains\Administration\Providers\NotificationServiceProvider;
use App\Domains\KnowledgeBase\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    /*
     * WP-2.7a. Its own provider rather than more lines in AppServiceProvider:
     * it registers a framework channel driver, the FR-NOT-003 trigger map and
     * the notification policy, and keeping those three together means the
     * question "what is notified, and to whom" has one file to open.
     */
    NotificationServiceProvider::class,

    // WP-I. The AI domain's event map — see the provider's own docblock.
    AiServiceProvider::class,
];
