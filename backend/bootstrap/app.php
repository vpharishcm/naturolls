<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RequirePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'perm' => RequirePermission::class,
            'idempotent' => Idempotency::class,
        ]);
        // There is no server login page: the API always answers 401, whatever the caller asks for.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Wrong passwords and low stock are normal at a counter, not faults to fill the log with.
        $exceptions->dontReport(BusinessException::class);

        $json = fn (string $message, string $code, int $status, array $extra = []) => response()->json(
            ['success' => false, 'message' => $message, 'code' => $code] + $extra,
            $status,
        );
        $isApi = fn (Request $r) => $r->is('api/*');

        $exceptions->render(fn (BusinessException $e) => $json($e->getMessage(), $e->errorCode, $e->status, $e->details ? ['details' => $e->details] : []));
        $exceptions->render(fn (ValidationException $e, Request $r) => $isApi($r) ? $json($e->getMessage(), 'validation_failed', 422, ['errors' => $e->errors()]) : null);
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $isApi($r) ? $json('Please sign in again.', 'unauthenticated', 401) : null);
        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($json, $isApi) {
            return $isApi($r) ? $json($e->getPrevious() instanceof ModelNotFoundException ? 'Record not found.' : 'Not found.', 'not_found', 404) : null;
        });
        $exceptions->render(function (Throwable $e, Request $r) use ($json, $isApi) {
            if (! $isApi($r) || config('app.debug')) {
                return null;
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return $json($status === 500 ? 'Something went wrong on the server.' : ($e->getMessage() ?: 'Request failed.'), 'server_error', $status);
        });
    })->create();
