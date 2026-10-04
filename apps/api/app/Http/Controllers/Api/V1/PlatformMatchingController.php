<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MatchCandidateStatus;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use App\Enums\ReviewAction;
use App\Http\Controllers\Controller;
use App\Models\MatchCandidate;
use App\Models\User;
use App\Models\VendorItemMapping;
use App\Services\CatalogReviewService;
use App\Services\PlatformMatchingQueryService;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PlatformMatchingController extends Controller
{
    public function index(Request $request, PlatformMatchingQueryService $queries): JsonResponse
    {
        $validated = $request->validate([
            'supplier_account_id' => ['nullable', 'uuid'], 'confidence' => ['nullable', Rule::enum(MatchConfidence::class)],
            'category_id' => ['nullable', 'uuid'], 'age_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'status' => ['nullable', Rule::enum(MatchCandidateStatus::class)], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        /** @var array{supplier_account_id?: string|null, confidence?: string|null, category_id?: string|null, age_days?: int|null, status?: string|null, page?: int, per_page?: int} $validated */
        $filters = [...$validated, 'page' => $validated['page'] ?? 1, 'per_page' => $validated['per_page'] ?? 25];
        $paginator = $queries->paginate($filters);

        return response()->json(['candidates' => $paginator->getCollection()->map(fn (MatchCandidate $candidate) => $queries->summary($candidate))->all(), 'pagination' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'total_pages' => $paginator->lastPage()], 'filters' => ['supplier_account_id' => $validated['supplier_account_id'] ?? null, 'confidence' => $validated['confidence'] ?? null, 'category_id' => $validated['category_id'] ?? null, 'age_days' => $validated['age_days'] ?? null, 'status' => $validated['status'] ?? null], 'filter_options' => $queries->filterOptions()]);
    }

    public function show(MatchCandidate $matchCandidate, PlatformMatchingQueryService $queries): JsonResponse
    {
        return response()->json(['match_candidate' => $queries->detail($matchCandidate)]);
    }

    public function canonicalProducts(Request $request, PlatformMatchingQueryService $queries): JsonResponse
    {
        $validated = $request->validate(['query' => ['nullable', 'string', 'max:100'], 'category_id' => ['nullable', 'uuid']]);

        return response()->json(['canonical_products' => $queries->canonicalProducts($validated['query'] ?? null, $validated['category_id'] ?? null)]);
    }

    public function review(Request $request, MatchCandidate $matchCandidate, CatalogReviewService $reviews, PlatformMatchingQueryService $queries): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::enum(ReviewAction::class)], 'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'canonical_product_id' => ['nullable', 'uuid'], 'product_variant_id' => ['nullable', 'uuid'],
            'match_class' => ['nullable', Rule::enum(MatchClass::class)], 'confidence' => ['nullable', Rule::enum(MatchConfidence::class)],
            'new_canonical' => ['nullable', 'array'], 'new_canonical.english_name' => ['required_if:action,create_canonical', 'string', 'min:2', 'max:255'],
            'new_canonical.category_id' => ['required_if:action,create_canonical', 'uuid', 'exists:categories,id'],
            'new_canonical.attributes' => ['nullable', 'array', 'max:20'], 'new_canonical.aliases' => ['nullable', 'array', 'max:10'],
            'new_canonical.aliases.*.locale' => ['required_with:new_canonical.aliases', 'string', 'min:2', 'max:10'], 'new_canonical.aliases.*.alias' => ['required_with:new_canonical.aliases', 'string', 'min:1', 'max:255'],
            'new_canonical.variant' => ['nullable', 'array'], 'new_canonical.variant.code' => ['required_with:new_canonical.variant', 'string', 'min:1', 'max:100'], 'new_canonical.variant.normalized_key' => ['nullable', 'string', 'max:255'], 'new_canonical.variant.attributes' => ['nullable', 'array', 'max:20'],
        ]);
        $action = ReviewAction::from($validated['action']);
        if ($action === ReviewAction::Reject && ! isset($validated['reason'])) {
            throw new ApiProblem('review_reason_required', 'A reason is required when rejecting a candidate.', 422);
        }
        if ($action === ReviewAction::ChooseExisting && ! isset($validated['canonical_product_id'])) {
            throw new ApiProblem('canonical_product_required', 'A canonical product is required for this review action.', 422);
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }
        $candidate = $reviews->review($matchCandidate, $actor, $action, $validated);

        return response()->json(['match_candidate' => $queries->detail($candidate)]);
    }

    public function reverse(Request $request, VendorItemMapping $mapping, CatalogReviewService $reviews): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }
        $reversed = $reviews->reverse($mapping, $actor, $validated['reason']);

        return response()->json(['mapping' => ['id' => $reversed->id, 'is_current' => $reversed->is_current, 'superseded_at' => $reversed->superseded_at?->toIso8601String()]]);
    }
}
