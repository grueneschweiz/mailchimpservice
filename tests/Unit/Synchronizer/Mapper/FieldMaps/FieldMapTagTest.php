<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer\Mapper\FieldMaps;


use Tests\TestCase;

class FieldMapTagTest extends TestCase
{
    public function testGetMailchimpParentKey()
    {
        $map = new FieldMapTag($this->getConfig());
        $this->assertEquals('tags', $map->getMailchimpParentKey());
    }
    
    private function getConfig()
    {
        return [
            'crmKey' => 'memberStatusCountry',
            'mailchimpTagName' => 'member',
            'type' => 'tag',
            'conditions' => ['member', 'unconfirmed'],
            'sync' => 'toMailchimp'
        ];
    }
    
    public function testGetCrmDataArray()
    {
        $map = new FieldMapTag($this->getConfig());
        $map->addMailchimpData($this->getMailchimpData());
        
        $this->assertEmpty($map->getCrmDataArray());
    }
    
    private function getMailchimpData()
    {
        return ['member'];
    }
    
    public function testGetMailchimpDataArray__add()
    {
        $map = new FieldMapTag($this->getConfig());
        $map->addCrmData($this->getCrmData());
        
        $this->assertEquals(['member'], $map->getMailchimpDataArray());
    }
    
    private function getCrmData()
    {
        return [
            'memberStatusCountry' => 'member',
        ];
    }
    
    public function testGetMailchimpDataArray__remove()
    {
        $map = new FieldMapTag($this->getConfig());
        $map->addCrmData(['memberStatusCountry' => 'sympathiser']);
        
        $this->assertEquals([], $map->getMailchimpDataArray());
    }
}
