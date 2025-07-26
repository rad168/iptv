<?php
//error_reporting(E_ALL);
//ini_set('display_errors', '1');

$request_url = isset($_GET['url']) ? urldecode($_GET['url']) : '';
if (empty($request_url)) {
    die('缺少 url 参数');
}

// 可选域名白名单
$allowed_domains = [
    'aktv.top',
    'php.jdshipin.com',
    'cdn12.jdshipin.com',
    'v2h.jdshipin.com',
    'v2hcdn.jdshipin.com',
    'cdn.163189.xyz',
    'cdn2.163189.xyz',
    'cdn3.163189.xyz',
    'cdn5.163189.xyz',
    'cdn6.163189.xyz',
    'cdn9.163189.xyz'
];

// 是否启用域名检查（设为 false 表示允许任何域名/IP）
$enable_domain_check = false;

$parsed_url = parse_url($request_url);
$host = $parsed_url['host'] ?? '';

if ($enable_domain_check && !in_array($host, $allowed_domains)) {
    die('非法请求的域名');
}

// 兼容 getallheaders() 函数
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $key = str_replace('_', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}

// 兼容 str_starts_with() 函数
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

// 兼容 str_contains 函数
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

// 构造请求头
$headers = [];
foreach (getallheaders() as $name => $value) {
    if (strtolower($name) !== 'host') {
        $headers[] = "$name: $value";
    }
}
$headers[] = "Host: $host";
$headers[] = "User-Agent: AppleCoreMedia/1.0.0.7B367 (iPad; U; CPU OS 4_3_3 like Mac OS X)";
$headers[] = "Referer: https://$host/";
$headers[] = "Accept-Encoding: gzip, deflate";

// 发起请求
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $request_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_ENCODING, "");
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}

$response = curl_exec($ch);
$curl_error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

// 拆分头和主体
$headers_raw = substr($response, 0, $header_size);
$body = substr($response, $header_size);

// 解析头
$response_headers = [];
foreach (explode("\r\n", $headers_raw) as $line) {
    if (stripos($line, 'HTTP/') === 0) {
        $response_headers[] = $line;
        continue;
    }
    $parts = explode(': ', $line, 2);
    if (count($parts) === 2) {
        $response_headers[strtolower($parts[0])] = $parts[1];
    }
}

// 重定向处理
if (in_array($http_code, [301, 302, 303, 307, 308]) && isset($response_headers['location'])) {
    $location = $response_headers['location'];
    if (!parse_url($location, PHP_URL_SCHEME)) {
        $base = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if (isset($parsed_url['port'])) {
            $base .= ':' . $parsed_url['port'];
        }
        $location = $base . '/' . ltrim($location, '/');
    }
    header("Location: mytv.php?url=" . urlencode($location), true, $http_code);
    exit();
}

// 设置 content-type
if (isset($response_headers['content-type'])) {
    header('Content-Type: ' . $response_headers['content-type']);
}

// 设置状态码
http_response_code($http_code);

// 出错输出
if ($response === false) {
    die("CURL ERROR: " . $curl_error);
}

// ========== m3u8 替换逻辑 ==========
$is_m3u8 = false;
$content_type = $response_headers['content-type'] ?? '';

if (
    strpos($request_url, '.m3u8') !== false ||
    stripos($content_type, 'mpegurl') !== false ||
    stripos($content_type, 'application/x-mpegurl') !== false ||
    stripos($content_type, 'text/plain') !== false ||
    strpos(ltrim($body), '#EXTM3U') === 0
) {
    $is_m3u8 = true;
}

if ($is_m3u8) {
    $base_root = $parsed_url['scheme'] . '://' . $parsed_url['host'] .
        (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '');
    $base_dir = $base_root . dirname($parsed_url['path']) . '/';

    $body = preg_replace_callback(
        // 更稳健的正则表达式：匹配 URL、绝对路径、相对路径，允许 query 参数，允许无后缀
        '/(?P<url>(https?:\/\/[^\s"\']+)|((\/|\.\.?\/)?[^\s"\']+))/i',
        function ($matches) use ($base_root, $base_dir) {
            $url = trim($matches['url']);

            // 跳过 m3u8 语法行
            if (str_starts_with($url, '#')) return $url;

            // 跳过 data uri 等
            if (str_starts_with($url, 'data:')) return $url;

            // 跳过已经被代理过的
            if (str_contains($url, 'mytv.php?url=')) return $url;

            // 完整 URL
            if (preg_match('/^https?:\/\//i', $url)) {
                return 'mytv.php?url=' . urlencode($url);
            }

            // 以 / 开头（绝对路径）
            if (str_starts_with($url, '/')) {
                return 'mytv.php?url=' . urlencode($base_root . $url);
            }

            // 处理 ../ 或 ./ 等相对路径
            return 'mytv.php?url=' . urlencode($base_dir . $url);
        },
        $body
    );

    header('Content-Disposition: inline; filename=index.m3u8');
}

echo $body;
?>
