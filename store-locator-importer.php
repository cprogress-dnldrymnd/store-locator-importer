<?php
/**
 * Plugin Name: WPSL Custom XML Importer
 * Description: Imports locations from WP XML export to wpsl_stores with advanced ACF field mapping. Includes scoped import targets for legacy repeater fields vs Gutenberg JSON architectures.
 * Version: 1.2.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Handles the logic and interface for the XML to ACF WP Store Locator Import.
 */
class WPSL_Custom_XML_Importer {

    /**
     * Class constructor. Hooks into WordPress administrative actions.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        
        // AJAX Endpoints
        add_action( 'wp_ajax_wpsl_upload_xml', [ $this, 'ajax_upload_xml' ] );
        add_action( 'wp_ajax_wpsl_process_batch', [ $this, 'ajax_process_batch' ] );
    }

    /**
     * Registers the importer tool page in the admin menu.
     */
    public function register_admin_menu() {
        add_management_page(
            'WPSL XML Importer',
            'WPSL XML Importer',
            'manage_options',
            'wpsl-xml-importer',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Enqueues jQuery if not already present on the admin page.
     *
     * @param string $hook The current admin page identifier.
     */
    public function enqueue_scripts( $hook ) {
        if ( $hook !== 'tools_page_wpsl-xml-importer' ) {
            return;
        }
        wp_enqueue_script( 'jquery' );
    }

    /**
     * Renders the UI for the XML Importer in the WordPress admin area.
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>WP Store Locator - Custom XML Importer</h1>
            <p>Upload the XML export file and define the target architecture for parsing `post_content` logic.</p>
            
            <form id="wpsl-import-form" method="post" enctype="multipart/form-data" style="background:#fff; padding:20px; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); max-width:800px; margin-top:20px;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="xml_file">Select XML File</label></th>
                        <td><input type="file" name="xml_file" id="xml_file" accept=".xml" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="import_type">Target Location Type</label></th>
                        <td>
                            <select name="import_type" id="import_type" required>
                                <option value="new_locations">Newer Locations (Gutenberg Blocks Parsing)</option>
                                <option value="old_locations">Older Locations (ACF 'Properties' Repeater Content)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Import Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="dry_run" id="dry_run" value="1" checked />
                                <strong>Dry Run</strong> (Simulate the process, generate logs, but DO NOT save data or download images)
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">Start Import</button>
                </p>
            </form>

            <div style="margin-top: 30px; max-width: 800px;">
                <h3>Live Progress Log</h3>
                <pre id="wpsl-log" style="background: #111; color: #0f0; padding: 15px; height: 400px; overflow-y: scroll; border-radius: 4px;"></pre>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#wpsl-import-form').on('submit', function(e) {
                e.preventDefault();
                
                var fileInput = $('#xml_file')[0].files[0];
                if (!fileInput) return alert('Please select a file.');

                var formData = new FormData();
                formData.append('xml_file', fileInput);
                formData.append('action', 'wpsl_upload_xml');
                formData.append('security', '<?php echo wp_create_nonce( "wpsl_import_nonce" ); ?>');
                
                var $log = $('#wpsl-log');
                var dryRun = $('#dry_run').is(':checked');
                var importType = $('#import_type').val();
                
                $log.html('Uploading and parsing XML structure...\n');
                $('button[type="submit"]').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            $log.append('XML successfully parsed. Found ' + response.data.total + ' target locations to import.\n');
                            processBatch(0, 3, response.data.total, dryRun, importType);
                        } else {
                            $log.append('Error: ' + response.data.message + '\n');
                            $('button[type="submit"]').prop('disabled', false);
                        }
                    },
                    error: function() {
                        $log.append('Server error during upload. Please check PHP file limits.\n');
                        $('button[type="submit"]').prop('disabled', false);
                    }
                });
            });

            function processBatch(offset, batchSize, total, dryRun, importType) {
                var $log = $('#wpsl-log');
                var endItem = Math.min(offset + batchSize, total);
                $log.append('\nProcessing items ' + (offset + 1) + ' to ' + endItem + ' of ' + total + '...\n');
                
                $.post(ajaxurl, {
                    action: 'wpsl_process_batch',
                    security: '<?php echo wp_create_nonce( "wpsl_import_nonce" ); ?>',
                    offset: offset,
                    batch: batchSize,
                    dry_run: dryRun ? 'true' : 'false',
                    import_type: importType
                }, function(response) {
                    if (response.success) {
                        $log.append(response.data.log);
                        $log.scrollTop($log[0].scrollHeight);

                        if (!response.data.done && offset + batchSize < total) {
                            processBatch(offset + batchSize, batchSize, total, dryRun, importType);
                        } else {
                            $log.append('\n--- IMPORT COMPLETED SEAMLESSLY ---\n');
                            $('button[type="submit"]').prop('disabled', false);
                        }
                    } else {
                        $log.append('Error during batch execution: ' + response.data.message + '\n');
                        $('button[type="submit"]').prop('disabled', false);
                    }
                }).fail(function() {
                    $log.append('Server timeout/error during processing. Retrying batch in 5 seconds...\n');
                    setTimeout(function() {
                        processBatch(offset, batchSize, total, dryRun, importType);
                    }, 5000);
                });
            }
        });
        </script>
        <?php
    }

    /**
     * AJAX handler: Uploads XML, maps attachments via attachment_url/guid, and extracts 'locations' to a temporary map JSON file.
     */
    public function ajax_upload_xml() {
        check_ajax_referer( 'wpsl_import_nonce', 'security' );

        if ( ! isset( $_FILES['xml_file'] ) || $_FILES['xml_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'File upload failed.' ] );
        }

        $file_tmp = $_FILES['xml_file']['tmp_name'];
        
        ini_set( 'memory_limit', '1024M' );
        set_time_limit( 300 );

        libxml_use_internal_errors( true );
        $xml = simplexml_load_file( $file_tmp );

        if ( ! $xml ) {
            wp_send_json_error( [ 'message' => 'Invalid or corrupted XML file structure.' ] );
        }

        $attachments = [];
        $locations   = [];

        // Distil and organize the target parameters from the XML export logic
        foreach ( $xml->channel->item as $item ) {
            $ns_wp     = $item->children( 'http://wordpress.org/export/1.2/' );
            $post_type = (string) $ns_wp->post_type;

            if ( $post_type === 'attachment' ) {
                $url = (string) ( $ns_wp->attachment_url ?? '' );
                if ( ! $url ) {
                    $url = (string) ( $item->guid ?? '' );
                }
                if ( $url ) {
                    $attachments[ (string) $ns_wp->post_id ] = $url;
                }
            } elseif ( $post_type === 'locations' ) {
                $meta = [];
                foreach ( $ns_wp->postmeta as $m ) {
                    $meta[ (string) $m->meta_key ] = (string) $m->meta_value;
                }

                $content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
                $content    = (string) $content_ns->encoded;

                $locations[] = [
                    'title'   => (string) $item->title,
                    'slug'    => (string) $ns_wp->post_name,
                    'content' => $content,
                    'meta'    => $meta
                ];
            }
        }

        $upload_dir  = wp_upload_dir();
        $temp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'wpsl_importer';
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }

        $data_file   = $temp_dir . '/import_data.json';
        $export_data = [
            'attachments' => $attachments,
            'locations'   => $locations
        ];

        file_put_contents( $data_file, wp_json_encode( $export_data ) );

        wp_send_json_success( [ 'total' => count( $locations ) ] );
    }

    /**
     * AJAX handler: Triggers logical mapping for a paginated batch segment to respect WP runtime capacities.
     */
    public function ajax_process_batch() {
        check_ajax_referer( 'wpsl_import_nonce', 'security' );

        $offset      = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
        $batch       = isset( $_POST['batch'] ) ? (int) $_POST['batch'] : 5;
        $dry_run     = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === 'true';
        $import_type = isset( $_POST['import_type'] ) ? sanitize_text_field( $_POST['import_type'] ) : 'new_locations';

        $upload_dir = wp_upload_dir();
        $data_file  = trailingslashit( $upload_dir['basedir'] ) . 'wpsl_importer/import_data.json';

        if ( ! file_exists( $data_file ) ) {
            wp_send_json_error( [ 'message' => 'Import data payload decoupled. Please reupload the file.' ] );
        }

        $data_json   = file_get_contents( $data_file );
        $data        = json_decode( $data_json, true );
        $attachments = $data['attachments'];
        $locations   = $data['locations'];
        
        $slice = array_slice( $locations, $offset, $batch );
        
        if ( empty( $slice ) ) {
            wp_send_json_success( [ 'log' => "Import trajectory complete.\n", 'done' => true ] );
        }

        $log_output = "";
        foreach ( $slice as $loc ) {
            $log_output .= $this->process_location( $loc, $attachments, $dry_run, $import_type );
        }

        wp_send_json_success( [ 'log' => $log_output, 'done' => false ] );
    }

    /**
     * Core Parsing Engine: Executes block abstractions and maps target data to WP Store Locator and ACF fields.
     *
     * @param array  $loc The location parameters.
     * @param array  $attachments The universal attachments map linking old IDs to source URIs.
     * @param bool   $dry_run Controls physical data modification.
     * @param string $import_type Scope determination for architecture routing (new_locations | old_locations).
     * @return string Generates log outputs representing the system's runtime evaluations.
     */
    private function process_location( $loc, $attachments, $dry_run, $import_type ) {
        $slug    = $loc['slug'];
        $title   = $loc['title'];
        $content = $loc['content'];
        $meta    = $loc['meta'];
        
        $log = ">>> Target: {$title} [/{$slug}/]\n";

        // Logic Override: Determine if the target expects older layout architecture 
        // Overwrite $content with values synthesized sequentially from the ACF properties repeater
        if ( $import_type === 'old_locations' ) {
            $property_names = [];
            foreach ( $meta as $key => $val ) {
                if ( preg_match( '/^properties_(\d+)_property_name$/', $key, $m ) ) {
                    $property_names[ (int) $m[1] ] = $val;
                }
            }
            if ( ! empty( $property_names ) ) {
                ksort( $property_names ); // Reorder strictly by numeric repeater index
                $content = implode( "\n\n", $property_names );
                $log .= "    [Info] Pre-compiling post_content synthesized from ACF 'properties' repeater.\n";
            } else {
                $log .= "    [Warning] Old Location configuration selected, but no 'properties_X_property_name' meta found.\n";
            }
        }

        // Query against WP scope to preserve unique slug structures instead of recreating
        $args = [
            'name'        => $slug,
            'post_type'   => 'wpsl_stores',
            'post_status' => 'any',
            'numberposts' => 1
        ];
        $existing = get_posts( $args );
        
        $post_id = 0;
        if ( $existing ) {
            $post_id = $existing[0]->ID;
            $log .= "    [Info] Pre-existing record identified: ID {$post_id}. Executing merge/overwrite...\n";
            if ( ! $dry_run ) {
                wp_update_post( [
                    'ID'           => $post_id,
                    'post_title'   => $title,
                    'post_content' => $content
                ] );
            }
        } else {
            $log .= "    [Info] No matching records found. Commencing generation instance...\n";
            if ( ! $dry_run ) {
                $post_id = wp_insert_post( [
                    'post_title'   => $title,
                    'post_name'    => $slug,
                    'post_content' => $content,
                    'post_type'    => 'wpsl_stores',
                    'post_status'  => 'publish'
                ] );
            }
        }

        if ( ! $post_id && ! $dry_run ) {
            return $log . "    [Error] Failure resolving post injection boundaries.\n";
        }

        $facilities           = [];
        $expertise            = [];
        $bedrooms             = '';
        $ensuite              = '';
        $cqc_id               = '';
        $gallery_ids          = [];
        $two_cols_heading     = '';
        $two_cols_content     = '';
        $two_columns_image    = '';
        $two_columns_btn_text = '';
        $two_columns_btn_link = '';

        // 1. Parse Existing Serialized Metadata Layouts 
        if ( ! empty( $meta['Bedrooms'] ) ) {
            $bedrooms = $meta['Bedrooms'];
        } elseif ( ! empty( $meta['properties_0_property_sidebar_bedrooms'] ) ) {
            $bedrooms = $meta['properties_0_property_sidebar_bedrooms'];
        }
        
        if ( ! empty( $meta['properties_0_property_sidebar_cqc_id'] ) ) {
            $cqc_id = $meta['properties_0_property_sidebar_cqc_id'];
        }

        if ( ! empty( $meta['properties_0_property_main_content_images'] ) ) {
            $old_ids = maybe_unserialize( $meta['properties_0_property_main_content_images'] );
            if ( is_array( $old_ids ) ) {
                foreach ( $old_ids as $old_id ) {
                    if ( isset( $attachments[ $old_id ] ) ) {
                        $url = $attachments[ $old_id ];
                        $log .= "    [Image] Extracted meta gallery layer -> {$url}\n";
                        if ( ! $dry_run ) {
                            $new_id = $this->sideload_image( $url );
                            if ( $new_id ) $gallery_ids[] = $new_id;
                        }
                    }
                }
            }
        }

        // 2. Parse inner Gutenberg JSON architectures for newer locations
        if ( $import_type === 'new_locations' ) {
            
            // Extract Block JSON Configurations mapped under the wp:acf wrapper
            if ( preg_match_all( '//s', $content, $matches ) ) {
                foreach ( $matches[1] as $json_str ) {
                    $data = json_decode( $json_str, true );
                    if ( isset( $data['data'] ) ) {
                        foreach ( $data['data'] as $k => $v ) {
                            // ACF List-Icons Extraction
                            if ( strpos( $k, 'item_title' ) !== false ) {
                                $val = trim( $v );
                                if ( preg_match( '/^(\d+)\s+Bedrooms/i', $val, $bed_matches ) ) {
                                    $bedrooms     = $bed_matches[1];
                                    $facilities[] = 'Bedrooms';
                                } elseif ( preg_match( '/^(\d+)\s+Ensuite Bedrooms/i', $val, $ens_matches ) ) {
                                    $ensuite      = $ens_matches[1];
                                    $facilities[] = 'Ensuite Bedrooms';
                                } elseif ( in_array( $val, [ 'Acquired Brain Injury', 'Autism', 'Complex Needs', 'Dementia', "Huntington's Disease", 'Learning Disabilities', 'Mental Health', 'Neurological Conditions' ] ) ) {
                                    $expertise[] = $val;
                                } else {
                                    $facilities[] = $val;
                                }
                            }
                            
                            // Image/Gallery ID Extraction
                            if ( strpos( $k, 'card_image' ) !== false && is_numeric( $v ) ) {
                                if ( isset( $attachments[ $v ] ) ) {
                                    $url = $attachments[ $v ];
                                    $log .= "    [Image] Extracted block gallery layer -> {$url}\n";
                                    if ( ! $dry_run ) {
                                        $new_id = $this->sideload_image( $url );
                                        if ( $new_id ) $gallery_ids[] = $new_id;
                                    }
                                }
                            }

                            // CQC Widget Parse
                            if ( $k === 'cqc_id' ) {
                                $cqc_id = $v;
                            }

                            // Two-Column Meta Configuration 
                            if ( $k === 'image_file' && is_numeric( $v ) ) {
                                $two_columns_image = $v;
                            }
                            if ( $k === 'button_link' && is_array( $v ) ) {
                                $two_columns_btn_text = $v['title'] ?? '';
                                $two_columns_btn_link = $v['url'] ?? '';
                            }
                        }
                    }
                }
            }

            // Execute node-extraction for dynamic Two-Column content mapping
            if ( preg_match( '/(.*?)/s', $content, $col_match ) ) {
                $col_html = $col_match[1];
                
                if ( preg_match( '/<h2[^>]*>(.*?)<\/h2>/is', $col_html, $h2_match ) ) {
                    $two_cols_heading = wp_strip_all_tags( $h2_match[1] );
                }
                
                // Clear the header strings and core WP annotations to yield explicit <p> paragraph content
                $clean_content = preg_replace( '//s', '', $col_html );
                $clean_content = preg_replace( '/<h2[^>]*>.*?<\/h2>/is', '', $clean_content );
                $two_cols_content = trim( $clean_content );
            }
        }

        // 3. ACF Field Injection via Validated JSON Keys
        if ( ! $dry_run ) {
            update_field( 'field_69aa920b6893c', array_unique( $facilities ), $post_id );
            update_field( 'field_69aa958818e50', array_unique( $expertise ), $post_id );
            
            if ( $bedrooms ) update_field( 'field_69aa9338e1174', $bedrooms, $post_id );
            if ( $ensuite ) update_field( 'field_69c3c065ee56d', $ensuite, $post_id );
            if ( $cqc_id ) update_field( 'field_69aaa93fb0482', $cqc_id, $post_id );

            // Dynamically evaluate sideloaded images against strict gallery configurations using exact JSON Keys
            if ( ! empty( $gallery_ids ) ) {
                $unique_gallery = array_values( array_unique( $gallery_ids ) );
                $gallery_keys   = [
                    'field_69aaaac54fe7a', 'field_69aaaadc4fe7d', 'field_69aaaae04fe7e',
                    'field_69aaaae14fe7f', 'field_69aaaae34fe80', 'field_69aaaae64fe81',
                    'field_69aaaae84fe82', 'field_69aaaaea4fe83', 'field_69aaaaeb4fe84',
                    'field_69aaaaec4fe85'
                ];
                
                for ( $i = 0; $i < count( $unique_gallery ); $i++ ) {
                    if ( isset( $gallery_keys[ $i ] ) ) {
                        update_field( $gallery_keys[ $i ], $unique_gallery[ $i ], $post_id );
                    }
                }
                $log .= "    [Info] Mapped " . count( $unique_gallery ) . " images to sequential ACF gallery_image fields.\n";
            }
            
            // Two Columns Block Fields Migration
            if ( ! empty( $two_columns_image ) && isset( $attachments[ $two_columns_image ] ) ) {
                $new_image_id = $this->sideload_image( $attachments[ $two_columns_image ] );
                if ( $new_image_id ) update_field( 'field_69aa9611c232c', $new_image_id, $post_id );
            }
            if ( ! empty( $two_cols_heading ) ) update_field( 'field_69aa9621c232d', $two_cols_heading, $post_id );
            if ( ! empty( $two_cols_content ) ) update_field( 'field_69aa9629c232e', $two_cols_content, $post_id );
            if ( ! empty( $two_columns_btn_text ) ) update_field( 'field_69aa983bc9e6d', $two_columns_btn_text, $post_id );
            if ( ! empty( $two_columns_btn_link ) ) update_field( 'field_69aa9848c9e6e', $two_columns_btn_link, $post_id );

            if ( ! empty( $meta['_thumbnail_id'] ) && isset( $attachments[ $meta['_thumbnail_id'] ] ) ) {
                $url = $attachments[ $meta['_thumbnail_id'] ];
                $new_thumb_id = $this->sideload_image( $url );
                if ( $new_thumb_id ) {
                    set_post_thumbnail( $post_id, $new_thumb_id );
                }
            }
        }

        // 4. Migrate Native WP Store Locator Data Mapping
        if ( ! $dry_run ) {
            $wpsl_fields_count = 0;

            // Direct wpsl_ field transfer
            foreach ( $meta as $meta_key => $meta_value ) {
                if ( strpos( $meta_key, 'wpsl_' ) === 0 ) {
                    update_post_meta( $post_id, $meta_key, $meta_value );
                    $wpsl_fields_count++;
                }
            }

            // Target the old `location` serialized payload and migrate to exact WP Store Locator indices
            if ( ! empty( $meta['location'] ) ) {
                $old_location = maybe_unserialize( $meta['location'] );
                if ( is_array( $old_location ) ) {
                    if ( ! empty( $old_location['address'] ) )   update_post_meta( $post_id, 'wpsl_address', $old_location['address'] );
                    if ( ! empty( $old_location['city'] ) )      update_post_meta( $post_id, 'wpsl_city', $old_location['city'] );
                    if ( ! empty( $old_location['state'] ) )     update_post_meta( $post_id, 'wpsl_state', $old_location['state'] );
                    if ( ! empty( $old_location['post_code'] ) ) update_post_meta( $post_id, 'wpsl_zip', $old_location['post_code'] );
                    if ( ! empty( $old_location['country'] ) )   update_post_meta( $post_id, 'wpsl_country', $old_location['country'] );
                    if ( ! empty( $old_location['lat'] ) )       update_post_meta( $post_id, 'wpsl_lat', $old_location['lat'] );
                    if ( ! empty( $old_location['lng'] ) )       update_post_meta( $post_id, 'wpsl_lng', $old_location['lng'] );
                    
                    $wpsl_fields_count += 7;
                    $log .= "    [Info] Unserialized old `location` data and mapped directly into `wpsl_` structures.\n";
                }
            }
        }

        $log .= sprintf(
            "    [Success] Compiled -> %d Facilities | %d Expertise | %s Beds | %s Ensuites | CQC: %s | %d Images Found.\n",
            count( $facilities ),
            count( $expertise ),
            ( $bedrooms ? $bedrooms : '0' ),
            ( $ensuite ? $ensuite : '0' ),
            ( $cqc_id ? $cqc_id : 'N/A' ),
            count( $gallery_ids )
        );

        if ( $dry_run ) {
            $log .= "    [DRY RUN] ACF fields and location records parsed but preserved without database alterations.\n\n";
        } else {
            $log .= "    [LIVE] Integration synchronized successfully to WP Post ID {$post_id}.\n\n";
        }
        
        return $log;
    }

    /**
     * Sideloads an image into the WP Media Library ensuring no duplications are executed.
     *
     * @param string $url The explicit URI to digest.
     * @return int|bool Relegated attachment ID, or false if execution fails.
     */
    private function sideload_image( $url ) {
        if ( empty( $url ) ) {
            return false;
        }

        if ( strpos( $url, '//' ) === 0 ) {
            $url = 'https:' . $url;
        }

        $filename = basename( parse_url( $url, PHP_URL_PATH ) );

        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like( $filename )
        );
        $existing = $wpdb->get_var( $query );

        if ( $existing ) {
            return (int) $existing;
        }

        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            return false;
        }

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp
        ];

        $attachment_id = media_handle_sideload( $file_array, 0 );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $file_array['tmp_name'] );
            return false;
        }

        return $attachment_id;
    }
}

new WPSL_Custom_XML_Importer();