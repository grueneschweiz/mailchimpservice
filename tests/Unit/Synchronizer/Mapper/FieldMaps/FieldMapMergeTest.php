<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer\Mapper\FieldMaps;


use Tests\TestCase;

class FieldMapMergeTest extends TestCase
{
    public function testCanSyncToMailchimp()
    {
        $map = new FieldMapMerge($this->getConfig());
        $this->assertTrue($map->canSyncToMailchimp());
    }
    
    private function getConfig()
    {
        return [
            'crmKey' => 'firstName',
            'mailchimpKey' => 'FNAME',
            'type' => 'merge',
            'default' => 'asdf',
            'sync' => 'toMailchimp'
        ];
    }
    
    public function testGetMailchimpParentKey()
    {
        $map = new FieldMapMerge($this->getConfig());
        $this->assertEquals('merge_fields', $map->getMailchimpParentKey());
    }
    
    public function testCanSyncToCrm__false()
    {
        $map = new FieldMapMerge($this->getConfig());
        $this->assertFalse($map->canSyncToCrm());
    }
    
    public function testCanSyncToCrm__true()
    {
        $config = $this->getConfig();
        $config['sync'] = 'both';
        
        $map = new FieldMapMerge($config);
        $this->assertTrue($map->canSyncToCrm());
    }
    
    public function testGetCrmDataArray()
    {
        $map = new FieldMapMerge($this->getConfig());
        $map->addMailchimpData($this->getMailchimpData());
        
        $this->assertEquals(['firstName' => 'Hugo'], $map->getCrmDataArray());
    }
    
    private function getMailchimpData()
    {
        return [
            'merge_fields' => [
                'FNAME' => 'Hugo'
            ]
        ];
    }
    
    public function testGetCrmDataArray__default()
    {
        $map = new FieldMapMerge($this->getConfig());
        $data = $this->getMailchimpData();
        $data['merge_fields']['FNAME'] = '';
        $map->addMailchimpData($data);
        
        $this->assertEquals(['firstName' => 'asdf'], $map->getCrmDataArray());
    }
    
    public function testGetMailchimpDataArray()
    {
        $map = new FieldMapMerge($this->getConfig());
        $map->addCrmData($this->getCrmData());
        
        $this->assertEquals(['FNAME' => 'Hugo'], $map->getMailchimpDataArray());
    }
    
    private function getCrmData()
    {
        return [
            'firstName' => 'Hugo',
        ];
    }
}
