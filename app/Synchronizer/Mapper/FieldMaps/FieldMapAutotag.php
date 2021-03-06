<?php


namespace App\Synchronizer\Mapper\FieldMaps;


use App\Exceptions\ConfigException;
use App\Exceptions\ParseCrmDataException;
use App\Exceptions\ParseMailchimpDataException;
use App\Synchronizer\CrmValue;

/**
 * Mapper for tag fields
 *
 * @package App\Synchronizer\Mapper
 */
class FieldMapAutotag extends FieldMap
{
    protected const MAILCHIMP_PARENT_KEY = 'tags';
    
    private $mailchimpTagNames;
    
    /**
     * FieldMapMerge constructor.
     *
     * @param array $config {crmKey: string, mailchimpTagName: string, conditions: string[], sync: {'toMailchimp'}}
     *
     * @throws ConfigException
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        if ($this->canSyncToCrm()) {
            throw new ConfigException("Syncing to crm is not allowed for field type 'tag'.");
        }
    }
    
    /**
     * Parse the payload from mailchimp and extract the values for this field
     *
     * @param array $data the payload from mailchimps API V3
     *
     * @throws ParseMailchimpDataException
     */
    public function addMailchimpData(array $data)
    {
        // don't do anything, we don't allow syncing from mailchimp to crm
    }
    
    /**
     * Parse the payload from crm and extract the values for this field
     *
     * @param array $data the payload from the crm api
     *
     * @throws ParseCrmDataException
     */
    public function addCrmData(array $data)
    {
        if (!array_key_exists($this->crmKey, $data)) {
            throw new ParseCrmDataException(sprintf("Missing key '%s'", $this->crmKey));
        }
        
        $this->mailchimpTagNames = (array)$data[$this->crmKey];
    }
    
    /**
     * Get key value pair ready for storing in the crm
     *
     * @return CrmValue[]
     */
    function getCrmData(): array
    {
        return []; // don't do anything, we don't allow syncing from mailchimp to crm
    }
    
    /**
     * Get key value pair ready for storing in mailchimp
     *
     * @return array
     */
    function getMailchimpDataArray()
    {
        return $this->mailchimpTagNames;
    }
    
    /**
     * Get the field key, that will hold the data of this field (for mailchimp requests)
     *
     * @return string
     */
    function getMailchimpParentKey()
    {
        return self::MAILCHIMP_PARENT_KEY;
    }
}