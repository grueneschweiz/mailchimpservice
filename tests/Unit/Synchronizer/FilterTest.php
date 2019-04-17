<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer;


use Tests\TestCase;

class FilterTest extends TestCase {

	/**
	 * @var Filter
	 */
	private $filter;

	public function setUp() {
		parent::setUp();

		config( [ 'app.config_base_path' => 'tests' ] );
		$config       = new Config( 'test.io.yml' );
		$this->filter = new Filter( $config->getFieldMaps(), $config->getSyncAll() );
	}

	public function testFilter__pass() {
		$records = [ $this->getRecord() ];
		$this->assertEquals( $records, $this->filter->filter( $records ) );
	}

	public function testFilter__status_blocked() {
		$record = $this->getRecord();
		$record['recordStatus'] = 'blocked';
		$records = [ $record ];
		$this->assertEmpty( $this->filter->filter( $records ) );
	}

	public function testFilter__email_empty() {
		$record = $this->getRecord();
		$record['email1'] = '';
		$records = [ $record ];
		$this->assertEmpty( $this->filter->filter( $records ) );
	}

	public function testFilter__email_invalid() {
		$record = $this->getRecord();
		$record['email1'] = 'email@example.com invalid';
		$records = [ $record ];
		$this->assertEmpty( $this->filter->filter( $records ) );
	}

	public function testFilter__no_subscriptions() {
		$record = $this->getRecord();
		$record['pressReleaseCountryD'] = 'no';
		$records = [ $record ];
		$this->assertEmpty( $this->filter->filter( $records ) );
	}

	private function getRecord() {
		return [
			'recordStatus'         => 'active',
			'email1'               => 'mail@example.com',
			'emailStatus'          => 'active',
			'newsletterCountryD'   => 'no',
			'newsletterCountryF'   => 'no',
			'pressReleaseCountryD' => 'yes',
			'pressReleaseCountryF' => 'no',
		];
	}
}
