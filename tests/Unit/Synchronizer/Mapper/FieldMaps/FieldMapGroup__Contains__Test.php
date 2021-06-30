<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer\Mapper\FieldMaps;


use Tests\TestCase;

class FieldMapGroup__Contains__Test extends TestCase
{
    public function testGetCrmData__add()
    {
        $map = new FieldMapGroup($this->getConfig());
        $map->addMailchimpData($this->getMailchimpData());
        
        self::assertEquals('notesCountry', $map->getCrmData()->getKey());
        self::assertStringContainsString('PolitletterDE', $map->getCrmData()->getValue());
        self::assertEquals('append', $map->getCrmData()->getMode());
    }
    
    private function getConfig()
    {
        return [
            'crmKey' => 'notesCountry',
            'mailchimpCategoryId' => 'bba5d2d564',
            'type' => 'group',
            'trueContainsString' => 'PolitletterDE',
            'falseContainsString' => 'PolitletterUnsubscribed',
            'sync' => 'both'
        ];
    }
    
    private function getMailchimpData()
    {
        return [
            'interests' => [
                'bba5d2d564' => true
            ]
        ];
    }
    
    public function testGetCrmData__remove()
    {
        $map = new FieldMapGroup($this->getConfig());
        $data = [
            'interests' => [
                'bba5d2d564' => false
            ]
        ];
        
        $map->addMailchimpData($data);
        
        self::assertEquals('notesCountry', $map->getCrmData()->getKey());
        self::assertStringContainsString('PolitletterUnsubscribed', $map->getCrmData()->getValue());
        self::assertEquals('append', $map->getCrmData()->getMode());
    }
    
    public function testGetMailchimpDataArray__add()
    {
        $map = new FieldMapGroup($this->getConfig());
        $map->addCrmData($this->getCrmData());
        
        $this->assertEquals(['bba5d2d564' => true], $map->getMailchimpDataArray());
    }
    
    private function getCrmData()
    {
        return [
            'notesCountry' => "some notes.\nother PolitletterDE. some\nother notes",
        ];
    }
    
    public function testGetMailchimpDataArray__remove()
    {
        $map = new FieldMapGroup($this->getConfig());
        
        $map->addCrmData(['notesCountry' => 'PolitletterDE PolitletterUnsubscribed']);
        $this->assertEquals(['bba5d2d564' => false], $map->getMailchimpDataArray());
    
        $map->addCrmData(['notesCountry' => 'PolitletterUnsubscribed PolitletterDE']);
        $this->assertEquals(['bba5d2d564' => false], $map->getMailchimpDataArray());
    
        $map->addCrmData(['notesCountry' => 'PolitletterUnsubscribed']);
        $this->assertEquals(['bba5d2d564' => false], $map->getMailchimpDataArray());
    
        $map->addCrmData(['notesCountry' => '']);
        $this->assertEquals(['bba5d2d564' => false], $map->getMailchimpDataArray());
    
        $map->addCrmData(['notesCountry' => null]);
        $this->assertEquals(['bba5d2d564' => false], $map->getMailchimpDataArray());
    }
    
    public function testGetMailchimpParentKey()
    {
        $map = new FieldMapGroup($this->getConfig());
        $this->assertEquals('interests', $map->getMailchimpParentKey());
    }
}
