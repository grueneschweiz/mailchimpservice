<?php


namespace App\Synchronizer\Mapper\FieldMaps;


use App\Exceptions\ConfigException;
use App\Exceptions\ParseCrmDataException;
use App\Exceptions\ParseMailchimpDataException;
use App\Synchronizer\CrmValue;
use App\Synchronizer\Mapper\FieldMaps\GroupConditions\GroupConditionFactory;
use App\Synchronizer\Mapper\FieldMaps\GroupConditions\GroupConditionInterface;

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
    
    private string $mailchimpCategoryId;
    
    private GroupConditionInterface $condition;
    
    /**
     * FieldMapMerge constructor.
     *
     * @param array $config {
     *  crmKey: string,
     *  mailchimpCategoryId: string,
     *  (trueCondition|trueContainsString): string,
     *  (falseCondition|trueContainsString): string,
     *  sync: {'both', 'toMailchimp'}
     * }
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
    
        $this->condition = GroupConditionFactory::makeFrom($config);
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
    
        $this->condition->setFromBool($inGroup);
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
    
        $this->condition->setFromCrmData($data[$this->crmKey]);
    }
    
    /**
     * Get key value pair ready for storing in the crm
     *
     * @return CrmValue
     */
    function getCrmData(): CrmValue
    {
        return $this->condition->getCrmValue($this->crmKey);
    }
    
    /**
     * Get key value pair ready for storing in mailchimp
     *
     * @return array
     */
    function getMailchimpDataArray()
    {
        return [$this->mailchimpCategoryId => $this->condition->getMailchimpValue()];
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