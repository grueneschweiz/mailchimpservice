<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('syncs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('crm_id');
            $table->unsignedInteger('internal_revision_id');
            $table->timestamps();
            
            $table->index('crm_id');
            $table->index('internal_revision_id');
            
            $table->foreign('internal_revision_id')
                ->references('id')
                ->on('revisions');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('syncs');
    }
}
