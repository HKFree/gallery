@extends('layouts.app')

@section('title', $ap['name'] . ($visibility === 'priv' ? ' — Dokumentace' : '') . ' — časová osa')

@section('content')
    <x-gallery.header :area="$area" :ap="$ap" :visibility="$visibility" mode="timeline" />

    @if ($coverage !== null && ($canManage || collect($coverage)->sum('count') > 0))
        <x-gallery.coverage :sectors="$coverage" />
    @endif

    @if ($canManage)
        <x-gallery.dropzone :area="$area" :ap="$ap" :visibility="$visibility" />
    @endif

    @if ($pending > 0)
        <p class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Probíhá indexace — {{ $pending }} obrázků se na časové ose objeví po obnovení stránky.
        </p>
    @endif

    <x-timeline.layout :months="$months" :sections="$sections" :next-url="$nextUrl" :newest-url="$newestUrl"
                       :url="$timelineUrl" :query="$timelineQuery" :can-manage="$canManage" />
@endsection
