<?php


namespace App\Synchronizer\Mapper\FieldMaps\GroupConditions;


use App\Synchronizer\CrmValue;

interface GroupConditionInterface
{
    public function __construct(string $trueCondition, string $falseCondition);
    
    public function setFromCrmData(?string $crmStringData);
    
    public function setFromBool(bool $bool);
    
    /**
     * @param string $crmFieldKey
     * @return CrmValue[]
     */
    public function getCrmValue(string $crmFieldKey): array;
    
    public function getMailchimpValue(): bool;
}