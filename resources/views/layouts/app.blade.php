<!DOCTYPE html>
<html lang="cs" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name')) — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-gray-50 text-gray-900 antialiased">
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="{{ route('home') }}" class="text-lg font-semibold tracking-tight">
                {{ config('app.name') }}
            </a>

            <nav class="flex items-center gap-3 text-sm">
                @auth
                    <span class="text-gray-600">{{ auth()->user()->name }}</span>
                    @can('manage-gallery')
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">správce</span>
                    @endcan
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="cursor-pointer rounded-md px-3 py-1.5 font-medium text-gray-600 hover:bg-gray-100">
                            Odhlásit
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="rounded-md bg-gray-900 px-3 py-1.5 font-medium text-white hover:bg-gray-700">
                        Přihlásit
                    </a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        @if (session('error'))
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
