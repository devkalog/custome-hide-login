<?php
/**
 * Plugin Name: Custom Hide Login
 * Description: Μετακινεί τη φόρμα σύνδεσης σε ένα ιδιωτικό slug και επιστρέφει 404 στο wp-login.php και στο wp-admin για μη συνδεδεμένους επισκέπτες.
 * Version:     2.0.2
 * Author:      Aboutnet
 * License:     GPL-2.0-or-later
 * Text Domain: custom-hide-login
 */

if ( ! defined( 'ABSPATH' ) ) 
	{
	exit;
}

// Το αρχείο μπορεί να βρίσκεται ταυτόχρονα σε mu-plugins και σε plugins.
if ( defined( 'CUSTOM_LOGIN_LOADED' ) ) {
	return;
}
define( 'CUSTOM_LOGIN_LOADED', true );


/* -------------------------------------------------------------------------
 * Ρυθμίσεις
 * ---------------------------------------------------------------------- */

if ( ! isset( $GLOBALS['cl_locked_settings'] ) ) {
	$GLOBALS['cl_locked_settings'] = array();
}
$GLOBALS['cl_locked_settings']['slug']                 = defined( 'CUSTOM_LOGIN_SLUG' );
$GLOBALS['cl_locked_settings']['trust_plugin_actions'] = defined( 'CUSTOM_LOGIN_TRUST_PLUGIN_ACTIONS' );
$GLOBALS['cl_locked_settings']['plugin_actions']       = defined( 'CUSTOM_LOGIN_PLUGIN_ACTIONS' );
$GLOBALS['cl_locked_settings']['block_xmlrpc']         = defined( 'CUSTOM_LOGIN_BLOCK_XMLRPC' );

if ( ! defined( 'CUSTOM_LOGIN_SLUG' ) ) {
	// Τα options περνούν από φίλτρα τρίτων plugins και μπορεί να επιστρέψουν
	// array ή false. Χωρίς cast, το preg_quote()/explode() παρακάτω θα ήταν
	// TypeError σε PHP 8.
	$cl_option_slug = strtolower( trim( (string) get_option( 'cl_login_slug', '' ) ) );
	$cl_option_slug = (string) preg_replace( '/[^a-z0-9_-]/', '', $cl_option_slug );
	$cl_option_slug = trim( $cl_option_slug, '-_' );
	define( 'CUSTOM_LOGIN_SLUG', ( '' !== $cl_option_slug ) ? $cl_option_slug : 'ab-admin' );
	unset( $cl_option_slug );
}

if ( ! defined( 'CUSTOM_LOGIN_ALLOWED_ACTIONS' ) ) {
	// Τα actions του πυρήνα που πρέπει να παραμείνουν προσβάσιμα στο πραγματικό
	// wp-login.php. Το `rp`/`resetpass` ειδικά *πρέπει* να μείνει εκεί: ο core
	// δένει το cookie wp-resetpass-* στο path του τρέχοντος request
	// (wp-login.php:934-939), οπότε μετακίνηση θα έσπαγε την επαναφορά κωδικού.
	define(
		'CUSTOM_LOGIN_ALLOWED_ACTIONS',
		implode( ',', array(
			'postpass',
			'logout',
			'lostpassword',
			'retrievepassword',
			'rp',
			'resetpass',
			'register',
			'confirmaction',
			'confirm_admin_email',
			'exit_recovery_mode',
		) )
	);
}

if ( ! defined( 'CUSTOM_LOGIN_TRUST_PLUGIN_ACTIONS' ) ) {
	$cl_option_trust = (string) get_option( 'cl_trust_plugin_actions', '' );
	define( 'CUSTOM_LOGIN_TRUST_PLUGIN_ACTIONS', ( '' === $cl_option_trust ) ? true : ( '1' === $cl_option_trust ) );
	unset( $cl_option_trust );
}
if ( ! defined( 'CUSTOM_LOGIN_PLUGIN_ACTIONS' ) ) {
	define( 'CUSTOM_LOGIN_PLUGIN_ACTIONS', (string) get_option( 'cl_plugin_actions', '' ) );
}
if ( ! defined( 'CUSTOM_LOGIN_BLOCK_XMLRPC' ) ) {
	define( 'CUSTOM_LOGIN_BLOCK_XMLRPC', '1' === (string) get_option( 'cl_block_xmlrpc', '0' ) );
}


/* -------------------------------------------------------------------------
 * Ανάλυση διαδρομής
 * ---------------------------------------------------------------------- */

/**
 * Φέρνει ένα request path στη μορφή που θα δει ο web server.
 *
 * Οι servers αποκωδικοποιούν το percent-encoding πριν αντιστοιχίσουν ένα URL σε
 * αρχείο, οπότε το /wp%2Dlogin.php εκτελεί κανονικά το wp-login.php· αν κρίναμε
 * το ακατέργαστο path θα ξεγλιστρούσε. Επίσης καταρρέουμε διπλές καθέτους και
 * επιλύουμε `.`/`..` ώστε ούτε να μας ξεφεύγει ούτε να μπλοκάρουμε κατά λάθος.
 *
 * @param string $raw_path
 * @return string Path χωρίς καθέτους στην αρχή/τέλος.
 */
function cl_normalize_request_path( $raw_path ) {
	$path = str_replace( array( "\0", '\\' ), array( '', '/' ), (string) $raw_path );
	$path = str_replace( "\0", '', rawurldecode( $path ) );
	$path = str_replace( '\\', '/', $path );

	$out = array();
	foreach ( explode( '/', $path ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}
		if ( '..' === $segment ) {
			array_pop( $out );
			continue;
		}
		$out[] = $segment;
	}

	return implode( '/', $out );
}

/**
 * Το siteurl χωρίς να περάσει από τα δικά μας φίλτρα.
 *
 * Το get_option('siteurl') τιμά ήδη το WP_SITEURL μέσω του option_siteurl
 * φίλτρου (_config_wp_siteurl), οπότε δεν χρειάζεται site_url().
 *
 * @param string $scheme
 * @return string
 */
function cl_site_url_base( $scheme = 'login' ) {
	return set_url_scheme( (string) get_option( 'siteurl' ), $scheme );
}

/**
 * Το path τμήμα της εγκατάστασης, π.χ. 'wp' όταν το WordPress ζει στο /wp/.
 *
 * @return string
 */
function cl_install_base_path() {
	static $base = null;
	if ( null === $base ) {
		$base = cl_normalize_request_path( (string) parse_url( cl_site_url_base(), PHP_URL_PATH ) );
	}
	return $base;
}

/**
 * Το request path σε σχέση με τη ρίζα της εγκατάστασης.
 *
 * @param string $path Κανονικοποιημένο path.
 * @return string
 */
function cl_relative_request_path( $path ) {
	$base = cl_install_base_path();
	if ( '' === $base ) {
		return $path;
	}
	if ( $path === $base ) {
		return '';
	}
	if ( 0 === strpos( $path . '/', $base . '/' ) ) {
		return (string) substr( $path, strlen( $base ) + 1 );
	}
	return $path;
}

/**
 * @param string $path Ακατέργαστο path από το REQUEST_URI.
 * @param string $slug
 * @return string 'skip' | 'real_login' | 'wp_admin' | 'custom_slug' | 'xmlrpc' | 'other'
 */
function cl_classify_path( $path, $slug ) {
	$path = cl_normalize_request_path( $path );
	if ( '' === $path ) {
		return 'other';
	}

	$segments = array_map( 'strtolower', explode( '/', $path ) );

	// Δικοί τους front controllers, σε χρήση και χωρίς σύνδεση.
	if ( in_array( 'admin-ajax.php', $segments, true )
		|| in_array( 'admin-post.php', $segments, true )
		|| in_array( 'wp-cron.php', $segments, true )
		|| in_array( 'wp-json', $segments, true ) ) {
		return 'skip';
	}

	// Το repair.php σχεδιάστηκε ρητά για χρήση χωρίς σύνδεση.
	if ( in_array( 'repair.php', $segments, true ) && defined( 'WP_ALLOW_REPAIR' ) && WP_ALLOW_REPAIR ) {
		return 'skip';
	}

	// Οποιοδήποτε segment, όχι μόνο το τελευταίο: το /wp-login.php/x εκτελεί
	// κανονικά το wp-login.php σε κάθε server που περνάει PATH_INFO (προεπιλογή
	// σε Apache και στο τυπικό nginx config), και ο πυρήνας το βλέπει έτσι
	// κιόλας — wp-includes/vars.php:52 ταιριάζει '#([^/]+\.php)([?/].*?)?$#i'.
	if ( in_array( 'wp-login.php', $segments, true ) ) {
		return 'real_login';
	}
	if ( in_array( 'xmlrpc.php', $segments, true ) ) {
		return 'xmlrpc';
	}

	$relative = strtolower( cl_relative_request_path( $path ) );

	if ( 'wp-admin' === $relative || 0 === strpos( $relative, 'wp-admin/' ) ) {
		return 'wp_admin';
	}

	// Αγκυρωμένο στη ρίζα της εγκατάστασης. Χωρίς αυτό, κάθε URL που απλώς
	// *τελειώνει* στο slug (π.χ. /2024/03/ab-admin/) θα σέρβιρε τη φόρμα login.
	if ( '' !== (string) $slug && $relative === strtolower( (string) $slug ) ) {
		return 'custom_slug';
	}

	return 'other';
}

/**
 * Η κατηγορία του τρέχοντος request, υπολογισμένη μία φορά.
 *
 * @return string
 */
function cl_current_category() {
	static $category = null;
	if ( null === $category ) {
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$category = cl_classify_path( (string) parse_url( $uri, PHP_URL_PATH ), CUSTOM_LOGIN_SLUG );
	}
	return $category;
}

/**
 * Το τρέχον login action, ασφαλές απέναντι σε ?action[]=x.
 *
 * @return string
 */
function cl_current_action() {
	if ( ! isset( $_REQUEST['action'] ) ) {
		return '';
	}
	return sanitize_key( wp_unslash( $_REQUEST['action'] ) );
}

/**
 * Περιβάλλοντα όπου δεν έχει νόημα — και είναι επικίνδυνο — να παρεμβαίνουμε.
 *
 * @return bool
 */
function cl_should_run() {
	if ( function_exists( 'wp_installing' ) && wp_installing() ) {
		return false;
	}
	if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
		return false;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return false;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return false;
	}
	return true;
}


/* -------------------------------------------------------------------------
 * Περιβάλλον εκτέλεσης του custom slug
 * ---------------------------------------------------------------------- */

// -PHP_INT_MAX αντί για PHP_INT_MIN: το δεύτερο υπάρχει μόνο από PHP 7.0.
add_action( 'plugins_loaded', 'cl_normalize_custom_login_context', -PHP_INT_MAX );

function cl_normalize_custom_login_context() {
	if ( ! cl_should_run() || 'custom_slug' !== cl_current_category() ) {
		return;
	}

	$base = cl_install_base_path();

	$_SERVER['SCRIPT_NAME']     = '/' . ltrim( ( '' === $base ? '' : $base . '/' ) . CUSTOM_LOGIN_SLUG, '/' );
	$_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-login.php';
	$GLOBALS['pagenow']         = 'wp-login.php';
}


/* -------------------------------------------------------------------------
 * Ξαναγράψιμο των URL σύνδεσης
 * ---------------------------------------------------------------------- */

/**
 * Ξαναχτίζει ένα URL από τα μέρη του parse_url(). Δεν υπάρχει http_build_url()
 * χωρίς το pecl_http.
 *
 * @param array $parts
 * @return string
 */
function cl_build_url( $parts ) {
	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
	$host   = isset( $parts['host'] ) ? $parts['host'] : '';
	if ( '' === $scheme && '' !== $host ) {
		$scheme = '//';
	}

	$user = isset( $parts['user'] ) ? $parts['user'] : '';
	$pass = isset( $parts['pass'] ) ? ':' . $parts['pass'] : '';
	$auth = ( '' !== $user || '' !== $pass ) ? $user . $pass . '@' : '';

	$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
	$path     = isset( $parts['path'] ) ? $parts['path'] : '';
	$query    = ( isset( $parts['query'] ) && '' !== $parts['query'] ) ? '?' . $parts['query'] : '';
	$fragment = ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

	return $scheme . $auth . $host . $port . $path . $query . $fragment;
}

/**
 * Το URL βάσης της κρυφής σελίδας σύνδεσης.
 *
 * @param string $scheme
 * @return string
 */
function cl_login_base_url( $scheme = 'login' ) {
	return trailingslashit( trailingslashit( cl_site_url_base( $scheme ) ) . CUSTOM_LOGIN_SLUG );
}

/**
 * Αντικαθιστά το wp-login.php με το slug, διατηρώντας scheme, host και query.
 *
 * Δουλεύει και σε multisite, όπου το network_site_url() μπορεί να δείχνει σε
 * άλλο host ή σε διαδρομή subsite.
 *
 * @param string $url
 * @return string
 */
function cl_rewrite_login_url( $url ) {
	$url   = (string) $url;
	$parts = parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
		return $url;
	}
	if ( 'wp-login.php' !== strtolower( basename( $parts['path'] ) ) ) {
		return $url;
	}

	$parts['path'] = substr( $parts['path'], 0, - strlen( 'wp-login.php' ) ) . CUSTOM_LOGIN_SLUG . '/';

	return cl_build_url( $parts );
}

add_filter( 'login_url', 'cl_rewrite_login_url', 999, 1 );
add_filter( 'logout_url', 'cl_rewrite_login_url', 999, 1 );
add_filter( 'lostpassword_url', 'cl_rewrite_login_url', 999, 1 );
add_filter( 'register_url', 'cl_rewrite_login_url', 999, 1 );

add_filter( 'site_url', 'cl_fix_login_post_url', 999, 3 );
add_filter( 'network_site_url', 'cl_fix_login_post_url', 999, 3 );

/**
 * Στέλνει τα POST των φορμών σύνδεσης στο slug.
 *
 * Ο πυρήνας γράφει τα action attributes κατευθείαν στο wp-login.php
 * (wp-login.php:670, 891, 1027, 1161, 1514) — τα φίλτρα login_url/κ.λπ. δεν τα
 * πιάνουν, γι' αυτό χρειάζεται και αυτό εδώ.
 *
 * @param string $url
 * @param string $path
 * @param string $scheme
 * @return string
 */
function cl_fix_login_post_url( $url, $path, $scheme ) {
	if ( 'login_post' !== $scheme || ! is_string( $path ) ) {
		return $url;
	}

	$file = ltrim( $path, '/' );
	$mark = strpos( $file, '?' );
	if ( false !== $mark ) {
		$file = substr( $file, 0, $mark );
	}
	if ( 'wp-login.php' !== $file ) {
		return $url;
	}

	// Το βήμα resetpass μένει στο πραγματικό αρχείο: το cookie wp-resetpass-*
	// δένεται στο path του request που το δημιούργησε (wp-login.php:934-939),
	// οπότε αν μετακινούσαμε μόνο τη φόρμα, το cookie δεν θα στελνόταν.
	if ( false !== strpos( $path, 'action=resetpass' ) ) {
		return $url;
	}

	return cl_rewrite_login_url( $url );
}

add_filter( 'wp_redirect', 'cl_fix_redirect_to_login', 999, 1 );

/**
 * Κρατάει τα ενδιάμεσα redirects μέσα στο κρυφό URL.
 *
 * Παράδειγμα: με κλειστές εγγραφές ο πυρήνας κάνει
 * wp_redirect( site_url( 'wp-login.php?registration=disabled' ) ) — wp-login.php:1109 —
 * που χωρίς αυτό θα κατέληγε σε 404.
 *
 * Εφαρμόζεται μόνο όταν το τρέχον request είναι ήδη το κρυφό URL· διαφορετικά
 * ένα redirect από το πραγματικό wp-login.php θα αποκάλυπτε το slug.
 *
 * @param string $location
 * @return string
 */
function cl_fix_redirect_to_login( $location ) {
	if ( 'custom_slug' !== cl_current_category() ) {
		return $location;
	}

	$parts = parse_url( (string) $location );
	if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
		return $location;
	}
	if ( 'wp-login.php' !== strtolower( basename( $parts['path'] ) ) ) {
		return $location;
	}

	// Ό,τι κουβαλάει `action` δουλεύει ήδη στο πραγματικό αρχείο, και το
	// resetpass εξαρτάται από το να μείνει εκεί.
	$args = array();
	if ( isset( $parts['query'] ) ) {
		parse_str( $parts['query'], $args );
	}
	if ( isset( $args['action'] ) && '' !== $args['action'] ) {
		return $location;
	}

	return cl_rewrite_login_url( $location );
}

add_filter( 'recovery_mode_begin_url', 'cl_fix_recovery_mode_begin_url', 999, 3 );

/**
 * Το link του recovery mode πρέπει να δείχνει στο πραγματικό wp-login.php.
 *
 * Ο WP_Recovery_Mode_Link_Service::handle_begin_link() απαιτεί
 * $GLOBALS['pagenow'] === 'wp-login.php', και το pagenow το θέτει το
 * wp-includes/vars.php από το PHP_SELF πολύ πριν τρέξει οτιδήποτε δικό μας.
 *
 * @param string $url
 * @param string $token
 * @param string $key
 * @return string
 */
function cl_fix_recovery_mode_begin_url( $url, $token, $key ) {
	return add_query_arg(
		array(
			'action'   => 'enter_recovery_mode',
			'rm_token' => $token,
			'rm_key'   => $key,
		),
		trailingslashit( cl_site_url_base( 'login' ) ) . 'wp-login.php'
	);
}


/* -------------------------------------------------------------------------
 * Παρεμβολή στο request
 * ---------------------------------------------------------------------- */

/**
 * @return bool
 */
function cl_is_allowed_real_login_request() {
	$action = cl_current_action();

	// Ο πυρήνας παρακάμπτει το action όταν υπάρχει ?checkemail (wp-login.php:483)
	// και εμφανίζει μόνο μήνυμα — ποτέ φόρμα σύνδεσης.
	if ( isset( $_GET['checkemail'] ) ) {
		return true;
	}

	// Η ίδια η σύνδεση δεν επιτρέπεται ποτέ στο πραγματικό URL. Το POST της
	// φόρμας δεν στέλνει action, οπότε το κενό action μπλοκάρεται κι αυτό.
	if ( '' === $action || 'login' === $action ) {
		return false;
	}

	// Το enter_recovery_mode το χειρίζεται ο πυρήνας στο wp-settings.php:589,
	// πριν φτάσουμε εμείς. Αν φτάσει εδώ σημαίνει ότι ο fatal error handler
	// είναι απενεργοποιημένος, οπότε δεν κάνει τίποτα.
	if ( 'enter_recovery_mode' === $action || 'entered_recovery_mode' === $action ) {
		return false;
	}

	if ( in_array( $action, explode( ',', CUSTOM_LOGIN_ALLOWED_ACTIONS ), true ) ) {
		return true;
	}

	if ( '' !== CUSTOM_LOGIN_PLUGIN_ACTIONS
		&& in_array( $action, explode( ',', CUSTOM_LOGIN_PLUGIN_ACTIONS ), true ) ) {
		return true;
	}

	if ( CUSTOM_LOGIN_TRUST_PLUGIN_ACTIONS && has_action( 'login_form_' . $action ) ) {
		return true;
	}

	return false;
}

/**
 * Εκτελείται ήδη το πραγματικό wp-login.php ως front controller;
 *
 * @return bool
 */
function cl_already_running_real_wp_login() {
	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : '';
	return ( 'wp-login.php' === strtolower( $script ) );
}

add_action( 'wp_loaded', 'cl_intercept_login_requests', PHP_INT_MAX );

function cl_intercept_login_requests() {
	if ( ! cl_should_run() ) {
		return;
	}

	switch ( cl_current_category() ) {
		case 'xmlrpc':
			if ( CUSTOM_LOGIN_BLOCK_XMLRPC ) {
				cl_block_request();
			}
			return;

		case 'real_login':
			cl_handle_real_login_request();
			return;

		case 'wp_admin':
			if ( ! is_user_logged_in() ) {
				cl_block_request();
			}
			return;

		case 'custom_slug':
			cl_render_wp_login( true );
			return;
	}
}

function cl_handle_real_login_request() {
	// Ο πυρήνας ανακατευθύνει εδώ από το handle_begin_link() χρησιμοποιώντας
	// wp_login_url(), το οποίο είναι ακόμη αφιλτράριστο όταν το αρχείο τρέχει ως
	// κανονικό plugin: το recovery mode αρχικοποιείται στο wp-settings.php:589
	// και τα plugins φορτώνουν στη 593. Χωρίς αυτό, το link από το email
	// fatal error κατέληγε σε 404. Ο έλεγχος wp_is_recovery_mode() σημαίνει ότι
	// το cookie έχει ήδη επικυρωθεί, οπότε δεν διαρρέει το slug σε τρίτους.
	if ( 'entered_recovery_mode' === cl_current_action()
		&& function_exists( 'wp_is_recovery_mode' )
		&& wp_is_recovery_mode() ) {
		wp_safe_redirect( add_query_arg( 'action', 'entered_recovery_mode', cl_login_base_url() ) );
		exit;
	}

	if ( ! cl_is_allowed_real_login_request() ) {
		cl_block_request();
		return;
	}

	// Το wp-login.php τρέχει ήδη ως front controller· άφησέ το να τελειώσει σε
	// global scope, που είναι και το σωστό για τις μεταβλητές του.
	if ( cl_already_running_real_wp_login() ) {
		return;
	}

	cl_render_wp_login( false );
}

/**
 * Φορτώνει το wp-login.php.
 *
 * Το wp-login.php είναι γραμμένο για global scope: οι login_header()
 * (wp-login.php:42) και login_footer() (:326) διαβάζουν $action, $interim_login
 * και $error μέσω `global`, ενώ το ίδιο το αρχείο τα αναθέτει σε script scope
 * (:476, :568). Αν το κάναμε require μέσα σε συνάρτηση χωρίς τις παρακάτω
 * δηλώσεις, θα γίνονταν δύο διαφορετικές μεταβλητές — με αποτέλεσμα κενό
 * body class `login-action-`, null στο φίλτρο login_body_class, χαλασμένο
 * interim-login modal και PHP 8 warnings σε κάθε φόρτωση.
 *
 * Η λίστα προέκυψε σαρώνοντας όλες τις αναθέσεις σε global scope του αρχείου.
 *
 * @param bool $rewrite_form_action Αν θα ξαναγραφτεί το action της φόρμας.
 * @return void
 */
function cl_render_wp_login( $rewrite_form_action ) {
	if ( defined( 'CL_WP_LOGIN_LOADED' ) ) {
		return;
	}
	define( 'CL_WP_LOGIN_LOADED', true );

	global $accessibility_text, $action, $admin_email, $admin_email_check_interval,
		$admin_email_help_url, $admin_email_lifespan, $aria_describedby, $change_link,
		$customize_login, $default_actions, $error, $errors, $expire, $has_errors,
		$hasher, $html_link, $http_post, $interim_login, $key, $login_link_separator,
		$login_script, $lostpassword_redirect, $message, $pagenow, $query,
		$query_component, $reauth, $redirect_to, $registration_redirect,
		$registration_url, $rememberme, $rememberme_help_text, $remind_interval,
		$remind_me_link, $request_id, $requested_redirect_to, $result, $rp_cookie,
		$rp_login, $rp_path, $secure, $secure_cookie, $url, $user, $user_email,
		$user_login, $user_name, $value;

	if ( ! $rewrite_form_action ) {
		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	$level = ob_get_level();
	ob_start();

	require_once ABSPATH . 'wp-login.php';

	// Αν κάποιο plugin έκλεισε το buffer στη διάρκεια του rendering, το
	// ob_get_clean() θα επέστρεφε false και η σελίδα θα έβγαινε κενή.
	$html = ( ob_get_level() > $level ) ? (string) ob_get_clean() : '';

	echo cl_force_login_form_action( $html );
	exit;
}

/**
 * Δίχτυ ασφαλείας για το action attribute της φόρμας σύνδεσης.
 *
 * Κανονικά το αναλαμβάνει το φίλτρο site_url/login_post· αυτό εδώ πιάνει τις
 * περιπτώσεις όπου κάποιο plugin ξαναχτίζει τη φόρμα μόνο του.
 *
 * @param string $html
 * @return string
 */
function cl_force_login_form_action( $html ) {
	if ( '' === $html || false === stripos( $html, 'loginform' ) ) {
		return $html;
	}

	$target = cl_login_base_url( 'login_post' );
	$target = function_exists( 'esc_url' ) ? esc_url( $target ) : htmlspecialchars( $target, ENT_QUOTES );
	$target = str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $target );

	// Το lookahead εντοπίζει το id οπουδήποτε μέσα στο tag, ώστε να μην
	// εξαρτόμαστε από τη σειρά των attributes.
	$fixed = preg_replace(
		'#(<form\b(?=[^>]*\bid=([\'"])loginform\2)[^>]*\baction=([\'"]))[^\'"]*\3#i',
		'${1}' . $target . '${3}',
		$html,
		1
	);

	return ( null !== $fixed ) ? $fixed : $html;
}

/**
 * Απαντά 404 χωρίς να αποκαλύψει ότι πρόκειται για WordPress.
 *
 * Το wp_die() παράγει WordPress-branded markup, που λέει στον scanner ότι το URL
 * υπάρχει αλλά κρύβεται.
 *
 * @return void
 */
function cl_block_request() {
	if ( ! headers_sent() ) {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	$html = "<!DOCTYPE html>\n<html><head><title>404 Not Found</title></head>\n"
		. "<body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>\n";

	/**
	 * Φιλτράρει το σώμα της απάντησης 404.
	 *
	 * @param string $html
	 */
	echo apply_filters( 'cl_not_found_html', $html );

	exit;
}


/* -------------------------------------------------------------------------
 * Οθόνη ρυθμίσεων
 * ---------------------------------------------------------------------- */

if ( is_admin() ) {
	add_action( 'admin_menu', 'cl_admin_add_settings_page' );
	add_action( 'admin_init', 'cl_admin_handle_settings_save' );
}

function cl_admin_add_settings_page() {
	add_options_page(
		'Custom Hide Login',
		'Custom Hide Login',
		'manage_options',
		'custom-hide-login',
		'cl_admin_render_settings_page'
	);
}

function cl_admin_reserved_slugs() {
	return array(
		'wp-admin',
		'wp-login.php',
		'wp-content',
		'wp-includes',
		'wp-json',
		'wp-cron.php',
		'wp-config.php',
		'wp-load.php',
		'wp-settings.php',
		'wp-blog-header.php',
		'wp-signup.php',
		'wp-activate.php',
		'wp-trackback.php',
		'wp-comments-post.php',
		'wp-links-opml.php',
		'wp-mail.php',
		'xmlrpc.php',
		'index.php',
		'readme.html',
		'license.txt',
		'feed',
		'comments',
		'embed',
		'robots.txt',
		'favicon.ico',
		'sitemap.xml',
		'admin',
		'administrator',
		'login',
	);
}

function cl_admin_sanitize_slug( $raw ) {
	if ( ! is_scalar( $raw ) ) {
		return '';
	}

	$slug = function_exists( 'sanitize_title' )
		? sanitize_title( (string) $raw )
		: strtolower( (string) preg_replace( '/[^A-Za-z0-9\-]/', '-', trim( (string) $raw ) ) );

	return trim( (string) $slug, '-/' );
}

function cl_admin_sanitize_actions_list( $raw ) {
	if ( ! is_scalar( $raw ) ) {
		return '';
	}

	$clean = array();
	foreach ( explode( ',', (string) $raw ) as $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			continue;
		}
		$key = function_exists( 'sanitize_key' )
			? sanitize_key( $part )
			: strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $part ) );
		if ( '' !== $key && ! in_array( $key, $clean, true ) ) {
			$clean[] = $key;
		}
	}

	return implode( ',', $clean );
}

function cl_admin_slug_collides_with_content( $slug ) {
	if ( ! function_exists( 'get_page_by_path' ) ) {
		return false;
	}

	$post_types = get_post_types( array( 'public' => true ) );
	if ( empty( $post_types ) ) {
		$post_types = array( 'page', 'post' );
	}

	return ( null !== get_page_by_path( $slug, OBJECT, $post_types ) );
}

function cl_admin_handle_settings_save() {
	if ( ! isset( $_POST['cl_save_settings'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'cl_save_settings', 'cl_settings_nonce' );

	$locked        = isset( $GLOBALS['cl_locked_settings'] ) ? $GLOBALS['cl_locked_settings'] : array();
	$redirect_args = array( 'page' => 'custom-hide-login' );

	// Η φόρμα επιβεβαίωσης σύγκρουσης στέλνει μόνο το slug. Χωρίς αυτόν τον
	// δείκτη, το checkbox και η λίστα actions θα έλειπαν από το POST και μια
	// άνευ όρων αποθήκευση θα τα μηδένιζε σιωπηλά — που μπορεί να κλειδώσει
	// έξω όποιον βασίζεται σε 2FA/SSO login actions.
	$is_full_form = isset( $_POST['cl_form_full'] ) && '1' === $_POST['cl_form_full'];

	if ( empty( $locked['slug'] ) && isset( $_POST['cl_login_slug'] ) ) {
		$new_slug  = cl_admin_sanitize_slug( wp_unslash( $_POST['cl_login_slug'] ) );
		$confirmed = isset( $_POST['cl_confirm_collision'] ) && '1' === $_POST['cl_confirm_collision'];

		if ( '' === $new_slug ) {
			$redirect_args['cl_error'] = 'empty_slug';
		} elseif ( in_array( $new_slug, cl_admin_reserved_slugs(), true ) ) {
			$redirect_args['cl_error'] = 'reserved_slug';
		} elseif ( cl_admin_slug_collides_with_content( $new_slug ) && ! $confirmed ) {
			$redirect_args['cl_error']        = 'collision';
			$redirect_args['cl_pending_slug'] = $new_slug;
		} else {
			update_option( 'cl_login_slug', $new_slug );
			$redirect_args['cl_saved'] = '1';
		}
	}

	if ( $is_full_form ) {
		if ( empty( $locked['trust_plugin_actions'] ) ) {
			update_option( 'cl_trust_plugin_actions', isset( $_POST['cl_trust_plugin_actions'] ) ? '1' : '0' );
			$redirect_args['cl_saved'] = '1';
		}
		if ( empty( $locked['plugin_actions'] ) ) {
			$raw_actions = isset( $_POST['cl_plugin_actions'] ) ? wp_unslash( $_POST['cl_plugin_actions'] ) : '';
			update_option( 'cl_plugin_actions', cl_admin_sanitize_actions_list( $raw_actions ) );
			$redirect_args['cl_saved'] = '1';
		}
		if ( empty( $locked['block_xmlrpc'] ) ) {
			update_option( 'cl_block_xmlrpc', isset( $_POST['cl_block_xmlrpc'] ) ? '1' : '0' );
			$redirect_args['cl_saved'] = '1';
		}
	}

	// Αν υπάρχει σφάλμα, μην αφήσεις και μήνυμα επιτυχίας.
	if ( isset( $redirect_args['cl_error'] ) ) {
		unset( $redirect_args['cl_saved'] );
	}

	wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'options-general.php' ) ) );
	exit;
}

function cl_admin_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$locked = isset( $GLOBALS['cl_locked_settings'] ) ? $GLOBALS['cl_locked_settings'] : array();

	$current_slug           = CUSTOM_LOGIN_SLUG;
	$current_trust          = CUSTOM_LOGIN_TRUST_PLUGIN_ACTIONS;
	$current_plugin_actions = CUSTOM_LOGIN_PLUGIN_ACTIONS;
	$current_block_xmlrpc   = CUSTOM_LOGIN_BLOCK_XMLRPC;

	$error        = isset( $_GET['cl_error'] ) ? sanitize_key( $_GET['cl_error'] ) : '';
	$pending_slug = isset( $_GET['cl_pending_slug'] ) ? cl_admin_sanitize_slug( wp_unslash( $_GET['cl_pending_slug'] ) ) : '';
	$saved        = isset( $_GET['cl_saved'] );
	$action_url   = admin_url( 'options-general.php' );

	// Με βάση το siteurl, όχι το home_url(): σε εγκατάσταση σε υποφάκελο
	// (siteurl = example.com/wp, home = example.com) το home_url() θα έδειχνε
	// URL που δεν υπάρχει.
	$login_url = cl_login_base_url( 'login' );
	?>
	<div class="wrap">
		<h1>Custom Hide Login</h1>

		<?php if ( $saved && '' === $error ) : ?>
			<div class="notice notice-success is-dismissible"><p>Οι ρυθμίσεις αποθηκεύτηκαν.</p></div>
		<?php endif; ?>

		<?php if ( 'empty_slug' === $error ) : ?>
			<div class="notice notice-error"><p>Το slug δεν μπορεί να είναι κενό.</p></div>
		<?php elseif ( 'reserved_slug' === $error ) : ?>
			<div class="notice notice-error"><p>Αυτό το slug είναι δεσμευμένο από τον πυρήνα του WordPress — διάλεξε άλλο.</p></div>
		<?php elseif ( 'collision' === $error && '' !== $pending_slug ) : ?>
			<div class="notice notice-warning">
				<p>
					Το slug <code><?php echo esc_html( $pending_slug ); ?></code> συμπίπτει με το URL υπάρχουσας
					σελίδας ή άρθρου — αν προχωρήσεις, η φόρμα σύνδεσης θα «κλέψει» αυτό το URL από το περιεχόμενο.
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<?php wp_nonce_field( 'cl_save_settings', 'cl_settings_nonce' ); ?>
					<input type="hidden" name="cl_login_slug" value="<?php echo esc_attr( $pending_slug ); ?>" />
					<input type="hidden" name="cl_confirm_collision" value="1" />
					<?php submit_button( 'Αποθήκευση ούτως ή άλλως', 'delete', 'cl_save_settings', false ); ?>
				</form>
			</div>
		<?php endif; ?>

		<p>Τρέχον URL σύνδεσης: <code><?php echo esc_html( $login_url ); ?></code></p>

		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( 'cl_save_settings', 'cl_settings_nonce' ); ?>
			<input type="hidden" name="cl_form_full" value="1" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cl_login_slug">Slug σύνδεσης</label></th>
					<td>
						<?php if ( ! empty( $locked['slug'] ) ) : ?>
							<input type="text" value="<?php echo esc_attr( $current_slug ); ?>" class="regular-text" disabled="disabled" />
							<p class="description">
								Ορισμένο μέσω <code>define( 'CUSTOM_LOGIN_SLUG', ... )</code> (π.χ. στο wp-config.php) —
								έχει προτεραιότητα έναντι αυτής της οθόνης, το πεδίο είναι κλειδωμένο.
							</p>
						<?php else : ?>
							<input type="text" name="cl_login_slug" id="cl_login_slug" value="<?php echo esc_attr( $current_slug ); ?>" class="regular-text" />
							<p class="description">Π.χ. <code>ab-admin</code>. Η αλλαγή ισχύει αμέσως μετά την αποθήκευση.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Εμπιστοσύνη σε plugin actions</th>
					<td>
						<?php if ( ! empty( $locked['trust_plugin_actions'] ) ) : ?>
							<p><?php echo $current_trust ? 'Ενεργή' : 'Ανενεργή'; ?> (ορισμένο μέσω wp-config.php — κλειδωμένο).</p>
						<?php else : ?>
							<label>
								<input type="checkbox" name="cl_trust_plugin_actions" value="1" <?php checked( $current_trust ); ?> />
								Να επιτρέπονται αυτόματα άγνωστα login actions άλλων εγκατεστημένων plugins (2FA/SSO/passwordless κ.λπ.)
							</label>
							<p class="description">
								Προτεινόμενο ενεργό σε agency περιβάλλον με πολλά, διαφορετικά sites. Απενεργοποίησέ το
								μόνο αν έχεις απογράψει ρητά όλα τα actions που χρειάζεσαι παρακάτω.
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cl_plugin_actions">Ρητά επιτρεπόμενα plugin actions</label></th>
					<td>
						<?php if ( ! empty( $locked['plugin_actions'] ) ) : ?>
							<input type="text" value="<?php echo esc_attr( $current_plugin_actions ); ?>" class="regular-text" disabled="disabled" />
							<p class="description">Ορισμένο μέσω wp-config.php — κλειδωμένο.</p>
						<?php else : ?>
							<input type="text" name="cl_plugin_actions" id="cl_plugin_actions" value="<?php echo esc_attr( $current_plugin_actions ); ?>" class="regular-text" />
							<p class="description">
								Comma-separated, π.χ. <code>my_2fa_action,my_sso_action</code>. Ισχύει πάντα, ανεξάρτητα
								από το παραπάνω toggle.
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Αποκλεισμός XML-RPC</th>
					<td>
						<?php if ( ! empty( $locked['block_xmlrpc'] ) ) : ?>
							<p><?php echo $current_block_xmlrpc ? 'Ενεργός' : 'Ανενεργός'; ?> (ορισμένο μέσω wp-config.php — κλειδωμένο).</p>
						<?php else : ?>
							<label>
								<input type="checkbox" name="cl_block_xmlrpc" value="1" <?php checked( $current_block_xmlrpc ); ?> />
								Επιστροφή 404 στο <code>xmlrpc.php</code>
							</label>
							<p class="description">
								Το <code>xmlrpc.php</code> δέχεται συνθηματικά και επιτρέπει μαζική δοκιμή μέσω
								<code>system.multicall</code>, οπότε παρακάμπτει το κρύψιμο της σελίδας σύνδεσης.
								Προεπιλογή ανενεργό: το χρειάζονται το Jetpack, η εφαρμογή WordPress και μερικά
								remote publishing εργαλεία.
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<p class="description">
				Οι βασικές ενέργειες του πυρήνα (<code>lostpassword</code>, <code>register</code>, <code>logout</code>,
				<code>postpass</code> κ.λπ.) δεν εμφανίζονται εδώ σκόπιμα — προκύπτουν από ανάλυση του πηγαίου κώδικα
				του πυρήνα και δεν πρέπει να αλλάζουν από UI. Το <code>rp</code>/<code>resetpass</code> ειδικά πρέπει
				να παραμείνει στο πραγματικό <code>wp-login.php</code>, γιατί ο πυρήνας δένει το cookie επαναφοράς
				κωδικού στο path εκείνου του URL.
			</p>
			<p class="description">
				Αν χρησιμοποιείς plugin full-page cache (WP Rocket, LiteSpeed, W3TC, Varnish), εξαίρεσε το
				<code><?php echo esc_html( $login_url ); ?></code> από το cache — το <code>advanced-cache.php</code>
				φορτώνει πριν από αυτόν τον κώδικα και μπορεί να σερβίρει αποθηκευμένη σελίδα σύνδεσης.
			</p>
			<?php submit_button( 'Αποθήκευση ρυθμίσεων', 'primary', 'cl_save_settings' ); ?>
		</form>
	</div>
	<?php
}