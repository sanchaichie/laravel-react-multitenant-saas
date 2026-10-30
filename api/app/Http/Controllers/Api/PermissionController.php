<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The permission catalogue, so the roles page can label and group its checkboxes.
 */
class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (Permission $permission) => [
                'value' => $permission->value,
                'label' => $permission->label(),
                'group' => $permission->group(),
            ], Permission::cases()),
        ]);
    }
}
