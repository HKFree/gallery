<?php

namespace App\Support;

/**
 * URLs of a gallery image (original, thumbnail, delete action), shared by the grid and timelines.
 */
class GalleryLinks
{
    /**
     * @return array{name: string, url: string, thumb_url: string, delete_url: string}
     */
    public static function image(string $visibility, int $areaId, int $apId, string $name): array
    {
        $route = $visibility === 'priv' ? 'private' : 'public';
        $parameters = ['area' => $areaId, 'ap' => $apId, 'filename' => $name];

        return [
            'name' => $name,
            'url' => route("gallery.{$route}.image", $parameters),
            'thumb_url' => route("gallery.{$route}.thumb", $parameters),
            'delete_url' => route('gallery.destroy', ['visibility' => $visibility, ...$parameters]),
        ];
    }
}
