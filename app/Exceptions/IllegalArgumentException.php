<?php

namespace App\Exceptions;


class IllegalArgumentException extends \Exception {

  /**
   * Render the exception into an HTTP response.
   *
   * @param  \Illuminate\Http\Request
   * @return \Illuminate\Http\Response
   */
  public function render($request) {
      abort(400, "Something is wrong with your argument: " . $this->getMessage());
  }

}
