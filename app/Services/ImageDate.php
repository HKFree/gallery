<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Determines the dates a gallery image is placed by on the timeline.
 *
 * Every date is returned as wall-clock time in the gallery timezone
 * (`services.gallery.timezone`): EXIF dates carry no zone and are taken as is, timestamps
 * (file mtime, source dates) are converted into it.
 */
class ImageDate
{
    /** EXIF tags holding the capture time, in order of preference (IFD0 `DateTime` is the edit time). */
    private const TAKEN_TAGS = ['DateTimeOriginal', 'DateTimeDigitized'];

    /** Dates before this are treated as an unset camera clock. */
    private const EARLIEST_PLAUSIBLE = '1990-01-01';

    /**
     * The capture time from EXIF, or null when missing, unreadable or implausible.
     *
     * EXIF is untrusted binary data; any read failure is treated as "no date".
     */
    public function takenAt(string $path): ?CarbonImmutable
    {
        try {
            $exif = @exif_read_data($path, 'EXIF');
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($exif)) {
            return null;
        }

        foreach (self::TAKEN_TAGS as $tag) {
            $date = is_string($exif[$tag] ?? null) ? $this->parseExifDate($exif[$tag]) : null;

            if ($date !== null && $this->isPlausible($date)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Parse an EXIF `YYYY:MM:DD HH:MM:SS` value strictly; overflowing values (month 13) and
     * placeholders (`0000:00:00 00:00:00`) do not round-trip and are rejected.
     */
    private function parseExifDate(string $value): ?CarbonImmutable
    {
        $value = trim($value, " \0");

        try {
            $date = CarbonImmutable::createFromFormat('Y:m:d H:i:s', $value, $this->timezone());
        } catch (\Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y:m:d H:i:s') === $value ? $date : null;
    }

    /**
     * A date reported by the image's source (the browser's `File.lastModified` on upload, the
     * attachment date on a Confluence import) as gallery wall-clock time, or null when absent
     * or implausible.
     */
    public function fromSourceDate(?CarbonInterface $date): ?CarbonImmutable
    {
        if ($date === null) {
            return null;
        }

        $date = CarbonImmutable::instance($date)->setTimezone($this->timezone());

        return $this->isPlausible($date) ? $date : null;
    }

    /**
     * A Unix timestamp (e.g. a file's mtime) as gallery wall-clock time.
     */
    public function fromTimestamp(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($timestamp, $this->timezone());
    }

    /**
     * Whether a date can be a real capture time: not before 1990 (unset camera clocks),
     * and not more than a day in the future (wrong clocks).
     */
    private function isPlausible(CarbonImmutable $date): bool
    {
        $earliest = CarbonImmutable::parse(self::EARLIEST_PLAUSIBLE, $this->timezone());
        $latest = CarbonImmutable::now($this->timezone())->addDay();

        return $date->between($earliest, $latest);
    }

    private function timezone(): string
    {
        return (string) config('services.gallery.timezone');
    }
}
