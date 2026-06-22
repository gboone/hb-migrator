<?php
/**
 * MediaExporter removed in v2. Media is now sideloaded via Destination\MediaImporter.
 * Placeholder to avoid missing-file errors.
 */

class Test_Media_Exporter extends WP_UnitTestCase {

	public function test_placeholder(): void {
		$this->assertTrue( true, 'MediaExporter removed in v2; see Destination\MediaImporter.' );
	}
}
