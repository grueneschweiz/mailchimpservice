<?php


namespace App\Synchronizer\Mapper\FieldMaps\GroupConditions;


use App\Exceptions\ConfigException;

class GroupConditionFactory
{
    /**
     * GroupCondition constructor.
     *
     * @param array $config {
     *  (trueCondition|trueContainsString): string,
     *  (falseCondition|trueContainsString): string,
     * }
     *
     * @throws ConfigException
     */
    public static function makeFrom(array $config): GroupConditionInterface
    {
        // 'condition' or 'containsString' must be present
        if (empty($config['trueCondition']) && empty($config['trueContainsString'])) {
            throw new ConfigException("Field: Missing condition definition. Either 'trueCondition' or 'trueContainsString' must be present.");
        }
        if (empty($config['falseCondition']) && empty($config['falseContainsString'])) {
            throw new ConfigException("Field: Missing condition definition. Either 'falseCondition' or 'falseContainsString' must be present.");
        }
        
        // both, 'condition' and 'containsString' at the same time are not allowed
        if (!empty($config['trueCondition']) && !empty($config['trueContainsString'])) {
            throw new ConfigException("Field: 'trueCondition' and 'trueContainsString' are mutually exclusive. Make sure there is only one of them.");
        }
        if (!empty($config['falseCondition']) && !empty($config['falseContainsString'])) {
            throw new ConfigException("Field: 'falseCondition' and 'falseContainsString' are mutually exclusive. Make sure there is only one of them.");
        }
        
        // 'condition' and 'containsString' must be the same for 'true' and 'false'
        if (!empty($config['trueCondition']) && !empty($config['falseContainsString'])) {
            throw new ConfigException("Field: 'trueCondition' and 'falseContainsString' can not be combined. If you use 'trueCondition' you must use 'falseCondition'.");
        }
        if (!empty($config['trueContainsString']) && !empty($config['falseCondition'])) {
            throw new ConfigException("Field: 'trueContainsString' and 'falseCondition' can not be combined. If you use 'trueContainsString' you must use 'falseContainsString'.");
        }
        
        // set 'condition'
        if (!empty($config['trueCondition']) && !empty($config['falseCondition'])) {
            return new GroupStringBoolCondition($config['trueCondition'], $config['falseCondition']);
        }
        
        // set 'containsString'
        if (!empty($config['trueContainsString']) && !empty($config['falseContainsString'])) {
            return new GroupStringContainsCondition($config['trueContainsString'], $config['falseContainsString']);
        }
        
        throw new ConfigException('Field: Invalid condition definition. Either "trueCondition" and "falseCondition" or "trueContainsString" and "falseContainsString" must be present.');
    }
}