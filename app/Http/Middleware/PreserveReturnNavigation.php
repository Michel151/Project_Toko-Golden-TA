<?php

namespace App\Http\Middleware;

use App\Support\ReturnNavigation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class PreserveReturnNavigation
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (!$response instanceof RedirectResponse || $request->expectsJson()) return $response;
        $return = ReturnNavigation::path($request->query('return_to'));
        $target = ReturnNavigation::path($response->getTargetUrl());
        if (!$return || !$target) return $response;
        $targetPath = parse_url($target, PHP_URL_PATH);
        $returnPath = parse_url($return, PHP_URL_PATH);
        $targetName = ReturnNavigation::routeName($targetPath);
        if ($targetPath === $returnPath || (str_ends_with($targetName ?? '', '.index') && str_starts_with($returnPath, rtrim($targetPath, '/').'/'))) {
            $response->setTargetUrl($return);
        } else {
            if (ReturnNavigation::isChild($targetName)) {
                $parts = parse_url($target);
                parse_str($parts['query'] ?? '', $query);
                $query['return_to'] = $return;
                $response->setTargetUrl($parts['path'].'?'.http_build_query($query).(isset($parts['fragment']) ? '#'.$parts['fragment'] : ''));
            }
        }
        return $response;
    }
}
