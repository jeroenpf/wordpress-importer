<?php

namespace Importer;

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
		'users',
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

	protected $benchmarks = array();

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
			$data      = json_decode( $this->wxz->getFromIndex( $zip_index ) );

			if ( ! $this->is_valid_object( $data, $object['type'], $file_path ) ) {
				continue;
			}

			// todo import
			$this->import_object( $object['type'], $data );

			$this->set_import_object_complete( $object['index'] );
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

		$type = self::IMPORT_ORDER[ $type ];

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
		$type = self::IMPORT_ORDER[ $type ];
		usleep(10000);
		//$this->log( 'importing', printf( 'Importing %s %d', $type, $data->id ) );
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

		$option_name = self::OPTION_PREFIX . 'import_state';

		// We require a lock because only one process should be manipulating the state at a time.
		$locked = $this->obtain_state_lock();

		if ( $locked instanceof WP_Error ) {
			return $locked;
		}

		$state = get_option(
			$option_name,
			array(
				'type'      => 0,
				'index'     => -1,
				'importing' => array(),
			)
		);

		// Remove importing objects that have timed-out.
		$state = $this->filter_timeout_objects( $state );

		$has_next_object_for_type    = isset( $this->wxz_json_index[ $state['type'] ][ $state['index'] + 1 ] );
		$type_has_unfinished_imports = ! empty( $state['importing'] );

		// If there are no more objects to process for the current type but there are still imports running,
		// we need to wait until all objects have completed before moving on to the next type.
		if ( ! $has_next_object_for_type && $type_has_unfinished_imports ) {
			$this->release_state_lock();
			sleep( 2 );
			return $this->get_next_object();
		}

		// If there are no more objects for the current type, proceed to the next type.
		if ( ! $has_next_object_for_type ) {
			$state['type']  = $this->get_next_type( $state['type'] );
			$state['index'] = 0;
		} else {
			$state['index']++;
		}

		// Add the object to the list of importing objects.
		$state['importing'][] = array( $state['index'], time() );

		// If the type is false, there are no more objects to process.
		if ( false === $state['type'] ) {
			$state = null;
			delete_option( $option_name );
		} else {
			update_option( $option_name, $state );
		}

		$this->release_state_lock();

		return $state;
	}

	/**
	 * Filter out objects that are importing but have passed the timeout threshold.
	 *
	 * @param $state
	 *
	 * @return array
	 */
	protected function filter_timeout_objects( $state ) {

		$max_duration = 30;

		$state['importing'] = array_filter(
			$state['importing'],
			function( $object ) use ( $max_duration ) {

				$timed_out = time() - $object[1] > $max_duration;

				if ( $timed_out ) {
					$this->log( 'object_import_timeout', __( 'Importing object has timed out', 'wordpress-importer' ) );
				}

				return $timed_out;
			}
		);

		return $state;

	}

	/**
	 * Get the next type of json object to import.
	 *
	 * A type refers to the type of JSON object (e.g. term, post, user, etc.).
	 *
	 * @param int $current_type The current type.
	 *
	 * @return false|int
	 */
	protected function get_next_type( $current_type ) {

		$next_type = $current_type + 1;

		if ( ! array_key_exists( $next_type, self::IMPORT_ORDER ) ) {
			return false;
		}

		// If the type has any indexed objects, return them, otherwise proceed to the next type.
		return empty( $this->wxz_json_index[ $next_type ] )
			? $this->get_next_type( $next_type )
			: $next_type;
	}

	protected function set_import_object_complete( $index ) {
		$option_name = self::OPTION_PREFIX . 'import_state';

		// We require a lock because only one process should be manipulating the state at a time.
		$locked = $this->obtain_state_lock();

		if ( $locked instanceof WP_Error ) {
			return $locked;
		}

		$state = get_option( $option_name );

		$state['importing'] = array_filter(
			$state['importing'],
			static function( $object ) use ( $index ) {
				return $object[0] !== $index;
			}
		);

		$this->release_state_lock();
	}

	/**
	 * Obtain a lock for manipulating the state.
	 *
	 * @return bool|WP_Error
	 */
	protected function obtain_state_lock() {

		$lock_name = self::OPTION_PREFIX . 'state_lock';
		$tries     = 0;
		$lock      = false;

		while ( ! $lock && $tries < 10 ) {

			$tries++;

			$this->benchmark_start('insert_lock');
			$lock = $this->insert_state_lock( $lock_name );
			$this->benchmark_end_print('insert_lock');

			// Check for an expired lock
			if ( ! $lock && get_option( $lock_name ) > time() - 5 ) {
				update_option( $lock_name, time() );
				$lock = true;
			}

			usleep( 100 );
		}

		if ( ! $lock ) {
			return new \WP_Error( 'no_state_lock', __( 'Can not obtain state lock' ) );
		}

		return true;

	}

	/**
	 * Try to insert the state lock option.
	 *
	 * @param $lock_name
	 *
	 * @return bool|int
	 */
	protected function insert_state_lock( $lock_name ) {
		global $wpdb;

		$lock_insert = $wpdb->prepare(
			"
			INSERT IGNORE INTO `$wpdb->options` ( `option_name`, `option_value`, `autoload` )
			VALUES (%s, %s, 'no') /* LOCK */",
			$lock_name,
			time()
		);

		return $wpdb->query( $lock_insert );
	}


	/**
	 * Release the lock.
	 */
	protected function release_state_lock() {
		$lock_name = self::OPTION_PREFIX . 'state_lock';

		delete_option( $lock_name );
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

			$index[ array_search( $type, self::IMPORT_ORDER ) ][] = $i;
		}

		$this->wxz_json_index = $index;
	}

	protected function benchmark_start( $name ) {
		$this->benchmarks[ $name ] = microtime( true );
	}

	protected function benchmark_end_print( $name ) {
		$total_time = microtime( true ) - $this->benchmarks[ $name ];
		printf( "-----> Benchmark %s took %s seconds - memory: %s\n", $name, $total_time, memory_get_peak_usage() );
	}


}
