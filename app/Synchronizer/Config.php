<?php


namespace App\Synchronizer;


use App\Exceptions\ConfigException;
use App\Synchronizer\Mapper\FieldMapFacade;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Config
{
    private const CRM_ID_KEY = 'id';

    private $fields;
    private $auth;
    private $dataOwner;
    private $mailchimp;
    private $mailchimpToCrm;
    private $errors;
    private $crmEmailKey;
    private $webling;

    /**
     * Config constructor.
     *
     * @param string $configFileName file name of the config file
     *
     * @throws ConfigException
     */
    public function __construct(string $configFileName)
    {
        $this->loadConfig($configFileName);
    }

    /**
     * Read the config file and populate object
     *
     * @param string $configFileName file name of the config file
     *
     * @throws ConfigException
     */
    private function loadConfig(string $configFileName)
    {
        $configFileName = ltrim($configFileName, './');
        $configFolderPath = rtrim(config('app.config_base_path'), '/');
        $configFilePath = base_path($configFolderPath . '/' . $configFileName);

        if (!file_exists($configFilePath)) {
            throw new ConfigException('The config file was not found.');
        }

        try {
            $config = Yaml::parseFile($configFilePath);
        } catch (ParseException $e) {
            throw new ConfigException("YAML parse error: {$e->getMessage()}");
        }

        // prevalidate config
        if ($config['auth']) {
            $this->auth = $config['auth'];
        } else {
            throw new ConfigException("Missing 'auth' section.");
        }

        if ($config['dataOwner']) {
            $this->dataOwner = $config['dataOwner'];
        } else {
            throw new ConfigException("Missing 'dataOwner' section.");
        }

        if ($config['mailchimp']) {
            $this->mailchimp = $config['mailchimp'];
        } else {
            throw new ConfigException("Missing 'mailchimp' section.");
        }

        if (isset($config['mailchimpToCrm'])) {
            $this->mailchimpToCrm = $config['mailchimpToCrm'];
            $this->mailchimpToCrm['isUpsertToCrmEnabled'] = true;
        } else {
            $this->mailchimpToCrm['isUpsertToCrmEnabled'] = false;
        }

        if ($config['fields']) {
            $this->fields = $config['fields'];
        } else {
            throw new ConfigException("Missing 'fields' section.");
        }

        if (isset($config['webling'])) {
            $this->webling = $config['webling'];
        }
    }

    public static function getCrmIdKey()
    {
        return self::CRM_ID_KEY;
    }

    /**
     * Return array with crm credentials
     *
     * @return array {clientId: string, clientSecret: string, url: string}
     *
     * @throws ConfigException
     */
    public function getCrmCredentials(): array
    {
        if (
            empty($this->auth['crm'])
            || empty($this->auth['crm']['clientId'])
            || empty($this->auth['crm']['clientSecret'])
            || empty($this->auth['crm']['url'])
        ) {
            throw new ConfigException("Missing CRM credentials.");
        }

        return $this->auth['crm'];
    }

    /**
     * Return array with mailchimp credentials
     *
     * @return array {apikey: string, url: string}
     *
     * @throws ConfigException
     */
    public function getMailchimpCredentials(): array
    {
        if (
            empty($this->auth['mailchimp'])
            || empty($this->auth['mailchimp']['apikey'])
        ) {
            throw new ConfigException("Missing Mailchimp credentials.");
        }

        return $this->auth['mailchimp'];
    }

    /**
     * Return array with name and email of data owner
     *
     * @return array {email: string, name: string,}
     *
     * @throws ConfigException
     */
    public function getDataOwner(): array
    {
        if (
            empty($this->dataOwner)
            || empty($this->dataOwner['email'])
            || empty($this->dataOwner['name'])
        ) {
            throw new ConfigException("Missing data owner details.");
        }

        return $this->dataOwner;
    }

    /**
     * Return bool that indicates if all records should be synced even if they dont have
     * relevant subscriptions
     *
     * @return bool
     */
    public function getSyncAll(): bool
    {
        if (array_key_exists('syncAll', $this->mailchimp)) {
            return filter_var($this->mailchimp['syncAll'], FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * Return bool that indicates if subscriptions through mailchimp should be ignored
     *
     * @return bool
     */
    public function getIgnoreSubscribeThroughMailchimp(): bool
    {
        if (array_key_exists('ignoreSubscribeThroughMailchimp', $this->mailchimp)) {
            return filter_var($this->mailchimp['ignoreSubscribeThroughMailchimp'], FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * Return the default list id in Mailchimp
     *
     * @return string the list id
     *
     * @throws ConfigException
     */
    public function getMailchimpListId(): string
    {
        if (empty($this->mailchimp['listId'])) {
            throw new ConfigException("Missing mailchimp list id.");
        }

        return $this->mailchimp['listId'];
    }

    /**
     * The mailchimp merge field key that corresponds to the crm's id
     *
     * @return string
     * @throws ConfigException
     */
    public function getMailchimpKeyOfCrmId(): string
    {
        foreach ($this->getFieldMaps() as $map) {
            if (self::CRM_ID_KEY === $map->getCrmKey()) {
                $keys = array_keys($map->getMailchimpDataArray());

                return reset($keys);
            }
        }

        throw new ConfigException('Missing "' . self::CRM_ID_KEY . '" field.');
    }

    /**
     * Return array with the field maps
     *
     * @return FieldMapFacade[]
     *
     * @throws ConfigException
     */
    public function getFieldMaps(): array
    {
        if (!is_array($this->fields)) {
            throw new ConfigException("Fields configuration must be an array.");
        }

        $fields = [];
        foreach ($this->fields as $config) {
            $fields[] = new FieldMapFacade($config);
        }

        return $fields;
    }

    /**
     * Return the field key in the crm that corresponds to the email field
     *
     * @return string
     * @throws ConfigException
     */
    public function getCrmEmailKey(): string
    {
        if ($this->crmEmailKey) {
            return $this->crmEmailKey;
        }

        foreach ($this->getFieldMaps() as $map) {
            if ($map->isEmail()) {
                $this->crmEmailKey = $map->getCrmKey();

                return $this->crmEmailKey;
            }
        }

        throw new ConfigException('Missing email field.');
    }

    /**
     * Return array of the Webling group ids of the prioritized groups
     *
     * @return int[]
     */
    public function getPrioritizedGroups(): array
    {
        return $this->webling['prioritizedGroups'] ?? [];
    }

    /**
     * Return the validation errors of this config
     *
     * @return array
     */
    public function getErrors(): array
    {
        if (is_null($this->errors)) {
            $this->isValid();
        }

        return $this->errors;
    }

    /**
     * Return true, if the given config is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        $methods = [
            'getCrmCredentials',
            'getDataOwner',
            'getFieldMaps',
            'getMailchimpCredentials',
            'getMailchimpKeyOfCrmId',
            'getMailchimpListId'
        ];

        $this->errors = [];
        foreach ($methods as $method) {
            // we throw errors and catch them, because one can also change the config
            // while the endpoint is already established, so the validation is not
            // necessarily executed.
            try {
                $this->$method();
            } catch (ConfigException $e) {
                $this->errors[] = $e->getMessage();
            }
        }

        return empty($this->errors);
    }

    /**
     * Get language tags from the config
     *
     * @return array List of language tag names
     */
    public function getLanguageTagsFromConfig(): array
    {
        $languageTags = [];
        $fieldMaps = $this->getFieldMaps();

        foreach ($fieldMaps as $fieldMap) {
            if (
                $fieldMap->getMailchimpParentKey() === 'tags' &&
                $fieldMap->canSyncToMailchimp() &&
                $fieldMap->getCrmKey() === 'language'
            ) {
                $reflection = new \ReflectionObject($fieldMap);
                $property = $reflection->getProperty('field');
                $property->setAccessible(true);
                $field = $property->getValue($fieldMap);

                $tagReflection = new \ReflectionObject($field);
                $tagNameProperty = $tagReflection->getProperty('mailchimpTagName');
                $tagNameProperty->setAccessible(true);
                $tagName = $tagNameProperty->getValue($field);

                if (!empty($tagName)) {
                    $languageTags[] = $tagName;
                }
            }
        }

        return $languageTags;
    }

    /**
     * Return bool that indicates if upserting to CRM is enabled
     *
     * @return bool
     */
    public function isUpsertToCrmEnabled(): bool
    {
        return !empty($this->mailchimpToCrm['isUpsertToCrmEnabled']);
    }

    /**
     * Return the tag that should be added to new subscribers
     *
     * @return string
     */
    public function getNewTag(): string
    {
        if (empty($this->mailchimpToCrm['newtag'])) {
            throw new ConfigException('Missing "newtag" field.');
        }

        return $this->mailchimpToCrm['newtag'];
    }
    /**
     * Return array of keys that should trigger an upsert to CRM when set to 'yes'
     * If the configuration is an array, returns that array
     * Otherwise returns an empty array (no upsert)
     *
     * @return array
     */
    public function getInterestsToSync(): array
    {
        if (array_key_exists('interestsToSync', $this->mailchimpToCrm)) {
            $config = $this->mailchimpToCrm['interestsToSync'];

            if (is_array($config)) {
                return $config;
            }
        }

        return [];
    }

    /**
     * Return the number of months to consider for the changed within filter
     *
     * @return int
     */
    public function getChangedWithinMonths(): int
    {
        return $this->mailchimpToCrm['changedWithinMonths'] ?? 6;
    }

    /**
     * Return the number of months to consider for the opt-in older than filter
     *
     * @return int
     */
    public function getOptInOlderThanMonths(): int
    {
        return $this->mailchimpToCrm['optInOlderThanMonths'] ?? 2;
    }

    /**
     * Return the group where new people should be added in the crm
     *
     * @return int
     */
    public function getGroupForNewMembers(): int
    {
        if (empty($this->mailchimpToCrm['groupForNewMembers'])) {
            throw new ConfigException('Missing "groupForNewMembers" field.');
        }

        return $this->mailchimpToCrm['groupForNewMembers'];
    }
}
