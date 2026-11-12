<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes retries and double taps safe. A repeated Idempotency-Key replays the first
 * response instead of doing the work twice, including after a lost network reply.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $key || ! $request->user() || ! in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'])) {
            return $next($request);
        }

        $userId = $request->user()->id;
        $route = $request->method().' '.$request->path();
        $hash = hash('sha256', $route.'|'.$request->getContent());

        try {
            $id = DB::table('idempotency_keys')->insertGetId([
                'key' => substr($key, 0, 100), 'user_id' => $userId, 'route' => substr($route, 0, 160),
                'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $row = DB::table('idempotency_keys')->where('user_id', $userId)->where('key', substr($key, 0, 100))->first();
            if ($row->request_hash !== $hash) {
                return $this->error('This request key was already used for a different request.', 'idempotency_mismatch', 422);
            }
            if ($row->status_code === null) {
                return $this->error('The same request is still being processed.', 'idempotency_in_progress', 409);
            }

            return response($row->response_body, $row->status_code, [
                'Content-Type' => 'application/json',
                'Idempotent-Replay' => 'true',
            ]);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            // A server failure rolled the work back, so the same key may safely try again.
            DB::table('idempotency_keys')->where('id', $id)->delete();
        } else {
            DB::table('idempotency_keys')->where('id', $id)->update([
                'status_code' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'updated_at' => now(),
            ]);
        }

        return $response;
    }

    private function error(string $message, string $code, int $status): Response
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}
