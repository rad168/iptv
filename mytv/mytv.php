<?php
//error_reporting(E_ALL);
//ini_set('display_errors', '1');

$request_url = isset($_GET['url']) ? urldecode($_GET['url']) : '';
$p = $_GET['p'] ?? '';

//订阅m3u列表
if ($p === 'm3u') {

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
        ? 'https' 
        : 'http';

    $path = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];


$m3uurl = "https://github.com/rad168/iptv/raw/refs/heads/main/mytv0.m3u";

$m3u = file_get_contents($m3uurl);

$m3u = preg_replace(
    '#http://[^/]+/mytv\.php#',
    $path,
    $m3u
);

header("Content-Type: text/plain; charset=utf-8");

echo $m3u;
exit;
}

if (empty($request_url) && empty($p)) {
    die('缺少 url 参数');
}

// 可选域名白名单（支持带端口的域名/IP）
$allowed_domains = [
    'php.jdshipin.com',
    'cdn12.jdshipin.com',
    'o11.163189.xyz',
    'cdn.163189.xyz',
    'cdn2.163189.xyz',
    'cdn3.163189.xyz',
    'cdn5.163189.xyz',
    'cdn6.163189.xyz',
    'cdn9.163189.xyz',
    '127.0.0.1',        // 示例：本地IP
    '192.168.1.100'     // 示例：内网IP
];

// 是否启用域名检查（false 表示允许任何域名/IP）
$enable_domain_check = false;

$parsed_url = parse_url($request_url);
$host = $parsed_url['host'] ?? '';
$port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';

// 构建完整的主机标识（包含端口）用于白名单检查
$host_with_port = $host . $port;
$host_without_port = $host;

if ($enable_domain_check) {
    // 检查是否在白名单中（支持带端口或不带端口）
    $is_allowed = in_array($host_without_port, $allowed_domains) || 
                  in_array($host_with_port, $allowed_domains);
    
    if (!$is_allowed) {
        die('非法请求的域名');
    }
}

// =================== TS 文件 Range 支持 ===================
if (preg_match('/\.ts$/i', $parsed_url['path'])) {
    // 初始化 cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $request_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    // 支持 Range 请求
    if (isset($_SERVER['HTTP_RANGE'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Range: " . $_SERVER['HTTP_RANGE']]);
        http_response_code(206); // 部分内容
    } else {
        http_response_code(200);
    }

    $ts_data = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($ts_data === false) {
        die("CURL ERROR: " . $curl_error);
    }

    header('Content-Type: video/MP2T');
    header('Content-Length: ' . strlen($ts_data));
    if (isset($_SERVER['HTTP_RANGE'])) {
        header('Accept-Ranges: bytes');
    }
    echo $ts_data;
    exit();
}

// =================== 普通 m3u8 代理 ===================

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

// 兼容 str_contains() 函数
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

// 构建 Host 头，包含端口（如果有）
$host_header = $host;
if (isset($parsed_url['port'])) {
    $host_header .= ':' . $parsed_url['port'];
}
$headers[] = "Host: $host_header";

// 构建 Referer，包含端口（如果有）
$referer_host = $host;
if (isset($parsed_url['port'])) {
    $referer_host .= ':' . $parsed_url['port'];
}
$headers[] = "User-Agent: AppleCoreMedia/1.0.0.7B367 (iPad; U; CPU OS 4_3_3 like Mac OS X)";
$headers[] = "Referer: " . $parsed_url['scheme'] . "://$referer_host/";
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

// 对于 IP 地址，可能需要禁用 SSL 主机验证
if (filter_var($host, FILTER_VALIDATE_IP)) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

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

// =================== m3u8 代理 ===================
$is_m3u8 = false;
$content_type = $response_headers['content-type'] ?? '';

if (
    strpos($request_url, '.m3u8') !== false ||
    stripos($content_type, 'mpegurl') !== false ||
    stripos($content_type, 'application/x-mpegurl') !== false ||
    strpos(ltrim($body), '#EXTM3U') === 0
) {
    $is_m3u8 = true;
}

if ($is_m3u8) {
    // 构建基础 URL，包含端口
    $base_root = $parsed_url['scheme'] . '://' . $parsed_url['host'];
    if (isset($parsed_url['port'])) {
        $base_root .= ':' . $parsed_url['port'];
    }

    $path = $parsed_url['path'] ?? '/';
    if (substr($path, -1) === '/') {
        $base_dir = $base_root . $path;
    } else {
        $base_dir = $base_root . dirname($path) . '/';
    }

    // ================= m3u8 安全逐行解析 =================
    $lines = preg_split("/\r\n|\n|\r/", $body);
    $out   = [];

    foreach ($lines as $rawLine) {
        $line = rtrim($rawLine);

        if ($line === '') {
            $out[] = $rawLine;
            continue;
        }

        // 处理 URI="..." 行
        if ($line[0] === '#' && stripos($line, 'URI="') !== false) {
            $line = preg_replace_callback(
                '/URI="([^"]+)"/i',
                function ($m) use ($base_root, $base_dir) {
                    $uri = $m[1];

                    if (strpos($uri, 'mytv.php?url=') !== false) {
                        return 'URI="' . $uri . '"';
                    }

                    if (preg_match('#^https?://#i', $uri)) {
                        $url = $uri;
                    } elseif (str_starts_with($uri, '/')) {
                        $url = $base_root . $uri;
                    } else {
                        $url = $base_dir . $uri;
                    }

                    return 'URI="mytv.php?url=' . urlencode($url) . '"';
                },
                $line
            );

            $out[] = $line;
            continue;
        }

        // 其它 EXT 行原样保留
        if ($line[0] === '#') {
            $out[] = $line;
            continue;
        }

        // 已代理的不重复处理
        if (strpos($line, 'mytv.php?url=') !== false) {
            $out[] = $line;
            continue;
        }

        // 普通资源行
        if (preg_match('#^https?://#i', $line)) {
            $url = $line;
        } elseif (str_starts_with($line, '/')) {
            $url = $base_root . $line;
        } else {
            $url = $base_dir . $line;
        }

        $out[] = 'mytv.php?url=' . urlencode($url);
    }

    $body = implode("\n", $out);
    header('Content-Disposition: inline; filename=index.m3u8');
}

echo $body;
?>
