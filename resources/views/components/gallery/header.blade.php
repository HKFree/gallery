@props(['area', 'ap', 'visibility', 'mode' => 'grid'])

@php
    $routes = $mode === 'timeline'
        ? ['pub' => 'gallery.public.timeline', 'priv' => 'gallery.private.timeline']
        : ['pub' => 'gallery.public', 'priv' => 'gallery.private'];
    $parameters = ['area' => $area['id'], 'ap' => $ap['id']];
    $prefix = $visibility === 'priv' ? 'gallery.private' : 'gallery.public';
@endphp

<nav class="mb-2 text-sm text-gray-500">
    <a href="{{ route('home') }}" class="hover:text-gray-700">Oblasti</a>
    <span class="px-1">/</span>
    <span>{{ $area['name'] }}</span>
    @if ($visibility === 'priv')
        <span class="px-1">/</span>
        <a href="{{ route($routes['pub'], $parameters) }}" class="hover:text-gray-700">{{ $ap['name'] }}</a>
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

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    @if ($visibility === 'pub')
        <a href="{{ route($routes['priv'], $parameters) }}"
           class="inline-flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 hover:border-gray-300 hover:shadow-sm">
            <x-icon.folder class="h-6 w-6 text-amber-500" />
            <span class="font-medium text-gray-900">Dokumentace</span>
            <x-icon.lock class="h-4 w-4 text-gray-400" />
        </a>
    @endif

    <x-view-toggle class="ms-auto" :mode="$mode" :grid-url="route($prefix, $parameters)" :timeline-url="route($prefix.'.timeline', $parameters)" />
</div>
