<?php

namespace Chocoholics\LaravelElasticEmail;

use Illuminate\Mail\MailServiceProvider as LaravelMailServiceProvider;

class MailServiceProvider extends LaravelMailServiceProvider
{
	/**
     * Register the Swift Transport instance.
     *
     * @return void
     */
    protected function registerSwiftTransport()
    {
        $this->app['swift.transport'] = $this->app->share(function ($app) {
            return new TransportManager($app);
        });
    }
}
