<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer\Mapper\FieldMaps;


use Tests\TestCase;

class FieldMapGroupTest extends TestCase {
	private function getConfig() {
		return [
			'crmKey'              => 'newsletterCountryD',
			'mailchimpCategoryId' => '55f795def4',
			'type'                => 'group',
			'trueCondition'       => 'yes',
			'falseCondition'      => 'no',
			'sync'                => 'both'
		];
	}

	private function getCrmData() {
		return [
			'newsletterCountryD' => 'yes',
		];
	}

	private function getMailchimpData() {
		return [
			'interests' => [
				'55f795def4' => true
			]
		];
	}

	public function testGetCrmDataArray__add() {
		$map = new FieldMapGroup( $this->getConfig() );
		$map->addMailchimpData( $this->getMailchimpData() );

		$this->assertEquals( [ 'newsletterCountryD' => 'yes' ], $map->getCrmDataArray() );
	}

	public function testGetCrmDataArray__remove() {
		$map  = new FieldMapGroup( $this->getConfig() );
		$data = [
			'interests' => [
				'55f795def4' => false
			]
		];

		$map->addMailchimpData( $data );

		$this->assertEquals( [ 'newsletterCountryD' => 'no' ], $map->getCrmDataArray() );
	}

	public function testGetMailchimpDataArray__add() {
		$map = new FieldMapGroup( $this->getConfig() );
		$map->addCrmData( $this->getCrmData() );

		$this->assertEquals( [ '55f795def4' => true ], $map->getMailchimpDataArray() );
	}

	public function testGetMailchimpDataArray__remove() {
		$map = new FieldMapGroup( $this->getConfig() );

		$map->addCrmData( [ 'newsletterCountryD' => 'no' ] );
		$this->assertEquals( [ '55f795def4' => false ], $map->getMailchimpDataArray() );

		$map->addCrmData( [ 'newsletterCountryD' => '' ] );
		$this->assertEquals( [ '55f795def4' => false ], $map->getMailchimpDataArray() );
	}

	public function testGetMailchimpParentKey() {
		$map = new FieldMapGroup( $this->getConfig() );
		$this->assertEquals( 'interests', $map->getMailchimpParentKey() );
	}
}
