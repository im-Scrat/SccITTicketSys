<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Exceptions;

use RuntimeException;

/**
 * A report period or grouping that cannot be drawn as asked, carrying a message
 * written for the Administrator ("Group by day allows at most 92 days …").
 * Report requests turn it into a 422 on the offending parameter.
 */
final class ReportRangeException extends RuntimeException {}
