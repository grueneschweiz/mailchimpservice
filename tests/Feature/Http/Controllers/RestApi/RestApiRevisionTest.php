<?php

namespace App\Http\Controllers\RestApi;


use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Http\Controllers\RestApi\EndpointHelper;
use Tests\TestCase;

class RestApiRevisionTest extends TestCase
{
    use RefreshDatabase;
    
    private const CONFIG_FILE_NAME = 'test.io.yml';
    
    /**
     * @var EndpointHelper
     */
    private $endpoint;
    
    public function setUp(): void
    {
        parent::setUp();
        
        config(['app.config_base_path' => 'tests']);
        $this->endpoint = new EndpointHelper(self::CONFIG_FILE_NAME);
    }
    
    public function tearDown(): void
    {
        $this->endpoint->delete();
        
        parent::tearDown();
    }
    
    public function test_401()
    {
        $response = $this->json('POST', $this->endpoint->get() . '_invalid', $this->getMailchimpPostData('subscribe'));
        $response->assertStatus(401);
    }
    
    private function getMailchimpPostData(string $type)
    {
        return array(
            'type' => $type,
            'fired_at' => '2009-03-26 21:35:57',
            'data[id]' => '8a25ff1d98',
            'data[list_id]' => 'a6b5da1054',
            'data[email]' => 'api@mailchimp.com',
            'data[email_type]' => 'html',
            'data[merges][EMAIL]' => 'api@mailchimp.com',
            'data[merges][FNAME]' => 'Mailchimp',
            'data[merges][LNAME]' => 'API',
            'data[merges][INTERESTS]' => 'Group1,Group2',
            'data[ip_opt]' => '10.20.10.30',
            'data[ip_signup]' => '10.20.10.30',
        );
    }
}
