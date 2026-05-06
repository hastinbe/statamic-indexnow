@php use function Statamic\trans as __; @endphp

@extends('statamic::layout')
@section('title', __('IndexNow'))

@section('content')

    <header class="mb-6">
        @includeIf('statamic::partials.breadcrumb', [
            'url' => cp_route('utilities.index'),
            'title' => __('Utilities')
        ])
        <div class="flex items-center justify-between">
            <h1>{{ __('IndexNow') }}</h1>
            <div class="flex items-center gap-4">
                @if ($config['auto_submit'])
                    <span class="badge-pill-sm bg-green-200 dark:bg-green-900 text-green-800 dark:text-green-200">Auto-submit enabled</span>
                @endif
                @if ($config['production_url'])
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        Submitting for: <strong>{{ $config['production_url'] }}</strong>
                    </span>
                @endif
            </div>
        </div>
    </header>

    <indexnow-utility :config='@json($config)'></indexnow-utility>

@endsection
