<?php
// 获取请求的 URL 并修正编码
$request_url = isset($_GET['url']) ? urldecode($_GET['url']) : '';
if (empty($request_url)) {
    die('缺少 url 参数');
}

// 允许代理的域名列表
$allowed_domains = [
    'aktv.top',
    'cdn12.jdshipin.com',
    'v2h.jdshipin.com',
    'v2hcdn.jdshipin.com',
    'cdn.163189.xyz',
    'cdn2.163189.xyz',
    'cdn3.163189.xyz',
    'cdn5.163189.xyz',
    'cdn9.163189.xyz'
];

$parsed_url = parse_url($request_url);
if (!$parsed_url || !isset($parsed_url['host']) || !in_array($parsed_url['host'], $allowed_domains)) {
    die('非法请求的域名');
}

// 处理 HTTP 头信息
$headers = [];
foreach (getallheaders() as $name => $value) {
    if (strtolower($name) !== 'host') {
        $headers[] = "$name: $value";
    }
}
$headers[] = "Host: {$parsed_url['host']}";
$headers[] = "User-Agent: AppleCoreMedia/1.0.0.7B367 (iPad; U; CPU OS 4_3_3 like Mac OS X)";
$headers[] = "Referer: https://{$parsed_url['host']}/";
$headers[] = "Accept-Encoding: gzip, deflate";

// 发送请求
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $request_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_ENCODING, ""); // 自动解码 gzip/deflate

// 禁用 HTTP/2，强制使用 HTTP/1.1
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// 设置 HTTP 响应状态码
http_response_code($http_code);

if ($response === false) {
    die("CURL ERROR: " . $curl_error);
}

// 如果是 m3u8 文件，仅替换 .ts 链接
if (strpos($request_url, '.m3u8') !== false) {
    $base_url = dirname($request_url) . '/';
    
    // 修正 TS 链接的替换逻辑
    $response = preg_replace_callback('/(https?:\/\/(?:' . implode('|', $allowed_domains) . ')\/[^\s"\r\n]*\.ts)|([^\s"\r\n]*\.ts)/', function ($matches) use ($base_url, $parsed_url) {
        if (!empty($matches[1])) {
            // 如果是带域名的 ts 链接，直接加上 /mytv.php?url=
            return 'mytv.php?url=' . urlencode($matches[1]);
        } elseif (!empty($matches[2])) {
            // 如果是相对路径的 ts 链接，拼接完整的 URL，并保留文件路径
            return 'mytv.php?url=' . urlencode($base_url . ltrim($matches[2], "/"));
        }
    }, $response);

}

echo $response;
?>
