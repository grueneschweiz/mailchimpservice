<?php


namespace App\Synchronizer\Mapper;


use App\Exceptions\ConfigException;
use App\Synchronizer\Mapper\FieldMaps\FieldMapAutotag;
use App\Synchronizer\Mapper\FieldMaps\FieldMapEmail;
use App\Synchronizer\Mapper\FieldMaps\FieldMapGroup;
use App\Synchronizer\Mapper\FieldMaps\FieldMapMerge;
use App\Synchronizer\Mapper\FieldMaps\FieldMapTag;

class FieldMapFacade
{
    const TYPE_MERGE = 'merge';
    const TYPE_EMAIL = 'email';
    const TYPE_GROUP = 'group';
    const TYPE_TAG = 'tag';
    const TYPE_AUTOTAG = 'autotag';
    
    private $field;
    
    /**
     * FieldMapFacade constructor.
     *
     * @param array $config the fields section of the config file
     *
     * @throws ConfigException
     *
     * @see /path/to/project/config/example.com.yml
     */
    public function __construct(array $config)
    {
        switch ($config['type']) {
            case self::TYPE_MERGE:
                $this->field = new FieldMapMerge($config);
                break;
            case self::TYPE_GROUP:
                $this->field = new FieldMapGroup($config);
                break;
            case self::TYPE_TAG:
                $this->field = new FieldMapTag($config);
                break;
            case self::TYPE_AUTOTAG:
                $this->field = new FieldMapAutotag($config);
                break;
            case self::TYPE_EMAIL:
                $this->field = new FieldMapEmail($config);
                break;
            default:
                throw new ConfigException('Field: Unknown type');
        }
    }
    
    /**
     * Set the data from mailchimp payload
     *
     * @param array $data the payload from mailchimp
     *
     * @throws \App\Exceptions\ParseMailchimpDataException
     */
    public function addMailchimpData(array $data)
    {
        $this->field->addMailchimpData($data);
    }
    
    /**
     * Set the data from the crm api payload
     *
     * @param array $data
     *
     * @throws \App\Exceptions\ParseCrmDataException
     */
    public function addCrmData(array $data)
    {
        $this->field->addCrmData($data);
    }
    
    /**
     * Get field data ready to merge into request array for crm
     *
     * @return array
     */
    public function getCrmDataArray(): array
    {
        return $this->field->getCrmDataArray();
    }
    
    /**
     * Get field data ready to merge into request array for mailchimp
     *
     * @return array
     */
    public function getMailchimpDataArray(): array
    {
        return $this->field->getMailchimpDataArray();
    }
    
    /**
     * Get first level array key for mailchimp request
     *
     * If the mailchimp data is not nested (directly located on first level)
     * this method will return an empty string.
     *
     * @return string
     */
    public function getMailchimpParentKey(): string
    {
        return $this->field->getMailchimpParentKey();
    }
    
    /**
     * Indicates if this field should be synced to the crm
     *
     * @return bool
     */
    public function canSyncToCrm()
    {
        return $this->field->canSyncToCrm();
    }
    
    /**
     * Indicates if this field should be synced to the mailchimp
     *
     * @return bool
     */
    public function canSyncToMailchimp()
    {
        return $this->field->canSyncToMailchimp();
    }
    
    /**
     * Indicates if the current field is an email field
     *
     * @return bool
     */
    public function isEmail()
    {
        return $this->field instanceof FieldMapEmail;
    }
    
    /**
     * Indicates if the current field is a group field
     *
     * @return bool
     */
    public function isGroup()
    {
        return $this->field instanceof FieldMapGroup;
    }
    
    /**
     * Return the crm key of the current field
     *
     * @return string
     */
    public function getCrmKey(): string
    {
        return $this->field->getCrmKey();
    }
}