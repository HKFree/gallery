@extends('layouts.app')

@section('title', 'Import z Confluence — '.$ap['name'])

@section('content')
    @php
        $galleryRoute = $visibility === 'priv' ? 'gallery.private' : 'gallery.public';
        $parameters = ['area' => $area['id'], 'ap' => $ap['id']];
    @endphp

    <nav class="mb-2 text-sm text-gray-500">
        <a href="{{ route('home') }}" class="hover:text-gray-700">Oblasti</a>
        <span class="px-1">/</span>
        <span>{{ $area['name'] }}</span>
        <span class="px-1">/</span>
        <a href="{{ route($galleryRoute, $parameters) }}" class="hover:text-gray-700">
            {{ $ap['name'] }}{{ $visibility === 'priv' ? ' — Dokumentace' : '' }}
        </a>
    </nav>

    <h1 class="mb-6 text-2xl font-semibold tracking-tight">Import z Confluence</h1>

    <form method="GET" action="{{ route('confluence.import.create', ['visibility' => $visibility, ...$parameters]) }}"
          class="mb-6 rounded-lg border border-gray-200 bg-white p-4">
        <label for="confluence-url" class="mb-2 block text-sm font-medium text-gray-700">Adresa stránky s fotkami</label>
        <div class="flex flex-col gap-2 sm:flex-row">
            <input id="confluence-url" type="url" name="url" value="{{ $url }}" required
                   placeholder="https://doc.hkfree.org/spaces/fotogalerie/pages/…"
                   class="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none">
            <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Načíst</button>
        </div>
        <p class="mt-2 text-xs text-gray-500">
            Fotky se naimportují do galerie <strong>{{ $ap['name'] }}{{ $visibility === 'priv' ? ' — Dokumentace' : '' }}</strong>.
        </p>
    </form>

    @foreach (array_filter([$error, session('error')]) as $message)
        <p class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</p>
    @endforeach

    @if ($preview)
        @php([$page, $analysis] = [$preview['page'], $preview['analysis']])

        <section class="rounded-lg border border-gray-200 bg-white p-4">
            <h2 class="text-lg font-semibold">
                <a href="{{ $page->url }}" target="_blank" rel="noopener" class="hover:text-emerald-700">{{ $page->title }}</a>
            </h2>
            <p class="mb-4 text-sm text-gray-500">
                {{ $page->spaceName }}@foreach ($page->ancestors as $ancestor) › {{ $ancestor }}@endforeach
            </p>

            @unless ($preview['matchesAp'])
                <p class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    Název stránky ani nadřazených stránek neodpovídá AP {{ $ap['name'] }}. Zkontrolujte, že importujete do správné galerie.
                </p>
            @endunless

            @if ($analysis->photos === [])
                <p class="text-sm text-gray-700">Tato stránka neobsahuje žádné fotky k importu.</p>

                @if ($preview['children'] !== [])
                    <p class="mt-4 mb-2 text-sm font-medium text-gray-700">Podstránky, které můžete importovat jednotlivě:</p>
                    <ul class="space-y-1 text-sm">
                        @foreach ($preview['children'] as $child)
                            <li>
                                <a href="{{ route('confluence.import.create', ['visibility' => $visibility, ...$parameters, 'url' => $child['url']]) }}"
                                   class="text-emerald-700 hover:text-emerald-800">{{ $child['title'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <dl class="mb-4 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:max-w-sm">
                    <dt class="text-gray-500">Fotek</dt>
                    <dd class="font-medium">{{ count($analysis->photos) }}</dd>
                    <dt class="text-gray-500">Celková velikost</dt>
                    <dd class="font-medium">{{ \Illuminate\Support\Number::withLocale('cs', fn () => \Illuminate\Support\Number::fileSize($analysis->totalSize(), precision: 1)) }}</dd>
                    @if ($preview['alreadyImported'] > 0)
                        <dt class="text-gray-500">Už naimportováno</dt>
                        <dd class="font-medium">{{ $preview['alreadyImported'] }}</dd>
                    @endif
                </dl>

                @if ($preview['newPhotos'] > 0)
                    <form method="POST" action="{{ route('confluence.import.store', ['visibility' => $visibility, ...$parameters]) }}">
                        @csrf
                        <input type="hidden" name="url" value="{{ $url }}">
                        <button type="submit" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
                            Importovat {{ $preview['newPhotos'] }} {{ $preview['newPhotos'] === 1 ? 'fotku' : ($preview['newPhotos'] < 5 ? 'fotky' : 'fotek') }}
                        </button>
                    </form>
                @else
                    <p class="text-sm text-gray-700">Všechny fotky z této stránky už v galerii jsou.</p>
                @endif
            @endif

            @if ($analysis->skipped !== [])
                <details class="mt-4 text-sm">
                    <summary class="cursor-pointer text-gray-600">Přeskočeno: {{ count($analysis->skipped) }}</summary>
                    <ul class="mt-2 space-y-0.5 text-gray-600">
                        @foreach ($analysis->skipped as $item)
                            <li><span class="font-mono text-xs">{{ $item['name'] }}</span> — {{ $item['reason'] }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>
    @endif
@endsection
