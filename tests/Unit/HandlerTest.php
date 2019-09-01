<?php

namespace App\Exceptions\Handler;

use App\Exceptions\Handler;
use App\Exceptions\IllegalArgumentException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request as Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HandlerTest extends TestCase
{
    
    private $message = 'This is a test message';
    
    public function testHandle_IllegalArgumentException()
    {
        $this->genericTestHandleException(new IllegalArgumentException(), 400);
    }
    
    /**
     * Helper function to test different Exceptions
     */
    private function genericTestHandleException(\Exception $exception, int $expectedErrorCode, String $message = null)
    {
        $mockInstance = new Handler($this->createMock(Container::class));
        $request = $this->createMock(Request::class);
        $class = new \ReflectionClass(Handler::class);
        $method = $class->getMethod('render');
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
    
}
