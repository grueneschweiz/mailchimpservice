<?php

namespace App\Http;

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
use DrewM\MailChimp\MailChimp;

class MailChimpClient
{
    private const MC_GET_LIMIT = 1000;
    private const API_WRITE_TIMEOUT = 30; // seconds
    
    /**
     * The Mailchimp client object
     *
     * @see https://github.com/drewm/mailchimp-api
     *
     * @var MailChimp
     */
    private $client;
    
    /**
     * The MailChimp api key
     *
     * @var string
     */
    private $apiKey;
    
    /**
     * The list we're working with
     *
     * @var string
     */
    private $listId;
    
    /**
     * In memory cache for all subscriber.
     *
     * Key: email, value: crmId
     *
     * @var array
     */
    private $subscribers;
    
    /**
     * @param string $api_key MailChimp api key
     *
     * @throws \Exception
     */
    public function __construct(string $api_key, string $listId)
    {
        $this->apiKey = $api_key;
        $this->listId = $listId;
        $this->client = new MailChimp($api_key);
    }
    
    /**
     * Get subscriber by email
     *
     * @param string $email
     *
     * @return array|false
     * @throws MailchimpClientException
     */
    public function getSubscriber(string $email)
    {
        $id = self::calculateSubscriberId($email);
        
        $get = $this->client->get("lists/{$this->listId}/members/$id", [], 30);
        $this->validateResponseStatus('GET subscriber', $get);
        $this->validateResponseContent('GET subscriber', $get);
        
        return $get;
    }
    
    /**
     * Calculate the id of the contact in mailchimp
     *
     * @see https://developer.mailchimp.com/documentation/mailchimp/guides/manage-subscribers-with-the-mailchimp-api/
     *
     * @param string $email
     *
     * @return string MD5 hash of the lowercase email address
     */
    public static function calculateSubscriberId(string $email)
    {
        $email = trim($email);
        $email = strtolower($email);
        
        return md5($email);
    }
    
    /**
     * Throw exception if we get a falsy response.
     *
     * @param string $method
     * @param $response
     *
     * @throws MailchimpClientException
     */
    private function validateResponseStatus(string $method, $response)
    {
        if (!$response) {
            throw new MailchimpClientException("$method request against Mailchimp failed: {$this->client->getLastError()}");
        }
    }
    
    /**
     * Throw exception if we get response with erroneous content.
     *
     * @param string $method
     * @param $response
     *
     * @throws MailchimpClientException
     */
    private function validateResponseContent(string $method, $response)
    {
        if (isset($response['status']) && is_numeric($response['status']) && $response['status'] !== 200) {
            $message = "$method request against Mailchimp failed (status code: {$response['status']}): {$response['detail']}";
            
            if (array_key_exists('errors', $response)) {
                foreach ($response['errors'] as $k => $v) {
                    $message .= " Errors[$k] => {$v['message']}";
                }
            }
            
            throw new MailchimpClientException($message);
        }
    }
    
    /**
     * Return the email address of the first subscriber that has the given value in the given merge tag field.
     *
     * Note: This function is really costly, since mailchimp's api doesn't allow to search by merge tag by april 2019.
     *
     * @param string $crmId
     * @param string $crmIdKey
     *
     * @return false|string email address on match else false
     * @throws MailchimpClientException
     */
    public function getSubscriberEmailByCrmId(string $crmId, string $crmIdKey)
    {
        $subscribers = $this->getAllSubscribers($crmIdKey);
        
        return array_search($crmId, $subscribers);
    }
    
    /**
     * Return cached array of all mailchimp entries with their email address as key and the crm id as value.
     *
     * Note: This function is really costly. We use it since mailchimp's api doesn't allow to search by merge tag by april 2019.
     *
     * @param string $crmIdKey
     *
     * @return array [email => crmId, ...]
     * @throws MailchimpClientException
     */
    private function getAllSubscribers(string $crmIdKey): array
    {
        if (null !== $this->subscribers) {
            return $this->subscribers;
        }
        
        $offset = 0;
        
        while (true) {
            $get = $this->client->get("lists/{$this->listId}/members?count=" . self::MC_GET_LIMIT . "&offset=$offset&fields=members.email_address,members.merge_fields", [], 30);
            
            $this->validateResponseStatus('GET multiple subscribers', $get);
            $this->validateResponseContent('GET multiple subscribers', $get);
            
            if (0 === count($get['members'])) {
                break;
            }
            
            foreach ($get['members'] as $member) {
                $this->subscribers[$member['email_address']] = $member['merge_fields'][$crmIdKey];
            }
            
            $offset += self::MC_GET_LIMIT;
        }
        
        if (!$this->subscribers) {
            $this->subscribers = [];
        }
        
        return $this->subscribers;
    }
    
    /**
     * Upsert subscriber
     *
     * @param array $mcData
     * @param string $email provide old email to update subscribers email address
     * @param string $id the mailchimp id of the subscriber
     *
     * @return array|false
     * @throws \InvalidArgumentException
     * @throws InvalidEmailException
     * @throws MailchimpClientException
     * @throws EmailComplianceException
     * @throws AlreadyInListException
     * @throws CleanedEmailException
     * @throws FakeEmailException
     * @throws UnsubscribedEmailException
     * @throws MergeFieldException
     * @throws MailchimpTooManySubscriptionsException
     * @throws ArchivedException
     */
    public function putSubscriber(array $mcData, string $email = null, string $id = null)
    {
        if (empty($mcData['email_address'])) {
            throw new \InvalidArgumentException('Missing email_address.');
        }
        
        if (!isset($mcData['status']) && !isset($mcData['status_if_new'])) {
            $mcData['status_if_new'] = 'subscribed';
        }
    
        if (!$email) {
            $email = $mcData['email_address'];
        }
    
        // it is possible, that the subscriber id differs from the lowercase email md5-hash (why?)
        // so we need a possibility to provide it manually.
        if (!$id) {
            $id = self::calculateSubscriberId($email);
        }
    
        $endpoint = "lists/{$this->listId}/members/$id";
        $put = $this->client->put($endpoint, $mcData, self::API_WRITE_TIMEOUT);
    
        $this->validateResponseStatus('PUT subscriber', $put);
        if (isset($put['status']) && is_numeric($put['status']) && $put['status'] !== 200) {
            $errorMsg = $put['errors'][0]['message'] ?? $put['detail'] ?? print_r($put, true);

            if (str_starts_with($errorMsg, 'Invalid email address')
                || strpos($errorMsg, 'provide a valid email address.')
            ) {
                throw new InvalidEmailException($errorMsg);
            }
            if (str_starts_with($errorMsg, 'This member\'s status is "cleaned."') ||
                (strpos($errorMsg, 'is already in this list with a status of "Cleaned".'))) {
                throw new CleanedEmailException($errorMsg);
            }
            if (str_starts_with($errorMsg, 'This member\'s status is "unsubscribed."') ||
                (strpos($errorMsg, 'is already in this list with a status of "Unsubscribed".')) ||
                (strpos($errorMsg, 'has previously unsubscribed from this list and must opt in again.')) ||
                (strpos($errorMsg, "was previously removed from this audience. To rejoin, they'll need to sign up using a Mailchimp form.")) ||
                (strpos($errorMsg, "was permanently deleted and cannot be re-imported. The contact must re-subscribe to get back on the list."))
            ) {
                throw new UnsubscribedEmailException($errorMsg);
            }
            if (strpos($errorMsg, 'is already a list member') ||
                (strpos($errorMsg, 'is already in this list with a status of "Deleted".')) ||
                (strpos($errorMsg, 'is already in this list with a status of "Subscribed".'))
            ) {
                throw new AlreadyInListException("{$errorMsg} Email used for id calc: $email. Called endpoint: $endpoint. Data: " . str_replace("\n", ', ', print_r($mcData, true)));
            }
            if (strpos($errorMsg, 'status is "archived."')
            ) {
                throw new ArchivedException($errorMsg);
            }
            if (strpos($errorMsg, 'compliance state')) {
                throw new EmailComplianceException($errorMsg);
            }
            if (strpos($errorMsg, 'looks fake or invalid, please enter a real email address.')) {
                throw new FakeEmailException($errorMsg);
            }
            if (strpos($errorMsg, 'merge fields were invalid')) {
                throw new MergeFieldException($errorMsg);
            }
            if (strpos($errorMsg, "has signed up to a lot of lists very recently; we're not allowing more signups for now.")
            ) {
                throw new MailchimpTooManySubscriptionsException($errorMsg);
            }
        }
        $this->validateResponseContent('PUT subscriber', $put);
        
        // this is needed for updates
        $this->updateSubscribersTags($put['id'], $mcData['tags']);
        
        return $put;
    }

    /**
     * Add or update tags for a subscriber
     *
     * @param string $subscriberId The MailChimp subscriber ID
     * @param array $tags Array of tag names (strings) or tag objects with 'name' and 'status'
     *
     * @throws MailchimpClientException
     */
    public function addTagsToSubscriber(string $subscriberId, array $tags)
    {
        if (empty($tags)) {
            return;
        }

        $formattedTags = [];
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $formattedTags[] = (object)['name' => $tag, 'status' => 'active'];
            }
        }

        $this->postSubscriberTags($subscriberId, ['tags' => $formattedTags]);
    }
    
    /**
     * Update tags of subscriber
     *
     * This must be done extra, because mailchimp doesn't allow to change the tags on subscriber update
     *
     * @param string $id
     * @param array $new the tags the subscriber should have
     *
     * @throws MailchimpClientException
     */
    private function updateSubscribersTags(string $id, array $new)
    {
        $get = $this->getSubscriberTags($id);
        
        $current = array_column($get['tags'], 'name');
        
        $update = [];
        foreach ($current as $currentTag) {
            if (!in_array($currentTag, $new)) {
                $update[] = (object)['name' => $currentTag, 'status' => 'inactive'];
            }
        }
        
        foreach ($new as $newTag) {
            // if we update a subscriber our $new array is two dimensional
            // if we insert, the $new array simply contains the tags
            if (is_array($newTag)) {
                $newTag = $newTag['name'];
            }
            if (!in_array($newTag, $current)) {
                $update[] = (object)['name' => $newTag, 'status' => 'active'];
            }
        }
        
        if (empty($update)) {
            return;
        }
        
        $this->postSubscriberTags($id, ['tags' => $update]);
    }
    
    /**
     * Get subscriber tags
     *
     * @param string $id
     *
     * @return array|false
     * @throws MailchimpClientException
     */
    private function getSubscriberTags(string $id)
    {
        // somehow we had a lot of timeouts when requesting the tags, therefore we increased the timeout
        $get = $this->client->get("lists/{$this->listId}/members/$id/tags", [], 30);
        
        $this->validateResponseStatus('GET tags', $get);
        $this->validateResponseContent('GET tags', $get);
        
        return $get;
    }
    
    /**
     * Update subscriber tags
     *
     * @param string $id
     * @param array $tags the tags that should be activated and the ones that should be deactivated
     *
     * @return array|false
     * @throws MailchimpClientException
     * @see https://developer.mailchimp.com/documentation/mailchimp/reference/lists/members/tags/#%20
     *
     */
    private function postSubscriberTags(string $id, array $tags)
    {
        $post = $this->client->post("lists/{$this->listId}/members/$id/tags", $tags, self::API_WRITE_TIMEOUT);
        
        $this->validateResponseStatus('POST tags', $post);
        $this->validateResponseContent('POST tags', $post);
        
        return $post;
    }
    
    /**
     * Delete subscriber
     *
     * @param string $email
     *
     * @throws MailchimpClientException
     * @throws MemberDeleteException
     */
    public function deleteSubscriber(string $email)
    {
        $id = self::calculateSubscriberId($email);
        $delete = $this->client->delete("lists/{$this->listId}/members/$id", [], self::API_WRITE_TIMEOUT);
    
        $this->validateResponseStatus('DELETE subscriber', $delete);
        if (isset($delete['status']) && is_numeric($delete['status']) && $delete['status'] !== 200) {
            if (isset($delete['detail']) && strpos($delete['detail'], 'member cannot be removed')) {
                throw new MemberDeleteException($delete['detail']);
            }
        }
        if (isset($delete['status']) && $delete['status'] === 404) {
            // the record we wanted to delete does not exist and hence our request is satisfied.
            return;
        }
    
        $this->validateResponseContent('DELETE subscriber', $delete);
    }
    
    /**
     * Delete subscriber permanently
     *
     * @param string $email
     *
     * @throws MailchimpClientException
     */
    public function permanentlyDeleteSubscriber(string $email)
    {
        $id = self::calculateSubscriberId($email);
        $delete = $this->client->post("lists/{$this->listId}/members/$id/actions/delete-permanent", [], self::API_WRITE_TIMEOUT);
        
        $this->validateResponseStatus('DELETE subscriber permanently', $delete);
        $this->validateResponseContent('DELETE subscriber permanently', $delete);
    }
}
