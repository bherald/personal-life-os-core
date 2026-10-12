<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Review\ReviewDeskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReviewDeskController extends Controller
{
    public function __construct(private ReviewDeskService $desk) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'sometimes|in:pending,followup,later,history',
            'readiness' => 'nullable|in:all,ready,needs_evidence,needs_research,operational_followup,source_review',
            'q' => 'nullable|string|max:300', 'type' => 'nullable|string|max:80',
            'offset' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        return response()->json($this->desk->items($filters));
    }

    public function show(string $reference): JsonResponse
    {
        $item = $this->desk->lookup($reference);
        if ($item === null) {
            $item = $this->desk->materializeReference($reference);
        }
        abort_if($item === null, 404, 'Review reference not found.');

        return response()->json($item);
    }

    public function decide(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject,skip,followup,reopen,refresh_region,link,false_detection,retry_scan_year_write',
            'revision' => 'required|string|max:128', 'idempotency_key' => 'required|string|max:128',
            'notes' => 'nullable|string|max:10000', 'reason_code' => 'nullable|string|max:100',
            'decision_scope' => 'required_if:action,retry_scan_year_write|nullable|string|max:100', 'return_condition' => 'nullable|string|max:2000',
            'person_id' => 'required_if:action,link|prohibited_unless:action,link|integer|min:1',
            'source_sha256' => 'required_if:action,refresh_region,false_detection,retry_scan_year_write|required_if:decision_scope,write_scan_estimated_year|prohibited_unless:action,approve,refresh_region,false_detection,retry_scan_year_write|string|regex:/^[a-f0-9]{64}$/D',
            'asset_uuid' => 'required_if:decision_scope,write_scan_estimated_year,retry_original_authorized_scan_estimated_year|prohibited_unless:decision_scope,write_scan_estimated_year,retry_original_authorized_scan_estimated_year|string|uuid',
            'date_decision_id' => 'required_if:action,retry_scan_year_write|prohibited_unless:action,retry_scan_year_write|integer|min:1',
            'authorization_receipt_id' => 'required_if:action,retry_scan_year_write|prohibited_unless:action,retry_scan_year_write|string|uuid',
            'face_region_convention' => 'required_if:action,refresh_region,false_detection|prohibited_unless:action,refresh_region,false_detection|in:normalized_top_left',
            'region' => 'required_if:action,refresh_region,false_detection|prohibited_unless:action,refresh_region,false_detection|array:x,y,w,h',
            'region.x' => 'required_with:region|numeric|min:0|max:1', 'region.y' => 'required_with:region|numeric|min:0|max:1',
            'region.w' => 'required_with:region|numeric|gt:0|max:1', 'region.h' => 'required_with:region|numeric|gt:0|max:1',
        ]);
        abort_if(array_diff(array_keys($request->all()), array_keys($validated)) !== [], 422, 'Unsupported decision fields.');
        try {
            $result = $this->desk->decide($reference, $validated, $request->user()?->email ?? 'operator');
        } catch (HttpException $exception) {
            return response()->json(['success' => false, 'receipt' => null, 'error' => $exception->getMessage()], $exception->getStatusCode());
        }
        $state = $result['receipt']['state'];
        $status = $result['success'] ? 200 : ($result['receipt']['http_status'] ?? (in_array($state, ['processing', 'unknown'], true) ? 409 : 422));

        return response()->json($result, $status);
    }

    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject,skip,followup', 'notes' => 'nullable|string|max:10000',
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'required|array:reference,revision,idempotency_key',
            'items.*.reference' => 'required|string|max:240', 'items.*.revision' => 'required|string|max:128',
            'items.*.idempotency_key' => 'required|string|max:128',
        ]);

        return response()->json($this->desk->batch($validated, $request->user()?->email ?? 'operator'));
    }
}
