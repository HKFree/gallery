@extends('layouts.app')

@section('title', 'Pokrytí výhledů')

@section('content')
    <h1 class="mb-2 text-2xl font-semibold tracking-tight">Pokrytí výhledů</h1>
    <p class="mb-6 max-w-3xl text-sm text-gray-600">
        Ze kterých směrů mají veřejné galerie AP fotky výhledů. Počítají se jen fotky se známým směrem;
        za aktuální se považují fotky mladší než {{ \App\Services\DirectionCoverage::FRESH_YEARS }} roky.
        Nahoře jsou AP, kterým chybí nejvíc směrů.
    </p>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-3 py-2 font-medium">AP</th>
                    <th class="px-3 py-2 font-medium">Směry</th>
                    <th class="px-3 py-2 font-medium">Pokryto</th>
                    <th class="px-3 py-2 font-medium">Chybí</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $row)
                    <tr data-coverage-row="{{ $row['ap']['id'] }}">
                        <td class="px-3 py-2">
                            <a href="{{ route('gallery.public', ['area' => $row['ap']['area']['id'], 'ap' => $row['ap']['id']]) }}"
                               class="font-medium text-gray-900 hover:text-emerald-700">{{ $row['ap']['name'] }}</a>
                            <span class="block text-xs text-gray-500">{{ $row['ap']['area']['name'] }}</span>
                        </td>
                        <td class="px-3 py-2"><x-gallery.compass-rose :sectors="$row['sectors']" :labels="false" size="h-8 w-8" /></td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row['covered'] }} / 8</td>
                        <td class="px-3 py-2 text-gray-600">
                            {{ collect($row['sectors'])->where('state', 'missing')->map(fn ($sector) => $sector['point'].($sector['neighbours'] ? ' → '.$sector['neighbours'][0] : ''))->implode(', ') ?: '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
