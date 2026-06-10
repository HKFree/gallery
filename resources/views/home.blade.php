@extends('layouts.app')

@section('title', 'Oblasti')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold tracking-tight">Oblasti</h1>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($tree as $area)
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                @if ($area['collapsed'])
                    <a href="{{ route('gallery.public', ['area' => $area['id'], 'ap' => $area['link_ap']['id']]) }}"
                       class="flex items-center gap-2 font-medium text-gray-900 hover:text-emerald-700">
                        <x-icon.folder class="h-5 w-5 text-amber-500" />
                        {{ $area['name'] }}
                    </a>
                @else
                    <p class="mb-2 flex items-center gap-2 font-medium text-gray-900">
                        <x-icon.folder class="h-5 w-5 text-amber-500" />
                        {{ $area['name'] }}
                    </p>
                    <ul class="space-y-1 border-l border-gray-100 pl-4">
                        @foreach ($area['aps'] as $ap)
                            <li>
                                <a href="{{ route('gallery.public', ['area' => $area['id'], 'ap' => $ap['id']]) }}"
                                   class="text-sm text-gray-600 hover:text-emerald-700">
                                    {{ $ap['name'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
@endsection
