<?php

namespace App\Enums;

enum ImportItemStatus: string
{
    case Pending = 'pending';
    case Imported = 'imported';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
