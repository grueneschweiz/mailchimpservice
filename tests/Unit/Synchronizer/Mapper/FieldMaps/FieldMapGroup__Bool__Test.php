<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer\Mapper\FieldMaps;


use Tests\TestCase;

class FieldMapGroup__Bool__Test extends TestCase
{
    public function testGetCrmData__add()
    {
        $map = new FieldMapGroup($this->getConfig());
        $map->addMailchimpData($this->getMailchimpData());
    
        self::assertEquals('newsletterCountryD', $map->getCrmData()[0]->getKey());
        self::assertEquals('yes', $map->getCrmData()[0]->getValue());
        self::assertEquals('replace', $map->getCrmData()[0]->getMode());
    }
    
    private function getConfig()
    {
        return [
            'crmKey' => 'newsletterCountryD',
            'mailchimpCategoryId' => '55f795def4',
            'type' => 'group',
            'trueCondition' => 'yes',
            'falseCondition' => 'no',
            'sync' => 'both'
        ];
    }
    
    private function getMailchimpData()
    {
        return [
            'interests' => [
                '55f795def4' => true
            ]
        ];
    }
    
    public function testGetCrmData__remove()
    {
        $map = new FieldMapGroup($this->getConfig());
        $data = [
            'interests' => [
                '55f795def4' => false
            ]
        ];
    
        $map->addMailchimpData($data);
    
        self::assertEquals('newsletterCountryD', $map->getCrmData()[0]->getKey());
        self::assertEquals('no', $map->getCrmData()[0]->getValue());
        self::assertEquals('replace', $map->getCrmData()[0]->getMode());
    }
    
    public function testGetMailchimpDataArray__add()
    {
        $map = new FieldMapGroup($this->getConfig());
        $map->addCrmData($this->getCrmData());
        
        $this->assertEquals(['55f795def4' => true], $map->getMailchimpDataArray());
    }
    
    private function getCrmData()
    {
        return [
            'newsletterCountryD' => 'yes',
        ];
    }
    
    public function testGetMailchimpDataArray__remove()
    {
        $map = new FieldMapGroup($this->getConfig());
    
        $map->addCrmData(['newsletterCountryD' => 'no']);
        $this->assertEquals(['55f795def4' => false], $map->getMailchimpDataArray());
    
        $map->addCrmData(['newsletterCountryD' => '']);
        $this->assertEquals(['55f795def4' => false], $map->getMailchimpDataArray());
    
        $map->addCrmData(['newsletterCountryD' => null]);
        $this->assertEquals(['55f795def4' => false], $map->getMailchimpDataArray());
    }
    
    public function testGetMailchimpParentKey()
    {
        $map = new FieldMapGroup($this->getConfig());
        $this->assertEquals('interests', $map->getMailchimpParentKey());
    }
}
