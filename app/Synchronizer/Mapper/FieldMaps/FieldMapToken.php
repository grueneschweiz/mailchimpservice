<?php


namespace App\Synchronizer\Mapper\FieldMaps;


use App\Exceptions\ConfigException;
use App\Exceptions\ParseCrmDataException;
use App\Exceptions\ParseMailchimpDataException;
use App\Synchronizer\CrmValue;

/**
 * Mapper for token merge field
 *
 * @package App\Synchronizer\Mapper
 */
class FieldMapToken extends FieldMap
{
    private const MAILCHIMP_PARENT_KEY = 'merge_fields';
    
    private $mailchimpKey;
    private $default = '';
    private $value;
    private $validUntil;
    private $secret;
    
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
        
        if (empty($config['valid'])) {
            throw new ConfigException('Field: Missing definition for key "valid".');
        }
        if (!$this->setValidUntil($config['valid'])) {
            throw new ConfigException('Field: Invalid time span for key "valid". See https://www.php.net/manual/en/datetime.formats.php');
        }
        
        if (empty($config['secret'])) {
            throw new ConfigException('Field: Missing or empty secret.');
        }
        $this->secret = $config['secret'];
    }
    
    private function setValidUntil(string $valid): bool
    {
        $validUntil = date_create($valid);
        if ($validUntil === false) {
            return false;
        }
        
        $this->validUntil = $validUntil;
        return true;
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
        
        $validUntilDate = $this->validUntil->format('Y-m-d');
        $email = strtolower(trim($data[$this->crmKey]));
        $secret = $this->secret;
        
        $this->value = hash_hmac('sha256', $email . $validUntilDate, $secret);
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