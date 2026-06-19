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
 * "Brno" (two APs → group).
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
                '101' => ['id' => 101, 'jmeno' => 'Slatina', 'aktivni' => 1],
            ],
            'admins' => [],
        ],
        '13' => [
            'id' => 13,
            'jmeno' => 'Brno',
            'aps' => [
                '201' => ['id' => 201, 'jmeno' => 'Brno', 'aktivni' => 1],
                '202' => ['id' => 202, 'jmeno' => 'Brno-Sever', 'aktivni' => 1],
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
