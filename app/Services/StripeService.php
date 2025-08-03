<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Checkout\Session;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function createPaymentIntent($amount, $currency = 'usd', $metadata = [])
    {
        try {
            return PaymentIntent::create([
                'amount' => $amount * 100, // Convert to cents
                'currency' => $currency,
                'metadata' => $metadata,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe PaymentIntent creation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function createCheckoutSession($lineItems, $orderId, $successUrl = null, $cancelUrl = null)
    {
        try {
            $sessionData = [
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => $successUrl ?? config('services.stripe.success_url') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl ?? config('services.stripe.cancel_url'),
                'metadata' => [
                    'order_id' => $orderId,
                ],
            ];

            return Session::create($sessionData);
        } catch (\Exception $e) {
            Log::error('Stripe Checkout Session creation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function retrievePaymentIntent($paymentIntentId)
    {
        try {
            return PaymentIntent::retrieve($paymentIntentId);
        } catch (\Exception $e) {
            Log::error('Stripe PaymentIntent retrieval failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function retrieveCheckoutSession($sessionId)
    {
        try {
            return Session::retrieve($sessionId);
        } catch (\Exception $e) {
            Log::error('Stripe Session retrieval failed: ' . $e->getMessage());
            throw $e;
        }
    }
}