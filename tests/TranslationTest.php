<?php

namespace NawrasBukhariTranslationScanner\Tests;

use NawrasBukhariTranslationScanner\Command\TranslationHelperCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

class TranslationTest extends TestCase
{
    private TranslationHelperCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new TranslationHelperCommand();
    }

    /**
     * @throws ReflectionException
     */
    public function testGetTranslationKeysFromFunctionWithSingleQuotes()
    {
        $keys = [];
        $functionName = '@lang';
        $content = "@lang('key1') and @lang('key2')";

        $this->invokeMethod($this->command, [&$keys, $functionName, $content]);

        $this->assertEquals(['key1' => 'key1', 'key2' => 'key2'], $keys);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetTranslationKeysFromFunctionWithDoubleQuotes()
    {
        $keys = [];
        $functionName = '@lang';
        $content = '@lang("key1") and @lang("key2")';

        $this->invokeMethod($this->command, [&$keys, $functionName, $content]);

        $this->assertEquals(['key1' => 'key1', 'key2' => 'key2'], $keys);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetTranslationKeysFromFunctionWithMixedQuotes()
    {
        $keys = [];
        $functionName = '@lang';
        $content = "@lang('key1') and @lang(\"key2\")";

        $this->invokeMethod($this->command, [&$keys, $functionName, $content]);

        $this->assertEquals(['key1' => 'key1', 'key2' => 'key2'], $keys);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetTranslationKeysFromFunctionWithSingleQuotesForDoubleUnderscore()
    {
        $keys = [];
        $functionName = '__';
        $content = "__('key1') and __('key2')";

        $this->invokeMethod($this->command, [&$keys, $functionName, $content]);

        $this->assertEquals(['key1' => 'key1', 'key2' => 'key2'], $keys);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetTranslationKeysFromFunctionWithDoubleQuotesForDoubleUnderscore()
    {
        $keys = [];
        $functionName = '__';
        $content = '__("key1") and __("key2")';

        $this->invokeMethod($this->command, [&$keys, $functionName, $content]);

        $this->assertEquals(['key1' => 'key1', 'key2' => 'key2'], $keys);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetTranslationKeysFromFunctionWithSingleQuotesForTrans()
    {
        $keys = [];
        $functionName = 'trans';
        $content = "trans('key1') and 'key2'";

        $this->invokeMethod($this->command, [&$keys, $functionName, $content]);

        $this->assertEquals(['key1' => 'key1'], $keys);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetTranslationKeysFromFunctionWithDoubleQuotesForTrans()
    {
        $keys = [];
        $functionName = 'trans';
        $content = 'trans("key1") and "key2"';

        $this->invokeMethod($this->command, [&$keys, $functionName, $content]);

        $this->assertEquals(['key1' => 'key1'], $keys);
    }

    /**
     * Invoke a protected or private method for testing.
     *
     * @throws ReflectionException
     */
    private function invokeMethod(object $object, array $parameters = []): void
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod('getTranslationKeysFromFunction');
        $method->setAccessible(true);

        $method->invokeArgs($object, $parameters);
    }
}
