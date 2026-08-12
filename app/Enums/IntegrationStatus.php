<?php

namespace App\Enums;

enum IntegrationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Sent = 'sent';
    case Failed = 'failed';
}
