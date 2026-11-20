<?php
/**
 * MicroPython Playground —— 内核本地更新脚本
 *
 * 由 micropython-playground.html 的「更新到本地」按钮调用：
 *   POST update.php   body: version=1.29.0-6
 * 从多个 CDN 依次尝试下载 micropython.wasm 与 micropython.mjs，
 * 覆盖前先把原文件备份为 .bak（如 micropython.wasm.bak），再写入本脚本所在目录。
 * 全部 CDN 失败才返回失败；备份失败则中止该文件的覆盖。
 *
 * 返回 JSON：
 *   { ok: bool, version: string, results: { file: {ok, source, bytes, backup, error} }, error?: string }
 *
 * 轻量探测（供页面「关于」框读取服务器 PHP 版本，不下载、不写入）：
 *   GET update.php?probe=php  →  { ok: true, php: "8.2.1" }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function mp_out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* 前端「关于」框探测服务器 PHP 版本：在任何下载/写入之前返回 */
if (isset($_GET['probe']) && $_GET['probe'] === 'php') {
    mp_out(['ok' => true, 'php' => PHP_VERSION]);
}

/* 取版本参数（仅接受白名单格式，防止路径/参数注入） */
$version = '';
if (isset($_POST['version'])) {
    $version = trim((string)$_POST['version']);
} elseif (isset($_GET['version'])) {
    $version = trim((string)$_GET['version']);
}
if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version)) {
    mp_out(['ok' => false, 'version' => $version, 'error' => '版本号格式无效：' . $version]);
}

/* 只更新固定的两个内核文件，不接受任意文件名 */
$files = ['micropython.wasm', 'micropython.mjs'];

/* 可选 file 参数：只更新指定的单个文件（前端分文件请求以显示真实进度）。
 * 不传或非法则更新全部两个文件，保持向后兼容。
 *   POST update.php  version=1.29.0-6 & file=micropython.wasm */
$reqFile = '';
if (isset($_POST['file'])) {
    $reqFile = trim((string)$_POST['file']);
} elseif (isset($_GET['file'])) {
    $reqFile = trim((string)$_GET['file']);
}
if ($reqFile !== '' && in_array($reqFile, $files, true)) {
    $files = [$reqFile];
}

/* CDN 模板：%s 依次替换为版本和文件名 */
$cdnTemplates = [
    'jsdelivr'  => 'https://cdn.jsdelivr.net/npm/@micropython/micropython-webassembly-pyscript@%s/%s',
    'fastly'    => 'https://fastly.jsdelivr.net/npm/@micropython/micropython-webassembly-pyscript@%s/%s',
    'unpkg'     => 'https://unpkg.com/@micropython/micropython-webassembly-pyscript@%s/%s',
    'jsdmirror' => 'https://cdn.jsdmirror.com/npm/@micropython/micropython-webassembly-pyscript@%s/%s',
];

/** 用 cURL（优先）或 fopen 拉取 URL 内容，失败返回 false */
function mp_fetch(string $url, int $timeout = 30)
{
    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch !== false) {
            @curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => 'MicroPythonPlaygroundUpdater/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $data = @curl_exec($ch);
            $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);
            if ($data !== false && $code >= 200 && $code < 300) {
                return $data;
            }
            return false;
        }
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header'  => "User-Agent: MicroPythonPlaygroundUpdater/1.0\r\n",
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? false : $data;
    }
    return false;
}

$dir = __DIR__;
if (!is_writable($dir)) {
    mp_out(['ok' => false, 'version' => $version, 'error' => '目录不可写，无法覆盖内核文件：' . $dir]);
}

$results = [];
$allOk = true;

foreach ($files as $file) {
    $data = false;
    $source = '';
    $tried = [];

    foreach ($cdnTemplates as $name => $tpl) {
        $url = sprintf($tpl, $version, $file);
        $bytes = mp_fetch($url);
        if ($bytes !== false && strlen($bytes) > 1024) {
            $data = $bytes;
            $source = $name;
            break;
        }
        $tried[] = $name;
    }

    if ($data === false) {
        $allOk = false;
        $results[$file] = [
            'ok'    => false,
            'error' => '全部 CDN 下载失败（已尝试：' . implode('、', $tried) . '）',
        ];
        continue;
    }

    /* 先写临时文件，再覆盖目标，避免中途失败损坏原文件 */
    $tmp = $dir . DIRECTORY_SEPARATOR . $file . '.update.tmp';
    $target = $dir . DIRECTORY_SEPARATOR . $file;
    if (@file_put_contents($tmp, $data) === false) {
        $allOk = false;
        $results[$file] = ['ok' => false, 'error' => '临时文件写入失败，请检查目录权限'];
        continue;
    }

    /* 覆盖前先把原文件备份为 .bak；备份失败则中止覆盖，保证可回退。
     * 用显式的读/写并校验字节数，避免某些环境下 copy() 被跳过或静默失败。 */
    clearstatcache(true, $target);
    $backup = $target . '.bak';
    $backupInfo = '无原文件（首次安装）';
    if (file_exists($target)) {
        $orig = @file_get_contents($target);
        $origLen = ($orig === false) ? -1 : strlen($orig);
        $wrote = ($origLen > 0) ? @file_put_contents($backup, $orig, LOCK_EX) : false;
        clearstatcache(true, $backup);
        if ($wrote !== false && file_exists($backup) && filesize($backup) === $origLen) {
            $backupInfo = basename($backup) . '（' . $origLen . ' 字节）';
        } else {
            @unlink($tmp);
            $allOk = false;
            $results[$file] = [
                'ok'    => false,
                'error' => '备份原文件失败，已中止覆盖 ' . $file . '（原文件 ' . $origLen .
                           ' 字节，备份写入 ' . ($wrote === false ? '失败' : $wrote . ' 字节') . '），请检查目录权限',
            ];
            continue;
        }
    }

    if (!@copy($tmp, $target)) {
        @unlink($tmp);
        $allOk = false;
        $results[$file] = ['ok' => false, 'error' => '覆盖 ' . $file . ' 失败，请检查文件权限'];
        continue;
    }
    @unlink($tmp);
    clearstatcache(true, $target);

    $results[$file] = [
        'ok'     => true,
        'source' => $source,
        'bytes'  => filesize($target),
        'backup' => $backupInfo,
    ];
}

mp_out([
    'ok'      => $allOk,
    'version' => $version,
    'dir'     => $dir,
    'results' => $results,
]);
