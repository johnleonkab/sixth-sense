<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Sixth Sense">

        <title inertia>{{ config('app.name', 'sixth-sense') }}</title>
        <meta name="description" content="Tu jornada de trabajo, hablando. An open-source assistant that answers out loud and shows the calendar, the email or the thread you are talking about. Run it yourself.">
        <meta property="og:title" content="Sixth Sense — your workday, spoken">
        <meta property="og:description" content="Tu jornada de trabajo, hablando. An open-source assistant that answers out loud and shows the calendar, the email or the thread you are talking about. Run it yourself.">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ url('/') }}">
        <meta property="og:image" content="{{ url('/icon-512.png') }}">
        <meta name="twitter:card" content="summary">
        <meta name="twitter:title" content="Sixth Sense — your workday, spoken">
        <meta name="twitter:description" content="Tu jornada de trabajo, hablando. An open-source assistant that answers out loud and shows the calendar, the email or the thread you are talking about. Run it yourself.">

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="theme-color" content="#fbfbfa" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0f1010" media="(prefers-color-scheme: dark)">

        @fonts
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
