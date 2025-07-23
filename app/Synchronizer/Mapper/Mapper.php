<?php


namespace App\Synchronizer\Mapper;


class Mapper
{
    /**
     * @var FieldMapFacade[]
     */
    private $fieldMaps;

    /**
     * Mapper constructor.
     *
     * @param FieldMapFacade[] $fieldMaps
     */
    public function __construct(array $fieldMaps)
    {
        $this->fieldMaps = $fieldMaps;
    }

    /**
     * Map webhook data from mailchimp so we can save it to the crm
     *
     * @param array $mailchimpData
     * @param bool $forceBothDirections If true, all fields will be synced regardless of their sync direction
     *
     * @return array
     *
     * @throws \App\Exceptions\ParseMailchimpDataException
     */
    public function mailchimpToCrm(array $mailchimpData, bool $forceBothDirections = false)
    {
        $data = [];
        foreach ($this->fieldMaps as $map) {
            if ($forceBothDirections || $map->canSyncToCrm()) {
                $map->addMailchimpData($mailchimpData);
                $crmData = $map->getCrmData();
                foreach ($crmData as $action) {
                    $data[$action->getKey()][] = [
                        'value' => $action->getValue(),
                        'mode' => $action->getMode()
                    ];
                }
            }
        }

        return $data;
    }

    /**
     * Map crm data to an array we can send to mailchimp's PUT members endpoint
     *
     * @param array $crmData
     *
     * @return array
     *
     * @throws \App\Exceptions\ParseCrmDataException
     */
    public function crmToMailchimp(array $crmData)
    {
        $data = [];
        foreach ($this->fieldMaps as $map) {
            if (!$map->canSyncToMailchimp()) {
                continue;
            }

            $map->addCrmData($crmData);
            $parentKey = $map->getMailchimpParentKey();

            if ($parentKey) {
                if (!isset($data[$parentKey])) {
                    $data[$parentKey] = [];
                }

                $data[$parentKey] = array_merge($data[$parentKey], $map->getMailchimpDataArray());
            } else {
                $data = array_merge($data, $map->getMailchimpDataArray());
            }
        }

        return $data;
    }
}
