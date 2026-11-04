<?php

namespace App\Http\Controllers\Api;

use App\Actions\Billing\BillingOverview;
use App\Actions\Billing\ListInvoices;
use App\Actions\Billing\OpenBillingPortal;
use App\Actions\Billing\StartCreditsCheckout;
use App\Actions\Billing\StartSubscriptionCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ListInvoicesRequest;
use App\Http\Requests\Billing\StartCheckoutRequest;
use App\Http\Requests\Billing\StartCreditsCheckoutRequest;
use App\Http\Resources\InvoiceResource;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Billing of the current organization. Payments happen on Stripe's hosted pages;
 * these endpoints only return their URLs, and the webhook records what was paid.
 * Requests with a body authorize in their Form Request, so a 403 comes before a 422.
 */
class BillingController extends Controller
{
    public function show(BillingOverview $overview, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('manageBilling');

        return response()->json(['data' => $overview->handle($currentOrganization->get())]);
    }

    public function checkout(StartCheckoutRequest $request, StartSubscriptionCheckout $action, CurrentOrganization $currentOrganization): JsonResponse
    {
        $url = $action->handle($currentOrganization->get(), $request->user(), $request->validated('plan'));

        return response()->json(['data' => ['url' => $url]]);
    }

    public function creditsCheckout(StartCreditsCheckoutRequest $request, StartCreditsCheckout $action, CurrentOrganization $currentOrganization): JsonResponse
    {
        $url = $action->handle($currentOrganization->get(), $request->user(), $request->validated('pack'));

        return response()->json(['data' => ['url' => $url]]);
    }

    public function portal(OpenBillingPortal $action, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('manageBilling');

        return response()->json(['data' => ['url' => $action->handle($currentOrganization->get())]]);
    }

    /**
     * The organization's invoices, read live from Stripe, 20 at a time.
     */
    public function invoices(ListInvoicesRequest $request, ListInvoices $action, CurrentOrganization $currentOrganization): AnonymousResourceCollection
    {
        $page = $action->handle($currentOrganization->get(), $request->validated('starting_after'));

        return InvoiceResource::collection($page->invoices)->additional(['meta' => ['next_cursor' => $page->nextCursor]]);
    }
}
