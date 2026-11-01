<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        $employees = Employee::query()
            ->orderBy('employee_number')
            ->get(['employee_number', 'name']);

        return response()->json(['employees' => $employees]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $employee = Employee::query()->updateOrCreate(
            ['employee_number' => $data['employee_number']],
            ['name' => $data['name']],
        );

        return response()->json([
            'employee' => $employee->only(['employee_number', 'name']),
        ]);
    }
}
