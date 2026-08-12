<?php

namespace App\Enums;

enum RecordState: string
{
    case Draft = 'draft';
    case Complete = 'complete';
}
