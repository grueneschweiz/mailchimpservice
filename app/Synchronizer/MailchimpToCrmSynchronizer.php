<?php


namespace App\Synchronizer;


use App\Exceptions\ConfigException;
use App\Http\CrmClient;
use App\Http\MailChimpClient;
use App\Mail\WrongSubscription;
use App\Synchronizer\Mapper\FieldMaps\FieldMapGroup;
use App\Synchronizer\Mapper\Mapper;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Mail;

class MailchimpToCrmSynchronizer
{
    use LogTrait;
    
    /**
     * Mailchimp webhook event types
     *
     * @see https://developer.mailchimp.com/documentation/mailchimp/guides/about-webhooks/
     */
    private const MC_EMAIL_UPDATE = 'upemail';
    private const MC_CLEANED_EMAIL = 'cleaned';
    private const MC_SUBSCRIBE = 'subscribe';
    private const MC_UNSUBSCRIBE = 'unsubscribe';
    private const MC_PROFILE_UPDATE = 'profile';
    
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
    private $mcClient;
    
    /**
     * Synchronizer constructor.
     *
     * @param Config $config
     * @param int $userId
     *
     * @throws \App\Exceptions\ConfigException
     * @throws \Exception
     */
    public function __construct(string $configFileName)
    {
        $this->config = new Config($configFileName);
        $this->configName = $configFileName;
        
        $crmCred = $this->config->getCrmCredentials();
        
        $this->crmClient = new CrmClient($crmCred['clientId'], $crmCred['clientSecret'], $crmCred['url']);
        $this->mcClient = new MailChimpClient($this->config->getMailchimpCredentials()['apikey'], $this->config->getMailchimpListId());
    }
    
    /**
     * Sync single record from mailchimp to the crm. Usually called via mailchimp webhook.
     *
     * @param array $mcData
     *
     * @throws \App\Exceptions\ConfigException
     * @throws \App\Exceptions\ParseMailchimpDataException
     * @throws GuzzleException
     * @throws \App\Exceptions\ParseCrmDataException
     * @throws \Exception
     */
    public function syncSingle(array $mcData)
    {
        $mapper = new Mapper($this->config->getFieldMaps());
        
        $email = isset($mcData['data']['new_email']) ? $mcData['data']['new_email'] : $mcData['data']['email'];
        
        $callType = $mcData['type'];
        $mailchimpId = MailChimpClient::calculateSubscriberId($email);
    
        $this->logWebhook('debug', $callType, $mailchimpId, "Sync single record from Mailchimp to CRM.");
        
        switch ($callType) {
            case self::MC_SUBSCRIBE:
                $mcData = $this->mcClient->getSubscriber($email);
                $mergeFields = $this->extractMergeFields($mcData);
    
                // if there is no crm id
                if (empty($mergeFields[$this->config->getMailchimpKeyOfCrmId()])) {
                    // send mail to dataOwner, that he should
                    // add the subscriber to webling not mailchimp
                    $this->sendMailSubscribeOnlyInWebling($this->config->getDataOwner(), $mcData);
                    $this->logWebhook('debug', $callType, $mailchimpId, "Notified data owner.");
                }
    
                return;
    
            case self::MC_UNSUBSCRIBE:
                $mergeFields = $this->extractMergeFields($mcData['data']);
                $crmId = $mergeFields[$this->config->getMailchimpKeyOfCrmId()];
                if (empty($crmId)) {
                    $this->logWebhook('debug', $callType, $mailchimpId, "Record not linked to crm. No action taken.");
                    break;
                }
    
                // get contact from crm
                // set all subscriptions, that are configured in the currently loaded config file, to NO
                $get = $this->crmClient->get('member/' . $crmId);
                $crmData = json_decode((string)$get->getBody(), true);
                $crmData = $this->unsubscribeAll($crmData);
                $this->logWebhook('debug', $callType, $mailchimpId, "Unsubscribe member in crm.", $crmId);
                break;
            
            case self::MC_CLEANED_EMAIL:
                // set email1 to invalid
                // add note 'email set to invalid because it bounced in mailchimp'
                if ('hard' !== $mcData['data']['reason']) {
                    $this->logWebhook('debug', $callType, $mailchimpId, "Bounce not hard. No action taken.");
    
                    return;
                }
                $mcData = $this->mcClient->getSubscriber($email);
                $mergeFields = $this->extractMergeFields($mcData);
                $crmId = $mergeFields[$this->config->getMailchimpKeyOfCrmId()];
                $note = sprintf("%s: Mailchimp reported the email as invalid. Email status changed.", date('Y-m-d H:i'));
                $crmData['emailStatus'] = [['value' => 'invalid', 'mode' => CrmValue::MODE_REPLACE]];
                $crmData['notesCountry'] = [['value' => $note, 'mode' => CrmValue::MODE_APPEND]];
                $this->logWebhook('debug', $callType, $mailchimpId, "Mark email invalid in crm.", $crmId);
                break;
            
            case self::MC_PROFILE_UPDATE:
                // get subscriber from mailchimp (so we have the interessts (groups) in a usable format)
                // update email1, subscriptions
                $mcData = $this->mcClient->getSubscriber($email);
                $mergeFields = $this->extractMergeFields($mcData);
                $crmId = $mergeFields[$this->config->getMailchimpKeyOfCrmId()];
                $crmData = $mapper->mailchimpToCrm($mcData);
                $this->logWebhook('debug', $callType, $mailchimpId, "Update subscriptions in crm.", $crmId);
                break;
            
            case self::MC_EMAIL_UPDATE:
                // update email1
                $mcData = $this->mcClient->getSubscriber($email);
                $mergeFields = $this->extractMergeFields($mcData);
                $crmId = $mergeFields[$this->config->getMailchimpKeyOfCrmId()];
                $crmValue = $this->updateEmail($mcData)[0];
                $crmData = [$crmValue->getKey() => [['value' => $crmValue->getValue(), 'mode' => $crmValue->getMode()]]];
                $this->logWebhook('debug', $callType, $mailchimpId, "Update email in crm.", $crmId);
                break;
    
            default:
                $this->logWebhook('notice', $callType, $mailchimpId, __METHOD__ . " was called with an undefined webhook event.");
        }
    
        $this->crmClient->put('member/' . $crmId, $crmData);
    
        $this->logWebhook('debug', $callType, $mailchimpId, "Sync successful");
    }
    
    private function extractMergeFields(array $mcData)
    {
        return $mcData['merges'] ?? $mcData['merge_fields'];
    }
    
    /**
     * Inform data owner that he should only add contact in the crm not in mailchimp
     *
     * @param array $dataOwner
     * @param array $mcData
     */
    private function sendMailSubscribeOnlyInWebling(array $dataOwner, array $mcData)
    {
        $mergeFields = $this->extractMergeFields($mcData);
        
        $mailData = new \stdClass();
        $mailData->dataOwnerName = $dataOwner['name'];
        $mailData->contactFirstName = $mergeFields['FNAME']; // todo: check if we cant get the field keys dynamically
        $mailData->contactLastName = $mergeFields['LNAME']; // todo: dito
        $mailData->contactEmail = $mcData['email_address'];
        $mailData->adminEmail = env('ADMIN_EMAIL');
        $mailData->configName = $this->configName;
        
        Mail::to($dataOwner['email'])
            ->send(new WrongSubscription($mailData));
    }
    
    /**
     * Return $crmData with all subscriptions disabled
     *
     * @param array $crmData
     *
     * @return array in crmData format
     *
     * @throws \App\Exceptions\ConfigException
     * @throws \App\Exceptions\ParseCrmDataException
     * @throws \App\Exceptions\ParseMailchimpDataException
     */
    private function unsubscribeAll(array $crmData): array
    {
        $mapper = new Mapper($this->config->getFieldMaps());
        $mcData = $mapper->crmToMailchimp($crmData);
        
        foreach ($mcData[FieldMapGroup::MAILCHIMP_PARENT_KEY] as $key => $value) {
            $mcData[FieldMapGroup::MAILCHIMP_PARENT_KEY][$key] = false;
        }
        
        return $mapper->mailchimpToCrm($mcData);
    }
    
    /**
     * Update the email field in $crmData according to the email in $mcData
     *
     * @param array $mcData
     *
     * @return CrmValue[] of the email field
     *
     * @throws \App\Exceptions\ConfigException
     * @throws \App\Exceptions\ParseMailchimpDataException
     */
    private function updateEmail(array $mcData): array
    {
        foreach ($this->config->getFieldMaps() as $map) {
            if ($map->isEmail()) {
                $map->addMailchimpData($mcData);
            
                return $map->getCrmData();
            }
        }
    
        throw new ConfigException('No field of type "email"');
    }
    
    private function logWebhook(string $method, string $webhook, string $record, string $message, int $crmId = -1)
    {
        $crmIdParam = $crmId >= 0 ? " crmId=\"$crmId\" " : " ";
        $this->log($method, $message, "webhook=\"$webhook\" record=\"$record\"$crmIdParam");
    }
}