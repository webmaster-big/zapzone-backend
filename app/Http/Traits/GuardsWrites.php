<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait GuardsWrites
{
    protected function guardWrite(string $action, array $context, callable $work): JsonResponse
    {
        try {
            return $work();
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error($action . ' failed', $context + [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong saving that. It has been logged — please try again, and tell an administrator if it keeps happening.',
            ], 500);
        }
    }
}
