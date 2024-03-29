<?php


namespace App\Synchronizer;


use App\Exceptions\AlreadyInListException;
use App\Exceptions\ArchivedException;
use App\Exceptions\CleanedEmailException;
use App\Exceptions\EmailComplianceException;
use App\Exceptions\FakeEmailException;
use App\Exceptions\InvalidEmailException;
use App\Exceptions\MailchimpClientException;
use App\Exceptions\MailchimpTooManySubscriptionsException;
use App\Exceptions\MemberDeleteException;
use App\Exceptions\MergeFieldException;
use App\Exceptions\UnsubscribedEmailException;
use App\Http\CrmClient;
use App\Http\MailChimpClient;
use App\Mail\InvalidEmailNotification;
use App\Revision;
use App\Sync;
use App\Synchronizer\Mapper\Mapper;
use App\SyncLaterRecords;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class CrmToMailchimpSynchronizer
{
    use LogTrait;
    
    private const LOCK_BASE_FOLDER_NAME = 'locks';
    private const MAX_LOCK_TIME = 15 * 60; // 15 minutes
    private const MAX_ONGOING_REVISION_AGE_BEFORE_FULL_SYNC = '-7 days';
    
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
    private $lockRoot;
    
    /**
     * @var string
     */
    private $lockFile;
    
    /**
     * The id of the current revision entry in the local DB (not the crm's
     * revision number)
     *
     * @var int
     */
    private $internalRevisionId;
    
    /**
     * @var int
     */
    private $syncCounter = 0;
    
    /**
     * Synchronizer constructor.
     *
     * @param string $configFileName file name of the config file
     *
     * @throws \App\Exceptions\ConfigException
     * @throws \Exception
     */
    public function __construct(string $configFileName)
    {
        $this->config = new Config($configFileName);
        $this->configName = $configFileName;
        
        $crmCred = $this->config->getCrmCredentials();
        $this->crmClient = new CrmClient((int)$crmCred['clientId'], $crmCred['clientSecret'], $crmCred['url']);
        
        $mcCred = $this->config->getMailchimpCredentials();
        $this->mailchimpClient = new MailChimpClient($mcCred['apikey'], $this->config->getMailchimpListId());
        
        $this->filter = new Filter($this->config->getFieldMaps(), $this->config->getSyncAll());
        $this->mapper = new Mapper($this->config->getFieldMaps());
        
        $this->lockRoot = storage_path() . '/' . self::LOCK_BASE_FOLDER_NAME;
        $this->lockFile = "{$this->lockRoot}/{$this->configName}.lock";
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
     * @param bool $all sync all records, not just changes
     *
     * @throws \App\Exceptions\ConfigException
     * @throws \App\Exceptions\ParseCrmDataException
     * @throws GuzzleException
     * @throws \Exception
     */
    public function syncAllChanges(int $limit = 100, int $offset = 0, bool $all = false)
    {
        if (!$this->lock()) {
            $this->log('info', 'There is already a synchronization for running. Start of new sync job canceled.');
    
            return;
        }
    
        // get revision id of last successful sync (or -1 if no successful revision in the last X days)
        $latestSuccessfulSyncRevision = $this->getLatestSuccessfullSyncRevision();
        $max_revision_age = date_create_immutable(self::MAX_ONGOING_REVISION_AGE_BEFORE_FULL_SYNC);
        if (!$latestSuccessfulSyncRevision) {
            $revId = -1;
            $log = 'No successful revision found.';
        } elseif ($latestSuccessfulSyncRevision->created_at < $max_revision_age) {
            $revId = -1;
            $log = "Last successful revision started {$latestSuccessfulSyncRevision->created_at->diffForHumans()}.";
        } else {
            $revId = $latestSuccessfulSyncRevision->revision_id;
            $log = "Last successful sync started {$latestSuccessfulSyncRevision->created_at->diffForHumans()} revision id: $revId.";
        }
    
        // sync all records instead of changes if all flag is set
        if ($all) {
            $revId = -1;
            $log = 'Force sync all records regardless of changes.';
        }
    
        if (0 === $offset) {
            $this->log('info', 'Starting to sync from crm into mailchimp.');
    
            // get latest revision id from webling and store it in the local database
            $currentRevision = $this->openNewRevision(-1 === $revId);
    
            if (-1 === $revId) {
                $log .= ' Doing full sync.';
            } elseif ($currentRevision->full_sync) {
                // resumption of full sync
                $revId = -1;
                $log = 'Resuming full sync.';
            } else {
                $log .= ' Synchronizing changes only.';
            }
    
            $this->log('info', $log);
        }
        
        while (true) {
            $this->log('debug', sprintf(
                "Requesting next %d records starting at %d.",
                $limit,
                $offset,
            ));
    
            // get changed members
            try {
                $get = $this->crmClient->get("member/changed/$revId/$limit/$offset");
                $crmData = json_decode((string)$get->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (GuzzleException $e) {
                $this->log('warning', "Failed to request records. Exiting. Original error Message: {$e->getMessage()}");
                exit(1);
            } catch (\JsonException $e) {
                $this->log('warning', "Failed to read changed members. Exiting. Original error Message: {$e->getMessage()}");
                exit(1);
            }
    
            // base case: sync completed.
            if (empty($crmData)) {
                if (SyncLaterRecords::hasRecordsQueuedForSync($this->configName)) {
                    // then sync the records queued for sync later
                    $this->log('debug', 'Sync records marked for sync later.');
                    $crmData = $this->getRecordsQueuedForSync();
                } else {
                    // now, really everything is done
                    $this->log('debug', 'Everything synced.');
                    $this->closeOpenRevision();
                    $this->unlock();
                    $this->log('debug', 'Sync successful.');
    
                    return;
                }
            }
    
            // sync members to mailchimp
            // don't use mailchimps batch operations, because they are async
            foreach ($crmData as $crmId => $record) {
                $this->syncCounter++;
                if ($this->alreadySynced($crmId)) {
                    $this->logRecord('debug', $this->getEmailFromCrmData($record), "Record with id $crmId already synced. Skipping.");
                } else {
                    $this->updateLock();
                    $this->syncSingleRetry($crmId, $record);
                }
            }
    
            $this->log('debug', sprintf(
                "Sync of records %d up to %d successful. Requesting next batch.",
                $offset,
                $offset + $limit,
            ));
    
            // get next batch
            $offset += $limit;
        }
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
    public function lock(): bool
    {
        if (!is_dir($this->lockRoot)) {
            $create = mkdir($this->lockRoot, 0751);
            if (!$create) {
                throw new \Exception('Lock folder did not exist and could not be created.');
            }
        }
        
        // delete lockfile if the max lock time was exceeded
        // this helps to recover from crashed syncs, where the
        // lockfile wasn't deleted.
        if (is_dir($this->lockFile)) {
            $lastMod = filemtime($this->lockFile);
            $age = time() - $lastMod;
            if ($age > self::MAX_LOCK_TIME) {
                rmdir($this->lockFile);
                $this->log('notice', 'Max lock time exceeded. Lockfile deleted.');
            } else {
                $age = Carbon::now()->subSeconds($age);
                $this->log('debug', "Lockfile created {$age->diffForHumans()}.");
    
                return false;
            }
        }
    
        // there is a small race condition here, but it affects only the error message
        // the mkdir is race condition free and kills the process if the file exists.
    
        $lock = mkdir($this->lockFile, 0700);
    
        return $lock;
    }
    
    /**
     * Return the id of the latest successful revision
     *
     * @return Revision|null
     */
    private function getLatestSuccessfullSyncRevision(): ?Revision
    {
        return Revision::where('config_name', $this->configName)
            ->where('sync_successful', true)
            ->latest()
            ->first();
    }
    
    /**
     * Add current crm revision id to the database, marked as none synced
     *
     * Make sure there is only one open revision per user. (if there were
     * existing ones, a previous sync must have failed. lets resume the it
     * then, so we have a self-healing approach).
     *
     * @throws GuzzleException
     */
    private function openNewRevision(bool $all): Revision
    {
        // try to resume
        try {
            $latestRev = $this->getOpenRevision();
            
            $this->log('info', "Resuming revision {$latestRev->revision_id}");
            
            return $latestRev;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // we don't have an open revision so there is nothing to resume
        }
    
        // get current revision id from crm
        $get = $this->crmClient->get('revision');
        $latestRevId = (int)json_decode((string)$get->getBody());
        
        // add current revision
        $latestRev = new Revision();
        $latestRev->config_name = $this->configName;
        $latestRev->revision_id = $latestRevId;
        $latestRev->sync_successful = false;
        $latestRev->full_sync = $all;
        $latestRev->save();
        
        $this->log('debug', "Opening revision {$latestRev->revision_id}");
        
        return $latestRev;
    }
    
    /**
     * Get the revision we're currently working on
     *
     * @return Revision|\Illuminate\Database\Eloquent\Model
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    private function getOpenRevision()
    {
        return Revision::where('config_name', $this->configName)
            ->where('sync_successful', false)
            ->latest()
            ->firstOrFail(); // else die hard
    }
    
    /**
     * Return array with records that were previously marked to be synced later
     *
     * See {@see SyncLaterRecords::getRecordsQueuedForSync()} for details on how the
     * records are selected. As we do only store the crm ids locally, the
     * record data is retrieved from the crm.
     *
     * @return array
     */
    private function getRecordsQueuedForSync(): array
    {
        $crmData = [];
        /** @var SyncLaterRecords $record */
        foreach (SyncLaterRecords::getRecordsQueuedForSync($this->configName) as $record) {
            $record->touch();
            $crmId = $record->crm_id;
            try {
                $get = $this->crmClient->get("member/$crmId");
                
                if (404 === $get->getStatusCode()) {
                    $this->log('info', "Record was deleted in crm. Marking as done in SyncLater queue.");
                    $this->markSyncLaterAsDone($crmId);
                }
                
                $crmData[$crmId] = json_decode((string)$get->getBody(), true, 512, JSON_THROW_ON_ERROR);
    
            } catch (GuzzleException $e) {
                $this->log('warning', "Failed to request record $crmId. Skipping. Original error Message: {$e->getMessage()}");
            } catch (\JsonException $e) {
                $this->log('warning', "Failed to read record $crmId. Skipping. Original error Message: {$e->getMessage()}");
            }
        }
    
        return $crmData;
    }
    
    private function markSyncLaterAsDone(int $crmId): void
    {
        $record = SyncLaterRecords::where('crm_id', '=', $crmId)
            ->where('config_name', '=', $this->configName)
            ->whereNull('sync_successful')
            ->first();
        
        if ($record) {
            $record->sync_successful = new Carbon();
            $record->save();
        }
    }
    
    /**
     * Mark the open revision as successfully synced
     */
    private function closeOpenRevision()
    {
        $revision = $this->getOpenRevision();
        $revision->sync_successful = true;
        $revision->save();
        $this->internalRevisionId = null;
        
        $this->log('debug', "Closing revision {$revision->revision_id}");
    }
    
    /**
     * Remove lock folder.
     */
    public function unlock()
    {
        $lockfile = "{$this->lockRoot}/{$this->configName}.lock";
        if (file_exists($lockfile)) {
            rmdir($lockfile);
        }
    }
    
    /**
     * Checks if the given crm entry was already synced for this revision and
     * this mailchimp instance.
     *
     * @param int $crmId
     * @return bool
     */
    private function alreadySynced(int $crmId)
    {
        return (bool)Sync::where('crm_id', $crmId)
            ->where('internal_revision_id', $this->getInternalRevisionId())
            ->count();
    }
    
    /**
     * The internal id of the current revision entry in the local db
     *
     * @return int
     */
    private function getInternalRevisionId()
    {
        if (!$this->internalRevisionId) {
            $this->internalRevisionId = $this->getOpenRevision()->id;
        }
        
        return $this->internalRevisionId;
    }
    
    private function logRecord(string $method, string $email, string $message): void
    {
        $email = strtolower(trim($email));
        $more = "email=\"$email\" num={$this->syncCounter}";
        $this->log($method, $message, $more);
    }
    
    private function getEmailFromCrmData(?array $crmData): string
    {
        if (!$crmData) {
            return "";
        }
        
        $key = $this->config->getCrmEmailKey();
        
        if (!array_key_exists($key, $crmData)) {
            return "";
        }
        
        if (empty($crmData[$key])) {
            return "";
        }
        
        return $crmData[$key];
    }
    
    /**
     * Set the lock file's modified time to now
     *
     * @return void
     */
    private function updateLock()
    {
        if (is_dir($this->lockFile)) {
            touch($this->lockFile);
        }
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
    private function syncSingleRetry($crmId, $record, $attempt = 0)
    {
        try {
            $this->syncSingle($crmId, $record);
            $this->markSynced($crmId);
        } catch (MailchimpClientException $e) {
            switch ($attempt) {
                case 0:
                    sleep(10);
                    $this->syncSingleRetry($crmId, $record, 1);
                    
                    return;
                
                case 1:
                    sleep(30);
                    $this->syncSingleRetry($crmId, $record, 2);
                    
                    return;
                
                default:
                    $this->logRecord('warning', $this->getEmailFromCrmData($record), "Failed to sync record $crmId to Mailchimp after tree attempts. Error: {$e->getMessage()}");
            }
        } catch (EmailComplianceException $e) {
            $this->logRecord('info', $this->getEmailFromCrmData($record), "This record is in a compliance state due to unsubscribe, bounce or compliance review and cannot be subscribed.");
        } catch (MemberDeleteException $e) {
            $this->logRecord('info', $this->getEmailFromCrmData($record), $e->getMessage());
        }
    }
    
    /**
     * @param int $crmId
     * @param array|null $crmData
     *
     * @throws EmailComplianceException
     * @throws MailchimpClientException
     * @throws MemberDeleteException
     * @throws \App\Exceptions\ConfigException
     * @throws \App\Exceptions\ParseCrmDataException
     */
    private function syncSingle(int $crmId, $crmData)
    {
        $this->logRecord('debug', $this->getEmailFromCrmData($crmData), "Start syncing record with id: $crmId");
    
        $emailKey = $this->config->getCrmEmailKey();
    
        $mcCrmIdFieldKey = $this->config->getMailchimpKeyOfCrmId();
        $mcEmail = $this->mailchimpClient->getSubscriberEmailByCrmId((string)$crmId, $mcCrmIdFieldKey);
    
        // if the record was deleted in the crm
        if (null === $crmData) {
            $this->logRecord('debug', '', "Record $crmId was deleted in crm.");
    
            if (!$mcEmail) {
                $this->logRecord('debug', '', "Record $crmId not present in mailchimp.");
                return;
            }
    
            $resp = $this->crmClient->post("member/match", ['email1' => ['value' => $mcEmail]]);
            $matches = json_decode((string)$resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    
            if ('no_match' === $matches['status']) {
                $this->logRecord('debug', $mcEmail, "No other record with same email in crm.");
                $this->mailchimpClient->deleteSubscriber($mcEmail);
                $this->logRecord('debug', $mcEmail, "Record $crmId deleted in mailchimp.");
                return;
            }
    
            $firstMatch = $matches['matches'][0];
            $this->logRecord('debug', $mcEmail, "There is at least one record with the same email in the crm (crm id: {$firstMatch['id']}). Syncing this one instead.");
            $this->syncSingle($firstMatch['id'], $firstMatch);
            return;
        }
    
        $crmData = $this->normalizeEmail($crmData);
    
        // skip if record has no email address and isn't in mailchimp yet
        if (!$mcEmail && empty($crmData[$emailKey])) {
            $this->logRecord('debug', '', "Record $crmId skipped (not in mailchimp and has no email address).");
        
            return;
        }
    
        // get the relevant record
        $main = $this->getRelevantRecord($crmData);
        $main = $this->normalizeEmail($main);
        $mainId = $main[Config::getCrmIdKey()];
        $email = $this->mailchimpClient->getSubscriberEmailByCrmId((string)$mainId, $mcCrmIdFieldKey);
        /** @noinspection TypeUnsafeComparisonInspection */
        if ($crmId != $mainId) { // type coercion wanted
            $this->logRecord('debug', $this->getEmailFromCrmData($crmData), "Found main record $mainId. Email of main record {$this->getEmailFromCrmData($main)}.");
        } else {
            $this->logRecord('debug', $this->getEmailFromCrmData($main), "This record seems to be the main record.");
        }
    
        // remove all subscribers that unsubscribed via crm
        if (!$this->filter->filterSingle($main)) {
            if ($email) {
                $this->mailchimpClient->deleteSubscriber($email);
                $this->logRecord('debug', $this->getEmailFromCrmData($main), "Filter criteria not met: Record deleted in Mailchimp.");
            } else {
                $this->logRecord('debug', $this->getEmailFromCrmData($main), "Filter criteria not met: Record not present in Mailchimp.");
            }
            
            return;
        }
        
        $mcRecord = $this->mapper->crmToMailchimp($main);
        
        // handle records already subscribed to mailchimp
        // where the email address has changed in the crm
        if ($email && $email !== $main['email1']) {
            $updateEmail = true;
            $this->logRecord('debug', $this->getEmailFromCrmData($main), "Email address has changed in crm. Changing address in Mailchimp from {$email} to {$this->getEmailFromCrmData($main)}.");
        } else {
            $updateEmail = false;
        }
        
        // handle re-subscriptions
        $mcRecord['status'] = 'subscribed';
        
        // store record in mailchimp
        try {
            $this->putSubscriber($mcRecord, $email, $updateEmail);
        } catch (AlreadyInListException $e) {
            $this->logRecord('warning', $mcRecord['email_address'], "Mailchimp claims subscriber is already in list. However we could not find an exact match for this email, so we did not take any action. The original Error message is still valid: {$e->getMessage()}");
        } catch (InvalidEmailException $e) {
            $this->logRecord('info', $mcRecord['email_address'], "INVALID EMAIL. Record skipped.");
        } catch (FakeEmailException $e) {
            $this->notifyAdminInvalidEmail($mcRecord);
            $this->logRecord('info', $mcRecord['email_address'], "FAKE or INVALID EMAIL. Config admin notified.");
        } catch (MergeFieldException $e) {
            $this->logRecord('error', $mcRecord['email_address'], $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Trims and converts the given email to a lowercase string.
     *
     * @param array $crmData
     * @return array the $crmData with the normalized email
     */
    private function normalizeEmail(array $crmData): array
    {
        $emailKey = $this->config->getCrmEmailKey();
        if (isset($crmData[$emailKey])) {
            $crmData[$emailKey] = strtolower(trim((string)$crmData[$emailKey]));
        }
        return $crmData;
    }
    
    /**
     * Return the relevant record that corresponds to the given one
     *
     * If there are no duplicates in the crm, the given record is returned. Else
     * the process runs through the following steps until only one single record
     * is left, which is then returned:
     * 1. only take the records with the highest rating
     * 2. if any records are in prioritized groups, only take those records
     * 3. if any of the records are already in mailchimp, take those
     * 4. take the one with the lowest id
     *
     * Most records should not have any duplicates at all. Most of those with
     * duplicates differ by the rating, especially members and sympathizers.
     * The records left after step 1 are primarily institutional contacts like
     * organizations and media. They should most probably be handled by
     * prioritized groups. Steps 3 and 4 should only be the last lifeline and
     * be used very rarely.
     *
     * @link https://github.com/grueneschweiz/mailchimpservice/issues/52
     *
     * @param array $crmData a record as served by the crm
     * @return array a record as served by the crm
     * @throws GuzzleException
     * @throws \App\Exceptions\ConfigException
     * @throws \JsonException
     */
    private function getRelevantRecord(array $crmData): array
    {
        $matchData = [
            'email1' => ['value' => $crmData['email1'] ?? $crmData['email2'] ?? null],
            'firstName' => ['value' => $crmData['firstName'] ?? null],
            'lastName' => ['value' => $crmData['lastName'] ?? null],
            'zip' => ['value' => $crmData['lastName'] ?? null],
        ];
        $matchResp = $this->crmClient->post("member/match", $matchData);
        $matches = json_decode((string)$matchResp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    
        // if there are no duplicates
        if (1 >= count($matches['matches'])) {
            return $crmData;
        }
    
        // remove matches without valid email address
        foreach ($matches['matches'] as $idx => $match) {
            $id = $match[Config::getCrmIdKey()];
            $email = $match[$this->config->getCrmEmailKey()] ?? null;
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                unset($matches['matches'][$idx], $matches['ratings'][(int)$id]);
            }
        }
    
        // if there are no matches left
        if (0 === count($matches['matches'])) {
            return $crmData;
        }
    
        // if there is a clear winner in terms of membership rating
        // use this record
        $topRatedMatches = $this->extractTopRated($matches);
        if (1 === count($topRatedMatches)) {
            return $topRatedMatches[0];
        }
    
        // if we've got a prioritized group and no top-rated winner
        // use the top-rated records in the prioritized groups.
        $prioritizedGroups = $this->config->getPrioritizedGroups();
        if (!empty($prioritizedGroups)) {
            $prioritizedMatches = array_filter(
                $topRatedMatches,
                static fn($match) => 1 <= count(array_intersect($prioritizedGroups, $match['groups']))
            );
            
            // if only one single top-rated record is in a prioritized group
            // use this one
            if (1 === count($prioritizedMatches)) {
                return array_values($prioritizedMatches)[0];
            }
            
            // if none of the top-rated records are in prioritized groups
            // proceed with all top-rated records
            if (empty($prioritizedMatches)) {
                $prioritizedMatches = $topRatedMatches;
            }
        } else {
            $prioritizedMatches = $topRatedMatches;
        }
        
        // if we can't find a clear winner using the rating and the prioritized
        // groups, check if one of the records is already in mailchimp and use
        // this one.
        $email = array_values($prioritizedMatches)[0][$this->config->getCrmEmailKey()];
        try {
            $subscriber = $this->mailchimpClient->getSubscriber($email);
            $crmId = $subscriber['merge_fields'][$this->config->getMailchimpKeyOfCrmId()];
        } catch (MailchimpClientException $e) {
            $crmId = null;
        }
        if ($crmId) {
            $alreadyInMailchimp = array_filter(
                $prioritizedMatches,
                static fn($match) => $match[Config::getCrmIdKey()] === $crmId
            );
            
            // if the one of the top-rated prioritized records is already in
            // mailchimp use this one
            if (1 === count($alreadyInMailchimp)) {
                return array_values($alreadyInMailchimp)[0];
            }
        }
        
        // if there wasn't a clear winner so far, use the top-rated prioritized
        // record with the lowest webling id
        usort(
            $prioritizedMatches,
            static fn($a, $b) => $a[Config::getCrmIdKey()] <=> $b[Config::getCrmIdKey()]
        );
        return $prioritizedMatches[0];
    }
    
    /**
     * Returns array with the crm data of the top-rated matches only
     *
     * @param array $matches
     * @return array
     */
    private function extractTopRated(array $matches): array
    {
        arsort($matches['ratings'], SORT_NUMERIC);
        $maxRating = array_values($matches['ratings'])[0];
        $topRated = array_filter(
            $matches['ratings'],
            static fn($rating) => $rating >= $maxRating
        );
        
        $topRatedMatches = [];
        foreach ($topRated as $idOfTopRated => $rating) {
            foreach ($matches['matches'] as $match) {
                /** @noinspection TypeUnsafeComparisonInspection */
                if ($match['id'] == $idOfTopRated) {
                    $topRatedMatches[] = $match;
                    break;
                }
            }
        }
        
        return $topRatedMatches;
    }
    
    /**
     * Upsert the record in mailchimp.
     *
     * Handle the edge case, where the subscriber id differs from the lowercase
     * email md5-hash. Why this occurs sometimes is an unresolved issue.
     *
     * @param array $mcRecord
     * @param string $email
     * @param bool $updateEmail if true, $email contains the old email address
     *
     * @throws AlreadyInListException If subscriber is in list with a different
     *   id, but the issue could not be resolved automatically.
     * @throws EmailComplianceException If the subscriber has unsubscribed and
     *   is therefore blocked by mailchimp.
     * @throws InvalidEmailException If the email address wasn't accepted by
     *   Mailchimp.
     * @throws MailchimpClientException On a connection error.
     * @throws MemberDeleteException If the deletion of a cleaned record failed.
     * @throws FakeEmailException If mailchimp recognizes a well known error
     *                            (like @gmail.con)
     * @throws MergeFieldException Invalid merge field configuration
     */
    private function putSubscriber(array $mcRecord, string $email, bool $updateEmail)
    {
        try {
            if ($updateEmail) {
                $this->mailchimpClient->putSubscriber($mcRecord, $email);
            } else {
                $this->mailchimpClient->putSubscriber($mcRecord);
            }
            $this->logRecord('debug', $mcRecord['email_address'], "Record synchronized to mailchimp.");
            $this->markSyncLaterAsDone($mcRecord['merge_fields']['WEBLINGID']);
        } catch (AlreadyInListException $e) {
            $oldEmail = $email;
            $newEmail = $mcRecord['email_address'];
    
            if ($updateEmail) {
                // the email has changed. however, there is already a record
                // with the new email in mailchimp. so update the record
                // with the new email and archive the one with the old email.
                $this->logRecord('debug', $oldEmail, "Failed to change email from $oldEmail to $newEmail because there is already a record with $newEmail in mailchimp. Trying to update $newEmail and archiving $oldEmail.");
        
                // update the record with the new email
                $this->putSubscriber($mcRecord, "", false);
        
                // archive the record with the old email
                $this->mailchimpClient->deleteSubscriber($oldEmail);
                $this->logRecord('debug', $oldEmail, "Archived record in Mailchimp.");
            } else {
                throw $e;
            }
        } catch (CleanedEmailException $e) {
            if (!$updateEmail) {
                $this->logRecord('info', $mcRecord['email_address'], "This email-address was cleaned and no new email address was provided. Update aborted.");
                return;
            }
    
            // delete record with old email (mailchimp doesn't allow to simply
            // delete (=archive) cleaned records, so we have to delete it
            // permanently).
            $this->mailchimpClient->permanentlyDeleteSubscriber($email);
    
            // then create a new one with the new email address
            $this->putSubscriber($mcRecord, "", false);
        } catch (UnsubscribedEmailException $e) {
            if ($updateEmail) {
                $this->logRecord('info', $email, "Change of address from {$email} to {$mcRecord['email_address']} rejected, because user is unsubscribed. Archiving {$email} and adding {$mcRecord['email_address']}.");
    
                // archive record with old email
                $this->mailchimpClient->deleteSubscriber($email);
    
                // then create a new one with the new email address
                $this->putSubscriber($mcRecord, "", false);
            } else {
                $this->logRecord('info', $email, $e->getMessage());
            }
        } catch (MailchimpTooManySubscriptionsException $e) {
            $this->logRecord('info', $email, "Blocked by Mailchimp's subscription rate limit. Retrying later.");
            $this->saveToSyncLater($mcRecord['merge_fields']['WEBLINGID']);
        } catch (ArchivedException $e) {
            $this->logRecord('info', $email, 'Record archived and can not be unarchived. At least now.');
        }
    }
    
    /**
     * Save the given crm id along with the config name to the database.
     *
     * Records stored in this table will be loaded at every sync of the same
     * config and it will be tried to sync them again.
     *
     * @param int $crmId
     * @return void
     */
    private function saveToSyncLater(int $crmId): void
    {
        $entry = SyncLaterRecords::where('crm_id', '=', $crmId)
            ->where('config_name', '=', $this->configName)
            ->whereNull('sync_successful')
            ->firstOrNew();
        $entry->attempts++;
        $entry->save();
    }
    
    /**
     * Inform data owner that he should only add contact in the crm not in mailchimp
     *
     * @param array $dataOwner
     * @param array $mcData
     */
    private function notifyAdminInvalidEmail(array $mcData)
    {
        $dataOwner = $this->config->getDataOwner();
        
        $mailData = new \stdClass();
        $mailData->dataOwnerName = $dataOwner['name'];
        $mailData->contactFirstName = $mcData['merge_fields']['FNAME']; // todo: check if we cant get the field keys dynamically
        $mailData->contactLastName = $mcData['merge_fields']['LNAME']; // todo: dito
        $mailData->contactEmail = $mcData['email_address'];
        $mailData->adminEmail = env('ADMIN_EMAIL');
        $mailData->configName = $this->configName;
        
        Mail::to($dataOwner['email'])
            ->send(new InvalidEmailNotification($mailData));
    }
    
    /**
     * Create a new database entry that marks the given record as already synced
     * for this revision so it can be skipped if the program runs twice.
     *
     * @param int $crmId
     */
    private function markSynced(int $crmId)
    {
        $sync = new Sync();
        $sync->crm_id = $crmId;
        $sync->internal_revision_id = $this->getInternalRevisionId();
        $sync->save();
    }
}