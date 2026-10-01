@extends('layouts.app')

@section('title', $ap['name'] . ($visibility === 'priv' ? ' — Dokumentace' : ''))

@section('content')
    <x-gallery.header :area="$area" :ap="$ap" :visibility="$visibility" mode="grid" />

    @if ($canManage)
        <x-gallery.dropzone :area="$area" :ap="$ap" :visibility="$visibility" :suggestion-count="$suggestionCount" />
    @endif

    @if (count($images) === 0)
        <p class="rounded-lg border border-gray-200 bg-white px-4 py-8 text-center text-sm text-gray-500">
            Zatím zde nejsou žádné obrázky.
        </p>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
            @foreach ($images as $image)
                <x-gallery.tile :image="$image" :can-manage="$canManage" />
            @endforeach
        </div>
    @endif
@endsection
