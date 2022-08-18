<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer;


use App\Synchronizer\Mapper\FieldMapFacade;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @var Config
     */
    private $config;
    
    public function setUp(): void
    {
        parent::setUp();
        
        config(['app.config_base_path' => 'tests']);
        
        $this->config = new Config('test.io.yml');
    }
    
    public function testGetCrmCredentials()
    {
        $cred = $this->config->getCrmCredentials();
        
        $this->assertEquals(1, $cred['clientId']);
        $this->assertEquals('crmclientsecret', $cred['clientSecret']);
        $this->assertEquals('https://crmclient.url', $cred['url']);
    }
    
    public function testGetMailchimpCredentials()
    {
        $cred = $this->config->getMailchimpCredentials();
        
        $this->assertEquals('apikey', $cred['apikey']);
    }
    
    public function testGetDataOwner()
    {
        $owner = $this->config->getDataOwner();
        
        $this->assertEquals('dataowner@example.com', $owner['email']);
        $this->assertEquals('dataowner', $owner['name']);
    }
    
    public function testFieldMaps()
    {
        $maps = $this->config->getFieldMaps();
        
        foreach ($maps as $map) {
            $this->assertInstanceOf(FieldMapFacade::class, $map);
        }
    }
    
    public function testGetCrmEmailKey()
    {
        $this->assertEquals('email1', $this->config->getCrmEmailKey());
    }
    
    public function testSyncAll()
    {
        $this->assertFalse($this->config->getSyncAll());
    }
    
    public function testGetMailchimpListId()
    {
        $this->assertEquals('6f33e28fa3', $this->config->getMailchimpListId());
    }
    
    public function testGetMailchimpKeyOfCrmId()
    {
        $this->assertEquals('WEBLINGID', $this->config->getMailchimpKeyOfCrmId());
    }
    
    public function testIsValid()
    {
        $this->assertTrue($this->config->isValid());
    }
    
    public function testIsValid__false()
    {
        $config = new Config('test_invalid.io.yml');
        $this->assertFalse($config->isValid());
    }
    
    public function testGetErrors()
    {
        $config = new Config('test_invalid.io.yml');
        $this->assertCount(5, $config->getErrors());
    }
    
    public function testGetPrioritizedGroups(): void
    {
        $this->assertEqualsCanonicalizing([1234567, 7654321], $this->config->getPrioritizedGroups());
    }
}
