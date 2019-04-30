<?php


namespace App\Synchronizer;


use App\Http\Controllers\RestApi\CrmClient;
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
	 * @var int
	 */
	private $userId;

	/**
	 * @var CrmClient
	 */
	private $crmClient;

	/**
	 * Synchronizer constructor.
	 *
	 * @param Config $config
	 * @param int $userId
	 *
	 * @throws \App\Exceptions\ConfigException
	 */
	public function __construct( Config $config, int $userId ) {
		$this->config = $config;
		$this->userId = $userId;

		$crmCred = $config->getCrmCredentials();

		$this->crmClient = new CrmClient( $crmCred['clientId'], $crmCred['clientSecret'], $crmCred['url'] );
	}

	/**
	 * Get latest changes from crm and push them into mailchimp.
	 *
	 * To surpass timeouts, this method only gets up to $limit records
	 * at a time (starting at $offset). It then restarts itself with a
	 * new request (reset hard server timeout limits). This is repeated
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
	 */
	public function syncAllChanges( int $limit = 100, int $offset = 0 ) {
		// get revision id of last successful sync (or -1 if first run)
		$revId = $this->getLatestSuccessfullSyncRevisionId();

		if ( 0 === $offset ) {
			Log::debug( sprintf(
				"Starting to sync all changes from crm into mailchimp\nUser id: %d\nId of last successfully synced revision: %d",
				$this->userId,
				$revId
			) );

			// get latest revision id and store it in the local database
			$this->openNewRevision();
		}

		// get changed members
		$get     = $this->crmClient->get( "member/changed/$revId/$offset/$limit" );
		$crmData = json_decode( (string) $get->getBody() ); // todo: adapt webling wrapper

		// base case: everything worked well. update revision id
		if ( empty( $crmData ) ) {
			$this->closeOpenRevision();

			Log::debug( sprintf(
				"Sync for user %d successful.",
				$this->userId
			) );

			return;
		}

		// only process the relevant datasets
		$filter          = new Filter( $this->config->getFieldMaps(), $this->config->getSyncAll() );
		$relevantRecords = $filter->filter( $crmData );

		// map crm data to mailchimp data and store them in mailchimp
		// don't use mailchimps batch operations, because they are async
		$mapper          = new Mapper( $this->config->getFieldMaps() );
		$mailchimpClient = new MailChimpClient( $this->config->getMailchimpCredentials() );
		foreach ( $relevantRecords as $crmRecord ) {
			$mcRecord = $mapper->crmToMailchimp( $crmRecord );
			$mailchimpClient->put( $mcRecord ); // let if fail hard, for the moment
		}

		// todo:
		// pass back to controller which should issue
		// a new http request with offset +=100.
		// this is to work around execution limits

		Log::debug( sprintf(
			"Sync of records %d upto %d for user %d successful. Requesting next batch.",
			$offset,
			$offset + $limit,
			$this->userId
		) );
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
		$latestRev                 = new Revision();
		$latestRev->userId         = $this->userId;
		$latestRev->revisionId     = $latestRevId;
		$latestRev->syncSuccessful = false;
		$latestRev->save();

		Log::debug( sprintf(
			'Opening revision %d for user %d',
			$latestRev,
			$this->userId
		) );
	}

	/**
	 * Delete all open revisions and log it
	 */
	private function deleteOpenRevisions() {
		$openRevisions = Revision::where( 'user_id', $this->userId )
		                         ->where( 'sync_successful', false );

		$count = $openRevisions->count();

		if ( $count ) {
			$openRevisions->delete();

			Log::notice( sprintf(
				'%d open (failed) revisions for user %d were deleted.',
				$count,
				$this->userId
			) );
		}
	}

	/**
	 * Mark the open revision as successfully synced
	 */
	private function closeOpenRevision() {
		$revision = Revision::where( 'user_id', $this->userId )
		                    ->where( 'sync_successful', false )
		                    ->latest()
		                    ->firstOrFail(); // else die hard

		$revision->syncSuccessful = true;
		$revision->save();

		Log::debug( sprintf(
			'Closing revision %d for user %d',
			$revision->revisionId,
			$this->userId
		) );
	}

	/**
	 * Return the id of the latest successful revision
	 *
	 * @return int
	 */
	private function getLatestSuccessfullSyncRevisionId(): int {
		try {
			return Revision::where( 'user_id', $this->userId )
			               ->where( 'sync_successful', true )
			               ->latest()
			               ->firstOrFail()
				->revision_id;
		} catch ( ModelNotFoundException $e ) {
			return - 1;
		}
	}
}