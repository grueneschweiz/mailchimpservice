<?php

namespace App\Synchronizer;

use App\Http\CrmClient;
use App\Http\MailChimpClient;
use App\Synchronizer\Mapper\Mapper;
use App\Synchronizer\Config;
use App\Synchronizer\LogTrait;

/**
 * Abstract base class for synchronizer implementations
 */
abstract class MailchimpToCrmSynchronizer
{
    use LogTrait;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var string
     */
    protected $configName;

    /**
     * @var MailChimpClient
     */
    protected $mcClient;

    /**
     * @var CrmClient
     */
    protected $crmClient;

    /**
     * @var Mapper
     */
    protected $mapper;

    /**
     * MailchimpToCrmSynchronizer constructor.
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

        $this->initializeClients();
    }

    /**
     * Initialize the clients based on configuration
     *
     * @throws \App\Exceptions\ConfigException
     */
    protected function initializeClients(): void
    {
        $crmCred = $this->config->getCrmCredentials();
        $this->crmClient = new CrmClient($crmCred['clientId'], $crmCred['clientSecret'], $crmCred['url']);

        $this->mcClient = new MailChimpClient($this->config->getMailchimpCredentials()['apikey'], $this->config->getMailchimpListId());
        $this->initializeMapper();
    }

    /**
     * Initialize the mapper with field mappings from config
     */
    protected function initializeMapper(): void
    {
        $this->mapper = new Mapper($this->config->getFieldMaps());
    }

    /**
     * Log webhook events with consistent formatting
     *
     * @param string $method The log level (debug, info, error, etc.)
     * @param string $webhook The webhook type
     * @param string $record The record identifier
     * @param string $message The log message
     * @param mixed $crmId The CRM ID (can be string or int)
     */
    protected function logWebhook(string $method, string $webhook, string $record, string $message, $crmId = -1): void
    {
        $crmIdParam = $crmId !== -1 && $crmId !== '' ? " crmId=\"$crmId\" " : " ";
        $this->log($method, $message, "webhook=\"$webhook\" record=\"$record\"$crmIdParam");
    }
}
