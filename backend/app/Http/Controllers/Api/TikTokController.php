<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TikTokActionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TikTok\RescheduleTikTokClipRequest;
use App\Http\Requests\TikTok\StoreTikTokResultsRequest;
use App\Http\Resources\TikTokClipResource;
use App\Models\TikTokClip;
use App\Services\TikTok\TikTokAllowanceService;
use App\Services\TikTok\TikTokEngineService;
use App\Support\TikTokCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TikTokController extends Controller
{
    public function __construct(
        private readonly TikTokEngineService $engine,
        private readonly TikTokAllowanceService $allowance,
    ) {}

    public function run(Request $request): JsonResponse
    {
        $result = $this->engine->run($request->user());

        return response()->json(
            $this->runPayload($request->user(), $result),
            $result['outcome'] === TikTokCatalog::CREATED ? 201 : 200,
        );
    }

    public function confirm(Request $request, int $clip): JsonResponse
    {
        try {
            $saved = $this->engine->confirm($request->user(), $this->clip($request, $clip));
        } catch (TikTokActionException $e) {
            return $this->error($e);
        }

        return TikTokClipResource::make($saved)->response();
    }

    public function results(StoreTikTokResultsRequest $request, int $clip): JsonResponse
    {
        try {
            $result = $this->engine->recordResults($request->user(), $this->clip($request, $clip), [
                'views' => (int) $request->validated('views'),
                'completion_percent' => (float) $request->validated('completion_percent'),
                'follows' => (int) $request->validated('follows'),
            ]);
        } catch (TikTokActionException $e) {
            return $this->error($e);
        }

        return response()->json([
            'data' => TikTokClipResource::make($result['clip'])->resolve($request),
            'next' => $result['next'] === null ? null : $this->runPayload($request->user(), $result['next']),
        ]);
    }

    public function discard(Request $request, int $clip): JsonResponse
    {
        try {
            $saved = $this->engine->discard($request->user(), $this->clip($request, $clip));
        } catch (TikTokActionException $e) {
            return $this->error($e);
        }

        return TikTokClipResource::make($saved)->response();
    }

    public function reschedule(RescheduleTikTokClipRequest $request, int $clip): JsonResponse
    {
        try {
            $saved = $this->engine->reschedule(
                $request->user(),
                $this->clip($request, $clip),
                CarbonImmutable::parse($request->validated('suggested_for')),
            );
        } catch (TikTokActionException $e) {
            return $this->error($e);
        }

        return TikTokClipResource::make($saved)->response();
    }

    private function clip(Request $request, int $clip): TikTokClip
    {
        return TikTokClip::query()->forUser($request->user())->whereKey($clip)->firstOrFail();
    }

    /**
     * @param  array{outcome: string, clip?: TikTokClip, reason?: string}  $result
     * @return array{outcome: string, clip: array<string, mixed>|null, reason: string|null, message: string}
     */
    private function runPayload($user, array $result): array
    {
        $reason = $result['reason'] ?? null;
        $clip = $result['clip'] ?? null;

        return [
            'outcome' => $result['outcome'],
            'clip' => $clip instanceof TikTokClip ? TikTokClipResource::make($clip)->resolve(request()) : null,
            'reason' => $reason,
            'message' => $this->message($user, $result['outcome'], $reason),
        ];
    }

    private function message($user, string $outcome, ?string $reason): string
    {
        if ($outcome === TikTokCatalog::CREATED) {
            return 'A clip is waiting for your yes.';
        }
        if ($reason === TikTokCatalog::ALLOWANCE_SPENT) {
            $status = $this->allowance->status($user);

            return TikTokCatalog::allowanceSpentMessage($status['limit'], substr($status['period_end'], 0, 10));
        }
        if ($reason === TikTokCatalog::NO_PLAN) {
            return TikTokCatalog::noPlanMessage();
        }

        return TikTokCatalog::skipMessage((string) $reason);
    }

    private function error(TikTokActionException $e): JsonResponse
    {
        $status = match ($e->errorCode) {
            TikTokCatalog::ERROR_INVALID_TIME, TikTokCatalog::ERROR_VALIDATION => 422,
            TikTokCatalog::ERROR_ALLOWANCE_SPENT, TikTokCatalog::ERROR_DAILY_CAP => 429,
            TikTokCatalog::ERROR_NO_PLAN => 402,
            default => 409,
        };

        return response()->json([
            'error' => [
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ],
        ], $status);
    }
}
