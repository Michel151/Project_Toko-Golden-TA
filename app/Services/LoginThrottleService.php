<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginThrottleService
{
    private function key(Request $request): string
    {
        return 'login:' . hash('sha256', Str::lower(trim((string) $request->input('email'))) . '|' . $request->ip());
    }

    public function remaining(Request $request): int
    {
        $key = $this->key($request) . ':cooldown';
        return RateLimiter::tooManyAttempts($key, 1) ? RateLimiter::availableIn($key) : 0;
    }

    public function failed(Request $request): int
    {
        $key = $this->key($request);
        $attempts = RateLimiter::hit($key . ':failures', 60);
        // Escalation expires after 15 minutes, avoiding a permanent account lock.
        if ($attempts >= 5 || RateLimiter::attempts($key . ':level') > 0) {
            $level = RateLimiter::hit($key . ':level', 900);
            $seconds = 15 * (2 ** min($level - 1, 2));
            RateLimiter::hit($key . ':cooldown', $seconds);
            return $seconds;
        }
        return 0;
    }

    public function clear(Request $request): void
    {
        $key = $this->key($request);
        foreach (['failures', 'level', 'cooldown'] as $suffix) {
            RateLimiter::clear($key . ':' . $suffix);
        }
    }
}
