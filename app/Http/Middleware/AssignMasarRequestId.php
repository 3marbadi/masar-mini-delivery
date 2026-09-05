<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One correlation id per inbound Masar request (CONTRACT §3.21.7).
 *
 * Every response on this channel carries it, including the failures, so a report
 * from Masar's side names a row that can be found here. Distinct from
 * `event_id`, which identifies the logical event across all of its retries: two
 * retries of one event share an `event_id` and have different request ids, and
 * telling them apart is exactly what an investigation needs.
 */
class AssignMasarRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('request_id', (string) Str::uuid());

        return $next($request);
    }
}
