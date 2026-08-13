<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RazorpayRoutesTest extends TestCase
{
    public function test_razorpay_checkout_creation_and_payment_capture_have_unique_names(): void
    {
        $createOrder = Route::getRoutes()->getByName('api.razorpay.create_order');
        $payment = Route::getRoutes()->getByName('api.razorpay.payment');

        $this->assertNotNull($createOrder);
        $this->assertNotNull($payment);
        $this->assertSame('api/v2/razorpay/pay-with-razorpay', $createOrder->uri());
        $this->assertSame('api/v2/razorpay/payment', $payment->uri());
        $this->assertStringContainsString('payWithRazorpay', $createOrder->getActionName());
        $this->assertStringContainsString('@payment', $payment->getActionName());
    }
}
