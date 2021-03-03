<?php


namespace App\Synchronizer;


use App\Exceptions\AlreadyInListException;
use App\Exceptions\CleanedEmailException;
use App\Exceptions\EmailComplianceException;
use App\Exceptions\FakeEmailException;
use App\Exceptions\InvalidEmailException;
use App\Exceptions\MailchimpClientException;
use App\Exceptions\MemberDeleteException;
use App\Http\CrmClient;
use App\Http\MailChimpClient;
use App\Mail\InvalidEmailNotification;
use App\Revision;
use App\Sync;
use App\Synchronizer\Mapper\Mapper;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CrmToMailchimpSynchronizer
{
    private const LOCK_BASE_FOLDER_NAME = 'locks';
    private const MAX_LOCK_TIME = 43200; // 12h
    
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
     * @throws RequestException
     * @throws \Exception
     */
    public function syncAllChanges(int $limit = 100, int $offset = 0, bool $all = false)
    {
        if (!$this->lock()) {
            Log::info("({$this->configName}) There is already a synchronization for running. Start of new sync job canceled.");
            
            return;
        }
        
        // get revision id of last successful sync (or -1 if first run)
        $revId = $this->getLatestSuccessfullSyncRevisionId();
        
        // sync all records instead of changes if all flag is set
        if ($all) {
            $revId = -1;
        }
        
        if (0 === $offset) {
            Log::debug("({$this->configName}) Starting to sync all changes from crm into mailchimp\nConfig: {$this->configName}");
            
            if (-1 === $revId) {
                Log::debug("({$this->configName}) Syncing all records regardless of changes.");
            } else {
                Log::debug("({$this->configName}) Id of last successfully synced revision: $revId");
            }
            
            // get latest revision id and store it in the local database
            $this->openNewRevision();
        }
        
        while (true) {
            // get changed members
            $get = $this->crmClient->get("member/changed/$revId/$limit/$offset");
            $crmData = json_decode((string)$get->getBody(), true);
            
            // base case: everything worked well. update revision id
            if (empty($crmData)) {
                Log::debug("({$this->configName}) Everything synced.");
                $this->closeOpenRevision();
                $this->unlock();
                Log::debug("({$this->configName}) Sync successful.");
                
                return;
            }
    
            // sync members to mailchimp
            // don't use mailchimps batch operations, because they are async
            foreach ($crmData as $crmId => $record) {
                if ($this->alreadySynced($crmId)) {
                    Log::debug("({$this->configName}) Record with id $crmId already synced. Skipping.");
                } else {
                    $this->syncSingleRetry($crmId, $record);
                }
            }
            
            Log::debug(sprintf(
                "(%s) Sync of records %d up to %d for config %s successful. Requesting next batch.",
                $this->configName,
                $offset,
                $offset + $limit,
                $this->configName
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
                Log::notice("({$this->configName}) Max lock time exceeded. Lockfile deleted.");
            } else {
                Log::debug("({$this->configName}) Lockfile created ${age}s ago.");
                
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
     * @return int
     */
    private function getLatestSuccessfullSyncRevisionId(): int
    {
        try {
            return Revision::where('config_name', $this->configName)
                ->where('sync_successful', true)
                ->latest()
                ->firstOrFail()
                ->revision_id;
        } catch (ModelNotFoundException $e) {
            return -1;
        }
    }
    
    /**
     * Add current crm revision id to the database, marked as none synced
     *
     * Make sure there is only one open revision per user. (if there were
     * existing ones, a previous sync must have failed. lets resume the it
     * then, so we have a self-healing approach).
     *
     * @throws RequestException
     */
    private function openNewRevision()
    {
        // try to resume
        try {
            $latestRev = $this->getOpenRevision();
    
            Log::info(sprintf(
                '(%s) Resuming revision %d for config %s',
                $this->configName,
                $latestRev->revision_id,
                $this->configName
            ));
    
            return;
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
        $latestRev->save();
        
        Log::debug(sprintf(
            '(%s) Opening revision %d for config %s',
            $this->configName,
            $latestRev->revision_id,
            $this->configName
        ));
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
    
        Log::debug(sprintf(
            '(%s) Closing revision %d',
            $this->configName,
            $revision->revision_id
        ));
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
     * Remove lock folder.
     */
    public function unlock()
    {
        rmdir("{$this->lockRoot}/{$this->configName}.lock");
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
                    Log::error("({$this->configName}) Failed to sync record $crmId to Mailchimp after tree attempts. Error: {$e->getMessage()}");
            }
        } catch (EmailComplianceException $e) {
            Log::info("({$this->configName}) This record is in a compliance state due to unsubscribe, bounce or compliance review and cannot be subscribed.");
        } catch (MemberDeleteException $e) {
            Log::info("({$this->configName}) " . $e->getMessage());
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
        Log::debug("({$this->configName}) Start syncing record with id: $crmId");
    
        $emailKey = $this->config->getCrmEmailKey();
        $crmData[$emailKey] = $this->normalizeEmail($crmData[$emailKey]);
    
        $mcCrmIdFieldKey = $this->config->getMailchimpKeyOfCrmId();
        $mcEmail = $this->mailchimpClient->getSubscriberEmailByCrmId((string)$crmId, $mcCrmIdFieldKey);
    
        // if the record was deleted in the crm
        if (null === $crmData) {
            Log::debug("({$this->configName}) Record was deleted in crm.");
        
            if ($mcEmail) {
                $this->mailchimpClient->deleteSubscriber($mcEmail);
                Log::debug("({$this->configName}) Record deleted in mailchimp.");
            } else {
                Log::debug("({$this->configName}) Record not present in mailchimp.");
            }
            
            return;
        }
        
        // skip if record has no email address and isn't in mailchimp yet
        if (!$mcEmail && empty($crmData[$emailKey])) {
            Log::debug("({$this->configName}) Record skipped (not in mailchimp and has no email address).");
        
            return;
        }
        
        // get the master record
        $get = $this->crmClient->get("member/$crmId/main");
        $main = json_decode((string)$get->getBody(), true);
        $main[$emailKey] = $this->normalizeEmail($main[$emailKey]);
        $mainId = $main[Config::getCrmIdKey()];
        $email = $this->mailchimpClient->getSubscriberEmailByCrmId((string)$mainId, $mcCrmIdFieldKey);
        if ($crmId != $mainId) { // type coercion wanted
            Log::debug("({$this->configName}) Found main record. ID: $mainId");
        } else {
            Log::debug("({$this->configName}) This record seems to be the main record.");
        }
        
        // remove all subscribers that unsubscribed via crm
        if (!$this->filter->filterSingle($main)) {
            if ($email) {
                $this->mailchimpClient->deleteSubscriber($email);
                Log::debug("({$this->configName}) Filter criteria not met: Record deleted in Mailchimp.");
            } else {
                Log::debug("({$this->configName}) Filter criteria not met: Record not present in Mailchimp.");
            }
            
            return;
        }
        
        $mcRecord = $this->mapper->crmToMailchimp($main);
        
        // handle records already subscribed to mailchimp
        // where the email address has changed in the crm
        if ($email && $email !== $main['email1']) {
            $updateEmail = true;
            Log::debug("({$this->configName}) Email address has changed in crm.");
        } else {
            $updateEmail = false;
        }
        
        // handle re-subscriptions
        $mcRecord['status'] = 'subscribed';
        
        // store record in mailchimp
        try {
            $this->putSubscriber($mcRecord, $email, $updateEmail);
        } catch (AlreadyInListException $e) {
            Log::warning("({$this->configName}) Mailchimp claims subscriber is already in list, but with a different id. However we could not find an exact match for this email, so we did not take any action. The original Error message is still valid: " . $e->getMessage());
        } catch (InvalidEmailException $e) {
            Log::info("({$this->configName}) INVALID EMAIL ({$mcRecord['email_address']}). Record skipped.");
        } catch (FakeEmailException $e) {
            $this->notifyAdminInvalidEmail($mcRecord);
            Log::info("({$this->configName}) FAKE or INVALID EMAIL ({$mcRecord['email_address']}). Config admin notified.");
        }
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
     * Upsert the record in mailchimp.
     *
     * Handle the edge case, where the subscriber id differs from the lowercase
     * email md5-hash. Why this occurs sometimes is an unresolved issue.
     *
     * @param array $mcRecord
     * @param string $email
     * @param bool $updateEmail
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
     */
    private function putSubscriber(array $mcRecord, string $email, bool $updateEmail)
    {
        try {
            if ($updateEmail) {
                $this->mailchimpClient->putSubscriber($mcRecord, $email);
            } else {
                $this->mailchimpClient->putSubscriber($mcRecord);
            }
            Log::debug("({$this->configName}) Record synchronized to mailchimp.");
        } catch (AlreadyInListException $e) {
            // it is possible, that the subscriber id differs from the lowercase email md5-hash (why?)
            // if this is the case, we should find the subscriber in mailchimp and use this id
            $matches = $this->mailchimpClient->findSubscriber($email);
            if (1 === $matches['exact_matches']['total_items']) {
                $id = $matches['exact_matches']['members'][0]['id'];
    
                $this->mailchimpClient->putSubscriber($mcRecord, null, $id);
    
                $calculatedId = MailChimpClient::calculateSubscriberId($email);
                Log::debug("({$this->configName}) Member was already in list with id '$id' instead of the lowercase MD5 hashed email '$calculatedId'. It was updated correctly anyhow.");
            } else {
                throw $e;
            }
        } catch (CleanedEmailException $e) {
            if (!$updateEmail) {
                Log::info("({$this->configName}) This email-address was cleaned and no new email address was provided. Update aborted.");
                return;
            }
    
            // delete record with old email (mailchimp doesn't allow to simply
            // delete (=archive) cleaned records, so we have to delete it
            // permanently).
            $this->mailchimpClient->permanentlyDeleteSubscriber($email);
    
            // then create a new one with the new email address
            $this->putSubscriber($mcRecord, "", false);
        }
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
     * Trims and converts the given email to a lowercase string.
     *
     * @param string|null|false $email
     * @return string
     */
    private function normalizeEmail(string|null|false $email): string
    {
        return strtolower(trim((string)$email));
    }
}