<?php

namespace Importer;

use Importer\Importers\ImporterAbstract;
use Importer\Importers\Post;
use Importer\Importers\Term;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use WP_Error;
use ZipArchive;
use Exception;

class Import {

	const OPTION_PREFIX = 'wp_import_';

	/**
	 * An ordered list of type of objects
	 */
	const IMPORT_ORDER = array(
		'terms',
		'posts',
	);

	const SCHEMA_MAP = array(
		'users' => 'https://wordpress.org/schema/user.json',
		'posts' => 'https://wordpress.org/schema/post.json',
		'meta'  => 'https://wordpress.org/schema/meta.json',
		'terms' => 'https://wordpress.org/schema/term.json',
	);

	const LOG_WARNING = 'warning';
	const LOG_ERROR   = 'error';

	/**
	 * @var ZipArchive
	 */
	protected $wxz;

	/**
	 * An index of JSON files to be indexed
	 *
	 * @var array
	 */
	protected $wxz_json_index = array();

	/**
	 * @var
	 */
	protected $start_time;

	/**
	 * Number of actions that have been processed during the current run.
	 *
	 * @var int
	 */
	protected $processed_counter = 0;


	/**
	 * @var array
	 */
	protected $last_object;

	/**
	 * Import constructor.
	 *
	 * @param $wxz_path
	 *
	 * @throws Exception
	 */
	public function __construct( $wxz_path ) {

		$this->start_time = microtime( true );

		$this->wxz = new ZipArchive();
		$output    = $this->wxz->open( $wxz_path );

		if ( true !== $output ) {
			throw new Exception( sprintf( __( 'Error opening wxz-file. Error code %s', 'wordpress-importer' ), $output ) );
		}

		$this->validator = new Validator();
		$resolver        = $this->validator->resolver();
		$resolver->registerPrefix( 'https://wordpress.org/schema/', __DIR__ . '/../vendor/wordpress/wxz-tools/schema' );
	}

	public function run() {

		// Index the WXZ.
		$this->index_wxz();

		while ( $this->can_proceed() ) {
			$stage = get_option( self::OPTION_PREFIX . 'stage', 'start' );

			switch ( $stage ) {

				case 'start':
					$this->pre_import();
					break;

				case 'objects':
					$this->import_objects();
					break;

				case 'finalize':
					break 2;

			}
		}

		echo 'PEAK USAGE ' . memory_get_peak_usage() . "\n";

	}

	protected function pre_import() {

		$this->log( 'pre_import', 'Starting the pre-import stage.' );
		// Reset the object state
		delete_option( self::OPTION_PREFIX . 'last_object' );

		update_option( self::OPTION_PREFIX . 'stage', 'objects' );
	}

	/**
	 * Import JSON objects from the WXZ
	 */
	protected function import_objects() {

		while ( $this->can_proceed() ) {

			// Get the next object to handle
			$object = $this->get_next_object();

			// If we ran out of objects, let the import proceed to the next stage because we are done here.
			if ( null === $object ) {
				update_option( self::OPTION_PREFIX . 'stage', 'finalize' );
				break;
			}

			$zip_index = $this->wxz_json_index[ $object['type'] ][ $object['index'] ];
			$file_path = $this->wxz->getNameIndex( $zip_index );
			$data      = json_decode( $this->wxz->getFromIndex( $zip_index ), false );

			if ( ! $this->is_valid_object( $data, $object['type'], $file_path ) ) {
				continue;
			}

			// todo import
			$this->import_object( $object['type'], $data );

			$this->set_last_object( $object );

			// Update the count of imported items
			$this->processed_counter++;
		}

	}

	/**
	 * @param $data
	 * @param $type
	 * @param $file_path
	 *
	 * @return bool
	 */
	protected function is_valid_object( $data, $type, $file_path ) {
		if ( null === $data ) {
			// Cannot parse the json. Log an error.

			$this->log( 'invalid-json', sprintf( 'Invalid JSON in %s', $file_path ) );
			return false;
		}

		if ( ! $this->data_is_valid( $data, $type ) ) {
			$this->log(
				'schema-violation',
				sprintf( 'The data in %s can not be validated against the schema.', $file_path )
			);

			return false;
		}

		return true;
	}

	protected function import_object( $type, $data ) {

		$class_map = array(
			'terms' => Term::class,
			'posts' => Post::class,
		);

		/** @var ImporterAbstract $importer */
		$importer = new $class_map[ $type ]( $this );

		$importer->run( $data );
	}

	/**
	 * Validate data from JSON against a JSON schema.
	 *
	 * @param $data
	 * @param $type
	 *
	 * @return bool
	 */
	protected function data_is_valid( $data, $type ) {
		$schema = self::SCHEMA_MAP[ $type ];

		try {
			$result = $this->validator->validate( $data, $schema );
		} catch ( Exception $e ) {
			$this->log( 'validation-exception', $e->getMessage(), self::LOG_ERROR );
			echo 'Exception: ' . $e->getMessage();
			return null;
		}

		// todo perhaps log schema errors?
		return $result->isValid();
	}

	/**
	 * Get the type and index of the next JSON object to process.
	 *
	 * @return array|WP_Error|null
	 */
	protected function get_next_object() {

		$current = $this->get_last_object();
		$current['index']++;

		if ( isset( $this->wxz_json_index[ $current['type'] ][ $current['index'] ] ) ) {
			return $current;
		}

		$next_type = $this->get_next_type( $current['type'] );

		if ( ! $next_type ) {
			return null;
		}

		return array(
			'type'  => $next_type,
			'index' => 0,
		);
	}

	protected function get_last_object() {

		if ( null === $this->last_object ) {

			$default = array(
				'type'  => self::IMPORT_ORDER[0],
				'index' => -1,
			);

			$this->last_object = get_option( self::OPTION_PREFIX . 'last_object', $default );
		}

		return $this->last_object;
	}

	/**
	 * Get the next type of json object to import.
	 *
	 * A type refers to the type of JSON object (e.g. term, post, user, etc.).
	 *
	 * @param string $type The current type.
	 *
	 * @return false|int
	 */
	protected function get_next_type( $type ) {

		$current_type = array_search( $type, self::IMPORT_ORDER, true );
		$next_type    = $current_type + 1;

		if ( ! array_key_exists( $next_type, self::IMPORT_ORDER ) ) {
			return false;
		}

		$next_type = self::IMPORT_ORDER[ $next_type ];

		// If the type has any indexed objects, return them, otherwise proceed to the next type.
		return empty( $this->wxz_json_index[ $next_type ] )
			? $this->get_next_type( $next_type )
			: $next_type;
	}

	/**
	 * Update the last JSON object that has been processed.
	 *
	 * @param $last_object
	 */
	protected function set_last_object( $last_object ) {
		$this->last_object = $last_object;
		update_option( self::OPTION_PREFIX . 'last_object', $last_object );
	}

	/**
	 * Checks memory and execution time limits.
	 *
	 * @todo Implement memory limits here.
	 */
	protected function can_proceed() {

		return ! $this->exceeding_time_limit();
	}

	/**
	 * @return bool
	 * @todo Improve this
	 */
	protected function exceeding_time_limit() {

		$time_limit = 10; //(int) ini_get( 'max_execution_time' );

		$total_time = microtime( true ) - $this->start_time;

		if ( 0 === $time_limit || 0 === $this->processed_counter ) {
			return false;
		}

		$time_per_action = $total_time / $this->processed_counter;

		// There needs to be time to run 4 more actions based on average time.
		return $time_per_action * ( $this->processed_counter + 4 ) > $time_limit;

	}

	protected function log( $code, $message, $level = self::LOG_WARNING ) {
		printf( "[%s][%s] %s\n", $level, $code, $message );
	}

	/**
	 * Indexes the WXZ json files.
	 */
	protected function index_wxz() {
		$index = array();
		for ( $i = 0; $i < $this->wxz->numFiles; $i++ ) {

			$file = $this->wxz->getNameIndex( $i );
			$type = dirname( $file );

			if ( ! in_array( $type, self::IMPORT_ORDER, true ) || pathinfo( $file, PATHINFO_EXTENSION ) !== 'json' ) {
				continue;
			}

			$index[ $type ][] = $i;
		}

		$this->wxz_json_index = $index;
	}
}
