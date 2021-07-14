<?php

namespace Importer\Importers;

use Importer\Import;

abstract class ImporterAbstract {

	/**
	 * Instance of the import.
	 *
	 * @var Import
	 */
	protected $import;

	public function __construct( Import $import ) {
		$this->import = $import;
	}

	abstract public function run( $data );

}
