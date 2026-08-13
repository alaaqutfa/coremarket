<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerificationRoutesTest extends TestCase
{
    public function test_verification_resend_get_and_post_routes_have_distinct_names(): void
    {
        $post = Route::getRoutes()->getByName('verification.resend');
        $get = Route::getRoutes()->getByName('verification.resend.get');

        $this->assertSame('email/resend', $post->uri());
        $this->assertSame('email/resend', $get->uri());
        $this->assertContains('POST', $post->methods());
        $this->assertContains('GET', $get->methods());
    }

    public function test_code_based_password_reset_has_a_distinct_route_name(): void
    {
        $reset = Route::getRoutes()->getByName('password.reset_with_code');

        $this->assertSame('password/reset/email/submit', $reset->uri());
        $this->assertContains('POST', $reset->methods());
        $this->assertStringContainsString('HomeController@reset_password_with_code', $reset->getActionName());
    }
}
