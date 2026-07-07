<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ coremarketStoreName() }} | {{ translate('Access denied') }}</title>
    <style>
        :root {
            color-scheme: light;
            --cm-bg: #f4f7fb;
            --cm-card: #ffffff;
            --cm-text: #1f2937;
            --cm-muted: #6b7280;
            --cm-border: #dbe3ef;
            --cm-accent: #0ea5b7;
            --cm-accent-strong: #0f766e;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(180deg, #f8fbff 0%, var(--cm-bg) 100%);
            color: var(--cm-text);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .cm-error-shell {
            width: min(100%, 920px);
            display: grid;
            grid-template-columns: minmax(260px, 380px) minmax(280px, 1fr);
            gap: 24px;
            align-items: center;
        }

        .cm-error-card {
            background: var(--cm-card);
            border: 1px solid var(--cm-border);
            border-radius: 24px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
            padding: 32px;
        }

        .cm-error-image {
            width: 100%;
            height: auto;
            display: block;
        }

        .cm-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(14, 165, 183, 0.12);
            color: var(--cm-accent-strong);
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 18px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(32px, 5vw, 44px);
            line-height: 1.1;
        }

        p {
            margin: 0;
            color: var(--cm-muted);
            font-size: 16px;
            line-height: 1.7;
        }

        .cm-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        .cm-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        .cm-button-primary {
            background: var(--cm-accent);
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(14, 165, 183, 0.22);
        }

        .cm-button-secondary {
            border: 1px solid var(--cm-border);
            color: var(--cm-text);
            background: #ffffff;
        }

        .cm-button:hover {
            transform: translateY(-1px);
        }

        @media (max-width: 768px) {
            .cm-error-shell {
                grid-template-columns: 1fr;
            }

            .cm-error-card {
                padding: 24px;
            }
        }
    </style>
</head>
<body>
    @php
        $isAdminRequest = request()->is('admin') || request()->is('admin/*');
        $primaryUrl = $isAdminRequest && auth()->check() ? route('admin.dashboard') : route('home');
        $primaryLabel = $isAdminRequest && auth()->check()
            ? translate('Return to dashboard')
            : translate('Return to storefront');
    @endphp

    <main class="cm-error-shell">
        <section class="cm-error-card" aria-hidden="true">
            <img
                src="{{ static_asset('assets/img/403.svg') }}"
                alt="{{ translate('Access denied illustration') }}"
                class="cm-error-image"
            >
        </section>

        <section class="cm-error-card">
            <div class="cm-badge">{{ translate('Permission required') }}</div>
            <h1>{{ translate('Access denied') }}</h1>
            <p>{{ translate('You do not have permission to open this page with your current account.') }}</p>

            <div class="cm-actions">
                <a href="{{ $primaryUrl }}" class="cm-button cm-button-primary">{{ $primaryLabel }}</a>
                <a href="{{ route('home') }}" class="cm-button cm-button-secondary">{{ translate('Go to home') }}</a>
            </div>
        </section>
    </main>
</body>
</html>
