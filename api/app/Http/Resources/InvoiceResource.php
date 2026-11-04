<?php

namespace App\Http\Resources;

use App\Billing\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'total' => $this->total,
            'currency' => $this->currency,
            'description' => $this->description,
            'created_at' => $this->createdAt->toIso8601ZuluString(),
            'hosted_url' => $this->hostedUrl,
            'pdf_url' => $this->pdfUrl,
        ];
    }
}
