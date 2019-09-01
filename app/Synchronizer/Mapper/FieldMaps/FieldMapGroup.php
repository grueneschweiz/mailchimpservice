<?php


namespace App\Synchronizer\Mapper\FieldMaps;


use App\Exceptions\ConfigException;
use App\Exceptions\ParseCrmDataException;
use App\Exceptions\ParseMailchimpDataException;

/**
 * Mapper for the group fields
 *
 * Note: in the mailchimp api v3 this field is called 'interessts'
 *
 * @package App\Synchronizer\Mapper
 */
class FieldMapGroup extends FieldMap
{
    public const MAILCHIMP_PARENT_KEY = 'interests';
    
    private $mailchimpCategoryId;
    private $trueCondition;
    private $falseCondition;
    
    private $mailchimpValue;
    private $crmValue;
    
    /**
     * FieldMapMerge constructor.
     *
     * @param array $config {crmKey: string, mailchimpCategoryId: string, trueCondition: string, falseCondition: string, sync: {'both', 'toMailchimp'}}
     *
     * @throws ConfigException
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        if (empty($config['mailchimpCategoryId'])) {
            throw new ConfigException('Field: Missing mailchimpCategoryId definition');
        }
        $this->mailchimpCategoryId = $config['mailchimpCategoryId'];
        
        if (empty($config['trueCondition'])) {
            throw new ConfigException("Field: Missing 'trueCondition' definition");
        }
        $this->trueCondition = $config['trueCondition'];
        
        if (empty($config['falseCondition'])) {
            throw new ConfigException("Field: Missing 'falseCondition' definition");
        }
        $this->falseCondition = $config['falseCondition'];
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
        
        if (!array_key_exists($this->mailchimpCategoryId, $data[self::MAILCHIMP_PARENT_KEY])) {
            throw new ParseMailchimpDataException(sprintf(
                "The interest (also called group or category) with the id '%s' does not exist in mailchimp.",
                $this->mailchimpCategoryId
            ));
        }
        
        $inGroup = $data[self::MAILCHIMP_PARENT_KEY][$this->mailchimpCategoryId];
        
        $this->mailchimpValue = $inGroup;
        $this->crmValue = $inGroup ? $this->trueCondition : $this->falseCondition;
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
        
        $this->mailchimpValue = $data[$this->crmKey] === $this->trueCondition;
        $this->crmValue = $data[$this->crmKey];
    }
    
    /**
     * Get key value pair ready for storing in the crm
     *
     * @return array
     */
    function getCrmDataArray()
    {
        return [$this->crmKey => $this->crmValue];
    }
    
    /**
     * Get key value pair ready for storing in mailchimp
     *
     * @return array
     */
    function getMailchimpDataArray()
    {
        return [$this->mailchimpCategoryId => $this->mailchimpValue];
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