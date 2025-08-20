<?php

/** @noinspection JsonEncodingApiUsageInspection */

/** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer;


use App\Exceptions\MailchimpClientException;
use App\Http\CrmClient;
use App\Http\MailChimpClient;
use App\Mail\InvalidEmailNotification;
use App\OAuthClient;
use App\Revision;
use App\Synchronizer\Mapper\Mapper;
use App\SyncLaterRecords;
use ErrorException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
            new Response(200, [], json_encode([
                $member2['id'] => $member2
            ])),
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member2],
                'ratings' => [$member2['id'] => 0]
            ])),
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
        $getRev = new \ReflectionMethod($this->sync, 'getLatestSuccessfullSyncRevision');
        $getRev->setAccessible(true);
        $rev = $getRev->invoke($this->sync);
        $id = $rev->revision_id;
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
            'language' => 'd',
            'groups' => [
                201
            ],
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
            new Response(200, [], json_encode([
                $member2['id'] => $member2
            ])),
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member2],
                'ratings' => [$member2['id'] => 0]
            ])),
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 1]
            ])),
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        $subscriber2 = $this->mcClientTesting->getSubscriber($email);
        $this->assertEquals('subscribed', $subscriber2['status']);

        // cleanup
        $this->mcClientTesting->deleteSubscriber($email);
    }

    public function testSyncAllChanges_delete_fromRevision__noDuplicates()
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        // test
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => null
            ])),
            new Response(200, [], json_encode([
                'status' => 'no_match',
                'matches' => [],
            ])),
            new Response(200, [], json_encode([])),
        ]);

        // mock mailchimp subscribers, as mailchimp is too slow in updating
        // so we don't get the inserted subscribers yes
        $mcClient = new \ReflectionProperty($this->sync, 'mailchimpClient');
        $subscribers = new \ReflectionProperty($mcClient->getValue($this->sync), 'subscribers');
        $subscribers->setValue($mcClient->getValue($this->sync), [
            $member1['email1'] => $member1['id'],
        ]);

        $this->sync->syncAllChanges(1, 0);

        // assert member1 not in mailchimp
        $subscriber1 = null;
        try {
            $subscriber1 = $this->mcClientTesting->getSubscriber($member1['email1']);
        } catch (\Exception $e) {
        }
        $this->assertTrue(
            is_null($subscriber1)
                || $subscriber1['status'] === 'archived'
        );
    }

    public function testSyncAllChanges_delete_fromRevision__withDuplicate()
    {
        // precondition
        $revisionId = 130;

        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);

        $member2 = $member1;
        $member2['firstName'] = 'duplicate 1';

        $member3 = $member1;
        $member3['firstName'] = 'duplicate 2';

        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        // test
        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => null
            ])),
            new Response(200, [], json_encode([
                'status' => 'multiple',
                'matches' => [
                    $member2,
                    $member3,
                ],
            ])),
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member2],
                'ratings' => [$member2['id'] => 0]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        // mock mailchimp subscribers, as mailchimp is too slow in updating
        // so we don't get the inserted subscribers yes
        $mcClient = new \ReflectionProperty($this->sync, 'mailchimpClient');
        $subscribers = new \ReflectionProperty($mcClient->getValue($this->sync), 'subscribers');
        $subscribers->setValue($mcClient->getValue($this->sync), [
            $member1['email1'] => $member1['id'],
        ]);

        $this->sync->syncAllChanges(1, 0);

        // assert member3 in mailchimp
        $subscriber = $this->mcClientTesting->getSubscriber($member3['email1']);

        $this->assertEquals($member2['firstName'], $subscriber['merge_fields']['FNAME']);
        $this->assertEquals($member2['id'], $subscriber['merge_fields']['WEBLINGID']);
        $this->assertEquals(strtolower($member2['email1']), strtolower($subscriber['email_address']));

        // cleanup
        $this->mcClientTesting->deleteSubscriber($member2['email1']);
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
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
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

    public function testSyncAllChanges_force_sync_all_after_failing_revisions()
    {
        // precondition
        $oldSuccessfulRevId = 10;
        $oldFailedRevId = 11;

        DB::insert('INSERT INTO revisions (revision_id, config_name, sync_successful, full_sync, created_at, updated_at) values (?, ?, ?, ?, ?, ?)', [
            $oldSuccessfulRevId,
            self::CONFIG_FILE_NAME,
            true,
            false,
            date_create_immutable('-30 days')->format('Y-m-d H:i:s'),
            date_create_immutable('-30 days')->format('Y-m-d H:i:s')
        ]);

        DB::insert('INSERT INTO revisions (revision_id, config_name, sync_successful, full_sync, created_at, updated_at) values (?, ?, ?, ?, ?, ?)', [
            $oldFailedRevId,
            self::CONFIG_FILE_NAME,
            false,
            false,
            date_create_immutable('-3 seconds')->format('Y-m-d H:i:s'),
            date_create_immutable('-3 seconds')->format('Y-m-d H:i:s')
        ]);

        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);

        $this->mockCrmResponse([
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        Log::shouldReceive('debug')
            ->withAnyArgs();

        // test
        $count = 0;
        $expectedRegex = '/config="' . preg_quote(self::CONFIG_FILE_NAME, '/') . '" msg="Last successful revision .*? Doing full sync."/';
        Log::shouldReceive('info')
            ->withArgs(static function ($args) use ($expectedRegex, &$count) {
                if (preg_match($expectedRegex, $args)) {
                    $count++;
                }
                return true;
            });

        $this->sync->syncAllChanges(1, 0);

        $this->assertEquals(1, $count, "Expected 1 log message of level INFO that matches regex: $expectedRegex");
    }

    public function testSyncAllChanges_not_force_sync_all_after_successfull_revisions()
    {
        // precondition
        $oldSuccessfulRevision = 20;
        $newRevisionId = 21;

        DB::insert('INSERT INTO revisions (revision_id, config_name, sync_successful, full_sync, created_at, updated_at) values (?, ?, ?, ?, ?, ?)', [
            $oldSuccessfulRevision,
            self::CONFIG_FILE_NAME,
            true,
            false,
            date_create_immutable('-3 seconds')->format('Y-m-d H:i:s'),
            date_create_immutable('-3 seconds')->format('Y-m-d H:i:s')
        ]);

        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);

        $this->mockCrmResponse([
            new Response(200, [], json_encode($newRevisionId)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        Log::shouldReceive('debug')
            ->withAnyArgs();

        // test
        $count = 0;
        $expectedRegex = '/' . preg_quote('(' . self::CONFIG_FILE_NAME . ')', '/') . ' Last successful revision .*? Doing full sync./';
        Log::shouldReceive('info')
            ->withArgs(static function ($args) use ($expectedRegex, &$count) {
                if (preg_match($expectedRegex, $args)) {
                    $count++;
                }
                return true;
            });

        $this->sync->syncAllChanges(1, 0);

        $this->assertEquals(0, $count, "Expected 0 log message of level INFO that matches regex: $expectedRegex");
    }

    public function testPutSubscriber__email_changed()
    {
        $putSubscriber = new \ReflectionMethod($this->sync, 'putSubscriber');
        $putSubscriber->setAccessible(true);

        $oldEmail = 'old-' . Str::random() . '@mymail.com';
        $newEmail = 'new-' . Str::random() . '@mymail.com';

        $oldMcRecord = [
            'email_address' => $oldEmail,
            'merge_fields' => [
                'FNAME' => 'Email Change',
                'LNAME' => 'Test',
                'GENDER' => 'f',
                'NOTES' => 'email not yet changed',
                'WEBLINGID' => 2354552,
            ],
            'interests' => [
                '55f795def4' => false,
                '1851be732e' => false,
                '294df36247' => false,
                '633e3c8dd7' => false,
                'bba5d2d564' => false,
            ],
            'tags' => [
                'member',
                'Deutsch',
                'ZG',
            ],
            'status' => 'subscribed',
        ];

        // add subscriber first
        $putSubscriber->invoke($this->sync, $oldMcRecord, "", false);

        // then change email and test
        $newMcRecord = $oldMcRecord;
        $newMcRecord['email_address'] = $newEmail;
        $newMcRecord['merge_fields']['NOTES'] = 'email changed';

        $putSubscriber->invoke($this->sync, $newMcRecord, $oldEmail, true);

        // assert new email in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($newEmail);
        $this->assertEquals(strtolower($newEmail), strtolower($subscriber1['email_address']));

        // assert old email not in mailchimp
        $subscriber2 = null;
        try {
            $subscriber2 = $this->mcClientTesting->getSubscriber($oldEmail);
        } catch (\Exception $e) {
        }
        $this->assertNull($subscriber2);

        // cleanup
        $this->mcClientTesting->permanentlyDeleteSubscriber($newEmail);
    }

    public function testPutSubscriber__email_changed__new_email_already_in_mailchimp()
    {
        $putSubscriber = new \ReflectionMethod($this->sync, 'putSubscriber');
        $putSubscriber->setAccessible(true);

        $oldEmail = 'old-' . Str::random() . '@mymail.com';
        $newEmail = 'new-' . Str::random() . '@mymail.com';

        $oldMcRecord = [
            'email_address' => $oldEmail,
            'merge_fields' => [
                'FNAME' => 'Email Change',
                'LNAME' => 'Test',
                'GENDER' => 'f',
                'NOTES' => 'email not yet changed',
                'WEBLINGID' => 2354552,
            ],
            'interests' => [
                '55f795def4' => false,
                '1851be732e' => false,
                '294df36247' => false,
                '633e3c8dd7' => false,
                'bba5d2d564' => false,
            ],
            'tags' => [
                'member',
                'Deutsch',
                'ZG',
            ],
            'status' => 'subscribed',
        ];

        // add subscriber with old email first
        $putSubscriber->invoke($this->sync, $oldMcRecord, "", false);

        // then add subscriber with new email as well
        $newMcRecord = $oldMcRecord;
        $newMcRecord['email_address'] = $newEmail;
        $putSubscriber->invoke($this->sync, $newMcRecord, "", false);

        // then test the email change
        $newMcRecord['merge_fields']['NOTES'] = 'email changed';
        $putSubscriber->invoke($this->sync, $newMcRecord, $oldEmail, true);

        // assert new email in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($newEmail);
        $this->assertEquals(strtolower($newEmail), strtolower($subscriber1['email_address']));

        // assert old email archived in mailchimp
        $archived = false;
        try {
            $subscriber2 = $this->mcClientTesting->getSubscriber($oldEmail);
            $archived = $subscriber2['status'] === 'archived';
        } catch (\Exception $e) {
        }
        $this->assertTrue($archived);

        // cleanup
        $this->mcClientTesting->permanentlyDeleteSubscriber($newEmail);
        $this->mcClientTesting->permanentlyDeleteSubscriber($oldEmail);
    }

    public function testPutSubscriber__email_changed__new_email_already_in_mailchimp_with_status_of_deleted(): void
    {
        $putSubscriber = new \ReflectionMethod($this->sync, 'putSubscriber');

        $oldEmail = 'old-' . Str::random() . '@mymail.com';
        $newEmail = 'new-' . Str::random() . '@mymail.com';

        $oldMcRecord = [
            'email_address' => $oldEmail,
            'merge_fields' => [
                'FNAME' => 'Email Change',
                'LNAME' => 'Test',
                'GENDER' => 'f',
                'NOTES' => 'email not yet changed',
                'WEBLINGID' => 2354552,
            ],
            'interests' => [
                '55f795def4' => false,
                '1851be732e' => false,
                '294df36247' => false,
                '633e3c8dd7' => false,
                'bba5d2d564' => false,
            ],
            'tags' => [
                'member',
                'Deutsch',
                'ZG',
            ],
            'status' => 'subscribed',
        ];

        // add subscriber with old email first
        $putSubscriber->invoke($this->sync, $oldMcRecord, "", false);

        // then add subscriber with new email as well
        $newMcRecord = $oldMcRecord;
        $newMcRecord['email_address'] = $newEmail;
        $putSubscriber->invoke($this->sync, $newMcRecord, "", false);

        // then archive the subscriber with the new email
        $this->mcClientTesting->deleteSubscriber($newEmail);

        // then test the email change
        $newMcRecord['merge_fields']['NOTES'] = 'email changed';
        $putSubscriber->invoke($this->sync, $newMcRecord, $oldEmail, true);

        // assert new email in mailchimp and subscribed again (so unarchived)
        $subscriber1 = $this->mcClientTesting->getSubscriber($newEmail);
        $this->assertEquals(strtolower($newEmail), strtolower($subscriber1['email_address']));
        $this->assertEquals('subscribed', $subscriber1['status']);

        // assert old email archived in mailchimp
        $archived = false;
        try {
            $subscriber2 = $this->mcClientTesting->getSubscriber($oldEmail);
            $archived = $subscriber2['status'] === 'archived';
        } catch (\Exception $e) {
        }
        $this->assertTrue($archived);

        // cleanup
        $this->mcClientTesting->permanentlyDeleteSubscriber($newEmail);
        $this->mcClientTesting->permanentlyDeleteSubscriber($oldEmail);
    }

    public function testSyncAllChanges_syncRecordsQueuedToSyncLater(): void
    {
        // precondition
        $revisionId = 1;
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);

        DB::insert('INSERT INTO sync_later_records (crm_id, config_name, attempts, created_at, updated_at) values (?, ?, ?, ?, ?)', [
            $member1['id'],
            self::CONFIG_FILE_NAME,
            1,
            date_create_immutable('-3 hours')->format('Y-m-d H:i:s'),
            date_create_immutable('-3 hours')->format('Y-m-d H:i:s')
        ]);

        $this->mockCrmResponse([
            new Response(200, [], json_encode($revisionId, JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode($member1, JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertTrue(SyncLaterRecords::hasRecordsQueuedForSync(self::CONFIG_FILE_NAME));

        $this->sync->syncAllChanges(1, 0);
        $this->mcClientTesting->permanentlyDeleteSubscriber($email);

        $this->assertFalse(SyncLaterRecords::hasRecordsQueuedForSync(self::CONFIG_FILE_NAME));

        $records = DB::select('SELECT * FROM sync_later_records WHERE config_name = ? AND crm_id = ? AND sync_successful IS NOT NULL', [
            self::CONFIG_FILE_NAME,
            $member1['id'],
        ]);

        $this->assertCount(1, $records);
    }

    public function testSyncAllChanges_getRelevantRecord_noDuplicates(): void
    {
        $member1 = $this->getMember(Str::random() . '@mymail.com');

        $this->mockCrmResponse([
            new Response(200, [], json_encode(123)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member1],
                'ratings' => [$member1['id'] => 0]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        // assert member1 in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($member1['email1']);
        $this->mcClientTesting->permanentlyDeleteSubscriber($member1['email1']);
        $this->assertEquals(strtolower($member1['email1']), $subscriber1['email_address']);
    }

    public function testSyncAllChanges_getRelevantRecord_topRated(): void
    {
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);
        $member2 = $this->getMember($email);
        $member3 = $this->getMember('');

        $member1['notesCountry'] = 'member1';
        $member2['notesCountry'] = 'member2';
        $member3['notesCountry'] = 'member3';

        $member1['memberStatusCountry'] = null;
        $member2['memberStatusCountry'] = 'sympathiser';
        $member3['memberStatusCountry'] = 'member';

        $this->mockCrmResponse([
            new Response(200, [], json_encode(123)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([
                'status' => 'multiple',
                'matches' => [$member1, $member2, $member3],
                'ratings' => [
                    $member1['id'] => 0,
                    $member2['id'] => 1,
                    $member3['id'] => 6
                ]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        // assert member2 in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($email);
        $this->mcClientTesting->permanentlyDeleteSubscriber($email);
        $this->assertEquals($member2['notesCountry'], $subscriber1['merge_fields']['NOTES']);
    }

    public function testSyncAllChanges_getRelevantRecord_topRated_prioritizedGroup_single(): void
    {
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);
        $member2 = $this->getMember($email);
        $member3 = $this->getMember('');
        $member4 = $this->getMember($email);

        $member1['notesCountry'] = 'member1';
        $member2['notesCountry'] = 'member2';
        $member3['notesCountry'] = 'member3';
        $member4['notesCountry'] = 'member4';

        $member1['memberStatusCountry'] = null;
        $member2['memberStatusCountry'] = 'sympathiser';
        $member3['memberStatusCountry'] = 'member';
        $member4['memberStatusCountry'] = 'sympathiser';

        $member2['groups'] = [201, 7654321];

        $this->mockCrmResponse([
            new Response(200, [], json_encode(123)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([
                'status' => 'multiple',
                'matches' => [$member1, $member2, $member3, $member4],
                'ratings' => [
                    $member1['id'] => 0,
                    $member2['id'] => 1,
                    $member3['id'] => 6,
                    $member4['id'] => 1,
                ]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        // assert member2 in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($email);
        $this->mcClientTesting->permanentlyDeleteSubscriber($email);
        $this->assertEquals($member2['notesCountry'], $subscriber1['merge_fields']['NOTES']);
    }

    public function testSyncAllChanges_getRelevantRecord_topRated_prioritizedGroup_multiple(): void
    {
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);
        $member2 = $this->getMember($email);
        $member3 = $this->getMember('');
        $member4 = $this->getMember($email);

        $member1['notesCountry'] = 'member1';
        $member2['notesCountry'] = 'member2';
        $member3['notesCountry'] = 'member3';
        $member4['notesCountry'] = 'member4';

        $member1['memberStatusCountry'] = null;
        $member2['memberStatusCountry'] = 'sympathiser';
        $member3['memberStatusCountry'] = 'member';
        $member4['memberStatusCountry'] = 'sympathiser';

        $member2['groups'] = [201, 7654321];
        $member4['groups'] = [201, 1234567];

        $this->mockCrmResponse([
            new Response(200, [], json_encode(123)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([
                'status' => 'multiple',
                'matches' => [$member1, $member2, $member3, $member4],
                'ratings' => [
                    $member1['id'] => 0,
                    $member2['id'] => 1,
                    $member3['id'] => 6,
                    $member4['id'] => 1,
                ]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        // assert member with lower id in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($email);
        $this->mcClientTesting->permanentlyDeleteSubscriber($email);
        $lowerIdMember = (int)$member2['id'] < (int)$member4['id'] ? $member2 : $member4;
        $this->assertEquals($lowerIdMember['notesCountry'], $subscriber1['merge_fields']['NOTES']);
    }

    public function testSyncAllChanges_getRelevantRecord_topRated_alreadyInMailchimp(): void
    {
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);
        $member2 = $this->getMember($email);
        $member3 = $this->getMember('');
        $member4 = $this->getMember($email);

        $member1['notesCountry'] = 'member1';
        $member2['notesCountry'] = 'member2';
        $member3['notesCountry'] = 'member3';
        $member4['notesCountry'] = 'member4';

        $member1['memberStatusCountry'] = null;
        $member2['memberStatusCountry'] = 'sympathiser';
        $member3['memberStatusCountry'] = 'member';
        $member4['memberStatusCountry'] = 'sympathiser';

        // precondition
        $this->mockCrmResponse([
            new Response(200, [], json_encode(123)),
            new Response(200, [], json_encode([
                $member2['id'] => $member2
            ])),
            new Response(200, [], json_encode([
                'status' => 'match',
                'matches' => [$member2],
                'ratings' => [
                    $member2['id'] => 1,
                ]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        // test
        $this->mockCrmResponse([
            new Response(200, [], json_encode(123)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([
                'status' => 'multiple',
                'matches' => [$member1, $member2, $member3, $member4],
                'ratings' => [
                    $member1['id'] => 0,
                    $member2['id'] => 1,
                    $member3['id'] => 6,
                    $member4['id'] => 1,
                ]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        // assert member2 in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($email);
        $this->mcClientTesting->permanentlyDeleteSubscriber($email);
        $this->assertEquals($member2['notesCountry'], $subscriber1['merge_fields']['NOTES']);
    }

    public function testSyncAllChanges_getRelevantRecord_topRated_lowerId(): void
    {
        $email = Str::random() . '@mymail.com';
        $member1 = $this->getMember($email);
        $member2 = $this->getMember($email);
        $member3 = $this->getMember('');
        $member4 = $this->getMember($email);

        $member1['notesCountry'] = 'member1';
        $member2['notesCountry'] = 'member2';
        $member3['notesCountry'] = 'member3';
        $member4['notesCountry'] = 'member4';

        $member1['memberStatusCountry'] = null;
        $member2['memberStatusCountry'] = 'sympathiser';
        $member3['memberStatusCountry'] = 'member';
        $member4['memberStatusCountry'] = 'sympathiser';

        $this->mockCrmResponse([
            new Response(200, [], json_encode(123)),
            new Response(200, [], json_encode([
                $member1['id'] => $member1
            ])),
            new Response(200, [], json_encode([
                'status' => 'multiple',
                'matches' => [$member1, $member2, $member3, $member4],
                'ratings' => [
                    $member1['id'] => 0,
                    $member2['id'] => 1,
                    $member3['id'] => 6,
                    $member4['id'] => 1,
                ]
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $this->sync->syncAllChanges(1, 0);

        // assert member with lower id in mailchimp
        $subscriber1 = $this->mcClientTesting->getSubscriber($email);
        $this->mcClientTesting->permanentlyDeleteSubscriber($email);
        $lowerIdMember = (int)$member2['id'] < (int)$member4['id'] ? $member2 : $member4;
        $this->assertEquals($lowerIdMember['notesCountry'], $subscriber1['merge_fields']['NOTES']);
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
}
