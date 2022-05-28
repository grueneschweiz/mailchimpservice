<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sync_later_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('crm_id');
            $table->string('config_name');
            $table->integer('attempts')->default(0);
            $table->timestamp('sync_successful')->nullable();
            $table->timestamps();
            
            $table->index('config_name');
            $table->index('sync_successful');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sync_later_records');
    }
};
