<?php

declare(strict_types=1);

namespace Modules\Portal\Application\UseCases;

use App\Models\Orders\OrderModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Orders\Application\UseCases\RegisterPayment;
use Modules\Portal\Domain\Exceptions\ReceiptRefused;
use Platform\Tenancy\TenantContext;

/**
 * The customer sends the photo of their mobile payment.
 *
 * The file goes to a PRIVATE disk: a receipt carries the payer's name, ID
 * number, phone and account balance, and a public URL ends up in browser
 * history and in the chat where somebody forwards it.
 *
 * The payment is recorded as pending review, not confirmed: a photo arriving
 * does not mean the money did.
 */
final class AttachReceipt
{
    private const DISK = 'local';

    public function __construct(
        private readonly RegisterPayment $payments,
        private readonly TenantContext $context,
    ) {}

    public function execute(OrderModel $order, UploadedFile $file, ?string $reference = null): OrderModel
    {
        if ($order->status->value !== 'pending_payment') {
            // Already paid, cancelled, or in the kitchen. Another photo changes
            // nothing and would confuse the tenant.
            throw ReceiptRefused::orderNotWaiting();
        }

        /*
         * The path carries the tenant up front: removing a customer is deleting
         * one directory, and a bug that mixed ids would show up immediately.
         */
        $path = $file->store("receipts/{$this->context->id()}/{$order->id}", self::DISK);

        if ($path === false) {
            throw ReceiptRefused::couldNotStore();
        }

        return $this->payments->execute(
            orderId: (string) $order->id,
            method: 'pago_movil',
            // What is left to pay, which on a portal order is all of it.
            amountCents: (int) $order->total_cents - (int) $order->paid_cents,
            reference: $reference,
            receiptUrl: $path,
        );
    }

    /** Where a receipt's file is, to serve it to whoever may view it. */
    public static function disk(): string
    {
        return self::DISK;
    }

    public static function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }
}
