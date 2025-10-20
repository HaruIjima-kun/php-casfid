<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Infrastructure\Http\Response;

final class ResponseCacheMiddlewareTest extends TestCase
{
    public function test_miss_then_hit_same_get(): void
    {
        // First request -> MISS
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/health';
        ob_start();
        require __DIR__ . '/../../public/index.php';
        ob_end_clean();

        $x1 = Response::getHeader('X-Cache');
        $this->assertSame('MISS', $x1, 'First GET should be cached as MISS');

        // Second request -> HIT
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/health';
        ob_start();
        require __DIR__ . '/../../public/index.php';
        ob_end_clean();

        $x2 = Response::getHeader('X-Cache');
        $this->assertSame('HIT', $x2, 'Second GET should be cached as HIT');
    }
}
