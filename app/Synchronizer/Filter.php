<?php


namespace App\Synchronizer;


use App\Synchronizer\Mapper\FieldMapFacade;
use App\Synchronizer\Mapper\FieldMaps\FieldMapGroup;

class Filter {
	private const CRM_RECORD_STATUS_KEY = 'recordStatus';
	private const CRM_RECORD_STATUS_ACTIVE = 'active';

	private const CRM_EMAIL_STATUS_KEY = 'emailStatus';
	private const CRM_EMAIL_STATUS_ACTIVE = 'active';

	/**
	 * @var FieldMapFacade[]
	 */
	private $fieldMaps;

	/**
	 * @var string
	 */
	private $emailKey;

	/**
	 * Don't consider the subscriptions in the filter
	 *
	 * @var bool
	 */
	private $syncAll;

	/**
	 * The fields of type group
	 *
	 * @var array
	 */
	private $groups;

	/**
	 * Filter constructor.
	 *
	 * @param FieldMapFacade[] $fieldMaps
	 * @param bool $syncAll true indicates that the filter should not consider the subscriptions
	 */
	public function __construct( array $fieldMaps, bool $syncAll = false ) {
		$this->fieldMaps = $fieldMaps;
		$this->syncAll   = $syncAll;
	}

	/**
	 * Filter the given data to return only the records with relevant data
	 *
	 * Takes the field mappings to determine, what records are relevant
	 *
	 * @param array $crmData
	 *
	 * @return array
	 */
	public function filter( array $crmData ): array {
		return array_filter( $crmData, [ $this, 'filterSingle' ] );
	}

	/**
	 * Check if record has relevant data
	 *
	 * @param array $record single crm record
	 *
	 * @return bool
	 *
	 * @throws \App\Exceptions\ParseCrmDataException
	 */
	private function filterSingle( array $record ): bool {
		// record status
		$status = $record[ self::CRM_RECORD_STATUS_KEY ];
		if ( $status !== self::CRM_RECORD_STATUS_ACTIVE ) {
			return false;
		}

		// valid email
		$email = $record[ $this->getEmailKey() ];
		if ( empty( $email ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}

		// email status
		$emailStatus = $record[ self::CRM_EMAIL_STATUS_KEY ];
		if ( $emailStatus !== self::CRM_EMAIL_STATUS_ACTIVE ) {
			return false;
		}

		// only sync contacts that have any relevant subscriptions
		if ( ! $this->syncAll ) {
			return $this->hasAnySubscriptions( $record );
		}

		return true;
	}

	/**
	 * Get crm key of the email field
	 *
	 * @return string
	 */
	private function getEmailKey() {
		if ( ! $this->emailKey ) {
			foreach ( $this->fieldMaps as $map ) {
				if ( $map->isEmail() ) {
					$this->emailKey = $map->getCrmKey();
				}
			}
		}

		return $this->emailKey;
	}

	/**
	 * Check if the given record has any active subscriptions
	 *
	 * @param array $record
	 *
	 * @return bool
	 *
	 * @throws \App\Exceptions\ParseCrmDataException
	 */
	private function hasAnySubscriptions( array $record ): bool {
		/** @var FieldMapGroup $map */
		foreach ( $this->getGroups() as $map ) {
			$key = $map->getCrmKey();

			if ( ! array_key_exists( $key, $record ) ) {
				continue;
			}

			$map->addCrmData( $record );
			$data    = $map->getMailchimpDataArray();
			$inGroup = reset( $data );

			if ( $inGroup ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the cached config fields of type group
	 *
	 * @return array[]
	 */
	private function getGroups() {
		if ( ! $this->groups ) {
			foreach ( $this->fieldMaps as $map ) {
				if ( $map->isGroup() ) {
					$this->groups[] = $map;
				}
			}
		}

		return $this->groups;
	}
}