@extends('layouts.app')

@section('title', 'Časová osa')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight">Časová osa</h1>

        @auth
            <a href="{{ $includePrivate ? route('timeline') : route('timeline', ['priv' => 1]) }}"
               @class([
                   'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium',
                   'border-gray-900 bg-gray-900 text-white' => $includePrivate,
                   'border-gray-200 bg-white text-gray-600 hover:bg-gray-100' => ! $includePrivate,
               ])>
                <x-icon.lock class="h-4 w-4" />
                {{ $includePrivate ? 'Včetně Dokumentace' : 'Zobrazit i Dokumentaci' }}
            </a>
        @endauth
    </div>

    <x-timeline.layout :months="$months" :sections="$sections" :next-url="$nextUrl" :newest-url="$newestUrl"
                       :url="$timelineUrl" :query="$timelineQuery" />
@endsection
