<?php

namespace App\Enums;

/**
 * Scene types recognised in the browser (CLIP zero-shot) for gallery photos; the browser sends
 * the key, the label is always this fixed Czech text (nothing is machine-translated).
 */
enum Scene: string
{
    case Zastavba = 'zastavba';
    case Sidliste = 'sidliste';
    case Krajina = 'krajina';
    case Les = 'les';
    case Anteny = 'anteny';
    case Technika = 'technika';
    case Strecha = 'strecha';

    /** Scores below this are too unreliable to show. */
    public const MIN_SCORE = 0.6;

    public function label(): string
    {
        return match ($this) {
            self::Zastavba => 'Výhled na zástavbu',
            self::Sidliste => 'Výhled na sídliště',
            self::Krajina => 'Výhled do krajiny',
            self::Les => 'Výhled na les',
            self::Anteny => 'Antény na stožáru',
            self::Technika => 'Rozvaděč / technika',
            self::Strecha => 'Střecha',
        };
    }
}
