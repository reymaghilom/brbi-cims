<?php

namespace App\Enums;

enum ClientFolderStatus: string
{
    case OnProgress = 'on_progress';
    case Completed = 'completed';
}
