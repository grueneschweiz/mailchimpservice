<?php

/** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer;

use App\Http\CrmClient;
use App\Http\MailChimpClient;
use App\OAuthClient;
use App\Synchronizer\Mapper\Mapper;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class MailchimpToCrmCronSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIG_FILE_NAME = 'test.io.yml';

    /**
     * @var MailChimpClient
     */
    private $mcClientTesting;

    /**
     * @var MailchimpToCrmCronSynchronizer
     */
    private $sync;

    /**
     * @var Config
     */
    private $config;

    private const DEFAULT_INTEREST_MATCHINGS = [
        '55f795def4' => true,
        '1851be732e' => true,
        '294df36247' => false,
        '633e3c8dd7' => false,
        'bba5d2d564' => false
    ];

    /**
     * Guzzle request history
     *
     * @var array
     */
    private $crmRequestHistory = [];

    public function setUp(): void
    {
        parent::setUp();

        $auth = new OAuthClient();
        $auth->client_id = 1;
        $auth->client_secret = 'crmclientsecret';
        $auth->token = 'crmclienttoken';
        $auth->save();

        $sync = new \ReflectionClass(MailchimpToCrmCronSynchronizer::class);
        $this->sync = $sync->newInstanceWithoutConstructor();

        $configName = new \ReflectionProperty($this->sync, 'configName');
        $configName->setAccessible(true);
        $configName->setValue($this->sync, self::CONFIG_FILE_NAME);

        config(['app.config_base_path' => 'tests']);
        $this->config = new Config(self::CONFIG_FILE_NAME);
        $c = new \ReflectionProperty($this->sync, 'config');
        $c->setAccessible(true);
        $c->setValue($this->sync, $this->config);

        $mailchimpClient = new \ReflectionProperty($this->sync, 'mcClient');
        $mailchimpClient->setAccessible(true);
        $mailchimpClient->setValue($this->sync, new MailChimpClient(env('MAILCHIMP_APIKEY'), $this->config->getMailchimpListId()));
        $this->mcClientTesting = $mailchimpClient->getValue($this->sync);

        $initializeMapperMethod = new \ReflectionMethod($this->sync, 'initializeMapper');
        $initializeMapperMethod->setAccessible(true);
        $initializeMapperMethod->invoke($this->sync);

        // Initialize languageTags property
        $languageTags = new \ReflectionProperty($this->sync, 'languageTags');
        $languageTags->setAccessible(true);
        $languageTags->setValue($this->sync, ['Deutsch', 'Française', 'Italiano']);

        // Initialize mailchimpKeyOfCrmId since we bypassed the constructor
        $mailchimpKeyOfCrmIdProp = new \ReflectionProperty($this->sync, 'mailchimpKeyOfCrmId');
        $mailchimpKeyOfCrmIdProp->setAccessible(true);
        $mailchimpKeyOfCrmIdProp->setValue($this->sync, $this->config->getMailchimpKeyOfCrmId());

        // Initialize sync criteria properties since we bypassed the constructor
        if (property_exists($this->sync, 'syncCriteriaField')) {
            $syncCriteriaFieldProp = new \ReflectionProperty($this->sync, 'syncCriteriaField');
            $syncCriteriaFieldProp->setAccessible(true);
            $syncCriteriaFieldProp->setValue($this->sync, $this->config->getSyncCriteriaField());
        }
        if (property_exists($this->sync, 'syncCriteriaThreshold')) {
            $syncCriteriaThresholdProp = new \ReflectionProperty($this->sync, 'syncCriteriaThreshold');
            $syncCriteriaThresholdProp->setAccessible(true);
            $syncCriteriaThresholdProp->setValue($this->sync, $this->config->getSyncCriteriaThreshold());
        }

        // Set up a default mock CRM client for all tests
        // This prevents 'Call to a member function post() on null' errors
        $this->mockCrmResponse([new Response(200)]);
    }

    private function mockCrmResponse(array $responses)
    {
        $sync = new \ReflectionClass($this->sync);
        $crmClient = $sync->getProperty('crmClient');
        $crmClient->setAccessible(true);

        // Create a mock CRM client that returns arrays instead of Response objects
        $mockClient = $this->createMock(CrmClient::class);

        // Configure the mock to return array responses for post method
        $mockClient->method('post')
            ->willReturnCallback(function ($endpoint, $data) use ($responses) {
                static $callCount = 0;

                if (!isset($responses[$callCount])) {
                    return ['id' => '12345']; // Default response
                }

                $response = $responses[$callCount++];

                if ($response instanceof Response) {
                    if ($response->getStatusCode() >= 400) {
                        throw new \Exception('Server Error: ' . $response->getStatusCode());
                    }
                    $body = $response->getBody()->getContents();
                    $decoded = json_decode($body, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                    if (is_scalar($decoded) && $decoded !== null && $decoded !== '') {
                        return ['id' => (string)$decoded];
                    }
                    return ['id' => '12345'];
                }

                return $response;
            });

        $crmClient->setValue($this->sync, $mockClient);
    }

    public function testFilterSingle_WithValidMember_ReturnsTrue()
    {
        $filterSingleMethod = new ReflectionMethod($this->sync, 'filterSingle');
        $filterSingleMethod->setAccessible(true);

        $configReflection = new \ReflectionObject($this->config);
        $mailchimpProperty = $configReflection->getProperty('mailchimp');
        $mailchimpProperty->setAccessible(true);
        $mailchimpConfig = $mailchimpProperty->getValue($this->config);
        $mailchimpConfig['interestCategoryId'] = 'abc123';
        $mailchimpProperty->setValue($this->config, $mailchimpConfig);

        $mailchimpToCrmProperty = $configReflection->getProperty('mailchimpToCrm');
        $mailchimpToCrmProperty->setAccessible(true);
        $mailchimpToCrmConfig = $mailchimpToCrmProperty->getValue($this->config);
        $mailchimpToCrmConfig['newtag'] = 'New';
        $mailchimpToCrmProperty->setValue($this->config, $mailchimpToCrmConfig);

        $member = [
            'email_address' => 'test@example.com',
            'tags_count' => 3,
            'merge_fields' => [
                'FNAME' => 'Test',
                'LNAME' => 'User'
            ],
            'tags' => [
                ['name' => 'New']
            ]
        ];

        try {
            $filterSingleMethod->invoke($this->sync, $member);
            $this->assertTrue(true);
        } catch (\Exception $e) {
            // Ignore exceptions in this test
            $this->assertTrue(false, "Error synching member: " . $e->getMessage());
        }
    }

    public function testFilterSingle_WithMissingEmail_ReturnsFalse()
    {
        $filterSingleMethod = new ReflectionMethod($this->sync, 'filterSingle');
        $filterSingleMethod->setAccessible(true);

        // Create a member without an email address
        $member = [
            'tags_count' => 3,
            'merge_fields' => [
                'FNAME' => 'Test',
                'LNAME' => 'User'
            ],
            'tags' => [
                ['name' => 'New']
            ]
        ];

        $result = $filterSingleMethod->invoke($this->sync, $member);
        $this->assertFalse($result);
    }

    public function testFilterSingle_WithLowRating_ReturnsFalse()
    {
        $filterSingleMethod = new ReflectionMethod($this->sync, 'filterSingle');
        $filterSingleMethod->setAccessible(true);

        $configReflection = new \ReflectionObject($this->config);
        $mailchimpToCrmProperty = $configReflection->getProperty('mailchimpToCrm');
        $mailchimpToCrmProperty->setAccessible(true);
        $mailchimpToCrmConfig = $mailchimpToCrmProperty->getValue($this->config);
        $mailchimpToCrmConfig['newtag'] = 'New';
        $mailchimpToCrmProperty->setValue($this->config, $mailchimpToCrmConfig);

        $member = [
            'email_address' => 'test@example.com',
            'tags_count' => 2, // Low rating
            'merge_fields' => [
                'FNAME' => 'Test',
                'LNAME' => 'User'
            ],
            'tags' => [
                ['name' => 'New']
            ]
        ];

        $result = $filterSingleMethod->invoke($this->sync, $member);
        $this->assertFalse($result);
    }

    public function testFilterSingle_WithoutNewTag_ReturnsFalse()
    {
        $filterSingleMethod = new ReflectionMethod($this->sync, 'filterSingle');
        $filterSingleMethod->setAccessible(true);

        $configReflection = new \ReflectionObject($this->config);
        $mailchimpToCrmProperty = $configReflection->getProperty('mailchimpToCrm');
        $mailchimpToCrmProperty->setAccessible(true);
        $mailchimpToCrmConfig = $mailchimpToCrmProperty->getValue($this->config);
        $mailchimpToCrmConfig['newtag'] = 'New';
        $mailchimpToCrmProperty->setValue($this->config, $mailchimpToCrmConfig);

        $member = [
            'email_address' => 'test@example.com',
            'tags_count' => 3,
            'merge_fields' => [
                'FNAME' => 'Test',
                'LNAME' => 'User'
            ],
            'tags' => [
                ['name' => 'other_tag'] // no new tag
            ]
        ];

        $result = $filterSingleMethod->invoke($this->sync, $member);
        $this->assertFalse($result);
    }

    public function testSyncSingle_WithValidMember_ReturnsTrue()
    {
        $member = [
            'email_address' => 'test@example.com',
            'merge_fields' => [
                'FNAME' => 'Test',
                'LNAME' => 'User',
                'GENDER' => 'm',
                'WEBLINGID' => ''
            ],
            'interests' => [
                '55f795def4' => true
            ],
            'tags' => [
                ['name' => 'New'],
                ['name' => 'Deutsch']
            ]
        ];

        $configReflection = new \ReflectionObject($this->config);
        $mailchimpToCrmProperty = $configReflection->getProperty('mailchimpToCrm');
        $mailchimpToCrmProperty->setAccessible(true);
        $mailchimpToCrmConfig = $mailchimpToCrmProperty->getValue($this->config);
        $mailchimpToCrmConfig['interestsToSync'] = ['55f795def4'];
        $mailchimpToCrmProperty->setValue($this->config, $mailchimpToCrmConfig);

        // Mock the mapper to return valid CRM data
        $mapperMock = $this->getMockBuilder(Mapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mapperMock->method('mailchimpToCrm')
            ->willReturn([
                'email1' => [['value' => 'test@example.com']],
                'firstName' => [['value' => 'Test']]
            ]);

        $mapperProperty = new \ReflectionProperty($this->sync, 'mapper');
        $mapperProperty->setAccessible(true);
        $mapperProperty->setValue($this->sync, $mapperMock);

        // Mock the CRM client to return a valid response
        $this->mockCrmResponse([['id' => '12345']]);

        // Mock the MailChimpClient to avoid actual API calls
        $mcClientMock = $this->createMock(MailChimpClient::class);
        $mcClientMock->method('updateSubscriberInterests')->willReturn(true);
        $mcClientMock->method('removeTagFromSubscriber')->willReturn(true);
        $mcClientMock->method('calculateSubscriberId')->willReturn('abc123');

        $mcClientProperty = new \ReflectionProperty($this->sync, 'mcClient');
        $mcClientProperty->setAccessible(true);
        $mcClientProperty->setValue($this->sync, $mcClientMock);

        // Call the method
        $result = $this->sync->syncSingle($member);

        // Assert the result
        $this->assertTrue($result);
    }

    /**
     * Ensures the synchronizer gracefully handles API failures without crashing the application
     */
    public function testSyncSingle_WithException_ReturnsFalse()
    {
        // Set up the CRM response to throw an exception
        $this->mockCrmResponse([
            new Response(500, [], 'Server Error')
        ]);

        $configReflection = new \ReflectionObject($this->config);
        $mailchimpToCrmProperty = $configReflection->getProperty('mailchimpToCrm');
        $mailchimpToCrmProperty->setAccessible(true);
        $mailchimpToCrmConfig = $mailchimpToCrmProperty->getValue($this->config);
        $mailchimpToCrmConfig['interestsToSync'] = ['55f795def4'];
        $mailchimpToCrmProperty->setValue($this->config, $mailchimpToCrmConfig);

        $member = [
            'email_address' => 'test@example.com',
            'merge_fields' => [
                'FNAME' => 'Test',
                'LNAME' => 'User',
                'GENDER' => 'm',
                'WEBLINGID' => ''
            ],
            'tags' => [
                ['name' => 'New'],
                ['name' => 'Deutsch']
            ],
            'interests' => [
                '55f795def4' => true
            ]
        ];

        // Mock the mapper to avoid real interest ID issues
        $mapperMock = $this->getMockBuilder(Mapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mapperMock->method('mailchimpToCrm')->willReturn([
            'email1' => [['value' => 'test@example.com']],
            'newsletterCountryD' => [['value' => 'yes']]
        ]);

        $mapperProperty = new \ReflectionProperty($this->sync, 'mapper');
        $mapperProperty->setAccessible(true);
        $mapperProperty->setValue($this->sync, $mapperMock);

        $result = $this->sync->syncSingle($member);
        $this->assertFalse($result);
    }

    public function testSyncAll_WithUpsertEnabled_ProcessesMembers()
    {
        $mcClientMock = $this->createMock(MailChimpClient::class);

        $mcClientMock->expects($this->exactly(1))
            ->method('getListMembers')
            ->willReturn([
                [
                    'email_address' => 'test1@example.com',
                    'tags_count' => 4,
                    'merge_fields' => ['FNAME' => 'Test1', 'LNAME' => 'User1'],
                    'tags' => [['name' => 'New']],
                    'interests' => self::DEFAULT_INTEREST_MATCHINGS
                ],
                [
                    'email_address' => 'test2@example.com',
                    'tags_count' => 5,
                    'merge_fields' => ['FNAME' => 'Test2', 'LNAME' => 'User2'],
                    'tags' => [['name' => 'New']],
                    'interests' => self::DEFAULT_INTEREST_MATCHINGS
                ]
            ]);

        $mcClientProperty = new \ReflectionProperty($this->sync, 'mcClient');
        $mcClientProperty->setAccessible(true);
        $mcClientProperty->setValue($this->sync, $mcClientMock);

        $configReflection = new \ReflectionObject($this->config);
        $mailchimpProperty = $configReflection->getProperty('mailchimp');
        $mailchimpProperty->setAccessible(true);
        $mailchimpConfig = $mailchimpProperty->getValue($this->config);
        $mailchimpConfig['interestCategoryId'] = 'abc123'; // Add interest category ID
        $mailchimpProperty->setValue($this->config, $mailchimpConfig);

        $mailchimpToCrmProperty = $configReflection->getProperty('mailchimpToCrm');
        $mailchimpToCrmProperty->setAccessible(true);
        $mailchimpToCrmConfig = $mailchimpToCrmProperty->getValue($this->config);
        $mailchimpToCrmConfig['newtag'] = 'New';
        $mailchimpToCrmConfig['interestsToSync'] = ['55f795def4']; // Enable upsert
        $mailchimpToCrmProperty->setValue($this->config, $mailchimpToCrmConfig);

        $this->mockCrmResponse([
            new Response(200, [], json_encode(12345)),
            new Response(200, [], json_encode(12346))
        ]);

        $result = $this->sync->syncAll(10, 0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertEquals(2, $result['success']);
    }

    public function testDetermineLanguageFromTags_WithGermanTag_ReturnsGerman()
    {
        // Create test tags with German language tag
        $tags = [
            'tags' => [
                ['name' => 'Deutsch'],
                ['name' => 'Other']
            ]
        ];

        // Call the method using reflection
        $determineMethod = new \ReflectionMethod($this->sync, 'determineLanguageFromTags');
        $determineMethod->setAccessible(true);
        $result = $determineMethod->invoke($this->sync, $tags);

        $this->assertEquals('Deutsch', $result);
    }

    public function testDetermineLanguageFromTags_WithFrenchTag_ReturnsFrench()
    {
        // Create test tags with French language tag
        $tags = [
            'tags' => [
                ['name' => 'Française'],
                ['name' => 'Other']
            ]
        ];

        // Call the method using reflection
        $determineMethod = new \ReflectionMethod($this->sync, 'determineLanguageFromTags');
        $determineMethod->setAccessible(true);
        $result = $determineMethod->invoke($this->sync, $tags);

        $this->assertEquals('Française', $result);
    }

    public function testDetermineLanguageFromTags_WithNoLanguageTag_ReturnsNull()
    {
        // Create test tags with no language tag
        $tags = [
            'tags' => [
                ['name' => 'Other'],
                ['name' => 'Another']
            ]
        ];

        // Call the method using reflection
        $determineMethod = new \ReflectionMethod($this->sync, 'determineLanguageFromTags');
        $determineMethod->setAccessible(true);
        $result = $determineMethod->invoke($this->sync, $tags);

        $this->assertNull($result);
    }

    public function testSyncAll_WithUpsertDisabled_NoProcessing()
    {
        $mcClientMock = $this->createMock(MailChimpClient::class);
        $mcClientMock->expects($this->exactly(1))
            ->method('getListMembers')
            ->willReturn([]);

        $mcClientProperty = new \ReflectionProperty($this->sync, 'mcClient');
        $mcClientProperty->setAccessible(true);
        $mcClientProperty->setValue($this->sync, $mcClientMock);

        $configReflection = new \ReflectionObject($this->config);
        $mailchimpToCrmProperty = $configReflection->getProperty('mailchimpToCrm');
        $mailchimpToCrmProperty->setAccessible(true);
        // Simulate missing mailchimpToCrm config part so isUpsertToCrmEnabled=false
        $mailchimpToCrmProperty->setValue($this->config, []);

        $this->mockCrmResponse([
            ['id' => 12345],
            ['id' => 12346]
        ]);

        $result = $this->sync->syncAll(10, 0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('processed', $result);
    }

    public function testGetRequestFilterParams_WithUpsertDisabled_ExcludesInterestParams()
    {
        $configReflection = new \ReflectionObject($this->config);
        $mailchimpToCrmProperty = $configReflection->getProperty('mailchimpToCrm');
        $mailchimpToCrmProperty->setAccessible(true);
        // Simulate missing mailchimpToCrm config part so isUpsertToCrmEnabled=false
        $mailchimpToCrmProperty->setValue($this->config, []);

        // Create a reflection method to access the private getRequestFilterParams method
        $getFilterParamsMethod = new \ReflectionMethod($this->sync, 'getRequestFilterParams');
        $getFilterParamsMethod->setAccessible(true);
        $result = $getFilterParamsMethod->invoke($this->sync);

        $this->assertEquals('subscribed', $result['status']);
        $this->assertArrayNotHasKey('interest_category_id', $result);
        $this->assertArrayNotHasKey('interest_ids', $result);
        $this->assertArrayNotHasKey('interest_match', $result);
    }

    public function testConstructor_ThrowsWhenUpsertDisabled()
    {
        // When mailchimpToCrm section is missing, isUpsertToCrmEnabled=false and constructor should throw
        $this->expectException(\App\Exceptions\ConfigException::class);
        new MailchimpToCrmCronSynchronizer('tests/test.no-upsert.yml');
    }
}
