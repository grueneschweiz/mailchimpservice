<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer\Mapper\FieldMaps;


use Tests\TestCase;

class FieldMapEmailTest extends TestCase
{
    public function testGetMailchimpDataArray()
    {
        $map = new FieldMapEmail($this->getConfig());
        $map->addCrmData($this->getCrmData());
        
        $this->assertEquals(['email_address' => 'info@example.org'], $map->getMailchimpDataArray());
    }
    
    private function getConfig()
    {
        return [
            'crmKey' => 'email1',
            'type' => 'email',
            'sync' => 'both'
        ];
    }
    
    private function getCrmData()
    {
        return [
            'email1' => 'info@example.org',
        ];
    }
    
    public function testGetCrmDatay()
    {
        $map = new FieldMapEmail($this->getConfig());
        $map->addMailchimpData($this->getMailchimpData());
    
        self::assertEquals('email1', $map->getCrmData()[0]->getKey());
        self::assertEquals('info@example.org', $map->getCrmData()[0]->getValue());
        self::assertEquals('replace', $map->getCrmData()[0]->getMode());
    }
    
    private function getMailchimpData()
    {
        return [
            'email_address' => 'info@example.org',
        ];
    }
    
    public function testGetMailchimpParentKey()
    {
        $map = new FieldMapEmail($this->getConfig());
        
        $this->assertEmpty($map->getMailchimpParentKey());
    }
}
