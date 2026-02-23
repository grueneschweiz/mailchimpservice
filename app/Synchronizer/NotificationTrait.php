<?php

namespace App\Synchronizer;

use Illuminate\Support\Facades\Mail;
use App\Mail\InvalidEmailNotification;

trait NotificationTrait
{
    /**
     * Inform data owner about invalid email submission
     *
     * @param array $mcData
     */
    private function notifyAdminInvalidEmail(array $mcData): void
    {
        $dataOwner = $this->config->getDataOwner();
        
        $mailData = new \stdClass();
        $mailData->dataOwnerName = $dataOwner['name'];
        $mailData->contactFirstName = $mcData['merge_fields']['FNAME'] ?? '';
        $mailData->contactLastName = $mcData['merge_fields']['LNAME'] ?? '';
        $mailData->contactEmail = $mcData['email_address'];
        $mailData->adminEmail = env('ADMIN_EMAIL');
        $mailData->configName = $this->configName;
        
        Mail::to($dataOwner['email'])
            ->send(new InvalidEmailNotification($mailData));
    }
}
