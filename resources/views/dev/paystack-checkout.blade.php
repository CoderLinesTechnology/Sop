<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Mock Paystack checkout (development)</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-dvh items-center justify-center bg-[#011B33] p-4">
    <main class="w-full max-w-sm rounded-xl bg-white p-7 shadow-2xl">
        <p class="rounded bg-tint-yellow px-3 py-2 text-xs font-semibold text-tint-yellow-ink">Development only — simulated Paystack checkout. No real payment is taken.</p>
        <p class="mt-6 text-sm text-muted">{{ $email }}</p>
        <p class="mt-1 text-3xl font-bold text-ink">{{ $amount }}</p>
        <p class="mt-1 text-xs text-muted">Reference: {{ $reference }}</p>
        <form method="POST" action="{{ route('dev.paystack.complete', $reference) }}" class="mt-7 space-y-3">
            @csrf
            <button name="outcome" value="success" class="btn w-full bg-[#09A5DB] text-white hover:bg-[#0791c1]" dusk="mock-pay-success">Simulate successful payment</button>
            <button name="outcome" value="failed" class="btn w-full border border-line-strong text-ink hover:bg-sand" dusk="mock-pay-failed">Simulate failed payment</button>
        </form>
    </main>
</body>
</html>
