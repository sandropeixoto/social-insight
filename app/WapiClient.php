<?php

final class WapiClient
{
    private string $baseUrl;
    private string $authToken;
    private int $timeout;
    private bool $verifySsl;

    public function __construct(string $baseUrl, string $authToken, int $timeout = 15, bool $verifySsl = true)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authToken = $authToken;
        $this->timeout = max($timeout, 5);
        $this->verifySsl = $verifySsl;
    }

    /**
     * Executes a GET request.
     */
    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    /**
     * Issues an HTTP request and returns the decoded JSON response.
     */
    private function request(string $method, string $endpoint): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL session.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->authToken,
            ],
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $result = curl_exec($handle);

        if ($result === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('W-API request failed: ' . $error);
        }

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $decoded = json_decode($result, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new UnexpectedValueException('Unexpected response from W-API: ' . json_last_error_msg());
        }

        if ($statusCode >= 400) {
            $message = is_array($decoded)
                ? ($decoded['message'] ?? $decoded['error'] ?? null)
                : null;

            throw new RuntimeException('W-API responded with error: ' . ($message ?: 'HTTP ' . $statusCode), $statusCode);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
