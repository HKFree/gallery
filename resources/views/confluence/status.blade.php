@extends('layouts.app')

@section('title', 'Import z Confluence — '.$ap['name'])

@php
    $active = $import->status->isActive();
    $total = array_sum($counts);
    $handled = $total - $counts['pending'];
    $parameters = ['area' => $area['id'], 'ap' => $ap['id']];
    $prefix = $visibility === 'priv' ? 'gallery.private' : 'gallery.public';
@endphp

@if ($active)
    @push('head')
        <meta http-equiv="refresh" content="3">
    @endpush
@endif

@section('content')
    <nav class="mb-2 text-sm text-gray-500">
        <a href="{{ route('home') }}" class="hover:text-gray-700">Oblasti</a>
        <span class="px-1">/</span>
        <span>{{ $area['name'] }}</span>
        <span class="px-1">/</span>
        <a href="{{ route($prefix, $parameters) }}" class="hover:text-gray-700">
            {{ $ap['name'] }}{{ $visibility === 'priv' ? ' — Dokumentace' : '' }}
        </a>
    </nav>

    <h1 class="mb-6 text-2xl font-semibold tracking-tight">Import z Confluence</h1>

    <section class="rounded-lg border border-gray-200 bg-white p-4">
        <h2 class="mb-1 text-lg font-semibold">
            <a href="{{ $import->page_url }}" target="_blank" rel="noopener" class="hover:text-emerald-700">{{ $import->page_title }}</a>
        </h2>

        <p class="mb-4 text-sm text-gray-600" data-import-status="{{ $import->status->value }}">
            @switch($import->status)
                @case(\App\Enums\ImportStatus::Queued)
                    Čeká na spuštění (nejpozději do minuty)…
                    @break
                @case(\App\Enums\ImportStatus::Running)
                    Importuji {{ $handled }} / {{ $total }}…
                    @break
                @case(\App\Enums\ImportStatus::Done)
                    {{ $counts['failed'] > 0 ? 'Hotovo, s chybami.' : 'Hotovo.' }}
                    @break
                @case(\App\Enums\ImportStatus::Failed)
                    {{ $import->error }}
                    @break
            @endswitch
        </p>

        <div class="mb-4 h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $handled }}">
            <div class="h-full bg-emerald-600 transition-all" style="width: {{ $total > 0 ? round($handled / $total * 100) : 0 }}%"></div>
        </div>

        <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:max-w-sm">
            <dt class="text-gray-500">Naimportováno</dt>
            <dd class="font-medium">{{ $counts['imported'] }}</dd>
            <dt class="text-gray-500">Už v galerii</dt>
            <dd class="font-medium">{{ $counts['skipped'] }}</dd>
            <dt class="text-gray-500">Chyby</dt>
            <dd class="font-medium">{{ $counts['failed'] }}</dd>
        </dl>

        @if ($failures->isNotEmpty())
            <ul class="mt-4 space-y-0.5 text-sm text-red-700">
                @foreach ($failures as $failure)
                    <li><span class="font-mono text-xs">{{ $failure->original_filename }}</span> — {{ $failure->reason }}</li>
                @endforeach
            </ul>
        @endif

        @unless ($active)
            <p class="mt-6 flex flex-wrap gap-3 text-sm">
                <a href="{{ route($prefix.'.timeline', $parameters) }}" class="rounded-md bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">Zobrazit na časové ose</a>
                <a href="{{ route($prefix, $parameters) }}" class="rounded-md border border-gray-200 px-4 py-2 font-medium text-gray-700 hover:bg-gray-50">Zpět do galerie</a>
            </p>

            @if ($counts['imported'] > 0)
                <p class="mt-4 text-sm text-gray-600">
                    Volitelně:
                    <x-gallery.scene-tagging :area="$area" :ap="$ap" :visibility="$visibility" :import="$import->id" />
                </p>
            @endif
        @endunless
    </section>
@endsection
