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

		// mock the crm client

		// replace the mailchimp client with one with secure but real credentials
		$mailchimpClient = new \ReflectionProperty( $this->sync, 'mailchimpClient' );
		$mailchimpClient->setAccessible( true );
		$mailchimpClient->setValue( $this->sync, new MailChimpClient( env( 'MAILCHIMP_APIKEY' ), $config->getMailchimpListId() ) );
		$this->mcClientTesting = $mailchimpClient->getValue( $this->sync );
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

	public function testSyncAllChanges() {
		$member1 = $this->getMember( 1, 'member1@gruene.ch' ); // relevant
		$member2 = $this->getMember( 2, 'member2@gruene.ch' ); // not relevant

		$member2['newsletterCountryD'] = 'no';

		$this->mockCrmResponse( [
			new Response( 200, [], json_encode( 123 ) ),
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
		$this->assertEquals( 123, $id );


		// part 2: next revision - update
		// member1: change subscriptions
		// member2: make relevant

		// part 3: next revision - delete
		// member1: delete
		// member2: delete

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
