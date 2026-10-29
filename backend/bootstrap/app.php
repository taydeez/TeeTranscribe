<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Folder\Exceptions\FolderNotFoundException;
use App\Domain\Folder\Exceptions\TranscriptionCannotBeAddedToFolderException;
use App\Domain\Transcriber\Exceptions\TranscriptionNotFoundException;
use App\Domain\Upload\Exceptions\UploadException;
use App\Http\Middleware\RequireActiveAccount;
use App\Http\Middleware\RequireAdminSession;
use Aws\S3\Exception\S3Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))));
        $middleware->appendToGroup('api', RequireActiveAccount::class);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'admin.session' => RequireAdminSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (BillingException $exception) => response()->json(['message' => $exception->getMessage()], $exception->httpStatus));
        $exceptions->render(fn (UploadException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ], $exception->httpStatus));
        $exceptions->render(function (S3Exception $exception, Request $request) {
            if ($request->is('api/v1/uploads/multipart*')) {
                return response()->json(['message' => 'Storage is unavailable. Your upload progress is saved; please retry.'], 503);
            }
        });
        $exceptions->render(function (LockTimeoutException $exception, Request $request) {
            if ($request->is('api/v1/uploads/multipart*')) {
                return response()->json(['message' => 'Another upload operation is in progress. Please retry.'], 409);
            }
        });
        $exceptions->render(fn (FolderNotFoundException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ], 404));
        $exceptions->render(fn (TranscriptionCannotBeAddedToFolderException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ], 422));
        $exceptions->render(fn (TranscriptionNotFoundException $exception) => response()->json([
            'message' => $exception->getMessage(),
        ], 404));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
