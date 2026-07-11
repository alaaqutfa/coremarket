<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CorePilotAddonRequestClient
{
    public function submit(array $payload): array
    {
        $url = config('coremarket.corepilot_addon_requests.url');
        $token = config('coremarket.corepilot_addon_requests.token');
        if (! $url || ! $token) { throw new RuntimeException('CorePilotOS add-on request connection is not configured.'); }
        $response = Http::acceptJson()->asJson()->timeout(12)->withHeaders([config('coremarket.corepilot_addon_requests.header') => $token])->post($url, $payload);
        if (! $response->successful()) { throw new RuntimeException('CorePilotOS could not accept the add-on request.'); }
        return $response->json();
    }
}
