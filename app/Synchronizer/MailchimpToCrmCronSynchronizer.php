<?php

namespace App\Synchronizer;

use App\Http\MailChimpClient;
use App\Exceptions\ConfigException;
use App\Synchronizer\LogTrait;

// Example member data from getListMembers():
// "{"email_address":"test@example.com",
// "id":"55502f40dc8b7c769880b10874abc9d0",
// "status":"subscribed",
// "merge_fields":{
//     "FNAME":"Test",
//     "LNAME":"User",
//     "GENDER":"m",
//     "WEBLINGID":"11195",
//     "NOTES_CH":""},
// "last_changed":"2025-04-11T15:39:31+00:00",
// "tags":[
//     {"id":6289607,"name":"member"},
//     {"id":6289608,"name":"donor"},
//     {"id":6289612,"name":"Region Olten"},
//     {"id":6289618,"name":"climate"},
//     {"id":6289625,"name":"Thal-G\u00e4u"}
// ]}"

/**
 * Batch synchronizer for pulling changed Mailchimp subscribers and upserting them into the CRM
 * when executed via cron or a CLI command. Designed to reduce CRM load and licensing overhead by
 * only processing relevant members.
 *
 * Applies filters and processes matching members in batches.
 */
class MailchimpToCrmCronSynchronizer extends MailchimpToCrmSynchronizer
{
    use LogTrait;

    private array $languageTags;
    private string $mailchimpKeyOfCrmId;
    private string $syncCriteriaField;
    private int $syncCriteriaThreshold;

    public function __construct(string $configFileName)
    {
        parent::__construct($configFileName);

        if (!$this->config->isUpsertToCrmEnabled()) {
            throw new ConfigException('Upsert to CRM is disabled. Please enable it in the config file.');
        }

        $this->languageTags = $this->config->getLanguageTagsFromConfig();
        $this->mailchimpKeyOfCrmId = $this->config->getMailchimpKeyOfCrmId();
        $this->syncCriteriaField = $this->config->getSyncCriteriaField();
        $this->syncCriteriaThreshold = $this->config->getSyncCriteriaThreshold();
    }

    /**
     * Synchronize all changed Mailchimp members to CRM
     *
     * @param int $batchSize Number of members to process per batch
     * @param int $limit Maximum number of members to process (0 for no limit)
     * @return array Statistics about the synchronization
     * @throws \Exception
     */
    public function syncAll(int $batchSize = 100, int $limit = 0): array
    {
        $this->log('info', 'Starting Mailchimp to CRM synchronization');

        $offset = 0;
        $totalProcessed = 0;
        $totalSuccess = 0;
        $totalFailed = 0;
        $hasMore = true;
        $requestFilterParams = $this->getRequestFilterParams();

        while ($hasMore && ($limit === 0 || $totalProcessed < $limit)) {
            $fetchCount = $limit > 0 ? min($batchSize, $limit - $totalProcessed) : $batchSize;

            try {
                $members = $this->mcClient->getListMembers($fetchCount, $offset, $requestFilterParams);

                if (empty($members)) {
                    $hasMore = false;
                    break;
                }

                $this->log('info', "Processing batch of " . count($members) . " members (offset: $offset)");

                foreach ($members as $member) {
                    $totalProcessed++;

                    $emailForLog = $member['email_address'] ?? '(no email)';
                    $this->log('info', "Processing member " . $emailForLog);
                    $this->log('info', "Member data: " . json_encode($member));
                    try {
                        $filterResult = $this->filterSingle($member);
                        if (!$filterResult) {
                            $totalFailed++;
                            continue;
                        }

                        $syncResult = $this->syncSingle($member);
                        if ($syncResult) {
                            $totalSuccess++;
                        } else {
                            $totalFailed++;
                        }
                    } catch (\Exception $e) {
                        $this->log('error', "Error processing member: " . $e->getMessage());
                        $totalFailed++;
                    }

                    if ($limit > 0 && $totalProcessed >= $limit) {
                        break;
                    }
                }

                $offset += count($members);

                if (count($members) < $fetchCount) {
                    $hasMore = false;
                }
            } catch (\Exception $e) {
                $this->log('error', "Error in syncAll batch: " . $e->getMessage());
                $totalFailed++;
                break;
            }
        }

        $this->log('info', "Completed Mailchimp to CRM synchronization: $totalProcessed processed, $totalSuccess successful, $totalFailed failed");

        return [
            'processed' => $totalProcessed,
            'success' => $totalSuccess,
            'failed' => $totalFailed
        ];
    }

    /**
     * Filter a single Mailchimp member for potential CRM update
     *
     * @param array $member The member data to filter
     * @return bool True if the member should be processed, false otherwise
     */
    private function filterSingle(array $member): bool
    {
        $email = $member['email_address'] ?? null;

        if (!$email) {
            $this->log('error', "Missing email in member data");
            return false;
        }

        if (!empty($member['merge_fields'][$this->mailchimpKeyOfCrmId])) {
            $this->log('debug', "Member " . $member['email_address'] . " has a CRM ID. Skipping.");
            return false;
        }

        $syncCriteriaValue = $member[$this->syncCriteriaField] ?? 0;
        if ($syncCriteriaValue <= $this->syncCriteriaThreshold) {
            $this->log('debug', "Member " . $member['email_address'] . " doesnt meet the sync criteria: " . $syncCriteriaValue . ". Skipping.");
            return false;
        }

        $configuredNewTag = $this->config->getNewTag();
        $newTagFound = false;
        if (isset($member['tags']) && is_array($member['tags'])) {
            foreach ($member['tags'] as $tag) {
                if ($tag['name'] === $configuredNewTag) {
                    $newTagFound = true;
                    break;
                }
            }
        }
        if (!$newTagFound) {
            $this->log('debug', "Member {$member['email_address']} does not have the configured new tag. Skipping.");
            return false;
        }

        return true;
    }

    /**
     * Process a single Mailchimp member for potential CRM update
     *
     * @param array $member The member data to sync
     * @return bool True if the member was processed successfully, false otherwise
     * @throws \Exception
     */
    public function syncSingle(array $member): bool
    {
        try {
            $crmData = $this->mapper->mailchimpToCrm($member);
            $member['entryChannel'] = 'Mailchimp import ' . date('Y-m-d H:i:s');
            //TODO MSC add newsletter subscription for crm
            $mailchimpId = MailChimpClient::calculateSubscriberId($member['email_address']);
            $crmId = $this->upsertToCrm($crmData, $member, 'daily_sync', $mailchimpId);

            return $crmId !== false;
        } catch (\Exception $e) {
            $this->log('error', "Error processing member {$member['email_address']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Return the interest filter parameters for the list members request
     */
    private function getRequestFilterParams(): array
    {
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $params = [
            'status' => 'subscribed',
            'since_last_changed' => $nowUtc->modify('-6 months')->format(DATE_ATOM),
            'since_timestamp_opt' => $nowUtc->modify('-4 months')->format(DATE_ATOM),
        ];

        $defaultFields = [
            'members.email_address',
            'members.merge_fields',
            'members.status',
            'members.tags',
            'members.id',
            'members.' . $this->syncCriteriaField,
            'members.interests'
        ];
        $params['fields'] = implode(',', $defaultFields);

        return $params;
    }

    /**
     * Try to upsert a contact to CRM and update Mailchimp with the CRM ID
     *
     * @param array $crmData The CRM data to upsert
     * @param array $member The member data from Mailchimp
     * @param string $callType The webhook event type
     * @param string $mailchimpId The Mailchimp ID of the contact
     *
     * @return string|false The CRM ID if successful, false otherwise
     * @throws Exception
     */
    private function upsertToCrm(array $crmData, array $member, string $callType, string $mailchimpId)
    {
        try {
            $response = $this->crmClient->post('/contacts', $crmData);
            if (isset($response['id']) && $response['id']) {
                $crmId = $response['id'];
                $this->updateMailchimpWithCrmId($crmId, $member, $mailchimpId);
                $this->logWebhook('debug', $callType, $mailchimpId, "Successfully upserted to CRM and updated Mailchimp with CRM ID.", $crmId);
                return $crmId;
            } else {
                $this->logWebhook('error', $callType, $mailchimpId, "Failed to get CRM ID from upsert response.");
                return false;
            }
        } catch (\Exception $e) {
            $this->logWebhook('error', $callType, $mailchimpId, "Error upserting to CRM: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update Mailchimp with the CRM ID
     *
     * @param string $crmId The CRM ID to update
     * @param array $member Optional member data if already available
     */
    private function updateMailchimpWithCrmId(string $crmId, array $member, string $mailchimpId): void
    {
        /*
        // TODO MSC
        $languageTag = $this->determineLanguageFromTags(['tags' => $member['tags']]);
        $interestsToSync = $this->config->getInterestsToSync();

        $interestId = reset($interestsToSync);

        for ($i = 0; $i < count($this->languageTags); $i++) {
            if ($languageTag === $this->languageTags[$i] && isset($interestsToSync[$i])) {
                $interestId = $interestsToSync[$i];
                break;
            }
        }*/

        $this->mcClient->updateSubscriberInterests(
            $mailchimpId,
            [$this->config->getMailchimpKeyOfCrmId() => $crmId],
            '' //$interestId
        );

        $this->mcClient->removeTagFromSubscriber($mailchimpId, $this->config->getNewTag());
    }

    /**
     * Determine the language from subscriber tags
     *
     * @param array $subscriberTags The tags of the subscriber
     *
     * @return string|null The language tag or null if not found
     */
    private function determineLanguageFromTags(array $subscriberTags): ?string
    {
        if (!isset($subscriberTags['tags']) || !is_array($subscriberTags['tags'])) {
            return null;
        }

        foreach ($subscriberTags['tags'] as $tag) {
            if (!isset($tag['name'])) {
                continue;
            }

            $tagName = $tag['name'];
            if (in_array($tagName, $this->languageTags)) {
                return $tagName;
            }
        }

        return null;
    }
}
