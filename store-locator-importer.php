<?php
/**
 * Plugin Name: WPSL Custom XML Importer
 * Description: Imports locations from WP XML export to wpsl_stores with advanced ACF field mapping. Integrates robust Gutenberg JSON parsing for newer locations.
 * Version: 1.3.0
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
            <p>Upload the XML export file. Defines target architectures for extracting ACF parameters from either legacy postmeta or modern Gutenberg JSON abstractions.</p>
            
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
                                <option value="old_locations">Older Locations (ACF Postmeta Extractions)</option>
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
     * Core Parsing Engine: Maps Target Data and Advanced Gutenberg abstractions to ACF configurations.
     */
    private function process_location( $loc, $attachments, $dry_run, $import_type ) {
        $slug    = $loc['slug'];
        $title   = $loc['title'];
        $content = $loc['content'];
        $meta    = $loc['meta'];
        
        $log = ">>> Target: {$title} [/{$slug}/]\n";

        // Pre-flight check and DB instance injection
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
            $log .= "    [Info] Pre-existing record identified: ID {$post_id}. Executing merge...\n";
            if ( ! $dry_run ) {
                wp_update_post( [
                    'ID'           => $post_id,
                    'post_title'   => $title,
                    'post_content' => $content
                ] );
            }
        } else {
            $log .= "    [Info] Commencing new generation instance...\n";
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
        $gallery_texts        = [];
        $two_cols_heading     = '';
        $two_cols_content     = '';
        $two_columns_image    = '';
        $two_columns_btn_text = '';
        $two_columns_btn_link = '';
        $walkthrough_360      = '';

        // --- UNIVERSAL METADATA EXTRACTIONS (Extant Serialize Check) --- //
        if ( ! empty( $meta['Bedrooms'] ) ) {
            $bedrooms = $meta['Bedrooms'];
        } elseif ( ! empty( $meta['properties_0_property_sidebar_bedrooms'] ) ) {
            $bedrooms = $meta['properties_0_property_sidebar_bedrooms'];
        }
        
        if ( ! empty( $meta['properties_0_property_sidebar_cqc_id'] ) ) {
            $cqc_id = $meta['properties_0_property_sidebar_cqc_id'];
        }

        // --- SCOPED ABSTRACTIONS (Based on Importer Target) --- //
        if ( $import_type === 'old_locations' ) {
            // Deprecated Image Meta Extractions for Legacy records
            if ( ! empty( $meta['properties_0_property_main_content_images'] ) ) {
                $old_ids = maybe_unserialize( $meta['properties_0_property_main_content_images'] );
                if ( is_array( $old_ids ) ) {
                    foreach ( $old_ids as $old_id ) {
                        if ( isset( $attachments[ $old_id ] ) ) {
                            $url = $attachments[ $old_id ];
                            $log .= "    [Image] Extracted legacy meta gallery layer -> {$url}\n";
                            if ( ! $dry_run ) {
                                $new_id = $this->sideload_image( $url );
                                if ( $new_id ) $gallery_ids[] = $new_id;
                            }
                        }
                    }
                }
            }
        } else {
            // Modern Architecture - Advanced Gutenberg JSON Parsing
            
            // 1. CQC Widget Parse
            $cqcBlocks = $this->extractAllAcfBlockJson( $content, 'acf/cqc-widget' );
            if ( ! empty( $cqcBlocks ) ) {
                $cqc_id = $cqcBlocks[0]['data']['cqc_id'] ?? $cqc_id;
            }

            // 2. Button Parse
            $buttons = $this->extractAllAcfBlockJson( $content, 'acf/button' );
            if ( ! empty( $buttons ) ) {
                // Find latest valid link structure
                for ( $i = count( $buttons ) - 1; $i >= 0; $i -- ) {
                    $btnData = $buttons[ $i ]['data'] ?? [];
                    if ( isset( $btnData['button_link']['url'] ) ) {
                        $two_columns_btn_text = $btnData['button_link']['title'] ?? '';
                        $two_columns_btn_link = $btnData['button_link']['url'] ?? '';
                        break;
                    }
                }
            }

            // 3. Image Column Sections (Two Columns Configuration)
            $twoImageColumns = $this->extractImageColumnSections( $content );
            $two_cols_heading = $twoImageColumns['second']['heading'] ?: $twoImageColumns['first']['heading'];
            $two_cols_content = $twoImageColumns['second']['content'] ?: $twoImageColumns['first']['content'];
            $two_columns_image = $twoImageColumns['second']['image_old_attachment_id'] ?: $twoImageColumns['first']['image_old_attachment_id'];

            // 4. List Icons Parser (Facilities / Expertise)
            $listIconTitles = $this->extractListIconTitles( $content );
            $expertise = $listIconTitles['our_expertise'];
            
            // Separate explicit numbers from facilities strings natively 
            foreach ( $listIconTitles['facilities_and_features'] as $fac_val ) {
                if ( preg_match( '/^(\d+)\s+Bedrooms/i', $fac_val, $bed_m ) ) {
                    $bedrooms     = $bed_m[1];
                    $facilities[] = 'Bedrooms';
                } elseif ( preg_match( '/^(\d+)\s+Ensuite Bedrooms/i', $fac_val, $ens_m ) ) {
                    $ensuite      = $ens_m[1];
                    $facilities[] = 'Ensuite Bedrooms';
                } else {
                    $facilities[] = $fac_val;
                }
            }

            // 5. 360 Walkthrough Iframe Abstraction
            if ( preg_match( '/(<iframe[^>]+src="https?:\/\/www\.youtube\.com\/embed\/[^"]+"[^>]*><\/iframe>)/i', $content, $m ) ) {
                $walkthrough_360 = $m[1];
            }

            // 6. Complex Gallery Card Parsing and Sorting
            $cards       = $this->extractContentCards( $content );
            $galleryData = $this->buildGalleryFromCards( $cards );
            
            // Sideload Two Column Header Image
            if ( ! empty( $two_columns_image ) && isset( $attachments[ $two_columns_image ] ) && ! $dry_run ) {
                $two_columns_image = $this->sideload_image( $attachments[ $two_columns_image ] );
            }

            // Sideload sequential gallery assets (Dining/Living/Kitchen -> Rest)
            foreach ( $galleryData['gallery_image_old_attachment_ids'] as $idx => $g_old_id ) {
                if ( $g_old_id && isset( $attachments[ $g_old_id ] ) ) {
                    if ( ! $dry_run ) {
                        $new_id = $this->sideload_image( $attachments[ $g_old_id ] );
                        if ( $new_id ) {
                            $gallery_ids[]   = $new_id;
                            $gallery_texts[] = $galleryData['gallery_texts'][ $idx ] ?? '';
                        }
                    } else {
                        // In dry run, emulate finding array
                        $gallery_ids[]   = $g_old_id;
                        $gallery_texts[] = $galleryData['gallery_texts'][ $idx ] ?? '';
                    }
                }
            }
        }

        // --- FIELD INJECTION LAYER --- //
        if ( ! $dry_run ) {
            // Universal Mappings
            if ( $cqc_id ) update_field( 'field_69aaa93fb0482', $cqc_id, $post_id );

            // Native Gallery Array Injection
            if ( ! empty( $gallery_ids ) ) {
                $gallery_img_keys = [
                    'field_69aaaac54fe7a', 'field_69aaaadc4fe7d', 'field_69aaaae04fe7e',
                    'field_69aaaae14fe7f', 'field_69aaaae34fe80', 'field_69aaaae64fe81',
                    'field_69aaaae84fe82', 'field_69aaaaea4fe83', 'field_69aaaaeb4fe84',
                    'field_69aaaaec4fe85'
                ];
                $gallery_txt_keys = [
                    'field_69aaaad64fe7b', 'field_69aaaaef4fe86', 'field_69aaaaf04fe87',
                    'field_69aaaaf34fe88', 'field_69aaaaf44fe89', 'field_69aaaaf64fe8a',
                    'field_69aaaaf74fe8b', 'field_69aaaaf94fe8c', 'field_69aaaaf94fe8d',
                    'field_69aaaafc4fe8e'
                ];
                
                for ( $i = 0; $i < count( $gallery_ids ); $i++ ) {
                    if ( isset( $gallery_img_keys[ $i ] ) ) {
                        update_field( $gallery_img_keys[ $i ], $gallery_ids[ $i ], $post_id );
                        
                        // Map texts only if we're on the new architecture that extracted them
                        if ( $import_type === 'new_locations' && isset( $gallery_texts[ $i ] ) ) {
                            update_field( $gallery_txt_keys[ $i ], $gallery_texts[ $i ], $post_id );
                        }
                    }
                }
            }
            
            // Post Thumbnail mapping
            if ( ! empty( $meta['_thumbnail_id'] ) && isset( $attachments[ $meta['_thumbnail_id'] ] ) ) {
                $new_thumb_id = $this->sideload_image( $attachments[ $meta['_thumbnail_id'] ] );
                if ( $new_thumb_id ) set_post_thumbnail( $post_id, $new_thumb_id );
            }

            // Scoped Abstraction Mapping
            if ( $import_type === 'new_locations' ) {
                if ( ! empty( $facilities ) ) update_field( 'field_69aa920b6893c', array_unique( $facilities ), $post_id );
                if ( ! empty( $expertise ) ) update_field( 'field_69aa958818e50', array_unique( $expertise ), $post_id );
                
                if ( $bedrooms ) update_field( 'field_69aa9338e1174', $bedrooms, $post_id );
                if ( $ensuite ) update_field( 'field_69c3c065ee56d', $ensuite, $post_id );
                
                if ( is_numeric( $two_columns_image ) ) update_field( 'field_69aa9611c232c', $two_columns_image, $post_id );
                if ( $two_cols_heading ) update_field( 'field_69aa9621c232d', $two_cols_heading, $post_id );
                if ( $two_cols_content ) update_field( 'field_69aa9629c232e', $two_cols_content, $post_id );
                if ( $two_columns_btn_text ) update_field( 'field_69aa983bc9e6d', $two_columns_btn_text, $post_id );
                if ( $two_columns_btn_link ) update_field( 'field_69aa9848c9e6e', $two_columns_btn_link, $post_id );
                if ( $walkthrough_360 ) update_field( 'field_69aaa78016a16', $walkthrough_360, $post_id );
            }

            // Migrate Native WPSL Arrays (Universal)
            foreach ( $meta as $meta_key => $meta_value ) {
                if ( strpos( $meta_key, 'wpsl_' ) === 0 ) {
                    update_post_meta( $post_id, $meta_key, $meta_value );
                }
            }

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
                }
            }
        }

        $log .= sprintf(
            "    [Success] Compiled -> %d Facilities | %d Expertise | CQC: %s | %d Images Found.\n",
            count( $facilities ),
            count( $expertise ),
            ( $cqc_id ? $cqc_id : 'N/A' ),
            count( $gallery_ids )
        );

        if ( $dry_run ) {
            $log .= "    [DRY RUN] Architecture evaluated without modifying DB tables.\n\n";
        } else {
            $log .= "    [LIVE] Meta integration synchronized successfully to WP Post ID {$post_id}.\n\n";
        }
        
        return $log;
    }


    /* -----------------------------------------------------------------------------------------
     * HELPER PARSING METHODS (Adapted for Advanced Gutenberg Meta Extraction)
     * ----------------------------------------------------------------------------------------- */

    /**
     * Extracts ACF JSON data objects mapping from wp:acf comments block.
     */
    private function extractAllAcfBlockJson( string $postContent, string $blockSlug ): array {
        $out = [];
        $needle = '';
        
        while ( true ) {
            $start = strpos( $postContent, $openTag, $offset );
            if ( $start === false ) break;
            $end = strpos( $postContent, $closeTag, $start );
            if ( $end === false ) {
                $end = strlen( $postContent );
            } else {
                $end += strlen( $closeTag );
            }
            $segments[] = substr( $postContent, $start, $end - $start );
            $offset = $end + 1;
        }

        $extractHeading = function ( string $segment ): string {
            if ( preg_match( '/\s*<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $segment, $m ) ) {
                return trim( html_entity_decode( wp_strip_all_tags( $m[1] ) ) );
            }
            if ( preg_match( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $segment, $m ) ) {
                return trim( html_entity_decode( wp_strip_all_tags( $m[1] ) ) );
            }
            return '';
        };

        for ( $i = 0; $i < 2; $i++ ) {
            $segment = $segments[ $i ] ?? '';
            if ( ! $segment ) continue;

            $heading = $extractHeading( $segment );
            
            // Clean paragraph extractions
            $content = '';
            if ( preg_match_all( '/<p[^>]*>(.*?)<\/p>/s', $segment, $matches ) ) {
                $parts = [];
                foreach ( $matches[1] as $pHtml ) {
                    $text = wp_strip_all_tags( $pHtml );
                    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                    $text = preg_replace( '/\s+/u', ' ', trim( $text ) );
                    if ( $text !== '' ) $parts[] = $text;
                }
                
                if ( $i === 1 && count( $parts ) >= 2 && mb_strlen( $parts[0] ) <= 120 ) {
                    $content = implode( "\n\n", array_slice( $parts, 1 ) );
                } else {
                    $content = implode( "\n\n", $parts );
                }
            }

            $img_id = null;
            if ( isset( $jsonBlocks[ $i ]['data']['image_file'] ) && is_numeric( $jsonBlocks[ $i ]['data']['image_file'] ) ) {
                $img_id = (int) $jsonBlocks[ $i ]['data']['image_file'];
            }

            if ( $i === 0 ) {
                $out['first']['heading'] = $heading;
                $out['first']['content'] = $content;
                $out['first']['image_old_attachment_id'] = $img_id;
            } else {
                $out['second']['heading'] = $heading;
                $out['second']['content'] = $content;
                $out['second']['image_old_attachment_id'] = $img_id;
            }
        }
        return $out;
    }

    /**
     * Extracts categorical parameters defined from Icon items.
     */
    private function extractListIconTitles( string $postContent ): array {
        $out = [ 'our_expertise' => [], 'facilities_and_features' => [] ];
        $blocks = $this->extractAllAcfBlockJson( $postContent, 'acf/list-icon' );
        
        foreach ( $blocks as $idx => $block ) {
            $data = $block['data'] ?? [];
            if ( ! is_array( $data ) ) continue;

            $items = [];
            $n = isset( $data['list_items'] ) ? (int) $data['list_items'] : 0;
            if ( $n > 0 ) {
                for ( $i = 0; $i < $n; $i++ ) {
                    $key = 'list_items_' . $i . '_item_title';
                    if ( isset( $data[ $key ] ) ) $items[] = (string) $data[ $key ];
                }
            }
            if ( $idx === 0 ) $out['our_expertise'] = $items;
            else if ( $idx === 1 ) $out['facilities_and_features'] = $items;
        }
        return $out;
    }

    /**
     * Distils array structures associated with ACF dynamic content-cards block architecture.
     */
    private function extractContentCards( string $postContent ): array {
        $blocks = $this->extractAllAcfBlockJson( $postContent, 'acf/content-cards' );
        if ( empty( $blocks ) ) return [];

        $data = $blocks[0]['data'] ?? [];
        $cardsCount = isset( $data['cards'] ) ? (int) $data['cards'] : 0;
        $cards = [];
        
        if ( $cardsCount > 0 ) {
            for ( $i = 0; $i < $cardsCount; $i++ ) {
                $cards[] = [
                    'heading' => isset( $data[ 'cards_' . $i . '_card_heading' ] ) ? (string) $data[ 'cards_' . $i . '_card_heading' ] : '',
                    'old_attachment_id' => isset( $data[ 'cards_' . $i . '_card_image' ] ) ? (int) $data[ 'cards_' . $i . '_card_image' ] : null,
                ];
            }
        }
        return $cards;
    }

    /**
     * Sorts designated location galleries and groups residual cards appropriately.
     */
    private function buildGalleryFromCards( array $cards ): array {
        $usedIds = [];
        
        $findByNeedle = function ( string $needle ) use ( $cards, &$usedIds ): ?int {
            foreach ( $cards as $card ) {
                $hid = $card['old_attachment_id'];
                if ( ! $hid || isset( $usedIds[ $hid ] ) ) continue;
                
                if ( strpos( mb_strtolower( $card['heading'] ), mb_strtolower( $needle ) ) !== false ) {
                    $usedIds[ $hid ] = true;
                    return (int) $hid;
                }
            }
            return null;
        };

        $dining  = $findByNeedle( 'dining' );
        $living  = $findByNeedle( 'living' );
        $kitchen = $findByNeedle( 'kitchen' );

        $gallery = [];
        $texts   = [];

        if ( $dining )  { $gallery[] = $dining;  $texts[] = 'Dining Room'; }
        if ( $living )  { $gallery[] = $living;  $texts[] = 'Living Room'; }
        if ( $kitchen ) { $gallery[] = $kitchen; $texts[] = 'Kitchen'; }

        foreach ( $cards as $card ) {
            $hid = $card['old_attachment_id'];
            if ( ! $hid || isset( $usedIds[ $hid ] ) ) continue;
            
            if ( count( $gallery ) >= 10 ) break;
            
            $gallery[] = (int) $hid;
            $texts[]   = preg_replace( '/^Our\s+/i', '', trim( (string) $card['heading'] ) );
        }

        return [
            'kitchen_old_attachment_id'        => $kitchen,
            'living_room_old_attachment_id'    => $living,
            'dining_room_old_attachment_id'    => $dining,
            'gallery_image_old_attachment_ids' => $gallery,
            'gallery_texts'                    => $texts,
        ];
    }

    /**
     * Sideloads an image into the WP Media Library ensuring no duplications are executed.
     */
    private function sideload_image( $url ) {
        if ( empty( $url ) ) return false;
        if ( strpos( $url, '//' ) === 0 ) $url = 'https:' . $url;

        $filename = basename( parse_url( $url, PHP_URL_PATH ) );

        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like( $filename )
        );
        $existing = $wpdb->get_var( $query );
        if ( $existing ) return (int) $existing;

        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) return false;

        $file_array = [ 'name' => $filename, 'tmp_name' => $tmp ];
        $attachment_id = media_handle_sideload( $file_array, 0 );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $file_array['tmp_name'] );
            return false;
        }

        return $attachment_id;
    }
}

new WPSL_Custom_XML_Importer();