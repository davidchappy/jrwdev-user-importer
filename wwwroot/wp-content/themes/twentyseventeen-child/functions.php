<?php
function twentyseventeen_child_styles() {

    $parent_style = 'twentyseventeen-style';

    wp_enqueue_style( $parent_style, get_template_directory_uri() . '/style.css' );
    wp_enqueue_style( 'child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( $parent_style )
    );

}
add_action( 'wp_enqueue_scripts', 'twentyseventeen_child_styles' );
?>

<?php
function twentyseventeen_child_javascripts() {
    wp_enqueue_script('html5', get_template_directory_uri() . '/assets/js/html5.js', array(), '3.7.3');
    wp_enqueue_script('twentyseventeen-skip-link-focus-fix', get_template_directory_uri() . '/assets/js/skip-link-focus-fix.js',  array(), '1.0', true );
    wp_enqueue_script('html5', get_template_directory_uri() . '/assets/js/html5.js', false);
    wp_enqueue_script('html5', get_template_directory_uri() . '/assets/js/html5.js', false);

    if ( has_nav_menu( 'top' ) ) {
        wp_enqueue_script( 'twentyseventeen-navigation', get_template_directory_uri() . '/assets/js/navigation.js', array(), '1.0', true );
        $twentyseventeen_l10n['expand']         = __( 'Expand child menu', 'twentyseventeen' );
        $twentyseventeen_l10n['collapse']       = __( 'Collapse child menu', 'twentyseventeen' );
        $twentyseventeen_l10n['icon']           = twentyseventeen_get_svg( array( 'icon' => 'angle-down', 'fallback' => true ) );
    }

    wp_enqueue_script( 'twentyseventeen-global', get_template_directory_uri() . '/assets/js/global.js', array( 'jquery' ), '1.0', true );

    wp_enqueue_script( 'jquery-scrollto', get_template_directory_uri() . '/assets/js/jquery.scrollTo.js', array( 'jquery' ), '2.1.2', true );

    wp_localize_script( 'twentyseventeen-skip-link-focus-fix', 'twentyseventeenScreenReaderText', $twentyseventeen_l10n );

    if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
        wp_enqueue_script( 'comment-reply' );
    }
}
add_action('wp_enqueue_scripts', 'twentyseventeen_child_javascripts');
?>