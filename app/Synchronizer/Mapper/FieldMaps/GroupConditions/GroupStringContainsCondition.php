<?php


namespace App\Synchronizer\Mapper\FieldMaps\GroupConditions;


use App\Synchronizer\CrmValue;

class GroupStringContainsCondition implements GroupConditionInterface
{
    private string $trueCondition;
    private string $falseCondition;
    private bool $value;
    
    public function __construct(string $trueCondition, string $falseCondition)
    {
        $this->trueCondition = $trueCondition;
        $this->falseCondition = $falseCondition;
    }
    
    public function setFromCrmData(string $crmStringData)
    {
        // the false condition must always have precedence over the true condition
        if (str_contains($crmStringData, $this->falseCondition)) {
            $this->value = false;
            return;
        }
        
        $this->value = str_contains($crmStringData, $this->trueCondition);
    }
    
    public function setFromBool(bool $bool)
    {
        $this->value = $bool;
    }
    
    public function getCrmValue(string $crmFieldKey): CrmValue
    {
        $value = $this->value ? $this->trueCondition : $this->falseCondition;
        return new CrmValue($crmFieldKey, $value, CrmValue::MODE_APPEND);
    }
    
    public function getMailchimpValue(): bool
    {
        return $this->value;
    }
}