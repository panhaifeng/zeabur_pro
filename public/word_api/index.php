<?php
/**
 * 单词打地鼠 API 入口
 * 访问示例：
 *   /word_api/index.php?r=user/get-info
 *   /word_api/user/get-info   （需 .htaccess 伪静态）
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once dirname(__FILE__) . '/common.php';
require_once dirname(__FILE__) . '/api_user.php';
require_once dirname(__FILE__) . '/api_word.php';
require_once dirname(__FILE__) . '/api_game.php';

wm_cors();

$r = '';
if (isset($_GET['r'])) {
    $r = $_GET['r'];
} else if (isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO']) {
    $r = trim($_SERVER['PATH_INFO'], '/');
} else {
    // 兼容 /word_api/user/get-info 被 rewrite 到本文件
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $pos = strpos($uri, 'word_api/');
    if ($pos !== false) {
        $part = substr($uri, $pos + strlen('word_api/'));
        $part = preg_replace('/\?.*$/', '', $part);
        $part = trim($part, '/');
        if ($part !== '' && $part !== 'index.php') {
            if (strpos($part, 'index.php/') === 0) {
                $part = substr($part, strlen('index.php/'));
            }
            $r = $part;
        }
    }
}

$r = trim($r, '/');
if ($r === '') {
    wm_ok(array('service' => 'word_mole_api', 'version' => '1.0'));
}

$map = array(
    'user/get-info' => 'api_user_get_info',
    'user/error-word' => 'api_user_error_word',
    'user/remove-word' => 'api_user_remove_word',
    'user/record-list' => 'api_user_record_list',
    'user/badge' => 'api_user_badge',
    'user/wx-login' => 'api_user_wx_login',
    'word/lib-list' => 'api_word_lib_list',
    'game/start' => 'api_game_start',
    'game/save-round' => 'api_game_save_round'
);

if (!isset($map[$r])) {
    wm_err('接口不存在: ' . $r, 404);
}

$fn = $map[$r];
if (!function_exists($fn)) {
    wm_err('接口未实现', 500);
}
$fn();
