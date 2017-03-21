<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wp_wads_user_importer' );

/** MySQL database username */
define( 'DB_USER', 'root' );

/** MySQL database password */
define( 'DB_PASSWORD', 'root' );

/** MySQL hostname */
define( 'DB_HOST', 'localhost' );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         'wEX VHp1=n~~atP.KrA)v%m^p!JN6+?B09_E}Z|:J2+afjt)m/ugNXz~N^w=wR9(');
define('SECURE_AUTH_KEY',  'IP[Uv8muBAn~$_M+}}0PmtkT a_xK$4fa K-r&!_j?4/!YUxf/*R}=,{K;q>DaY!');
define('LOGGED_IN_KEY',    'G(w)IDgG34kx>CdsN0)|r8Si+01gMJ> TAKeP[]jGbkf95h(~n9Yq!kK2+7ISoe[');
define('NONCE_KEY',        'z.)opBJOFO}8xHEvv7JDi|2-Trh4iLk1h4d|!JreE!U:><@+bS%`> V=&V6-V|+G');
define('AUTH_SALT',        'qc;jC(92APg-wl)tU%aQ;na3qa7:|J|jJY-yM +fk^fIz#]s.-Ss`<!=uS^L4rd|');
define('SECURE_AUTH_SALT', 'E0OKy+ )Y>TtdWEZ:B+ /%Tcq-1b+!~VT_(_Nf|{w|;B#D7O+K,(|V[qm!DNC---');
define('LOGGED_IN_SALT',   '/AB&<-VT}qh4(AA^s*h~pE6:KrO@$+S^&T,|-YY#q1u{Z(2nW~~aqO3QmN`1!@% ');
define('NONCE_SALT',       ':-<dlf%B/8U{S>@Sz0Rl^M ufh*|kf[-cIP.{Sc#}g)8z8joAZ$~&V;Pg*q%Vn+%');


/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);
define('WP_MEMORY_LIMIT', '256M');


/* That's all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) )
	define( 'ABSPATH', dirname( __FILE__ ) . '/' );

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
