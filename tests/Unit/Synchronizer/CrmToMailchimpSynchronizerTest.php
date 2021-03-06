<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer;


use App\Exceptions\MailchimpClientException;
use App\Http\CrmClient;
use App\Http\MailChimpClient;
use App\Mail\InvalidEmailNotification;
use App\OAuthClient;
use App\Revision;
use App\Synchronizer\Mapper\Mapper;
use ErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmToMailchimpSynchronizerTest extends TestCase
{
    use RefreshDatabase;
    
    private const CONFIG_FILE_NAME = 'test.io.yml';
    
    /**
     * @var MailChimpClient
     */
    private $mcClientTesting;
    
    /**
     * @var CrmToMailchimpSynchronizer
     */
    private $sync;
    
    /**
     * @var Config
     */
    private $config;
    
    private $emailMember1;
    private $emailMember2;
    
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
        $sync = new \ReflectionClass(CrmToMailchimpSynchronizer::class);
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
        
        // add lock path
        $lockRoot = new \ReflectionProperty($this->sync, 'lockRoot');
        $lockRoot->setAccessible(true);
        $lockRoot->setValue($this->sync, storage_path() . '/locks');
        
        // add lock path
        $lockFile = new \ReflectionProperty($this->sync, 'lockFile');
        $lockFile->setAccessible(true);
        $lockFile->setValue($this->sync, "{$lockRoot->getValue($this->sync)}/{$configName->getValue($this->sync)}.lock");
    
        // replace the mailchimp client with one with secure but real credentials
        $mailchimpClient = new \ReflectionProperty($this->sync, 'mailchimpClient');
        $mailchimpClient->setAccessible(true);
        $mailchimpClient->setValue($this->sync, new MailChimpClient(env('MAILCHIMP_APIKEY'), $this->config->getMailchimpListId()));
        $this->mcClientTesting = $mailchimpClient->getValue($this->sync);
    
        $this->emailMember1 = Str::random() . '@mymail.com';
        $this->emailMember2 = Str::random() . '@mymail.com';
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
    
        // cleanup after failed tests
        try {
            $this->sync->unlock();
        } catch (ErrorException $e) {
        }
    }
    
    public function testSyncAllChanges_add_all()
    {
        $revisionId = 123;
        
        $member1 = $this->getMember($this->emailMember1); // relevant
        $member2 = $this->getMember($this->emailMember2); // not relevant
        
        $member2['newsletterCountryD'] = 'no';
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([
                $member2['id'] => $member2
            ])),
            new Response(200, [], json_encode($member2)),
            new Response(200, [], json_encode([
                3 => null
            ])),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        // assert member1 in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($member1['email1']);
        $this->assertEquals(strtolower($member1['email1']), $subscriber1['email_address']);
        
        // assert member2 not in mailchimp
        $subscriber2 = null;
        try {
            $subscriber2 = $this->mcClientTesting->getSubscriber($member2['email1']);
        } catch (\Exception $e) {
        }
        $this->assertNull($subscriber2);
        
        // assert getLatestSuccessfullSyncRevisionId is 123
        $getRevId = new \ReflectionMethod($this->sync, 'getLatestSuccessfullSyncRevisionId');
        $getRevId->setAccessible(true);
        $id = $getRevId->invoke($this->sync);
        $this->assertEquals($revisionId, $id);
    }
    
    private function getMember($email)
    {
        return [
            'recordStatus' => 'active',
            'email1' => $email,
            'emailStatus' => 'active',
            'firstName' => 'my first name',
            'lastName' => 'my last name',
            'gender' => 'f',
            'newsletterCountryD' => 'yes',
            'newsletterCountryF' => 'no',
            'pressReleaseCountryD' => 'no',
            'pressReleaseCountryF' => 'no',
            'memberStatusCountry' => 'member',
            'interests' => ['climate', 'energy'],
            'donorCountry' => 'sponsor',
            'notesCountry' => 'Go to hell',
            'group' => 'BE',
            'recordCategory' => 'media',
            'id' => (string)random_int(1, 2147483647),
        ];
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
        
        $guzzle = $refClient->getProperty('guzzle');
        $guzzle->setAccessible(true);
        $guzzle->setValue($client, new Client(['handler' => $handler]));
        
        $crmClient->setValue($this->sync, $client);
    }
    
    public function testSyncAllChanges_update_fromRevision()
    {
        $revisionId = 124;
        
        $member1 = $this->getMember($this->emailMember1);
        $member1['emailStatus'] = 'invalid';
        
        $member2 = $this->getMember($this->emailMember2);
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([
                $member2['id'] => $member2
            ])),
            new Response(200, [], json_encode($member2)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        // assert member1 not in mailchimp
        $subscriber1 = null;
        try {
            $subscriber1 = $this->mcClientTesting->getSubscriber($member1['email1']);
        } catch (\Exception $e) {
        }
        $this->assertNull($subscriber1);
        
        // assert member2 in mailchimp
        $subscriber2 = $this->mcClientTesting->getSubscriber($member2['email1']);
        $this->assertEquals(strtolower($member2['email1']), $subscriber2['email_address']);
    }
    
    public function testSyncAllChanges_email_change()
    {
        // precondition
        $revisionId = 126;
        
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        $subscriber1 = $this->mcClientTesting->getSubscriber($email);
        $this->assertNotEmpty($subscriber1);
        
        // the test
        $member1['email1'] = Str::random() . '@mymail.com';
        $member1['group'] = 'ZH';
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                1 => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        $subscriber2 = $this->mcClientTesting->getSubscriber($member1['email1']);
        $this->assertEquals($subscriber1['merge_fields']['WEBLINGID'], $subscriber2['merge_fields']['WEBLINGID']);
        $this->assertNotEquals($subscriber1['email_address'], $subscriber2['email_address']);
        
        // cleanup
        $this->mcClientTesting->deleteSubscriber($member1['email1']);
    }
    
    public function testSyncAllChanges_fake_email()
    {
        Mail::fake();
        
        // the test
        $member1 = $this->getMember(Str::random() . '@gmail.con');
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode(123)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        // assert member1 not in mailchimp
        $subscriber1 = null;
        try {
            $subscriber1 = $this->mcClientTesting->getSubscriber($member1['email1']);
        } catch (\Exception $e) {
        }
        self::assertNull($subscriber1);
        
        Mail::assertSent(InvalidEmailNotification::class, function ($mail) use ($member1) {
            $this->assertEquals($member1['firstName'], $mail->mail->contactFirstName);
            $this->assertEquals($member1['lastName'], $mail->mail->contactLastName);
            $this->assertEquals(strtolower($member1['email1']), $mail->mail->contactEmail);
            $this->assertEquals(env('ADMIN_EMAIL'), $mail->mail->adminEmail);
            $this->assertEquals($this->config->getDataOwner()['name'], $mail->mail->dataOwnerName);
            
            return true;
        });
    }
    
    public function testSyncAllChanges_tag_change()
    {
        // precondition
        $revisionId = 126;
        
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);
        
        // make sure there is no member with this id
        try {
            $email = $this->mcClientTesting->getSubscriberEmailByCrmId($member1['id'], 'id');
            $this->mcClientTesting->deleteSubscriber($email);
        } catch (\Exception $e) {
        }
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        $subscriber1 = $this->mcClientTesting->getSubscriber($email);
        $this->assertNotEmpty($subscriber1);
        
        // the test
        $member1['memberStatusCountry'] = 'sympathizer';
        $member1['interests'] = ['climate', 'agriculture'];
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        $subscriber2 = $this->mcClientTesting->getSubscriber($email);
        $tags = array_column($subscriber2['tags'], 'name');
        
        $this->assertTrue(in_array('climate', $tags));
        $this->assertTrue(in_array('agriculture', $tags));
        $this->assertFalse(in_array('energy', $tags));
        
        // cleanup
        $this->mcClientTesting->deleteSubscriber($email);
    }
    
    public function testSyncAllChanges_resubscribe()
    {
        // precondition
        $revisionId = 127;
        
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        $subscriber1 = $this->mcClientTesting->getSubscriber($email);
        $this->assertNotEmpty($subscriber1);
        
        $subscriber1['status'] = 'unsubscribed';
        $this->mcClientTesting->putSubscriber($subscriber1);
        $subscriber1 = $this->mcClientTesting->getSubscriber($email);
        $this->assertEquals('unsubscribed', $subscriber1['status']);
        
        // the test
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        $subscriber2 = $this->mcClientTesting->getSubscriber($email);
        $this->assertEquals('subscribed', $subscriber2['status']);
        
        // cleanup
        $this->mcClientTesting->deleteSubscriber($email);
    }
    
    public function testSyncAllChanges_delete_fromRevision()
    {
        // precondition
        $revisionId = 130;
        
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        // test
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => null
            ])),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        // assert member1 not in mailchimp
        $subscriber1 = null;
        try {
            $subscriber1 = $this->mcClientTesting->getSubscriber($this->emailMember1);
        } catch (\Exception $e) {
        }
        $this->assertNull($subscriber1);
    }
    
    public function testSyncAllChanges_update_twice_fromRevision()
    {
        $revisionId = 131;
        
        $member1 = $this->getMember($this->emailMember1);
        
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode($member1)),
            new Response(200, [], json_encode([])),
        ]);
        
        $this->sync->syncAllChanges(1, 0);
        
        // assert member1 in synced database
        $internalRevisionId = Revision::where('config_name', self::CONFIG_FILE_NAME)
            ->latest()
            ->firstOrFail()
            ->id;
        $this->assertDatabaseHas('syncs', [
            'crm_id' => (int)$member1['id'],
            'internal_revision_id' => $internalRevisionId
        ]);
    
        // assert member1 is present in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($member1['email1']);
        $this->assertEquals(strtolower($member1['email1']), $subscriber1['email_address']);
    
        // reopen the internal revision (else we can't see, if it was skipped)
        $internalRevision = Revision::find($internalRevisionId);
        $internalRevision->sync_successful = false;
        $internalRevision->save();
    
        // resync with different email. it should not get synced
        $member1['email1'] = 'changed_' . $member1['email1'];
        $this->mockCrmResponse([
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([])),
        ]);
    
        $this->sync->syncAllChanges(1, 0);
        
        // assert the changed member is not present in mailchimp
        $this->expectException(MailchimpClientException::class);
        $this->mcClientTesting->getSubscriber($member1['email1']);
        
        // cleanup
        $this->mcClientTesting->deleteSubscriber($this->emailMember1);
    }
}
