<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Fake the Userdb /areas endpoint with a default (or custom) fixture.
 *
 * Default fixture: area "Slatina" (single same-named AP → collapsed) and area
 * "Brno" (two APs → group). Brno-Sever lies 2.2 km due north of Brno; Slatina 6.6 km
 * east-south-east of it.
 *
 * @param  array<string, mixed>|null  $areas
 * @return array<string, mixed>
 */
function fakeUserdbAreas(?array $areas = null): array
{
    $areas ??= [
        '12' => [
            'id' => 12,
            'jmeno' => 'Slatina',
            'aps' => [
                '101' => ['id' => 101, 'jmeno' => 'Slatina', 'aktivni' => 1, 'gps' => '49.175000,16.700000'],
            ],
            'admins' => [],
        ],
        '13' => [
            'id' => 13,
            'jmeno' => 'Brno',
            'aps' => [
                '201' => ['id' => 201, 'jmeno' => 'Brno', 'aktivni' => 1, 'gps' => '49.195000,16.610000'],
                '202' => ['id' => 202, 'jmeno' => 'Brno-Sever', 'aktivni' => 1, 'gps' => '49.215000,16.610000'],
            ],
            'admins' => [],
        ],
    ];

    Http::fake([
        '*userdb*' => Http::response($areas),
    ]);

    return $areas;
}

/**
 * POST $contents to the chunked gallery upload endpoint as a sequence of chunks under one
 * upload id, returning the final chunk's response. The current test must already be
 * authenticated (via {@see TestCase::actingAs()}).
 */
function uploadGalleryChunks(string $visibility, int $area, int $ap, string $filename, string $contents, int $chunkSize = 1048576): TestResponse
{
    $uploadId = (string) Str::uuid();
    $chunks = str_split($contents, $chunkSize);
    $total = count($chunks);
    $response = null;

    foreach ($chunks as $index => $piece) {
        $response = test()->post(
            route('gallery.upload', ['visibility' => $visibility, 'area' => $area, 'ap' => $ap]),
            [
                'upload_id' => $uploadId,
                'chunk_index' => $index,
                'total_chunks' => $total,
                'filename' => $filename,
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $piece),
            ],
            ['Accept' => 'application/json'],
        );
    }

    return $response;
}

/**
 * A small JPEG carrying a hand-built EXIF block with the given date tags
 * (format `YYYY:MM:DD HH:MM:SS`), for testing EXIF date extraction.
 */
function jpegWithExif(?string $dateTimeOriginal = null, ?string $dateTimeDigitized = null, ?string $dateTime = null): string
{
    // IFD0 holds DateTime (0x0132) and the pointer to the Exif IFD (0x8769); the Exif IFD
    // holds DateTimeOriginal (0x9003) and DateTimeDigitized (0x9004). Tags sorted ascending.
    $ifd0 = array_filter([0x0132 => $dateTime]);
    $exif = array_filter([0x9003 => $dateTimeOriginal, 0x9004 => $dateTimeDigitized]);

    if ($exif !== []) {
        $ifd0[0x8769] = true;
    }

    ksort($ifd0);

    $exifOffset = 8 + 2 + 12 * count($ifd0) + 4;
    $dataOffset = $exifOffset + ($exif === [] ? 0 : 2 + 12 * count($exif) + 4);
    $data = '';

    $directory = function (array $tags) use (&$data, $exifOffset, $dataOffset): string {
        $entries = pack('v', count($tags));

        foreach ($tags as $tag => $value) {
            if ($value === true) {
                $entries .= pack('vvVV', $tag, 4, 1, $exifOffset);

                continue;
            }

            $value .= "\0";
            $entries .= pack('vvVV', $tag, 2, strlen($value), $dataOffset + strlen($data));
            $data .= $value;
        }

        return $entries.pack('V', 0);
    };

    $tiff = "II*\0".pack('V', 8).$directory($ifd0);
    $tiff .= $exif === [] ? '' : $directory($exif);
    $app1 = "Exif\0\0".$tiff.$data;

    $image = UploadedFile::fake()->image('exif.jpg', 8, 8);
    $jpeg = file_get_contents($image->getRealPath());

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

/**
 * Fake the Confluence REST API for one page: its content (body), attachments and child pages.
 * Defaults to the recorded page 22020482 ("Foto výhled": a gallery macro, 11 JPEGs).
 *
 * @param  list<array<string, mixed>>|null  $attachments  attachment API results
 * @param  list<array{id: int, title: string}>  $children
 */
function fakeConfluencePage(int $id = 22020482, ?string $body = null, ?array $attachments = null, array $children = [], string $title = 'Foto výhled', array $ancestors = ['Home', 'Benesovka']): void
{
    $page = json_decode(file_get_contents(base_path('tests/Fixtures/confluence/page-22020482.json')), true);
    $page['id'] = (string) $id;
    $page['title'] = $title;
    $page['ancestors'] = array_map(fn (string $ancestor, int $index): array => ['id' => (string) $index, 'title' => $ancestor], $ancestors, array_keys($ancestors));

    if ($body !== null) {
        $page['body']['storage']['value'] = $body;
    }

    $attachments ??= json_decode(file_get_contents(base_path('tests/Fixtures/confluence/attachments-22020482.json')), true)['results'];

    Http::fake([
        "doc.hkfree.org/rest/api/content/{$id}/child/attachment*" => Http::response(['results' => $attachments, 'size' => count($attachments)]),
        "doc.hkfree.org/rest/api/content/{$id}/child/page*" => Http::response(['results' => array_map(fn (array $child): array => [
            'id' => (string) $child['id'], 'title' => $child['title'], '_links' => ['webui' => "/pages/viewpage.action?pageId={$child['id']}"],
        ], $children)]),
        "doc.hkfree.org/rest/api/content/{$id}*" => Http::response($page),
        'doc.hkfree.org/*' => Http::response([], 404),
    ]);
}

/**
 * One attachment as the Confluence API returns it.
 *
 * @return array<string, mixed>
 */
function confluenceAttachment(string $filename, string $mediaType = 'image/jpeg', int $size = 1000, string $created = '2020-01-01T10:00:00.000+01:00', int $id = 0): array
{
    $id = $id ?: crc32($filename);

    return [
        'id' => (string) $id,
        'type' => 'attachment',
        'title' => $filename,
        'version' => ['number' => 1, 'when' => $created],
        'metadata' => ['mediaType' => $mediaType],
        'extensions' => ['mediaType' => $mediaType, 'fileSize' => $size],
        '_links' => ['download' => '/download/attachments/1/'.rawurlencode($filename).'?version=1&api=v2'],
    ];
}

/**
 * A small JPEG with an EXIF GPS block: position and/or compass heading, as phones record them.
 */
function jpegWithGps(?float $lat = null, ?float $lon = null, ?float $heading = null): string
{
    $rational = fn (float $value): string => pack('VV', (int) round($value * 10000), 10000);
    $dms = fn (float $value): string => $rational(floor($value))
        .$rational(floor(($value - floor($value)) * 60))
        .$rational(($value * 60 - floor($value * 60)) * 60);

    // tag => [type (2 ASCII, 5 RATIONAL), count, value bytes]
    $entries = [];

    if ($lat !== null && $lon !== null) {
        $entries[1] = [2, 2, $lat >= 0 ? "N\0" : "S\0"];
        $entries[2] = [5, 3, $dms(abs($lat))];
        $entries[3] = [2, 2, $lon >= 0 ? "E\0" : "W\0"];
        $entries[4] = [5, 3, $dms(abs($lon))];
    }

    if ($heading !== null) {
        $entries[16] = [2, 2, "T\0"];
        $entries[17] = [5, 1, $rational($heading)];
    }

    $gpsOffset = 8 + 2 + 12 + 4;
    $dataOffset = $gpsOffset + 2 + 12 * count($entries) + 4;
    $directory = pack('v', count($entries));
    $data = '';

    foreach ($entries as $tag => [$type, $count, $value]) {
        if (strlen($value) <= 4) {
            $directory .= pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");
        } else {
            $directory .= pack('vvVV', $tag, $type, $count, $dataOffset + strlen($data));
            $data .= $value;
        }
    }

    $tiff = "II*\0".pack('V', 8)
        .pack('v', 1).pack('vvVV', 0x8825, 4, 1, $gpsOffset).pack('V', 0)
        .$directory.pack('V', 0).$data;
    $app1 = "Exif\0\0".$tiff;

    $image = UploadedFile::fake()->image('gps.jpg', 8, 8);
    $jpeg = file_get_contents($image->getRealPath());

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}
