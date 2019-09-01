<?php


namespace App\Synchronizer\Mapper\FieldMaps;


use App\Exceptions\ConfigException;
use App\Exceptions\ParseCrmDataException;
use App\Exceptions\ParseMailchimpDataException;

/**
 * Mapper for the email field
 *
 * @package App\Synchronizer\Mapper
 */
class FieldMapEmail extends FieldMap
{
    protected const MAILCHIMP_PARENT_KEY = '';
    private const MAILCHIMP_FIELD_KEY = 'email_address';
    
    private $value;
    
    /**
     * FieldMapMerge constructor.
     *
     * @param array $config {crmKey: string, mailchimpKey: string, sync: {'both'}}
     *
     * @throws ConfigException
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
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
        if (!array_key_exists(self::MAILCHIMP_FIELD_KEY, $data)) {
            throw new ParseMailchimpDataException(sprintf("Missing key '%s'", self::MAILCHIMP_PARENT_KEY));
        }
        
        if (empty($data[self::MAILCHIMP_FIELD_KEY])) {
            throw new ParseMailchimpDataException(sprintf("No data for '%s'", self::MAILCHIMP_PARENT_KEY));
        }
        
        $this->value = $data[self::MAILCHIMP_FIELD_KEY];
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
        
        $this->value = $data[$this->crmKey];
    }
    
    /**
     * Get key value pair ready for storing in the crm
     *
     * @return array
     */
    function getCrmDataArray()
    {
        return [$this->crmKey => $this->value];
    }
    
    /**
     * Get key value pair ready for storing in mailchimp
     *
     * @return array
     */
    function getMailchimpDataArray()
    {
        return [self::MAILCHIMP_FIELD_KEY => $this->value];
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