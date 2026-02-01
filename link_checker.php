<?php
/**
 * PHP Link Checker
 * Usage: php check.php [URL] [Options]
 */

// 1. Get the first argument as the target URL
$targetUrl = $argv[1] ?? null;

// 2. Parse remaining Options (starting from index 2)
$options = [];
for ($i = 2; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (preg_match('/^--?([a-z]+)=?(.*)$/', $arg, $matches)) {
        $key = $matches[1];
        $val = $matches[2];
        
        // Handle space-separated values like -d 3
        if ($val === '' && isset($argv[$i + 1]) && strpos($argv[$i + 1], '-') !== 0) {
            $val = $argv[++$i];
        }
        $options[$key] = $val;
    }
}

// 3. Set parameters and default values
$maxDepth      = (int)($options['d'] ?? $options['depth'] ?? 1);
$checkExternal = filter_var($options['e'] ?? $options['external'] ?? false, FILTER_VALIDATE_BOOLEAN);
$checkHtml     = filter_var($options['h'] ?? $options['html'] ?? false, FILTER_VALIDATE_BOOLEAN);
$logFile       = $options['o'] ?? $options['output'] ?? 'link_checker_report.txt';

if (!$targetUrl || strpos($targetUrl, 'http') !== 0) {
    die("Usage: php check.php [URL] [Options]\n" .
        "Options:\n" .
        "  -d, --depth <int>    Max crawling depth (Default: 1)\n" .
        "  -e, --external <0|1> Check external links (Default: 0)\n" .
        "  -h, --html <0|1>     Check HTML structure (Default: 0)\n" .
        "  -o, --output <file>  Log file path (Default: link_checker_report.txt)\n");
}

// --- Initialize Variables ---
$parsedTarget = parse_url($targetUrl);
$targetHost   = $parsedTarget['host'] ?? '';
$targetPort   = $parsedTarget['port'] ?? null;
$visited = []; 
$results = []; 
$stats = [
    'total_discovered' => 0, 
    'duplicate_skipped' => 0, 
    'external_skipped' => 0,
    'external_checked' => 0
];

/**
 * Identify PHP errors in HTML response
 */
function getPhpErrorDetails($html) {
    if (preg_match('/in\s+<b>(.*?)<\/b>\s+on\s+line\s+<b>(\d+)<\/b>/i', $html, $matches)) {
        return ['file' => $matches[1], 'line' => $matches[2]];
    }
    return [];
}

/**
 * Identify HTML syntax errors using libxml
 */
function getHtmlErrorDetails($html) {
    if (empty($html)) return [];
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    @$doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    $details = [];
    foreach ($errors as $error) {
        $details[] = ['msg' => trim($error->message), 'line' => $error->line];
    }
    return $details;
}

/**
 * Core recursive function to check URLs
 */
function checkUrl($url, $depth, $maxDepth, &$visited, &$results, $targetHost, $targetPort, $checkExternal, $checkHtml, &$stats, $referrer = 'Entry Point') {
    $stats['total_discovered']++;
    
    if (isset($visited[$url])) {
        $stats['duplicate_skipped']++;
        return;
    }
    
    $parsed      = parse_url($url);
    $currentHost = $parsed['host'] ?? '';
    $currentPort = $parsed['port'] ?? null;
    $isExternal  = ($currentHost !== '' && $currentHost !== $targetHost) || ($currentPort !== $targetPort);
    $isEmbed = preg_match('/\.(js|css|jpg|jpeg|png|gif|svg|webp|ico|mp4|webm|mp3|wav|woff2?|ttf|pdf)$/i', $parsed['path'] ?? '') ? true : false;

    // Skip external links if not enabled
    if ($isExternal && !$checkExternal) {
        $stats['external_skipped']++;
        $visited[$url] = true;
        return;
    }

    // Increment checked counter if external and enabled
    if ($isExternal && $checkExternal) {
        $stats['external_checked']++;
    }

    if (strpos($url, 'http') !== 0) return;

    $visited[$url] = true;
    echo "Depth [$depth] Checking" . ($isExternal ? "(External)" : "") . ": $url\n";

    // Initialize cURL
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'PHP Link Checker/1.0',
        CURLOPT_NOBODY         => $isExternal
    ]);
    
    $response    = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $results["HTTP $httpCode"][] = ['url' => $url, 'ref' => $referrer, 'isExt' => $isExternal, 'isEmbed' => $isEmbed];

    // Check for issues if the link is internal
    if (!$isExternal) {
        $phpDetails = getPhpErrorDetails($response);
        if (!empty($phpDetails) || preg_match('/(Fatal error|Parse error|PHP Warning|PHP Notice)/i', $response)) {
            $results['PHP Issue'][] = [
                'url' => $url, 'ref' => $referrer, 
                'file' => $phpDetails['file'] ?? 'Unknown', 
                'line' => $phpDetails['line'] ?? 'Unknown'
            ];
        }

        if ($checkHtml && $httpCode === 200 && strpos($contentType, 'text/html') !== false) {
            $htmlErrors = getHtmlErrorDetails($response);
            if (!empty($htmlErrors)) {
                $results['HTML Issue'][] = ['url' => $url, 'ref' => $referrer, 'errors' => $htmlErrors];
            }
        }
    }

    // Crawl sub-links if depth is not reached
    if (!$isExternal && $httpCode === 200 && $depth < $maxDepth && strpos($contentType, 'text/html') !== false) {
        $dom = new DOMDocument();
        @$dom->loadHTML($response);
        $base = ($parsed['scheme'] ?? 'http') . '://' . $currentHost . ($currentPort ? ":$currentPort" : "");
        $nodes = [];
        foreach ($dom->getElementsByTagName('a') as $link) $nodes[] = $link->getAttribute('href');
        foreach ($dom->getElementsByTagName('link') as $link) $nodes[] = $link->getAttribute('href');
        foreach ($dom->getElementsByTagName('script') as $link) $nodes[] = $link->getAttribute('src');

        foreach ($nodes as $href) {
            $url = '';
            if (!$href || strpos($href, '#') === 0 || strpos($href, 'mailto:') === 0 || strpos($href, 'javascript:') === 0) continue;
            if (preg_match("/^\/\//", $href)) $url = $parsed['scheme'] . '://' . ltrim($href, '/');
            elseif (strpos($href, 'http') !== 0) $url = $base . '/' . ltrim($href, '/');
            checkUrl($url, $depth + 1, $maxDepth, $visited, $results, $targetHost, $targetPort, $checkExternal, $checkHtml, $stats, $url);
        }
    }
}

// --- Execution ---
checkUrl($targetUrl, 0, $maxDepth, $visited, $results, $targetHost, $targetPort, $checkExternal, $checkHtml, $stats);

// --- Report Generation ---
uksort($results, function($a, $b) {
    $priority = [
        'HTTP 200'   => 10,
        'HTTP 301'   => 20,
        'HTTP 302'   => 21,
        'HTTP 404'   => 30,
        'PHP Issue'  => 100,
        'HTML Issue' => 200
    ];

    // 1. Assign Priority for $a
    if (isset($priority[$a])) {
        $pA = $priority[$a];
    } elseif (preg_match('/HTTP 5\d{2}/', $a)) {
        $pA = 35; // Group 5xx errors near 404
    } elseif (strpos($a, 'HTTP') === 0) {
        $pA = 90; // Fallback for other HTTP xxx
    } else {
        $pA = 999;
    }

    // 2. Assign Priority for $b
    if (isset($priority[$b])) {
        $pB = $priority[$b];
    } elseif (preg_match('/HTTP 5\d{2}/', $b)) {
        $pB = 35;
    } elseif (strpos($b, 'HTTP') === 0) {
        $pB = 90;
    } else {
        $pB = 999;
    }
    
    // Sort by priority value, then alphabetically if equal
    return ($pA <=> $pB) ?: strcmp($a, $b);
});

$width = 20;

// 1. Setting Section
$summaryPart = "\nSetting\n" .
               str_pad(" - Root Url:", $width) . $targetUrl . "\n" .
               str_pad(" - Max Depth:", $width) . $maxDepth . "\n" .
               str_pad(" - Check HTML:", $width) . ($checkHtml ? "Enabled" : "Disabled") . "\n" .
               str_pad(" - Check External:", $width) . ($checkExternal ? "Enabled" : "Disabled") . "\n";

// 2. Summary Section
$extValue = $checkExternal ? $stats['external_checked'] : $stats['external_skipped'];
$extLabel = $checkExternal ? " Checked" : " Ignored";

$summaryPart .= "\nSummary\n" .
               str_pad(" - Handled URLs:", $width) . number_format($stats['total_discovered']) . "\n" .
               str_pad(" - Unique URLs:", $width) . number_format(count($visited)) . " Checked\n" .
               str_pad(" - External URLs:", $width) . number_format($extValue) . $extLabel . "\n" .
               str_pad(" - Duplicate URLs:", $width) . number_format($stats['duplicate_skipped']) . " Skipped\n";

// 3. Results Overview
$summaryPart .= "\nResult\n";
foreach ($results as $code => $data) {
    $flag = '[?]';
    if ($code === 'HTTP 200') $flag = '[O]';
    else if (preg_match('/HTTP (404|5\d{2})/', $code)) $flag = '[X]';
    else if (preg_match('/Issue$/', $code)) $flag = '[!]';
    elseif (preg_match('/HTTP 3\d{2}/', $code)) $flag = ' [>]';
    
    $summaryPart .= str_pad(" - $flag $code:", $width) . number_format(count($data)) . "\n";
}

// 4. Detailed Issues (for log file only)
$detailPart = "";
$hasIssues = false;
foreach ($results as $code => $data) { if ($code !== "HTTP 200") { $hasIssues = true; break; } }

if ($hasIssues) {
    $detailPart .= "\nIssue Details\n";
    foreach ($results as $code => $data) {
        if ($code === "HTTP 200") continue; 
        $detailPart .= "[$code]\n";
        foreach ($data as $item) {
            $detailPart .= " - " . $item['url'] .  " <- " . $item['ref'] . "\n";
            if ($code === "PHP Issue"){
                $detailPart .= "   {$item['file']} on line {$item['line']}\n";
            }else if ($code === "HTML Issue") {
                foreach ($item['errors'] as $err) $detailPart .= "   {$err['msg']} on line {$err['line']}\n";
            }
        }
        $detailPart .= "\n";
    }
}

// Final output
echo $summaryPart;
file_put_contents($logFile, "Link Checker Report\n" . date('Y-m-d H:i:s') . "\n" . $summaryPart . $detailPart);
echo "\nDone! you can check detailed report.\n";
echo " - $logFile\n\n";
