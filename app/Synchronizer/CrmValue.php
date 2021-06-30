<?php


namespace App\Synchronizer;


class CrmValue
{
    public const MODE_REPLACE = 'replace';
    public const MODE_APPEND = 'append';
    public const MODE_REPLACE_EMPTY = 'replaceEmpty';
    public const MODE_ADD_IF_NEW = 'addIfNew';
    
    private string $key;
    private array|string|int|null $value;
    private string $mode;
    
    /**
     * CrmValue constructor.
     * @param string $key
     * @param array|int|string|null $value
     * @param string $mode
     */
    public function __construct(string $key, int|array|string|null $value, string $mode)
    {
        $this->setKey($key);
        $this->setValue($value);
        $this->setMode($mode);
    }
    
    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }
    
    /**
     * @param string $key
     */
    public function setKey(string $key): void
    {
        $this->key = $key;
    }
    
    /**
     * @return array|int|string|null
     */
    public function getValue(): array|int|string|null
    {
        return $this->value;
    }
    
    /**
     * @param array|int|string|null $value
     */
    public function setValue(array|int|string|null $value): void
    {
        $this->value = $value;
    }
    
    /**
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }
    
    /**
     * @param string $mode
     */
    public function setMode(string $mode): void
    {
        $allowedModes = [
            self::MODE_APPEND,
            self::MODE_REPLACE,
            self::MODE_ADD_IF_NEW,
            self::MODE_REPLACE_EMPTY
        ];
        if (!in_array($mode, $allowedModes)) {
            throw new \InvalidArgumentException("Invalid mode: $mode");
        }
        
        $this->mode = $mode;
    }
}