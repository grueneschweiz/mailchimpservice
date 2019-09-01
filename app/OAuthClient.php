<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OAuthClient extends Model
{
    /**
     * The primary key is not an autoincrement
     *
     * @var bool
     */
    public $incrementing = false;
    /**
     * The primary key
     *
     * @var string
     */
    protected $primaryKey = 'client_id';
    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'client_secret',
        'token',
    ];
    
    /**
     * The model's default values for attributes.
     *
     * @var array
     */
    protected $attributes = [
        'token' => null,
    ];
}
