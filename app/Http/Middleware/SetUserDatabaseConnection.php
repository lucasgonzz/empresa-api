<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DatabaseHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SetUserDatabaseConnection
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        Log::info('Middleware database');
        $user = Auth()->user();
        if (!is_null($user)) {
            Log::info('auth user_id: '.$user->id);
            DatabaseHelper::set_user_conecction();
        } else {
            Log::info('No habia user');
        }
        return $next($request);
    }
}
