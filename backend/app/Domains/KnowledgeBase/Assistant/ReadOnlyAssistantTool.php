<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant;

/**
 * Marker: a tool the assistant may hold because it only ever *reads*.
 *
 * The assistant is advisory (SRS FR-AI-032, SDD DD-75). Giving the model tools
 * does not change that as long as no tool can write — and this interface is
 * how that is stated in code rather than remembered. {@see AssistantToolbox}
 * registers nothing that does not implement it, and
 * `tests/Feature/AI/AssistantToolsTest.php` checks that no tool source touches a
 * write path.
 */
interface ReadOnlyAssistantTool {}
