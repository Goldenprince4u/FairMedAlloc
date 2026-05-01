<?php
/**
 * Local ML service client
 * =======================
 * Talks to the loopback-only Python scoring API. Keeps HTTP request handling
 * in one place so callers can stay focused on allocation flow.
 */

class MlServiceClient {
    private $baseUrl;
    private $timeout;

    public function __construct($baseUrl = null, $timeout = null) {
        $this->baseUrl = rtrim($baseUrl ?? ML_SERVICE_URL, '/');
        $this->timeout = (float)($timeout ?? ML_SERVICE_TIMEOUT);
    }

    public function health(): array {
        return $this->request('GET', '/health');
    }

    public function scoreBatch(array $payload): array {
        return $this->request('POST', '/ml/score-batch', $payload);
    }

    private function request(string $method, string $path, ?array $payload = null): array {
        $url        = $this->baseUrl . $path;
        $maxRetries = 2;
        $delayMs    = 200; // starts at 200 ms, doubles each attempt

        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
            try {
                if (function_exists('curl_init')) {
                    return $this->requestWithCurl($method, $url, $payload);
                }
                return $this->requestWithStreams($method, $url, $payload);

            } catch (Throwable $e) {
                $lastException = $e;

                if ($attempt <= $maxRetries) {
                    Logger::warning(
                        "ML service attempt {$attempt} failed ({$path}): " . $e->getMessage()
                        . " — retrying in {$delayMs}ms"
                    );
                    usleep($delayMs * 1000);
                    $delayMs *= 2; // exponential back-off: 200ms → 400ms
                }
            }
        }

        // All attempts exhausted — let the caller decide how to handle
        Logger::error("ML service unreachable after " . ($maxRetries + 1) . " attempts ({$path})", $lastException);
        throw $lastException;
    }

    private function requestWithCurl(string $method, string $url, ?array $payload = null): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('Unable to initialize cURL for ML service request.');
        }

        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($payload !== null) {
            $body = json_encode($payload);
            if ($body === false) {
                throw new Exception('Unable to encode ML service payload.');
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('ML service request failed: ' . $error);
        }

        return $this->decodeResponse($response, $status);
    }

    private function requestWithStreams(string $method, string $url, ?array $payload = null): array {
        $headers = ['Accept: application/json'];
        $content = null;

        if ($payload !== null) {
            $content = json_encode($payload);
            if ($content === false) {
                throw new Exception('Unable to encode ML service payload.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Exception('ML service request failed.');
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }

        return $this->decodeResponse($response, $status);
    }

    private function decodeResponse(string $response, int $status): array {
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new Exception('ML service returned invalid JSON.');
        }

        if ($status >= 400) {
            throw new Exception($decoded['message'] ?? ('ML service returned HTTP ' . $status));
        }

        return $decoded;
    }
}
