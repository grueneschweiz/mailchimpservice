<?php

namespace App\Console\Commands;


use App\Exceptions\ConfigException;
use App\MailchimpEndpoint;
use App\Synchronizer\Config;
use Illuminate\Console\Command;

abstract class EndpointCommand extends Command {
	public const MC_ENDPOINT_ROUTE_NAME = 'mailchimp_endpoint';

	/**
	 * The config validation errors
	 *
	 * @var array
	 */
	private $configErrors = [];

	/**
	 * @param int $id
	 *
	 * @return int|MailchimpEndpoint
	 */
	protected function getEndpointById( int $id ) {
		if ( 0 >= $id ) {
			$this->error( '<comment>Invalid id:</comment> ' . $id );

			return false;
		}

		$endpoint = MailchimpEndpoint::find( $id );

		if ( empty( $endpoint ) ) {
			$this->error( '<comment>No endpoint with id:</comment> ' . $id );

			return false;
		}

		return $endpoint;
	}

	/**
	 * Check if config file is valid
	 *
	 * @param string $configFileName
	 *
	 * @return bool
	 */
	protected function isValidConfig( string $configFileName ) {
		try {
			$config = new Config( $configFileName );
		} catch ( ConfigException $e ) {
			$this->error( $e->getMessage() );

			return false;
		}

		if ( $config->isValid() ) {
			return true;
		} else {
			$this->configErrors = $config->getErrors();
		}

		return false;
	}

	/**
	 * Print config validation errors
	 */
	protected function printConfigErrors() {
		if ( $this->configErrors ) {
			$this->error( 'Invalid config file:' );

			foreach ( $this->configErrors as $error ) {
				$this->info( $error );
			}
		}
	}

	/**
	 * Generate new endpoint secret (CPRNG)
	 *
	 * @return string
	 */
	protected function getNewEndpointSecret() {
		return str_random( 22 ); // 64^22 > 2^128
	}
}