<?php


namespace App\Synchronizer;


use App\Exceptions\EmailComplianceException;
use App\Exceptions\InvalidEmailException;
use App\Exceptions\MailchimpClientException;
use App\Exceptions\MemberDeleteException;
use App\Http\CrmClient;
use App\Http\MailChimpClient;
use App\Revision;
use App\Synchronizer\Mapper\Mapper;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class CrmToMailchimpSynchronizer {
	private const LOCK_BASE_FOLDER_NAME = 'locks';

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
	 * @var Filter
	 */
	private $filter;

	/**
	 * @var Mapper
	 */
	private $mapper;

	/**
	 * @var string
	 */
	private $lockPath;

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

		$this->filter = new Filter( $this->config->getFieldMaps(), $this->config->getSyncAll() );
		$this->mapper = new Mapper( $this->config->getFieldMaps() );

		$this->lockPath = storage_path() . self::LOCK_BASE_FOLDER_NAME;
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
	 * This method prevents concurrent runs with the same config,
	 * to eliminate race conditions on the records to sync and to
	 * avoid to start the same sync job from the same revision
	 * multiple times if one is still running.
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
		if ( ! $this->lock() ) {
			Log::info( "There is already a synchronization for {$this->configName} running. Start of new sync job canceled." );

			return;
		}

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

		while ( true ) {
			// get changed members
			$get     = $this->crmClient->get( "member/changed/$revId/$limit/$offset" );
			$crmData = json_decode( (string) $get->getBody(), true );

			// base case: everything worked well. update revision id
			if ( empty( $crmData ) ) {
				Log::debug( "Everything synced." );
				$this->closeOpenRevision();
				Log::debug( "Sync for config {$this->configName} successful." );

				return;
			}

			// sync members to mailchimp
			foreach ( $crmData as $crmId => $record ) {
				// don't use mailchimps batch operations, because they are async
				$this->syncSingleRetry( $crmId, $record );
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

		$this->unlock();
	}

	/**
	 * Lock process with this config file. If already locked, false is returned.
	 *
	 * We bind it to the config file, so only one process per config can sync at a
	 * time, but if there are multiple different configs, they may sync at in parallel.
	 *
	 * Since we can't use semaphores (hosting too cheap), we fallback to the folder
	 * trick, to prevent any race conditions.
	 *
	 * @return bool
	 *
	 * @throws \Exception
	 */
	public function lock(): bool {
		if ( ! is_dir( $this->lockPath ) ) {
			$create = mkdir( $this->lockPath, 751 );
			if ( ! $create ) {
				throw new \Exception( 'Lock folder did not exist and could not be created.' );
			}
		}

		$lock = mkdir( "{$this->lockPath}/{$this->configName}.lock", 700 );

		return $lock;
	}

	/**
	 * Remove lock folder.
	 */
	public function unlock() {
		rmdir( "{$this->lockPath}/{$this->configName}.lock" );
	}

	/**
	 * Try to sync the given record to mailchimp. Retry twice if it fails due to a MailchimpClientException.
	 *
	 * @param $crmId
	 * @param $record
	 * @param int $attempt
	 *
	 * @throws \App\Exceptions\ConfigException
	 * @throws \App\Exceptions\ParseCrmDataException
	 */
	private function syncSingleRetry( $crmId, $record, $attempt = 0 ) {
		try {
			$this->syncSingle( $crmId, $record );
		} catch ( MailchimpClientException $e ) {
			switch ( $attempt ) {
				case 0:
					sleep( 10 );
					$this->syncSingleRetry( $crmId, $record, 1 );

					return;

				case 1:
					sleep( 30 );
					$this->syncSingleRetry( $crmId, $record, 2 );

					return;

				default:
					Log::error( "Failed to sync record $crmId to Mailchimp after tree attempts. Error: {$e->getMessage()}" );
			}
		} catch ( EmailComplianceException $e ) {
			Log::info( "This record is in a compliance state due to unsubscribe, bounce or compliance review and cannot be subscribed." );
		} catch ( MemberDeleteException $e ) {
			Log::info( $e->getMessage() );
		}
	}

	/**
	 * @param int $crmId
	 * @param array|null $crmData
	 *
	 * @throws \App\Exceptions\ConfigException
	 * @throws \App\Exceptions\ParseCrmDataException
	 * @throws \App\Exceptions\MailchimpClientException
	 * @throws \App\Exceptions\EmailComplianceException
	 * @throws \App\Exceptions\MemberDeleteException
	 */
	private function syncSingle( int $crmId, $crmData ) {
		Log::debug( "Start syncing record with id: $crmId" );

		$mcCrmIdFieldKey = $this->config->getMailchimpKeyOfCrmId();
		$mcEmail         = $this->mailchimpClient->getSubscriberEmailByCrmId( (string) $crmId, $mcCrmIdFieldKey );

		// if the record was deleted in the crm
		if ( null === $crmData ) {
			Log::debug( "Record was deleted in crm." );

			if ( $mcEmail ) {
				$this->mailchimpClient->deleteSubscriber( $mcEmail );
				Log::debug( "Record deleted in mailchimp." );
			} else {
				Log::debug( "Record not present in mailchimp." );
			}

			return;
		}

		// skip if record has no email address and isn't in mailchimp yet
		if ( ! $mcEmail && empty( $crmData[ $this->config->getCrmEmailKey() ] ) ) {
			Log::debug( "Record skipped (not in mailchimp and has no email address)." );

			return;
		}

		// get the master record
		$get    = $this->crmClient->get( "member/$crmId/main" );
		$main   = json_decode( (string) $get->getBody(), true );
		$mainId = $main[ Config::getCrmIdKey() ];
		$email  = $this->mailchimpClient->getSubscriberEmailByCrmId( (string) $mainId, $mcCrmIdFieldKey );
		if ( $crmId !== $mainId ) {
			Log::debug( "Found main record. ID: $mainId" );
		} else {
			Log::debug( "This record seems to be the main record." );
		}

		// remove all subscribers that unsubscribed via crm
		if ( ! $this->filter->filterSingle( $main ) ) {
			if ( $email ) {
				$this->mailchimpClient->deleteSubscriber( $email );
				Log::debug( "Filter criteria not met: Record deleted in Mailchimp." );
			} else {
				Log::debug( "Filter criteria not met: Record not present in Mailchimp." );
			}

			return;
		}

		$mcRecord = $this->mapper->crmToMailchimp( $main );

		// handle records already subscribed to mailchimp
		// where the email address has changed in the crm
		if ( $email && $email !== $main['email1'] ) {
			try {
				$this->mailchimpClient->putSubscriber( $mcRecord, $email );
				Log::debug( "Email address has changed in crm. Updated record in mailchimp." );
			} catch ( InvalidEmailException $e ) {
				Log::info( "Email address has changed in crm to an INVALID EMAIL. Not updated in Mailchimp." );
			}

			return;
		}

		// map crm data to mailchimp data and store them in mailchimp
		$mcRecord['status'] = 'subscribed'; // handles re-subscriptions
		try {
			$this->mailchimpClient->putSubscriber( $mcRecord );
			Log::debug( "Record synchronized to mailchimp." );
		} catch ( InvalidEmailException $e ) {
			Log::info( "INVALID EMAIL. Record skipped." );
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