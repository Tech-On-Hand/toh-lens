<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComputerController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        /** @var Computer $computer */
        $computer = $request->user();

        return response()->json([
            'id' => $computer->id,
            'school_id' => $computer->school_id,
            'class_id' => $computer->class_id,
            'name' => $computer->name,
            'role' => $computer->role,
        ]);
    }
}
