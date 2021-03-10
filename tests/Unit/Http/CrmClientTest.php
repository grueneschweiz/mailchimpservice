<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Http\Controllers\RestApi;


use App\Http\CrmClient;
use App\OAuthClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmClientTest extends TestCase
{
    use RefreshDatabase;
    
    /** @var CrmClient */
    private $client;
    
    public function setUp(): void
    {
        parent::setUp();
        
        $ref = new \ReflectionClass(CrmClient::class);
        
        $this->client = $ref->newInstanceWithoutConstructor();
    }
    
    public function testPost()
    {
        $m = [
            'firstName' => [
                'value' => 'Unit Post Create',
                'mode' => 'replace'
            ],
            'lastName' => [
                'value' => 'Test',
                'mode' => 'append'
            ],
            'email1' => [
                'value' => 'unittest+' . Str::random() . '@unittest.ut',
                'mode' => 'replace'
            ],
            'groups' => [
                'value' => [100],
                'mode' => 'append',
            ]
        ];
        
        $this->mockResponse([
            new Response(201, [], json_encode(1234)),
            new Response(200, [], json_encode([
                'firstName' => $m['firstName']['value'],
                'lastName' => $m['lastName']['value'],
                'email1' => $m['email1']['value'],
                'groups' => $m['groups']['value'],
                'id' => 1234
            ])),
        ]);
        
        $post = $this->client->post('/api/v1/member', $m);
        $this->assertEquals(201, $post->getStatusCode());
        
        $id = json_decode($post->getBody());
        
        $get = $this->client->get("/api/v1/member/$id");
        $inserted = json_decode($get->getBody());
        
        $this->assertEquals($m['email1']['value'], $inserted->email1);
        $this->assertEquals($m['lastName']['value'], $inserted->lastName);
    }
    
    private function mockResponse(array $responses)
    {
        $ref = new \ReflectionObject($this->client);
        
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        
        $guzzle = $ref->getProperty('guzzle');
        $guzzle->setAccessible(true);
        $guzzle->setValue($this->client, new Client(['handler' => $handler]));
    }
    
    public function testPut()
    {
        $email2 = 'unittest_update+' . Str::random() . '@unittest.ut';
        
        $m = [
            'firstName' => [
                'value' => 'Unit Post Update',
                'mode' => 'replace'
            ],
            'lastName' => [
                'value' => 'Test',
                'mode' => 'append'
            ],
            'email1' => [
                'value' => 'unittest+' . Str::random() . '@unittest.ut',
                'mode' => 'replace'
            ],
            'groups' => [
                'value' => [100],
                'mode' => 'append',
            ]
        ];
        
        $this->mockResponse([
            new Response(201, [], json_encode(1234)),
            new Response(201, [], json_encode(1234)),
            new Response(200, [], json_encode([
                'firstName' => $m['firstName']['value'],
                'lastName' => $m['lastName']['value'],
                'email1' => $email2,
                'groups' => $m['groups']['value'],
                'id' => 1234
            ])),
        ]);
        
        $post = $this->client->post('/api/v1/member', $m);
        $this->assertEquals(201, $post->getStatusCode());
        
        $id = json_decode($post->getBody());
        
        $m2['email1'] = [
            'value' => $email2,
            'mode' => 'replace',
        ];
        
        $put = $this->client->put("/api/v1/member/$id", $m2);
        $this->assertEquals(201, $put->getStatusCode());
        
        $get = $this->client->get("/api/v1/member/$id");
        $updated = json_decode($get->getBody());
        
        $this->assertEquals($m2['email1']['value'], $updated->email1);
    }
    
    public function testGet()
    {
        $this->mockResponse([
            new Response(200, [], json_encode(1234)),
        ]);
        
        $get = $this->client->get('/api/v1/revision');
        $revision = json_decode($get->getBody());
        
        $this->assertIsNumeric($revision);
        $this->assertGreaterThan(0, $revision);
    }
    
    public function testIsTokenValid()
    {
        $method = new \ReflectionMethod(CrmClient::class, 'isTokenValid');
        $method->setAccessible(true);
        
        $this->mockResponse([
            new Response(200),
            new Response(401)
        ]);
        
        $this->assertTrue($method->invoke($this->client));
        $this->assertFalse($method->invoke($this->client));
    }
    
    public function testLoadToken()
    {
        $id = 123;
        $t = 'token';
        
        $obj = new \ReflectionObject($this->client);
        
        $clientId = $obj->getProperty('clientId');
        $clientId->setAccessible(true);
        $clientId->setValue($this->client, $id);
        
        $clientSecret = $obj->getProperty('clientSecret');
        $clientSecret->setAccessible(true);
        $clientSecret->setValue($this->client, 'secret');
        
        $token = $obj->getProperty('token');
        $token->setAccessible(true);
        
        $method = new \ReflectionMethod(CrmClient::class, 'loadToken');
        $method->setAccessible(true);
        
        $this->mockResponse([
            new Response(401),
            new Response(200, [], json_encode(['access_token' => $t])),
        ]);
        
        $method->invoke($this->client);
        
        $this->assertEquals($t, $token->getValue($this->client));
        $this->assertEquals($t, OAuthClient::find($id)->token);
    }
    
    public function testForceSSL()
    {
        $method = new \ReflectionMethod(CrmClient::class, 'forceSSL');
        $method->setAccessible(true);
    
        $this->assertMatchesRegularExpression('/^https:\/\//', $method->invoke($this->client, 'http://example.com'));
        $this->assertMatchesRegularExpression('/^https:\/\//', $method->invoke($this->client, '//example.com'));
    }
}
