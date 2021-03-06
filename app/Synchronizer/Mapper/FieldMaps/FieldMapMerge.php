<?php


namespace App\Synchronizer\Mapper\FieldMaps;


use App\Exceptions\ConfigException;
use App\Exceptions\ParseCrmDataException;
use App\Exceptions\ParseMailchimpDataException;
use App\Synchronizer\CrmValue;

/**
 * Mapper for merge fields
 *
 * @package App\Synchronizer\Mapper
 */
class FieldMapMerge extends FieldMap
{
    private const MAILCHIMP_PARENT_KEY = 'merge_fields';
    
    private $mailchimpKey;
    private $default = '';
    private $value;
    
    /**
     * FieldMapMerge constructor.
     *
     * @param array $config {crmKey: string, mailchimpKey: string, sync: {'both', 'toMailchimp'}, [default: string]}
     *
     * @throws ConfigException
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        if (empty($config['mailchimpKey'])) {
            throw new ConfigException('Field: Missing mailchimp key');
        }
        $this->mailchimpKey = $config['mailchimpKey'];
        
        if (isset($config['default'])) {
            $this->default = $config['default'];
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
        if (!array_key_exists(self::MAILCHIMP_PARENT_KEY, $data)) {
            throw new ParseMailchimpDataException(sprintf("Missing key '%s'", self::MAILCHIMP_PARENT_KEY));
        }
        
        if (!array_key_exists($this->mailchimpKey, $data[self::MAILCHIMP_PARENT_KEY])) {
            throw new ParseMailchimpDataException("Missing merge field '{$this->mailchimpKey}'");
        }
        
        if (!empty($data[self::MAILCHIMP_PARENT_KEY][$this->mailchimpKey])) {
            $this->value = $data[self::MAILCHIMP_PARENT_KEY][$this->mailchimpKey];
        } else {
            $this->value = $this->default;
        }
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
        
        if (!empty($data[$this->crmKey])) {
            $clean = trim(preg_replace('/\s+/', ' ', $data[$this->crmKey]));
            $this->value = $clean;
        } else {
            $this->value = $this->default;
        }
    }
    
    /**
     * Get key value pair ready for storing in the crm
     *
     * @return CrmValue[]
     */
    function getCrmData(): array
    {
        return [new CrmValue($this->crmKey, $this->value, CrmValue::MODE_REPLACE)];
    }
    
    /**
     * Get key value pair ready for storing in mailchimp
     *
     * @return array
     */
    function getMailchimpDataArray()
    {
        return [$this->mailchimpKey => $this->value];
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