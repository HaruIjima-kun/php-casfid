<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Infrastructure\Http\Response;

final class RateLimitHeadersTest extends TestCase
{
    public function test_headers_are_present_in_response(): void
    {
        // Simula “petición” real llamando a /health con el front controller
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/health';

        // Capturamos salida para no ensuciar el test
        ob_start();
        require __DIR__ . '/../../public/index.php';
        ob_end_clean();

        // Intenta primero con headers_list() (CLI suele devolver vacío)
        $headers = function_exists('headers_list') ? headers_list() : [];

        $hasLimit = false;
        foreach ($headers as $h) {
            if (stripos($h, 'X-RateLimit-Limit:') === 0) {
                $hasLimit = true; break;
            }
        }

        if (!$hasLimit) {
            // Fallback a nuestro Response (cabeceras almacenadas para CLI/tests)
            $limit     = Response::getHeader('X-RateLimit-Limit');
            $remaining = Response::getHeader('X-RateLimit-Remaining');
            $reset     = Response::getHeader('X-RateLimit-Reset');

            $this->assertNotNull($limit, 'Missing X-RateLimit-Limit header');
            $this->assertNotNull($remaining, 'Missing X-RateLimit-Remaining header');
            $this->assertNotNull($reset, 'Missing X-RateLimit-Reset header');
            return;
        }

        $this->assertTrue($hasLimit, 'Missing X-RateLimit-Limit header');
    }
}
