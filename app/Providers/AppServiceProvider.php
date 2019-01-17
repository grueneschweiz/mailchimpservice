<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
	    /**
	     * Reduce the default string length in the DB to be compatible
	     * with mysql < 5.7.7
	     *
	     * @see https://laravel-news.com/laravel-5-4-key-too-long-error
	     */
    	Schema::defaultStringLength(191);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
