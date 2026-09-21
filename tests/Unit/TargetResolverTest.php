<?php
declare( strict_types = 1 );

namespace ACFJP\Tests\Unit;

use ACFJP\Exceptions\ErrorCodes;
use ACFJP\Exceptions\ResolutionException;
use ACFJP\Model\FieldGroup;
use ACFJP\Model\RawTree;
use ACFJP\Model\Target;
use ACFJP\Resolve\TargetResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACFJP\Resolve\TargetResolver
 */
final class TargetResolverTest extends TestCase {

	private TargetResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new TargetResolver();
	}

	private function tree(): RawTree {
		return new RawTree(
			FieldGroup::fromAcfArray(
				array(
					'key'    => 'group_co',
					'title'  => 'Company',
					'fields' => array(
						array(
							'key'        => 'field_contact',
							'name'       => 'contact',
							'label'      => 'Contact',
							'type'       => 'group',
							'sub_fields' => array(
								array( 'key' => 'field_email', 'name' => 'email', 'label' => 'Email', 'type' => 'email' ),
								array(
									'key'        => 'field_social',
									'name'       => 'social',
									'label'      => 'Social',
									'type'       => 'group',
									'sub_fields' => array(
										array( 'key' => 'field_li', 'name' => 'linkedin', 'label' => 'LinkedIn', 'type' => 'url' ),
									),
								),
							),
						),
						array( 'key' => 'field_dup1', 'name' => 'note_a', 'label' => 'Note', 'type' => 'text' ),
						array( 'key' => 'field_dup2', 'name' => 'note_b', 'label' => 'Note', 'type' => 'text' ),
						array(
							'key'   => 'field_clone',
							'name'  => 'shared',
							'label' => 'Shared',
							'type'  => 'clone',
						),
					),
				)
			)
		);
	}

	public function testResolvesByKey(): void {
		self::assertSame( 'field_email', $this->resolver->resolveField( $this->tree(), 'field_email' )->key );
	}

	public function testResolvesByNameWithinScope(): void {
		self::assertSame(
			'field_email',
			$this->resolver->resolveField( $this->tree(), 'email', 'field_contact' )->key
		);
	}

	public function testResolvesByLabelWhenUnique(): void {
		self::assertSame(
			'field_contact',
			$this->resolver->resolveField( $this->tree(), 'Contact' )->key
		);
	}

	public function testResolvesADottedPath(): void {
		self::assertSame(
			'field_li',
			$this->resolver->resolveField( $this->tree(), 'contact.social.linkedin' )->key
		);
	}

	public function testResolvesALocusFromAPath(): void {
		$locus = $this->resolver->resolveLocus(
			$this->tree(),
			new Target( 'Company', array( 'Contact', 'Social' ) )
		);

		self::assertSame( 'field_social', $locus->parentKey() );
		self::assertSame( 'Company › Contact › Social', $locus->display );
	}

	/**
	 * Guessing between two equally good matches is how a tool writes to the wrong
	 * field. It must refuse and show both.
	 */
	public function testAmbiguousLabelIsRefusedWithCandidates(): void {
		try {
			$this->resolver->resolveField( $this->tree(), 'Note' );
			self::fail( 'Expected a ResolutionException.' );
		} catch ( ResolutionException $e ) {
			self::assertSame( ErrorCodes::AMBIGUOUS_TARGET, $e->errorCode() );
			self::assertCount( 2, $e->suggestions() );
		}
	}

	public function testMissingFieldSuggestsWhatIsAvailable(): void {
		try {
			$this->resolver->resolveField( $this->tree(), 'emial', 'field_contact' );
			self::fail( 'Expected a ResolutionException.' );
		} catch ( ResolutionException $e ) {
			self::assertSame( ErrorCodes::FIELD_NOT_FOUND, $e->errorCode() );
			self::assertContains( 'email', $e->suggestions() );
		}
	}

	public function testDescendingIntoANonContainerIsRefused(): void {
		try {
			$this->resolver->resolveLocus( $this->tree(), new Target( 'Company', array( 'Contact', 'Email' ) ) );
			self::fail( 'Expected a ResolutionException.' );
		} catch ( ResolutionException $e ) {
			self::assertSame( ErrorCodes::NOT_A_CONTAINER, $e->errorCode() );
		}
	}

	public function testFindFieldReturnsNullInsteadOfThrowing(): void {
		self::assertNull( $this->resolver->findField( $this->tree(), 'nope' ) );
	}
}
