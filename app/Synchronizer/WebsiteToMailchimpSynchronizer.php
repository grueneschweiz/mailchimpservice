<?php

namespace App\Synchronizer;

use App\Synchronizer\Mapper\Mapper;
use App\Http\MailChimpClient;
use App\Synchronizer\Filter;

class WebsiteToMailchimpSynchronizer
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var string
     */
    private $configName;

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

        $mcCred = $this->config->getMailchimpCredentials();
        $this->mailchimpClient = new MailChimpClient($mcCred['apikey'], $this->config->getMailchimpListId());

        $this->filter = new Filter($this->config->getFieldMaps(), $this->config->getSyncAll());
        $this->mapper = new Mapper($this->config->getFieldMaps());
    }

    /**
     * Synchronize a single contact from website data to Mailchimp
     *
     * @param array $websiteData Data from website in internal format (keys match crmKey from config)
     * 
     * @return array The Mailchimp API response
     * 
     * @throws \App\Exceptions\InvalidEmailException
     * @throws \App\Exceptions\EmailComplianceException
     * @throws \App\Exceptions\MailchimpClientException
     * @throws \App\Exceptions\AlreadyInListException
     * @throws \App\Exceptions\FakeEmailException
     * @throws \App\Exceptions\UnsubscribedEmailException
     * @throws \App\Exceptions\MergeFieldException
     * @throws \App\Exceptions\MailchimpTooManySubscriptionsException
     * @throws \App\Exceptions\ArchivedException
     */
    public function syncSingle(array $websiteData)
    {
        // Validate that we have an email address
        $emailField = $this->getEmailFieldFromConfig();
        if (empty($websiteData[$emailField])) {
            throw new \App\Exceptions\InvalidEmailException('Email address is required');
        }

        // Normalize the email address
        $websiteData[$emailField] = strtolower(trim($websiteData[$emailField]));

        // Fill missing CRM keys with empty values
        $websiteData = $this->fillMissingCrmKeys($websiteData);

        // Validate email format
        if (!filter_var($websiteData[$emailField], FILTER_VALIDATE_EMAIL)) {
            throw new \App\Exceptions\InvalidEmailException('Invalid email format: ' . $websiteData[$emailField]);
        }

        // Map the website data to Mailchimp format
        $mailchimpData = $this->mapper->crmToMailchimp($websiteData);

        // Ensure we have the email address in the correct format for Mailchimp
        $mailchimpData['email_address'] = $websiteData[$emailField];

        // Set the status to subscribed for new subscribers
        $mailchimpData['status'] = 'subscribed';

        // Add the subscriber to Mailchimp
        $response = $this->mailchimpClient->putSubscriber($mailchimpData);

        // Add tags to new subscriber
        $tags = [$this->config->getNewTag()];
        if (isset($websiteData['notesCountry'])) {
            $tags[] = $websiteData['notesCountry'];
        }
        if(isset($mailchimpData['tags']) && is_array($mailchimpData['tags'])) {
            $tags = array_merge($tags, $mailchimpData['tags']);
        }
        $this->mailchimpClient->addTagsToSubscriber($response['id'], $tags);

        return $response;
    }

    /**
     * Ensures all required CRM keys are present in the data array, filling missing ones with empty values.
     *
     * @param array $data
     * @return array
     */
    private function fillMissingCrmKeys(array $data): array
    {
        foreach ($this->config->getFieldMaps() as $fieldMap) {
            $crmKey = $fieldMap->getCrmKey();
            if (!array_key_exists($crmKey, $data)) {
                $data[$crmKey] = null;
            }
        }
        return $data;
    }

    /**
     * Get the email field name from the config
     *
     * @return string The email field name
     * @throws \App\Exceptions\ConfigException
     */
    private function getEmailFieldFromConfig(): string
    {
        $fieldMaps = $this->config->getFieldMaps();

        foreach ($fieldMaps as $fieldMap) {
            if ($fieldMap->isEmail()) {
                return $fieldMap->getCrmKey();
            }
        }

        throw new \App\Exceptions\ConfigException('No email field defined in configuration');
    }
}
