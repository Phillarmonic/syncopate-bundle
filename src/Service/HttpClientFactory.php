<?php

namespace Phillarmonic\SyncopateBundle\Service;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Factory service for HTTP client components
 */
class HttpClientFactory
{
    /**
     * Creates a progress callback for HTTP responses to optimize memory usage
     */
    public static function createProgressCallback(): \Closure
    {
        return function(int $dlNow, int $dlSize, array $info) {
            // Release memory periodically during downloads
            if ($dlNow > 0 && $dlSize > 0 && $dlNow % (1024 * 1024) < 1024) { // Every ~1MB
                gc_collect_cycles();
            }

            // No need to return anything for the callback
            return null;
        };
    }

    /**
     * Stream response content in chunks to manage memory
     */
    public static function streamResponseContent(ResponseInterface $response, int $chunkSize = 8192): string
    {
        $content = '';
        $chunkCount = 0;

        foreach ($response->toArray(false) as $chunk) {
            $content .= $chunk;
            $chunkCount++;

            // Free memory after processing chunks
            if ($chunkCount % 10 === 0) {
                gc_collect_cycles();
            }
        }

        return $content;
    }

    /**
     * Parse a JSON response with memory optimization
     */
    public static function parseJsonResponse(ResponseInterface $response): array
    {
        $content = self::streamResponseContent($response);

        try {
            $result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            // Free memory
            unset($content);
            gc_collect_cycles();

            return $result;
        } finally {
            // Ensure memory is freed even on exception
            unset($content);
            gc_collect_cycles();
        }
    }
}