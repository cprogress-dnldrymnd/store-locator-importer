<?php
/**
 * IVolve Store Locator migration helper.
 *
 * Plugin Name: IVolve Store Locator Importer
 * Description: Imports old `locations` CPT content into new `wpsl_stores` Store Locator fields (ACF + images). Handles both Gutenberg blocks and legacy ACF meta layouts.
 * Version: 1.1.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 *
 * Place this file into `wp-content/mu-plugins/` on your staging environment,
 * then run via WP-CLI:
 *
 * wp ivolve locations store-locator-import --old-xml="/path/to/Original Data/ivolve.WordPress.2026-03-23.xml" --slug="68-woodhurst-avenue"
 *
 * Notes:
 * - This importer intentionally "fills blanks only" for existing `wpsl_stores` posts by default (`--merge=s2`).
 * - It sideloads images from the URLs embedded in the old WXR export.
 */

// In staging, this file will be executed inside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'IVolve_WXR_Store_Locator_Parser' ) ) {
	class IVolve_WXR_Store_Locator_Parser {
		// Use untyped property for broader PHP compatibility (avoids PHP 7.4+ typed properties).
		private $wxrPath;

		/**
		 * Constructor for the parser.
		 *
		 * @param string $wxrPath Absolute path to the XML file.
		 */
		public function __construct( string $wxrPath ) {
			$this->wxrPath = $wxrPath;
		}

		/**
		 * Parse WXR attachments into: old_attachment_id => attachment_url
		 *
		 * @return array<int, string>
		 */
		public function loadAttachmentUrlMap(): array {
			$xml = $this->loadXml();

			$out = [];
			if ( empty( $xml->channel->item ) ) {
				return $out;
			}

			foreach ( $xml->channel->item as $item ) {
				$wp = $item->children( 'wp', true );
				$postType = (string) ( $wp->post_type ?? '' );
				if ( $postType !== 'attachment' ) {
					continue;
				}

				$oldId = (int) ( $wp->post_id ?? 0 );
				if ( ! $oldId ) {
					continue;
				}

				$url = (string) ( $wp->attachment_url ?? '' );
				if ( ! $url ) {
					// Fallback: WXR usually contains `guid`, but `attachment_url` is the most consistent.
					$url = (string) ( $item->guid ?? '' );
				}

				if ( $url ) {
					$out[ $oldId ] = $url;
				}
			}

			return $out;
		}

		/**
		 * Iterate through WXR `<item>` nodes and return parsed structures for locations posts.
		 *
		 * @param callable(array $payload):void $onLocation
		 */
		public function iterateLocations( callable $onLocation ): void {
			$xml = $this->loadXml();

			if ( empty( $xml->channel->item ) ) {
				return;
			}

			foreach ( $xml->channel->item as $item ) {
				$wp = $item->children( 'wp', true );
				$postType = (string) ( $wp->post_type ?? '' );
				if ( $postType !== 'locations' ) {
					continue;
				}

				$postName = (string) ( $wp->post_name ?? '' );
				$postStatus = (string) ( $wp->status ?? 'publish' );
				$postId = (int) ( $wp->post_id ?? 0 );

				$postTitle = (string) ( $item->title ?? $postName );

				$contentNs = $item->children( 'content', true );
				$postContent = (string) ( $contentNs->encoded ?? '' );

				// Categories: e.g. domain="location_type" nicename="supported-living".
				$categoriesByDomain = [];
				foreach ( $item->category as $cat ) {
					$domain = (string) ( $cat['domain'] ?? '' );
					$nicename = (string) ( $cat['nicename'] ?? '' );
					if ( $domain && $nicename ) {
						$categoriesByDomain[ $domain ][] = $nicename;
					}
				}

				// Post meta.
				$meta = [];
				if ( ! empty( $wp->postmeta ) ) {
					foreach ( $wp->postmeta as $postmeta ) {
						$pmWp = $postmeta->children( 'wp', true );
						$key = (string) ( $pmWp->meta_key ?? '' );
						$value = (string) ( $pmWp->meta_value ?? '' );
						if ( $key ) {
							$meta[ $key ] = $value;
						}
					}
				}

				$payload = [
					'post_id'              => $postId,
					'post_name'            => $postName,
					'post_status'          => $postStatus,
					'post_title'           => $postTitle,
					'post_content'         => $postContent,
					'categories_by_domain' => $categoriesByDomain,
					'meta'                 => $meta,
				];

				$onLocation( $payload );
			}
		}

		/**
		 * Loads and parses the XML file.
		 *
		 * @return object
		 * @throws RuntimeException if file fails to load.
		 */
		private function loadXml(): object {
			$prev = libxml_use_internal_errors( true );
			$xml = simplexml_load_file( $this->wxrPath );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );

			if ( ! $xml ) {
				throw new RuntimeException( 'Failed to load WXR XML: ' . $this->wxrPath );
			}

			return $xml;
		}
	}
}

if ( ! class_exists( 'IVolve_Store_Locator_Import_Command' ) ) {
	class IVolve_Store_Locator_Import_Command {
		/**
		 * Extract values from the old `locations` post content.
		 *
		 * @param array $location The parsed location data array.
		 * @return array The normalized mapping for insertion into wpsl_stores.
		 */
		private static function mapLocationPayload( array $location ): array {
			$postContent = (string) ( $location['post_content'] ?? '' );
			$meta        = (array) ( $location['meta'] ?? [] );

			// Address + city/zip/country come from the serialized `location` meta.
			$wpsl = [
				'wpsl_address' => '',
				'wpsl_city'    => '',
				'wpsl_zip'     => '',
				'wpsl_country' => '',
			];
			$serializedLocation = $meta['location'] ?? '';
			$locationArr        = is_string( $serializedLocation ) ? @unserialize( $serializedLocation ) : false;
			
			if ( is_array( $locationArr ) ) {
				// Map directly from 'address' key. Fallback to street_number + street_name if empty.
				$wpsl['wpsl_address'] = (string) ( $locationArr['address'] ?? '' );
				if ( empty( trim( $wpsl['wpsl_address'] ) ) ) {
					$streetNumber = (string) ( $locationArr['street_number'] ?? '' );
					$streetName   = (string) ( $locationArr['street_name'] ?? '' );
					$wpsl['wpsl_address'] = trim( $streetNumber . ' ' . $streetName );
				}
				
				$wpsl['wpsl_city']    = (string) ( $locationArr['city'] ?? '' );
				$wpsl['wpsl_zip']     = (string) ( $locationArr['post_code'] ?? '' );
				$wpsl['wpsl_country'] = (string) ( $locationArr['country'] ?? '' );
			}

			// Bedrooms: old meta key is `Bedrooms` (note capital B).
			$bedrooms = null;
			if ( isset( $meta['Bedrooms'] ) ) {
				$raw = (string) $meta['Bedrooms'];
				if ( is_numeric( trim( $raw ) ) ) {
					$bedrooms = (int) trim( $raw );
				} else if ( preg_match( '/(\d+)/', $raw, $m ) ) {
					$bedrooms = (int) $m[1];
				}
			}

			// Original thumbnail fallback
			$originalThumbnailId = ! empty( $meta['_thumbnail_id'] ) ? (int) $meta['_thumbnail_id'] : null;

			// CQC id: `acf/cqc-widget`.
			$cqcId     = '';
			$cqcBlocks = self::extractAllAcfBlockJson( $postContent, 'acf/cqc-widget' );
			if ( ! empty( $cqcBlocks ) ) {
				$first = $cqcBlocks[0];
				$data  = $first['data'] ?? [];
				$cqcId = (string) ( $data['cqc_id'] ?? '' );
			}

			// Image-column blocks for the first section and the "two columns" section.
			$twoImageColumns = self::extractImageColumnSections( $postContent );

			// Expertise/features: `acf/list-icon` blocks.
			$listIconTitles = self::extractListIconTitles( $postContent );
			$ourExpertise   = self::normalizeOurExpertiseFromLabels( (array) ( $listIconTitles['our_expertise'] ?? [] ) );
			$facilitiesAndFeatures = self::normalizeCheckboxValues( 'facilities_and_features', (array) ( $listIconTitles['facilities_and_features'] ?? [] ) );
			
			if ( is_int( $bedrooms ) && $bedrooms > 0 && ! in_array( 'Bedrooms', $facilitiesAndFeatures, true ) ) {
				$facilitiesAndFeatures[] = 'Bedrooms';
			}

			// Content cards: `acf/content-cards` blocks -> kitchen/living/dining and gallery slots.
			$cards = self::extractContentCards( $postContent );
			$orderedGallery = self::buildGalleryFromCards( $cards );

			$heroImageOldId   = self::extractPageHeaderHeroImageAttachmentId( $postContent );
			$twoColumnsButton = self::extractTwoColumnsButtonFromContent( $postContent );
			$walkthrough360   = self::extractWalkthrough360FromContent( $postContent );
			
			$twoColumnsHeading = (string) ( $twoImageColumns['second']['heading'] ?? '' );
			if ( trim( $twoColumnsHeading ) === '' ) {
				$twoColumnsHeading = (string) ( $twoImageColumns['first']['heading'] ?? '' );
			}
			$twoColumnsContent = (string) ( $twoImageColumns['second']['content'] ?? '' );
			if ( trim( $twoColumnsContent ) === '' ) {
				$twoColumnsContent = (string) ( $twoImageColumns['first']['content'] ?? '' );
			}

			$firstSectionContent = (string) ( $twoImageColumns['first']['content'] ?? '' );

			// --- LEGACY META FALLBACKS (e.g. Knollbeck format) ---
			
			// Fallback: If Gutenberg block parsing yielded empty content, check native postmeta.
			if ( empty( trim( $firstSectionContent ) ) && ! empty( $meta['properties_0_property_main_content_property_main_text'] ) ) {
				$firstSectionContent = $meta['properties_0_property_main_content_property_main_text'];
			}

			// Fallback: Legacy CQC ID
			if ( empty( $cqcId ) && ! empty( $meta['properties_0_property_sidebar_cqc_id'] ) ) {
				$cqcId = $meta['properties_0_property_sidebar_cqc_id'];
			}

			// Fallback: Legacy Bedrooms
			if ( empty( $bedrooms ) && ! empty( $meta['properties_0_property_sidebar_bedrooms'] ) ) {
				$bedrooms = (int) $meta['properties_0_property_sidebar_bedrooms'];
				if ( ! in_array( 'Bedrooms', $facilitiesAndFeatures, true ) ) {
					$facilitiesAndFeatures[] = 'Bedrooms';
				}
			}

			// Fallback: Extract images from legacy meta array
			if ( empty( $heroImageOldId ) && empty( $twoImageColumns['first']['image_old_attachment_id'] ) && ! empty( $meta['properties_0_property_main_content_images'] ) ) {
				$legacyImages = @unserialize( $meta['properties_0_property_main_content_images'] );
				if ( is_array( $legacyImages ) && count( $legacyImages ) > 0 ) {
					
					// Prefer original thumbnail as hero if present in legacy array, else take the first image
					$heroImageOldId = $originalThumbnailId && in_array( $originalThumbnailId, $legacyImages ) ? $originalThumbnailId : (int) $legacyImages[0];

					// Populate gallery with the remaining images
					$galleryImages = [];
					foreach( $legacyImages as $imgId ) {
						if ( (int) $imgId !== $heroImageOldId ) {
							$galleryImages[] = (int) $imgId;
						}
					}
					
					if ( empty( $orderedGallery['gallery_image_old_attachment_ids'] ) ) {
						$orderedGallery['gallery_image_old_attachment_ids'] = array_slice( $galleryImages, 0, 10 );
						$orderedGallery['gallery_texts'] = array_fill( 0, count( $orderedGallery['gallery_image_old_attachment_ids'] ), '' );
					}
				}
			}
			// --------------------------------------------------------

			$oldImageRefs = [
				'first_section_image' => $heroImageOldId,
				'two_columns_image'   => $twoImageColumns['second']['image_old_attachment_id'] ?? $twoImageColumns['first']['image_old_attachment_id'] ?? null,
				'kitchen'             => $orderedGallery['kitchen_old_attachment_id'] ?? null,
				'living_room'         => $orderedGallery['living_room_old_attachment_id'] ?? null,
				'dining_room'         => $orderedGallery['dining_room_old_attachment_id'] ?? null,
				'gallery_images'      => $orderedGallery['gallery_image_old_attachment_ids'] ?? [],
				'gallery_texts'       => $orderedGallery['gallery_texts'] ?? [],
			];

			$taxonomyCategory = '';
			$catsByDomain     = (array) ( $location['categories_by_domain'] ?? [] );
			if ( ! empty( $catsByDomain['location_type'] ) && is_array( $catsByDomain['location_type'] ) ) {
				$taxonomyCategory = (string) ( $catsByDomain['location_type'][0] ?? '' );
			}

			$locationDescription = (string) ( $meta['location_description'] ?? '' );
			if ( ! $locationDescription ) {
				$locationDescription = (string) ( $meta['location'] ?? '' );
			}

			return [
				'taxonomy_category_nicename' => $taxonomyCategory,
				'wpsl'                       => $wpsl,
				'bedrooms'                   => $bedrooms,
				'cqc_id'                     => $cqcId,
				'first_section_heading'      => (string) ( $twoImageColumns['first']['heading'] ?? '' ),
				'first_section_content'      => $firstSectionContent,
				'two_columns_heading'        => $twoColumnsHeading,
				'two_columns_content'        => $twoColumnsContent,
				'our_expertise'              => $ourExpertise,
				'facilities_and_features'    => $facilitiesAndFeatures,
				'two_columns_button_text'    => (string) ( $twoColumnsButton['text'] ?? '' ),
				'two_columns_button_link'    => (string) ( $twoColumnsButton['link']['url'] ?? '' ),
				'walkthrough_360'            => (string) $walkthrough360,
				'short_description'          => $locationDescription,
				'old_image_refs'             => $oldImageRefs,
				'original_thumbnail_id'      => $originalThumbnailId, // Carried over for strict thumbnail fallback
			];
		}

		/**
		 * Extract ACF JSON data objects from blocks like:
		 * *
		 * @param string $postContent Full post content.
		 * @param string $blockSlug ACF block identifier.
		 * @return array<int, array> list of decoded JSON objects
		 */
		private static function extractAllAcfBlockJson( string $postContent, string $blockSlug ): array {
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

			$extractParagraphs = function ( string $segment, bool $truncateAtFirstButton = true ): array {
				if ( $truncateAtFirstButton ) {
					$buttonPos = strpos( $segment, '\s*<p[^>]*>(.*?)<\/p>\s*/s', $beforeButton, $matches ) ) {
						return [];
					}

					$parts = [];
					foreach ( $matches[1] as $pHtml ) {
						$text = wp_strip_all_tags( $pHtml );
						$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
						if ( $text !== '' ) {
							$parts[] = $text;
						}
					}
					return $parts;
				}

				$parts = [];
				if ( preg_match_all( '/<p[^>]*>(.*?)<\/p>/s', $segment, $matches ) ) {
					foreach ( $matches[1] as $pHtml ) {
						$text = wp_strip_all_tags( $pHtml );
						$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
						if ( $text !== '' ) {
							$parts[] = $text;
						}
					}
				}
				return $parts;
			};

			$extractOldAttachmentRef = function ( array $jsonData, string $segment ) {
				$candidates = [ 'image', 'image_id', 'imageId', 'image_file', 'imageFile', 'image_old_attachment_id', 'image_attachment_id', 'image_old_id', 'attachment_id', 'old_attachment_id' ];
				foreach ( $candidates as $key ) {
					if ( ! array_key_exists( $key, $jsonData ) ) continue;
					$val = $jsonData[ $key ];

					if ( is_numeric( $val ) ) {
						$id = (int) $val;
						if ( $id > 0 ) return $id;
					}

					if ( is_array( $val ) ) {
						foreach ( [ 'id', 'ID', 'attachment_id', 'old_attachment_id' ] as $subKey ) {
							if ( isset( $val[ $subKey ] ) && is_numeric( $val[ $subKey ] ) ) {
								$id = (int) $val[ $subKey ];
								if ( $id > 0 ) return $id;
							}
						}
					}
				}

				if ( preg_match( '/wp-image-(\d+)/', $segment, $m ) ) {
					$id = (int) $m[1];
					return $id > 0 ? $id : null;
				}
				if ( preg_match( '/<img[^>]+src="([^"]+)"/i', $segment, $m ) ) {
					$url = (string) $m[1];
					if ( str_starts_with( $url, 'http' ) ) return $url;
				}
				return null;
			};

			for ( $i = 0; $i < 2; $i ++ ) {
				$segment = $segments[ $i ] ?? '';
				if ( ! $segment ) continue;

				$heading = $extractHeading( $segment );
				$paragraphs = $extractParagraphs( $segment, $i === 0 );
				$content = implode( "\n", $paragraphs );
				
				if ( $i === 1 ) {
					$building = null;
					foreach ( $paragraphs as $p ) {
						if ( strpos( $p, 'Building independence and belonging through care' ) !== false ) {
							$building = $p;
							break;
						}
					}
					if ( $building !== null ) {
						$content = $building;
					} else if ( count( $paragraphs ) >= 2 && mb_strlen( $paragraphs[0] ) <= 120 ) {
						$content = implode( "\n", array_slice( $paragraphs, 1 ) );
					}
				}

				$image_old_attachment_id = null;
				$jsonData = $jsonBlocks[ $i ]['data'] ?? [];
				if ( is_array( $jsonData ) ) {
					$image_old_attachment_id = $extractOldAttachmentRef( $jsonData, $segment );
				}

				if ( $i === 0 ) {
					$out['first']['heading'] = $heading;
					$out['first']['content'] = $content;
					$out['first']['image_old_attachment_id'] = $image_old_attachment_id;
				} else {
					$out['second']['heading'] = $heading;
					$out['second']['content'] = $content;
					$out['second']['image_old_attachment_id'] = $image_old_attachment_id;
				}
			}

			return $out;
		}

		/**
		 * Retrieve lists of expertise and facilities logic from Gutenberg block parsing.
		 *
		 * @param string $postContent
		 * @return array
		 */
		private static function extractListIconTitles( string $postContent ): array {
			$out = [ 'our_expertise' => [], 'facilities_and_features' => [] ];
			$blocks = self::extractAllAcfBlockJson( $postContent, 'acf/list-icon' );
			
			foreach ( $blocks as $idx => $block ) {
				$data = $block['data'] ?? [];
				if ( ! is_array( $data ) ) continue;

				$items = [];
				$n = isset( $data['list_items'] ) ? (int) $data['list_items'] : 0;
				if ( $n > 0 ) {
					for ( $i = 0; $i < $n; $i ++ ) {
						$key = 'list_items_' . $i . '_item_title';
						if ( isset( $data[ $key ] ) ) {
							$items[] = (string) $data[ $key ];
						}
					}
				} else {
					$indexes = [];
					foreach ( $data as $k => $_v ) {
						if ( preg_match( '/^list_items_(\d+)_item_title$/', (string) $k, $m ) ) {
							$indexes[] = (int) $m[1];
						}
					}
					sort( $indexes );
					foreach ( $indexes as $i ) {
						$key = 'list_items_' . $i . '_item_title';
						if ( isset( $data[ $key ] ) ) {
							$items[] = (string) $data[ $key ];
						}
					}
				}

				if ( $idx === 0 ) {
					$out['our_expertise'] = $items;
				} else if ( $idx === 1 ) {
					$out['facilities_and_features'] = $items;
				}
			}

			return $out;
		}

		/**
		 * Normalizer for checkboxes that checks target database setup context.
		 *
		 * @param string $fieldName
		 * @param array $items
		 * @return array
		 */
		private static function normalizeCheckboxValues( string $fieldName, array $items ): array {
			$allowed = self::getCheckboxAllowedValues( $fieldName );
			if ( empty( $allowed ) ) return $items;

			$choiceValueMap = self::buildCheckboxChoiceValueMap( $fieldName );
			if ( empty( $choiceValueMap ) ) {
				$choiceValueMap = [];
				foreach ( $allowed as $opt ) {
					$k = strtolower( trim( (string) $opt ) );
					$choiceValueMap[ $k ] = $opt;
				}
			}

			$bucket = [];
			foreach ( $items as $raw ) {
				$v = trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( $v === '' ) continue;

				$key = strtolower( preg_replace( '/\s+/u', ' ', $v ) );
				$tries = [ $key ];

				if ( $fieldName === 'our_expertise' && $key === 'mental health needs' ) {
					$tries = [ 'mental health', 'mental health needs' ];
				} else if ( $fieldName === 'facilities_and_features' ) {
					if ( in_array( $key, [ '6 ensuite bedrooms', 'ensuite bedrooms' ], true ) ) {
						$tries = [ 'bedrooms' ];
					} else if ( in_array( $key, [ 'beautiful garden', 'small homely garden' ], true ) ) {
						$tries = [ 'large garden' ];
					}
				}

				foreach ( $tries as $t ) {
					if ( isset( $choiceValueMap[ $t ] ) ) {
						$bucket[ (string) $choiceValueMap[ $t ] ] = true;
						break;
					}
				}
			}

			$normalized = [];
			foreach ( $allowed as $option ) {
				if ( isset( $bucket[ (string) $option ] ) ) {
					$normalized[] = $option;
				}
			}

			if ( $fieldName === 'our_expertise' && empty( $normalized ) && ! empty( $items ) ) {
				$map = [
					'autism'                => 'Autism',
					'learning disabilities' => 'Learning Disabilities',
					'mental health'         => 'Mental Health',
					'mental health needs'   => 'Mental Health',
					'complex needs'         => 'Complex Needs',
				];
				$bucket2 = [];
				foreach ( $items as $raw ) {
					$v = trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					if ( $v === '' ) continue;
					$key = strtolower( preg_replace( '/\s+/u', ' ', $v ) );
					if ( isset( $map[ $key ] ) ) {
						$bucket2[ $map[ $key ] ] = true;
					}
				}
				$expectedOrder = [ 'Autism', 'Learning Disabilities', 'Mental Health', 'Complex Needs' ];
				$normalized2 = [];
				foreach ( $expectedOrder as $opt ) {
					if ( isset( $bucket2[ $opt ] ) ) {
						$normalized2[] = $opt;
					}
				}
				$normalized = $normalized2;
			}

			return $normalized;
		}

		private static function buildCheckboxChoiceValueMap( string $fieldName ): array {
			if ( ! function_exists( 'get_field_object' ) ) return [];

			$field = get_field_object( $fieldName );
			$choices = is_array( $field ) ? ( $field['choices'] ?? null ) : null;
			if ( empty( $choices ) || ! is_array( $choices ) ) return [];

			$map = [];
			foreach ( $choices as $value => $label ) {
				$valueNorm = strtolower( preg_replace( '/\s+/u', ' ', trim( (string) $value ) ) );
				$labelNorm = strtolower( preg_replace( '/\s+/u', ' ', trim( (string) $label ) ) );

				if ( $valueNorm !== '' ) $map[ $valueNorm ] = $value;
				if ( $labelNorm !== '' ) $map[ $labelNorm ] = $value;
			}

			return $map;
		}

		private static function getCheckboxAllowedValues( string $fieldName ): array {
			if ( function_exists( 'get_field_object' ) ) {
				$field = get_field_object( $fieldName );
				if ( is_array( $field ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					return array_values( array_keys( $field['choices'] ) );
				}
			}

			if ( $fieldName === 'our_expertise' ) {
				return [ 'Autism', 'Learning Disabilities', 'Mental Health', 'Complex Needs' ];
			}
			if ( $fieldName === 'facilities_and_features' ) {
				return [ 'Bedrooms', 'Mixed Gender', 'Communal Spaces', 'Large Garden' ];
			}
			return [];
		}

		private static function extractContentCards( string $postContent ): array {
			$blocks = self::extractAllAcfBlockJson( $postContent, 'acf/content-cards' );
			if ( empty( $blocks ) ) return [];

			$data = $blocks[0]['data'] ?? [];
			if ( ! is_array( $data ) ) return [];

			$cardsCount = isset( $data['cards'] ) ? (int) $data['cards'] : 0;
			$cards = [];
			
			if ( $cardsCount > 0 ) {
				for ( $i = 0; $i < $cardsCount; $i ++ ) {
					$heading = isset( $data[ 'cards_' . $i . '_card_heading' ] ) ? (string) $data[ 'cards_' . $i . '_card_heading' ] : '';
					$oldId   = isset( $data[ 'cards_' . $i . '_card_image' ] ) ? (int) $data[ 'cards_' . $i . '_card_image' ] : null;
					$cards[] = [ 'heading' => $heading, 'old_attachment_id' => $oldId ?: null ];
				}
				return $cards;
			}

			$indexes = [];
			foreach ( $data as $k => $_v ) {
				if ( preg_match( '/^cards_(\d+)_card_image$/', (string) $k, $m ) ) {
					$indexes[] = (int) $m[1];
				}
			}
			sort( $indexes );

			foreach ( $indexes as $i ) {
				$heading = isset( $data[ 'cards_' . $i . '_card_heading' ] ) ? (string) $data[ 'cards_' . $i . '_card_heading' ] : '';
				$oldId   = isset( $data[ 'cards_' . $i . '_card_image' ] ) ? (int) $data[ 'cards_' . $i . '_card_image' ] : null;
				$cards[] = [ 'heading' => $heading, 'old_attachment_id' => $oldId ?: null ];
			}

			return $cards;
		}

		private static function buildGalleryFromCards( array $cards ): array {
			$usedAttachmentIds = [];

			$findByNeedle = function ( string $needle, string $mustNot = '' ) use ( $cards, &$usedAttachmentIds ): ?int {
				foreach ( $cards as $card ) {
					$hid = $card['old_attachment_id'];
					if ( ! $hid || isset( $usedAttachmentIds[ $hid ] ) ) continue;
					
					$h = mb_strtolower( $card['heading'] );
					$needleLower  = mb_strtolower( $needle );
					$mustNotLower = mb_strtolower( $mustNot );
					
					$hasNeedle  = strpos( $h, $needleLower ) !== false;
					$hasMustNot = $mustNot !== '' && strpos( $h, $mustNotLower ) !== false;
					if ( $hasNeedle && ! $hasMustNot ) {
						$usedAttachmentIds[ $hid ] = true;
						return (int) $hid;
					}
				}
				return null;
			};

			$dining  = $findByNeedle( 'dining room' ) ?? $findByNeedle( 'dining' );
			$living  = $findByNeedle( 'living room' ) ?? $findByNeedle( 'living' );
			$kitchen = $findByNeedle( 'kitchen' );

			$remaining = [];
			foreach ( $cards as $card ) {
				$hid = $card['old_attachment_id'];
				if ( ! $hid || isset( $usedAttachmentIds[ $hid ] ) ) continue;
				$remaining[] = $card;
			}

			$gallery = [];
			$texts   = [];

			if ( $dining )  { $gallery[] = $dining;  $texts[] = 'Dining Room'; }
			if ( $living )  { $gallery[] = $living;  $texts[] = 'Living Room'; }
			if ( $kitchen ) { $gallery[] = $kitchen; $texts[] = 'Kitchen'; }

			foreach ( $remaining as $card ) {
				if ( count( $gallery ) >= 10 ) break;
				$gallery[] = (int) $card['old_attachment_id'];

				$heading = trim( (string) $card['heading'] );
				$heading = preg_replace( '/^Our\\s+/i', '', $heading );
				$texts[] = $heading;
			}

			return [
				'dining_room_old_attachment_id'      => $dining,
				'living_room_old_attachment_id'      => $living,
				'kitchen_old_attachment_id'          => $kitchen,
				'two_column_image_old_attachment_id' => null,
				'gallery_image_old_attachment_ids'   => $gallery,
				'gallery_texts'                      => $texts,
			];
		}

		private static function getExistingStoreIdBySlug( string $slug ): int {
			$posts = get_posts(
				[
					'name'           => $slug,
					'post_type'      => 'wpsl_stores',
					'posts_per_page' => 1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				]
			);
			return ! empty( $posts ) ? (int) $posts[0] : 0;
		}

		private static function getImportSlug( array $location ): string {
			$postName = trim( (string) ( $location['post_name'] ?? '' ) );
			if ( $postName !== '' ) return $postName;

			$title = trim( (string) ( $location['post_title'] ?? '' ) );
			$fallback = sanitize_title( $title );
			if ( $fallback !== '' ) return $fallback;

			$oldId = (int) ( $location['post_id'] ?? 0 );
			if ( $oldId > 0 ) return 'legacy-location-' . $oldId;

			return 'legacy-location-' . wp_generate_password( 8, false, false );
		}

		private static function mapOldStatusToTargetStatus( array $location ): string {
			$old = strtolower( trim( (string) ( $location['post_status'] ?? '' ) ) );
			return $old === 'draft' ? 'draft' : 'publish';
		}

		private static function updateFieldIfNeeded( int $storeId, string $fieldName, $value, bool $isExisting, string $merge ): void {
			if ( ! $isExisting || $merge !== 's2' || self::shouldUpdateField( $storeId, $fieldName, $isExisting, $merge ) ) {
				self::doUpdateField( $storeId, $fieldName, $value );
			}
		}

		private static function shouldUpdateField( int $storeId, string $fieldName, bool $isExisting, string $merge ): bool {
			if ( ! $isExisting ) return true;
			if ( $merge !== 's2' ) return true;

			$current = get_post_meta( $storeId, $fieldName, true );
			$isEmpty = ( $current === '' || $current === null || $current === [] || $current === 0 || $current === '0' );
			
			if ( ! $isEmpty && is_string( $current ) ) {
				$trimmed = trim( (string) $current );
				$isEmpty = in_array( $trimmed, [ '[]', 'a:0:{}' ], true );
				
				if ( $fieldName === 'two_columns_button_link' && str_starts_with( $trimmed, 'a:' ) ) {
					$isEmpty = true;
				}

				if ( $fieldName === 'two_columns_content' ) {
					$hasWhereCare = strpos( $trimmed, 'Where care, community, and independence come together to thrive.' ) !== false;
					$hasBuildingIndependence = strpos( $trimmed, 'Building independence and belonging through care' ) !== false;
					if ( $hasWhereCare && ! $hasBuildingIndependence ) {
						$isEmpty = true;
					}
				}

				if ( $fieldName === 'two_columns_image' ) {
					$firstImg = get_post_meta( $storeId, 'first_section_image', true );
					if ( (int) $firstImg === (int) $trimmed ) $isEmpty = true;
				}
			}

			if ( ! $isEmpty && $fieldName === 'two_columns_image' ) {
				$firstImg = get_post_meta( $storeId, 'first_section_image', true );
				if ( (int) $firstImg > 0 && (int) $current === (int) $firstImg ) $isEmpty = true;
			}

			if ( ! $isEmpty && $fieldName === 'two_columns_heading' ) {
				$firstHeading = get_post_meta( $storeId, 'first_section_heading', true );
				if ( is_string( $current ) && $current !== '' && is_string( $firstHeading ) && trim( $current ) === trim( $firstHeading ) ) {
					$isEmpty = true;
				}
			}

			if ( ! $isEmpty && in_array( $fieldName, [ 'our_expertise', 'facilities_and_features' ], true ) ) {
				$currentValues = is_array( $current ) ? $current : ( maybe_unserialize( $current ) ?: [ $current ] );

				$currentValues = array_values(
					array_filter(
						array_map( static function ( $v ): string { return trim( (string) $v ); }, $currentValues ),
						static function ( string $v ): bool { return $v !== ''; }
					)
				);

				$normalizedCurrent = self::normalizeCheckboxValues( $fieldName, $currentValues );
				if ( count( $normalizedCurrent ) !== count( $currentValues ) ) $isEmpty = true;
			}

			return $isEmpty;
		}

		private static function doUpdateField( int $storeId, string $fieldName, $value ): void {
			if ( function_exists( 'update_field' ) ) {
				update_field( $fieldName, $value, $storeId );
				$current = get_post_meta( $storeId, $fieldName, true );
				$currentEmpty = ( $current === '' || $current === null || $current === [] );
				$intendedEmpty = ( $value === '' || $value === null || $value === [] );
				if ( ! $intendedEmpty && $currentEmpty ) {
					update_post_meta( $storeId, $fieldName, $value );
				}
				return;
			}
			update_post_meta( $storeId, $fieldName, $value );
		}

		private static function sideloadOldAttachmentId( $oldAttachmentRef, array $attachmentUrlMap, array &$uploadedCache, int $storeId, bool $dryRun, array &$report ): ?int {
			if ( empty( $oldAttachmentRef ) ) return null;

			$cacheKey = null;
			$url = '';

			if ( is_numeric( $oldAttachmentRef ) ) {
				$oldAttachmentId = (int) $oldAttachmentRef;
				if ( $oldAttachmentId <= 0 ) return null;
				
				$cacheKey = (string) $oldAttachmentId;
				if ( isset( $uploadedCache[ $cacheKey ] ) ) return (int) $uploadedCache[ $cacheKey ];
				$url = $attachmentUrlMap[ $oldAttachmentId ] ?? '';
			} else if ( is_string( $oldAttachmentRef ) ) {
				$url = (string) $oldAttachmentRef;
				if ( ! str_starts_with( $url, 'http' ) ) return null;
				
				$cacheKey = 'url:' . md5( $url );
				if ( isset( $uploadedCache[ $cacheKey ] ) ) return (int) $uploadedCache[ $cacheKey ];
			}

			if ( ! $url ) {
				if ( class_exists( 'WP_CLI' ) && is_numeric( $oldAttachmentRef ) ) {
					WP_CLI::warning( 'No attachment URL found for old attachment id ' . (int) $oldAttachmentRef );
				}
				$report['missing_attachment_url'] = (int) ( $report['missing_attachment_url'] ?? 0 ) + 1;
				return null;
			}

			if ( $dryRun ) {
				if ( class_exists( 'WP_CLI' ) ) {
					WP_CLI::line( 'Dry run: would sideload attachment (' . $url . ')' );
				}
				return null;
			}

			$path = parse_url( $url, PHP_URL_PATH );
			$filename = $path ? basename( $path ) : 'attachment';
			$desc = 'Imported from old locations: ' . $filename;

			$newId = media_sideload_image( $url, $storeId, $desc, 'id' );
			if ( is_wp_error( $newId ) ) {
				if ( class_exists( 'WP_CLI' ) ) {
					WP_CLI::warning( 'Sideload failed: ' . $newId->get_error_message() );
				}
				$report['sideload_failed'] = (int) ( $report['sideload_failed'] ?? 0 ) + 1;
				return null;
			}

			$uploadedCache[ (string) $cacheKey ] = (int) $newId;
			$report['images_sideloaded'] = (int) ( $report['images_sideloaded'] ?? 0 ) + 1;
			return (int) $newId;
		}

		private static function updatePostMetaIfNeeded( int $storeId, string $metaKey, $value, bool $isExisting, string $merge ): void {
			if ( ! $isExisting || $merge !== 's2' ) {
				update_post_meta( $storeId, $metaKey, $value );
				return;
			}
			$current = get_post_meta( $storeId, $metaKey, true );
			$isEmpty = ( $current === '' || $current === null || $current === [] || $current === 0 || $current === '0' );
			if ( $isEmpty ) update_post_meta( $storeId, $metaKey, $value );
		}

		private static function ensureWpslCategory( int $storeId, string $nicename ): void {
			if ( ! $nicename ) return;
			$taxonomy = 'wpsl_store_category';
			$term = term_exists( $nicename, $taxonomy );
			if ( ! $term ) wp_insert_term( $nicename, $taxonomy, [ 'slug' => $nicename ] );
			wp_set_object_terms( $storeId, [ $nicename ], $taxonomy );
		}

		public static function importFromOldXml( string $oldXml, string $slug, string $merge, bool $dryRun ): array {
			$merge = strtolower( $merge );

			if ( ! $oldXml || ! file_exists( $oldXml ) ) {
				throw new RuntimeException( 'Missing or unreadable --old-xml=' . $oldXml );
			}

			$slug = trim( (string) $slug );
			$parser = new IVolve_WXR_Store_Locator_Parser( $oldXml );
			$attachmentUrlMap = $parser->loadAttachmentUrlMap();

			$uploadedAttachmentIdCache = [];
			$report = [
				'locations_scanned'      => 0,
				'stores_processed'       => 0,
				'stores_created'         => 0,
				'stores_existing'        => 0,
				'missing_attachment_url' => 0,
				'sideload_failed'        => 0,
				'missing_image_mapping'  => 0,
				'images_sideloaded'      => 0,
				'debug_dry_run'          => $dryRun ? 1 : 0,
				'debug_merge'            => (string) $merge,
			];

			$parser->iterateLocations(
				function ( array $location ) use ( $slug, $dryRun, $merge, $attachmentUrlMap, &$uploadedAttachmentIdCache, &$report ) {
					$report['locations_scanned'] = (int) ( $report['locations_scanned'] ?? 0 ) + 1;

					$oldPostName = self::getImportSlug( $location );
					if ( $slug && $oldPostName !== $slug ) return;

					$report['stores_processed'] = (int) ( $report['stores_processed'] ?? 0 ) + 1;
					$payload = self::mapLocationPayload( $location );

					$storeId    = self::getExistingStoreIdBySlug( $oldPostName );
					$isExisting = $storeId > 0;

					if ( $dryRun ) return;

					if ( ! $isExisting ) {
						$createdId = wp_insert_post(
							[
								'post_type'    => 'wpsl_stores',
								'post_status'  => self::mapOldStatusToTargetStatus( $location ),
								'post_name'    => $oldPostName,
								'post_title'   => (string) ( $location['post_title'] ?? $oldPostName ),
								'post_content' => ( function () use ( $payload ) {
									$desc = (string) ( $payload['short_description'] ?? '' );
									return ! $desc ? '' : '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
								} )(),
							],
							true
						);

						if ( is_wp_error( $createdId ) ) return;

						$storeId = (int) $createdId;
						self::ensureWpslCategory( $storeId, (string) ( $payload['taxonomy_category_nicename'] ?? '' ) );
						$report['stores_created'] = (int) ( $report['stores_created'] ?? 0 ) + 1;
						$isExisting = true;
					} else {
						$report['stores_existing'] = (int) ( $report['stores_existing'] ?? 0 ) + 1;
					}

					if ( ! $storeId ) return;

					// WPSL meta map
					foreach ( (array) ( $payload['wpsl'] ?? [] ) as $metaKey => $metaValue ) {
						self::updatePostMetaIfNeeded( (int) $storeId, (string) $metaKey, $metaValue, $isExisting, $merge );
					}

					self::updateFieldIfNeeded( (int) $storeId, 'number_of_bedrooms', (int) ( $payload['bedrooms'] ?? 0 ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'cqc_id', (string) ( $payload['cqc_id'] ?? '' ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'first_section_heading', (string) ( $payload['first_section_heading'] ?? '' ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'first_section_content', (string) ( $payload['first_section_content'] ?? '' ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'two_columns_heading', (string) ( $payload['two_columns_heading'] ?? '' ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'two_columns_content', (string) ( $payload['two_columns_content'] ?? '' ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'two_columns_button_text', (string) ( $payload['two_columns_button_text'] ?? '' ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'two_columns_button_link', (string) ( $payload['two_columns_button_link'] ?? '' ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'walkthrough_360', (string) ( $payload['walkthrough_360'] ?? '' ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'our_expertise', (array) ( $payload['our_expertise'] ?? [] ), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'facilities_and_features', (array) ( $payload['facilities_and_features'] ?? [] ), $isExisting, $merge );

					$oldRefs = (array) ( $payload['old_image_refs'] ?? [] );
					$imageFields = [
						'first_section_image' => $oldRefs['first_section_image'] ?? null,
						'two_columns_image'   => $oldRefs['two_columns_image'] ?? null,
						'kitchen'             => $oldRefs['kitchen'] ?? null,
						'living_room'         => $oldRefs['living_room'] ?? null,
						'dining_room'         => $oldRefs['dining_room'] ?? null,
					];

					foreach ( $imageFields as $fieldName => $oldAttachmentId ) {
						if ( ! self::shouldUpdateField( (int) $storeId, (string) $fieldName, $isExisting, $merge ) ) continue;

						if ( ! $oldAttachmentId ) {
							$report['missing_image_mapping'] = (int) ( $report['missing_image_mapping'] ?? 0 ) + 1;
							continue;
						}

						$newAttachmentId = self::sideloadOldAttachmentId( $oldAttachmentId, $attachmentUrlMap, $uploadedAttachmentIdCache, (int) $storeId, $dryRun, $report );
						if ( $newAttachmentId ) {
							self::doUpdateField( (int) $storeId, (string) $fieldName, (int) $newAttachmentId );

							if ( $fieldName === 'first_section_image' && function_exists( 'set_post_thumbnail' ) ) {
								$currentThumb = get_post_meta( (int) $storeId, '_thumbnail_id', true );
								$isThumbEmpty = ( $currentThumb === '' || $currentThumb === null || (int) $currentThumb === 0 );
								if ( ! $isExisting || $merge !== 's2' || $isThumbEmpty ) {
									set_post_thumbnail( (int) $storeId, (int) $newAttachmentId );
								}
							}
						}
					}

					// Strict Thumbnail Check & Fallback
					if ( function_exists( 'set_post_thumbnail' ) ) {
						$currentThumb = get_post_meta( (int) $storeId, '_thumbnail_id', true );
						$isThumbEmpty = ( $currentThumb === '' || $currentThumb === null || (int) $currentThumb === 0 );
						if ( $isThumbEmpty ) {
							$acfThumb = (int) get_post_meta( (int) $storeId, 'first_section_image', true );
							if ( $acfThumb > 0 ) {
								set_post_thumbnail( (int) $storeId, $acfThumb );
							} else {
								// Revert to strict original thumbnail fallback requested
								$origThumbOldId = $payload['original_thumbnail_id'] ?? null;
								if ( $origThumbOldId ) {
									$newThumbId = self::sideloadOldAttachmentId( $origThumbOldId, $attachmentUrlMap, $uploadedAttachmentIdCache, (int) $storeId, $dryRun, $report );
									if ( $newThumbId ) {
										set_post_thumbnail( (int) $storeId, $newThumbId );
									}
								}
							}
						}
					}

					// Gallery Map
					$galleryImages = (array) ( $oldRefs['gallery_images'] ?? [] );
					$galleryTexts  = (array) ( $oldRefs['gallery_texts'] ?? [] );
					for ( $i = 1; $i <= 10; $i ++ ) {
						$imgIndex = $i - 1;
						$imgOldAttachmentId = $galleryImages[ $imgIndex ] ?? null;
						$galleryImageField  = 'gallery_image_' . $i;
						$galleryTextField   = 'gallery_text_' . $i;

						$slotText = isset( $galleryTexts[ $imgIndex ] ) ? (string) $galleryTexts[ $imgIndex ] : '';

						if ( self::shouldUpdateField( (int) $storeId, $galleryTextField, $isExisting, $merge ) ) {
							if ( $slotText !== '' ) {
								self::doUpdateField( (int) $storeId, $galleryTextField, $slotText );
							} else {
								$report['missing_image_mapping'] = (int) ( $report['missing_image_mapping'] ?? 0 ) + 1;
							}
						}

						if ( self::shouldUpdateField( (int) $storeId, $galleryImageField, $isExisting, $merge ) ) {
							$newAttachmentId = self::sideloadOldAttachmentId( $imgOldAttachmentId, $attachmentUrlMap, $uploadedAttachmentIdCache, (int) $storeId, $dryRun, $report );
							if ( $newAttachmentId ) {
								self::doUpdateField( (int) $storeId, $galleryImageField, (int) $newAttachmentId );
							}
						}
					}

					// Description Block
					$desc = (string) ( $payload['short_description'] ?? '' );
					if ( $desc ) {
						$desiredContent = '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
						if ( ! $isExisting ) {
							wp_update_post( [ 'ID' => (int) $storeId, 'post_content' => $desiredContent ], true );
						} else {
							$currentContent = (string) get_post_field( 'post_content', (int) $storeId );
							if ( $merge !== 's2' || trim( $currentContent ) === '' ) {
								wp_update_post( [ 'ID' => (int) $storeId, 'post_content' => $desiredContent ], true );
							}
						}
					}
				}
			);

			return $report;
		}

		public static function run( array $args, array $assoc_args ): void {
			$oldXml = (string) ( $assoc_args['old-xml'] ?? '' );
			if ( ! $oldXml || ! file_exists( $oldXml ) ) {
				WP_CLI::error( 'Missing or unreadable --old-xml=' . $oldXml );
				return;
			}

			$slug   = isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '';
			$merge  = strtolower( (string) ( $assoc_args['merge'] ?? 's2' ) );
			$dryRun = ! empty( $assoc_args['dry-run'] );

			WP_CLI::line( 'Beginning locations import...' );

			$report = self::importFromOldXml( $oldXml, $slug, $merge, $dryRun );

			WP_CLI::line( 'Import summary:' );
			WP_CLI::line( ' - stores_processed: ' . (int) ( $report['stores_processed'] ?? 0 ) );
			WP_CLI::line( ' - stores_created: ' . (int) ( $report['stores_created'] ?? 0 ) );
			WP_CLI::line( ' - stores_existing: ' . (int) ( $report['stores_existing'] ?? 0 ) );
			WP_CLI::line( ' - images_sideloaded: ' . (int) ( $report['images_sideloaded'] ?? 0 ) );
		}
	}
}

// Register command only when WP-CLI is present.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'ivolve locations store-locator-import', [ IVolve_Store_Locator_Import_Command::class, 'run' ] );
}

// Dashboard UI
if ( is_admin() ) {
	add_filter( 'upload_mimes', function ( $mimes ) {
		$mimes['xml'] = 'application/xml';
		return $mimes;
	});

	add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename, $mimes ) {
		if ( strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) ) === 'xml' ) {
			$data['ext']  = 'xml';
			$data['type'] = 'application/xml';
		}
		return $data;
	}, 10, 4 );

	add_action( 'admin_menu', function () {
		add_menu_page( 'Store Locator Import', 'Store Locator Import', 'manage_options', 'ivolve-store-locator-import', function () {
			if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
			$nonce = wp_create_nonce( 'ivolve_store_locator_import' );
			echo '<div class="wrap"><h1>Store Locator Import</h1>';
			echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="ivolve_store_locator_importer" /><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';
			echo '<table class="form-table" role="presentation">';
			echo '<tr><th scope="row"><label>Old site WXR XML (upload)</label></th><td><input type="file" name="old_xml_upload" accept=".xml" required /></td></tr>';
			echo '<tr><th scope="row"><label>Slug filter (optional)</label></th><td><input type="text" name="slug" /></td></tr>';
			echo '<tr><th scope="row"><label>Merge strategy</label></th><td><select name="merge"><option value="s2" selected>Fill blanks only (s2)</option></select></td></tr>';
			echo '<tr><th scope="row"><label>Dry run</label></th><td><label><input type="checkbox" name="dry_run" value="1" checked /> Scan only</label></td></tr>';
			echo '</table><p><button type="submit" class="button button-primary">Run Import</button></p></form></div>';
		});
	});

	add_action( 'admin_post_ivolve_store_locator_importer', function () {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
		check_admin_referer( 'ivolve_store_locator_import' );

		$slug   = isset( $_POST['slug'] ) ? (string) $_POST['slug'] : '';
		$merge  = isset( $_POST['merge'] ) ? (string) $_POST['merge'] : 's2';
		$dryRun = ! empty( $_POST['dry_run'] );

		if ( empty( $_FILES['old_xml_upload']['tmp_name'] ) ) wp_die( 'Missing uploaded XML file.' );

		$handled = wp_handle_upload( $_FILES['old_xml_upload'], [ 'test_form' => false, 'mimes' => [ 'xml' => 'application/xml, text/xml, text/plain' ] ] );
		if ( isset( $handled['error'] ) ) wp_die( 'Upload failed: ' . esc_html( (string) $handled['error'] ) );

		@set_time_limit( 0 );

		try {
			$report = IVolve_Store_Locator_Import_Command::importFromOldXml( $handled['file'], $slug, $merge, $dryRun );
		} catch ( Throwable $e ) {
			wp_die( 'Import failed: ' . esc_html( $e->getMessage() ) );
		}

		echo '<div class="wrap"><h1>Import Result</h1><ul>';
		foreach ( (array) $report as $k => $v ) echo '<li><strong>' . esc_html( (string) $k ) . ':</strong> ' . esc_html( (string) $v ) . '</li>';
		echo '</ul></div>';
	});
}