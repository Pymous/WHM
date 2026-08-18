<?php

namespace App\Http\Controllers;

use App\Services\EvePiTaxAnalyticsService;
use App\Services\EvePiTaxSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminPiRevenueController extends Controller
{
    public function index(EvePiTaxAnalyticsService $analytics): Response
    {
        return Inertia::render('admin/PiRevenue', [
            'initialData' => $analytics->analyze($this->corporationId()),
        ]);
    }

    public function data(Request $request, EvePiTaxAnalyticsService $analytics): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['required', 'in:24h,7d,3m,1y,custom'],
            'start' => ['nullable', 'required_if:range,custom', 'date', 'before_or_equal:today'],
            'end' => ['nullable', 'required_if:range,custom', 'date', 'after_or_equal:start', 'before_or_equal:today'],
        ]);

        return response()->json($analytics->analyze(
            $this->corporationId(),
            $validated['range'],
            $validated['start'] ?? null,
            $validated['end'] ?? null,
        ));
    }

    public function sync(
        Request $request,
        EvePiTaxSyncService $sync,
        EvePiTaxAnalyticsService $analytics,
    ): JsonResponse {
        $validated = $request->validate([
            'range' => ['sometimes', 'in:24h,7d,3m,1y,custom'],
            'start' => ['nullable', 'required_if:range,custom', 'date', 'before_or_equal:today'],
            'end' => ['nullable', 'required_if:range,custom', 'date', 'after_or_equal:start', 'before_or_equal:today'],
        ]);

        try {
            $syncResult = $sync->sync($this->corporationId());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'PI wallet synchronization failed. Check the EVE authorization and try again.',
            ], 503);
        }

        return response()->json([
            'sync' => $syncResult,
            'analytics' => $analytics->analyze(
                $this->corporationId(),
                $validated['range'] ?? '7d',
                $validated['start'] ?? null,
                $validated['end'] ?? null,
            ),
        ]);
    }

    private function corporationId(): int
    {
        $corporationId = (int) config('eve.corporations.main');

        abort_unless($corporationId, 503, 'The main EVE corporation is not configured.');

        return $corporationId;
    }
}
