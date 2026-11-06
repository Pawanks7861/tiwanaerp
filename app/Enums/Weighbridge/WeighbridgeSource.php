<?php

namespace App\Enums\Weighbridge;

enum WeighbridgeSource: string
{
    case Measured = 'measured';
    case Manual = 'manual';
}
