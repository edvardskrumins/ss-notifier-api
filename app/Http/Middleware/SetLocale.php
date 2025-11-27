<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Category;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * Detects locale from query parameter, Accept-Language header, or defaults to LV.
     * Sets the locale on the request for use throughout the application.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('locale') 
            ?? $request->header('Accept-Language') 
            ?? Category::LOCALE_LV;

        if (!in_array($locale, [Category::LOCALE_LV, Category::LOCALE_EN])) {
            $locale = Category::LOCALE_LV;
        }

        $request->merge(['locale' => $locale]);
        $request->attributes->set('locale', $locale);

        return $next($request);
    }
}
