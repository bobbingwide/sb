<?php
/**
 * Server-side rendering of the `core/template-part` block.
 *
 * @package WordPress
 */

/**
 * Renders the `core/template-part` block on the server.
 *
 * @param array $attributes The block attributes.
 *
 * @return string The render.
 */
function sb_render_block_core_template_part( $attributes, $content, $block ) {
    static $seen_ids = array();
    // The $block object is often too large to trace because of the pointer to the registry.
    bw_trace2( $attributes, 'attributes', false , BW_TRACE_VERBOSE );
    //bw_backtrace();

    $template_part_id = null;
    $content          = null;
    $area             = WP_TEMPLATE_PART_AREA_UNCATEGORIZED;

    if ( ! empty( $attributes['postId'] ) && get_post_status( $attributes['postId'] ) ) {
        $template_part_id = $attributes['postId'];
        // If we have a post ID and the post exists, which means this template part
        // is user-customized, render the corresponding post content.
        $content = get_post( $attributes['postId'] )->post_content;
    } elseif (
        isset( $attributes['slug'] ) &&
        isset( $attributes['theme'] ) &&
        wp_get_theme()->get_stylesheet() === $attributes['theme']
    ) {
        $template_part_id    = $attributes['theme'] . '//' . $attributes['slug'];
        $template_part_query = new WP_Query(
            array(
                'post_type'      => 'wp_template_part',
                'post_status'    => 'publish',
                'post_name__in'  => array( $attributes['slug'] ),
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'wp_theme',
                        'field'    => 'slug',
                        'terms'    => $attributes['theme'],
                    ),
                ),
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            )
        );
        $template_part_post  = $template_part_query->have_posts() ? $template_part_query->next_post() : null;
        if ( $template_part_post ) {
            // A published post might already exist if this template part was customized elsewhere
            // or if it's part of a customized template.
            $content    = $template_part_post->post_content;
            $area_terms = get_the_terms( $template_part_post, 'wp_template_part_area' );
            if ( ! is_wp_error( $area_terms ) && false !== $area_terms ) {
                $area = $area_terms[0]->name;
            }
        } else {
            // Else, if the template part was provided by the active theme,
            // render the corresponding file content.
            $template_part_file_path = get_stylesheet_directory() . '/parts/' . $attributes['slug'] . '.html';
            if ( 0 === validate_file( $attributes['slug'] ) && file_exists( $template_part_file_path ) ) {
                $content = file_get_contents( $template_part_file_path );

            }
        }
    }

    // bw_trace2( $content, "template part content", false, BW_TRACE_VERBOSE );

    if ( is_null( $content ) && is_user_logged_in() ) {
        //print_r( $attributes );
        return sprintf(
        /* translators: %s: Template part slug. */
            __( 'Template part is unavailable: %s' ),
            $attributes['slug']
        );
    }

    if ( isset( $seen_ids[ $template_part_id ] ) ) {
        if ( ! is_admin() ) {
            trigger_error(
                sprintf(
                // translators: %s are the block attributes.
                    __( 'Could not render Template Part block with the attributes: <code>%s</code>. Block cannot be rendered inside itself.' ),
                    wp_json_encode( $attributes )
                ),
                E_USER_WARNING
            );
        }

        // WP_DEBUG_DISPLAY must only be honored when WP_DEBUG. This precedent
        // is set in `wp_debug_mode()`.
        $is_debug = defined( 'WP_DEBUG' ) && WP_DEBUG &&
            defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
        return $is_debug ?
            // translators: Visible only in the front end, this warning takes the place of a faulty block.
            __( '[block rendering halted]' ) :
            '';
    }

    // Run through the actions that are typically taken on the_content.
    $seen_ids[ $template_part_id ] = true;
    $content                       = do_blocks( $content );
    unset( $seen_ids[ $template_part_id ] );
    $content = wptexturize( $content );
    $content = convert_smilies( $content );
    //$content = wpautop( $content );
    $content = shortcode_unautop( $content );
    if ( function_exists( 'wp_filter_content_tags' ) ) {
        $content = wp_filter_content_tags( $content );
    } else {
        $content = wp_make_content_images_responsive( $content );
    }
    $content = do_shortcode( $content );

    if ( empty( $attributes['tagName'] ) ) {
        $area_tags = array(
            WP_TEMPLATE_PART_AREA_HEADER        => 'header',
            WP_TEMPLATE_PART_AREA_FOOTER        => 'footer',
            WP_TEMPLATE_PART_AREA_UNCATEGORIZED => 'div',
        );
        $html_tag  = null !== $area && isset( $area_tags[ $area ] ) ? $area_tags[ $area ] : $area_tags[ WP_TEMPLATE_PART_AREA_UNCATEGORIZED ];
    } else {
        $html_tag = esc_attr( $attributes['tagName'] );
    }
    $wrapper_attributes = get_block_wrapper_attributes();

    return "<$html_tag $wrapper_attributes>" . str_replace( ']]>', ']]&gt;', $content ) . "</$html_tag>";
}