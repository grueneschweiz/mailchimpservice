<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer;


use Tests\TestCase;

class ConfigTest extends TestCase {
	/**
	 * @var Config
	 */
	private $config;

	public function setUp() {
		parent::setUp();

		config( [ 'app.config_base_path' => 'tests' ] );

		$this->config = new Config( 'test.io.yml' );
	}

	public function testGetCrmCredentials() {
		$cred = $this->config->getCrmCredentials();

		$this->assertEquals( 'crmclientid', $cred['clientId'] );
		$this->assertEquals( 'crmclientsecret', $cred['clientSecret'] );
		$this->assertEquals( 'crmclienturl', $cred['url'] );
	}

	public function testGetMailchimpCredentials() {
		$cred = $this->config->getMailchimpCredentials();

		$this->assertEquals( 'apikey', $cred['apikey'] );
		$this->assertEquals( 'apiurl', $cred['url'] );
	}

	public function testGetDataOwner() {
		$owner = $this->config->getDataOwner();

		$this->assertEquals( 'dataowner@example.com', $owner['email'] );
		$this->assertEquals( 'dataowner', $owner['name'] );
	}
}
