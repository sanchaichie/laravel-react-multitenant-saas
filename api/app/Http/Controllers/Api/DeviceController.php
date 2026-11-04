<?php

namespace App\Http\Controllers\Api;

use App\Actions\Account\RevokeDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The logged-in user's API tokens, as signed-in devices. Another user's token is a 404.
 */
class DeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tokens = $request->user()->tokens()
            ->orderByRaw('last_used_at desc nulls last')
            ->latest('id')
            ->limit(100)
            ->get();

        return DeviceResource::collection($tokens);
    }

    public function destroy(Request $request, int $device, RevokeDevice $action): Response
    {
        $action->handle($request->user(), $device);

        return response()->noContent();
    }
}
