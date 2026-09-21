<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Unit;

use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Exceptions\ParseException;
use ACFJP\Json\Parser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACFJP\Json\Parser
 */
final class ParserTest extends TestCase {

	private Parser $parser;

	protected function setUp(): void {
		$this->parser = new Parser();
	}

	public function testParsesValidJson(): void {
		self::assertSame( array( 'a' => 1 ), $this->parser->parse( '{"a":1}' ) );
	}

	public function testStripsByteOrderMark(): void {
		self::assertSame( array( 'a' => 1 ), $this->parser->parse( "\xEF\xBB\xBF" . '{"a":1}' ) );
	}

	public function testRejectsEmptyInput(): void {
		$this->expectException( ParseException::class );

		try {
			$this->parser->parse( '   ' );
		} catch ( ParseException $e ) {
			self::assertSame( ErrorCodes::EMPTY_PAYLOAD, $e->errorCode() );
			throw $e;
		}
	}

	public function testRejectsScalarJson(): void {
		$this->expectException( ParseException::class );

		try {
			$this->parser->parse( '"just a string"' );
		} catch ( ParseException $e ) {
			self::assertSame( ErrorCodes::NOT_AN_OBJECT, $e->errorCode() );
			throw $e;
		}
	}

	/**
	 * The hints exist so that an AI handed the error usually fixes it next try.
	 */
	public function testTrailingCommaProducesAHint(): void {
		try {
			$this->parser->parse( '{"a":1,}' );
			self::fail( 'Expected a ParseException.' );
		} catch ( ParseException $e ) {
			self::assertSame( ErrorCodes::INVALID_JSON, $e->errorCode() );
			self::assertNotEmpty( $e->suggestions() );
			self::assertStringContainsString( 'trailing comma', strtolower( implode( ' ', $e->suggestions() ) ) );
		}
	}

	public function testMarkdownFenceProducesAHint(): void {
		try {
			$this->parser->parse( "```json\n{\"a\":1}\n```" );
			self::fail( 'Expected a ParseException.' );
		} catch ( ParseException $e ) {
			self::assertStringContainsString( 'code fence', strtolower( implode( ' ', $e->suggestions() ) ) );
		}
	}

	public function testCommentsProduceAHint(): void {
		try {
			$this->parser->parse( "{\n// a comment\n\"a\":1}" );
			self::fail( 'Expected a ParseException.' );
		} catch ( ParseException $e ) {
			self::assertStringContainsString( 'comments', strtolower( implode( ' ', $e->suggestions() ) ) );
		}
	}
}
