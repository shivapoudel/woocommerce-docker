<?php
/**
 * HyperDB configuration file.
 *
 * This file should be installed at ABSPATH . 'db-config.php'.
 */

/**
 * Save queries to the log for debugging.
 *
 * @see WP_DB::log_query()
 */
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	$wpdb->save_queries = true;
}

/**
 * This is the primary database connection (Master).
 * Configured with write=1, read=2 so reads prefer replica but can fallback to master.
 *
 * @see WP_DB::add_database()
 */
$wpdb->add_database( array(
	'host'     => defined( 'DB_HOST' ) ? DB_HOST : 'mysql:3306',
	'user'     => defined( 'DB_USER' ) ? DB_USER : 'wordpress',
	'password' => defined( 'DB_PASSWORD' ) ? DB_PASSWORD : 'password',
	'name'     => defined( 'DB_NAME' ) ? DB_NAME : 'wordpress_db',
	'write'    => 1, // Master handles all writes.
	'read'     => 2, // Lower priority for reads (prefer replica).
) );

/**
 * Read replica database connection.
 *
 * HyperDB automatically routes reads here, but will route to master after writes
 * to maintain read-after-write consistency.
 *
 * IMPORTANT: HyperDB tracks written tables and routes subsequent reads to the
 * same server (master) to ensure consistency. This means:
 * - GET_LOCK() on master will work correctly
 * - Reads after writes will go to master (not replica)
 * - Only "cold" reads (no recent writes) will use replica
 */
$read_host = defined( 'WORDPRESS_DB_READ_HOST' ) ? WORDPRESS_DB_READ_HOST : ( defined( 'DB_READ_HOST' ) ? DB_READ_HOST : null );
if ( ! empty( $read_host ) && $read_host !== DB_HOST ) {
	$wpdb->add_database( array(
		'host'     => $read_host,
		'user'     => defined( 'DB_USER' ) ? DB_USER : 'wordpress',
		'password' => defined( 'DB_PASSWORD' ) ? DB_PASSWORD : 'password',
		'name'     => defined( 'DB_NAME' ) ? DB_NAME : 'wordpress_db',
		'write'    => 0, // Replica is read-only.
		'read'     => 1, // Higher priority for reads (preferred).
		'dataset'  => 'global',
		'timeout'  => 2.25,
	) );
}
