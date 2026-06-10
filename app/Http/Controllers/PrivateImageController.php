<?php

namespace App\Http\Controllers;

use App\Services\GalleryStorage;
use App\Services\UserdbService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateImageController extends Controller
{
    public function __construct(
        private readonly UserdbService $userdb,
        private readonly GalleryStorage $storage,
    ) {}

    public function show(int $area, int $ap, string $filename): StreamedResponse
    {
        return $this->stream($area, $ap, $filename, thumb: false);
    }

    public function thumb(int $area, int $ap, string $filename): StreamedResponse
    {
        return $this->stream($area, $ap, $filename, thumb: true);
    }

    private function stream(int $areaId, int $apId, string $filename, bool $thumb): StreamedResponse
    {
        abort_if($this->userdb->findAp($areaId, $apId) === null, 404);

        $filename = basename($filename);

        abort_if($this->storage->isTrashed($filename), 404);

        $disk = Storage::disk($this->storage->diskName('priv'));
        $path = $this->storage->path('priv', $areaId, $apId, $filename, $thumb);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}
