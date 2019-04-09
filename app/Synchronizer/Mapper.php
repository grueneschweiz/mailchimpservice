<?php


namespace App\Synchronizer;


class Mapper {
	/**
	 * @var Config
	 */
	private $config;

	/**
	 * Mapper constructor.
	 *
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Map webhook data from mailchimp so we can save it to the crm
	 *
	 * @param array $mailchimpData
	 *
	 * @return array
	 */
	public function mailchimpToCrm( array $mailchimpData ) {

	}

	/**
	 * Map crm data to an array we can send to mailchimps PUT endpoint
	 *
	 * @param array $crmData
	 *
	 * @return array
	 */
	public function crmToMailchimp( array $crmData ) {

	}
}