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
			$table->unsignedInteger( 'user_id' );
			$table->boolean( 'sync_successful' );
			$table->timestamps();

			$table->foreign( 'user_id' )->references( 'id' )->on( 'users' );
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
