<?php
/**
 * Tests for Cli\MigrationKeyCommand — currently limited to resolve_migration_id(), the
 * one piece of this command's logic with no WP_CLI dependency. The get()/update()/
 * delete() methods themselves call \WP_CLI::error()/success()/confirm() directly,
 * which requires a real WP-CLI runtime (WP_CLI::error() halts execution via exit())
 * that this PHPUnit environment does not provide — those methods are exercised via a
 * live `wp hbm migration source-key` smoke test instead, not by this suite.
 */

use HBMigrator\Cli\MigrationKeyCommand;

class Test_Migration_Key_Command extends WP_UnitTestCase {

	public function test_resolve_migration_id_accepts_positive_integer_string(): void {
		$this->assertSame( 42, MigrationKeyCommand::resolve_migration_id( '42' ) );
	}

	public function test_resolve_migration_id_accepts_single_digit(): void {
		$this->assertSame( 1, MigrationKeyCommand::resolve_migration_id( '1' ) );
	}

	public function test_resolve_migration_id_rejects_zero(): void {
		$this->assertNull( MigrationKeyCommand::resolve_migration_id( '0' ) );
	}

	public function test_resolve_migration_id_rejects_negative_number(): void {
		$this->assertNull( MigrationKeyCommand::resolve_migration_id( '-5' ) );
	}

	public function test_resolve_migration_id_rejects_non_numeric_string(): void {
		$this->assertNull( MigrationKeyCommand::resolve_migration_id( 'abc' ) );
	}

	public function test_resolve_migration_id_rejects_decimal(): void {
		$this->assertNull( MigrationKeyCommand::resolve_migration_id( '4.2' ) );
	}

	public function test_resolve_migration_id_rejects_empty_string(): void {
		$this->assertNull( MigrationKeyCommand::resolve_migration_id( '' ) );
	}

	public function test_resolve_migration_id_rejects_leading_plus(): void {
		$this->assertNull( MigrationKeyCommand::resolve_migration_id( '+42' ) );
	}

	public function test_resolve_migration_id_rejects_whitespace(): void {
		$this->assertNull( MigrationKeyCommand::resolve_migration_id( ' 42' ) );
		$this->assertNull( MigrationKeyCommand::resolve_migration_id( '42 ' ) );
	}
}
