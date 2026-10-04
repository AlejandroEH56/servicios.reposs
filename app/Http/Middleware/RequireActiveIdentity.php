<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\IAM\Domain\IdentityState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveIdentity
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $started = $request->session()->get('iam_authenticated_at', 0);
        $last = $request->session()->get('iam_last_activity', 0);
        if (! $user instanceof User || $user->estado !== IdentityState::Active
            || ! is_int($started) || ! is_int($last)
            || $started > now()->timestamp || $last > now()->timestamp
            || now()->timestamp - $started >= config('modernization.sessions.absolute_seconds')
            || now()->timestamp - $last >= min(config('modernization.sessions.idle_seconds'), config('session.lifetime') * 60)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(401);
        }
        $request->session()->put('iam_last_activity', now()->timestamp);

        return $next($request);
    }
}
