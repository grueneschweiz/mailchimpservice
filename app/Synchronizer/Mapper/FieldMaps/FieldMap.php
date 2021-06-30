<?php


namespace App\Synchronizer\Mapper\FieldMaps;


use App\Exceptions\ConfigException;
use App\Exceptions\ParseCrmDataException;
use App\Exceptions\ParseMailchimpDataException;
use App\Synchronizer\CrmValue;

abstract class FieldMap
{
    private const SYNC_BOTH = 'both';
    private const SYNC_TO_MAILCHIMP = 'toMailchimp';
    
    protected $crmKey;
    protected $sync;
    
    /**
     * FieldMap constructor.
     *
     * @param array $config single field config value as it is coming from the yaml file.
     *
     * @throws ConfigException
     */
    public function __construct(array $config)
    {
        if (empty($config['crmKey'])) {
            throw new ConfigException('Field: Missing crm key');
        }
        $this->crmKey = $config['crmKey'];
        
        if (empty($config['sync'])) {
            throw new ConfigException('Field: Missing sync definition');
        }
        if (!in_array($config['sync'], [self::SYNC_BOTH, self::SYNC_TO_MAILCHIMP])) {
            throw new ConfigException('Field: Unknown sync direction');
        }
        $this->sync = $config['sync'];
    }
    
    /**
     * @return bool
     */
    public function canSyncToCrm()
    {
        return self::SYNC_BOTH === $this->sync;
    }
    
    /**
     * @return bool
     */
    public function canSyncToMailchimp()
    {
        return $this->sync === self::SYNC_BOTH || $this->sync === self::SYNC_TO_MAILCHIMP;
    }
    
    /**
     * @return string
     */
    public function getCrmKey()
    {
        return $this->crmKey;
    }
    
    /**
     * Get key value pair ready for storing in the crm
     *
     * @return CrmValue
     */
    abstract function getCrmData();
    
    /**
     * Get key value pair ready for storing in mailchimp
     *
     * @return array
     */
    abstract function getMailchimpDataArray();
    
    /**
     * Get the field key, that will hold the data of this field (for mailchimp requests)
     *
     * @return string
     */
    abstract function getMailchimpParentKey();
    
    /**
     * Parse the payload from mailchimp and extract the values for this field
     *
     * @param array $data the payload from mailchimps API V3
     *
     * @throws ParseMailchimpDataException
     */
    abstract public function addMailchimpData(array $data);
    
    /**
     * Parse the payload from crm and extract the values for this field
     *
     * @param array $data the payload from the crm api
     *
     * @throws ParseCrmDataException
     */
    abstract public function addCrmData(array $data);
}