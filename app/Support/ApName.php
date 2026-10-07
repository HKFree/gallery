<?php

namespace App\Support;

class ApName
{
    /**
     * An AP's name as used in sentences: "AP Brno", but "Plotiště AP" as is.
     */
    public static function label(string $name): string
    {
        return preg_match('/\bAP\b/u', $name) === 1 ? $name : "AP {$name}";
    }
}
