<?php

namespace App\Exceptions;


class MailchimpClientException extends \Exception
{
    
    /**
     * Render the exception into an HTTP response.
     *
     * @param \Illuminate\Http\Request
     *
     * @return \Illuminate\Http\Response
     */
    public function render($request)
    {
        abort(500, "Internal Server Error: " . $this->getMessage());
    }
}
