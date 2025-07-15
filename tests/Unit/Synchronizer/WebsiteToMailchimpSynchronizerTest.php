<?php
/** @noinspection JsonEncodingApiUsageInspection */

/** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer;

use Tests\TestCase;
use App\Exceptions\InvalidEmailException;
use App\Exceptions\MailchimpClientException;
use App\OAuthClient;
use App\Http\MailChimpClient;
use App\Synchronizer\Mapper\Mapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class WebsiteToMailchimpSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIG_FILE_NAME = 'test.io.yml';

    /**
     * @var WebsiteToMailchimpSynchronizer
     */
    private $sync;

    /**
     * @var Config
     */
    private $config;

    protected function setUp(): void
    {
        parent::setUp();

        // crm client prepare access token
        $auth = new OAuthClient();
        $auth->client_id = 1;
        $auth->client_secret = 'crmclientsecret';
        $auth->token = 'crmclienttoken';
        $auth->save();

        // get synchronizer
        $sync = new \ReflectionClass(WebsiteToMailchimpSynchronizer::class);
        $this->sync = $sync->newInstanceWithoutConstructor();

        $configName = new \ReflectionProperty($this->sync, 'configName');
        $configName->setAccessible(true);
        $configName->setValue($this->sync, self::CONFIG_FILE_NAME);

        // mock config
        config(['app.config_base_path' => 'tests']);
        $this->config = new Config(self::CONFIG_FILE_NAME);
        $c = new \ReflectionProperty($this->sync, 'config');
        $c->setAccessible(true);
        $c->setValue($this->sync, $this->config);

        // add filter
        $filter = new \ReflectionProperty($this->sync, 'filter');
        $filter->setAccessible(true);
        $filter->setValue($this->sync, new Filter($this->config->getFieldMaps(), $this->config->getSyncAll()));

        // add mapper
        $mapper = new \ReflectionProperty($this->sync, 'mapper');
        $mapper->setAccessible(true);
        $mapper->setValue($this->sync, new Mapper($this->config->getFieldMaps()));

        // replace the mailchimp client with one with secure but real credentials
        $mailchimpClient = new \ReflectionProperty($this->sync, 'mailchimpClient');
        $mailchimpClient->setAccessible(true);
        $mailchimpClient->setValue($this->sync, new MailChimpClient(env('MAILCHIMP_APIKEY'), $this->config->getMailchimpListId()));
    }

    public function testSyncSingleWithValidData()
    {
        $email = Str::random() . '@mymail.com';

        $websiteData = [
            'email1' => $email,
            'firstName' => 'John',
            'lastName' => 'Doe',
            'gender' => 'm',
            'newsletterCountryD' => 'yes',
        ];

        $result = $this->sync->syncSingle($websiteData);

        $this->assertEquals('subscribed', $result['status']);
        $this->assertEquals(strtolower($websiteData['email1']), $result['email_address']);
    }

    public function testSyncSingleWithMissingEmail()
    {
        // Define test data with missing email
        $websiteData = [
            'firstName' => 'John',
            'lastName' => 'Doe'
        ];

        // Expect an exception
        $this->expectException(InvalidEmailException::class);
        $this->expectExceptionMessage('Email address is required');

        // Execute the method under test
        $this->sync->syncSingle($websiteData);
    }

    public function testSyncSingleWithInvalidEmail()
    {
        // Define test data with invalid email
        $websiteData = [
            'email1' => 'not-an-email',
            'firstName' => 'John',
            'lastName' => 'Doe'
        ];

        // Expect an exception
        $this->expectException(InvalidEmailException::class);
        $this->expectExceptionMessage('Invalid email format: not-an-email');

        // Execute the method under test
        $this->sync->syncSingle($websiteData);
    }
}
