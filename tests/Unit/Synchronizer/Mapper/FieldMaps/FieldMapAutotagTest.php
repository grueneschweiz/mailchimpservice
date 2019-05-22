<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer\Mapper\FieldMaps;


use Tests\TestCase;

class FieldMapAutotagTest extends TestCase {

	private function getConfig() {
		return [
			'crmKey' => 'interests',
			'type'   => 'autotag',
			'sync'   => 'toMailchimp'
		];
	}

	private function getCrmData() {
		return [
			'interests' => [ 'digitisation', 'energy' ],
		];
	}

	private function getMailchimpData() {
		return [ 'digitisation', 'energy' ];
	}

	public function testGetMailchimpParentKey() {
		$map = new FieldMapAutotag( $this->getConfig() );
		$this->assertEquals( 'tags', $map->getMailchimpParentKey() );
	}

	public function testGetCrmDataArray() {
		$map = new FieldMapAutotag( $this->getConfig() );
		$map->addMailchimpData( $this->getMailchimpData() );

		$this->assertEmpty( $map->getCrmDataArray() );
	}

	public function testGetMailchimpDataArray__add() {
		$map = new FieldMapAutotag( $this->getConfig() );
		$map->addCrmData( $this->getCrmData() );

		$this->assertEquals( [ 'digitisation', 'energy' ], $map->getMailchimpDataArray() );
	}

	public function testGetMailchimpDataArray__remove() {
		$map = new FieldMapAutotag( $this->getConfig() );
		$map->addCrmData( [ 'interests' => [] ] );

		$this->assertEquals( [], $map->getMailchimpDataArray() );
	}
}
