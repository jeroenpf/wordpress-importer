<?php

use Importer\Import;

class Tests_Importer extends WP_UnitTestCase {

	public function test_sample() {

		$this->assertTrue( true );
	}

	/**
	 * @throws Exception
	 */
	public function test_can_create_import_instance_with_valid_zip() {

		$this->expectNotToPerformAssertions();
		$import = new Import( DIR_TESTDATA_WORDPRESS_IMPORTER . '/wordpress-export.zip' );
	}

	public function test_can_index_zip_file() {

		$import = new Import( DIR_TESTDATA_WORDPRESS_IMPORTER . '/wordpress-export.zip' );

		$import->run();


	}

}
