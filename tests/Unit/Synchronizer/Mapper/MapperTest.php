<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Synchronizer\Mapper;


use Tests\TestCase;

class MapperTest extends TestCase
{
    
    public function testMailchimpToCrm()
    {
        $mapper = new Mapper($this->getFieldMaps());
        $crmData = $mapper->mailchimpToCrm($this->getMailchimpData());
        
        $expectedData = [
            'email1' => 'info@example.org',
            'newsletterCountryD' => 'yes',
        ];
        $this->assertEquals($expectedData, $crmData);
    }
    
    private function getFieldMaps()
    {
        $fieldConfigs = [
            [
                'crmKey' => 'memberStatusCountry',
                'mailchimpTagName' => 'member',
                'type' => 'tag',
                'conditions' => ['member', 'unconfirmed'],
                'sync' => 'toMailchimp'
            ],
            [
                'crmKey' => 'firstName',
                'mailchimpKey' => 'FNAME',
                'type' => 'merge',
                'default' => 'asdf',
                'sync' => 'toMailchimp'
            ],
            [
                'crmKey' => 'interests',
                'type' => 'autotag',
                'sync' => 'toMailchimp'
            ],
            [
                'crmKey' => 'email1',
                'type' => 'email',
                'sync' => 'both'
            ],
            [
                'crmKey' => 'newsletterCountryD',
                'mailchimpCategoryId' => '55f795def4',
                'type' => 'group',
                'trueCondition' => 'yes',
                'falseCondition' => 'no',
                'sync' => 'both'
            ]
        ];
        
        $fieldMaps = [];
        foreach ($fieldConfigs as $config) {
            $fieldMaps[] = new FieldMapFacade($config);
        }
        
        return $fieldMaps;
    }
    
    private function getMailchimpData()
    {
        return [
            'email_address' => 'info@example.org',
            'tags' => [
                ['id' => 1234, 'name' => 'member'],
                ['id' => 1234, 'name' => 'digitisation'],
                ['id' => 1234, 'name' => 'energy'],
            ],
            'merge_fields' => [
                'FNAME' => 'Hugo'
            ],
            'interests' => [
                '55f795def4' => true
            ]
        ];
    }
    
    public function testCrmToMailchimp()
    {
        $mapper = new Mapper($this->getFieldMaps());
        $crmData = $mapper->crmToMailchimp($this->getCrmData());
        
        $expectedData = [
            'email_address' => 'info@example.org',
            'tags' => ['member', 'digitisation', 'energy'],
            'merge_fields' => [
                'FNAME' => 'Hugo'
            ],
            'interests' => [
                '55f795def4' => true
            ]
        ];
        $this->assertEquals($expectedData, $crmData);
    }
    
    private function getCrmData()
    {
        return [
            'email1' => 'info@example.org',
            'memberStatusCountry' => 'member',
            'interests' => ['digitisation', 'energy'],
            'firstName' => 'Hugo',
            'newsletterCountryD' => 'yes',
        ];
    }
}
