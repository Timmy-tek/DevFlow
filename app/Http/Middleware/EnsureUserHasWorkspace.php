<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $current = $user->currentWorkspace;

        // No current workspace, or the user is no longer a member of it.
        if (!$current || $user->roleIn($current) === null) {
            $fallback = $user->workspaces()->first();

            if (!$fallback) {
                return redirect()->route('workspaces.create');
            }

            $user->switchWorkspace($fallback);
            $user->unsetRelation('currentWorkspace');
        }

        return $next($request);
    }
}