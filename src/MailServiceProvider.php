<?php

namespace Chocoholics\LaravelElasticEmail;

use Illuminate\Mail\MailServiceProvider as MailServiceProvider;

class MailServiceProvider extends MailServiceProvider
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
