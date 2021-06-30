<?php


namespace App\Synchronizer\Mapper\FieldMaps\GroupConditions;


use App\Synchronizer\CrmValue;

interface GroupConditionInterface
{
    public function __construct(string $trueCondition, string $falseCondition);
    
    public function setFromCrmData(string $crmStringData);
    
    public function setFromBool(bool $bool);
    
    public function getCrmValue(string $crmFieldKey): CrmValue;
    
    public function getMailchimpValue(): bool;
}