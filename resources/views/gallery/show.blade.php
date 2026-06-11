@extends('layouts.app')

@section('title', $ap['name'] . ($visibility === 'priv' ? ' — Dokumentace' : ''))

@section('content')
    <nav class="mb-2 text-sm text-gray-500">
        <a href="{{ route('home') }}" class="hover:text-gray-700">Oblasti</a>
        <span class="px-1">/</span>
        <span>{{ $area['name'] }}</span>
        @if ($visibility === 'priv')
            <span class="px-1">/</span>
            <a href="{{ route('gallery.public', ['area' => $area['id'], 'ap' => $ap['id']]) }}" class="hover:text-gray-700">{{ $ap['name'] }}</a>
            <span class="px-1">/</span>
            <span>Dokumentace</span>
        @endif
    </nav>

    <h1 class="mb-6 flex items-center gap-2 text-2xl font-semibold tracking-tight">
        @if ($visibility === 'priv')
            <x-icon.lock class="h-6 w-6 text-gray-400" />
        @endif
        {{ $ap['name'] }}
        @if ($visibility === 'priv')
            <span class="text-gray-400">— Dokumentace</span>
        @endif
    </h1>

    @if ($visibility === 'pub')
        <a href="{{ route('gallery.private', ['area' => $area['id'], 'ap' => $ap['id']]) }}"
           class="mb-6 inline-flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 hover:border-gray-300 hover:shadow-sm">
            <x-icon.folder class="h-6 w-6 text-amber-500" />
            <span class="font-medium text-gray-900">Dokumentace</span>
            <x-icon.lock class="h-4 w-4 text-gray-400" />
        </a>
    @endif

    @if ($canManage)
        <div data-dropzone
             data-upload-url="{{ route('gallery.upload', ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']]) }}"
             class="mb-6 cursor-pointer rounded-lg border-2 border-dashed border-gray-300 bg-white px-6 py-8 text-center transition hover:border-emerald-400 hover:bg-emerald-50/40">
            <input type="file" accept="image/*" multiple hidden data-dropzone-input>
            <p class="text-sm text-gray-600">
                Přetáhněte sem obrázky nebo <span class="font-medium text-emerald-700">klikněte pro výběr</span>
            </p>
            <div class="mt-2 flex items-center justify-center gap-2">
                <svg data-dropzone-spinner class="hidden h-4 w-4 animate-spin text-emerald-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p data-dropzone-status class="text-xs text-gray-400"></p>
            </div>
        </div>
    @endif

    @if (count($images) === 0)
        <p class="rounded-lg border border-gray-200 bg-white px-4 py-8 text-center text-sm text-gray-500">
            Zatím zde nejsou žádné obrázky.
        </p>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
            @foreach ($images as $image)
                <figure data-image class="group relative overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <a href="{{ $image['url'] }}" target="_blank" rel="noopener">
                        <img src="{{ $image['thumb_url'] }}" alt="{{ $image['name'] }}" loading="lazy"
                             class="aspect-square w-full object-cover">
                    </a>
                    @if ($canManage)
                        <button type="button" data-delete-url="{{ $image['delete_url'] }}"
                                title="Přesunout do koše"
                                class="absolute right-1.5 top-1.5 hidden rounded-md bg-white/90 p-1.5 text-red-600 shadow hover:bg-white group-hover:block">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-7 0v12a1 1 0 001 1h6a1 1 0 001-1V7" />
                            </svg>
                        </button>
                    @endif
                    <figcaption class="truncate px-2 py-1 text-xs text-gray-500" title="{{ $image['name'] }}">{{ $image['name'] }}</figcaption>
                </figure>
            @endforeach
        </div>
    @endif
@endsection
