<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class ReturnNavigation
{
    public static function isPage(?string $name): bool
    {
        if (str_starts_with($name ?? '', 'sale.drafts.')) return false;
        return $name === 'dashboard' || in_array($name, ['report.stock', 'report.sale', 'report.purchase', 'report.transfer', 'report.profit-loss'], true)
            || (bool) preg_match('/\.(index|show|create|edit|import|import-trial|history|reset-password-result)$/', $name ?? '');
    }

    public static function isChild(?string $name): bool
    {
        return static::isPage($name) && (bool) preg_match('/\.(show|create|edit|import|import-trial|history|reset-password-result)$/', $name ?? '');
    }

    public static function path($value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 8192) return null;
        $decoded = rawurldecode($value);
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) || str_starts_with($decoded, '//')) return null;
        $parts = parse_url($value);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return null;
        if (isset($parts['host']) || isset($parts['scheme'])) {
            $origin = parse_url(request()->root());
            if (strtolower($parts['host'] ?? '') !== strtolower($origin['host'] ?? '') ||
                strtolower($parts['scheme'] ?? '') !== strtolower($origin['scheme'] ?? '') ||
                ($parts['port'] ?? null) !== ($origin['port'] ?? null)) return null;
        }
        $path = $parts['path'] ?? '';
        if (!str_starts_with($path, '/') || str_starts_with(rawurldecode($path), '//')) return null;
        try {
            if (!static::isPage(static::routeName($path))) return null;
        } catch (\Throwable) {
            return null;
        }
        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public static function routeName(string $path): ?string
    {
        $request = Request::create(request()->getSchemeAndHttpHost().$path, 'GET');
        // Validate without binding: matching a URL must not replace the current route's model parameters.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && $route->matches($request)) return $route->getName();
        }
        return null;
    }

    public static function back(string $fallback): string
    {
        $return = static::path(request()->query('return_to'));
        return $return && $return !== request()->getRequestUri() ? $return : $fallback;
    }

    public static function childPatterns(): array
    {
        $patterns = [];
        foreach (Route::getRoutes() as $route) {
            if (!in_array('GET', $route->methods(), true) || !static::isChild($route->getName())) continue;
            $uri = request()->getBaseUrl().'/'.ltrim($route->uri(), '/');
            $segments = explode('/', $uri);
            $patterns[] = '^'.implode('/', array_map(fn ($segment) => str_starts_with($segment, '{') ? '[^/]+' : preg_quote($segment, '~'), $segments)).'/?$';
        }
        return array_values(array_unique($patterns));
    }
}
