<?php

namespace App\Http\Controllers;

use App\DTOs\CartContext;
use App\Enums\CouponStatus;
use App\Http\Requests\ApplyCouponRequest;
use App\Http\Requests\ConsumeCouponRequest;
use App\Http\Requests\ReleaseCouponRequest;
use App\Http\Resources\CouponActionResource;
use App\Http\Resources\CouponApplyResource;
use App\Http\Resources\CouponStatusResource;
use App\Jobs\ConsumeCouponJob;
use App\Jobs\ReleaseCouponJob;
use App\Jobs\ValidateCouponJob;
use App\Messages\CouponMessage;
use App\Services\CouponReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CouponController extends Controller
{
    public function __construct(private readonly CouponReservationService $reservationService) {}

    /**
     * POST /api/coupons/apply
     *
     * Dispatches ValidateCouponJob and returns immediately.
     * The client polls GET /api/coupons/status/{request_id} for the result.
     */
    public function apply(ApplyCouponRequest $request): JsonResponse
    {
        $user = $request->user();

        $idempotencyKey = $this->buildIdempotencyKey(
            $user->id,
            $request->input('coupon_code'),
            $request->input('cart_id'),
        );

        $existing = $this->reservationService->getStatus($idempotencyKey);

        if ($existing && in_array($existing['status'], [
            CouponStatus::Processing->value,
            CouponStatus::Reserved->value,
            CouponStatus::Consumed->value,
        ])) {
            return (new CouponApplyResource($idempotencyKey, $existing['status'], $existing['message']))
                ->response();
        }

        $cart = CartContext::fromArray([
            'cart_id' => $request->input('cart_id'),
            'user_id' => $user->id,
            'cart_value' => $request->input('cart_value'),
            'item_categories' => $request->input('item_categories', []),
            'product_ids' => $request->input('product_ids', []),
            'user_segments' => $request->input('user_segments', []),
            'is_first_order' => $user->orders()->doesntExist(),
        ]);

        ValidateCouponJob::dispatch(
            couponCode: $request->input('coupon_code'),
            cart: $cart,
            idempotencyKey: $idempotencyKey,
        );

        return (new CouponApplyResource($idempotencyKey, CouponStatus::Processing->value, CouponMessage::VERIFICATION_IN_PROGRESS))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * GET /api/coupons/status/{requestId}
     *
     * Poll for the async validation result.
     */
    public function status(Request $request, string $requestId): JsonResponse
    {
        $status = $this->reservationService->getStatus($requestId);

        if (! $status) {
            return (new CouponStatusResource(['status' => CouponStatus::NotFound->value]))
                ->response()
                ->setStatusCode(Response::HTTP_NOT_FOUND);
        }

        return (new CouponStatusResource($status))->response();
    }

    /**
     * POST /api/coupons/consume
     *
     * Called on successful checkout — permanently records coupon usage.
     */
    public function consume(ConsumeCouponRequest $request): JsonResponse
    {
        $user = $request->user();
        $cart = CartContext::fromArray([
            'cart_id' => $request->input('cart_id'),
            'user_id' => $user->id,
            'cart_value' => $request->input('cart_value'),
            'order_id' => $request->input('order_id'),
        ]);

        ConsumeCouponJob::dispatch(
            couponCode: $request->input('coupon_code'),
            cart: $cart,
            orderId: $request->input('order_id'),
            discountAmount: (float) $request->input('discount_amount'),
            settingVersion: (int) $request->input('setting_version'),
            idempotencyKey: $request->input('request_id'),
        );

        return (new CouponActionResource(CouponMessage::CONSUMPTION_QUEUED))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * POST /api/coupons/release
     *
     * Called on checkout failure or cancellation — frees the reservation.
     */
    public function release(ReleaseCouponRequest $request): JsonResponse
    {
        $user = $request->user();
        $cart = CartContext::fromArray([
            'cart_id' => $request->input('cart_id'),
            'user_id' => $user->id,
            'cart_value' => $request->input('cart_value'),
        ]);

        ReleaseCouponJob::dispatch(
            couponCode: $request->input('coupon_code'),
            cart: $cart,
            idempotencyKey: $request->input('request_id'),
            reason: $request->input('reason', 'checkout_failed'),
        );

        return (new CouponActionResource(CouponMessage::RELEASE_QUEUED))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    private function buildIdempotencyKey(int $userId, string $couponCode, string $cartId): string
    {
        return hash('sha256', "apply_coupon:{$userId}:{$couponCode}:{$cartId}");
    }
}
