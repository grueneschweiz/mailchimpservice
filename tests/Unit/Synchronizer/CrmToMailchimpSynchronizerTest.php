<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer;


use App\Http\Controllers\RestApi\CrmClient;
use App\Http\Controllers\RestApi\MailChimpClient;
use App\OAuthClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmToMailchimpSynchronizerTest extends TestCase {
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

	private $emailMember1;
	private $emailMember2;

	public function setUp() {
		parent::setUp();

		// crm client prepare access token
		$auth                = new OAuthClient();
		$auth->client_id     = 1;
		$auth->client_secret = 'crmclientsecret';
		$auth->token         = 'crmclienttoken';
		$auth->save();

		// get synchronizer
		$sync       = new \ReflectionClass( CrmToMailchimpSynchronizer::class );
		$this->sync = $sync->newInstanceWithoutConstructor();

		$configName = new \ReflectionProperty( $this->sync, 'configName' );
		$configName->setAccessible( true );
		$configName->setValue( $this->sync, self::CONFIG_FILE_NAME );

		// mock config
		config( [ 'app.config_base_path' => 'tests' ] );
		$config = new Config( self::CONFIG_FILE_NAME );
		$c      = new \ReflectionProperty( $this->sync, 'config' );
		$c->setAccessible( true );
		$c->setValue( $this->sync, $config );

		// replace the mailchimp client with one with secure but real credentials
		$mailchimpClient = new \ReflectionProperty( $this->sync, 'mailchimpClient' );
		$mailchimpClient->setAccessible( true );
		$mailchimpClient->setValue( $this->sync, new MailChimpClient( env( 'MAILCHIMP_APIKEY' ), $config->getMailchimpListId() ) );
		$this->mcClientTesting = $mailchimpClient->getValue( $this->sync );

		$this->emailMember1 = str_random() . '@mymail.com';
		$this->emailMember2 = str_random() . '@mymail.com';
	}

	private function mockCrmResponse( array $responses ) {
		$sync      = new \ReflectionClass( $this->sync );
		$crmClient = $sync->getProperty( 'crmClient' );
		$crmClient->setAccessible( true );

		$refClient = new \ReflectionClass( CrmClient::class );
		$client    = $refClient->newInstanceWithoutConstructor();

		$mock    = new MockHandler( $responses );
		$handler = HandlerStack::create( $mock );

		$guzzle = $refClient->getProperty( 'guzzle' );
		$guzzle->setAccessible( true );
		$guzzle->setValue( $client, new Client( [ 'handler' => $handler ] ) );

		$crmClient->setValue( $this->sync, $client );
	}

	public function testSyncAllChanges_add_all() {
		$revisionId = 123;

		$member1 = $this->getMember( 1, $this->emailMember1 ); // relevant
		$member2 = $this->getMember( 2, $this->emailMember2 ); // not relevant

		$member2['newsletterCountryD'] = 'no';

		$this->mockCrmResponse( [
			new Response( 200, [], json_encode( $revisionId ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [
				2 => $member2
			] ) ),
			new Response( 200, [], json_encode( [
				2 => $member2
			] ) ),
			new Response( 200, [], json_encode( [
				3 => null
			] ) ),
			new Response( 200, [], json_encode( [] ) ),
		] );

		$this->sync->syncAllChanges( 1, 0 );

		// assert member1 in mailchimp
		$subscriber1 = $this->mcClientTesting->getSubscriber( $member1['email1'] );
		$this->assertEquals( $member1['email1'], $subscriber1['email_address'] );

		// assert member2 not in mailchimp
		$subscriber2 = null;
		try {
			$subscriber2 = $this->mcClientTesting->getSubscriber( $member2['email1'] );
		} catch ( \Exception $e ) {
		}
		$this->assertNull( $subscriber2 );

		// assert getLatestSuccessfullSyncRevisionId is 123
		$getRevId = new \ReflectionMethod( $this->sync, 'getLatestSuccessfullSyncRevisionId' );
		$getRevId->setAccessible( true );
		$id = $getRevId->invoke( $this->sync );
		$this->assertEquals( $revisionId, $id );
	}

	public function testSyncAllChanges_update_fromRevision() {
		$revisionId = 124;

		$member1                = $this->getMember( 1, $this->emailMember1 );
		$member1['emailStatus'] = 'invalid';

		$member2 = $this->getMember( 2, $this->emailMember2 );

		$this->mockCrmResponse( [
			new Response( 200, [], json_encode( $revisionId ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [
				2 => $member2
			] ) ),
			new Response( 200, [], json_encode( [
				2 => $member2
			] ) ),
			new Response( 200, [], json_encode( [] ) ),
		] );

		$this->sync->syncAllChanges( 1, 0 );

		// assert member1 not in mailchimp
		$subscriber1 = null;
		try {
			$subscriber1 = $this->mcClientTesting->getSubscriber( $member1['email1'] );
		} catch ( \Exception $e ) {
		}
		$this->assertNull( $subscriber1 );

		// assert member2 in mailchimp
		$subscriber2 = $this->mcClientTesting->getSubscriber( $member2['email1'] );
		$this->assertEquals( $member2['email1'], $subscriber2['email_address'] );
	}

	public function testSyncAllChanges_email_change() {
		// precondition
		$revisionId = 126;

		$email   = str_random() . '@mymail.com';
		$member1 = $this->getMember( 1, $email );

		$this->mockCrmResponse( [
			new Response( 200, [], json_encode( $revisionId ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [] ) ),
		] );

		$this->sync->syncAllChanges( 1, 0 );

		$subscriber1 = $this->mcClientTesting->getSubscriber( $email );
		$this->assertNotEmpty( $subscriber1 );

		// the test
		$member1['email1'] = str_random() . '@mymail.com';

		$this->mockCrmResponse( [
			new Response( 200, [], json_encode( $revisionId ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [] ) ),
		] );

		$this->sync->syncAllChanges( 1, 0 );

		$subscriber2 = $this->mcClientTesting->getSubscriber( $member1['email1'] );
		$this->assertEquals( $subscriber1['merge_fields']['WEBLINGID'], $subscriber2['merge_fields']['WEBLINGID'] );
		$this->assertNotEquals( $subscriber1['email_address'], $subscriber2['email_address'] );

		// cleanup
		$this->mcClientTesting->deleteSubscriber( $member1['email1'] );
	}

	public function testSyncAllChanges_resubscribe() {
		// precondition
		$revisionId = 127;

		$email   = str_random() . '@mymail.com';
		$member1 = $this->getMember( 1, $email );

		$this->mockCrmResponse( [
			new Response( 200, [], json_encode( $revisionId ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [] ) ),
		] );

		$this->sync->syncAllChanges( 1, 0 );

		$subscriber1 = $this->mcClientTesting->getSubscriber( $email );
		$this->assertNotEmpty( $subscriber1 );

		$subscriber1['status'] = 'unsubscribed';
		$this->mcClientTesting->putSubscriber( $subscriber1 );
		$subscriber1 = $this->mcClientTesting->getSubscriber( $email );
		$this->assertEquals( 'unsubscribed', $subscriber1['status'] );

		// the test
		$this->mockCrmResponse( [
			new Response( 200, [], json_encode( $revisionId ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [
				1 => $member1
			] ) ),
			new Response( 200, [], json_encode( [] ) ),
		] );

		$this->sync->syncAllChanges( 1, 0 );

		$subscriber2 = $this->mcClientTesting->getSubscriber( $email );
		$this->assertEquals( 'subscribed', $subscriber2['status'] );

		// cleanup
		$this->mcClientTesting->deleteSubscriber( $email );
	}

	public function testSyncAllChanges_delete_fromRevision() {
		$revisionId = 125;

		$this->mockCrmResponse( [
			new Response( 200, [], json_encode( $revisionId ) ),
			new Response( 200, [], json_encode( [
				1 => null
			] ) ),
			new Response( 200, [], json_encode( [
				2 => null
			] ) ),
			new Response( 200, [], json_encode( [] ) ),
		] );

		$this->sync->syncAllChanges( 1, 0 );

		// assert member1 not in mailchimp
		$subscriber1 = null;
		try {
			$subscriber1 = $this->mcClientTesting->getSubscriber( $this->emailMember1 );
		} catch ( \Exception $e ) {
		}
		$this->assertNull( $subscriber1 );

		// assert member2 not in mailchimp
		$subscriber2 = null;
		try {
			$subscriber2 = $this->mcClientTesting->getSubscriber( $this->emailMember2 );
		} catch ( \Exception $e ) {
		}
		$this->assertNull( $subscriber2 );
	}

	private function getMember( $crmId, $email ) {
		return [
			'recordStatus'         => 'active',
			'email1'               => $email,
			'emailStatus'          => 'active',
			'firstName'            => 'my first name',
			'lastName'             => 'my last name',
			'gender'               => 'f',
			'newsletterCountryD'   => 'yes',
			'newsletterCountryF'   => 'no',
			'pressReleaseCountryD' => 'no',
			'pressReleaseCountryF' => 'no',
			'memberStatusCountry'  => 'member',
			'interests'            => [ 'climate', 'energy' ],
			'donorCountry'         => 'sponsor',
			'notesCountry'         => 'Go to hell',
			'group'                => 'BE',
			'recordCategory'       => 'media',
			'id'                   => (string) $crmId,
		];
	}
}
