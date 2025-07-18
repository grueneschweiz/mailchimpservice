<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer;


use App\Http\CrmClient;
use App\Http\MailChimpClient;
use App\Mail\WrongSubscription;
use App\OAuthClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailchimpToCrmSynchronizerTest extends TestCase
{
    use RefreshDatabase;
    
    private const CONFIG_FILE_NAME = 'test.io.yml';
    
    /**
     * @var MailChimpClient
     */
    private $mcClientTesting;
    
    /**
     * @var MailchimpToCrmSynchronizer
     */
    private $sync;
    
    /**
     * @var Config
     */
    private $config;
    
    /**
     * Guzzle request history
     *
     * @var array
     */
    private $crmRequestHistory = [];
    
    public function setUp(): void
    {
        parent::setUp();
        
        // crm client prepare access token
        $auth = new OAuthClient();
        $auth->client_id = 1;
        $auth->client_secret = 'crmclientsecret';
        $auth->token = 'crmclienttoken';
        $auth->save();
        
        // get synchronizer
        $sync = new \ReflectionClass(MailchimpToCrmSynchronizer::class);
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
        
        // replace the mailchimp client with one with secure but real credentials
        $mailchimpClient = new \ReflectionProperty($this->sync, 'mcClient');
        $mailchimpClient->setAccessible(true);
        $mailchimpClient->setValue($this->sync, new MailChimpClient(env('MAILCHIMP_APIKEY'), $this->config->getMailchimpListId()));
        $this->mcClientTesting = $mailchimpClient->getValue($this->sync);
    }
    
    public function testSyncSingle__subscribe()
    {
        // config
        Mail::fake();
        $email = Str::random() . '@mymail.com';
        
        // precondition
        $subscriber = [
            'email_address' => $email,
            'merge_fields' => [
                'FNAME' => 'First Name',
                'LNAME' => 'Last Name',
                'GENDER' => 'n',
                'WEBLINGID' => '',
            ],
            'interests' => [
                '55f795def4' => true,
                '1851be732e' => false,
                '294df36247' => true,
                '633e3c8dd7' => false,
                'bba5d2d564' => true,
            ],
            'tags' => [],
        ];
        
        $this->mcClientTesting->putSubscriber($subscriber);
        
        // test
        $webhookData = [
            'type' => 'subscribe',
            'data' => [
                'email' => $email,
                'merge_fields' => [
                    'EMAIL' => $email,
                    'FNAME' => 'First Name',
                    'LANME' => 'Last Name',
                    'GENDER' => 'n',
                    'WEBLINGID' => '',
                ],
            ],
        ];
        
        $this->sync->syncSingle($webhookData);

        if ($this->config->getIgnoreSubscribeThroughMailchimp()) {
            Mail::assertNotSent(WrongSubscription::class);
        } else {
            Mail::assertSent(WrongSubscription::class, function ($mail) use ($subscriber) {
                $this->assertEquals($subscriber['merge_fields']['FNAME'], $mail->mail->contactFirstName);
                $this->assertEquals($subscriber['merge_fields']['LNAME'], $mail->mail->contactLastName);
                $this->assertEquals($subscriber['email_address'], $mail->mail->contactEmail);
                $this->assertEquals(env('ADMIN_EMAIL'), $mail->mail->adminEmail);
                $this->assertEquals($this->config->getDataOwner()['name'], $mail->mail->dataOwnerName);
                $this->assertEquals(self::CONFIG_FILE_NAME, $mail->mail->configName);
    
                return true;
            });
        }
    
        // cleanup
        $this->mcClientTesting->deleteSubscriber($email);
    }
    
    
    public function testSyncSingle__subscribe__merges()
    {
        // config
        Mail::fake();
        $email = Str::random() . '@mymail.com';
        
        // precondition
        $subscriber = [
            'email_address' => $email,
            'merge_fields' => [
                'FNAME' => 'First Name',
                'LNAME' => 'Last Name',
                'GENDER' => 'n',
                'WEBLINGID' => '',
            ],
            'interests' => [
                '55f795def4' => true,
                '1851be732e' => false,
                '294df36247' => true,
                '633e3c8dd7' => false,
            ],
            'tags' => [],
        ];
        
        $this->mcClientTesting->putSubscriber($subscriber);
        
        // test
        $webhookData = [
            'type' => 'subscribe',
            'data' => [
                'email' => $email,
                'merges' => [
                    'EMAIL' => $email,
                    'FNAME' => 'First Name',
                    'LANME' => 'Last Name',
                    'GENDER' => 'n',
                    'WEBLINGID' => '',
                ],
            ],
        ];
        
        $this->sync->syncSingle($webhookData);
        
        if ($this->config->getIgnoreSubscribeThroughMailchimp()) {
            Mail::assertNotSent(WrongSubscription::class);
        } else {
            Mail::assertSent(WrongSubscription::class, function ($mail) use ($subscriber) {
                $this->assertEquals($subscriber['merge_fields']['FNAME'], $mail->mail->contactFirstName);
                $this->assertEquals($subscriber['merge_fields']['LNAME'], $mail->mail->contactLastName);
                $this->assertEquals($subscriber['email_address'], $mail->mail->contactEmail);
                $this->assertEquals(env('ADMIN_EMAIL'), $mail->mail->adminEmail);
                $this->assertEquals($this->config->getDataOwner()['name'], $mail->mail->dataOwnerName);
                $this->assertEquals(self::CONFIG_FILE_NAME, $mail->mail->configName);

                return true;
            });
        }

        // cleanup
        $this->mcClientTesting->deleteSubscriber($email);
    }
    
    public function testSyncSingle__unsubscribe()
    {
        // config
        $email = Str::random() . '@mymail.com';
        $crmId = 123456;
        
        // precondition
        $this->mockCrmResponse([
            new Response(200, [], json_encode($this->getMember($crmId, $email))),
            new Response(201)
        ]);
        
        // test
        $webhookData = [
            'type' => 'unsubscribe',
            'data' => [
                'email' => $email,
                'merge_fields' => [
                    'EMAIL' => $email,
                    'FNAME' => 'First Name',
                    'LANME' => 'Last Name',
                    'GENDER' => 'n',
                    'WEBLINGID' => (string)$crmId,
                        'BIRTHDAY' => '2022-08-22'
                ],
            ],
        ];
    
        $this->sync->syncSingle($webhookData);
    
        /** @var Request $put */
        $put = $this->crmRequestHistory[1]['request'];
        $data = json_decode((string)$put->getBody(), true);
    
        $this->assertEquals("member/$crmId", $put->getUri()->getPath());
        $this->assertEquals('no', $data['newsletterCountryD'][0]['value']);
        $this->assertEquals('no', $data['newsletterCountryF'][0]['value']);
        $this->assertEquals('no', $data['pressReleaseCountryD'][0]['value']);
        $this->assertEquals('no', $data['pressReleaseCountryF'][0]['value']);
        $this->assertEquals('PolitletterUnsubscribed', $data['notesCountry'][0]['value']);
        $this->assertEquals('append', $data['notesCountry'][0]['mode']);
        $this->assertEquals('PolitletterDE', $data['notesCountry'][1]['value']);
        $this->assertEquals('remove', $data['notesCountry'][1]['mode']);
    }
    
    private function mockCrmResponse(array $responses)
    {
        $sync = new \ReflectionClass($this->sync);
        $crmClient = $sync->getProperty('crmClient');
        $crmClient->setAccessible(true);
        
        $refClient = new \ReflectionClass(CrmClient::class);
        $client = $refClient->newInstanceWithoutConstructor();
        
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        
        $history = Middleware::history($this->crmRequestHistory);
        $handler->push($history);
        
        $guzzle = $refClient->getProperty('guzzle');
        $guzzle->setAccessible(true);
        $guzzle->setValue($client, new Client(['handler' => $handler]));
        
        $crmClient->setValue($this->sync, $client);
    }
    
    private function getMember($crmId, $email)
    {
        return [
            'recordStatus' => 'active',
            'email1' => $email,
            'emailStatus' => 'active',
            'firstName' => 'my first name',
            'lastName' => 'my last name',
            'gender' => 'f',
                'birthday' => '2023-08-22',
            'newsletterCountryD' => 'yes',
            'newsletterCountryF' => 'no',
            'pressReleaseCountryD' => 'no',
            'pressReleaseCountryF' => 'no',
            'memberStatusCountry' => 'member',
            'interests' => ['climate', 'energy'],
            'donorCountry' => 'sponsor',
            'notesCountry' => 'Go to hell',
            'firstLevelGroupNames' => 'BE',
            'recordCategory' => 'media',
            'id' => (string)$crmId,
        ];
    }
    
    public function testSyncSingle__bounced()
    {
        $email = Str::random() . '@mymail.com';
        $crmId = random_int(10 ** 6, 10 ** 7);
        
        // precondition
        $subscriber = [
            'email_address' => $email,
            'merge_fields' => [
                'FNAME' => 'First Name',
                'LNAME' => 'Last Name',
                'GENDER' => 'n',
                'WEBLINGID' => (string)$crmId,
            ],
            'interests' => [
                '55f795def4' => true,
                '1851be732e' => false,
                '294df36247' => true,
                '633e3c8dd7' => false,
            ],
            'tags' => [],
        ];
        
        $this->mcClientTesting->putSubscriber($subscriber);
        
        $member = $this->getMember($crmId, $email);
        
        // precondition
        $this->mockCrmResponse([
            new Response(201)
        ]);
        
        // test
        $webhookData = [
            'type' => 'cleaned',
            'data' => [
                'email' => $email,
                'reason' => 'hard',
            ],
        ];
    
        $this->sync->syncSingle($webhookData);
    
        /** @var Request $put */
        $put = $this->crmRequestHistory[0]['request'];
        $data = json_decode((string)$put->getBody(), true);
    
        $this->assertEquals("member/$crmId", $put->getUri()->getPath());
        $this->assertEquals('invalid', $data['emailStatus'][0]['value']);
        $this->assertEquals('replace', $data['emailStatus'][0]['mode']);
        $this->assertStringContainsString('Mailchimp reported the email as invalid. Email status changed.', $data['notesCountry'][0]['value']);
        $this->assertEquals('append', $data['notesCountry'][0]['mode']);
    
        // cleanup
        $this->mcClientTesting->deleteSubscriber($email);
    }
    
    public function testSyncSingle__updated()
    {
        // config
        $email = Str::random() . '@mymail.com';
        $crmId = 123456;
        $member = $this->getMember($crmId, $email);
        
        // precondition
        $this->mockCrmResponse([
            new Response(200, [], json_encode($member)),
            new Response(201)
        ]);
        
        // precondition
        $subscriber = [
            'email_address' => $email,
            'merge_fields' => [
                'FNAME' => 'First Name',
                'LNAME' => 'Last Name',
                'GENDER' => 'n',
                'WEBLINGID' => (string)$crmId,
            ],
            'interests' => [
                '55f795def4' => true,
                '1851be732e' => false,
                '294df36247' => true,
                '633e3c8dd7' => false,
                'bba5d2d564' => true,
            ],
            'tags' => [],
        ];
        
        $this->mcClientTesting->putSubscriber($subscriber);
        
        // test
        $webhookData = [
            'type' => 'profile',
            'data' => [
                'email' => $email,
                'merges' => [
                    'EMAIL' => $email,
                    'FNAME' => 'First Name',
                    'LANME' => 'Last Name',
                    'GENDER' => 'n',
                    'WEBLINGID' => (string)$crmId,
                ],
            ],
        ];
    
        $this->sync->syncSingle($webhookData);
    
        /** @var Request $put */
        $put = $this->crmRequestHistory[0]['request'];
        $data = json_decode((string)$put->getBody(), true);
    
        $this->assertEquals("member/$crmId", $put->getUri()->getPath());
        $this->assertEquals('yes', $data['newsletterCountryD'][0]['value']);
        $this->assertEquals('no', $data['newsletterCountryF'][0]['value']);
        $this->assertEquals('yes', $data['pressReleaseCountryD'][0]['value']);
        $this->assertEquals('no', $data['pressReleaseCountryF'][0]['value']);
        $this->assertEquals('PolitletterDE', $data['notesCountry'][0]['value']);
        $this->assertEquals('append', $data['notesCountry'][0]['mode']);
        $this->assertEquals('PolitletterUnsubscribed', $data['notesCountry'][1]['value']);
        $this->assertEquals('remove', $data['notesCountry'][1]['mode']);
    
        // cleanup
        $this->mcClientTesting->deleteSubscriber($email);
    }
    
    public function testSyncSingle__emailUpdated()
    {
        // config
        $email = Str::random() . '@mymail.com';
        $newEmail = 'u-' . $email;
        $crmId = 123456;
        $member = $this->getMember($crmId, $email);
        
        // precondition
        $this->mockCrmResponse([
            new Response(200, [], json_encode($member)),
            new Response(201)
        ]);
        
        // precondition
        $subscriber = [
            'email_address' => $newEmail,
            'merge_fields' => [
                'FNAME' => 'First Name',
                'LNAME' => 'Last Name',
                'GENDER' => 'n',
                'WEBLINGID' => (string)$crmId,
            ],
            'interests' => [
                '55f795def4' => true,
                '1851be732e' => false,
                '294df36247' => true,
                '633e3c8dd7' => false,
            ],
            'tags' => [],
        ];
        
        $this->mcClientTesting->putSubscriber($subscriber);
        
        // test
        $webhookData = [
            'type' => 'upemail',
            'data' => [
                'new_email' => $newEmail,
                'old_email' => $email,
            ],
        ];
        
        $this->sync->syncSingle($webhookData);
        
        /** @var Request $put */
        $put = $this->crmRequestHistory[0]['request'];
        $data = json_decode((string)$put->getBody(), true);
    
        $this->assertEquals("member/$crmId", $put->getUri()->getPath());
        $this->assertEquals($newEmail, $data['email1'][0]['value']);
    }
}
