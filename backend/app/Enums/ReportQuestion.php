<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

/**
 * The five questions an AI interpretation of the overall report may answer
 * (Phase 2.10 R5, SDD DD-95). Each has its own facts and its own sufficiency
 * gate; a question below its gate is stated as "not enough data" by the
 * application and never sent to the model.
 */
enum ReportQuestion: string
{
    use HasValues;

    case ReplacementDrivers = 'q1';
    case ProblemLocations = 'q2';
    case FailingComponents = 'q3';
    case PerformanceIssues = 'q4';
    case PreventiveCandidates = 'q5';

    public function label(): string
    {
        return match ($this) {
            self::ReplacementDrivers => 'Which PC units needed the most part replacements, and why?',
            self::ProblemLocations => 'Where do PC problems cluster?',
            self::FailingComponents => 'Which components fail most often?',
            self::PerformanceIssues => 'Are performance problems recurring?',
            self::PreventiveCandidates => 'Which PC units should get preventive care first?',
        };
    }
}
