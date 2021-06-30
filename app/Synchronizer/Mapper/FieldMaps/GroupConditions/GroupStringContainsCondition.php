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
    
    public function setFromCrmData(?string $crmStringData)
    {
        // the false condition must always have precedence over the true condition
        if (str_contains((string)$crmStringData, $this->falseCondition)) {
            $this->value = false;
            return;
        }
    
        $this->value = str_contains((string)$crmStringData, $this->trueCondition);
    }
    
    public function setFromBool(bool $bool)
    {
        $this->value = $bool;
    }
    
    public function getCrmValue(string $crmFieldKey): array
    {
        $append = $this->value ? $this->trueCondition : $this->falseCondition;
        $remove = !$this->value ? $this->trueCondition : $this->falseCondition;
        return [
            new CrmValue($crmFieldKey, $append, CrmValue::MODE_APPEND),
            new CrmValue($crmFieldKey, $remove, CrmValue::MODE_REMOVE)
        ];
    }
    
    public function getMailchimpValue(): bool
    {
        return $this->value;
    }
}