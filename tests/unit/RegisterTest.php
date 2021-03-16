<?php
/**
 * @author Igor Timoshenkov [it@campoint.net]
 * @started: 2018-12-17 14:38
 */

/**
 * @param $mapping
 */
function phutil_register_library_map($mapping) {
	RegisterTest::$mapperArguments = $mapping;
}

function phutil_register_library($name, $file) {
	RegisterTest::$name = $name;
	RegisterTest::$file = $file;
}

class RegisterTest extends \PHPUnit\Framework\TestCase {

	/**
	 * @var array
	 */
	public static $mapperArguments;

	/**
	 * @var string
	 */
	public static $name;

	/**
	 * @var string
	 */
	public static $file;

	public function setUp(): void {
		static::$mapperArguments = [];
	}

	public function testRegistersUponInclude() {
		// load file
		include_once __DIR__ . './../../.arc/phpunit-seven/__phutil_library_map__.php';

		// check arguments
		$this->assertArrayHasKey('__library_version__', static::$mapperArguments);
		$this->assertArrayHasKey('class', static::$mapperArguments);
		$this->assertArrayHasKey('function', static::$mapperArguments);
		$this->assertArrayHasKey('xmap', static::$mapperArguments);

		$this->assertArrayHasKey('PHPUnit7Engine', static::$mapperArguments['class']);
		$this->assertArrayHasKey('PHPUnit7Parser', static::$mapperArguments['class']);
	}

	public function testInitArgumentsOnInclude() {
		$init = __DIR__ . '/../../.arc/phpunit-seven/__phutil_library_init__.php';

		// load file
		include_once $init;

		$this->assertEquals('phpunit-seven', static::$name);
		$this->assertEquals(realpath($init), static::$file);
	}
}
