<?php
/**
 * Router for PHP's built-in server (php -S). Replaces the Apache/nginx rewrite rules
 * WordPress normally relies on. The built-in server ignores .htaccess, so this file is
 * also the ONLY thing that keeps the SQLite database and the debug log from being
 * downloadable. Local development only: use a real web server when hosting.
 *
 * Usage (see scripts/start.sh): php -S "$HOST:$PORT" -t wordpress scripts/router.php
 *
 * @package PayrailsWooExample
 */

// Test tooling: the e2e harness starts the server with PAYRAILS_WOO_TESTING=1 for the
// "API unreachable" test, which points PAYRAILS_API_URL at a never-resolving
// *.payrails.invalid host. The plugin only accepts such hosts when this constant is set.
if ( '1' === getenv( 'PAYRAILS_WOO_TESTING' ) && ! defined( 'PAYRAILS_WOO_TESTING' ) ) {
	define( 'PAYRAILS_WOO_TESTING', true );
}

$payrails_router_root = rtrim( $_SERVER['DOCUMENT_ROOT'], '/' );

// Path = REQUEST_URI up to "?". Not parse_url(): it reads "//wp-config.php" as a
// scheme-relative URL (host "wp-config.php", no path), which would hide the file from
// the rules below. Repeated slashes are collapsed for the same reason.
$payrails_router_path = rawurldecode( explode( '?', $_SERVER['REQUEST_URI'] ?? '/', 2 )[0] );
if ( '' === $payrails_router_path || '/' !== $payrails_router_path[0] ) {
	$payrails_router_path = '/' . $payrails_router_path;
}
$payrails_router_path = preg_replace( '#/+#', '/', $payrails_router_path );

/**
 * Sends a plain 403 and stops routing.
 */
function payrails_router_forbidden(): bool {
	http_response_code( 403 );
	header( 'Content-Type: text/plain' );
	echo "Forbidden\n";
	return true;
}

// 0. /.well-known/: existing static files only (e.g. Apple Pay domain association,
//    ACME). Never PHP, never a directory, never a sensitive extension.
if ( preg_match( '#^/\.well-known/[A-Za-z0-9._/-]+$#', $payrails_router_path ) && ! preg_match( '#/\.\.?(/|$)#', $payrails_router_path ) ) {
	$payrails_router_file = $payrails_router_root . $payrails_router_path;
	if ( is_file( $payrails_router_file ) && ! preg_match( '#\.(log|sqlite|sqlite3|db|env|pem|key|crt|p12|php|phtml|phar)$#i', $payrails_router_path ) ) {
		return false;
	}
	http_response_code( 404 );
	return true;
}

// 1. Deny anything sensitive: the SQLite DB dir, dotfiles (.ht.sqlite, .git), logs,
//    wp-config, the payrails-woo PHP internals (only its assets/ are public), XML-RPC.
if (
	preg_match( '#/wp-content/database(/|$)#i', $payrails_router_path )
	|| preg_match( '#/\.#', $payrails_router_path )
	|| preg_match( '#\.(log|sqlite|sqlite3|db|env|pem|key|crt|p12)$#i', $payrails_router_path )
	|| preg_match( '#/wp-config(-sample)?\.php$#i', $payrails_router_path )
	|| preg_match( '#/wp-content/plugins/payrails-woo/(?!assets/)#i', $payrails_router_path )
	|| preg_match( '#^/xmlrpc\.php$#i', $payrails_router_path )
) {
	return payrails_router_forbidden();
}

$payrails_router_file = $payrails_router_root . $payrails_router_path;

// 2. Directory request: serve its index.php (e.g. /wp-admin/ -> /wp-admin/index.php).
if ( '/' !== $payrails_router_path && is_dir( $payrails_router_file ) ) {
	if ( '/' !== substr( $payrails_router_path, -1 ) ) {
		header( 'Location: ' . $payrails_router_path . '/', true, 301 );
		return true;
	}
	if ( is_file( $payrails_router_file . 'index.php' ) ) {
		$_SERVER['SCRIPT_NAME']     = $payrails_router_path . 'index.php';
		$_SERVER['SCRIPT_FILENAME'] = $payrails_router_file . 'index.php';
		$_SERVER['PHP_SELF']        = $payrails_router_path . 'index.php';
		chdir( $payrails_router_file );
		require $payrails_router_file . 'index.php';
		return true;
	}
}

// 3. A real file (a static asset, or a .php entry point such as wp-login.php): let the
//    built-in server handle it. Symlinks are followed, so the linked plugin and theme work.
if ( '/' !== $payrails_router_path && is_file( $payrails_router_file ) ) {
	return false;
}

// 4. Everything else is a pretty permalink: WordPress front controller.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $payrails_router_root . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir( $payrails_router_root );
require $payrails_router_root . '/index.php';
return true;
