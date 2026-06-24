<!-- SEO Meta Content -->
@push('meta')
    <meta name="title" content="{{ $page->meta_title }}" />

    <meta name="description" content="{{ $page->meta_description }}" />

    <meta name="keywords" content="{{ $page->meta_keywords }}" />
@endPush

<!-- Typography for CMS HTML content (the theme's CSS reset strips list bullets,
     table borders and heading spacing, so restore sensible document styling). -->
@push('styles')
    <style>
        .cms-page-content { line-height: 1.7; color: #374151; }
        .cms-page-content h1 { font-size: 2rem; font-weight: 700; margin: 1.5rem 0 1rem; }
        .cms-page-content h2 { font-size: 1.5rem; font-weight: 700; margin: 1.5rem 0 .75rem; }
        .cms-page-content h3 { font-size: 1.25rem; font-weight: 600; margin: 1.25rem 0 .5rem; }
        .cms-page-content h4 { font-size: 1.1rem; font-weight: 600; margin: 1rem 0 .5rem; }
        .cms-page-content p { margin: .75rem 0; }
        .cms-page-content ul { list-style: disc; margin: .75rem 0; padding-left: 1.5rem; }
        .cms-page-content ol { list-style: decimal; margin: .75rem 0; padding-left: 1.5rem; }
        .cms-page-content li { margin: .35rem 0; }
        .cms-page-content a { color: #1d4ed8; text-decoration: underline; }
        .cms-page-content table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
        .cms-page-content th, .cms-page-content td { border: 1px solid #d1d5db; padding: .5rem .75rem; text-align: left; vertical-align: top; }
        .cms-page-content th { background: #f9fafb; font-weight: 600; }
        .cms-page-content img { max-width: 100%; height: auto; }
        .cms-page-content blockquote { border-left: 4px solid #d1d5db; margin: 1rem 0; padding-left: 1rem; color: #6b7280; }
    </style>
@endPush

<!-- Page Layout -->
<x-shop::layouts>
    <!-- Page Title -->
    <x-slot:title>
        {{ $page->meta_title }}
    </x-slot>

    <!-- Page Content -->
    <div class="container mt-8 px-[60px] max-lg:px-8">
        <div class="cms-page-content">
            {!! $page->html_content !!}
        </div>
    </div>
</x-shop::layouts>