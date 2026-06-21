<?php
/**
 * VPN/代理自动封禁模块
 * 功能：检测访问者是否使用 VPN/代理，若是则封禁其 IP 并拒绝访问
 * 存储：黑名单 + 检测结果缓存（JSON 文件）
 * API：ip-api.com（免费，无需密钥，每分钟最多 45 次请求）
 */

// ====================== 配置区域 ======================
define('BANNED_IPS_FILE', __DIR__ . '/banned_ips.json');      // 黑名单文件
define('VPN_CACHE_FILE',  __DIR__ . '/vpn_cache.json');       // 检测缓存文件
define('CACHE_TTL',       86400);                             // 缓存有效期（秒），24小时
define('API_TIMEOUT',     2);                                 // API 请求超时（秒）
define('ENABLE_DEBUG',    false);                             // 调试模式（记录日志）
// ======================================================

// 获取客户端真实 IP（忽略常见内网/回环地址）
function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // 如果服务器前有反向代理且信任代理头，可取消注释以下代码（注意安全风险）
    // if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    //     $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    //     $ip = trim($ips[0]);
    // }
    // 过滤无效 IP 和回环地址
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $ip;
    }
    // 若获取的是内网 IP，仍返回原值（可能是本地开发或 NAT）
    return $ip ?: '0.0.0.0';
}

// 记录调试信息（文件日志）
function debug_log($msg) {
    if (ENABLE_DEBUG) {
        file_put_contents(__DIR__ . '/vpn_block_debug.log', date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
    }
}

// 从 JSON 文件读取数据（加共享锁）
function read_json_file($file, $default = []) {
    if (!file_exists($file)) return $default;
    $fp = fopen($file, 'r');
    if (flock($fp, LOCK_SH)) {
        $data = '';
        while (!feof($fp)) $data .= fread($fp, 8192);
        flock($fp, LOCK_UN);
        fclose($fp);
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : $default;
    }
    fclose($fp);
    return $default;
}

// 写入 JSON 文件（加独占锁）
function write_json_file($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fp = fopen($file, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

// 检查 IP 是否已在黑名单
function is_ip_banned($ip) {
    $banned = read_json_file(BANNED_IPS_FILE, []);
    return isset($banned[$ip]) && ($banned[$ip]['banned_until'] > time());
}

// 将 IP 加入黑名单（永久封禁，可将 banned_until 设为超大值）
function add_to_banned($ip, $reason = 'VPN/Proxy detected') {
    $banned = read_json_file(BANNED_IPS_FILE, []);
    // 永久封禁（设置 10 年后过期，实际可视为永久）
    $banned[$ip] = [
        'reason'       => $reason,
        'banned_at'    => time(),
        'banned_until' => time() + 315360000   // 10年
    ];
    write_json_file(BANNED_IPS_FILE, $banned);
    debug_log("IP {$ip} 已被封禁，原因：{$reason}");
}

// 获取缓存中的 VPN 检测结果（返回 null 表示无缓存或已过期）
function get_cached_vpn_status($ip) {
    $cache = read_json_file(VPN_CACHE_FILE, []);
    if (isset($cache[$ip]) && ($cache[$ip]['expires'] > time())) {
        return $cache[$ip]['is_vpn'];
    }
    return null;
}

// 保存 VPN 检测结果到缓存
function set_cached_vpn_status($ip, $is_vpn) {
    $cache = read_json_file(VPN_CACHE_FILE, []);
    $cache[$ip] = [
        'is_vpn'  => $is_vpn,
        'expires' => time() + CACHE_TTL
    ];
    write_json_file(VPN_CACHE_FILE, $cache);
}

// 通过 ip-api.com 检测 IP 是否为代理/VPN
function is_vpn_by_api($ip) {
    $url = "http://ip-api.com/json/{$ip}?fields=status,proxy,message";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'VPN-Blocker/1.0'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    //curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        debug_log("API 请求失败 IP:{$ip} error:{$curlError} http:{$httpCode}");
        return null; // 返回 null 表示检测失败
    }

    $data = json_decode($response, true);
    if (!isset($data['status']) || $data['status'] !== 'success') {
        debug_log("API 返回错误 IP:{$ip} message:" . ($data['message'] ?? 'unknown'));
        return null;
    }
    // proxy 字段为 true 表示是代理或 VPN
    return !empty($data['proxy']);
}

// ====================== 主执行逻辑 ======================
$client_ip = get_client_ip();

// 1. 黑名单检查
if (is_ip_banned($client_ip)) {
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Your IP address has been banned due to VPN/proxy usage.</p>');
}

// 2. 检查缓存结果
$cached = get_cached_vpn_status($client_ip);
if ($cached === true) {
    // 缓存记录为 VPN，直接封禁
    add_to_banned($client_ip, 'VPN/Proxy (from cache)');
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>VPN or proxy detected. Access denied.</p>');
} elseif ($cached === false) {
    // 缓存记录为非 VPN，允许访问
    debug_log("IP {$client_ip} 已通过缓存验证为非VPN，允许访问");
    return; // 正常继续执行后续代码
}

// 3. 无有效缓存，调用 API 检测
$is_vpn = is_vpn_by_api($client_ip);
if ($is_vpn === null) {
    // API 检测失败（网络超时或接口错误），采取保守策略：允许访问，但记录日志
    debug_log("IP {$client_ip} API 检测失败，临时允许访问");
    // 同时可将失败结果缓存短时间，避免短时间内重复失败（可选）
    // set_cached_vpn_status($client_ip, false); // 不建议强制缓存为 false，否则可能漏封
    return;
}

// 保存检测结果到缓存
set_cached_vpn_status($client_ip, $is_vpn);

if ($is_vpn) {
    // 检测为 VPN/代理，立即封禁并拒绝访问
    add_to_banned($client_ip, 'VPN/Proxy detected by API');
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>VPN or proxy detected. Access denied.</p>');
}

// 非 VPN，允许正常访问
debug_log("IP {$client_ip} 非VPN，允许访问");
return;