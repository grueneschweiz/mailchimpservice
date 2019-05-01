<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRevisionsTable extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::create( 'revisions', function ( Blueprint $table ) {
			$table->increments( 'id' );
			$table->unsignedInteger( 'revision_id' );
			$table->string( 'config_name' );
			$table->boolean( 'sync_successful' );
			$table->timestamps();

			$table->index( 'config_name' );
		} );
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::dropIfExists( 'revisions' );
	}
}
