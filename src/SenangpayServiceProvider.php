<?php

namespace Jiannius\Senangpay;

use Illuminate\Support\ServiceProvider;

class SenangpayServiceProvider extends ServiceProvider
{
    // register
    public function register() : void
    {
        //
    }

    // boot
    public function boot() : void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->bind('senangpay', fn($app) => new \Jiannius\Senangpay\Senangpay());
    }
}