<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ListPlatformAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PlatformAuditLogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform admin: the platform audit log, newest first, in cursor pages.
 */
class AuditLogController extends Controller
{
    public function index(ListPlatformAuditLog $action): AnonymousResourceCollection
    {
        return PlatformAuditLogResource::collection($action->handle());
    }
}
