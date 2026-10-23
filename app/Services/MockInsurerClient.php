<?php

namespace App\Services;

use App\Http\Controllers\MockInsurerController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MockInsurerClient
{
    /**
     * Submit a policy request to the mock insurer.
     *
     * @param array  $requestData  The data to send
     * @param string $scenario     The mock scenario to simulate
     *
     * @return array{success: bool, status: int, data: array, error: string|null, timed_out: bool}
     */
    public function submit(array $requestData, string $scenario = 'SUCCESS'): array
    {
        $externalUrl = config('services.mock_insurer.url');

        if ($externalUrl) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders(['X-Mock-Scenario' => $scenario])
                    ->post(rtrim($externalUrl, '/') . '/api/mock-insurer/submit', $requestData);

                $isTimeout = $response->status() === 504;

                return [
                    'success' => $response->successful(),
                    'status' => $response->status(),
                    'data' => $response->json() ?? [],
                    'error' => $response->successful() ? null : ($response->json('error') ?? 'Unknown error'),
                    'timed_out' => $isTimeout,
                ];
            } catch (ConnectionException $e) {
                return [
                    'success' => false,
                    'status' => 0,
                    'data' => [],
                    'error' => 'Connection timeout: ' . $e->getMessage(),
                    'timed_out' => true,
                ];
            }
        }

        // In-process mock dispatcher (prevents PHP CLI single-threaded web server deadlock on localhost)
        $mockController = app(MockInsurerController::class);
        $request = Request::create(
            '/api/mock-insurer/submit',
            'POST',
            $requestData,
            [],
            [],
            ['HTTP_X_MOCK_SCENARIO' => $scenario],
        );

        $response = $mockController->submit($request);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(), true) ?? [];

        return [
            'success' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => $data,
            'error' => ($status >= 200 && $status < 300) ? null : ($data['error'] ?? 'Unknown error'),
            'timed_out' => $status === 504,
        ];
    }

    /**
     * Check the status of a previously submitted request via provider reference.
     *
     * @param string $providerReference  The provider's reference ID
     *
     * @return array{found: bool, status: string|null, policy_number: string|null}
     */
    public function checkStatus(string $providerReference): array
    {
        $externalUrl = config('services.mock_insurer.url');

        if ($externalUrl) {
            try {
                $response = Http::timeout(5)->get(rtrim($externalUrl, '/') . '/api/mock-insurer/status/' . $providerReference);

                if ($response->successful()) {
                    return [
                        'found' => true,
                        'status' => $response->json('status'),
                        'policy_number' => $response->json('policy_number'),
                    ];
                }

                return ['found' => false, 'status' => null, 'policy_number' => null];
            } catch (ConnectionException) {
                return ['found' => false, 'status' => null, 'policy_number' => null];
            }
        }

        $mockController = app(MockInsurerController::class);
        $response = $mockController->status($providerReference);

        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getContent(), true) ?? [];
            return [
                'found' => true,
                'status' => $data['status'] ?? null,
                'policy_number' => $data['policy_number'] ?? null,
            ];
        }

        return ['found' => false, 'status' => null, 'policy_number' => null];
    }
}
