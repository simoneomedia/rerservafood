<?php
/**
 * Plugin Name: Reactivate
 * Description: Demonstration plugin that requires the bundled Gutenpride dependency.
 * Version: 1.0.0
 * Author: Example
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ensure Gutenpride dependency is present during activation.
 */
function reactivate_activate() {
    $dependency = plugin_dir_path( __FILE__ ) . '../gutenpride/gutenpride.php';

    if ( file_exists( $dependency ) ) {
        require $dependency;
    } else {
        wp_die(
            sprintf(
                esc_html__( 'Reactivate requires the Gutenpride dependency. Please make sure the file %s exists.', 'reactivate' ),
                '<code>' . esc_html( $dependency ) . '</code>'
            ),
            esc_html__( 'Missing Dependency', 'reactivate' ),
            [ 'back_link' => true ]
        );
    }
}
register_activation_hook( __FILE__, 'reactivate_activate' );

// Attempt to load the dependency on regular execution as well to prevent fatal errors.
$gutenpride = plugin_dir_path( __FILE__ ) . '../gutenpride/gutenpride.php';
if ( file_exists( $gutenpride ) ) {
    require_once $gutenpride;
} else {
    add_action(
        'admin_notices',
        function () use ( $gutenpride ) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html( sprintf( 'Reactivate requires Gutenpride. Missing file: %s', $gutenpride ) )
            );
        }
    );
    return;
}
