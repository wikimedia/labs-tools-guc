<?php

use Guc\Wiki;

class WikiTest extends PHPUnit\Framework\TestCase {

	public static function provideEscapeId() {
		return [
			[ 'Foo bar', 'Foo_bar' ],
			[ 'Foo/bar', 'Foo.2Fbar' ],
		];
	}

	/**
	 * @dataProvider provideEscapeId
	 */
	public function testEscapeId( $input, $expected ) {
		$this->assertEquals( $expected, Wiki::escapeId( $input ) );
	}
}
