<?php
/**
 * IVolve Store Locator migration helper.
 *
 * Plugin Name: IVolve Store Locator Importer
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Description: Imports old `locations` CPT content into new `wpsl_stores` Store Locator fields (ACF + images).
 * Version: 0.2.1
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
		 * Constructor.
		 * * @param string $wxrPath Path to the WXR file.
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
					'post_id' => $postId,
					'post_name' => $postName,
					'post_status' => $postStatus,
					'post_title' => $postTitle,
					'post_content' => $postContent,
					'categories_by_domain' => $categoriesByDomain,
					'meta' => $meta,
				];

				$onLocation( $payload );
			}
		}

		/**
		 * Loads and parses the XML file securely.
		 * * @return object SimpleXMLElement object.
		 * @throws RuntimeException If the file cannot be loaded.
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
		 * Supports both recent ACF block schemas and older legacy meta structures.
		 * * @param array $location Parsed WXR item data.
		 * @return array Store Locator target fields.
		 */
		private static function mapLocationPayload( array $location ): array {
			$postContent = (string) ( $location['post_content'] ?? '' );
			$meta = (array) ( $location['meta'] ?? [] );

			// Address + city/zip/country come from the serialized `location` meta.
			$wpsl = [
				'wpsl_address' => '',
				'wpsl_city' => '',
				'wpsl_zip' => '',
				'wpsl_country' => '',
			];
			$serializedLocation = $meta['location'] ?? '';
			$locationArr = is_string( $serializedLocation ) ? @unserialize( $serializedLocation ) : false;
			if ( is_array( $locationArr ) ) {
				$streetNumber = (string) ( $locationArr['street_number'] ?? '' );
				$streetName = (string) ( $locationArr['street_name'] ?? '' );
				$wpsl['wpsl_address'] = trim( $streetNumber . ' ' . $streetName );
				$wpsl['wpsl_city'] = (string) ( $locationArr['city'] ?? '' );
				$wpsl['wpsl_zip'] = (string) ( $locationArr['post_code'] ?? '' );
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

			// CQC id: `acf/cqc-widget` or legacy meta fallback.
			$cqcId = '';
			$cqcBlocks = self::extractAllAcfBlockJson( $postContent, 'acf/cqc-widget' );
			if ( ! empty( $cqcBlocks ) ) {
				$first = $cqcBlocks[0];
				$data = $first['data'] ?? [];
				$cqcId = (string) ( $data['cqc_id'] ?? '' );
			}
			if ( empty( $cqcId ) && ! empty( $meta['properties_0_property_sidebar_cqc_id'] ) ) {
				$cqcId = (string) $meta['properties_0_property_sidebar_cqc_id'];
			}

			// Image-column blocks for the first section and the "two columns" section.
			$twoImageColumns = self::extractImageColumnSections( $postContent );

			// Content cards: `acf/content-cards` blocks -> kitchen/living/dining and gallery slots.
			$cards = self::extractContentCards( $postContent );

			$orderedGallery = self::buildGalleryFromCards( $cards );
			$heroImageOldId = self::extractPageHeaderHeroImageAttachmentId( $postContent );

			// Fallback: Populate missing images from legacy thumbnail or properties gallery meta.
			if ( ! $heroImageOldId && ! empty( $meta['_thumbnail_id'] ) ) {
				$heroImageOldId = (int) $meta['_thumbnail_id'];
			}
			$legacyImages = ! empty( $meta['properties_0_property_main_content_images'] ) 
				? maybe_unserialize( $meta['properties_0_property_main_content_images'] ) : [];
			
			if ( ! $heroImageOldId && is_array( $legacyImages ) && ! empty( $legacyImages ) ) {
				$heroImageOldId = (int) reset( $legacyImages );
			}
			if ( empty( $orderedGallery['gallery_image_old_attachment_ids'] ) && is_array( $legacyImages ) && ! empty( $legacyImages ) ) {
				$orderedGallery['gallery_image_old_attachment_ids'] = array_map( 'intval', array_values( $legacyImages ) );
			}

			// Fallback: Legacy formats utilizing plain Gutenberg or properties meta.
			if ( trim( $twoImageColumns['first']['content'] ?? '' ) === '' ) {
				$legacyText = '';
				if ( ! empty( $meta['properties_0_property_main_content_property_main_text'] ) ) {
					$legacyText = wp_strip_all_tags( (string) $meta['properties_0_property_main_content_property_main_text'] );
				} else if ( trim( $postContent ) !== '' ) {
					if ( preg_match_all( '/\s*<p[^>]*>(.*?)<\/p>\s*/s', $postContent, $matches ) ) {
						$parts = [];
						foreach ( $matches[1] as $pHtml ) {
							$text = wp_strip_all_tags( $pHtml );
							$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
							$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
							if ( $text !== '' ) {
								$parts[] = $text;
							}
						}
						$legacyText = implode( "\n\n", $parts );
					} else {
						$legacyText = trim( wp_strip_all_tags( $postContent ) );
					}
				}

				if ( $legacyText !== '' ) {
					$twoImageColumns['first']['content'] = trim( $legacyText );
				}
			}

			// Expertise/features: `acf/list-icon` blocks.
			$listIconTitles = self::extractListIconTitles( $postContent );
			
			$ourExpertise = self::normalizeOurExpertiseFromLabels( (array) ( $listIconTitles['our_expertise'] ?? [] ) );
			$facilitiesAndFeatures = self::normalizeCheckboxValues( 'facilities_and_features', (array) ( $listIconTitles['facilities_and_features'] ?? [] ) );
			
			if ( is_int( $bedrooms ) && $bedrooms > 0 && ! in_array( 'Bedrooms', $facilitiesAndFeatures, true ) ) {
				$facilitiesAndFeatures[] = 'Bedrooms';
			}

			$twoColumnsButton = self::extractTwoColumnsButtonFromContent( $postContent );
			$walkthrough360 = self::extractWalkthrough360FromContent( $postContent );
			$twoColumnsHeading = (string) ( $twoImageColumns['second']['heading'] ?? '' );
			if ( trim( $twoColumnsHeading ) === '' ) {
				$twoColumnsHeading = (string) ( $twoImageColumns['first']['heading'] ?? '' );
			}
			$twoColumnsContent = (string) ( $twoImageColumns['second']['content'] ?? '' );
			if ( trim( $twoColumnsContent ) === '' ) {
				$twoColumnsContent = (string) ( $twoImageColumns['first']['content'] ?? '' );
			}

			$oldImageRefs = [
				'first_section_image' => $heroImageOldId,
				'two_columns_image' => $twoImageColumns['second']['image_old_attachment_id'] ?? $twoImageColumns['first']['image_old_attachment_id'] ?? null,
				'kitchen' => $orderedGallery['kitchen_old_attachment_id'] ?? null,
				'living_room' => $orderedGallery['living_room_old_attachment_id'] ?? null,
				'dining_room' => $orderedGallery['dining_room_old_attachment_id'] ?? null,
				'gallery_images' => $orderedGallery['gallery_image_old_attachment_ids'] ?? [],
				'gallery_texts' => $orderedGallery['gallery_texts'] ?? [],
			];

			$taxonomyCategory = '';
			$catsByDomain = (array) ( $location['categories_by_domain'] ?? [] );
			if ( ! empty( $catsByDomain['location_type'] ) && is_array( $catsByDomain['location_type'] ) ) {
				$taxonomyCategory = (string) ( $catsByDomain['location_type'][0] ?? '' );
			}

			$locationDescription = (string) ( $meta['location_description'] ?? '' );
			if ( ! $locationDescription ) {
				$locationDescription = (string) ( $meta['location'] ?? '' );
			}

			return [
				'taxonomy_category_nicename' => $taxonomyCategory,
				'wpsl' => $wpsl,
				'bedrooms' => $bedrooms,
				'cqc_id' => $cqcId,
				'first_section_heading' => (string) ( $twoImageColumns['first']['heading'] ?? '' ),
				'first_section_content' => (string) ( $twoImageColumns['first']['content'] ?? '' ),
				'two_columns_heading' => $twoColumnsHeading,
				'two_columns_content' => $twoColumnsContent,
				'our_expertise' => $ourExpertise,
				'facilities_and_features' => $facilitiesAndFeatures,
				'two_columns_button_text' => (string) ( $twoColumnsButton['text'] ?? '' ),
				'two_columns_button_link' => (string) ( $twoColumnsButton['link']['url'] ?? '' ),
				'walkthrough_360' => (string) $walkthrough360,
				'short_description' => $locationDescription,
				'old_image_refs' => $oldImageRefs,
			];
		}

		/**
		 * Extract ACF JSON data objects from blocks.
		 *
		 * @param string $postContent Content string.
		 * @param string $blockSlug Block identifier.
		 * @return array List of decoded JSON objects
		 */
		private static function extractAllAcfBlockJson( string $postContent, string $blockSlug ): array {
			$out = [];
			$needle = '\s*<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $segment, $m ) ) {
				return trim( html_entity_decode( wp_strip_all_tags( $m[1] ) ) );
			}
			if ( preg_match( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $segment, $m ) ) {
				return trim( html_entity_decode( wp_strip_all_tags( $m[1] ) ) );
			}
			return '';
		}

		/**
		 * Flattens the paragraph parser extraction mapping.
		 *
		 * @param string $segment Extracted segment.
		 * @param bool $truncateAtFirstButton Cutoff validation criteria.
		 * @return array Valid paragraphs.
		 */
		private static function parseImageColumnParagraphs( string $segment, bool $truncateAtFirstButton ): array {
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
		}

		/**
		 * Parses the internal attachment references.
		 *
		 * @param array $jsonData Sourced chunk parameters.
		 * @param string $segment DOM wrapper.
		 * @return int|null Valid identifier link.
		 */
		private static function parseImageColumnOldAttachmentRef( array $jsonData, string $segment ): ?int {
			$candidates = [
				'image', 'image_id', 'imageId', 'image_file', 'imageFile', 
				'image_old_attachment_id', 'image_attachment_id', 'image_old_id', 
				'attachment_id', 'old_attachment_id'
			];

			foreach ( $candidates as $key ) {
				if ( ! array_key_exists( $key, $jsonData ) ) {
					continue;
				}
				$val = $jsonData[ $key ];

				if ( is_numeric( $val ) ) {
					$id = (int) $val;
					if ( $id > 0 ) {
						return $id;
					}
					continue;
				}

				if ( is_string( $val ) && is_numeric( $val ) ) {
					$id = (int) $val;
					if ( $id > 0 ) {
						return $id;
					}
					continue;
				}

				if ( is_array( $val ) ) {
					foreach ( [ 'id', 'ID', 'attachment_id', 'old_attachment_id' ] as $subKey ) {
						if ( isset( $val[ $subKey ] ) && is_numeric( $val[ $subKey ] ) ) {
							$id = (int) $val[ $subKey ];
							if ( $id > 0 ) {
								return $id;
							}
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
				if ( str_starts_with( $url, 'http' ) ) {
					return $url;
				}
			}

			return null;
		}

		/**
		 * Extract the first two `acf/image-column` blocks: first section and the "two columns" section.
		 *
		 * @param string $postContent Target content.
		 * @return array Two column extraction array format.
		 */
		private static function extractImageColumnSections( string $postContent ): array {
			$jsonBlocks = self::extractAllAcfBlockJson( $postContent, 'acf/image-column' );

			$out = [
				'first' => [
					'heading' => '',
					'content' => '',
					'image_old_attachment_id' => null,
				],
				'second' => [
					'heading' => '',
					'content' => '',
					'image_old_attachment_id' => null,
				],
			];

			$segments = [];
			$offset = 0;
			$openTag = '';
			while ( true ) {
				$start = strpos( $postContent, $openTag, $offset );
				if ( $start === false ) {
					break;
				}
				$end = strpos( $postContent, $closeTag, $start );
				if ( $end === false ) {
					$end = strlen( $postContent );
				} else {
					$end += strlen( $closeTag );
				}

				$segments[] = substr( $postContent, $start, $end - $start );
				$offset = $end + 1;
			}

			for ( $i = 0; $i < 2; $i ++ ) {
				$segment = $segments[ $i ] ?? '';
				if ( ! $segment ) {
					continue;
				}

				$heading = self::parseImageColumnHeading( $segment );
				$paragraphs = self::parseImageColumnParagraphs( $segment, $i === 0 );

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
					$image_old_attachment_id = self::parseImageColumnOldAttachmentRef( $jsonData, $segment );
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
		 * Extracts expertise titles recursively.
		 * * @param string $postContent String content body.
		 * @return array Extracted feature titles.
		 */
		private static function extractListIconTitles( string $postContent ): array {
			$out = [
				'our_expertise' => [],
				'facilities_and_features' => [],
			];

			$blocks = self::extractAllAcfBlockJson( $postContent, 'acf/list-icon' );
			
			foreach ( $blocks as $idx => $block ) {
				$data = $block['data'] ?? [];
				if ( ! is_array( $data ) ) {
					continue;
				}

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
		 * Normalize list-icon titles so they match ACF checkbox choices exactly.
		 *
		 * @param string $fieldName ACF attribute.
		 * @param array $items Extracted arrays.
		 * @return array
		 */
		private static function normalizeCheckboxValues( string $fieldName, array $items ): array {
			$allowed = self::getCheckboxAllowedValues( $fieldName );
			if ( empty( $allowed ) ) {
				return $items;
			}

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
				if ( $v === '' ) {
					continue;
				}

				$key = strtolower( preg_replace( '/\s+/u', ' ', $v ) );
				$tries = [ $key ];

				if ( $fieldName === 'our_expertise' ) {
					if ( $key === 'mental health needs' ) {
						$tries = [ 'mental health', 'mental health needs' ];
					}
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
					'autism' => 'Autism',
					'learning disabilities' => 'Learning Disabilities',
					'mental health' => 'Mental Health',
					'mental health needs' => 'Mental Health',
					'complex needs' => 'Complex Needs',
				];
				$bucket2 = [];
				foreach ( $items as $raw ) {
					$v = trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					if ( $v === '' ) {
						continue;
					}
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

		/**
		 * Build mapping of both checkbox labels and choice keys to the stored checkbox value.
		 *
		 * @param string $fieldName Subject name.
		 * @return array map of normalized string -> stored value
		 */
		private static function buildCheckboxChoiceValueMap( string $fieldName ): array {
			if ( ! function_exists( 'get_field_object' ) ) {
				return [];
			}

			$field = get_field_object( $fieldName );
			$choices = is_array( $field ) ? ( $field['choices'] ?? null ) : null;
			if ( empty( $choices ) || ! is_array( $choices ) ) {
				return [];
			}

			$map = [];
			foreach ( $choices as $value => $label ) {
				$valueNorm = strtolower( preg_replace( '/\s+/u', ' ', trim( (string) $value ) ) );
				$labelNorm = strtolower( preg_replace( '/\s+/u', ' ', trim( (string) $label ) ) );

				if ( $valueNorm !== '' ) {
					$map[ $valueNorm ] = $value;
				}
				if ( $labelNorm !== '' ) {
					$map[ $labelNorm ] = $value;
				}
			}

			return $map;
		}

		/**
		 * Get values allowed for choices based on ACF configuration.
		 * * @param string $fieldName Reference key.
		 * @return array List of valid choices.
		 */
		private static function getCheckboxAllowedValues( string $fieldName ): array {
			if ( function_exists( 'get_field_object' ) ) {
				$field = get_field_object( $fieldName );
				if ( is_array( $field ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$choices = $field['choices'];
					return array_values( array_keys( $choices ) );
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

		/**
		 * Extracts `acf/content-cards` details array.
		 * * @param string $postContent Encoded block.
		 * @return array List of content card representations.
		 */
		private static function extractContentCards( string $postContent ): array {
			$blocks = self::extractAllAcfBlockJson( $postContent, 'acf/content-cards' );
			if ( empty( $blocks ) ) {
				return [];
			}

			$data = $blocks[0]['data'] ?? [];
			if ( ! is_array( $data ) ) {
				return [];
			}

			$cardsCount = isset( $data['cards'] ) ? (int) $data['cards'] : 0;
			$cards = [];
			
			if ( $cardsCount > 0 ) {
				for ( $i = 0; $i < $cardsCount; $i ++ ) {
					$heading = isset( $data[ 'cards_' . $i . '_card_heading' ] ) ? (string) $data[ 'cards_' . $i . '_card_heading' ] : '';
					$oldId = isset( $data[ 'cards_' . $i . '_card_image' ] ) ? (int) $data[ 'cards_' . $i . '_card_image' ] : null;
					$cards[] = [
						'heading' => $heading,
						'old_attachment_id' => $oldId ?: null,
					];
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
				$oldId = isset( $data[ 'cards_' . $i . '_card_image' ] ) ? (int) $data[ 'cards_' . $i . '_card_image' ] : null;
				$cards[] = [
					'heading' => $heading,
					'old_attachment_id' => $oldId ?: null,
				];
			}

			return $cards;
		}

		/**
		 * Filters the given cards to locate a targeted room type.
		 *
		 * @param string $needle String context.
		 * @param string $mustNot Unwanted contextual overlap string.
		 * @param array $cards Validated block container.
		 * @param array &$usedAttachmentIds Assigned mappings array logic schema definition.
		 * @return int|null Mapped resource payload valid.
		 */
		private static function findGalleryCardByNeedle( string $needle, string $mustNot, array $cards, array &$usedAttachmentIds ): ?int {
			foreach ( $cards as $card ) {
				$hid = $card['old_attachment_id'];
				if ( ! $hid || isset( $usedAttachmentIds[ $hid ] ) ) {
					continue;
				}
				$h = mb_strtolower( $card['heading'] );
				$needleLower = mb_strtolower( $needle );
				$mustNotLower = mb_strtolower( $mustNot );
				$hasNeedle = strpos( $h, $needleLower ) !== false;
				$hasMustNot = $mustNot !== '' && strpos( $h, $mustNotLower ) !== false;
				if ( $hasNeedle && ! $hasMustNot ) {
					$usedAttachmentIds[ $hid ] = true;
					return (int) $hid;
				}
			}
			return null;
		}

		/**
		 * Determine which card images become kitchen/living/dining and which populate gallery slots.
		 *
		 * @param array $cards List of extracted content cards arrays payload representations container structures object schemas parameters.
		 * @return array Sorted target arrays mapping outputs parameters schemas structures representations block formats properties schemas objects keys properties validation logic formats strings objects schemas representations outputs outputs.
		 */
		private static function buildGalleryFromCards( array $cards ): array {
			$usedAttachmentIds = [];

			$dining = self::findGalleryCardByNeedle( 'dining room', '', $cards, $usedAttachmentIds ) ?? self::findGalleryCardByNeedle( 'dining', '', $cards, $usedAttachmentIds );
			$living = self::findGalleryCardByNeedle( 'living room', '', $cards, $usedAttachmentIds ) ?? self::findGalleryCardByNeedle( 'living', '', $cards, $usedAttachmentIds );
			$kitchen = self::findGalleryCardByNeedle( 'kitchen', '', $cards, $usedAttachmentIds );

			$remaining = [];
			foreach ( $cards as $card ) {
				$hid = $card['old_attachment_id'];
				if ( ! $hid ) {
					continue;
				}
				if ( isset( $usedAttachmentIds[ $hid ] ) ) {
					continue;
				}
				$remaining[] = $card;
			}

			$gallery = [];
			$texts = [];

			if ( $dining ) {
				$gallery[] = $dining;
				$texts[] = 'Dining Room';
			}
			if ( $living ) {
				$gallery[] = $living;
				$texts[] = 'Living Room';
			}
			if ( $kitchen ) {
				$gallery[] = $kitchen;
				$texts[] = 'Kitchen';
			}

			foreach ( $remaining as $card ) {
				if ( count( $gallery ) >= 10 ) {
					break;
				}
				$gallery[] = (int) $card['old_attachment_id'];

				$heading = trim( (string) $card['heading'] );
				$heading = preg_replace( '/^Our\\s+/i', '', $heading );
				$texts[] = $heading;
			}

			$heroImageOldId = null;

			return [
				'dining_room_old_attachment_id' => $dining,
				'living_room_old_attachment_id' => $living,
				'kitchen_old_attachment_id' => $kitchen,
				'two_column_image_old_attachment_id' => $heroImageOldId,
				'gallery_image_old_attachment_ids' => $gallery,
				'gallery_texts' => $texts,
			];
		}

		/**
		 * Fetch corresponding native Store ID based on specific permalink slug.
		 * * @param string $slug Valid location slug identifier.
		 * @return int Target WP ID.
		 */
		private static function getExistingStoreIdBySlug( string $slug ): int {
			$posts = get_posts(
				[
					'name' => $slug,
					'post_type' => 'wpsl_stores',
					'posts_per_page' => 1,
					'post_status' => 'any',
					'fields' => 'ids',
				]
			);
			return ! empty( $posts ) ? (int) $posts[0] : 0;
		}

		/**
		 * Determines destination title slug based off of XML parsed post variables.
		 * * @param array $location Post context.
		 * @return string Valid slug.
		 */
		private static function getImportSlug( array $location ): string {
			$postName = trim( (string) ( $location['post_name'] ?? '' ) );
			if ( $postName !== '' ) {
				return $postName;
			}

			$title = trim( (string) ( $location['post_title'] ?? '' ) );
			$fallback = sanitize_title( $title );
			if ( $fallback !== '' ) {
				return $fallback;
			}

			$oldId = (int) ( $location['post_id'] ?? 0 );
			if ( $oldId > 0 ) {
				return 'legacy-location-' . $oldId;
			}

			return 'legacy-location-' . wp_generate_password( 8, false, false );
		}

		/**
		 * Transcribes legacy post statuses into valid target flags.
		 * * @param array $location WP post object definition.
		 * @return string Mapped target status.
		 */
		private static function mapOldStatusToTargetStatus( array $location ): string {
			$old = strtolower( trim( (string) ( $location['post_status'] ?? '' ) ) );
			if ( $old === 'draft' ) {
				return 'draft';
			}
			return 'publish';
		}

		/**
		 * Safely delegates standard field insertion alongside defined override handling parameters.
		 * * @param int $storeId Valid active post container.
		 * @param string $fieldName Metadata string accessor.
		 * @param mixed $value Insertion value representation.
		 * @param bool $isExisting Signals duplicate status limits.
		 * @param string $merge Processing constraint flag.
		 */
		private static function updateFieldIfNeeded( int $storeId, string $fieldName, $value, bool $isExisting, string $merge ): void {
			if ( ! $isExisting ) {
				self::doUpdateField( $storeId, $fieldName, $value );
				return;
			}

			if ( $merge !== 's2' ) {
				self::doUpdateField( $storeId, $fieldName, $value );
				return;
			}

			if ( self::shouldUpdateField( $storeId, $fieldName, $isExisting, $merge ) ) {
				self::doUpdateField( $storeId, $fieldName, $value );
			}
		}

		/**
		 * Pre-evaluates field applicability based upon configured write boundaries.
		 * * @param int $storeId Subject container context.
		 * @param string $fieldName Metadata map reference.
		 * @param bool $isExisting Whether item is a fresh save vs rewrite.
		 * @param string $merge Processing rule reference.
		 * @return bool Validity result indicator.
		 */
		private static function shouldUpdateField( int $storeId, string $fieldName, bool $isExisting, string $merge ): bool {
			if ( ! $isExisting ) {
				return true;
			}
			if ( $merge !== 's2' ) {
				return true;
			}

			$current = get_post_meta( $storeId, $fieldName, true );
			$isEmpty = ( $current === '' || $current === null || $current === [] || $current === 0 || $current === '0' );
			
			if ( ! $isEmpty && is_string( $current ) ) {
				$trimmed = trim( (string) $current );
				$isEmpty = in_array( $trimmed, [ '[]', 'a:0:{}' ], true );
				
				if ( $fieldName === 'two_columns_button_link' && str_starts_with( $trimmed, 'a:' ) ) {
					$isEmpty = true;
				}

				if ( $fieldName === 'two_columns_content' ) {
					$cur = $trimmed;
					$hasWhereCare = strpos( $cur, 'Where care, community, and independence come together to thrive.' ) !== false;
					$hasBuildingIndependence = strpos( $cur, 'Building independence and belonging through care' ) !== false;
					if ( $hasWhereCare && ! $hasBuildingIndependence ) {
						$isEmpty = true;
					}
				}

				if ( $fieldName === 'two_columns_image' ) {
					$firstImg = get_post_meta( $storeId, 'first_section_image', true );
					$isSame = (int) $firstImg === (int) $trimmed;
					if ( $isSame ) {
						$isEmpty = true;
					}
				}
			}

			if ( ! $isEmpty && $fieldName === 'two_columns_image' ) {
				$firstImg = get_post_meta( $storeId, 'first_section_image', true );
				if ( (int) $firstImg > 0 && (int) $current === (int) $firstImg ) {
					$isEmpty = true;
				}
			}

			if ( ! $isEmpty && $fieldName === 'two_columns_heading' ) {
				$firstHeading = get_post_meta( $storeId, 'first_section_heading', true );
				if ( is_string( $current ) && $current !== '' && is_string( $firstHeading ) && trim( $current ) === trim( $firstHeading ) ) {
					$isEmpty = true;
				}
			}

			if ( ! $isEmpty && in_array( $fieldName, [ 'our_expertise', 'facilities_and_features' ], true ) ) {
				$currentValues = [];
				if ( is_array( $current ) ) {
					$currentValues = $current;
				} else if ( is_string( $current ) ) {
					$maybe = maybe_unserialize( $current );
					if ( is_array( $maybe ) ) {
						$currentValues = $maybe;
					} else if ( trim( $current ) !== '' ) {
						$currentValues = [ $current ];
					}
				}

				$filteredValues = [];
				foreach ( $currentValues as $v ) {
					$trimmed = trim( (string) $v );
					if ( $trimmed !== '' ) {
						$filteredValues[] = $trimmed;
					}
				}

				$normalizedCurrent = self::normalizeCheckboxValues( $fieldName, $filteredValues );
				if ( count( $normalizedCurrent ) !== count( $filteredValues ) ) {
					$isEmpty = true;
				}
			}

			return $isEmpty;
		}

		/**
		 * Direct invocation implementation logic ensuring dual compatibility against native API or standalone WP routines.
		 * * @param int $storeId Insertion entity id.
		 * @param string $fieldName Field identification label.
		 * @param mixed $value Insertion value object payload.
		 */
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

		/**
		 * Initiates image download, cache checks, error boundaries, and attachment saving procedures.
		 *
		 * @param mixed $oldAttachmentRef old attachment id (int) OR old attachment URL (string)
		 * @param array $attachmentUrlMap old attachment id => url
		 * @param array &$uploadedCache cache old id/url => new attachment id
		 * @param int $storeId Linked association wrapper.
		 * @param bool $dryRun Verification flag.
		 * @param array &$report Statistics container tracking mapping and sideload outputs.
		 * @return int|null Created target file identifier.
		 */
		private static function sideloadOldAttachmentId( $oldAttachmentRef, array $attachmentUrlMap, array &$uploadedCache, int $storeId, bool $dryRun, array &$report ): ?int {
			if ( empty( $oldAttachmentRef ) ) {
				return null;
			}

			$cacheKey = null;
			$url = '';

			if ( is_numeric( $oldAttachmentRef ) ) {
				$oldAttachmentId = (int) $oldAttachmentRef;
				if ( $oldAttachmentId <= 0 ) {
					return null;
				}
				$cacheKey = (string) $oldAttachmentId;
				if ( isset( $uploadedCache[ $cacheKey ] ) ) {
					return (int) $uploadedCache[ $cacheKey ];
				}
				$url = $attachmentUrlMap[ $oldAttachmentId ] ?? '';
			} else if ( is_string( $oldAttachmentRef ) ) {
				$url = (string) $oldAttachmentRef;
				if ( ! str_starts_with( $url, 'http' ) ) {
					return null;
				}
				$cacheKey = 'url:' . md5( $url );
				if ( isset( $uploadedCache[ $cacheKey ] ) ) {
					return (int) $uploadedCache[ $cacheKey ];
				}
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

		/**
		 * Direct utility executing fallback update handlers matching meta values against specific overwrite schemas.
		 * * @param int $storeId Association tracking.
		 * @param string $metaKey Column specifier.
		 * @param mixed $value Direct primitive parameter mapping payload entry.
		 * @param bool $isExisting Entity persistence verification logic flag.
		 * @param string $merge Configuration policy handling constraints structure schema.
		 */
		private static function updatePostMetaIfNeeded( int $storeId, string $metaKey, $value, bool $isExisting, string $merge ): void {
			if ( ! $isExisting ) {
				update_post_meta( $storeId, $metaKey, $value );
				return;
			}

			if ( $merge !== 's2' ) {
				update_post_meta( $storeId, $metaKey, $value );
				return;
			}

			$current = get_post_meta( $storeId, $metaKey, true );
			$isEmpty = ( $current === '' || $current === null || $current === [] || $current === 0 || $current === '0' );
			if ( $isEmpty ) {
				update_post_meta( $storeId, $metaKey, $value );
			}
		}

		/**
		 * Inserts mapped valid categories associated directly alongside location context references safely avoiding duplicates.
		 * * @param int $storeId System container ID reference entity.
		 * @param string $nicename System internal validation category term configuration parameter.
		 */
		private static function ensureWpslCategory( int $storeId, string $nicename ): void {
			if ( ! $nicename ) {
				return;
			}

			$taxonomy = 'wpsl_store_category';

			$term = term_exists( $nicename, $taxonomy );
			if ( ! $term ) {
				wp_insert_term( $nicename, $taxonomy, [ 'slug' => $nicename ] );
			}

			wp_set_object_terms( $storeId, [ $nicename ], $taxonomy );
		}

		/**
		 * Import core logic that can be triggered from the WP admin dashboard.
		 *
		 * @param string $oldXml Uploaded file reference string link.
		 * @param string $slug Restricting identifier constraints filter.
		 * @param string $merge Config parameter limiting replacement parameters schema.
		 * @param bool $dryRun Target execution constraint flag.
		 * @return array<string,mixed> report counters
		 */
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
				'locations_scanned' => 0,
				'stores_processed' => 0,
				'stores_created' => 0,
				'stores_existing' => 0,
				'missing_attachment_url' => 0,
				'sideload_failed' => 0,
				'missing_image_mapping' => 0,
				'images_sideloaded' => 0,
				'debug_dry_run' => $dryRun ? 1 : 0,
				'debug_merge' => (string) $merge,
			];

			if ( function_exists( 'get_field_object' ) ) {
				$ourChoices = get_field_object( 'our_expertise' );
				$facChoices = get_field_object( 'facilities_and_features' );

				$ourChoiceList = [];
				if ( is_array( $ourChoices ) && ! empty( $ourChoices['choices'] ) && is_array( $ourChoices['choices'] ) ) {
					foreach ( $ourChoices['choices'] as $val => $label ) {
						$ourChoiceList[] = (string) $val . '=> ' . (string) $label;
					}
				}
				$facChoiceList = [];
				if ( is_array( $facChoices ) && ! empty( $facChoices['choices'] ) && is_array( $facChoices['choices'] ) ) {
					foreach ( $facChoices['choices'] as $val => $label ) {
						$facChoiceList[] = (string) $val . '=> ' . (string) $label;
					}
				}

				$report['acf_our_expertise_choices'] = ! empty( $ourChoiceList ) ? implode( ' | ', $ourChoiceList ) : '(empty/unavailable)';
				$report['acf_facilities_and_features_choices'] = ! empty( $facChoiceList ) ? implode( ' | ', $facChoiceList ) : '(empty/unavailable)';
			}

			$parser->iterateLocations(
				function ( array $location ) use ( $slug, $dryRun, $merge, $attachmentUrlMap, &$uploadedAttachmentIdCache, &$report ) {
					$report['locations_scanned'] = (int) ( $report['locations_scanned'] ?? 0 ) + 1;

					$oldPostName = self::getImportSlug( $location );
					if ( $slug && $oldPostName !== $slug ) {
						return;
					}

					$report['stores_processed'] = (int) ( $report['stores_processed'] ?? 0 ) + 1;

					$payload = self::mapLocationPayload( $location );

					if ( $slug && $oldPostName === $slug ) {
						$report['debug_our_expertise_payload'] = json_encode( array_values( (array) ( $payload['our_expertise'] ?? [] ) ) );
						$report['debug_facilities_payload'] = json_encode( array_values( (array) ( $payload['facilities_and_features'] ?? [] ) ) );
					}

					$storeId = self::getExistingStoreIdBySlug( $oldPostName );
					$isExisting = $storeId > 0;

					if ( $dryRun ) {
						return;
					}

					if ( ! $isExisting ) {
						$desc = (string) ( $payload['short_description'] ?? '' );
						$postContentHtml = '';
						if ( $desc !== '' ) {
							$postContentHtml = '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
						}

						$createdId = wp_insert_post(
							[
								'post_type' => 'wpsl_stores',
								'post_status' => self::mapOldStatusToTargetStatus( $location ),
								'post_name' => $oldPostName,
								'post_title' => (string) ( $location['post_title'] ?? $oldPostName ),
								'post_content' => $postContentHtml,
							],
							true
						);

						if ( is_wp_error( $createdId ) ) {
							return;
						}

						$storeId = (int) $createdId;
						self::ensureWpslCategory( $storeId, (string) ( $payload['taxonomy_category_nicename'] ?? '' ) );
						$report['stores_created'] = (int) ( $report['stores_created'] ?? 0 ) + 1;
						$isExisting = true;
					} else {
						$report['stores_existing'] = (int) ( $report['stores_existing'] ?? 0 ) + 1;
					}

					if ( ! $storeId ) {
						return;
					}

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
						'two_columns_image' => $oldRefs['two_columns_image'] ?? null,
						'kitchen' => $oldRefs['kitchen'] ?? null,
						'living_room' => $oldRefs['living_room'] ?? null,
						'dining_room' => $oldRefs['dining_room'] ?? null,
					];

					foreach ( $imageFields as $fieldName => $oldAttachmentId ) {
						if ( ! self::shouldUpdateField( (int) $storeId, (string) $fieldName, $isExisting, $merge ) ) {
							continue;
						}

						if ( ! $oldAttachmentId ) {
							$report['missing_image_mapping'] = (int) ( $report['missing_image_mapping'] ?? 0 ) + 1;
							continue;
						}

						$newAttachmentId = self::sideloadOldAttachmentId(
							$oldAttachmentId,
							$attachmentUrlMap,
							$uploadedAttachmentIdCache,
							(int) $storeId,
							$dryRun,
							$report
						);

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

					$galleryImages = (array) ( $oldRefs['gallery_images'] ?? [] );
					$galleryTexts = (array) ( $oldRefs['gallery_texts'] ?? [] );
					for ( $i = 1; $i <= 10; $i ++ ) {
						$imgIndex = $i - 1;
						$imgOldAttachmentId = $galleryImages[ $imgIndex ] ?? null;
						$galleryImageField = 'gallery_image_' . $i;
						$galleryTextField = 'gallery_text_' . $i;

						$slotText = isset( $galleryTexts[ $imgIndex ] ) ? (string) $galleryTexts[ $imgIndex ] : '';

						if ( self::shouldUpdateField( (int) $storeId, $galleryTextField, $isExisting, $merge ) ) {
							if ( $slotText !== '' ) {
								self::doUpdateField( (int) $storeId, $galleryTextField, $slotText );
							} else {
								$report['missing_image_mapping'] = (int) ( $report['missing_image_mapping'] ?? 0 ) + 1;
							}
						}

						if ( self::shouldUpdateField( (int) $storeId, $galleryImageField, $isExisting, $merge ) ) {
							$newAttachmentId = self::sideloadOldAttachmentId(
								$imgOldAttachmentId,
								$attachmentUrlMap,
								$uploadedAttachmentIdCache,
								(int) $storeId,
								$dryRun,
								$report
							);

							if ( $newAttachmentId ) {
								self::doUpdateField( (int) $storeId, $galleryImageField, (int) $newAttachmentId );
							}
						}
					}

					if ( function_exists( 'set_post_thumbnail' ) ) {
						$currentThumb = get_post_meta( (int) $storeId, '_thumbnail_id', true );
						$isThumbEmpty = ( $currentThumb === '' || $currentThumb === null || (int) $currentThumb === 0 );
						if ( $isThumbEmpty ) {
							$acfThumb = (int) get_post_meta( (int) $storeId, 'first_section_image', true );
							if ( $acfThumb > 0 ) {
								set_post_thumbnail( (int) $storeId, $acfThumb );
							}
						}
					}

					$desc = (string) ( $payload['short_description'] ?? '' );
					if ( $desc !== '' ) {
						$desiredContent = '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
						if ( ! $isExisting ) {
							wp_update_post(
								[
									'ID' => (int) $storeId,
									'post_content' => $desiredContent,
								],
								true
							);
						} else {
							$currentContent = (string) get_post_field( 'post_content', (int) $storeId );
							$currentContentTrim = trim( $currentContent );
							$shouldUpdateContent = ( $merge !== 's2' ) || ( $currentContentTrim === '' );
							if ( $shouldUpdateContent ) {
								wp_update_post(
									[
										'ID' => (int) $storeId,
										'post_content' => $desiredContent,
									],
									true
								);
							}
						}
					}
				}
			);

			return $report;
		}

		/**
		 * WP-CLI command handler implementation.
		 * * @param array $args Unassoc parameters context variables.
		 * @param array $assoc_args Configured flag parameter options structure mapping.
		 */
		public static function run( array $args, array $assoc_args ): void {
			$oldXml = (string) ( $assoc_args['old-xml'] ?? '' );
			if ( ! $oldXml || ! file_exists( $oldXml ) ) {
				WP_CLI::error( 'Missing or unreadable --old-xml=' . $oldXml );
				return;
			}

			$slug = isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '';
			$merge = strtolower( (string) ( $assoc_args['merge'] ?? 's2' ) );
			$dryRun = ! empty( $assoc_args['dry-run'] );

			WP_CLI::line( 'Loading old attachment URLs...' );
			$parser = new IVolve_WXR_Store_Locator_Parser( $oldXml );
			$attachmentUrlMap = $parser->loadAttachmentUrlMap();
			WP_CLI::line( 'Attachments loaded: ' . count( $attachmentUrlMap ) );

			WP_CLI::line( 'Beginning locations iteration...' );

			$processed = 0;
			$uploadedAttachmentIdCache = [];
			$report = [
				'locations_scanned' => 0,
				'stores_processed' => 0,
				'stores_created' => 0,
				'stores_existing' => 0,
				'missing_attachment_url' => 0,
				'sideload_failed' => 0,
				'missing_image_mapping' => 0,
				'images_sideloaded' => 0,
			];
			$parser->iterateLocations(
				function ( array $location ) use ( $slug, &$processed, $dryRun, $attachmentUrlMap, $merge, &$uploadedAttachmentIdCache, &$report ) {
					$processed ++;
					$oldPostName = self::getImportSlug( $location );
					if ( $slug && $oldPostName !== $slug ) {
						return;
					}

					WP_CLI::line( sprintf( 'Importing location: %s (%s)', $oldPostName, $location['post_title'] ) );

					$report['stores_processed'] = (int) $report['stores_processed'] + 1;

					$payload = self::mapLocationPayload( $location );

					$storeId = self::getExistingStoreIdBySlug( $oldPostName );
					$isExisting = $storeId > 0;

					if ( ! $dryRun && ! $isExisting ) {
						$desc = (string) ( $payload['short_description'] ?? '' );
						$postContentHtml = '';
						if ( $desc !== '' ) {
							$postContentHtml = '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
						}

						$storeId = wp_insert_post(
							[
								'post_type' => 'wpsl_stores',
								'post_status' => self::mapOldStatusToTargetStatus( $location ),
								'post_name' => $oldPostName,
								'post_title' => $location['post_title'],
								'post_content' => $postContentHtml,
							],
							true
						);

						if ( is_wp_error( $storeId ) ) {
							WP_CLI::error( 'Failed to create wpsl_stores post: ' . $storeId->get_error_message() );
							return;
						}

						self::ensureWpslCategory( (int) $storeId, (string) $payload['taxonomy_category_nicename'] );
						$report['stores_created'] = (int) $report['stores_created'] + 1;
					} else if ( $isExisting ) {
						$report['stores_existing'] = (int) $report['stores_existing'] + 1;
					}

					if ( ! $storeId ) {
						WP_CLI::line( 'Dry run: would create/update store for slug: ' . $oldPostName );
						$isExisting = $isExisting;
					}

					WP_CLI::line( 'Store ID: ' . (int) $storeId . ' existing=' . ( $isExisting ? 'yes' : 'no' ) );

					if ( $dryRun ) {
						WP_CLI::line( 'Dry run mode: skipping all write operations.' );
						return;
					}

					if ( $storeId ) {
						foreach ( $payload['wpsl'] as $metaKey => $metaValue ) {
							self::updatePostMetaIfNeeded( (int) $storeId, (string) $metaKey, $metaValue, $isExisting, $merge );
						}

						self::updateFieldIfNeeded( (int) $storeId, 'number_of_bedrooms', (int) ( $payload['bedrooms'] ?? 0 ), $isExisting, $merge );
						self::updateFieldIfNeeded( (int) $storeId, 'cqc_id', (string) $payload['cqc_id'], $isExisting, $merge );

						self::updateFieldIfNeeded( (int) $storeId, 'first_section_heading', (string) $payload['first_section_heading'], $isExisting, $merge );
						self::updateFieldIfNeeded( (int) $storeId, 'first_section_content', (string) $payload['first_section_content'], $isExisting, $merge );
						self::updateFieldIfNeeded( (int) $storeId, 'two_columns_heading', (string) $payload['two_columns_heading'], $isExisting, $merge );
						self::updateFieldIfNeeded( (int) $storeId, 'two_columns_content', (string) $payload['two_columns_content'], $isExisting, $merge );

						self::updateFieldIfNeeded( (int) $storeId, 'two_columns_button_text', (string) ( $payload['two_columns_button_text'] ?? '' ), $isExisting, $merge );
						self::updateFieldIfNeeded( (int) $storeId, 'two_columns_button_link', (string) ( $payload['two_columns_button_link'] ?? '' ), $isExisting, $merge );
						self::updateFieldIfNeeded( (int) $storeId, 'walkthrough_360', (string) ( $payload['walkthrough_360'] ?? '' ), $isExisting, $merge );

						self::updateFieldIfNeeded( (int) $storeId, 'our_expertise', (array) $payload['our_expertise'], $isExisting, $merge );
						self::updateFieldIfNeeded( (int) $storeId, 'facilities_and_features', (array) $payload['facilities_and_features'], $isExisting, $merge );

						$oldRefs = $payload['old_image_refs'] ?? [];

						$imageFields = [
							'first_section_image' => $oldRefs['first_section_image'] ?? null,
							'two_columns_image' => $oldRefs['two_columns_image'] ?? null,
							'kitchen' => $oldRefs['kitchen'] ?? null,
							'living_room' => $oldRefs['living_room'] ?? null,
							'dining_room' => $oldRefs['dining_room'] ?? null,
						];

						foreach ( $imageFields as $fieldName => $oldAttachmentId ) {
							if ( ! self::shouldUpdateField( (int) $storeId, (string) $fieldName, $isExisting, $merge ) ) {
								continue;
							}

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

						$galleryImages = (array) ( $oldRefs['gallery_images'] ?? [] );
						$galleryTexts = (array) ( $oldRefs['gallery_texts'] ?? [] );
						$maxSlots = 10;
						for ( $i = 1; $i <= $maxSlots; $i ++ ) {
							$imgIndex = $i - 1;
							$imgOldAttachmentId = $galleryImages[ $imgIndex ] ?? null;
							$galleryImageField = 'gallery_image_' . $i;
							$galleryTextField = 'gallery_text_' . $i;

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

						if ( function_exists( 'set_post_thumbnail' ) ) {
							$currentThumb = get_post_meta( (int) $storeId, '_thumbnail_id', true );
							$isThumbEmpty = ( $currentThumb === '' || $currentThumb === null || (int) $currentThumb === 0 );
							if ( $isThumbEmpty ) {
								$acfThumb = (int) get_post_meta( (int) $storeId, 'first_section_image', true );
								if ( $acfThumb > 0 ) {
									set_post_thumbnail( (int) $storeId, $acfThumb );
								}
							}
						}

						$desc = (string) ( $payload['short_description'] ?? '' );
						if ( $desc !== '' ) {
							$desiredContent = '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
							if ( ! $isExisting || $merge !== 's2' ) {
								wp_update_post(
									[
										'ID' => (int) $storeId,
										'post_content' => $desiredContent,
									],
									true
								);
							} else {
								$currentContent = (string) get_post_field( 'post_content', (int) $storeId );
								$currentContentTrim = trim( $currentContent );
								if ( $currentContentTrim === '' ) {
									wp_update_post(
										[
											'ID' => (int) $storeId,
											'post_content' => $desiredContent,
										],
										true
									);
								}
							}
						}

						WP_CLI::line( 'Mapped text fields + arrays + images (t3).' );
					}

					$oldRefs = $payload['old_image_refs'] ?? [];
					WP_CLI::line( 'Old image refs (attachment IDs): kitchen=' . ( $oldRefs['kitchen'] ?? 'null' ) . ' living=' . ( $oldRefs['living_room'] ?? 'null' ) . ' dining=' . ( $oldRefs['dining_room'] ?? 'null' ) );
					WP_CLI::line( 'Gallery old image IDs: ' . implode( ',', (array) ( $oldRefs['gallery_images'] ?? [] ) ) );
				}
			);

			WP_CLI::line( 'Locations scanned: ' . $processed );
			$report['locations_scanned'] = (int) $processed;
			WP_CLI::line( 'Import summary:' );
			WP_CLI::line( ' - stores_processed: ' . (int) ( $report['stores_processed'] ?? 0 ) );
			WP_CLI::line( ' - stores_created: ' . (int) ( $report['stores_created'] ?? 0 ) );
			WP_CLI::line( ' - stores_existing: ' . (int) ( $report['stores_existing'] ?? 0 ) );
			WP_CLI::line( ' - images_sideloaded: ' . (int) ( $report['images_sideloaded'] ?? 0 ) );
			WP_CLI::line( ' - missing_attachment_url: ' . (int) ( $report['missing_attachment_url'] ?? 0 ) );
			WP_CLI::line( ' - sideload_failed: ' . (int) ( $report['sideload_failed'] ?? 0 ) );
			WP_CLI::line( ' - missing_image_mapping: ' . (int) ( $report['missing_image_mapping'] ?? 0 ) );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'ivolve locations store-locator-import', [ IVolve_Store_Locator_Import_Command::class, 'run' ] );
}

if ( is_admin() ) {
	add_filter(
		'upload_mimes',
		function ( $mimes ) {
			$mimes['xml'] = 'application/xml';
			return $mimes;
		}
	);

	add_filter(
		'wp_check_filetype_and_ext',
		function ( $data, $file, $filename, $mimes ) {
			$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
			if ( $ext === 'xml' ) {
				$data['ext'] = 'xml';
				$data['type'] = 'application/xml';
			}
			return $data;
		},
		10,
		4
	);

	add_action(
		'admin_menu',
		function () {
			add_menu_page(
				'Store Locator Import',
				'Store Locator Import',
				'manage_options',
				'ivolve-store-locator-import',
				function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						wp_die( 'Access denied.' );
					}

					$nonce = wp_create_nonce( 'ivolve_store_locator_import' );

					echo '<div class="wrap">';
					echo '<h1>Store Locator Import</h1>';
					echo '<p>Upload the <code>locations</code> WXR export from the old site (must include attachments). Then run a dry run or the real import.</p>';
					echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					echo '<input type="hidden" name="action" value="ivolve_store_locator_importer" />';
					echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';
					echo '<table class="form-table" role="presentation">';
					echo '<tr><th scope="row"><label for="old_xml_upload">Old site WXR XML (upload)</label></th><td><input id="old_xml_upload" type="file" name="old_xml_upload" accept=".xml" required /></td></tr>';
					echo '<tr><th scope="row"><label for="slug">Slug filter (optional)</label></th><td><input id="slug" type="text" name="slug" value="" placeholder="e.g. 68-woodhurst-avenue" /></td></tr>';
					echo '<tr><th scope="row"><label for="merge">Merge strategy</label></th><td><select id="merge" name="merge"><option value="s2" selected>Fill blanks only (s2)</option></select></td></tr>';
					echo '<tr><th scope="row"><label for="dry_run">Dry run</label></th><td><label><input id="dry_run" type="checkbox" name="dry_run" value="1" checked /> Scan/mapping only (no writes)</label></td></tr>';
					echo '</table>';
					echo '<p><button type="submit" class="button button-primary">Run Import</button></p>';
					echo '</form>';
					echo '</div>';
				}
			);
		}
	);

	add_action(
		'admin_post_ivolve_store_locator_importer',
		function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Access denied.' );
			}

			check_admin_referer( 'ivolve_store_locator_import' );

			$slug = isset( $_POST['slug'] ) ? (string) $_POST['slug'] : '';
			$merge = isset( $_POST['merge'] ) ? (string) $_POST['merge'] : 's2';
			$dryRun = ! empty( $_POST['dry_run'] );

			if ( empty( $_FILES['old_xml_upload'] ) || empty( $_FILES['old_xml_upload']['tmp_name'] ) ) {
				wp_die( 'Missing uploaded XML file.' );
			}

			$handled = wp_handle_upload(
				$_FILES['old_xml_upload'],
				[
					'test_form' => false,
					'mimes' => [ 'xml' => 'application/xml, text/xml, text/plain' ],
				]
			);

			if ( isset( $handled['error'] ) ) {
				wp_die( 'Upload failed: ' . esc_html( (string) $handled['error'] ) );
			}

			$oldXmlPath = (string) ( $handled['file'] ?? '' );
			if ( ! $oldXmlPath || ! file_exists( $oldXmlPath ) ) {
				wp_die( 'Uploaded file missing on server.' );
			}

			@set_time_limit( 0 );

			try {
				$report = IVolve_Store_Locator_Import_Command::importFromOldXml( $oldXmlPath, $slug, $merge, $dryRun );
			} catch ( Throwable $e ) {
				wp_die( 'Import failed: ' . esc_html( $e->getMessage() ) );
			}

			echo '<div class="wrap">';
			echo '<h1>Import Result</h1>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ivolve-store-locator-import' ) ) . '">Back</a></p>';
			echo '<h2>Summary</h2>';
			echo '<ul>';
			foreach ( (array) $report as $k => $v ) {
				echo '<li><strong>' . esc_html( (string) $k ) . ':</strong> ' . esc_html( (string) $v ) . '</li>';
				}
			echo '</ul>';
			echo '<p>If the dry run succeeded, run again with dry run unchecked.</p>';
			echo '</div>';
		}
	);
}