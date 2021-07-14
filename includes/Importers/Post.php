<?php

namespace Importer\Importers;

class Post extends ImporterAbstract {

	public function run( $data ) {
		printf( "Importing post with title '%s'\n", $data->title );
	}
}
