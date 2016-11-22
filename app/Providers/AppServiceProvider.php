<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use DB;

class AppServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        DB::listen(function($query) {
            error_log("[".date('Y-m-d H:i:s')."]".$query->sql . ", with[". join(',', $query->bindings)."]" . "\r\n", 3, storage_path()."/logs/SQL-".date('Y-m-d').".log");
            //Log::info("[".$query->time."]".$query->sql . ", with[". join(',', $query->bindings)."]");
        });
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
