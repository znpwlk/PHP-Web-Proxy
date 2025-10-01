<?php
// Stealth PHP Reverse Proxy with first-time secure path setup
// - First visit: show setup page to configure a secret path
// - After setup: homepage shows official "Welcome to nginx!" style
// - Proxy is accessed only via /{secure_path}; initial URL submitted by POST

error_reporting(0);
header_remove('X-Powered-By');
session_start();

$CONFIG_FILE = __DIR__ . DIRECTORY_SEPARATOR . '.proxy_config.php';

function load_config(string $file) {
    if (!file_exists($file)) return null;
    $cfg = include $file;
    if (!is_array($cfg) || empty($cfg['secure_path'])) return null;
    return $cfg;
}

function save_config(string $file, string $securePath): bool {
    $cfg = [
        'secure_path' => $securePath,
        'created_at' => time(),
    ];
    $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
    return @file_put_contents($file, $php) !== false;
}

// Utilities
function resolve_url(string $base, string $rel): string {
    if ($rel === '') return $base;
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', $rel)) return $rel; // absolute
    if (strpos($rel, '//') === 0) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'http';
        return $scheme . ':' . $rel;
    }
    $p = parse_url($base);
    $scheme = $p['scheme'] ?? 'http';
    $host = $p['host'] ?? '';
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $basePath = $p['path'] ?? '/';
    $baseDir = preg_replace('#/[^/]*$#', '/', $basePath);
    $path = (strpos($rel, '/') === 0) ? $rel : $baseDir . $rel;
    $segments = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') array_pop($segments); else $segments[] = $seg;
    }
    $path = '/' . implode('/', $segments);
    $q = '';
    $f = '';
    if (strpos($rel, '?') !== false) {
        $q = parse_url($rel, PHP_URL_QUERY);
        $q = $q ? '?' . $q : '';
    }
    if (strpos($rel, '#') !== false) {
        $f = parse_url($rel, PHP_URL_FRAGMENT);
        $f = $f ? '#' . $f : '';
    }
    return $scheme . '://' . $host . $port . $path . $q . $f;
}

function rewrite_links(string $html, string $base, string $proxyPath): string {
    $proxy = rtrim($proxyPath, '/');
    $callback = function ($matches) use ($base, $proxy) {
        $attr = $matches[1];
        $url = html_entity_decode($matches[2], ENT_QUOTES);
        if ($url === '') return $matches[0];
        if (preg_match('#^(mailto:|javascript:|data:)#i', $url)) return $matches[0];
        $abs = resolve_url($base, $url);
        $proxied = $proxy . '?u=' . urlencode($abs);
        return $attr . '="' . htmlspecialchars($proxied, ENT_QUOTES) . '"';
    };
    $html = preg_replace_callback('/\b(href|src|action)\s*=\s*"([^"]*)"/i', $callback, $html);
    $html = preg_replace_callback("/\\b(href|src|action)\\s*=\\s*'([^']*)'/i", $callback, $html);
    return $html;
}

// Pages
function render_setup_form(): void {
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>首次设置安全链接</title>'
       . '<style>body{font-family:system-ui,Segoe UI,Arial;max-width:720px;margin:60px auto;padding:0 16px;color:#222}'
       . 'h1{font-size:22px;margin-bottom:16px}label{display:block;margin-bottom:6px;color:#444}'
       . 'input{padding:10px;border:1px solid #ccc;border-radius:6px;width:100%;font-size:15px}'
       . 'button{margin-top:12px;padding:10px 14px;border:0;border-radius:6px;background:#0b76ef;color:#fff;cursor:pointer}'
       . '.tip{color:#666;margin-top:10px;font-size:13px}'
       . '</style></head><body>'
       . '<h1>设置安全链接路径</h1>'
       . '<form method="POST">'
       . '<label for="secure_path">安全路径（仅字母数字与短横，长度≥6）：</label>'
       . '<input id="secure_path" name="secure_path" pattern="[A-Za-z0-9-]{6,64}" required placeholder="例如 portal-abc123">'
       . '<button type="submit">保存</button>'
       . '</form>'
       . '<div class="tip">设置后：主页将显示官方 Nginx 欢迎页；仅通过 /{安全路径} 进入代理页面。为隐匿，入口不在首页展示。</div>'
       . '</body></html>';
}

function render_nginx_page(string $securePath): void {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome to nginx!</title>'
       . '<style>body{width:35em;margin:0 auto;font-family:Tahoma,Verdana,Arial,sans-serif;color:#000}h1{color:#000}</style>'
       . '</head><body>'
       . '<h1>Welcome to nginx!</h1>'
       . '<p>If you see this page, the nginx web server is successfully installed and working. Further configuration is required.</p>'
       . '<p>For online documentation and support please refer to <a href="http://nginx.org/">nginx.org</a>.<br/>'
       . 'Commercial support is available at <a href="http://nginx.com/">nginx.com</a>.</p>'
       . '<p><em>Thank you for using nginx.</em></p>'
       . '</body></html>';
}

function render_proxy_form(string $securePath): void {
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>入口</title>'
       . '<style>body{font-family:system-ui,Segoe UI,Arial;max-width:720px;margin:40px auto;padding:0 16px;color:#222}'
       . 'h1{font-size:18px;margin-bottom:12px}.tip{color:#666;margin-top:10px;font-size:13px}'
       . 'input[type=url]{width:100%;padding:10px;border:1px solid #ccc;border-radius:6px}'
       . 'button{margin-top:10px;padding:10px 14px;border:0;border-radius:6px;background:#0b76ef;color:#fff;cursor:pointer}'
       . '</style></head><body>'
       . '<h1>代理入口</h1>'
       . '<form method="POST"><input type="url" name="url" placeholder="输入要访问的链接，例如 https://example.com" required>'
       . '<button type="submit">提交</button></form>'
       . '<div class="tip">初次提交使用 POST；页面中的资源链接将自动重写并通过本入口继续访问。</div>'
       . '</body></html>';
}

// Core proxy
function handle_proxy(string $target, string $proxyPath): void {
    $target = trim($target);
    if (!filter_var($target, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo '错误：URL 不合法';
        return;
    }
    $scheme = parse_url($target, PHP_URL_SCHEME);
    if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
        http_response_code(400);
        echo '错误：仅支持 http/https';
        return;
    }

    $method = 'GET'; // downstream fetch should be GET for resource loading
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $target);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    // Stealth headers
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    $hdrs = [
        'User-Agent: ' . $ua,
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        http_response_code(502);
        echo '代理错误：' . htmlspecialchars($err, ENT_QUOTES);
        return;
    }
    $info = curl_getinfo($ch);
    $headerSize = $info['header_size'] ?? 0;
    curl_close($ch);

    $rawHeaders = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    $blocks = preg_split('/\r\n\r\n/', trim($rawHeaders));
    $headerBlock = end($blocks);
    $lines = explode("\r\n", $headerBlock);
    $statusLine = array_shift($lines);
    $statusCode = 200;
    if (preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m)) $statusCode = (int)$m[1];

    $up = [];
    foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
            [$n, $v] = explode(':', $line, 2);
            $n = trim($n);
            $v = trim($v);
            $up[$n] = $up[$n] ?? [];
            $up[$n][] = $v;
        }
    }

    // Redirects -> keep inside proxy
    if (in_array($statusCode, [301,302,303,307,308], true) && isset($up['Location'])) {
        $loc = $up['Location'][0];
        $absLoc = resolve_url($target, $loc);
        $proxyLoc = rtrim($proxyPath, '/') . '?u=' . urlencode($absLoc);
        header('Location: ' . $proxyLoc, true, $statusCode);
        return;
    }

    // Pass selected headers and avoid disclosing PHP
    if (!empty($up['Content-Type'])) header('Content-Type: ' . $up['Content-Type'][0]);
    header('Cache-Control: no-store');
    if (!empty($up['Content-Language'])) header('Content-Language: ' . $up['Content-Language'][0]);
    if (!empty($up['Set-Cookie'])) foreach ($up['Set-Cookie'] as $cookie) header('Set-Cookie: ' . $cookie, false);

    // Rewrite HTML links
    $contentType = $up['Content-Type'][0] ?? ($info['content_type'] ?? '');
    if ($contentType && stripos($contentType, 'text/html') !== false) {
        $body = rewrite_links($body, $target, rtrim($proxyPath, '/'));
    }

    http_response_code($statusCode);
    echo $body;
}

// Routing
$config = load_config($CONFIG_FILE);
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$config) {
    if ($method === 'POST' && isset($_POST['secure_path'])) {
        $path = strtolower(trim($_POST['secure_path']));
        if (!preg_match('/^[a-z0-9-]{6,64}$/', $path)) {
            http_response_code(400);
            echo '安全路径不合法，须为 6-64 位字母数字或短横。';
            exit;
        }
        if (!save_config($CONFIG_FILE, $path)) {
            http_response_code(500);
            echo '保存失败，请检查文件写入权限。';
            exit;
        }
        header('Location: /');
        exit;
    }
    render_setup_form();
    exit;
}

$securePath = '/' . $config['secure_path'];

if ($uri === '/' || $uri === '') {
    render_nginx_page($securePath);
    exit;
}

if (rtrim($uri, '/') === rtrim($securePath, '/')) {
    if ($method === 'POST' && isset($_POST['url'])) {
        handle_proxy($_POST['url'], $securePath);
        exit;
    }
    if ($method === 'GET' && isset($_GET['u'])) {
        handle_proxy($_GET['u'], $securePath);
        exit;
    }
    render_proxy_form($securePath);
    exit;
}

http_response_code(404);
echo '404 Not Found';