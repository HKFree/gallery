<?php

use App\Services\ImageDate;
use Carbon\CarbonImmutable;

function imageDateFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'exif');
    file_put_contents($path, $contents);

    return $path;
}

it('reads DateTimeOriginal as gallery wall-clock time', function () {
    $date = app(ImageDate::class)->takenAt(imageDateFile(jpegWithExif('2024:05:17 10:20:30')));

    expect($date->format('Y-m-d H:i:s'))->toBe('2024-05-17 10:20:30')
        ->and($date->getTimezone()->getName())->toBe('Europe/Prague');
});

it('falls back to DateTimeDigitized when DateTimeOriginal is missing', function () {
    $date = app(ImageDate::class)->takenAt(imageDateFile(jpegWithExif(dateTimeDigitized: '2023:02:03 04:05:06')));

    expect($date->format('Y-m-d H:i:s'))->toBe('2023-02-03 04:05:06');
});

it('ignores the IFD0 DateTime edit time', function () {
    expect(app(ImageDate::class)->takenAt(imageDateFile(jpegWithExif(dateTime: '2024:05:17 10:20:30'))))->toBeNull();
});

it('rejects placeholder, overflowing and implausible dates', function (string $value) {
    expect(app(ImageDate::class)->takenAt(imageDateFile(jpegWithExif($value))))->toBeNull();
})->with([
    'placeholder' => '0000:00:00 00:00:00',
    'blank' => '                   ',
    'month overflow' => '2024:13:01 00:00:00',
    'unset camera clock' => '1970:01:01 00:00:00',
    'future' => CarbonImmutable::now()->addYear()->format('Y:m:d H:i:s'),
]);

it('falls back to DateTimeDigitized when DateTimeOriginal is implausible', function () {
    $date = app(ImageDate::class)->takenAt(imageDateFile(jpegWithExif('0000:00:00 00:00:00', '2022:08:09 10:11:12')));

    expect($date->format('Y-m-d'))->toBe('2022-08-09');
});

it('returns null for images without EXIF and for corrupt EXIF', function (string $contents) {
    expect(app(ImageDate::class)->takenAt(imageDateFile($contents)))->toBeNull();
})->with([
    'no exif' => fn () => jpegWithExif(),
    'corrupt APP1' => fn () => "\xFF\xD8\xFF\xE1".pack('n', 20)."Exif\0\0II*\0".str_repeat("\xFF", 10)."\xFF\xD9",
    'not an image' => 'plain text',
]);

it('converts source dates and file timestamps into gallery wall-clock time', function () {
    $dates = app(ImageDate::class);

    // 2024-01-31 23:30 UTC is already February in Prague.
    $timestamp = CarbonImmutable::parse('2024-01-31 23:30:00', 'UTC')->getTimestamp();

    expect($dates->fromTimestamp($timestamp)->format('Y-m-d H:i'))->toBe('2024-02-01 00:30')
        ->and($dates->fromSourceDate(CarbonImmutable::createFromTimestampMs($timestamp * 1000))->format('Y-m-d H:i'))->toBe('2024-02-01 00:30')
        ->and($dates->fromSourceDate(CarbonImmutable::parse('2011-05-25T21:49:54+02:00'))->format('Y-m-d H:i'))->toBe('2011-05-25 21:49')
        ->and($dates->fromSourceDate(null))->toBeNull()
        ->and($dates->fromSourceDate(CarbonImmutable::createFromTimestamp(0)))->toBeNull();
});
