<?php
/**
 * index.php
 * Core redirect engine for go2trek.com → fetnepal.com migration.
 *
 * HOW IT WORKS:
 *   1. Reads the incoming URL path from the request.
 *   2. Normalises it (lowercase, strips trailing slash, decodes URL encoding).
 *   3. Looks it up in the redirect map built from redirect_map.csv.
 *   4. Sends a 301 permanent redirect to the matching fetnepal.com URL.
 *   5. If no match is found, sends a 302 temporary redirect to the homepage
 *      (302 so the fallback is not permanently cached by browsers/Google).
 *
 * DEPLOYMENT:
 *   - Upload this entire folder to the root of go2trek.com.
 *   - Make sure .htaccess is also uploaded (routes all traffic here).
 *   - The redirect_map.csv must sit in the same folder as this file.
 */

require_once __DIR__ . '/config.php';

// ─── 1. Build redirect map from CSV ──────────────────────────────────────────
// The map is built on every request. For 150–200 URLs this is fast enough
// (under 1ms). If the site ever grows to thousands of URLs, consider caching
// the parsed array in a redirects.php file using tools/csv_to_php.php instead.

$map = [];
$csv_file = __DIR__ . '/redirect_map.csv';

if (file_exists($csv_file)) {
    $handle = fopen($csv_file, 'r');
    $is_first_row = true;

    while (($row = fgetcsv($handle)) !== false) {
        // Skip header row
        if ($is_first_row) {
            $is_first_row = false;
            continue;
        }

        // Expect exactly 2 columns: old_path, new_url
        if (count($row) < 2) continue;

        $old_path = trim($row[0]);
        $new_url  = trim($row[1]);

        if ($old_path === '' || $new_url === '') continue;

        // Normalise the key: lowercase, remove trailing slash
        $key = rtrim(strtolower(rawurldecode($old_path)), '/');
        if ($key === '') $key = '/';

        $map[$key] = $new_url;
    }

    fclose($handle);
}

// ─── 2. Normalise the incoming request path ───────────────────────────────────
$raw_uri = $_SERVER['REQUEST_URI'] ?? '/';

// Separate path from query string
$path = parse_url($raw_uri, PHP_URL_PATH) ?? '/';

// Decode any %XX or + encoding, then lowercase, then strip trailing slash
$path = rtrim(strtolower(rawurldecode($path)), '/');
if ($path === '') $path = '/';

// ─── 3. Preserve query string (pass through to destination if present) ────────
$query_string = $_SERVER['QUERY_STRING'] ?? '';

// ─── 4. Look up in map and redirect ───────────────────────────────────────────
if (isset($map[$path])) {
    $destination = $map[$path];

    // Append original query string to the destination if there was one
    if ($query_string !== '') {
        $separator   = str_contains($destination, '?') ? '&' : '?';
        $destination = $destination . $separator . $query_string;
    }

    header('Location: ' . $destination, true, 301);
    exit;
}

// ─── 5. Fallback — no match found ─────────────────────────────────────────────
// Using 302 (temporary) so unmatched URLs are NOT permanently cached.
// This means if you later add the URL to redirect_map.csv, browsers will
// pick up the correct destination rather than going to the homepage forever.
header('Location: ' . FALLBACK_URL, true, 302);
exit;
