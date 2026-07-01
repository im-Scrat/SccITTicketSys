<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Concerns\HasValues;

enum ComponentType: string
{
    use HasValues;

    case Cpu = 'cpu';
    case Motherboard = 'motherboard';
    case Ram = 'ram';
    case Gpu = 'gpu';
    case Storage = 'storage';
    case PowerSupply = 'power_supply';
    case Monitor = 'monitor';
    case Keyboard = 'keyboard';
    case Mouse = 'mouse';
    case NetworkAdapter = 'network_adapter';
    case Cooler = 'cooler';
    case Chassis = 'chassis';
    case Peripheral = 'peripheral';
    case Other = 'other';
}
