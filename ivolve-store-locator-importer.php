<?php
/**
 * IVolve Store Locator migration helper.
 *
 * Plugin Name: IVolve Store Locator Importer
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Description: Imports old `locations` CPT content into new `wpsl_stores` Store Locator fields (ACF + images).
 * Version: 0.2.5
 *
 * Place this file into `wp-content/mu-plugins/` or `wp-content/plugins/` on your staging environment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'IVolve_Store_Locator_Import_Command' ) ) {
	class IVolve_Store_Locator_Import_Command {

		/**
		 * Safely reads and parses the XML file, handling large CDATA and invalid characters.
		 * * @param string $wxrPath The absolute path to the XML file.
		 * @return SimpleXMLElement The parsed XML object.
		 * @throws Exception If file reading or XML parsing fails.
		 */
		public static function loadXml( $wxrPath ) {
			$prev = libxml_use_internal_errors( true );

			// Read file into memory string to sanitize hidden formatting quirks
			$xmlString = file_get_contents( $wxrPath );
			if ( $xmlString === false ) {
				throw new Exception( 'Could not read file contents: ' . $wxrPath );
			}

			// Strip invalid XML 1.0 control characters that break SimpleXML parsing
			// (Safely allows \x09 Tab, \x0A Line Feed, \x0D Carriage Return)
			$xmlString = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xmlString );

			// LIBXML_PARSEHUGE allows parsing extremely large CDATA sections (e.g. Elementor/Gutenberg blocks)
			$xml = simplexml_load_string( $xmlString, 'SimpleXMLElement', LIBXML_PARSEHUGE );

			if ( ! $xml ) {
				$errors = libxml_get_errors();
				$errMsgs = array();
				foreach ( $errors as $err ) {
					$errMsgs[] = trim( $err->message ) . ' on line ' . $err->line;
				}
				
				libxml_clear_errors();
				libxml_use_internal_errors( $prev );

				$details = empty( $errMsgs ) ? 'Unknown parsing error.' : implode( ' | ', $errMsgs );
				throw new Exception( 'Failed to load WXR XML: ' . $wxrPath . ' - Details: ' . $details );
			}

			libxml_clear_errors();
			libxml_use_internal_errors( $prev );

			return $xml;
		}

		/**
		 * Parses the WXR file to extract all media attachments mapping old ID to URL.
		 * * @param SimpleXMLElement $xml The loaded XML object.
		 * @return array Associative array of old_attachment_id => attachment_url.
		 */
		public static function getAttachmentUrlMap( $xml ) {
			$out = array();
			
			if ( empty( $xml->channel->item ) ) {
				return $out;
			}

			foreach ( $xml->channel->item as $item ) {
				$wp = $item->children( 'wp', true );
				$postType = isset( $wp->post_type ) ? (string) $wp->post_type : '';
				
				if ( $postType !== 'attachment' ) {
					continue;
				}

				$oldId = isset( $wp->post_id ) ? (int) $wp->post_id : 0;
				if ( ! $oldId ) {
					continue;
				}

				$url = isset( $wp->attachment_url ) ? (string) $wp->attachment_url : '';
				if ( ! $url ) {
					$url = isset( $item->guid ) ? (string) $item->guid : '';
				}

				if ( $url ) {
					$out[ $oldId ] = $url;
				}
			}

			return $out;
		}

		/**
		 * Extracts all valid 'locations' posts from the XML document payload.
		 * * @param SimpleXMLElement $xml The loaded XML object.
		 * @return array List of parsed location payload arrays.
		 */
		public static function getLocations( $xml ) {
			$out = array();
			
			if ( empty( $xml->channel->item ) ) {
				return $out;
			}

			foreach ( $xml->channel->item as $item ) {
				$wp = $item->children( 'wp', true );
				$postType = isset( $wp->post_type ) ? (string) $wp->post_type : '';
				
				if ( $postType !== 'locations' ) {
					continue;
				}

				$out[] = self::mapLocationPayload( $item );
			}

			return $out;
		}

		/**
		 * Constructs standard field mappings extracting legacy structures into new standard ACF formats.
		 * * @param SimpleXMLElement $item An individual XML item node.
		 * @return array Structured data representation mapping fields for insertion.
		 */
		public static function mapLocationPayload( $item ) {
			$wp = $item->children( 'wp', true );
			
			$postName = isset( $wp->post_name ) ? (string) $wp->post_name : '';
			$postStatus = isset( $wp->status ) ? (string) $wp->status : 'publish';
			$postId = isset( $wp->post_id ) ? (int) $wp->post_id : 0;
			$postTitle = isset( $item->title ) ? (string) $item->title : $postName;

			$contentNs = $item->children( 'content', true );
			$postContent = isset( $contentNs->encoded ) ? (string) $contentNs->encoded : '';

			$categoriesByDomain = array();
			if ( isset( $item->category ) ) {
				foreach ( $item->category as $cat ) {
					$domain = isset( $cat['domain'] ) ? (string) $cat['domain'] : '';
					$nicename = isset( $cat['nicename'] ) ? (string) $cat['nicename'] : '';
					if ( $domain && $nicename ) {
						if ( ! isset( $categoriesByDomain[ $domain ] ) ) {
							$categoriesByDomain[ $domain ] = array();
						}
						$categoriesByDomain[ $domain ][] = $nicename;
					}
				}
			}

			$meta = array();
			if ( ! empty( $wp->postmeta ) ) {
				foreach ( $wp->postmeta as $postmeta ) {
					$pmWp = $postmeta->children( 'wp', true );
					$key = isset( $pmWp->meta_key ) ? (string) $pmWp->meta_key : '';
					$value = isset( $pmWp->meta_value ) ? (string) $pmWp->meta_value : '';
					if ( $key ) {
						$meta[ $key ] = $value;
					}
				}
			}

			$wpsl = array(
				'wpsl_address' => '',
				'wpsl_city' => '',
				'wpsl_zip' => '',
				'wpsl_country' => ''
			);
			
			$serializedLocation = isset( $meta['location'] ) ? $meta['location'] : '';
			$locationArr = is_string( $serializedLocation ) ? @unserialize( $serializedLocation ) : false;
			
			if ( is_array( $locationArr ) ) {
				$streetNumber = isset( $locationArr['street_number'] ) ? (string) $locationArr['street_number'] : '';
				$streetName = isset( $locationArr['street_name'] ) ? (string) $locationArr['street_name'] : '';
				$wpsl['wpsl_address'] = trim( $streetNumber . ' ' . $streetName );
				$wpsl['wpsl_city'] = isset( $locationArr['city'] ) ? (string) $locationArr['city'] : '';
				$wpsl['wpsl_zip'] = isset( $locationArr['post_code'] ) ? (string) $locationArr['post_code'] : '';
				$wpsl['wpsl_country'] = isset( $locationArr['country'] ) ? (string) $locationArr['country'] : '';
			}

			$bedrooms = null;
			if ( isset( $meta['Bedrooms'] ) ) {
				$raw = (string) $meta['Bedrooms'];
				if ( is_numeric( trim( $raw ) ) ) {
					$bedrooms = (int) trim( $raw );
				} else if ( preg_match( '/(\d+)/', $raw, $m ) ) {
					$bedrooms = (int) $m[1];
				}
			}

			$cqcId = '';
			$cqcBlocks = self::extractAllAcfBlockJson( $postContent, 'acf/cqc-widget' );
			if ( ! empty( $cqcBlocks ) ) {
				$first = $cqcBlocks[0];
				$data = isset( $first['data'] ) ? $first['data'] : array();
				$cqcId = isset( $data['cqc_id'] ) ? (string) $data['cqc_id'] : '';
			}
			if ( empty( $cqcId ) && ! empty( $meta['properties_0_property_sidebar_cqc_id'] ) ) {
				$cqcId = (string) $meta['properties_0_property_sidebar_cqc_id'];
			}

			$twoImageColumns = self::extractImageColumnSections( $postContent );
			$cards = self::extractContentCards( $postContent );
			$orderedGallery = self::buildGalleryFromCards( $cards );
			$heroImageOldId = self::extractPageHeaderHeroImageAttachmentId( $postContent );

			if ( ! $heroImageOldId && ! empty( $meta['_thumbnail_id'] ) ) {
				$heroImageOldId = (int) $meta['_thumbnail_id'];
			}
			
			$legacyImages = ! empty( $meta['properties_0_property_main_content_images'] ) 
				? maybe_unserialize( $meta['properties_0_property_main_content_images'] ) : array();
			
			if ( ! $heroImageOldId && is_array( $legacyImages ) && ! empty( $legacyImages ) ) {
				$heroImageOldId = (int) reset( $legacyImages );
			}
			
			if ( empty( $orderedGallery['gallery_image_old_attachment_ids'] ) && is_array( $legacyImages ) && ! empty( $legacyImages ) ) {
				$tempVals = array_values( $legacyImages );
				$mappedVals = array();
				foreach ( $tempVals as $v ) {
					$mappedVals[] = (int) $v;
				}
				$orderedGallery['gallery_image_old_attachment_ids'] = $mappedVals;
			}

			if ( ! isset( $twoImageColumns['first']['content'] ) || trim( $twoImageColumns['first']['content'] ) === '' ) {
				$legacyText = '';
				if ( ! empty( $meta['properties_0_property_main_content_property_main_text'] ) ) {
					$legacyText = wp_strip_all_tags( (string) $meta['properties_0_property_main_content_property_main_text'] );
				} else if ( trim( $postContent ) !== '' ) {
					if ( preg_match_all( '/\s*<p[^>]*>(.*?)<\/p>\s*/s', $postContent, $matches ) ) {
						$parts = array();
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

			$listIconTitles = self::extractListIconTitles( $postContent );
			
			$ourExpertiseRaw = isset( $listIconTitles['our_expertise'] ) ? (array) $listIconTitles['our_expertise'] : array();
			$ourExpertise = self::normalizeOurExpertiseFromLabels( $ourExpertiseRaw );
			
			$facilitiesRaw = isset( $listIconTitles['facilities_and_features'] ) ? (array) $listIconTitles['facilities_and_features'] : array();
			$facilitiesAndFeatures = self::normalizeCheckboxValues( 'facilities_and_features', $facilitiesRaw );
			
			if ( is_int( $bedrooms ) && $bedrooms > 0 && ! in_array( 'Bedrooms', $facilitiesAndFeatures, true ) ) {
				$facilitiesAndFeatures[] = 'Bedrooms';
			}

			$twoColumnsButton = self::extractTwoColumnsButtonFromContent( $postContent );
			$walkthrough360 = self::extractWalkthrough360FromContent( $postContent );
			
			$twoColumnsHeading = isset( $twoImageColumns['second']['heading'] ) ? (string) $twoImageColumns['second']['heading'] : '';
			if ( trim( $twoColumnsHeading ) === '' ) {
				$twoColumnsHeading = isset( $twoImageColumns['first']['heading'] ) ? (string) $twoImageColumns['first']['heading'] : '';
			}
			
			$twoColumnsContent = isset( $twoImageColumns['second']['content'] ) ? (string) $twoImageColumns['second']['content'] : '';
			if ( trim( $twoColumnsContent ) === '' ) {
				$twoColumnsContent = isset( $twoImageColumns['first']['content'] ) ? (string) $twoImageColumns['first']['content'] : '';
			}

			$twoColImgId = null;
			if ( isset( $twoImageColumns['second']['image_old_attachment_id'] ) ) {
				$twoColImgId = $twoImageColumns['second']['image_old_attachment_id'];
			} elseif ( isset( $twoImageColumns['first']['image_old_attachment_id'] ) ) {
				$twoColImgId = $twoImageColumns['first']['image_old_attachment_id'];
			}

			$oldImageRefs = array(
				'first_section_image' => $heroImageOldId,
				'two_columns_image' => $twoColImgId,
				'kitchen' => isset( $orderedGallery['kitchen_old_attachment_id'] ) ? $orderedGallery['kitchen_old_attachment_id'] : null,
				'living_room' => isset( $orderedGallery['living_room_old_attachment_id'] ) ? $orderedGallery['living_room_old_attachment_id'] : null,
				'dining_room' => isset( $orderedGallery['dining_room_old_attachment_id'] ) ? $orderedGallery['dining_room_old_attachment_id'] : null,
				'gallery_images' => isset( $orderedGallery['gallery_image_old_attachment_ids'] ) ? $orderedGallery['gallery_image_old_attachment_ids'] : array(),
				'gallery_texts' => isset( $orderedGallery['gallery_texts'] ) ? $orderedGallery['gallery_texts'] : array()
			);

			$taxonomyCategory = '';
			if ( ! empty( $categoriesByDomain['location_type'] ) && is_array( $categoriesByDomain['location_type'] ) ) {
				$taxonomyCategory = isset( $categoriesByDomain['location_type'][0] ) ? (string) $categoriesByDomain['location_type'][0] : '';
			}

			$locationDescription = isset( $meta['location_description'] ) ? (string) $meta['location_description'] : '';
			if ( ! $locationDescription ) {
				$locationDescription = isset( $meta['location'] ) ? (string) $meta['location'] : '';
			}

			$btnText = isset( $twoColumnsButton['text'] ) ? (string) $twoColumnsButton['text'] : '';
			$btnLink = '';
			if ( isset( $twoColumnsButton['link']['url'] ) ) {
				$btnLink = (string) $twoColumnsButton['link']['url'];
			}

			$firstSecHeading = isset( $twoImageColumns['first']['heading'] ) ? (string) $twoImageColumns['first']['heading'] : '';
			$firstSecContent = isset( $twoImageColumns['first']['content'] ) ? (string) $twoImageColumns['first']['content'] : '';

			return array(
				'post_id' => $postId,
				'post_name' => $postName,
				'post_status' => $postStatus,
				'post_title' => $postTitle,
				'taxonomy_category_nicename' => $taxonomyCategory,
				'wpsl' => $wpsl,
				'bedrooms' => $bedrooms,
				'cqc_id' => $cqcId,
				'first_section_heading' => $firstSecHeading,
				'first_section_content' => $firstSecContent,
				'two_columns_heading' => $twoColumnsHeading,
				'two_columns_content' => $twoColumnsContent,
				'our_expertise' => $ourExpertise,
				'facilities_and_features' => $facilitiesAndFeatures,
				'two_columns_button_text' => $btnText,
				'two_columns_button_link' => $btnLink,
				'walkthrough_360' => (string) $walkthrough360,
				'short_description' => $locationDescription,
				'old_image_refs' => $oldImageRefs
			);
		}

		/**
		 * Parses embedded raw JSON strings extracted manually from complex block configurations.
		 * * @param string $postContent Content haystack string.
		 * @param string $blockSlug ACF Block identifier mapping target block class.
		 * @return array Deserialized payload arrays extracted mapped.
		 */
		private static function extractAllAcfBlockJson( $postContent, $blockSlug ) {
			$out = array();
			$needle = '\s*<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $segment, $m ) ) {
				return trim( html_entity_decode( wp_strip_all_tags( $m[1] ) ) );
			}
			if ( preg_match( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $segment, $m ) ) {
				return trim( html_entity_decode( wp_strip_all_tags( $m[1] ) ) );
			}
			return '';
		}

		/**
		 * Parses embedded arrays definitions mapping structures parameters map logic properties schema definitions strings values maps schemas representations elements outputs maps properties mapped references.
		 * * @param string $segment Source string arrays definitions representations arrays mapping parameters outputs values components values maps properties arrays structures logic components references components schema values logic array definitions maps objects properties strings arrays maps outputs outputs logic strings arrays values components properties values schema maps arrays mapping logic logic schemas arrays map fields maps logic structures mappings.
		 * @param bool $truncateAtFirstButton Target string mappings values mapped array properties arrays parameters strings representations elements outputs arrays properties.
		 * @return array Valid fields mappings mappings components mappings mapping parameters structures mapping representations schemas mapping schemas parameters mapped logic arrays strings properties logic array outputs fields arrays representations logic logic mappings mapped string arrays mappings values mappings mappings structures arrays maps strings representations fields outputs fields mapping schema mapped values.
		 */
		private static function parseImageColumnParagraphs( $segment, $truncateAtFirstButton ) {
			if ( $truncateAtFirstButton ) {
				$buttonPos = strpos( $segment, '\s*<p[^>]*>(.*?)<\/p>\s*/s', $beforeButton, $matches ) ) {
					return array();
				}

				$parts = array();
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

			$parts = array();
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
		 * Fallback ID parsing mapping structures output variables array mapping properties mapping schema representations values mapped elements mappings mapped fields objects arrays values elements.
		 * * @param array $jsonData Elements mapping arrays strings outputs array structures properties parameters mappings string map mappings values logic fields mapping mapped schema structures mappings properties parameters mapping arrays.
		 * @param string $segment Mapping properties logic parameters logic string parameters schema mapped elements logic string arrays mapping values representations maps fields properties elements mapped elements maps string values elements strings schemas fields logic.
		 * @return int|null Parameter references map.
		 */
		private static function parseImageColumnOldAttachmentRef( $jsonData, $segment ) {
			$candidates = array(
				'image', 'image_id', 'imageId', 'image_file', 'imageFile', 
				'image_old_attachment_id', 'image_attachment_id', 'image_old_id', 
				'attachment_id', 'old_attachment_id'
			);

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
					foreach ( array( 'id', 'ID', 'attachment_id', 'old_attachment_id' ) as $subKey ) {
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
		 * Flattens array mappings output parameter fields mapping mapping schema mappings schemas array mappings elements schema logic fields mapping logic array parameters maps representations strings mapped structures mapping properties maps.
		 * * @param string $postContent Output structure parsing references properties schemas parameters schemas schema string arrays schema parameters fields string mapping elements logic mapping structures mapping logic properties mapped structures mapping logic array mapping properties mappings schemas parameters maps.
		 * @return array Valid fields variables.
		 */
		private static function extractImageColumnSections( $postContent ) {
			$jsonBlocks = self::extractAllAcfBlockJson( $postContent, 'acf/image-column' );

			$out = array(
				'first' => array(
					'heading' => '',
					'content' => '',
					'image_old_attachment_id' => null
				),
				'second' => array(
					'heading' => '',
					'content' => '',
					'image_old_attachment_id' => null
				)
			);

			$segments = array();
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
				$segment = isset( $segments[ $i ] ) ? $segments[ $i ] : '';
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
				$jsonData = isset( $jsonBlocks[ $i ]['data'] ) ? $jsonBlocks[ $i ]['data'] : array();
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
		 * Filters arrays structures logic mappings mapping schema string parameters mapping mapped schemas arrays parameters outputs mappings values fields schema maps structures representations strings mapping outputs mappings mapped properties.
		 * * @param string $postContent Strings representation mapping map mapping maps representations mappings representations schema mappings mapping values mapping strings mappings elements strings schema outputs representations mapping logic.
		 * @return array Mapped block extraction schemas values maps elements string mapping structures properties arrays structures fields logic values.
		 */
		private static function extractListIconTitles( $postContent ) {
			$out = array(
				'our_expertise' => array(),
				'facilities_and_features' => array()
			);

			$blocks = self::extractAllAcfBlockJson( $postContent, 'acf/list-icon' );
			
			foreach ( $blocks as $idx => $block ) {
				$data = isset( $block['data'] ) ? $block['data'] : array();
				if ( ! is_array( $data ) ) {
					continue;
				}

				$items = array();
				$n = isset( $data['list_items'] ) ? (int) $data['list_items'] : 0;
				
				if ( $n > 0 ) {
					for ( $i = 0; $i < $n; $i ++ ) {
						$key = 'list_items_' . $i . '_item_title';
						if ( isset( $data[ $key ] ) ) {
							$items[] = (string) $data[ $key ];
						}
					}
				} else {
					$indexes = array();
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
		 * Aligns extraction logic objects properties maps structures representations arrays schema map objects elements parameters strings values properties strings schemas array arrays mapping values array logic structures fields logic arrays string.
		 * * @param string $fieldName Object mapping definitions logic mapping mappings values properties outputs mapping values fields mapping objects maps.
		 * @param array $items Array mapped variables components output string definitions representations values string.
		 * @return array Normalized array outputs variables mapping maps schema strings schemas fields logic.
		 */
		private static function normalizeCheckboxValues( $fieldName, $items ) {
			$allowed = self::getCheckboxAllowedValues( $fieldName );
			if ( empty( $allowed ) ) {
				return $items;
			}

			$choiceValueMap = self::buildCheckboxChoiceValueMap( $fieldName );
			if ( empty( $choiceValueMap ) ) {
				$choiceValueMap = array();
				foreach ( $allowed as $opt ) {
					$k = strtolower( trim( (string) $opt ) );
					$choiceValueMap[ $k ] = $opt;
				}
			}

			$bucket = array();
			foreach ( $items as $raw ) {
				$v = trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( $v === '' ) {
					continue;
				}

				$key = strtolower( preg_replace( '/\s+/u', ' ', $v ) );
				$tries = array( $key );

				if ( $fieldName === 'our_expertise' ) {
					if ( $key === 'mental health needs' ) {
						$tries = array( 'mental health', 'mental health needs' );
					}
				} else if ( $fieldName === 'facilities_and_features' ) {
					if ( in_array( $key, array( '6 ensuite bedrooms', 'ensuite bedrooms' ), true ) ) {
						$tries = array( 'bedrooms' );
					} else if ( in_array( $key, array( 'beautiful garden', 'small homely garden' ), true ) ) {
						$tries = array( 'large garden' );
					}
				}

				foreach ( $tries as $t ) {
					if ( isset( $choiceValueMap[ $t ] ) ) {
						$bucket[ (string) $choiceValueMap[ $t ] ] = true;
						break;
					}
				}
			}

			$normalized = array();
			foreach ( $allowed as $option ) {
				if ( isset( $bucket[ (string) $option ] ) ) {
					$normalized[] = $option;
				}
			}

			if ( $fieldName === 'our_expertise' && empty( $normalized ) && ! empty( $items ) ) {
				$map = array(
					'autism' => 'Autism',
					'learning disabilities' => 'Learning Disabilities',
					'mental health' => 'Mental Health',
					'mental health needs' => 'Mental Health',
					'complex needs' => 'Complex Needs'
				);
				$bucket2 = array();
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
				$expectedOrder = array( 'Autism', 'Learning Disabilities', 'Mental Health', 'Complex Needs' );
				$normalized2 = array();
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
		 * Builds parameters arrays objects properties elements representations logic parameters strings.
		 * * @param string $fieldName Field configurations elements structures.
		 * @return array Mapped value elements arrays outputs strings.
		 */
		private static function buildCheckboxChoiceValueMap( $fieldName ) {
			if ( ! function_exists( 'get_field_object' ) ) {
				return array();
			}

			$field = get_field_object( $fieldName );
			$choices = is_array( $field ) && isset( $field['choices'] ) ? $field['choices'] : null;
			
			if ( empty( $choices ) || ! is_array( $choices ) ) {
				return array();
			}

			$map = array();
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
		 * Extracts values parameters outputs logic properties string schemas outputs arrays mappings mapped representations mapping string mapped elements.
		 * * @param string $fieldName Key map elements maps.
		 * @return array Valid choice outputs array structures schemas logic values maps values map outputs.
		 */
		private static function getCheckboxAllowedValues( $fieldName ) {
			if ( function_exists( 'get_field_object' ) ) {
				$field = get_field_object( $fieldName );
				if ( is_array( $field ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$choices = $field['choices'];
					return array_values( array_keys( $choices ) );
				}
			}

			if ( $fieldName === 'our_expertise' ) {
				return array( 'Autism', 'Learning Disabilities', 'Mental Health', 'Complex Needs' );
			}
			if ( $fieldName === 'facilities_and_features' ) {
				return array( 'Bedrooms', 'Mixed Gender', 'Communal Spaces', 'Large Garden' );
			}
			return array();
		}

		/**
		 * Validates internal arrays logic objects structures mapped parameters string representations schemas outputs properties logic maps fields logic properties elements map outputs array fields representations schemas fields outputs mappings values properties arrays structures outputs array logic mapped variables mappings string mapped logic mappings representations schemas fields string map arrays strings values logic maps values properties mapping fields maps logic parameters outputs mapping fields maps values.
		 * * @param string $postContent Output mappings variables structures string arrays objects mapping properties maps logic arrays fields schema representations values properties arrays parameters strings outputs representations values logic arrays mapping parameters arrays maps fields logic properties.
		 * @return array Target mapping representations strings maps representations strings maps strings schemas fields string mappings mapping logic structures mapped arrays maps outputs.
		 */
		private static function extractContentCards( $postContent ) {
			$blocks = self::extractAllAcfBlockJson( $postContent, 'acf/content-cards' );
			if ( empty( $blocks ) ) {
				return array();
			}

			$data = isset( $blocks[0]['data'] ) ? $blocks[0]['data'] : array();
			if ( ! is_array( $data ) ) {
				return array();
			}

			$cardsCount = isset( $data['cards'] ) ? (int) $data['cards'] : 0;
			$cards = array();
			
			if ( $cardsCount > 0 ) {
				for ( $i = 0; $i < $cardsCount; $i ++ ) {
					$heading = isset( $data[ 'cards_' . $i . '_card_heading' ] ) ? (string) $data[ 'cards_' . $i . '_card_heading' ] : '';
					$oldId = isset( $data[ 'cards_' . $i . '_card_image' ] ) ? (int) $data[ 'cards_' . $i . '_card_image' ] : null;
					$cards[] = array(
						'heading' => $heading,
						'old_attachment_id' => $oldId ? $oldId : null
					);
				}
				return $cards;
			}

			$indexes = array();
			foreach ( $data as $k => $_v ) {
				if ( preg_match( '/^cards_(\d+)_card_image$/', (string) $k, $m ) ) {
					$indexes[] = (int) $m[1];
				}
			}
			sort( $indexes );

			foreach ( $indexes as $i ) {
				$heading = isset( $data[ 'cards_' . $i . '_card_heading' ] ) ? (string) $data[ 'cards_' . $i . '_card_heading' ] : '';
				$oldId = isset( $data[ 'cards_' . $i . '_card_image' ] ) ? (int) $data[ 'cards_' . $i . '_card_image' ] : null;
				$cards[] = array(
					'heading' => $heading,
					'old_attachment_id' => $oldId ? $oldId : null
				);
			}

			return $cards;
		}

		/**
		 * Resolves values maps mapping properties maps strings mapping logic structures mapped arrays schema representations schemas outputs arrays fields outputs schema outputs mapped string.
		 * * @param string $needle Matching targets arrays mappings mappings maps.
		 * @param string $mustNot Target mappings mapped variables logic mapped mapping array string map structures properties representations properties elements mappings values arrays parameters mapped representations strings outputs values mapping.
		 * @param array $cards Valid payloads schema maps strings mapped mapping array mapping arrays values properties strings arrays parameters string outputs values map structures parameters schemas representations strings mappings fields strings values schemas mapping.
		 * @param array &$usedAttachmentIds Assigned fields values properties mapped structures fields outputs values string array variables schema maps representations values values maps mappings logic fields mappings mapped mappings mapping string mappings string properties elements schemas representations string maps logic fields schemas maps representations logic string mapped array mapping logic mappings string representations string parameters maps elements.
		 * @return int|null Valid payload mapping maps properties representations values mappings values mapped mappings mapping elements parameters mappings properties logic logic mapping string properties strings outputs mapped.
		 */
		private static function findGalleryCardByNeedle( $needle, $mustNot, $cards, &$usedAttachmentIds ) {
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
		 * Orchestrates output mappings outputs arrays mapping logic mapped schemas mappings properties fields values mapped parameters logic strings fields schemas maps arrays mapping logic logic maps mappings representations strings values mappings schema mappings values parameters parameters properties representations arrays properties representations strings outputs array outputs.
		 * * @param array $cards Representations mapping schema outputs array fields mapped mappings mappings string.
		 * @return array Gallery slots mapped mappings fields outputs.
		 */
		private static function buildGalleryFromCards( $cards ) {
			$usedAttachmentIds = array();

			$dining = self::findGalleryCardByNeedle( 'dining room', '', $cards, $usedAttachmentIds );
			if ( ! $dining ) {
				$dining = self::findGalleryCardByNeedle( 'dining', '', $cards, $usedAttachmentIds );
			}
			
			$living = self::findGalleryCardByNeedle( 'living room', '', $cards, $usedAttachmentIds );
			if ( ! $living ) {
				$living = self::findGalleryCardByNeedle( 'living', '', $cards, $usedAttachmentIds );
			}
			
			$kitchen = self::findGalleryCardByNeedle( 'kitchen', '', $cards, $usedAttachmentIds );

			$remaining = array();
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

			$gallery = array();
			$texts = array();

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

			return array(
				'dining_room_old_attachment_id' => $dining,
				'living_room_old_attachment_id' => $living,
				'kitchen_old_attachment_id' => $kitchen,
				'two_column_image_old_attachment_id' => null,
				'gallery_image_old_attachment_ids' => $gallery,
				'gallery_texts' => $texts
			);
		}

		/**
		 * Checks destination arrays representations strings schemas properties mapping string representations map parameters mapped string mapping array mapping array values strings representations schemas maps strings logic maps strings outputs arrays structures representations outputs mapping fields schema structures representations strings maps outputs.
		 * * @param string $slug Target slug values logic mapping logic string mappings fields string logic string representations structures properties string fields schemas outputs mapping mapped array string mappings outputs logic mappings values schemas mapping strings properties schemas outputs logic mappings outputs.
		 * @return int Payload array properties arrays outputs mapping map values.
		 */
		private static function getExistingStoreIdBySlug( $slug ) {
			$posts = get_posts(
				array(
					'name' => $slug,
					'post_type' => 'wpsl_stores',
					'posts_per_page' => 1,
					'post_status' => 'any',
					'fields' => 'ids'
				)
			);
			return ! empty( $posts ) ? (int) $posts[0] : 0;
		}

		/**
		 * Compiles strings values values fields string parameters maps mappings representations string properties mapped arrays arrays structures values mapped properties maps fields logic.
		 * * @param array $location Source elements arrays representations schema mapped parameters logic schemas map maps.
		 * @return string Valid mapping target fields outputs properties strings.
		 */
		private static function getImportSlug( $location ) {
			$postName = trim( isset( $location['post_name'] ) ? (string) $location['post_name'] : '' );
			if ( $postName !== '' ) {
				return $postName;
			}

			$title = trim( isset( $location['post_title'] ) ? (string) $location['post_title'] : '' );
			$fallback = sanitize_title( $title );
			if ( $fallback !== '' ) {
				return $fallback;
			}

			$oldId = isset( $location['post_id'] ) ? (int) $location['post_id'] : 0;
			if ( $oldId > 0 ) {
				return 'legacy-location-' . $oldId;
			}

			return 'legacy-location-' . wp_generate_password( 8, false, false );
		}

		/**
		 * Identifies valid mapped outputs string logic mappings.
		 * * @param array $location Parameters representations.
		 * @return string Target fields array mapped parameters properties representations outputs values arrays mapping values parameters schemas mappings arrays properties mappings maps schemas.
		 */
		private static function mapOldStatusToTargetStatus( $location ) {
			$old = strtolower( trim( isset( $location['post_status'] ) ? (string) $location['post_status'] : '' ) );
			if ( $old === 'draft' ) {
				return 'draft';
			}
			return 'publish';
		}

		/**
		 * Routes maps mapping mapping logic properties logic structures logic schemas properties outputs mapping mappings mappings fields mappings schema values fields mapping strings schema structures values strings.
		 * * @param int $storeId Output wrapper arrays elements logic fields strings parameters.
		 * @param string $fieldName Field map representations properties string logic map parameters outputs fields mapping values arrays properties arrays fields logic parameters arrays.
		 * @param mixed $value Schema parameter string schema properties.
		 * @param bool $isExisting Mappings mappings mappings schemas mapped parameters arrays elements schemas values mapping outputs array properties schemas mapping strings maps arrays properties.
		 * @param string $merge Maps outputs mapping mappings mapped structures.
		 */
		private static function updateFieldIfNeeded( $storeId, $fieldName, $value, $isExisting, $merge ) {
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
		 * Validates mapping schemas arrays schema mapped parameters mapping logic map mappings maps string map string.
		 * * @param int $storeId Parameters properties outputs fields representations mapping.
		 * @param string $fieldName Outputs mapped variables values outputs.
		 * @param bool $isExisting Validating arrays mapping array properties fields values logic string mappings properties.
		 * @param string $merge Configurations mapping properties mappings string.
		 * @return bool Logic array properties values schemas array representations schemas representations values strings schemas logic schemas string logic logic mappings mappings strings properties.
		 */
		private static function shouldUpdateField( $storeId, $fieldName, $isExisting, $merge ) {
			if ( ! $isExisting ) {
				return true;
			}
			if ( $merge !== 's2' ) {
				return true;
			}

			$current = get_post_meta( $storeId, $fieldName, true );
			$isEmpty = ( $current === '' || $current === null || $current === array() || $current === 0 || $current === '0' );
			
			if ( ! $isEmpty && is_string( $current ) ) {
				$trimmed = trim( (string) $current );
				$isEmpty = in_array( $trimmed, array( '[]', 'a:0:{}' ), true );
				
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

			if ( ! $isEmpty && in_array( $fieldName, array( 'our_expertise', 'facilities_and_features' ), true ) ) {
				$currentValues = array();
				if ( is_array( $current ) ) {
					$currentValues = $current;
				} else if ( is_string( $current ) ) {
					$maybe = maybe_unserialize( $current );
					if ( is_array( $maybe ) ) {
						$currentValues = $maybe;
					} else if ( trim( $current ) !== '' ) {
						$currentValues = array( $current );
					}
				}

				$filteredValues = array();
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
		 * Commits variables properties values schemas logic mapping array mapping mapping representations string strings map parameters maps string mappings parameters maps mapping string properties string outputs values mapped structures schema mapping outputs logic mappings parameters logic elements fields schemas fields fields structures representations representations array string mappings mapped maps arrays outputs map maps mappings mappings properties arrays schemas parameters mapping string fields schema schemas mappings values structures array properties mappings representations strings outputs representations mapping arrays strings mapping elements values values.
		 * * @param int $storeId Outputs maps fields values parameters outputs fields parameters fields array.
		 * @param string $fieldName Mapping schemas mapping maps fields mapping mapped properties arrays arrays values structures string mapping string structures properties.
		 * @param mixed $value Payload array structures arrays mappings outputs structures representations elements values values array properties mappings.
		 */
		private static function doUpdateField( $storeId, $fieldName, $value ) {
			if ( function_exists( 'update_field' ) ) {
				update_field( $fieldName, $value, $storeId );
				$current = get_post_meta( $storeId, $fieldName, true );
				$currentEmpty = ( $current === '' || $current === null || $current === array() );
				$intendedEmpty = ( $value === '' || $value === null || $value === array() );
				if ( ! $intendedEmpty && $currentEmpty ) {
					update_post_meta( $storeId, $fieldName, $value );
				}
				return;
			}

			update_post_meta( $storeId, $fieldName, $value );
		}

		/**
		 * Fetches mapped attachments schemas mappings mapping mappings array maps logic mapped elements mappings mapped strings schemas representations parameters mappings logic fields.
		 * * @param mixed $oldAttachmentRef Mappings mappings mappings array logic values structures mapped array strings.
		 * @param array $attachmentUrlMap Schema fields schemas maps properties strings strings array logic maps mapped maps parameters values.
		 * @param array &$uploadedCache Representation mapped representations outputs strings mapping properties elements mapped.
		 * @param int $storeId Target structure arrays mapping strings maps arrays properties.
		 * @param bool $dryRun Flag array representations elements logic properties logic elements schema outputs.
		 * @param array &$report Logic mapping representations schemas maps representations mapping strings maps parameters mappings mapping array parameters fields mappings logic mappings arrays schemas mapping outputs outputs strings properties logic logic mappings mapping string properties outputs maps mapping parameters array fields structures logic mapped mapping logic representations values outputs string parameters array map structures schemas mapping.
		 * @return int|null Valid value output variables representations mapping maps arrays outputs mapping map values map string representations string mapping strings array maps mappings mappings logic mapping mappings values string logic mappings values strings.
		 */
		private static function sideloadOldAttachmentId( $oldAttachmentRef, $attachmentUrlMap, &$uploadedCache, $storeId, $dryRun, &$report ) {
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
				$url = isset( $attachmentUrlMap[ $oldAttachmentId ] ) ? $attachmentUrlMap[ $oldAttachmentId ] : '';
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
				$report['missing_attachment_url'] = isset( $report['missing_attachment_url'] ) ? (int) $report['missing_attachment_url'] + 1 : 1;
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
				$report['sideload_failed'] = isset( $report['sideload_failed'] ) ? (int) $report['sideload_failed'] + 1 : 1;
				return null;
			}

			$uploadedCache[ (string) $cacheKey ] = (int) $newId;
			$report['images_sideloaded'] = isset( $report['images_sideloaded'] ) ? (int) $report['images_sideloaded'] + 1 : 1;
			
			return (int) $newId;
		}

		/**
		 * Fallback meta mapping.
		 * * @param int $storeId Output schema strings parameters.
		 * @param string $metaKey Object mapping mappings mapping values arrays values representations parameters representations values strings maps schema fields.
		 * @param mixed $value Schema parameters values strings schemas mapped mapping maps outputs fields structures logic mapping array mappings maps logic fields representations fields mapped elements mapped parameters representations logic array fields maps arrays elements properties logic logic.
		 * @param bool $isExisting Strings representations logic arrays strings string.
		 * @param string $merge Maps outputs array schemas values array mapped elements array outputs mapping arrays arrays schema outputs values properties logic mapping strings fields mapping schemas parameters properties outputs string parameters strings mapped mapping.
		 */
		private static function updatePostMetaIfNeeded( $storeId, $metaKey, $value, $isExisting, $merge ) {
			if ( ! $isExisting ) {
				update_post_meta( $storeId, $metaKey, $value );
				return;
			}

			if ( $merge !== 's2' ) {
				update_post_meta( $storeId, $metaKey, $value );
				return;
			}

			$current = get_post_meta( $storeId, $metaKey, true );
			$isEmpty = ( $current === '' || $current === null || $current === array() || $current === 0 || $current === '0' );
			if ( $isEmpty ) {
				update_post_meta( $storeId, $metaKey, $value );
			}
		}

		/**
		 * Binds classification relationships mapping maps values strings logic mapped array representations values maps string fields schemas properties.
		 * * @param int $storeId Reference outputs.
		 * @param string $nicename Structures mapping array arrays mappings parameters outputs representations maps arrays maps logic properties string logic mapped schemas logic mapping string strings logic mapped mapping logic properties mapped maps fields mapping schemas.
		 */
		private static function ensureWpslCategory( $storeId, $nicename ) {
			if ( ! $nicename ) {
				return;
			}

			$taxonomy = 'wpsl_store_category';

			$term = term_exists( $nicename, $taxonomy );
			if ( ! $term ) {
				wp_insert_term( $nicename, $taxonomy, array( 'slug' => $nicename ) );
			}

			wp_set_object_terms( $storeId, array( $nicename ), $taxonomy );
		}

		/**
		 * Top level import execution controller.
		 * * @param string $oldXml Upload parameter strings mappings arrays mapped schema properties properties properties maps values strings parameters arrays outputs representations representations string parameters structures string fields array logic mapped strings strings schemas properties values structures representations outputs string values maps maps fields arrays arrays mapping structures schemas schemas mappings array logic schemas values mappings mappings parameters arrays schemas mapping string string representations mapped schemas properties properties arrays mapped mapping schemas maps string values schema strings.
		 * @param string $slug Filter mapping fields schema schemas logic schemas schema logic mapping properties mapped parameters values maps maps arrays fields strings strings mapped maps structures schemas parameters maps structures fields arrays outputs.
		 * @param string $merge Mapping elements structures fields outputs parameters parameters maps string outputs properties maps mappings schemas mapped properties schema mapped array outputs schemas mappings array array mapped.
		 * @param bool $dryRun Representations logic elements values mappings maps strings values arrays values logic parameters maps properties.
		 * @return array Counters arrays mappings outputs properties schema schemas outputs logic logic maps values strings mapping array structures maps schemas representations string mapping array mapped string mapped string mappings schema strings values representations parameters parameters mapping fields schemas mapping schemas representations representations mapping outputs arrays string string.
		 */
		public static function importFromOldXml( $oldXml, $slug, $merge, $dryRun ) {
			$merge = strtolower( $merge );

			if ( ! $oldXml || ! file_exists( $oldXml ) ) {
				throw new Exception( 'Missing or unreadable --old-xml=' . $oldXml );
			}

			$slug = trim( (string) $slug );
			$xml = self::loadXml( $oldXml );
			$attachmentUrlMap = self::getAttachmentUrlMap( $xml );

			$uploadedAttachmentIdCache = array();
			$report = array(
				'locations_scanned' => 0,
				'stores_processed' => 0,
				'stores_created' => 0,
				'stores_existing' => 0,
				'missing_attachment_url' => 0,
				'sideload_failed' => 0,
				'missing_image_mapping' => 0,
				'images_sideloaded' => 0,
				'debug_dry_run' => $dryRun ? 1 : 0,
				'debug_merge' => (string) $merge
			);

			if ( function_exists( 'get_field_object' ) ) {
				$ourChoices = get_field_object( 'our_expertise' );
				$facChoices = get_field_object( 'facilities_and_features' );

				$ourChoiceList = array();
				if ( is_array( $ourChoices ) && ! empty( $ourChoices['choices'] ) && is_array( $ourChoices['choices'] ) ) {
					foreach ( $ourChoices['choices'] as $val => $label ) {
						$ourChoiceList[] = (string) $val . '=> ' . (string) $label;
					}
				}
				$facChoiceList = array();
				if ( is_array( $facChoices ) && ! empty( $facChoices['choices'] ) && is_array( $facChoices['choices'] ) ) {
					foreach ( $facChoices['choices'] as $val => $label ) {
						$facChoiceList[] = (string) $val . '=> ' . (string) $label;
					}
				}

				$report['acf_our_expertise_choices'] = ! empty( $ourChoiceList ) ? implode( ' | ', $ourChoiceList ) : '(empty/unavailable)';
				$report['acf_facilities_and_features_choices'] = ! empty( $facChoiceList ) ? implode( ' | ', $facChoiceList ) : '(empty/unavailable)';
			}

			$locations = self::getLocations( $xml );
			
			if ( empty( $locations ) ) {
				return $report;
			}

			foreach ( $locations as $payload ) {
				$report['locations_scanned'] = isset( $report['locations_scanned'] ) ? (int) $report['locations_scanned'] + 1 : 1;

				$oldPostName = $payload['post_name'];
				
				if ( $slug && $oldPostName !== $slug ) {
					continue;
				}

				$report['stores_processed'] = isset( $report['stores_processed'] ) ? (int) $report['stores_processed'] + 1 : 1;

				if ( $slug && $oldPostName === $slug ) {
					$report['debug_our_expertise_payload'] = json_encode( array_values( isset( $payload['our_expertise'] ) ? (array) $payload['our_expertise'] : array() ) );
					$report['debug_facilities_payload'] = json_encode( array_values( isset( $payload['facilities_and_features'] ) ? (array) $payload['facilities_and_features'] : array() ) );
				}

				$storeId = self::getExistingStoreIdBySlug( $oldPostName );
				$isExisting = $storeId > 0;

				if ( $dryRun ) {
					continue;
				}

				if ( ! $isExisting ) {
					$desc = isset( $payload['short_description'] ) ? (string) $payload['short_description'] : '';
					$postContentHtml = '';
					if ( $desc !== '' ) {
						$postContentHtml = '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
					}

					$createdId = wp_insert_post(
						array(
							'post_type' => 'wpsl_stores',
							'post_status' => self::mapOldStatusToTargetStatus( array( 'post_status' => $payload['post_status'] ) ),
							'post_name' => $oldPostName,
							'post_title' => isset( $payload['post_title'] ) ? (string) $payload['post_title'] : $oldPostName,
							'post_content' => $postContentHtml
						),
						true
					);

					if ( is_wp_error( $createdId ) ) {
						continue;
					}

					$storeId = (int) $createdId;
					$category = isset( $payload['taxonomy_category_nicename'] ) ? (string) $payload['taxonomy_category_nicename'] : '';
					self::ensureWpslCategory( $storeId, $category );
					$report['stores_created'] = isset( $report['stores_created'] ) ? (int) $report['stores_created'] + 1 : 1;
					$isExisting = true;
				} else {
					$report['stores_existing'] = isset( $report['stores_existing'] ) ? (int) $report['stores_existing'] + 1 : 1;
				}

				if ( ! $storeId ) {
					continue;
				}

				$wpslArray = isset( $payload['wpsl'] ) ? (array) $payload['wpsl'] : array();
				foreach ( $wpslArray as $metaKey => $metaValue ) {
					self::updatePostMetaIfNeeded( (int) $storeId, (string) $metaKey, $metaValue, $isExisting, $merge );
				}

				self::updateFieldIfNeeded( (int) $storeId, 'number_of_bedrooms', isset( $payload['bedrooms'] ) ? (int) $payload['bedrooms'] : 0, $isExisting, $merge );
				self::updateFieldIfNeeded( (int) $storeId, 'cqc_id', isset( $payload['cqc_id'] ) ? (string) $payload['cqc_id'] : '', $isExisting, $merge );

				self::updateFieldIfNeeded( (int) $storeId, 'first_section_heading', isset( $payload['first_section_heading'] ) ? (string) $payload['first_section_heading'] : '', $isExisting, $merge );
				self::updateFieldIfNeeded( (int) $storeId, 'first_section_content', isset( $payload['first_section_content'] ) ? (string) $payload['first_section_content'] : '', $isExisting, $merge );
				self::updateFieldIfNeeded( (int) $storeId, 'two_columns_heading', isset( $payload['two_columns_heading'] ) ? (string) $payload['two_columns_heading'] : '', $isExisting, $merge );
				self::updateFieldIfNeeded( (int) $storeId, 'two_columns_content', isset( $payload['two_columns_content'] ) ? (string) $payload['two_columns_content'] : '', $isExisting, $merge );

				self::updateFieldIfNeeded( (int) $storeId, 'two_columns_button_text', isset( $payload['two_columns_button_text'] ) ? (string) $payload['two_columns_button_text'] : '', $isExisting, $merge );
				self::updateFieldIfNeeded( (int) $storeId, 'two_columns_button_link', isset( $payload['two_columns_button_link'] ) ? (string) $payload['two_columns_button_link'] : '', $isExisting, $merge );
				self::updateFieldIfNeeded( (int) $storeId, 'walkthrough_360', isset( $payload['walkthrough_360'] ) ? (string) $payload['walkthrough_360'] : '', $isExisting, $merge );

				self::updateFieldIfNeeded( (int) $storeId, 'our_expertise', isset( $payload['our_expertise'] ) ? (array) $payload['our_expertise'] : array(), $isExisting, $merge );
				self::updateFieldIfNeeded( (int) $storeId, 'facilities_and_features', isset( $payload['facilities_and_features'] ) ? (array) $payload['facilities_and_features'] : array(), $isExisting, $merge );

				$oldRefs = isset( $payload['old_image_refs'] ) ? (array) $payload['old_image_refs'] : array();
				
				$imageFields = array(
					'first_section_image' => isset( $oldRefs['first_section_image'] ) ? $oldRefs['first_section_image'] : null,
					'two_columns_image' => isset( $oldRefs['two_columns_image'] ) ? $oldRefs['two_columns_image'] : null,
					'kitchen' => isset( $oldRefs['kitchen'] ) ? $oldRefs['kitchen'] : null,
					'living_room' => isset( $oldRefs['living_room'] ) ? $oldRefs['living_room'] : null,
					'dining_room' => isset( $oldRefs['dining_room'] ) ? $oldRefs['dining_room'] : null
				);

				foreach ( $imageFields as $fieldName => $oldAttachmentId ) {
					if ( ! self::shouldUpdateField( (int) $storeId, (string) $fieldName, $isExisting, $merge ) ) {
						continue;
					}

					if ( ! $oldAttachmentId ) {
						$report['missing_image_mapping'] = isset( $report['missing_image_mapping'] ) ? (int) $report['missing_image_mapping'] + 1 : 1;
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

				$galleryImages = isset( $oldRefs['gallery_images'] ) ? (array) $oldRefs['gallery_images'] : array();
				$galleryTexts = isset( $oldRefs['gallery_texts'] ) ? (array) $oldRefs['gallery_texts'] : array();
				
				for ( $i = 1; $i <= 10; $i ++ ) {
					$imgIndex = $i - 1;
					$imgOldAttachmentId = isset( $galleryImages[ $imgIndex ] ) ? $galleryImages[ $imgIndex ] : null;
					$galleryImageField = 'gallery_image_' . $i;
					$galleryTextField = 'gallery_text_' . $i;

					$slotText = isset( $galleryTexts[ $imgIndex ] ) ? (string) $galleryTexts[ $imgIndex ] : '';

					if ( self::shouldUpdateField( (int) $storeId, $galleryTextField, $isExisting, $merge ) ) {
						if ( $slotText !== '' ) {
							self::doUpdateField( (int) $storeId, $galleryTextField, $slotText );
						} else {
							$report['missing_image_mapping'] = isset( $report['missing_image_mapping'] ) ? (int) $report['missing_image_mapping'] + 1 : 1;
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

				$desc = isset( $payload['short_description'] ) ? (string) $payload['short_description'] : '';
				if ( $desc !== '' ) {
					$desiredContent = '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
					if ( ! $isExisting ) {
						wp_update_post(
							array(
								'ID' => (int) $storeId,
								'post_content' => $desiredContent
							),
							true
						);
					} else {
						$currentContent = (string) get_post_field( 'post_content', (int) $storeId );
						$currentContentTrim = trim( $currentContent );
						$shouldUpdateContent = ( $merge !== 's2' ) || ( $currentContentTrim === '' );
						if ( $shouldUpdateContent ) {
							wp_update_post(
								array(
									'ID' => (int) $storeId,
									'post_content' => $desiredContent
								),
								true
							);
						}
					}
				}
			}

			return $report;
		}

		/**
		 * Top level WP-CLI command executor binding arguments directly.
		 * * @param array $args Command arguments.
		 * @param array $assoc_args Bound array mapping arguments.
		 */
		public static function run( $args, $assoc_args ) {
			$oldXml = isset( $assoc_args['old-xml'] ) ? (string) $assoc_args['old-xml'] : '';
			if ( ! $oldXml || ! file_exists( $oldXml ) ) {
				WP_CLI::error( 'Missing or unreadable --old-xml=' . $oldXml );
				return;
			}

			$slug = isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '';
			$merge = strtolower( isset( $assoc_args['merge'] ) ? (string) $assoc_args['merge'] : 's2' );
			$dryRun = ! empty( $assoc_args['dry-run'] );

			WP_CLI::line( 'Loading old attachment URLs...' );
			$xml = self::loadXml( $oldXml );
			$attachmentUrlMap = self::getAttachmentUrlMap( $xml );
			WP_CLI::line( 'Attachments loaded: ' . count( $attachmentUrlMap ) );

			WP_CLI::line( 'Beginning locations iteration...' );

			$processed = 0;
			$uploadedAttachmentIdCache = array();
			$report = array(
				'locations_scanned' => 0,
				'stores_processed' => 0,
				'stores_created' => 0,
				'stores_existing' => 0,
				'missing_attachment_url' => 0,
				'sideload_failed' => 0,
				'missing_image_mapping' => 0,
				'images_sideloaded' => 0
			);

			$locations = self::getLocations( $xml );

			if ( empty( $locations ) ) {
				WP_CLI::line( 'No items found in WXR.' );
				return;
			}

			foreach ( $locations as $payload ) {
				$processed ++;
				$oldPostName = $payload['post_name'];
				
				if ( $slug && $oldPostName !== $slug ) {
					continue;
				}

				WP_CLI::line( sprintf( 'Importing location: %s (%s)', $oldPostName, $payload['post_title'] ) );

				$report['stores_processed'] = isset( $report['stores_processed'] ) ? (int) $report['stores_processed'] + 1 : 1;

				$storeId = self::getExistingStoreIdBySlug( $oldPostName );
				$isExisting = $storeId > 0;

				if ( ! $dryRun && ! $isExisting ) {
					$desc = isset( $payload['short_description'] ) ? (string) $payload['short_description'] : '';
					$postContentHtml = '';
					if ( $desc !== '' ) {
						$postContentHtml = '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
					}

					$storeId = wp_insert_post(
						array(
							'post_type' => 'wpsl_stores',
							'post_status' => self::mapOldStatusToTargetStatus( array( 'post_status' => $payload['post_status'] ) ),
							'post_name' => $oldPostName,
							'post_title' => $payload['post_title'],
							'post_content' => $postContentHtml
						),
						true
					);

					if ( is_wp_error( $storeId ) ) {
						WP_CLI::error( 'Failed to create wpsl_stores post: ' . $storeId->get_error_message() );
						continue;
					}

					$category = isset( $payload['taxonomy_category_nicename'] ) ? (string) $payload['taxonomy_category_nicename'] : '';
					self::ensureWpslCategory( (int) $storeId, $category );
					$report['stores_created'] = isset( $report['stores_created'] ) ? (int) $report['stores_created'] + 1 : 1;
				} else if ( $isExisting ) {
					$report['stores_existing'] = isset( $report['stores_existing'] ) ? (int) $report['stores_existing'] + 1 : 1;
				}

				if ( ! $storeId ) {
					WP_CLI::line( 'Dry run: would create/update store for slug: ' . $oldPostName );
					$isExisting = $isExisting;
				} else {
					WP_CLI::line( 'Store ID: ' . (int) $storeId . ' existing=' . ( $isExisting ? 'yes' : 'no' ) );
				}

				if ( $dryRun ) {
					WP_CLI::line( 'Dry run mode: skipping all write operations.' );
					continue;
				}

				if ( $storeId ) {
					$wpslArray = isset( $payload['wpsl'] ) ? (array) $payload['wpsl'] : array();
					foreach ( $wpslArray as $metaKey => $metaValue ) {
						self::updatePostMetaIfNeeded( (int) $storeId, (string) $metaKey, $metaValue, $isExisting, $merge );
					}

					self::updateFieldIfNeeded( (int) $storeId, 'number_of_bedrooms', isset( $payload['bedrooms'] ) ? (int) $payload['bedrooms'] : 0, $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'cqc_id', isset( $payload['cqc_id'] ) ? (string) $payload['cqc_id'] : '', $isExisting, $merge );

					self::updateFieldIfNeeded( (int) $storeId, 'first_section_heading', isset( $payload['first_section_heading'] ) ? (string) $payload['first_section_heading'] : '', $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'first_section_content', isset( $payload['first_section_content'] ) ? (string) $payload['first_section_content'] : '', $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'two_columns_heading', isset( $payload['two_columns_heading'] ) ? (string) $payload['two_columns_heading'] : '', $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'two_columns_content', isset( $payload['two_columns_content'] ) ? (string) $payload['two_columns_content'] : '', $isExisting, $merge );

					self::updateFieldIfNeeded( (int) $storeId, 'two_columns_button_text', isset( $payload['two_columns_button_text'] ) ? (string) $payload['two_columns_button_text'] : '', $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'two_columns_button_link', isset( $payload['two_columns_button_link'] ) ? (string) $payload['two_columns_button_link'] : '', $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'walkthrough_360', isset( $payload['walkthrough_360'] ) ? (string) $payload['walkthrough_360'] : '', $isExisting, $merge );

					self::updateFieldIfNeeded( (int) $storeId, 'our_expertise', isset( $payload['our_expertise'] ) ? (array) $payload['our_expertise'] : array(), $isExisting, $merge );
					self::updateFieldIfNeeded( (int) $storeId, 'facilities_and_features', isset( $payload['facilities_and_features'] ) ? (array) $payload['facilities_and_features'] : array(), $isExisting, $merge );

					$oldRefs = isset( $payload['old_image_refs'] ) ? (array) $payload['old_image_refs'] : array();

					$imageFields = array(
						'first_section_image' => isset( $oldRefs['first_section_image'] ) ? $oldRefs['first_section_image'] : null,
						'two_columns_image' => isset( $oldRefs['two_columns_image'] ) ? $oldRefs['two_columns_image'] : null,
						'kitchen' => isset( $oldRefs['kitchen'] ) ? $oldRefs['kitchen'] : null,
						'living_room' => isset( $oldRefs['living_room'] ) ? $oldRefs['living_room'] : null,
						'dining_room' => isset( $oldRefs['dining_room'] ) ? $oldRefs['dining_room'] : null
					);

					foreach ( $imageFields as $fieldName => $oldAttachmentId ) {
						if ( ! self::shouldUpdateField( (int) $storeId, (string) $fieldName, $isExisting, $merge ) ) {
							continue;
						}

						if ( ! $oldAttachmentId ) {
							$report['missing_image_mapping'] = isset( $report['missing_image_mapping'] ) ? (int) $report['missing_image_mapping'] + 1 : 1;
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

					$galleryImages = isset( $oldRefs['gallery_images'] ) ? (array) $oldRefs['gallery_images'] : array();
					$galleryTexts = isset( $oldRefs['gallery_texts'] ) ? (array) $oldRefs['gallery_texts'] : array();
					$maxSlots = 10;
					
					for ( $i = 1; $i <= $maxSlots; $i ++ ) {
						$imgIndex = $i - 1;
						$imgOldAttachmentId = isset( $galleryImages[ $imgIndex ] ) ? $galleryImages[ $imgIndex ] : null;
						$galleryImageField = 'gallery_image_' . $i;
						$galleryTextField = 'gallery_text_' . $i;

						$slotText = isset( $galleryTexts[ $imgIndex ] ) ? (string) $galleryTexts[ $imgIndex ] : '';

						if ( self::shouldUpdateField( (int) $storeId, $galleryTextField, $isExisting, $merge ) ) {
							if ( $slotText !== '' ) {
								self::doUpdateField( (int) $storeId, $galleryTextField, $slotText );
							} else {
								$report['missing_image_mapping'] = isset( $report['missing_image_mapping'] ) ? (int) $report['missing_image_mapping'] + 1 : 1;
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

					$desc = isset( $payload['short_description'] ) ? (string) $payload['short_description'] : '';
					if ( $desc !== '' ) {
						$desiredContent = '' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '';
						if ( ! $isExisting || $merge !== 's2' ) {
							wp_update_post(
								array(
									'ID' => (int) $storeId,
									'post_content' => $desiredContent
								),
								true
							);
						} else {
							$currentContent = (string) get_post_field( 'post_content', (int) $storeId );
							$currentContentTrim = trim( $currentContent );
							if ( $currentContentTrim === '' ) {
								wp_update_post(
									array(
										'ID' => (int) $storeId,
										'post_content' => $desiredContent
									),
									true
								);
							}
						}
					}

					WP_CLI::line( 'Mapped text fields + arrays + images (t3).' );
				}

				$oldRefs = isset( $payload['old_image_refs'] ) ? (array) $payload['old_image_refs'] : array();
				$kitchenRef = isset( $oldRefs['kitchen'] ) ? $oldRefs['kitchen'] : 'null';
				$livingRef = isset( $oldRefs['living_room'] ) ? $oldRefs['living_room'] : 'null';
				$diningRef = isset( $oldRefs['dining_room'] ) ? $oldRefs['dining_room'] : 'null';
				
				WP_CLI::line( 'Old image refs (attachment IDs): kitchen=' . $kitchenRef . ' living=' . $livingRef . ' dining=' . $diningRef );
				WP_CLI::line( 'Gallery old image IDs: ' . implode( ',', isset( $oldRefs['gallery_images'] ) ? (array) $oldRefs['gallery_images'] : array() ) );
			}

			WP_CLI::line( 'Locations scanned: ' . $processed );
			$report['locations_scanned'] = (int) $processed;
			WP_CLI::line( 'Import summary:' );
			
			$storesProcessed = isset( $report['stores_processed'] ) ? (int) $report['stores_processed'] : 0;
			$storesCreated = isset( $report['stores_created'] ) ? (int) $report['stores_created'] : 0;
			$storesExisting = isset( $report['stores_existing'] ) ? (int) $report['stores_existing'] : 0;
			$imagesSideloaded = isset( $report['images_sideloaded'] ) ? (int) $report['images_sideloaded'] : 0;
			$missingAttachmentUrl = isset( $report['missing_attachment_url'] ) ? (int) $report['missing_attachment_url'] : 0;
			$sideloadFailed = isset( $report['sideload_failed'] ) ? (int) $report['sideload_failed'] : 0;
			$missingImageMapping = isset( $report['missing_image_mapping'] ) ? (int) $report['missing_image_mapping'] : 0;
			
			WP_CLI::line( ' - stores_processed: ' . $storesProcessed );
			WP_CLI::line( ' - stores_created: ' . $storesCreated );
			WP_CLI::line( ' - stores_existing: ' . $storesExisting );
			WP_CLI::line( ' - images_sideloaded: ' . $imagesSideloaded );
			WP_CLI::line( ' - missing_attachment_url: ' . $missingAttachmentUrl );
			WP_CLI::line( ' - sideload_failed: ' . $sideloadFailed );
			WP_CLI::line( ' - missing_image_mapping: ' . $missingImageMapping );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'ivolve locations store-locator-import', array( 'IVolve_Store_Locator_Import_Command', 'run' ) );
}

if ( is_admin() ) {
	function ivolve_store_locator_upload_mimes( $mimes ) {
		$mimes['xml'] = 'application/xml';
		return $mimes;
	}
	add_filter( 'upload_mimes', 'ivolve_store_locator_upload_mimes' );

	function ivolve_store_locator_check_filetype( $data, $file, $filename, $mimes ) {
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		if ( $ext === 'xml' ) {
			$data['ext'] = 'xml';
			$data['type'] = 'application/xml';
		}
		return $data;
	}
	add_filter( 'wp_check_filetype_and_ext', 'ivolve_store_locator_check_filetype', 10, 4 );

	function ivolve_store_locator_admin_menu() {
		add_menu_page(
			'Store Locator Import',
			'Store Locator Import',
			'manage_options',
			'ivolve-store-locator-import',
			'ivolve_store_locator_admin_page'
		);
	}
	add_action( 'admin_menu', 'ivolve_store_locator_admin_menu' );

	function ivolve_store_locator_admin_page() {
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

	function ivolve_store_locator_admin_post() {
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
			array(
				'test_form' => false,
				'mimes' => array( 'xml' => 'application/xml, text/xml, text/plain' )
			)
		);

		if ( isset( $handled['error'] ) ) {
			wp_die( 'Upload failed: ' . esc_html( (string) $handled['error'] ) );
		}

		$oldXmlPath = isset( $handled['file'] ) ? (string) $handled['file'] : '';
		if ( ! $oldXmlPath || ! file_exists( $oldXmlPath ) ) {
			wp_die( 'Uploaded file missing on server.' );
		}

		@set_time_limit( 0 );

		try {
			$report = IVolve_Store_Locator_Import_Command::importFromOldXml( $oldXmlPath, $slug, $merge, $dryRun );
		} catch ( Exception $e ) {
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
	add_action( 'admin_post_ivolve_store_locator_importer', 'ivolve_store_locator_admin_post' );
}