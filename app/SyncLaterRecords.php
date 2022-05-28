<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $crm_id
 * @property string $config_name
 * @property int $attempts
 * @property Carbon $sync_successful
 * @property Carbon $updated_at
 * @property Carbon $created_at
 * @mixin Builder
 */
class SyncLaterRecords extends Model
{
    use HasFactory;
    
    public static function hasRecordsQueuedForSync(string $configName): bool
    {
        return self::getRecordsQueuedForSync($configName)->count() > 0;
    }
    
    public static function getRecordsQueuedForSync(string $configName): Collection
    {
        return self::where('config_name', '=', $configName)
            ->whereNull('sync_successful')
            ->where('updated_at', '<', date_create('-1h')->format('Y-m-d H:i:s'))
            ->get();
    }
}
