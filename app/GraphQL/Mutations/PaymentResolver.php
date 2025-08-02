<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Services\AuthService;
use App\Services\ZalopayService;
use App\GraphQL\Traits\GraphQLResponse;
use App\Models\Payment;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use App\Services\VNPayService;
use App\Services\StripeService;
use App\GraphQL\Enums\PaymentStatus;
use App\GraphQL\Enums\OrderStatus;
use App\GraphQL\Enums\PaymentMethod;
use App\GraphQL\Enums\ShippingStatus;

final class PaymentResolver
{
    use GraphQLResponse;
    
    /** @param  array{}  $args */
    protected ZalopayService $zalopayService;
    protected VNPayService $vnpayService;
    protected StripeService $stripeService;

    public function __construct(ZalopayService $zalopayService, VNPayService $vnpayService, StripeService $stripeService)
    {
        $this->zalopayService = $zalopayService;
        $this->vnpayService = $vnpayService;
        $this->stripeService = $stripeService;
    }
    
    public function createPaymentZalopay($_, array $args)
    {
        $user= auth('api')->user();

        
        if(!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }
        
        $order = Order::with(['items.product'], 'user')
                      ->where('id', $args['order_id'])
                      ->first();
                      
        if(!$order){
            return $this->error('Order not found', 404);
        }
        
        // Check if user can create payment for this order using policy
        if (Gate::denies('create', [Payment::class, $order])) {
            return $this->error('You are not authorized to create payment for this order', 403);
        }
        
        // Check if payment already exists for this order
        $existingPayment = Payment::where('order_id', $args['order_id'])
                                ->whereIn('payment_status', [PaymentStatus::PENDING, PaymentStatus::COMPLETED])
                                ->first();
                                
        if ($existingPayment) {
            return $this->error('Payment already exists for this order', 400);
        }
        
        $callbackUrl = route('payment.callback');
        $returnUrl = route('payment.return');

        $result = $this->zalopayService->createPaymentOrder($order, $callbackUrl, $returnUrl);
        Log::info($result);
        
        // Handle ZaloPay API response
        if ($result['return_code'] !== 1) {
            return $this->error($result['return_message'], 400);
        }
        
        $payment = Payment::create([
            'order_id' => $args['order_id'],
            'amount' => $order->total_price,
            'payment_method' => 'zalopay',
            'payment_status' => PaymentStatus::PENDING,
            'transaction_id' => $result['app_trans_id'] ?? $this->generateTransactionId('ZP'),
        ]);
        
        return $this->success([
            'payment_url' => $result['order_url'] ?? null,
            'transaction_id' => $payment->transaction_id,
        ], 'Payment created successfully', 200);
    }
    
    public function createPaymentCOD($_, array $args)
    {
        $user= auth('api')->user();
        
        if(!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }
        
        $order = Order::where('id', $args['order_id'])->first();
        
        if(!$order){
            return $this->error('Order not found', 404);
        }
        
        // Check if user can create payment for this order using policy
        if (Gate::denies('create', [Payment::class, $order])) {
            return $this->error('You are not authorized to create payment for this order', 403);
        }
        
        // Check if payment already exists for this order
        $existingPayment = Payment::where('order_id', $args['order_id'])
                                ->whereIn('payment_status', [PaymentStatus::PENDING, PaymentStatus::COMPLETED])
                                ->first();
                                
        if ($existingPayment) {
            return $this->error('Payment already exists for this order', 400);
        }

        $payment = Payment::create([
            'order_id' => $args['order_id'],
            'amount' => $order->total_price,
            'payment_method' => 'cod',
            'payment_status' => PaymentStatus::PENDING,
            'transaction_id' => $this->generateTransactionId('COD'),
        ]);

        $order->status = OrderStatus::CONFIRMED;
        $order->save();
        
        return $this->success([
            'transaction_id' => $payment->transaction_id,
        ], 'Payment created successfully', 200);
    }
    public function createPaymentVNPay($_, array $args)
    {
        $user= auth('api')->user();

        if(!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }

        $order = Order::where('id', $args['order_id'])->first();

        if(!$order){
            return $this->error('Order not found', 404);
        }
        
        // Check if user can create payment for this order using policy
        if (Gate::denies('create', [Payment::class, $order])) {
            return $this->error('You are not authorized to create payment for this order', 403);
        }
        
        // Check if payment already exists for this order
        $existingPayment = Payment::where('order_id', $args['order_id'])
                                ->whereIn('payment_status', [PaymentStatus::PENDING, PaymentStatus::COMPLETED])
                                ->first();
                                
        if ($existingPayment) {
            return $this->error('Payment already exists for this order', 400);
        }

        //Create payment using VNPay
        $payment = Payment::create([
            'order_id' => $args['order_id'],
            'amount' => $order->total_price,
            'payment_method' => 'vnpay',
            'payment_status' => PaymentStatus::PENDING,
            'transaction_id' => $this->generateTransactionId('VNP'),
        ]);

        $paymentUrl = $this->vnpayService->createPayment([
            'order_id' => $payment->transaction_id,
            'amount' => $order->total_price,
            'order_info' => 'Payment for order #' . $payment->transaction_id,
            'locale' => 'vn',
            'bank_code' => $args['bank_code'] ?? '',
            'order_type' => $args['order_type'] ?? 'other',
        ]);

        return $this->success([
            'payment_url' => $paymentUrl,
            'transaction_id' => $payment->transaction_id,
        ], 'Payment created successfully', 200);
    }

    public function VNPayIPN($_, array $args)
    {
        if (!$this->vnpayService->validateReturn($args)) {
            return $this->error('Invalid IPN data', 400);
        }

        if (!isset($args['vnp_ResponseCode'])) {
            return $this->error('vnp_ResponseCode is required', 400);
        }

        $payment = Payment::where('transaction_id', $args['vnp_TxnRef'])->first();
        if (!$payment) {
            return $this->error('Payment not found', 404);
        }

        $order = Order::find($payment->order_id);

        if ($args['vnp_ResponseCode'] === '00') {
            // Thành công
            $payment->update([
                'payment_status' => PaymentStatus::COMPLETED,
            ]);
            if ($order && $order->status === OrderStatus::PENDING) {
                $order->status = OrderStatus::CONFIRMED;
                $order->save();
            }
            return $this->success([
                'transaction_id' => $payment->transaction_id,
            ], 'Payment verified successfully', 200);
        } elseif ($args['vnp_ResponseCode'] === '01') {
            // Thất bại, huỷ đơn
            $payment->update([
                'payment_status' => PaymentStatus::FAILED,
            ]);
            if ($order && $order->status !== OrderStatus::CANCELLED) {
                $order->status = OrderStatus::CANCELLED;
                $order->save();
            }
            return $this->error('Payment failed and order cancelled', 400);
        } else {
            return $this->error('Payment failed', 400);
        }
    }
    
    public function updatePaymentStatus($_, array $args)
    {
        $user= auth('api')->user();

        
        if (!isset($args['payment_id']) || !isset($args['status'])) {
            return $this->error('payment_id and status are required', 400);
        }
        
        $payment = Payment::find($args['payment_id']);
        
        if (!$payment) {
            return $this->error('Payment not found', 404);
        }
        
        // Check if user can update this payment using policy
        if (Gate::denies('update', $payment)) {
            return $this->error('You are not authorized to update this payment', 403);
        }
        
        // Validate status
        $validStatuses = [
            PaymentStatus::PENDING,
            PaymentStatus::COMPLETED,
            PaymentStatus::FAILED,
            PaymentStatus::REFUNDED
        ];
        if (!in_array($args['status'], $validStatuses)) {
            return $this->error('Invalid status. Status must be one of: ' . implode(', ', $validStatuses), 400);
        }
        
        $payment->payment_status = $args['status'];
        $payment->save();
        
        // If payment is completed, update order status if needed
        if ($args['status'] === PaymentStatus::COMPLETED) {
            $order = Order::find($payment->order_id);
            if ($order && $order->status === OrderStatus::PENDING) {
                $order->status = OrderStatus::CONFIRMED;
                $order->save();
            }
        }
        
        return $this->success([
            'payment' => $payment
        ], 'Payment status updated successfully', 200);
    }
    
    public function deletePayment($_, array $args)
    {
        $user= auth('api')->user();

        
        if (!isset($args['payment_id'])) {
            return $this->error('payment_id is required', 400);
        }
        
        $payment = Payment::find($args['payment_id']);
        
        if (!$payment) {
            return $this->error('Payment not found', 404);
        }
        
        // Check if user can delete this payment using policy
        if (Gate::denies('delete', $payment)) {
            return $this->error('You are not authorized to delete this payment', 403);
        }
        // Only allow deletion if payment is pending or failed
        if (!in_array($payment->payment_status, [PaymentStatus::PENDING, PaymentStatus::FAILED])) {
            return $this->error('Cannot delete payments with status: ' . $payment->payment_status, 400);
        }
        
        $payment->delete();
        
        return $this->success([], 'Payment deleted successfully', 200);
    }
     public function createPaymentStripe($_, array $args)
    {
        $user = auth('api')->user();
        
        if (!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }

        $order = Order::find($args['order_id']);
        if (!$order) {
            return $this->error('Order not found', 404);
        }

        if (Gate::denies('create', [Payment::class, $order])) {
            return $this->error('You are not authorized to create payment for this order', 403);
        }

        $existingPayment = Payment::where('order_id', $args['order_id'])
                                ->whereIn('payment_status', [PaymentStatus::PENDING, PaymentStatus::COMPLETED])
                                ->first();
                                
        if ($existingPayment) {
            return $this->error('Payment already exists for this order', 400);
        }

        try {
            $payment = Payment::create([
                'order_id' => $args['order_id'],
                'amount' => $order->total_price,
                'payment_method' => 'stripe',
                'payment_status' => PaymentStatus::PENDING,
                'transaction_id' => $this->generateTransactionId('STRIPE'),
            ]);

            // Create Stripe Payment Intent
            $paymentIntent = $this->stripeService->createPaymentIntent(
                $order->total_price,
                'usd', // or get from order/config
                [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'customer_email' => $user->email,
                ]
            );

            // Update payment with Stripe payment intent ID
            $payment->update([
                'stripe_payment_intent_id' => $paymentIntent->id,
            ]);

            return $this->success([
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
                'transaction_id' => $payment->transaction_id,
            ], 'Stripe Payment Intent created successfully', 200);

        } catch (\Exception $e) {
            Log::error('Stripe Payment Creation Error: ' . $e->getMessage());
            return $this->error('Failed to create Stripe payment: ' . $e->getMessage(), 500);
        }
    }

    public function createStripeCheckoutSession($_, array $args)
    {
        $user = auth('api')->user();
        
        if (!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }

        $order = Order::with('items.product', 'shipping')->find($args['order_id']);
        if (!$order) {
            return $this->error('Order not found', 404);
        }

        // Check authorization
        if (Gate::denies('create', [Payment::class, $order])) {
            return $this->error('You are not authorized to create payment for this order', 403);
        }

        // Check if payment already exists
        $existingPayment = Payment::where('order_id', $args['order_id'])
                                ->whereIn('payment_status', [PaymentStatus::PENDING, PaymentStatus::COMPLETED])
                                ->first();
                                
        if ($existingPayment) {
            return $this->error('Payment already exists for this order', 400);
        }

        try {
            // Prepare line items for Stripe
            $lineItems = [];
            
            // Add order items
            foreach ($order->items as $item) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $item->name ?? 'Product',
                            'description' => 'Order item from E-commerce store',
                        ],
                        'unit_amount' => (int)($item->price * 100), // Convert to cents
                    ],
                    'quantity' => $item->quantity,
                ];
            }

            // Add shipping if exists
            if ($order->shipping && $order->shipping->shipping_fee > 0) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => 'Shipping Fee',
                            'description' => 'Delivery charge',
                        ],
                        'unit_amount' => (int)($order->shipping->shipping_fee * 100),
                    ],
                    'quantity' => 1,
                ];
            }

            // Ensure we have at least one line item
            if (empty($lineItems)) {
                return $this->error('No items found in order', 400);
            }

            // Create payment record
            $payment = Payment::create([
                'order_id' => $args['order_id'],
                'amount' => $order->total_price,
                'payment_method' => 'stripe',
                'payment_status' => PaymentStatus::PENDING,
                'transaction_id' => $this->generateTransactionId('STRIPE'),
            ]);

            // Create Stripe Checkout Session
            $session = $this->stripeService->createCheckoutSession(
                $lineItems,
                $order->id,
                $args['success_url'] ?? null,
                $args['cancel_url'] ?? null
            );

            // Update payment with Stripe session ID
            $payment->update([
                'stripe_session_id' => $session->id,
            ]);

            return $this->success([
                'session_id' => $session->id,
                'checkout_url' => $session->url,
                'transaction_id' => $payment->transaction_id,
            ], 'Stripe Checkout Session created successfully', 200);

        } catch (\Exception $e) {
            Log::error('Stripe Checkout Session Error: ' . $e->getMessage());
            return $this->error('Failed to create Stripe checkout session: ' . $e->getMessage(), 500);
        }
    }

    public function confirmStripePayment($_, array $args)
    {
        if (!isset($args['payment_intent_id'])) {
            return $this->error('payment_intent_id is required', 400);
        }

        try {
            $paymentIntent = $this->stripeService->retrievePaymentIntent($args['payment_intent_id']);
            
            // Find payment record
            $payment = Payment::where('stripe_payment_intent_id', $args['payment_intent_id'])->first();
            if (!$payment) {
                return $this->error('Payment not found', 404);
            }

            $order = Order::find($payment->order_id);
            
            // Update payment status based on Stripe payment intent status
            switch ($paymentIntent->status) {
                case 'succeeded':
                    $payment->update([
                        'payment_status' => PaymentStatus::COMPLETED,
                        'payment_time' => now(),
                    ]);
                    
                    if ($order && $order->status === OrderStatus::PENDING) {
                        $order->status = OrderStatus::CONFIRMED;
                        $order->save();
                    }
                    
                    return $this->success([
                        'payment_status' => 'completed',
                        'transaction_id' => $payment->transaction_id,
                    ], 'Payment confirmed successfully', 200);
                    
                case 'requires_payment_method':
                case 'requires_confirmation':
                    return $this->success([
                        'payment_status' => 'pending',
                        'transaction_id' => $payment->transaction_id,
                    ], 'Payment requires action', 200);
                    
                case 'canceled':
                case 'payment_failed':
                    $payment->update([
                        'payment_status' => PaymentStatus::FAILED,
                    ]);
                    
                    if ($order && $order->status !== OrderStatus::CANCELLED) {
                        $order->status = OrderStatus::CANCELLED;
                        $order->save();
                    }
                    
                    return $this->error('Payment failed', 400);
                    
                default:
                    return $this->success([
                        'payment_status' => $paymentIntent->status,
                        'transaction_id' => $payment->transaction_id,
                    ], 'Payment status: ' . $paymentIntent->status, 200);
            }

        } catch (\Exception $e) {
            Log::error('Stripe Payment Confirmation Error: ' . $e->getMessage());
            return $this->error('Failed to confirm payment: ' . $e->getMessage(), 500);
        }
    }


    
    private function generateTransactionId($method='COD')
    {
        return $method . time() . rand(1000, 9999);
    }
}