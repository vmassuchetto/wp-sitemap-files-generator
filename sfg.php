<?php
/*
* Plugin Name: Sitemap Files Generator
* Plugin URI: http://github.com/vmassuchetto/wp-sitemap-files-generator
* Description: Generate Google and Google News Sitemaps files
* Version: 0.02
* Author: Leo Germani, Vinicius Massuchetto, Rodrigo Primo
* Author URI: http://github.com/vmassuchetto/wp-sitemap-files-generator
*/
class Sitemap_Files_Generator {

    var $secret;
    var $secret_link;
    var $sitemap_files;

    var $xmldir;
    var $limit;

    var $log_file;
    var $log_limit;

    function Sitemap_Files_Generator() {

        load_plugin_textdomain( 'sfg', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );

        $this->xmldir = WP_CONTENT_DIR . '/sitemaps';
        $this->xmlurl = content_url( 'sitemaps' );
        $this->buffer_size = 100000; // 100KB

        // Google's sitemaps limits
        $this->limit = 50000;
        $this->limit_news = 2000;

        $this->secret = substr( md5( AUTH_KEY ), 0, 7 );
        $this->secret_link = home_url( 'index.php?generate_sitemaps=' . $this->secret );
        $this->index_link = $this->xmlurl . '/index.xml';

        $this->log_file = $this->xmldir . '/sitemap-' . $this->secret . '.log';
        $this->log_limit = 400000; // 400KB

        add_action( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'wp', array( $this, 'wp' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );

    }

    function admin_menu() {
        add_submenu_page( 'options-general.php',
            'Sitemap Files Generator', 'Sitemap Files Generator',
            'level_10', 'sfg', array( $this, 'submenu_page' ) );
    }

    function submenu_page() {
        $this->sitemap_files = $this->get_sitemap_files();
        include( dirname( __FILE__ ) . '/sfg-options.php' );
    }

    function query_vars( $vars = array() ) {
        array_push( $vars, 'generate_sitemaps' );
        return $vars;
    }

    function wp() {
        if ( $this->secret == get_query_var( 'generate_sitemaps' ) )
            $this->generate_all();
    }

    function generate_all() {

        if ( !$this->_mkdir() )
            wp_die( printf( __( 'Could not create directory %s.', 'sfg' ), $this->xmldir ) );

        set_time_limit( 0 );

        $this->start_sitemaps();
        $this->generate_news_sitemap();
        $this->generate_posts_sitemap();
        $this->generate_taxonomies_sitemap();
        $this->clear_sitemaps();
        $this->generate_index();

        _e( 'DONE!', 'sfg' );
        exit();
    }

    function log( $msg ) {

        $mode = 'a';
        if ( !is_file( $this->log_file )
            || filesize( $this->log_file ) > $this->log_limit ) {
            $mode = 'w';
        }

        $handle = fopen( $this->log_file, $mode );
        $msg = '[' . current_time( 'mysql' ) . '] ' . $msg . "\n";
        fwrite( $handle, $msg );
        fclose( $handle );

    }

    function start_sitemaps() {
        global $wpdb;
        $sql = "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_sfg_file_status_%'";
        $wpdb->query( $sql );
        $this->log( __( 'Starting global sitemap generation.', 'sfg' ) );
    }

    function clear_sitemaps() {

        global $wpdb;

        $this->log( __( 'Sitemap generation finished. Clearing old sitemaps...', 'sfg' ) );

        $files = $this->get_sitemap_files();
        foreach ( $files as $file ) {

            $file_option = '_sfg_file_status_' . md5( $file['name'] );
            $sql = "SELECT option_value FROM {$wpdb->options}
                WHERE option_name = '{$file_option}'";
            if ( $wpdb->get_results( $sql ) > 0 )
                continue;

            $this->log( sprintf( __( 'Removing invalid sitemap %s', 'sfg' ), $file['name'] ) );
            unlink( $this->xmldir . '/' . $file['name'] );

        }

    }

    function _mkdir() {

        if ( !is_dir( $this->xmldir ) && !mkdir( $this->xmldir, 700 ) )
            return false;

        $silence_file = $this->xmldir . '/index.php';
        if ( !is_file( $silence_file ) ) {
            $silence = '<' . '?php /* Silence is golden */ ?' . '>';
            if ( !file_put_contents( $silence_file, $silence ) )
                return false;
        }

        return true;

    }

    function generate_taxonomies_sitemap() {

        global $wpdb;

        $taxonomies = get_taxonomies( array( 'public' => true ) );
        foreach( $taxonomies as $taxonomy ) {

            // use query instead of get_terms for better memory usage
            $sql = "SELECT term_id
                FROM {$wpdb->term_taxonomy}
                WHERE taxonomy = '{$taxonomy}'";
            $terms = $wpdb->get_results( $sql );

            $index = 0;
            $total = count( $terms );
            $file_format = 'taxonomy-' . $taxonomy . '-%03d.xml';
            $file_index = 1;
            $file_handle = null;
            $buffer = '';

            foreach( $terms as $term ) {

                $term = get_term_by( 'id', $term->term_id, $taxonomy );
                $entry = array(
                    'loc' => get_term_link( $term ),
                    'lastmod' => date( 'c' ),
                    'changefreq' => 'daily',
                    'priority' => '0.6'
                );
                $this->sitemap_put( $file_format, $file_index, $file_handle,
                    $buffer, $index, $total, $entry );

                $this->clear_object_cache();

            }

        }

    }

    function generate_posts_sitemap() {

        global $wpdb, $wp_object_cache;

        $post_types = get_post_types( array( 'public' => true ) );
        unset( $post_types['attachment'] );
        foreach( $post_types as $post_type ) {

            $from = "FROM {$wpdb->posts}";
            $where = "WHERE 1=1
                AND post_status = 'publish'
                AND post_type = '{$post_type}'";

            $fetch_count = 0;
            $fetch_total = $wpdb->get_var( "SELECT COUNT(ID) {$from} {$where}" );

            $index = 0;
            $file_format = 'post-type-' . $post_type . '-%03d.xml';
            $file_index = 1;
            $file_handle = null;
            $buffer = '';

            while ( $fetch_count <= $fetch_total ) {

                $limit = "LIMIT {$fetch_count}, {$this->limit}";
                $posts = $wpdb->get_results( "SELECT ID, post_date {$from} {$where} {$limit}" );
                $total = count( $posts );

                foreach( $posts as $post ) {

                    $entry = array(
                        'id' => $post->ID,
                        'loc' => get_permalink( $post->ID ),
                        'lastmod' => date( 'c', strtotime( $post->post_date ) ),
                        'changefreq' => 'monthly',
                        'priority' => '0.4'
                    );
                    $this->sitemap_put( $file_format, $file_index, $file_handle,
                        $buffer, $index, $total, $entry );

                    $this->clear_object_cache();

                }

                $fetch_count += $this->limit;

            }

        }

    }

    function clear_object_cache() {
        global $wp_object_cache;
        $leave = array( 'options' );
        foreach( $wp_object_cache->cache as $k => $v ) {
            if ( !in_array( $k, $leave ) )
                unset( $wp_object_cache->cache[ $k ] );
        }
    }

    /*
     * @param $file_format Printf format for file
     * @param $file_index  Start from 1
     * @param $file_handle Start from null
     * @param $buffer      Start from empty string
     * @param $index       Start from 0
     * @param $total       Total number of items to insert in the sitemap
     * @param $entry       Array with url information
     */
    function sitemap_put ( $file_format, &$file_index, &$file_handle, &$buffer, &$index, $total, $entry ) {

        if ( 0 == $index ) {
            $type = !empty( $entry['news'] ) ? 'news' : 'standard';
            $buffer = $this->get_sitemap_head( $type );
            $file_name = $this->xmldir . '/' . sprintf( $file_format, $file_index );
            $file_handle = fopen( $file_name, 'w' );
            update_option( '_sfg_file_status_' . md5( $file_name ), false );
            $this->log( sprintf( __( 'Starting sitemap %s with %d items.', 'sfg' ), $file_name, $total ) );
        }

        $buffer .= $this->get_sitemap_entry( $entry );
        if ( strlen( $buffer ) >= $this->buffer_size ) {
            fwrite( $file_handle, $buffer );
            $buffer = '';
        }
        $index++;

        if ( 0 == $index % 5000 ) {
            $file_name = $this->xmldir . '/' . sprintf( $file_format, $file_index );
            $this->log( sprintf( __( '%d items inserted in %s', 'sfg' ), $index, $file_name ) );
        }

        if ( $index >= $total ) {
            $index = 0;
            $file_name = $this->xmldir . '/' . sprintf( $file_format, $file_index );
            $file_index++;
            $buffer .= '</urlset>';
            fwrite( $file_handle, $buffer );
            fclose( $file_handle );
            update_option( '_sfg_file_status_' . md5( $file_name ), true );
            $this->log( sprintf( __( 'Sitemap %s successfully generated', 'sfg' ), $file_name ) );
        }

    }

    function get_sitemap_head( $type = '') {
        $out = '<' . '?xml version="1.0" encoding="UTF-8"?' . '>'
            . '<urlset '
            . 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            . 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" ';

        if ( 'news' == $type )
            $out .= 'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9" ';

        return $out . '>';
    }

    function get_sitemap_entry( $entry = array() ) {

        $img = '';
        if ( !empty( $entry['id'] )
            && has_post_thumbnail( $entry['id'] )
            && $img = wp_get_attachment_image_src( get_post_thumbnail_id( $entry['id'] ), 'full' ) )
            $img = '<image:image><image:loc>' . $img[0] . '</image:loc></image:image>';

        if ( !empty( $entry['news'] ) ) {
            $language = preg_replace( '/-.*/', '', get_bloginfo( 'language' ) );
            return '<url>'
                . '<loc>' . $entry['loc'] . '</loc>'
                . $img
                . '<news:news>'
                . '<news:publication>'
                . '<news:name>' . get_bloginfo( 'name' ) . '</news:name>'
                . '<news:language>' . $language . '</news:language>'
                . '</news:publication>'
                . '<news:publication_date>' . $entry['news']['publication_date'] . '</news:publication_date>'
                . '<news:title>' . $entry['news']['title'] . '</news:title>'
                . '<news:keywords>' . $entry['news']['keywords'] . '</news:keywords>'
                . '</news:news>'
                . '</url>';
        }

        return '<url>'
            . '<loc>' . $entry['loc'] . '</loc>'
            . $img
            . '<lastmod>' . $entry['lastmod'] . '</lastmod>'
            . '<changefreq>' . $entry['changefreq'] . '</changefreq>'
            . '<priority>' . $entry['priority'] . '</priority>'
            . '</url>';

    }

    function generate_news_sitemap() {

        global $post, $wpdb, $wp_object_cache;

        // last two days, only
        $time = current_time( 'timestamp' ) - 3600 * 24 * 2;

        $sql = $wpdb->prepare("
            SELECT ID, post_title, post_date
            FROM {$wpdb->posts}
            WHERE 1=1
                AND post_type = 'post'
                AND post_status = 'publish'
                AND post_date >= '%s'
            LIMIT 0, %d
        ", date( 'Y-m-d H:i:s', $time ), $this->limit_news );
        $posts = $wpdb->get_results( $sql );

        $index = 0;
        $total = count( $posts );
        $file_format = 'news-%03d.xml';
        $file_index = 1;
        $file_handle = null;
        $buffer = '';

        foreach ( $posts as $post ) {

            $tag_list = array();
            if ( $tags = get_the_tags() ) {
                foreach( $tags as $tag ) {
                    $tag_list[] = $tag->name;
                }
            }

            $entry = array(
                'id' => $post->ID,
                'loc' => get_permalink( $post->ID ),
                'news' => array(
                    'publication_date' => date( 'c', strtotime( $post->post_date ) ),
                    'title' => strip_tags( apply_filters( 'the_content', $post->post_title ) ),
                    'keywords' => implode( ',', $tag_list )
                )
            );
            $this->sitemap_put( $file_format, $file_index, $file_handle,
                $buffer, $index, $total, $entry );

            $this->clear_object_cache();

        }

    }

    function get_sitemap_files() {

        if ( !$handle = @opendir( $this->xmldir ) )
            return false;

        $files = array();

        while( $file = readdir( $handle ) ) {
            if ( preg_match( '/\.xml$/', $file ) ) {
                $file_name = $this->xmldir . '/' . $file;
                $file_status_option = '_sfg_file_status_' . md5( $file_name );
                $files[] = array(
                    'name' => $file,
                    'url' => $this->xmlurl . '/' . $file,
                    'size' => $this->_filesize( $file_name ),
                    'mod' => date( 'c', filemtime( $file_name ) ),
                    'status' => get_option( $file_status_option )
                );
            }
        }

        usort( $files, function( $a, $b ) { return $a['name'] < $b['name'] ? -1 : 1; return 0; } );
        return $files;

    }

    function _filesize( $path ) {
        $bytes = sprintf( '%u', filesize( $path ));
        if ( $bytes > 0 ) {
            $unit = intval( log( $bytes, 1024 ) );
            $units = array( 'B', 'KB', 'MB', 'GB' );
            if ( array_key_exists( $unit, $units ) === true )
                return sprintf( '%d %s', $bytes / pow( 1024, $unit ), $units[ $unit ] );
        }
        return $bytes;
    }

    function generate_index() {

        if ( !$files = $this->get_sitemap_files() )
            return false;

        $file_option = '_sfg_file_status_' . md5( $this->xmldir . '/index.xml' );
        update_option( $file_option, false );

        $out = '<' . '?xml version="1.0" encoding="UTF-8"?' . '>'
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach( $files as $file ) {

            if ( 'index.xml' == $file['name'] )
                continue;

            $out .= '<sitemap>'
                . '<loc>' . $this->xmlurl . '/' . $file['name'] . '</loc>'
                . '<lastmod>' . date( 'c' ) . '</lastmod>'
                . '</sitemap>';

        }

        $out .= '</sitemapindex>';

        file_put_contents( $this->xmldir . '/index.xml', $out );
        update_option( $file_option, true );

    }

}

function sfg_init() {
    new Sitemap_Files_Generator();
}
add_action( 'plugins_loaded', 'sfg_init' );

?>
