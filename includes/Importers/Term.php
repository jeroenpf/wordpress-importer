<?php

namespace Importer\Importers;

class Term extends ImporterAbstract {

	public function run( $data ) {
		printf( "Importing term with name '%s'\n", $data->name );
	}

}
