<?php

namespace Chocoholics\LaravelElasticEmail;

use Illuminate\Mail\TransportManager as TransportManager;

class TransportManager extends TransportManager
{
	protected function createElasticEmailDriver()
	{
		$config = $this->app['config']->get('services.elastic_email', []);

		return new ElasticTransport(
			$this->getHttpClient($config),
			$config['secret'],
			$config['username']
		);
	}
}
