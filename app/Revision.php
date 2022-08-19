<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class Revision
 *
 *
 * @mixin Builder
 * @property int $id
 * @property int $revision_id
 * @property string $config_name
 * @property bool $sync_successful
 * @property bool $full_sync
 * @property Carbon $updated_at
 * @property Carbon $created_at
 * @package App
 */
class Revision extends Model
{
    //
}
