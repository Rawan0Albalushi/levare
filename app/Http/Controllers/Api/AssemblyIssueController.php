<?php

namespace App\Http\Controllers\Api;

use App\Actions\InsufficientAssemblyStock;
use App\Actions\IssueAssemblyStock;
use App\Http\Controllers\Controller;
use App\Models\Assembly;
use App\Models\PartIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssemblyIssueController extends Controller
{
    public function index(Assembly $assembly): JsonResponse
    {
        $issues = PartIssue::query()
            ->where('assembly_part_number', $assembly->part_number)
            ->with('lines.allocations')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (PartIssue $issue): array => $issue->toDocument())
            ->values();

        return response()->json(['issues' => $issues]);
    }

    public function store(Request $request, Assembly $assembly, IssueAssemblyStock $action): JsonResponse
    {
        $data = $request->validate([
            'positions' => ['required', 'array', 'min:1', 'max:200'],
            'positions.*' => ['integer', 'distinct', 'min:1', 'max:65535'],
        ]);

        try {
            $issue = $action->issue($assembly, $data['positions']);
        } catch (InsufficientAssemblyStock $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'short' => $exception->short,
            ], 422);
        }

        return response()->json(['issue' => $issue->toDocument()], 201);
    }
}
