<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
