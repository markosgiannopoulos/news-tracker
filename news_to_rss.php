<?php
declare(strict_types=1);

/**
 * Google News Topic -> Original Articles RSS Generator (streaming items)
 *
 * - Input: Google News topic URL
 * - Process: Fetch RSS for topic, filter/dedupe, rank top 5, fetch article text, generate original 600-800 word HTML article, find Google Images photo (width 1000-1600), emit RSS
 * - Output: RSS XML to stdout, flushing each <item> as soon as it's completed
 *
 * Requirements:
 * - PHP 8.0+
 * - Environment variable OPENAI_API_KEY for article generation
 * - Optional SERPAPI_API_KEY to improve Google Images reliability (if present, used first)
 */

// --------------- Configuration ---------------

const DEFAULT_TOPIC_URL = 'https://news.google.com/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNREpxYW5RU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US%3Aen';
const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const MAX_ITEMS = 5;
const MIN_IMAGE_WIDTH = 1000;
const MAX_IMAGE_WIDTH = 1600;
const OPENAI_MODEL = 'gpt-4o-mini';

/**
 * Allowed categories (fixed set from user requirements)
 */
const ALLOWED_CATEGORIES = [
	'Charts / Awards', 'Classical', 'Country', 'Digital Life and Gaming', 'Jazz', 'Latin',
	'Metal / Hard Rock', 'Movies and TV', 'Music Industry', 'Oldies', 'Pop / Rock',
	'Reviews', 'RnB', 'Rock', 'Soundtracks', 'Tour Dates'
];

// --------------- Utilities ---------------

function stderr(string $message): void {
	fwrite(STDERR, $message . "\n");
}

function http_get(string $url, array $headers = [], int $timeoutSeconds = 20): string {
	$ch = curl_init();
	curl_setopt_array($ch, [
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
		CURLOPT_TIMEOUT => $timeoutSeconds,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_ENCODING => 'gzip,deflate,br',
		CURLOPT_HTTPHEADER => array_merge([
			'User-Agent: ' . USER_AGENT,
			'Accept: */*',
			'Accept-Language: en-US,en;q=0.9',
		], $headers),
	]);
	$response = curl_exec($ch);
	if ($response === false) {
		$err = curl_error($ch);
		curl_close($ch);
		throw new RuntimeException('HTTP GET failed: ' . $err);
	}
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($httpCode >= 400) {
		throw new RuntimeException('HTTP GET returned status ' . $httpCode . ' for ' . $url);
	}
	return (string)$response;
}

function url_with_query(string $base, array $query): string {
	$sep = str_contains($base, '?') ? '&' : '?';
	return $base . $sep . http_build_query($query);
}

function flush_now(): void {
	// Encourage immediate streaming of output
	echo str_repeat("\n", 1);
	flush();
	if (function_exists('ob_flush')) { @ob_flush(); }
}

// --------------- Google News RSS Handling ---------------

/**
 * Convert the Google News topic page URL to the equivalent RSS feed URL.
 */
function topic_url_to_rss(string $topicUrl): string {
	// Replace /topics/ with /rss/topics/
	$parts = parse_url($topicUrl);
	if (!isset($parts['scheme'], $parts['host'], $parts['path'])) {
		throw new InvalidArgumentException('Invalid topic URL');
	}
	$path = $parts['path'];
	$path = str_replace('/topics/', '/rss/topics/', $path);
	$query = $parts['query'] ?? '';
	$rssUrl = $parts['scheme'] . '://' . $parts['host'] . $path;
	if ($query !== '') { $rssUrl .= '?' . $query; }
	return $rssUrl;
}

/**
 * Parse RSS feed items from XML string.
 * Returns array of ['title' => string, 'link' => string, 'pubDate' => ?int]
 */
function parse_rss_items(string $rssXml): array {
	$items = [];
	$doc = new DOMDocument();
	$prev = libxml_use_internal_errors(true);
	$loaded = $doc->loadXML($rssXml);
	libxml_use_internal_errors($prev);
	if (!$loaded) {
		throw new RuntimeException('Failed to parse RSS XML');
	}
	foreach ($doc->getElementsByTagName('item') as $item) {
		$title = '';
		$link = '';
		$pubDateTs = null;
		foreach ($item->childNodes as $node) {
			if ($node->nodeName === 'title') {
				$title = trim($node->textContent);
			} elseif ($node->nodeName === 'link') {
				$link = trim($node->textContent);
			} elseif ($node->nodeName === 'pubDate') {
				$ts = strtotime(trim($node->textContent));
				$pubDateTs = $ts !== false ? $ts : null;
			}
		}
		if ($title !== '' && $link !== '') {
			$items[] = [
				'title' => $title,
				'link' => cleanup_google_news_link($link),
				'pubDate' => $pubDateTs,
			];
		}
	}
	return $items;
}

/**
 * Convert Google News redirect link to the original article URL when possible.
 */
function cleanup_google_news_link(string $link): string {
	// Some links are direct; some are Google redirectors with url= param
	$parts = parse_url($link);
	if (!isset($parts['host'])) { return $link; }
	if (str_contains($parts['host'], 'news.google.com')) {
		if (!empty($parts['query'])) {
			parse_str($parts['query'], $q);
			if (!empty($q['url'])) {
				return $q['url'];
			}
		}
	}
	return $link;
}

// --------------- Filtering & Deduplication ---------------

function is_horoscope(string $title): bool {
	$needle = strtolower($title);
	$keywords = ['horoscope', 'zodiac', 'astrology', 'astrological'];
	foreach ($keywords as $kw) {
		if (str_contains($needle, $kw)) { return true; }
	}
	return false;
}

function normalize_for_similarity(string $text): array {
	$lc = strtolower($text);
	$lc = preg_replace('/[^a-z0-9\s]/', ' ', $lc) ?? $lc;
	$lc = preg_replace('/\s+/', ' ', $lc) ?? $lc;
	$tokens = array_values(array_filter(explode(' ', $lc)));
	$stop = [
		'a','an','the','and','or','but','if','on','in','at','to','of','for','by','with','from','as','is','are','was','were','be','been','being','that','this','it','its','into','over','about','after','before','under','above','across','new','latest','breaking','update','updates','news'
	];
	$tokens = array_values(array_filter($tokens, fn($t) => !in_array($t, $stop, true) && strlen($t) > 2));
	return array_unique($tokens);
}

function jaccard_similarity(array $a, array $b): float {
	$setA = array_fill_keys($a, true);
	$setB = array_fill_keys($b, true);
	$intersect = array_intersect_key($setA, $setB);
	$union = $setA + $setB;
	$u = count($union);
	if ($u === 0) { return 0.0; }
	return count($intersect) / $u;
}

function extract_title_entities(string $title): array {
	// Capture sequences of Capitalized Words (basic heuristic for names/topics)
	$entities = [];
	if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,4})\b/u', $title, $m)) {
		foreach ($m[1] as $ent) {
			$ent = trim($ent);
			if (strlen($ent) >= 3) {
				$entities[] = $ent;
			}
		}
	}
	return array_values(array_unique($entities));
}

function are_titles_duplicates(string $a, string $b): bool {
	$tokA = normalize_for_similarity($a);
	$tokB = normalize_for_similarity($b);
	$jac = jaccard_similarity($tokA, $tokB);
	if ($jac >= 0.7) { return true; }
	$entsA = extract_title_entities($a);
	$entsB = extract_title_entities($b);
	if (!empty(array_intersect($entsA, $entsB))) {
		// Consider duplicates if share at least one prominent entity and similarity moderately high
		if ($jac >= 0.4) { return true; }
	}
	return false;
}

function dedupe_items(array $items): array {
	$result = [];
	foreach ($items as $candidate) {
		if (is_horoscope($candidate['title'])) { continue; }
		$duplicateFound = false;
		foreach ($result as $existing) {
			if (are_titles_duplicates($candidate['title'], $existing['title'])) {
				$duplicateFound = true;
				break;
			}
		}
		if (!$duplicateFound) {
			$result[] = $candidate;
		}
	}
	return $result;
}

function rank_items(array $items): array {
	usort($items, function ($a, $b) {
		$ta = $a['pubDate'] ?? 0;
		$tb = $b['pubDate'] ?? 0;
		return $tb <=> $ta;
	});
	return $items;
}

// --------------- Article Content Extraction ---------------

function strip_scripts_and_styles(string $html): string {
	$html = preg_replace('#<script[\s\S]*?</script>#i', '', $html) ?? $html;
	$html = preg_replace('#<style[\s\S]*?</style>#i', '', $html) ?? $html;
	return $html;
}

function extract_article_text(string $url): string {
	try {
		$html = http_get($url);
	} catch (Throwable $e) {
		return '';
	}
	$html = strip_scripts_and_styles($html);
	$doc = new DOMDocument();
	$prev = libxml_use_internal_errors(true);
	$loaded = $doc->loadHTML($html);
	libxml_use_internal_errors($prev);
	if (!$loaded) { return ''; }
	$xpath = new DOMXPath($doc);

	// Try <article> tag first
	$articleNodes = $xpath->query('//article');
	if ($articleNodes !== false && $articleNodes->length > 0) {
		$text = aggregate_paragraphs($articleNodes->item(0));
		if (mb_strlen($text) > 400) { return $text; }
	}
	// Try main content containers
	$candidates = [
		"//*[@role='main']",
		"//main",
		"//*[@id='content']",
		"//*[@id='main']",
		"//*[@class and contains(concat(' ', normalize-space(@class), ' '), ' article ')]",
		"//*[@class and contains(concat(' ', normalize-space(@class), ' '), ' content ')]"
	];
	foreach ($candidates as $q) {
		$nodes = $xpath->query($q);
		if ($nodes && $nodes->length > 0) {
			$text = '';
			for ($i = 0; $i < $nodes->length; $i++) {
				$text .= aggregate_paragraphs($nodes->item($i)) . "\n";
			}
			$text = trim($text);
			if (mb_strlen($text) > 400) { return $text; }
		}
	}
	// Fallback: gather all <p> tags
	$ps = $doc->getElementsByTagName('p');
	$out = [];
	foreach ($ps as $p) {
		$t = trim($p->textContent);
		if (mb_strlen($t) >= 60) { $out[] = $t; }
	}
	$out = array_slice($out, 0, 20);
	return trim(implode("\n\n", $out));
}

function aggregate_paragraphs(DOMNode $node): string {
	$paras = [];
	$walker = function (DOMNode $n) use (&$walker, &$paras) {
		if ($n->nodeName === 'p') {
			$t = trim($n->textContent);
			if ($t !== '' && mb_strlen($t) >= 40) { $paras[] = $t; }
		}
		if ($n->hasChildNodes()) {
			foreach ($n->childNodes as $c) { $walker($c); }
		}
	};
	$walker($node);
	$paras = array_slice($paras, 0, 30);
	return trim(implode("\n\n", $paras));
}

// --------------- Categorization ---------------

function classify_category(string $title, string $articleText): string {
	$lcTitle = strtolower($title);
	$lcBody = strtolower($articleText);
	$pick = function (string $cat): string { return $cat; };

	// Simple keyword-based mapping
	if (str_contains($lcTitle, 'tour') || str_contains($lcBody, 'tour dates')) { return $pick('Tour Dates'); }
	if (str_contains($lcTitle, 'soundtrack') || str_contains($lcBody, 'soundtrack')) { return $pick('Soundtracks'); }
	if (preg_match('/\boscars?\b|\bgrammys?\b|\bbillboard\b|\bchart\b|\bawards?\b/', $lcTitle)) { return $pick('Charts / Awards'); }
	if (preg_match('/\bmovie|film|tv|series|show|netflix|hulu|disney\b/', $lcTitle)) { return $pick('Movies and TV'); }
	if (preg_match('/\bgaming|video game|playstation|xbox|nintendo|pc game\b/', $lcTitle) || str_contains($lcBody, 'gaming')) { return $pick('Digital Life and Gaming'); }
	if (preg_match('/\bclassical|symphony|philharmonic|orchestra|concerto\b/', $lcTitle)) { return $pick('Classical'); }
	if (preg_match('/\bjazz\b/', $lcTitle)) { return $pick('Jazz'); }
	if (preg_match('/\blatin\b/', $lcTitle)) { return $pick('Latin'); }
	if (preg_match('/\bcountry\b/', $lcTitle)) { return $pick('Country'); }
	if (preg_match('/\bmetal|hard rock|heavy metal\b/', $lcTitle)) { return $pick('Metal / Hard Rock'); }
	if (preg_match('/\brnb|r\&b\b/', $lcTitle)) { return $pick('RnB'); }
	if (preg_match('/\brock\b/', $lcTitle)) { return $pick('Rock'); }
	if (preg_match('/\bpop\b/', $lcTitle)) { return $pick('Pop / Rock'); }
	if (preg_match('/\breview|reviews\b/', $lcTitle)) { return $pick('Reviews'); }
	if (preg_match('/\boldies|classic hits\b/', $lcTitle)) { return $pick('Oldies'); }
	if (preg_match('/\bindustry|label|streaming|royalties|catalog\b/', $lcTitle) || str_contains($lcBody, 'music industry')) { return $pick('Music Industry'); }

	// Fallbacks for general news: keep within allowed list
	if (preg_match('/\bai|artificial intelligence|tech|software|app|platform\b/', $lcTitle)) { return $pick('Digital Life and Gaming'); }
	if (preg_match('/\bseries|season|episode|box office\b/', $lcTitle)) { return $pick('Movies and TV'); }
	return $pick('Pop / Rock');
}

// --------------- Google Images Search ---------------

function fetch_image_via_serpapi(string $query): ?array {
	$apiKey = getenv('SERPAPI_API_KEY');
	if (!$apiKey) { return null; }
	$endpoint = 'https://serpapi.com/search.json';
	$params = [
		'q' => $query,
		'engine' => 'google_images',
		'hl' => 'en',
		'tbm' => 'isch',
		'safe' => 'active',
		'api_key' => $apiKey,
		// Try to bias toward larger images
		'tbs' => 'isz:l'
	];
	$url = url_with_query($endpoint, $params);
	try {
		$json = http_get($url);
		$data = json_decode($json, true);
		if (!is_array($data) || empty($data['images_results'])) { return null; }
		foreach ($data['images_results'] as $img) {
			$w = (int)($img['original_width'] ?? 0);
			$h = (int)($img['original_height'] ?? 0);
			$src = $img['original'] ?? ($img['thumbnail'] ?? null);
			if (!$src) { continue; }
			if ($w >= MIN_IMAGE_WIDTH && $w <= MAX_IMAGE_WIDTH) {
				return ['url' => $src, 'width' => $w, 'height' => $h];
			}
		}
		// Fallback to first acceptable even if width missing
		foreach ($data['images_results'] as $img) {
			$src = $img['original'] ?? ($img['thumbnail'] ?? null);
			if ($src) { return ['url' => $src, 'width' => null, 'height' => null]; }
		}
	} catch (Throwable $e) {
		return null;
	}
	return null;
}

function fetch_image_from_google_images_html(string $query): ?array {
	$searchUrl = url_with_query('https://www.google.com/search', [
		'tbm' => 'isch',
		'q' => $query,
		'safe' => 'active',
		'tbs' => 'isz:l'
	]);
	try {
		$html = http_get($searchUrl, [
			'Referer: https://www.google.com/',
			'Cache-Control: no-cache'
		]);
	} catch (Throwable $e) {
		return null;
	}
	// Try to extract original image URLs and dimensions from embedded JSON patterns
	$results = [];
	// Pattern 1: old schema with \"ou\": "url", \"ow\": width, \"oh\": height
	if (preg_match_all('/\\"ou\\":\\"(https?:\\/\\/[^\\"]+)\\",\\"ow\\":(\d+),\\"oh\\":(\d+)/', $html, $m, PREG_SET_ORDER)) {
		foreach ($m as $g) {
			$url = stripslashes($g[1]);
			$w = (int)$g[2];
			$h = (int)$g[3];
			$results[] = ['url' => $url, 'width' => $w, 'height' => $h];
		}
	}
	// Pattern 2: newer inline JSON might contain "data:image" and thumbnails; skip those, prefer http(s)
	if (empty($results)) {
		if (preg_match_all('/(https?:\\/\\/[^\\\s\"]+\.(?:jpg|jpeg|png|webp))/i', $html, $m2)) {
			foreach ($m2[1] as $candidate) {
				if (!str_contains($candidate, 'gstatic.com')) {
					$results[] = ['url' => stripslashes($candidate), 'width' => null, 'height' => null];
				}
			}
		}
	}
	// Filter by width constraints if available
	foreach ($results as $r) {
		$w = (int)($r['width'] ?? 0);
		if ($w >= MIN_IMAGE_WIDTH && $w <= MAX_IMAGE_WIDTH) { return $r; }
	}
	return $results[0] ?? null;
}

function find_relevant_image(string $query): ?array {
	// Prefer SerpAPI if available, fallback to brittle HTML parsing
	$img = fetch_image_via_serpapi($query);
	if ($img) {
		// If dimensions missing, try to probe
		if (empty($img['width']) || empty($img['height'])) {
			$probe = probe_image_dimensions($img['url']);
			if ($probe) {
				$img['width'] = $probe['width'];
				$img['height'] = $probe['height'];
			}
		}
		if (!empty($img['width']) && $img['width'] >= MIN_IMAGE_WIDTH && $img['width'] <= MAX_IMAGE_WIDTH) {
			return $img;
		}
	}
	$htmlImg = fetch_image_from_google_images_html($query);
	if ($htmlImg) {
		if (empty($htmlImg['width']) || empty($htmlImg['height'])) {
			$probe = probe_image_dimensions($htmlImg['url']);
			if ($probe) {
				$htmlImg['width'] = $probe['width'];
				$htmlImg['height'] = $probe['height'];
			}
		}
		if (!empty($htmlImg['width']) && $htmlImg['width'] >= MIN_IMAGE_WIDTH && $htmlImg['width'] <= MAX_IMAGE_WIDTH) {
			return $htmlImg;
		}
	}
	return $img ?: $htmlImg; // best effort
}

/**
 * Fetch up to maxBytes from a URL. Returns raw string or null on failure.
 */
function http_get_partial(string $url, int $maxBytes = 512000): ?string {
	$ch = curl_init();
	curl_setopt_array($ch, [
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 3,
		CURLOPT_USERAGENT => USER_AGENT,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 20,
		CURLOPT_HTTPHEADER => [
			'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
			'Accept-Language: en-US,en;q=0.9',
		],
		CURLOPT_RANGE => '0-' . max(0, $maxBytes - 1),
	]);
	$raw = curl_exec($ch);
	if ($raw === false) {
		curl_close($ch);
		return null;
	}
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($code >= 400) { return null; }
	return $raw;
}

/**
 * Probe dimensions of an image URL by downloading the first chunk.
 * Returns ['width' => int, 'height' => int] or null.
 */
function probe_image_dimensions(string $url): ?array {
	$raw = http_get_partial($url, 512000);
	if ($raw === null) { return null; }
	$info = @getimagesizefromstring($raw);
	if ($info === false) { return null; }
	$w = (int)($info[0] ?? 0);
	$h = (int)($info[1] ?? 0);
	if ($w <= 0 || $h <= 0) { return null; }
	return ['width' => $w, 'height' => $h];
}

// --------------- OpenAI Generation ---------------

function openai_chat(string $apiKey, array $messages, bool $jsonResponse = false): array {
	$url = 'https://api.openai.com/v1/chat/completions';
	$headers = [
		'Authorization: Bearer ' . $apiKey,
		'Content-Type: application/json'
	];
	$payload = [
		'model' => OPENAI_MODEL,
		'messages' => $messages,
		'temperature' => 0.7,
		'top_p' => 0.95,
		'presence_penalty' => 0.1,
		'frequency_penalty' => 0.1,
		'response_format' => $jsonResponse ? ['type' => 'json_object'] : null,
	];
	$payload = array_filter($payload, fn($v) => $v !== null);

	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POSTFIELDS => json_encode($payload),
		CURLOPT_TIMEOUT => 120,
	]);
	$response = curl_exec($ch);
	if ($response === false) {
		$err = curl_error($ch);
		curl_close($ch);
		throw new RuntimeException('OpenAI request failed: ' . $err);
	}
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($httpCode >= 400) {
		throw new RuntimeException('OpenAI returned HTTP ' . $httpCode . ' response: ' . $response);
	}
	$data = json_decode($response, true);
	if (!is_array($data)) {
		throw new RuntimeException('Failed to decode OpenAI response');
	}
	return $data;
}

function generate_article_with_openai(string $apiKey, string $sourceTitle, string $sourceUrl, string $sourceText, string $category): array {
	// We ask for strict HTML structure and no pre-headline before first paragraph
	$sys = [
		'role' => 'system',
		'content' => "You are a professional news writer. Write original, factual articles based on provided source text. Follow these rules strictly:\n- Output valid HTML only using <p>, <h3>, and <i> tags.\n- Start with a paragraph (no headline before it). Use section headlines with <h3> only after the first paragraph.\n- Include at least one <i> quote styled section when appropriate.\n- Length: 600-800 words.\n- Tone: clear, neutral, concise, informative.\n- Do NOT include images, links, scripts, CSS, or any tags except <p>, <h3>, <i>.\n- Do NOT include a category; it will be assigned separately.\n- Create an original, compelling headline different from the source title."
	];
	$user = [
		'role' => 'user',
		'content' => json_encode([
			'instructions' => 'Write one original headline and a 600-800 word HTML-formatted body. Keep to the allowed tags and structure. The first paragraph has no headline. Use <h3> for section headers. Include at least one <i> quote. Output as JSON with keys: headline, body_html. No extra keys.',
			'source_title' => $sourceTitle,
			'source_url' => $sourceUrl,
			'source_text' => mb_substr($sourceText, 0, 12000),
			'category' => $category,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
	];
	$assistantInstruction = [
		'role' => 'assistant',
		'content' => 'Acknowledge. I will output strict JSON with keys headline and body_html.'
	];
	$data = openai_chat($apiKey, [$sys, $assistantInstruction, $user], true);
	$choice = $data['choices'][0]['message']['content'] ?? '';
	$parsed = json_decode($choice, true);
	if (!is_array($parsed) || empty($parsed['headline']) || empty($parsed['body_html'])) {
		// Fallback: try non-JSON parse
		$raw = $data['choices'][0]['message']['content'] ?? '';
		// Try to extract headline and body heuristically
		$headline = trim((string)preg_replace('/\s+/', ' ', strip_tags($sourceTitle)));
		$body = trim($raw);
		return [
			'headline' => $headline,
			'body_html' => $body,
		];
	}
	return [
		'headline' => trim((string)$parsed['headline']),
		'body_html' => (string)$parsed['body_html'],
	];
}

// --------------- RSS Output ---------------

function xml_escape(string $s): string {
	return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function emit_rss_header(string $feedTitle, string $feedLink): void {
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">' . "\n";
	echo '<channel>' . "\n";
	echo '<title>' . xml_escape($feedTitle) . '</title>' . "\n";
	echo '<link>' . xml_escape($feedLink) . '</link>' . "\n";
	echo '<description>Top 5 synthesized articles from Google News topic</description>' . "\n";
	flush_now();
}

function emit_rss_footer(): void {
	echo '</channel></rss>';
	flush_now();
}

function emit_rss_item(string $headline, string $bodyHtml, string $category, ?string $mediaUrl, string $sourceUrl): void {
	$guid = sha1($headline . '|' . $sourceUrl);
	echo '<item>' . "\n";
	echo '<guid isPermaLink="false">' . $guid . '</guid>' . "\n";
	echo '<title>' . xml_escape($headline) . '</title>' . "\n";
	echo '<link>' . xml_escape($sourceUrl) . '</link>' . "\n";
	echo '<category>' . xml_escape($category) . '</category>' . "\n";
	if ($mediaUrl) {
		echo '<media:content url="' . xml_escape($mediaUrl) . '" medium="image" />' . "\n";
	}
	$desc = '<![CDATA[' . $bodyHtml . ']]>';
	echo '<description>' . $desc . '</description>' . "\n";
	echo '</item>' . "\n";
	flush_now();
}

// --------------- Main ---------------

function main(array $argv): void {
	$topicUrl = $argv[1] ?? DEFAULT_TOPIC_URL;
	$openaiKey = getenv('OPENAI_API_KEY') ?: '';
	if ($openaiKey === '') {
		stderr('ERROR: OPENAI_API_KEY is not set. Please export OPENAI_API_KEY and rerun.');
		exit(1);
	}

	// Prepare output buffering for streaming
	if (function_exists('ob_get_level')) {
		while (ob_get_level() > 0) { ob_end_flush(); }
	}
	@ob_implicit_flush(true);
	if (php_sapi_name() !== 'cli') {
		header('Content-Type: application/rss+xml; charset=utf-8');
	}

	$feedTitle = 'AI-Synthesized Topic Feed';
	$feedLink = $topicUrl;

	try {
		$rssUrl = topic_url_to_rss($topicUrl);
		$rssXml = http_get($rssUrl, ['Referer: ' . $topicUrl]);
		$items = parse_rss_items($rssXml);
		$items = dedupe_items($items);
		$items = rank_items($items);
		$top = array_slice($items, 0, MAX_ITEMS * 2); // keep extra in case generation fails
	} catch (Throwable $e) {
		stderr('Failed to fetch topic: ' . $e->getMessage());
		echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Error</title><description>' . xml_escape($e->getMessage()) . '</description></channel></rss>';
		return;
	}

	emit_rss_header($feedTitle, $feedLink);

	$emitted = 0;
	foreach ($top as $item) {
		if ($emitted >= MAX_ITEMS) { break; }
		$title = $item['title'];
		$link = $item['link'];
		$sourceText = extract_article_text($link);
		$category = classify_category($title, $sourceText);

		try {
			$gen = generate_article_with_openai($openaiKey, $title, $link, $sourceText, $category);
			$headline = $gen['headline'];
			$bodyHtml = $gen['body_html'];
			// Minimal HTML sanitation: keep only <p>, <h3>, <i>
			$bodyHtml = preg_replace('#<(?!/?(p|h3|i)\b)[^>]*>#i', '', $bodyHtml) ?? $bodyHtml;
			// Ensure first element is a paragraph (no heading before it)
			$bodyHtml = ltrim($bodyHtml);
			if (preg_match('/^<h3>/i', $bodyHtml)) {
				$bodyHtml = preg_replace('/^<h3>.*?<\/h3>\s*/is', '', $bodyHtml) ?? $bodyHtml;
			}

			// Find relevant image from Google Images
			$imgQuery = $headline;
			$img = find_relevant_image($imgQuery);
			$mediaUrl = $img['url'] ?? null;

			emit_rss_item($headline, $bodyHtml, $category, $mediaUrl, $link);
			$emitted++;
		} catch (Throwable $e) {
			stderr('Generation failed for: ' . $title . ' — ' . $e->getMessage());
			continue;
		}
	}

	emit_rss_footer();
}

// Entry
if (php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server') {
	main($argv);
}