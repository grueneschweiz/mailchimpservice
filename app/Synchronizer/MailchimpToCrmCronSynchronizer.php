<?php

namespace App\Synchronizer;

use App\Http\MailChimpClient;
use App\Exceptions\ConfigException;
use App\Synchronizer\LogTrait;
use Psr\Http\Message\ResponseInterface;

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
    private int $groupForNewMembers;
    private array $interestsToSync;

    public function __construct(string $configFileName)
    {
        parent::__construct($configFileName);

        if (!$this->config->isUpsertToCrmEnabled()) {
            throw new ConfigException('Upsert to CRM is disabled. Please enable it in the config file.');
        }

        $this->languageTags = $this->config->getLanguageTagsFromConfig();
        $this->mailchimpKeyOfCrmId = $this->config->getMailchimpKeyOfCrmId();
        $this->groupForNewMembers = $this->config->getGroupForNewMembers();

        $this->interestsToSync = $this->config->getInterestsToSync();
        if (empty($this->interestsToSync)) {
            throw new ConfigException('No interests configured for sync. Please configure it in the config file.');
        }
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
                    try {
                        $filterResult = $this->filterSingle($member);
                        if (!$filterResult) {
                            $totalFailed++;
                            continue;
                        }

                        $syncResult = $this->syncSingle($member);
                        if ($syncResult) {
                            $this->log('info', "Member {$member['email_address']} sync to Crm was successful.");
                            $totalSuccess++;
                        } else {
                            $this->log('info', "Member {$member['email_address']} sync to Crm has failed.");
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

        $hasNewsletter = false;
        $memberInterests = $member['interests'] ?? [];
        foreach ($this->interestsToSync as $interestId) {
            if (isset($memberInterests[$interestId]) && $memberInterests[$interestId] === true) {
                $hasNewsletter = true;
                break;
            }
        }
        if (!$hasNewsletter) {
            $this->log('debug', "Member " . $member['email_address'] . " has no newsletter interests set. Skipping.");
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
            $crmData = $this->mapper->mailchimpToCrm($member, true);
            $crmData = $this->addCustomMapping($crmData, $member);

            $mailchimpId = MailChimpClient::calculateSubscriberId($member['email_address']);
            $crmId = $this->upsertToCrm($crmData, $member, 'daily_sync', $mailchimpId);

            return $crmId !== false;
        } catch (\Exception $e) {
            $this->log('error', "Error processing member {$member['email_address']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add custom CRM data to the mapped data
     *
     * @param array $crmData The mapped CRM data
     * @param array $member The member data from Mailchimp
     *
     * @return array The CRM data with custom fields added
     *
     * @throws \Exception
     */
    private function addCustomMapping(array $crmData, array $member): array
    {
        $crmData['entryChannel'] = [
            'value' => 'Mailchimp import ' . date('Y-m-d H:i:s'),
            'mode' => 'replaceEmpty'
        ];
        $crmData['groups'] = [
            'value' => (string) $this->groupForNewMembers,
            'mode' => 'append'
        ];

        $languageTag = $this->determineLanguageFromTags($member);
        if ($languageTag) {
            $crmData['language'] = [
                'value' => $languageTag,
                'mode' => 'replaceEmpty'
            ];
        }

        // remove empty WEBLINGID from CRM data
        if (isset($crmData['id'])) {
            unset($crmData['id']);
        }
        return $crmData;
    }

    /**
     * Return the interest filter parameters for the list members request
     */
    private function getRequestFilterParams(): array
    {
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $params = [
            'status' => 'subscribed',
            'since_last_changed' => $nowUtc->modify('-' . $this->config->getChangedWithinMonths() . ' months')->format(DATE_ATOM),
            // Only include subscribers whose opt-in is older than the specified months
            'before_timestamp_opt' => $nowUtc->modify('-' . $this->config->getOptInOlderThanMonths() . ' months')->format(DATE_ATOM),
        ];

        $defaultFields = [
            'members.email_address',
            'members.merge_fields',
            'members.status',
            'members.tags',
            'members.id',
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
            $response = $this->crmClient->post('/api/v1/member', $crmData);
            $crmId = (string) json_decode((string) $response->getBody(), true);

            if (!empty($crmId)) {
                $this->updateMailchimpWithCrmId($crmId, $mailchimpId);
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
     */
    private function updateMailchimpWithCrmId(string $crmId, string $mailchimpId): void
    {
        $this->mcClient->updateMergeFields(
            $mailchimpId,
            [$this->config->getMailchimpKeyOfCrmId() => $crmId]
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
                // e.g. Deutsch -> "d", Francais -> "f", Italiano -> "i"
                return strtolower(substr($tagName, 0, 1));
            }
        }

        return null;
    }
}
