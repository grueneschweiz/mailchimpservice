<?php
/** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer\Mapper\FieldMaps;


use Tests\TestCase;

class FieldMapTokenTest extends TestCase
{
    public function testCanSyncToMailchimp(): void
    {
        $map = new FieldMapToken($this->getConfig());
        $this->assertTrue($map->canSyncToMailchimp());
    }
    
    private function getConfig(): array
    {
        return [
            'crmKey' => 'email1',
            'mailchimpKey' => 'TOKEN',
            'type' => 'token',
            'valid' => '+30 days',
            'secret' => 'my secret',
            'sync' => 'toMailchimp'
        ];
    }
    
    public function testGetMailchimpParentKey(): void
    {
        $map = new FieldMapToken($this->getConfig());
        $this->assertEquals('merge_fields', $map->getMailchimpParentKey());
    }
    
    public function testCanSyncToCrm(): void
    {
        $map = new FieldMapToken($this->getConfig());
        $this->assertFalse($map->canSyncToCrm());
    }
    
    public function testGetMailchimpDataArray(): void
    {
        $map = new FieldMapToken($this->getConfig());
        $map->addCrmData($this->getCrmData());
        
        $validUntilDate = date_create($this->getConfig()['valid'])->format('Y-m-d');
        $email = $this->getCrmData()['email1'];
        $secret = $this->getConfig()['secret'];
        
        $token = hash_hmac('sha256', $email . $validUntilDate, $secret);
        $this->assertEquals(['TOKEN' => $token], $map->getMailchimpDataArray());
    }
    
    private function getCrmData()
    {
        return [
            'email1' => 'email@example.com',
        ];
    }
}
