<?php
/**
 * IVolve Store Locator migration helper.
 *
 * Plugin Name: IVolve Store Locator Importer
 * Description: Imports old `locations` CPT content into new `wpsl_stores` Store Locator fields (ACF + images).
 * Version: 0.1.8
 *
 * Place this file into `wp-content/mu-plugins/` on your staging environment,
 * then run via WP-CLI:
 *
 *   wp ivolve locations store-locator-import --old-xml="/path/to/Original Data/ivolve.WordPress.2026-03-23.xml" --slug="68-woodhurst-avenue"
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

			// CQC id: `acf/cqc-widget`.
			$cqcId = '';
			$cqcBlocks = self::extractAllAcfBlockJson( $postContent, 'acf/cqc-widget' );
			if ( ! empty( $cqcBlocks ) ) {
				$first = $cqcBlocks[0];
				$data = $first['data'] ?? [];
				$cqcId = (string) ( $data['cqc_id'] ?? '' );
			}

			// Image-column blocks for the first section and the "two columns" section.
			$twoImageColumns = self::extractImageColumnSections( $postContent );

			// Expertise/features: `acf/list-icon` blocks.
			$listIconTitles = self::extractListIconTitles( $postContent );
			// `our_expertise` has been missing on some stores due to ACF choice key/label mismatch.
			// Use a stable label-based normalizer to ensure we always write the expected checkbox values.
			$ourExpertise = self::normalizeOurExpertiseFromLabels( (array) ( $listIconTitles['our_expertise'] ?? [] ) );
			$facilitiesAndFeatures = self::normalizeCheckboxValues( 'facilities_and_features', (array) ( $listIconTitles['facilities_and_features'] ?? [] ) );
			// Keep facilities checkbox aligned with the dedicated bedrooms field.
			if ( is_int( $bedrooms ) && $bedrooms > 0 && ! in_array( 'Bedrooms', $facilitiesAndFeatures, true ) ) {
				$facilitiesAndFeatures[] = 'Bedrooms';
			}

			// Content cards: `acf/content-cards` blocks -> kitchen/living/dining and gallery slots.
			$cards = self::extractContentCards( $postContent );

			$orderedGallery = self::buildGalleryFromCards( $cards );

			$heroImageOldId = self::extractPageHeaderHeroImageAttachmentId( $postContent );
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
				// Fallback to the first image-column when old content only has one.
				'two_columns_image' => $twoImageColumns['second']['image_old_attachment_id']
					?? $twoImageColumns['first']['image_old_attachment_id']
					?? null,
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
			// Fallback: sometimes `location_description` isn't set as expected.
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
				// Elementor's ACF_URL / href sanitization expects a string URL, not the full Link array.
				'two_columns_button_link' => (string) ( $twoColumnsButton['link']['url'] ?? '' ),
				'walkthrough_360' => (string) $walkthrough360,
				'short_description' => $locationDescription,
				// Image refs are old attachment IDs; t3 will upload and set ACF fields.
				'old_image_refs' => $oldImageRefs,
			];
		}

		/**
		 * Extract ACF JSON data objects from blocks like:
		 *   <!-- wp:acf/cqc-widget {"name":"acf/cqc-widget","data":{...}} /-->
		 *
		 * @return array<int, array> list of decoded JSON objects
		 */
		private static function extractAllAcfBlockJson( string $postContent, string $blockSlug ): array {
			$out = [];
			$needle = '<!-- wp:' . $blockSlug;
			$offset = 0;
			while ( true ) {
				$pos = strpos( $postContent, $needle, $offset );
				if ( $pos === false ) {
					break;
				}

				$braceStart = strpos( $postContent, '{', $pos );
				if ( $braceStart === false ) {
					break;
				}

				$braceEnd = self::findMatchingBrace( $postContent, $braceStart );
				if ( $braceEnd === -1 ) {
					break;
				}

				$jsonStr = substr( $postContent, $braceStart, $braceEnd - $braceStart + 1 );
				$decoded = json_decode( $jsonStr, true );
				if ( is_array( $decoded ) ) {
					$out[] = $decoded;
				}

				$offset = $braceEnd + 1;
			}
			return $out;
		}

		/**
		 * Normalize "Our Expertise" checkbox items using visible labels.
		 *
		 * For "Mental Health" we intentionally include both "Mental Health" and
		 * "Mental Health Needs" when the old XML provides "Mental Health Needs".
		 * This increases the odds that we match the ACF checkbox choice value,
		 * since the exact stored choice value seems inconsistent between stores.
		 *
		 * @param array<int, string> $items
		 * @return array<int, string>
		 */
		private static function normalizeOurExpertiseFromLabels( array $items ): array {
			$order = [ 'Autism', 'Learning Disabilities', 'Mental Health', 'Mental Health Needs', 'Complex Needs' ];
			$out = [];

			$seen = [];
			// "Mental Health Needs" sometimes needs to be preserved as-is for ACF choice-value matching.
			$map = [
				'autism' => [ 'Autism' ],
				'learning disabilities' => [ 'Learning Disabilities' ],
				'mental health' => [ 'Mental Health' ],
				'mental health needs' => [ 'Mental Health', 'Mental Health Needs' ],
				'complex needs' => [ 'Complex Needs' ],
			];
			foreach ( $items as $raw ) {
				$v = trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( $v === '' ) {
					continue;
				}
				$key = strtolower( preg_replace( '/\s+/u', ' ', $v ) );
				if ( isset( $map[ $key ] ) ) {
					foreach ( $map[ $key ] as $canon ) {
						$seen[ (string) $canon ] = true;
					}
				}
			}

			foreach ( $order as $opt ) {
				if ( isset( $seen[ $opt ] ) ) {
					$out[] = $opt;
				}
			}

			return $out;
		}

		private static function extractPageHeaderHeroImageAttachmentId( string $postContent ): ?int {
			$blocks = self::extractAllAcfBlockJson( $postContent, 'acf/page-header' );
			if ( empty( $blocks ) ) {
				return null;
			}
			$data = $blocks[0]['data'] ?? [];
			if ( ! is_array( $data ) ) {
				return null;
			}
			$img = $data['image'] ?? null;
			$img = is_numeric( $img ) ? (int) $img : null;
			return $img && $img > 0 ? $img : null;
		}

		/**
		 * Extract the "View Service Profile" PDF button (acf/button block) from old content.
		 *
		 * @return array<string, mixed>|null { 'text' => string, 'link' => array{url:string,title:string,target:string} }
		 */
		private static function extractTwoColumnsButtonFromContent( string $postContent ): ?array {
			$buttons = self::extractAllAcfBlockJson( $postContent, 'acf/button' );
			if ( empty( $buttons ) ) {
				return null;
			}

			$pdfButtons = [];
			foreach ( $buttons as $block ) {
				$data = $block['data'] ?? [];
				if ( ! is_array( $data ) ) {
					continue;
				}
				$link = $data['button_link'] ?? [];
				if ( ! is_array( $link ) ) {
					continue;
				}
				$url = (string) ( $link['url'] ?? '' );
				$title = (string) ( $link['title'] ?? '' );
				$target = (string) ( $link['target'] ?? '' );

				if ( ! $url ) {
					continue;
				}

				// Prefer a PDF, because that's what the new field expects.
				$lower = strtolower( $url );
				if ( substr( $lower, -4 ) === '.pdf' ) {
					$pdfButtons[] = [
						'text' => $title,
						'link' => [
							'url' => $url,
							'title' => $title,
							'target' => $target,
						],
					];
				}
			}

			if ( ! empty( $pdfButtons ) ) {
				// Choose the last PDF button found (most specific/most recent in content).
				return $pdfButtons[ count( $pdfButtons ) - 1 ];
			}

			// Fallback: last button with a URL.
			for ( $i = count( $buttons ) - 1; $i >= 0; $i -- ) {
				$data = $buttons[ $i ]['data'] ?? [];
				if ( ! is_array( $data ) ) continue;
				$link = $data['button_link'] ?? [];
				if ( ! is_array( $link ) ) continue;
				$url = (string) ( $link['url'] ?? '' );
				if ( ! $url ) continue;
				$title = (string) ( $link['title'] ?? '' );
				$target = (string) ( $link['target'] ?? '' );

				return [
					'text' => $title,
					'link' => [
						'url' => $url,
						'title' => $title,
						'target' => $target,
					],
				];
			}

			return null;
		}

		private static function extractWalkthrough360FromContent( string $postContent ): string {
			// Look for a YouTube embed iframe and reuse it as-is.
			// If not found, return empty so merge_strategy=s2 will preserve existing values.
			$pattern = '/(<iframe[^>]+src="https?:\/\/www\.youtube\.com\/embed\/[^"]+"[^>]*><\/iframe>)/i';
			if ( preg_match( $pattern, $postContent, $m ) ) {
				return (string) $m[1];
			}

			return '';
		}

		private static function findMatchingBrace( string $s, int $startIndex ): int {
			$depth = 0;
			$inString = false;
			$escape = false;

			$len = strlen( $s );
			for ( $i = $startIndex; $i < $len; $i++ ) {
				$ch = $s[ $i ];

				if ( $inString ) {
					if ( $escape ) {
						$escape = false;
						continue;
					}
					if ( $ch === '\\' ) {
						$escape = true;
						continue;
					}
					if ( $ch === '"' ) {
						$inString = false;
					}
					continue;
				}

				if ( $ch === '"' ) {
					$inString = true;
					continue;
				}

				if ( $ch === '{' ) {
					$depth ++;
				} else if ( $ch === '}' ) {
					$depth --;
					if ( $depth === 0 ) {
						return $i;
					}
				}
			}

			return -1;
		}

		/**
		 * Extract the first two `acf/image-column` blocks: first section and the "two columns" section.
		 *
		 * @return array{
		 *   first: array{heading:string, content:string, image_old_attachment_id:?int},
		 *   second: array{heading:string, content:string, image_old_attachment_id:?int}
		 * }
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

			// Slice each image-column block content so we can pull its own heading + paragraphs.
			$segments = [];
			$offset = 0;
			$openTag = '<!-- wp:acf/image-column';
			$closeTag = '<!-- /wp:acf/image-column -->';
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

			$extractHeading = function ( string $segment ): string {
				if ( preg_match( '/<!-- wp:heading.*?-->\s*<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $segment, $m ) ) {
					return trim( html_entity_decode( wp_strip_all_tags( $m[1] ) ) );
				}
				// Fallback: first h2 in segment.
				if ( preg_match( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', $segment, $m ) ) {
					return trim( html_entity_decode( wp_strip_all_tags( $m[1] ) ) );
				}
				return '';
			};

			$extractParagraphs = function ( string $segment, bool $truncateAtFirstButton = true ): array {
				if ( $truncateAtFirstButton ) {
					$buttonPos = strpos( $segment, '<!-- wp:acf/button' );
					$beforeButton = $buttonPos === false ? $segment : substr( $segment, 0, $buttonPos );

					// Only take paragraphs before the first CTA button for the first section.
					if ( ! preg_match_all( '/<!-- wp:paragraph.*?-->\s*<p[^>]*>(.*?)<\/p>\s*<!-- \/wp:paragraph -->/s', $beforeButton, $matches ) ) {
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

				// For the second section we must not truncate at buttons, and we also
				// can't rely on Gutenberg `<!-- wp:paragraph -->` comments being present.
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
				$candidates = [
					'image',
					'image_id',
					'imageId',
					'image_file',
					'imageFile',
					'image_old_attachment_id',
					'image_attachment_id',
					'image_old_id',
					'attachment_id',
					'old_attachment_id',
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

				// Last resort: look for class `wp-image-123`.
				if ( preg_match( '/wp-image-(\d+)/', $segment, $m ) ) {
					$id = (int) $m[1];
					return $id > 0 ? $id : null;
				}

				// Another fallback: extract the image URL from the HTML and let the importer
				// sideload it directly if the old attachment id isn't available.
				if ( preg_match( '/<img[^>]+src="([^"]+)"/i', $segment, $m ) ) {
					$url = (string) $m[1];
					if ( str_starts_with( $url, 'http' ) ) {
						return $url;
					}
				}

				return null;
			};

			for ( $i = 0; $i < 2; $i ++ ) {
				$segment = $segments[ $i ] ?? '';
				if ( ! $segment ) {
					continue;
				}

				$heading = $extractHeading( $segment );
				// For the first section we keep the previous "stop before CTA button" behavior.
				// The second image-column often has its main paragraph after the CTA button,
				// so we must not truncate there.
				$paragraphs = $extractParagraphs( $segment, $i === 0 );

				// Heuristic:
				// The second image-column often includes a short "tagline" paragraph
				// followed by the main paragraph the template actually expects.
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

		private static function extractListIconTitles( string $postContent ): array {
			$out = [
				'our_expertise' => [],
				'facilities_and_features' => [],
			];

			$blocks = self::extractAllAcfBlockJson( $postContent, 'acf/list-icon' );
			// In your sample, the first list-icon is "Our Expertise" and the second is "Facilities and Features".
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
					// Fallback: infer from keys.
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
		 * @param array<int, string> $items
		 * @return array<int, string>
		 */
		private static function normalizeCheckboxValues( string $fieldName, array $items ): array {
			$allowed = self::getCheckboxAllowedValues( $fieldName );
			if ( empty( $allowed ) ) {
				return $items;
			}

			$choiceValueMap = self::buildCheckboxChoiceValueMap( $fieldName );
			// If ACF is unavailable, fall back to assuming choice stored values == labels.
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

				// Known old->new label variants based on XML.
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

			// Fallback: if ACF choice mapping fails (e.g. key/label direction mismatch),
			// still attempt to map based on known visible labels from the old XML.
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
				// Preserve the expected checkbox order.
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
		 * @return array<string, mixed> map of normalized string -> stored value
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
		 * @return array<int, string>
		 */
		private static function getCheckboxAllowedValues( string $fieldName ): array {
			// Prefer the real ACF checkbox choices, so we don't guess internal stored values.
			if ( function_exists( 'get_field_object' ) ) {
				$field = get_field_object( $fieldName );
				if ( is_array( $field ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$choices = $field['choices'];
					// For ACF checkboxes, values are stored as the choice "key" (array key).
					return array_values( array_keys( $choices ) );
				}
			}

			// Fallback hard-coded defaults (used only if ACF field object isn't available).
			if ( $fieldName === 'our_expertise' ) {
				return [ 'Autism', 'Learning Disabilities', 'Mental Health', 'Complex Needs' ];
			}
			if ( $fieldName === 'facilities_and_features' ) {
				return [ 'Bedrooms', 'Mixed Gender', 'Communal Spaces', 'Large Garden' ];
			}
			return [];
		}

		/**
		 * @return array<int, array{heading:string, old_attachment_id:int|null}>
		 */
		private static function extractContentCards( string $postContent ): array {
			$blocks = self::extractAllAcfBlockJson( $postContent, 'acf/content-cards' );
			if ( empty( $blocks ) ) {
				return [];
			}

			// Usually only one content-cards block per post.
			$data = $blocks[0]['data'] ?? [];
			if ( ! is_array( $data ) ) {
				return [];
			}

			$cardsCount = isset( $data['cards'] ) ? (int) $data['cards'] : 0;

			$cards = [];
			// Primary path: `cards` count.
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

			// Fallback: infer indexes from keys.
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
		 * Determine which card images become kitchen/living/dining and which populate gallery slots.
		 *
		 * @param array<int, array{heading:string, old_attachment_id:int|null}> $cards
		 * @return array<string, mixed>
		 */
		private static function buildGalleryFromCards( array $cards ): array {
			$kitchen = null;
			$living = null;
			$dining = null;
			$remaining = [];

			$usedAttachmentIds = [];

			$findByNeedle = function ( string $needle, string $mustNot = '' ) use ( $cards, &$usedAttachmentIds ): ?int {
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
			};

			$dining = $findByNeedle( 'dining room' ) ?? $findByNeedle( 'dining' );
			$living = $findByNeedle( 'living room' ) ?? $findByNeedle( 'living' );
			$kitchen = $findByNeedle( 'kitchen' );

			// Remaining cards in original order.
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

			// Your 68 example shows gallery slot 1..3 labeled as Dining, Living, Kitchen.
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

			// `first_section_image` / `two_columns_image` appear in the new store, but the old post content
			// doesn't always have a direct "hero" image in the content-cards block.
			// In your sample, the first-section image is likely sourced from the page-header image.
			// TODO t2: we can attempt to parse the page-header image_file if needed.
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

		private static function mapOldStatusToTargetStatus( array $location ): string {
			$old = strtolower( trim( (string) ( $location['post_status'] ?? '' ) ) );
			if ( $old === 'draft' ) {
				return 'draft';
			}
			return 'publish';
		}

		private static function updateFieldIfNeeded( int $storeId, string $fieldName, $value, bool $isExisting, string $merge ): void {
			// For new stores: always set.
			if ( ! $isExisting ) {
				self::doUpdateField( $storeId, $fieldName, $value );
				return;
			}

			if ( $merge !== 's2' ) {
				// Only s2 is supported in this initial implementation.
				self::doUpdateField( $storeId, $fieldName, $value );
				return;
			}

			// Unify s2 decision logic with shouldUpdateField().
			if ( self::shouldUpdateField( $storeId, $fieldName, $isExisting, $merge ) ) {
				self::doUpdateField( $storeId, $fieldName, $value );
			}
		}

		private static function shouldUpdateField( int $storeId, string $fieldName, bool $isExisting, string $merge ): bool {
			if ( ! $isExisting ) {
				return true;
			}
			if ( $merge !== 's2' ) {
				return true;
			}

			$current = get_post_meta( $storeId, $fieldName, true );
			$isEmpty = ( $current === '' || $current === null || $current === [] || $current === 0 || $current === '0' );
			// Some ACF fields (like "Link") serialize empty state as PHP's `a:0:{}`.
			if ( ! $isEmpty && is_string( $current ) ) {
				$trimmed = trim( (string) $current );
				$isEmpty = in_array( $trimmed, [ '[]', 'a:0:{}' ], true );
				// If a previous run stored a wrong Link array into a field that the template expects as URL string,
				// the meta value will look like `a:3:{...}`. Treat that as replaceable under s2.
				if ( $fieldName === 'two_columns_button_link' && str_starts_with( $trimmed, 'a:' ) ) {
					$isEmpty = true;
				}

				// If we previously mapped the second section paragraph incorrectly as the short tagline,
				// treat it as replaceable so the corrected mapping can overwrite it under s2.
				if ( $fieldName === 'two_columns_content' ) {
					$cur = $trimmed;
					$hasWhereCare = strpos( $cur, 'Where care, community, and independence come together to thrive.' ) !== false;
					$hasBuildingIndependence = strpos( $cur, 'Building independence and belonging through care' ) !== false;
					if ( $hasWhereCare && ! $hasBuildingIndependence ) {
						$isEmpty = true;
					}
				}

				// If the second section image was previously mapped to the same image as the first section,
				// treat it as replaceable under s2 so we can correct it.
				if ( $fieldName === 'two_columns_image' ) {
					$firstImg = get_post_meta( $storeId, 'first_section_image', true );
					$isSame = (int) $firstImg === (int) $trimmed;
					if ( $isSame ) {
						$isEmpty = true;
					}
				}
			}

			// Compare based on raw values (covers numeric meta stored as ints/bools).
			if ( ! $isEmpty && $fieldName === 'two_columns_image' ) {
				$firstImg = get_post_meta( $storeId, 'first_section_image', true );
				if ( (int) $firstImg > 0 && (int) $current === (int) $firstImg ) {
					$isEmpty = true;
				}
			}

			// Heading: if second heading accidentally got mapped from the first section, overwrite under s2.
			if ( ! $isEmpty && $fieldName === 'two_columns_heading' ) {
				$firstHeading = get_post_meta( $storeId, 'first_section_heading', true );
				if ( is_string( $current ) && $current !== '' && is_string( $firstHeading ) && trim( $current ) === trim( $firstHeading ) ) {
					$isEmpty = true;
				}
			}

			// Checkbox fields: if stored values don't match the current expected option set,
			// treat as replaceable so normalized values can be written under s2.
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

				$currentValues = array_values(
					array_filter(
						array_map(
							static function ( $v ): string {
								return trim( (string) $v );
							},
							$currentValues
						),
						static function ( string $v ): bool {
							return $v !== '';
						}
					)
				);

				$normalizedCurrent = self::normalizeCheckboxValues( $fieldName, $currentValues );
				// If any stored value is legacy/unrecognized, allow overwrite.
				if ( count( $normalizedCurrent ) !== count( $currentValues ) ) {
					$isEmpty = true;
				}
			}

			return $isEmpty;
		}

		private static function doUpdateField( int $storeId, string $fieldName, $value ): void {
			if ( function_exists( 'update_field' ) ) {
				update_field( $fieldName, $value, $storeId );
				// Some ACF setups may silently skip writes when field objects/choices
				// cannot be resolved at runtime. If we intended to write a non-empty value
				// but the meta is still empty, force-save via post meta.
				$current = get_post_meta( $storeId, $fieldName, true );
				$currentEmpty = ( $current === '' || $current === null || $current === [] );
				$intendedEmpty = ( $value === '' || $value === null || $value === [] );
				if ( ! $intendedEmpty && $currentEmpty ) {
					update_post_meta( $storeId, $fieldName, $value );
				}
				return;
			}

			// Fallback when ACF is unavailable (shouldn't happen on staging).
			update_post_meta( $storeId, $fieldName, $value );
		}

		/**
		 * @param array<int, string> $attachmentUrlMap old attachment id => url
		 * @param array<string, int> $uploadedCache cache old id/url => new attachment id
		 * @param int|string|null $oldAttachmentRef old attachment id (int) OR old attachment URL (string)
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
				// Debug: confirm whether the admin UI checkbox was interpreted correctly.
				'debug_dry_run' => $dryRun ? 1 : 0,
				'debug_merge' => (string) $merge,
			];

			// Debug: show ACF checkbox choice keys/labels so we can map correctly.
			// Especially useful when a checkbox appears checked in ACF but isn't persisted by our importer.
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

					// Debug for the slug-filtered run: confirm extracted checkbox payload.
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
						$createdId = wp_insert_post(
							[
								'post_type' => 'wpsl_stores',
								'post_status' => self::mapOldStatusToTargetStatus( $location ),
								'post_name' => $oldPostName,
								'post_title' => (string) ( $location['post_title'] ?? $oldPostName ),
								'post_content' => ( function () use ( $payload ) {
									$desc = (string) ( $payload['short_description'] ?? '' );
									if ( ! $desc ) {
										return '';
									}
									$descEsc = esc_html( $desc );
									return '<!-- wp:paragraph -->' . "\n" . '<p>' . $descEsc . '</p>' . "\n" . '<!-- /wp:paragraph -->';
								} )(),
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

					// Store Locator meta.
					foreach ( (array) ( $payload['wpsl'] ?? [] ) as $metaKey => $metaValue ) {
						self::updatePostMetaIfNeeded( (int) $storeId, (string) $metaKey, $metaValue, $isExisting, $merge );
					}

					// ACF fields.
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

					// Images (single-image fields).
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

							// The template's hero image is often rendered from the post thumbnail,
							// not the ACF image field. Populate it from the first_section_image.
							if ( $fieldName === 'first_section_image' && function_exists( 'set_post_thumbnail' ) ) {
								$currentThumb = get_post_meta( (int) $storeId, '_thumbnail_id', true );
								$isThumbEmpty = ( $currentThumb === '' || $currentThumb === null || (int) $currentThumb === 0 );

								// Under merge_strategy=s2 we only fill when empty.
								if ( ! $isExisting || $merge !== 's2' || $isThumbEmpty ) {
									set_post_thumbnail( (int) $storeId, (int) $newAttachmentId );
								}
							}
						}
					}

					// Gallery slots.
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

					// Ensure hero image renders even if ACF image fields were already present
					// but the post thumbnail was not set (common when importing older records).
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

					// Fill post_content only when blank (merge_strategy=s2), because the
					// Store Locator template may render the short description from it.
					$desc = (string) ( $payload['short_description'] ?? '' );
					if ( $desc ) {
						$desiredContent = '<!-- wp:paragraph -->' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '<!-- /wp:paragraph -->';
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
		 * WP-CLI command: `wp ivolve locations store-locator-import ...`
		 *
		 * Required:
		 * - --old-xml=/path/to/original-export.xml
 *
		 * Optional:
		 * - --slug=68-woodhurst-avenue (limit)
		 * - --merge=s2 (fill blanks only) [default]
		 * - --dry-run=1
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

			// In this first todo we only set up parsing + iteration.
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
						$storeId = wp_insert_post(
							[
								'post_type' => 'wpsl_stores',
								'post_status' => self::mapOldStatusToTargetStatus( $location ),
								'post_name' => $oldPostName,
								'post_title' => $location['post_title'],
								'post_content' => ( function () use ( $payload ) {
									$desc = (string) ( $payload['short_description'] ?? '' );
									if ( ! $desc ) {
										return '';
									}
									$descEsc = esc_html( $desc );
									return '<!-- wp:paragraph -->' . "\n" . '<p>' . $descEsc . '</p>' . "\n" . '<!-- /wp:paragraph -->';
								} )(),
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
						// Dry run case: we still want to show mapping summary.
						WP_CLI::line( 'Dry run: would create/update store for slug: ' . $oldPostName );
						$isExisting = $isExisting; // keep.
					}

					WP_CLI::line( 'Store ID: ' . (int) $storeId . ' existing=' . ( $isExisting ? 'yes' : 'no' ) );

					if ( $dryRun ) {
						WP_CLI::line( 'Dry run mode: skipping all write operations.' );
						return;
					}

					if ( $storeId ) {
						// Store Locator meta.
						foreach ( $payload['wpsl'] as $metaKey => $metaValue ) {
							self::updatePostMetaIfNeeded( (int) $storeId, (string) $metaKey, $metaValue, $isExisting, $merge );
						}

						// ACF fields.
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

						// Images (t3): sideload attachment URLs, then set ACF image fields.
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

								// Populate the post thumbnail so the template can show the hero image.
								if ( $fieldName === 'first_section_image' && function_exists( 'set_post_thumbnail' ) ) {
									$currentThumb = get_post_meta( (int) $storeId, '_thumbnail_id', true );
									$isThumbEmpty = ( $currentThumb === '' || $currentThumb === null || (int) $currentThumb === 0 );

									if ( ! $isExisting || $merge !== 's2' || $isThumbEmpty ) {
										set_post_thumbnail( (int) $storeId, (int) $newAttachmentId );
									}
								}
							}
						}

						// Gallery slots: `gallery_image_1..gallery_image_10` + `gallery_text_1..gallery_text_10`.
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
									// Field exists but old extraction didn't produce text for this slot.
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

						// Ensure hero image renders even if ACF image fields already existed
						// but post thumbnail was missing.
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

						// Fill post_content from the old `location_description` when blank (s2),
						// because the Store Locator template may render this field directly.
						$desc = (string) ( $payload['short_description'] ?? '' );
						if ( $desc ) {
							$desiredContent = '<!-- wp:paragraph -->' . "\n" . '<p>' . esc_html( $desc ) . '</p>' . "\n" . '<!-- /wp:paragraph -->';
							if ( ! $isExisting || $merge !== 's2' ) {
								// For newly created records, it's already set on insert; for non-s2, refresh it.
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

					// For images, we only log the old attachment IDs here; t3 will sideload + update ACF image fields.
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

// Register command only when WP-CLI is present.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'ivolve locations store-locator-import', [ IVolve_Store_Locator_Import_Command::class, 'run' ] );
}

// Dashboard UI: allow running the importer from WP Admin.
if ( is_admin() ) {
	// WordPress often blocks `.xml` uploads by default; allow it explicitly so the dashboard form works.
	add_filter(
		'upload_mimes',
		function ( $mimes ) {
			// Accept common WXR content types.
			$mimes['xml'] = 'application/xml';
			return $mimes;
		}
	);

	// Some setups ignore `upload_mimes` and rely on extension-to-mime detection.
	// This filter loosens that detection for `.xml`.
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

			// Prevent web timeouts for large imports.
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

