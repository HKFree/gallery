<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * Whether the import is still waiting for or doing work.
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
