<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Computer;
use App\Support\PolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePolicyController extends ApiController
{
    /** Pass `known` (the last version seen) to get a tiny answer when nothing changed. */
    public function show(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $policy = PolicyResolver::forDevice($device);

        if ($request->query('known') === $policy['version']) {
            return $this->success(['unchanged' => true, 'version' => $policy['version'], 'server_time' => $policy['server_time']]);
        }

        return $this->success($policy);
    }
}
