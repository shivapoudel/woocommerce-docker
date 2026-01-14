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
 * This is the primary database connection.
 *
 * @see WP_DB::add_database()
 */
$wpdb->add_database( array(
	'host'     => defined( 'DB_HOST' ) ? DB_HOST : 'mysql:3306',
	'user'     => defined( 'DB_USER' ) ? DB_USER : 'wordpress',
	'password' => defined( 'DB_PASSWORD' ) ? DB_PASSWORD : 'password',
	'name'     => defined( 'DB_NAME' ) ? DB_NAME : 'wordpress_db',
	'write'    => 1,
	'read'     => 1,
) );
