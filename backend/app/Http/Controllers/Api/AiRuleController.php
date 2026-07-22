<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = AiRule::query()
            ->with('updatedByUser:id,email')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (AiRule $rule) => $rule->toAdminArray());

        return response()->json(['rules' => $rules]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.key' => ['required', 'string', Rule::in(AiRule::allowedKeys())],
            'rules.*.rules' => ['sometimes', 'string', 'max:10000'],
            'rules.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $updated = [];

        foreach ($validated['rules'] as $item) {
            $key = $item['key'];
            unset($item['key']);

            if ($item === []) {
                continue;
            }

            $rule = AiRule::query()->findOrFail($key);
            $rule->update([
                ...$item,
                'updated_by' => $request->user()->id,
            ]);
            $updated[] = $rule->fresh(['updatedByUser:id,email'])->toAdminArray();
        }

        if ($updated === []) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        AiRule::clearCache();

        return response()->json(['rules' => $updated]);
    }
}
