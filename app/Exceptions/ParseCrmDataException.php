<?php

namespace App\Exceptions;


class ParseCrmDataException extends \Exception {

	/**
	 * Render the exception into an HTTP response.
	 *
	 * @param \Illuminate\Http\Request
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function render( $request ) {
		abort( 400, "Invalid payload: " . $this->getMessage() );
	}
}
