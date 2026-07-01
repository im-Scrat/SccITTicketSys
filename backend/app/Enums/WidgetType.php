<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum WidgetType: string
{
    use HasValues;

    case Counter = 'counter';
    case LineChart = 'line_chart';
    case BarChart = 'bar_chart';
    case PieChart = 'pie_chart';
    case Table = 'table';
    case ListWidget = 'list';
    case Map = 'map';
    case Timeline = 'timeline';
    case Gauge = 'gauge';
}
