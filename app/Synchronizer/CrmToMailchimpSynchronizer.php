<?php


namespace App\Synchronizer;


use App\Http\Controllers\RestApi\CrmClient;
use App\Http\Controllers\RestApi\MailChimpClient;
use App\Revision;
use App\Synchronizer\Mapper\Mapper;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class CrmToMailchimpSynchronizer {
	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var string
	 */
	private $configName;

	/**
	 * @var CrmClient
	 */
	private $crmClient;

	/**
	 * @var MailChimpClient
	 */
	private $mailchimpClient;

	/**
	 * Synchronizer constructor.
	 *
	 * @param string $configFileName file name of the config file
	 *
	 * @throws \App\Exceptions\ConfigException
	 * @throws \Exception
	 */
	public function __construct( string $configFileName ) {
		$this->config     = new Config( $configFileName );
		$this->configName = $configFileName;

		$crmCred         = $this->config->getCrmCredentials();
		$this->crmClient = new CrmClient( (int) $crmCred['clientId'], $crmCred['clientSecret'], $crmCred['url'] );

		$mcCred                = $this->config->getMailchimpCredentials();
		$this->mailchimpClient = new MailChimpClient( $mcCred['apikey'], $this->config->getMailchimpListId() );
	}

	/**
	 * Get latest changes from crm and push them into mailchimp.
	 *
	 * To surpass timeouts, this method only gets up to $limit records
	 * at a time (starting at $offset). This is repeated
	 * until there is no new data any more.
	 *
	 * To keep track of the changes already synced, the revision id of
	 * the crm is used in conjunction with this app's revision model.
	 *
	 * @param int $limit number of records to sync at a time
	 * @param int $offset number of records to skip
	 *
	 * @throws \App\Exceptions\ConfigException
	 * @throws \App\Exceptions\ParseCrmDataException
	 * @throws RequestException
	 * @throws \Exception
	 */
	public function syncAllChanges( int $limit = 100, int $offset = 0 ) {
		// get revision id of last successful sync (or -1 if first run)
		$revId = $this->getLatestSuccessfullSyncRevisionId();

		if ( 0 === $offset ) {
			Log::debug( sprintf(
				"Starting to sync all changes from crm into mailchimp\nConfig: %s\nId of last successfully synced revision: %d",
				$this->configName,
				$revId
			) );

			// get latest revision id and store it in the local database
			$this->openNewRevision();
		}

		$filter = new Filter( $this->config->getFieldMaps(), $this->config->getSyncAll() );
		$mapper = new Mapper( $this->config->getFieldMaps() );

		$mcCrmIdFieldKey = $this->config->getMailchimpKeyOfCrmId();

		while ( true ) {
			// get changed members
			$get     = $this->crmClient->get( "member/changed/$revId/$limit/$offset" );
			$crmData = json_decode( (string) $get->getBody(), true );

			// base case: everything worked well. update revision id
			if ( empty( $crmData ) ) {
				$this->closeOpenRevision();

				Log::debug( sprintf(
					"Sync for config %s successful.",
					$this->configName
				) );

				return;
			}

			foreach ( $crmData as $crmId => $record ) {
				// delete the once that were deleted in the crm
				if ( null === $record ) {
					$email = $this->mailchimpClient->getSubscriberEmailByCrmId( (string) $crmId, $mcCrmIdFieldKey );

					if ( $email ) {
						$this->mailchimpClient->deleteSubscriber( $email );
					}

					unset( $crmData[ $crmId ] );
					continue;
				}

				// get the master record
				$get  = $this->crmClient->get( "member/$crmId/main" );
				$main = json_decode( (string) $get->getBody(), true );
				unset( $crmData[ $crmId ] );
				$crmData += $main;
			}

			// only process the relevant datasets
			$relevantRecords = $filter->filter( $crmData );

			// map crm data to mailchimp data and store them in mailchimp
			// don't use mailchimps batch operations, because they are async
			foreach ( $relevantRecords as $crmRecord ) {
				$mcRecord = $mapper->crmToMailchimp( $crmRecord );
				$this->mailchimpClient->putSubscriber( $mcRecord ); // let it fail hard, for the moment
			}

			// remove all subscribers that unsubscribed via crm
			$rejectedRecords = $filter->getRejected();
			foreach ( $rejectedRecords as $crmId => $record ) {
				$email = $this->mailchimpClient->getSubscriberEmailByCrmId( (string) $crmId, $mcCrmIdFieldKey );
				if ( $email ) {
					$this->mailchimpClient->deleteSubscriber( $email );
				}
			}

			Log::debug( sprintf(
				"Sync of records %d up to %d for config %s successful. Requesting next batch.",
				$offset,
				$offset + $limit,
				$this->configName
			) );

			// get next batch
			$offset += $limit;
		}
	}

	/**
	 * Add current crm revision id to the database, marked as none synced
	 *
	 * Make sure there is only one open revision per user. (if there were
	 * existing ones, a previous sync must have failed. lets resync all
	 * records then, so we have a self healing approach).
	 *
	 * @throws RequestException
	 */
	private function openNewRevision() {
		// delete old open revisions (from failed syncs)
		$this->deleteOpenRevisions();

		// get current revision id from crm
		$get         = $this->crmClient->get( 'revision' );
		$latestRevId = (int) json_decode( (string) $get->getBody() );

		// add current revision
		$latestRev                  = new Revision();
		$latestRev->config_name     = $this->configName;
		$latestRev->revision_id     = $latestRevId;
		$latestRev->sync_successful = false;
		$latestRev->save();

		Log::debug( sprintf(
			'Opening revision %d for config %s',
			$latestRev->revision_id,
			$this->configName
		) );
	}

	/**
	 * Delete all open revisions and log it
	 */
	private function deleteOpenRevisions() {
		$openRevisions = Revision::where( 'config_name', $this->configName )
		                         ->where( 'sync_successful', false );

		$count = $openRevisions->count();

		if ( $count ) {
			$openRevisions->delete();

			Log::notice( sprintf(
				'%d failed revisions for config %s were deleted.',
				$count,
				$this->configName
			) );
		}
	}

	/**
	 * Mark the open revision as successfully synced
	 */
	private function closeOpenRevision() {
		$revision = Revision::where( 'config_name', $this->configName )
		                    ->where( 'sync_successful', false )
		                    ->latest()
		                    ->firstOrFail(); // else die hard

		$revision->sync_successful = true;
		$revision->save();

		Log::debug( sprintf(
			'Closing revision %d for config %s',
			$revision->revision_id,
			$this->configName
		) );
	}

	/**
	 * Return the id of the latest successful revision
	 *
	 * @return int
	 */
	private function getLatestSuccessfullSyncRevisionId(): int {
		try {
			return Revision::where( 'config_name', $this->configName )
			               ->where( 'sync_successful', true )
			               ->latest()
			               ->firstOrFail()
				->revision_id;
		} catch ( ModelNotFoundException $e ) {
			return - 1;
		}
	}
}