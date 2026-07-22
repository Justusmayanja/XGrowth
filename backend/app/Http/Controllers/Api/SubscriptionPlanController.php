<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => $plan->toPublicArray());

        return response()->json(['plans' => $plans]);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        if (! in_array($slug, SubscriptionPlan::allowedSlugs(), true)) {
            return response()->json(['message' => 'Unknown plan.'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:64'],
            'price_display' => ['sometimes', 'string', 'max:32'],
            'period' => ['sometimes', 'string', 'max:16'],
            'yearly_price_display' => ['sometimes', 'string', 'max:32'],
            'yearly_period' => ['sometimes', 'string', 'max:16'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'cta' => ['sometimes', 'string', 'max:64'],
            'highlighted' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        $plan = SubscriptionPlan::query()->findOrFail($slug);
        $plan->update($validated);

        return response()->json(['plan' => $plan->fresh()->toPublicArray()]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plans' => ['required', 'array', 'min:1'],
            'plans.*.slug' => ['required', 'string', Rule::in(SubscriptionPlan::allowedSlugs())],
            'plans.*.name' => ['sometimes', 'string', 'max:64'],
            'plans.*.price_display' => ['sometimes', 'string', 'max:32'],
            'plans.*.period' => ['sometimes', 'string', 'max:16'],
            'plans.*.yearly_price_display' => ['sometimes', 'string', 'max:32'],
            'plans.*.yearly_period' => ['sometimes', 'string', 'max:16'],
            'plans.*.description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'plans.*.cta' => ['sometimes', 'string', 'max:64'],
            'plans.*.highlighted' => ['sometimes', 'boolean'],
            'plans.*.daily_ai_generations' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'plans.*.daily_web_searches' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $updated = [];

        foreach ($validated['plans'] as $item) {
            $slug = $item['slug'];
            unset($item['slug']);

            if ($item === []) {
                continue;
            }

            $plan = SubscriptionPlan::query()->findOrFail($slug);
            $plan->update($item);
            $updated[] = $plan->fresh()->toPublicArray();
        }

        if ($updated === []) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        return response()->json(['plans' => $updated]);
    }
}
