<?php

namespace App\Exceptions\Handler;

use Tests\TestCase;
use App\Exceptions\Handler;
use Illuminate\Http\Request as Request;
use Illuminate\Contracts\Container\Container;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Exceptions\IllegalArgumentException;

class HandlerTest extends TestCase {

  private $message = 'This is a test message';

  /**
  * Helper function to test different Exceptions
  */
  private function genericTestHandleException(\Exception $exception, int $expectedErrorCode, String $message = null) {
    $mockInstance = new Handler($this->createMock(Container::class));
    $request = $this->createMock(Request::class);
    $class   = new \ReflectionClass(Handler::class);
    $method  = $class->getMethod('render');
    $method->setAccessible(true);
    try {
      $method->invokeArgs($mockInstance, [$request, $exception]);
    } catch (HttpException $e) {
      $this->assertEquals($expectedErrorCode, $e->getStatusCode());
      if ($message) {
        $this->assertEquals($message, $e->getMessage());
      }
    }
  }

  public function testHandle_IllegalArgumentException() {
    $this->genericTestHandleException(new IllegalArgumentException(), 400);
  }

}
