<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;

interface Middleware
{
    /**
     * @param callable(Request): void $next
     */
    public function handle(Request $req, callable $next): void;
}
