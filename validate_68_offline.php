<?php
/**
 * Offline validation helper for the `68-woodhurst-avenue` mapping.
 *
 * It does NOT require WordPress/ACF; it only parses the two WXR XMLs:
 * - old export (locations + attachments)
 * - new export (wpsl_stores + already-mapped meta)
 *
 * Usage:
 *   php validate_68_offline.php --old-xml="C:\\path\\Original Data\\ivolve.WordPress.2026-03-23.xml" --new-xml="C:\\path\\New Site Data\\ivolve.WordPress.2026-03-23 (1).xml"
 */

function findMatchingBrace(string $s, int $startIndex): int {
	$depth = 0;
	$inString = false;
	$escape = false;
	$len = strlen($s);
	for ($i = $startIndex; $i < $len; $i++) {
		$ch = $s[$i];
		if ($inString) {
			if ($escape) {
				$escape = false;
				continue;
			}
			if ($ch === '\\') {
				$escape = true;
				continue;
			}
			if ($ch === '"') {
				$inString = false;
			}
			continue;
		}
		if ($ch === '"') {
			$inString = true;
			continue;
		}
		if ($ch === '{') {
			$depth++;
		} elseif ($ch === '}') {
			$depth--;
			if ($depth === 0) {
				return $i;
			}
		}
	}
	return -1;
}

function extractAllAcfBlockJson(string $postContent, string $blockSlug): array {
	$out = [];
	$needle = '<!-- wp:' . $blockSlug;
	$offset = 0;
	while (true) {
		$pos = strpos($postContent, $needle, $offset);
		if ($pos === false) break;

		$braceStart = strpos($postContent, '{', $pos);
		if ($braceStart === false) break;

		$braceEnd = findMatchingBrace($postContent, $braceStart);
		if ($braceEnd === -1) break;

		$jsonStr = substr($postContent, $braceStart, $braceEnd - $braceStart + 1);
		$decoded = json_decode($jsonStr, true);
		if (is_array($decoded)) {
			$out[] = $decoded;
		}

		$offset = $braceEnd + 1;
	}
	return $out;
}

function extractImageColumnHeadingAndParagraphs(string $postContent): array {
	$out = ['heading' => '', 'content' => ''];
	$start = strpos($postContent, '<!-- wp:acf/image-column');
	if ($start === false) return $out;

	$end = strpos($postContent, '<!-- /wp:acf/image-column -->', $start);
	if ($end === false) $end = strlen($postContent);
	$segment = substr($postContent, $start, $end - $start);

	if (preg_match('/<!-- wp:heading.*?-->\s*<h2[^>]*>(.*?)<\/h2>/s', $segment, $m)) {
		$out['heading'] = trim(html_entity_decode(strip_tags($m[1])));
	}

	$buttonPos = strpos($segment, '<!-- wp:acf/button');
	$beforeButton = $buttonPos === false ? $segment : substr($segment, 0, $buttonPos);

	if (preg_match_all('/<!-- wp:paragraph.*?-->\s*<p[^>]*>(.*?)<\/p>\s*<!-- \/wp:paragraph -->/s', $beforeButton, $matches)) {
		$parts = [];
		foreach ($matches[1] as $pHtml) {
			$text = strip_tags($pHtml);
			$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$text = preg_replace('/\s+/u', ' ', trim($text));
			if ($text !== '') $parts[] = $text;
		}
		$out['content'] = implode("\n", $parts);
	}

	return $out;
}

function extractListIconTitles(string $postContent): array {
	$out = ['our_expertise' => [], 'facilities_and_features' => []];
	$blocks = extractAllAcfBlockJson($postContent, 'acf/list-icon');
	foreach ($blocks as $idx => $block) {
		$data = $block['data'] ?? [];
		if (!is_array($data)) continue;

		$items = [];
		$n = isset($data['list_items']) ? (int)$data['list_items'] : 0;
		if ($n > 0) {
			for ($i = 0; $i < $n; $i++) {
				$key = 'list_items_' . $i . '_item_title';
				if (isset($data[$key])) $items[] = (string)$data[$key];
			}
		} else {
			$indexes = [];
			foreach ($data as $k => $_v) {
				if (preg_match('/^list_items_(\d+)_item_title$/', (string)$k, $m)) {
					$indexes[] = (int)$m[1];
				}
			}
			sort($indexes);
			foreach ($indexes as $i) {
				$key = 'list_items_' . $i . '_item_title';
				if (isset($data[$key])) $items[] = (string)$data[$key];
			}
		}

		if ($idx === 0) $out['our_expertise'] = $items;
		if ($idx === 1) $out['facilities_and_features'] = $items;
	}
	return $out;
}

function extractContentCards(string $postContent): array {
	$blocks = extractAllAcfBlockJson($postContent, 'acf/content-cards');
	if (empty($blocks)) return [];
	$data = $blocks[0]['data'] ?? [];
	if (!is_array($data)) return [];

	$count = isset($data['cards']) ? (int)$data['cards'] : 0;
	$cards = [];

	if ($count > 0) {
		for ($i = 0; $i < $count; $i++) {
			$heading = $data['cards_' . $i . '_card_heading'] ?? '';
			$oldId = $data['cards_' . $i . '_card_image'] ?? null;
			$oldId = is_numeric($oldId) ? (int)$oldId : null;
			$cards[] = ['heading' => (string)$heading, 'old_attachment_id' => $oldId && $oldId > 0 ? $oldId : null];
		}
		return $cards;
	}

	$indexes = [];
	foreach ($data as $k => $_v) {
		if (preg_match('/^cards_(\d+)_card_image$/', (string)$k, $m)) {
			$indexes[] = (int)$m[1];
		}
	}
	sort($indexes);
	foreach ($indexes as $i) {
		$heading = $data['cards_' . $i . '_card_heading'] ?? '';
		$oldId = $data['cards_' . $i . '_card_image'] ?? null;
		$oldId = is_numeric($oldId) ? (int)$oldId : null;
		$cards[] = ['heading' => (string)$heading, 'old_attachment_id' => $oldId && $oldId > 0 ? $oldId : null];
	}
	return $cards;
}

function extractPageHeaderHeroImageAttachmentId(string $postContent): ?int {
	$blocks = extractAllAcfBlockJson($postContent, 'acf/page-header');
	if (empty($blocks)) return null;
	$data = $blocks[0]['data'] ?? [];
	if (!is_array($data)) return null;
	$img = $data['image'] ?? null;
	$img = is_numeric($img) ? (int)$img : null;
	return $img && $img > 0 ? $img : null;
}

function buildGalleryFromCards(array $cards): array {
	$kitchen = null;
	$living = null;
	$dining = null;
	$used = [];

	$findByNeedle = function (string $needle, string $mustNot = '') use ($cards, &$used): ?int {
		foreach ($cards as $card) {
			$hid = $card['old_attachment_id'];
			if (!$hid || isset($used[$hid])) continue;
			$h = mb_strtolower($card['heading']);
			$needleLower = mb_strtolower($needle);
			$mustNotLower = mb_strtolower($mustNot);
			$hasNeedle = strpos($h, $needleLower) !== false;
			$hasMustNot = $mustNot !== '' && strpos($h, $mustNotLower) !== false;
			if ($hasNeedle && !$hasMustNot) {
				$used[$hid] = true;
				return (int)$hid;
			}
		}
		return null;
	};

	$dining = $findByNeedle('dining room') ?? $findByNeedle('dining');
	$living = $findByNeedle('living room') ?? $findByNeedle('living');
	$kitchen = $findByNeedle('kitchen');

	$remaining = [];
	foreach ($cards as $card) {
		$hid = $card['old_attachment_id'];
		if (!$hid) continue;
		if (isset($used[$hid])) continue;
		$remaining[] = $card;
	}

	$gallery = [];
	$texts = [];

	if ($dining) { $gallery[] = $dining; $texts[] = 'Dining Room'; }
	if ($living) { $gallery[] = $living; $texts[] = 'Living Room'; }
	if ($kitchen) { $gallery[] = $kitchen; $texts[] = 'Kitchen'; }

	foreach ($remaining as $card) {
		if (count($gallery) >= 10) break;
		$gallery[] = (int)$card['old_attachment_id'];
		$heading = trim((string)$card['heading']);
		$heading = preg_replace('/^Our\s+/i', '', $heading);
		$texts[] = $heading;
	}

	return [
		'kitchen_old_attachment_id' => $kitchen,
		'living_room_old_attachment_id' => $living,
		'dining_room_old_attachment_id' => $dining,
		'gallery_image_old_attachment_ids' => $gallery,
		'gallery_texts' => $texts,
	];
}

function isEmptyMeta(string $value): bool {
	$v = trim($value);
	if ($v === '') return true;
	// In WXR, ACF link fields may store `a:0:{}` or `[]`.
	if ($v === '[]' || $v === 'a:0:{}') return true;
	return false;
}

$opts = getopt('', ['old-xml:', 'new-xml:']);
$oldXml = $opts['old-xml'] ?? '';
$newXml = $opts['new-xml'] ?? '';

if (!$oldXml || !$newXml) {
	fwrite(STDERR, "Missing --old-xml or --new-xml\n");
	exit(1);
}

$old = simplexml_load_file($oldXml);
$new = simplexml_load_file($newXml);
if (!$old || !$new) {
	fwrite(STDERR, "Failed to load one of the XML files\n");
	exit(1);
}

$slug = '68-woodhurst-avenue';

// Parse old `locations` post.
$oldLocation = null;
foreach ($old->channel->item as $item) {
	$wp = $item->children('wp', true);
	if ((string)($wp->post_type ?? '') !== 'locations') continue;
	if ((string)($wp->post_name ?? '') !== $slug) continue;

	$contentNs = $item->children('content', true);
	$postContent = (string)($contentNs->encoded ?? '');
	$postTitle = (string)($item->title ?? $slug);

	$meta = [];
	if (!empty($wp->postmeta)) {
		foreach ($wp->postmeta as $postmeta) {
			$pmWp = $postmeta->children('wp', true);
			$key = (string)($pmWp->meta_key ?? '');
			$val = (string)($pmWp->meta_value ?? '');
			if ($key) $meta[$key] = $val;
		}
	}

	$oldLocation = ['post_content' => $postContent, 'post_title' => $postTitle, 'meta' => $meta];
	break;
}
if (!$oldLocation) {
	fwrite(STDERR, "Could not find old locations post for slug {$slug}\n");
	exit(1);
}

$postContent = $oldLocation['post_content'];
$meta = $oldLocation['meta'];

// Extract like the importer.
$section = extractImageColumnHeadingAndParagraphs($postContent);
$listIcons = extractListIconTitles($postContent);
$cards = extractContentCards($postContent);
$gallery = buildGalleryFromCards($cards);
$heroImageOldId = extractPageHeaderHeroImageAttachmentId($postContent);

$cqcBlocks = extractAllAcfBlockJson($postContent, 'acf/cqc-widget');
$cqcId = '';
if (!empty($cqcBlocks) && isset($cqcBlocks[0]['data']['cqc_id'])) {
	$cqcId = (string)$cqcBlocks[0]['data']['cqc_id'];
}

$oldBeds = $meta['Bedrooms'] ?? '';
$beds = is_numeric(trim((string)$oldBeds)) ? (int)trim((string)$oldBeds) : null;

// Find new `wpsl_stores` post.
$newMeta = [];
foreach ($new->channel->item as $item) {
	$wp = $item->children('wp', true);
	if ((string)($wp->post_type ?? '') !== 'wpsl_stores') continue;
	if ((string)($wp->post_name ?? '') !== $slug) continue;

	if (!empty($wp->postmeta)) {
		foreach ($wp->postmeta as $postmeta) {
			$pmWp = $postmeta->children('wp', true);
			$key = (string)($pmWp->meta_key ?? '');
			$val = (string)($pmWp->meta_value ?? '');
			if ($key) $newMeta[$key] = $val;
		}
	}
	break;
}
if (empty($newMeta)) {
	fwrite(STDERR, "Could not find new wpsl_stores post for slug {$slug}\n");
	exit(1);
}

$mappedFields = [
	'wpsl_address',
	'wpsl_city',
	'wpsl_zip',
	'wpsl_country',
	'number_of_bedrooms',
	'cqc_id',
	'first_section_heading',
	'first_section_content',
	'two_columns_heading',
	'two_columns_content',
	'our_expertise',
	'facilities_and_features',
	'first_section_image',
	'two_columns_image',
	'kitchen',
	'living_room',
	'dining_room',
];
for ($i = 1; $i <= 10; $i++) {
	$mappedFields[] = "gallery_image_{$i}";
	$mappedFields[] = "gallery_text_{$i}";
}

echo "Offline validation for {$slug}\n\n";
echo "Old extraction (sample):\n";
echo "- first_section_heading: {$section['heading']}\n";
echo "- cqc_id: {$cqcId}\n";
echo "- bedrooms(meta Bedrooms): " . ($beds ?? 'n/a') . "\n";
echo "- our_expertise: " . json_encode($listIcons['our_expertise']) . "\n";
echo "- facilities_and_features: " . json_encode($listIcons['facilities_and_features']) . "\n";
echo "- hero image old attachment id: " . ($heroImageOldId ?? 'null') . "\n";
echo "- gallery old image ids: " . json_encode($gallery['gallery_image_old_attachment_ids']) . "\n";
echo "- gallery old texts: " . json_encode($gallery['gallery_texts']) . "\n\n";

echo "New existing meta values (non-empty check for merge_strategy=s2):\n";
foreach ($mappedFields as $field) {
	$val = $newMeta[$field] ?? '';
	$nonEmpty = !isEmptyMeta((string)$val);
	echo "- {$field}: " . ($nonEmpty ? 'NON_EMPTY' : 'EMPTY_OR_MISSING') . "\n";
}

