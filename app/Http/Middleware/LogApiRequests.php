<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        
        $rawBody = $request->getContent();
        $requestBody = $request->all();
        
        // If raw body exists and is JSON, prefer decoded JSON over parsed body
        if (!empty($rawBody) && $request->isJson()) {
            $jsonBody = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonBody)) {
                $requestBody = $jsonBody;
            }
        }
        
        $sanitizedBody = $this->sanitizeBody($requestBody);
        
        if (is_array($sanitizedBody) && empty($sanitizedBody)) {
            $sanitizedBody = new \stdClass();
        }
        
        $bodyJson = !empty($sanitizedBody) ? json_encode($sanitizedBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
        
        // Capture request data
        $requestData = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => $request->user()?->id,
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'query' => $request->query->all(),
            'body' => $bodyJson, 
        ];

        // Process the request
        $response = $next($request);

        // Calculate duration
        $duration = round((microtime(true) - $startTime) * 1000, 2); // milliseconds
        
        // Get response headers
        $responseHeaders = [];
        foreach ($response->headers->all() as $key => $values) {
            $responseHeaders[$key] = $values;
        }
        
        $responseContent = $response->getContent();
        
        // Combine request and response into a single log entry
        $logData = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => $request->user()?->id,
            'duration_ms' => $duration,
            'request' => [
                'headers' => $requestData['headers'],
                'query' => $requestData['query'],
                'body' => $requestData['body'],
            ],
            'response' => [
                'status_code' => $response->getStatusCode(),
                'headers' => $this->sanitizeHeaders($responseHeaders),
                'body' => $responseContent,
                'size' => strlen($responseContent),
            ],
        ];

        Log::channel('api')->info('API Request/Response', $logData);

        return $response;
    }

    /**
     * Sanitize headers to remove sensitive information
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'x-xsrf-token', 'x-csrf-token', 'set-cookie'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitive)) {
                // For set-cookie, just log that cookies were set without the full value
                if ($lowerKey === 'set-cookie') {
                    $sanitized[$key] = array_map(function($cookie) {
                        // Extract just the cookie name (before =)
                        if (preg_match('/^([^=]+)=/', $cookie, $matches)) {
                            return $matches[1] . '=***';
                        }
                        return '***';
                    }, $value);
                } else {
                    $sanitized[$key] = ['***'];
                }
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize request body to remove sensitive information
     */
    private function sanitizeBody(array $data): array
    {
        $sensitive = ['password', 'password_confirmation', 'token', 'api_key', 'secret'];
        $sanitized = $data;

        foreach ($sanitized as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitive)) {
                $sanitized[$key] = '***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeBody($value);
            }
        }

        return $sanitized;
    }


}

