<?php
/**
 * 公共方法：CORS、JSON、数据库、鉴权
 * 语法兼容 PHP5.1：禁止短数组 []，使用 array()
 */

require_once dirname(__FILE__) . '/config.php';

function wm_cors() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, token, Authorization, X-Requested-With');
    header('Content-Type: application/json; charset=utf-8');
    if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
        exit;
    }
}

function wm_json($code, $msg, $data) {
    if ($data === null) {
        $data = new stdClass();
    }
    $out = array(
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    );
    echo json_encode($out);
    exit;
}

function wm_ok($data) {
    wm_json(0, 'success', $data);
}

function wm_err($msg, $code) {
    if ($code === null || $code === 0) {
        $code = 1;
    }
    wm_json($code, $msg, null);
}

/**
 * 数据库连接：优先 mysqli（兼容 PHP7/8 与线上），否则回退 mysql_*（PHP5.x）
 */
function wm_db() {
    static $link = null;
    static $driver = null;
    global $WM_CONFIG;

    if ($link !== null) {
        return array('link' => $link, 'driver' => $driver);
    }

    $host = $WM_CONFIG['db_host'];
    $user = $WM_CONFIG['db_user'];
    $pass = $WM_CONFIG['db_pass'];
    $name = $WM_CONFIG['db_name'];
    $port = intval($WM_CONFIG['db_port']);
    if ($port <= 0) {
        $port = 3306;
    }

    if (function_exists('mysqli_connect')) {
        $link = @mysqli_connect($host, $user, $pass, $name, $port);
        if (!$link) {
            wm_err('数据库连接失败', 500);
        }
        mysqli_set_charset($link, $WM_CONFIG['db_charset']);
        $driver = 'mysqli';
    } else if (function_exists('mysql_connect')) {
        $link = @mysql_connect($host . ':' . $port, $user, $pass);
        if (!$link) {
            wm_err('数据库连接失败', 500);
        }
        if (!mysql_select_db($name, $link)) {
            wm_err('数据库选择失败', 500);
        }
        mysql_query("SET NAMES '" . $WM_CONFIG['db_charset'] . "'", $link);
        $driver = 'mysql';
    } else {
        wm_err('服务器未启用 MySQL 扩展', 500);
    }

    return array('link' => $link, 'driver' => $driver);
}

function wm_escape($str) {
    $db = wm_db();
    $str = strval($str);
    if ($db['driver'] === 'mysqli') {
        return mysqli_real_escape_string($db['link'], $str);
    }
    return mysql_real_escape_string($str, $db['link']);
}

function wm_query($sql) {
    $db = wm_db();
    if ($db['driver'] === 'mysqli') {
        $res = mysqli_query($db['link'], $sql);
        if ($res === false) {
            wm_err('SQL错误: ' . mysqli_error($db['link']), 500);
        }
        return $res;
    }
    $res = mysql_query($sql, $db['link']);
    if ($res === false) {
        wm_err('SQL错误: ' . mysql_error($db['link']), 500);
    }
    return $res;
}

function wm_fetch_all($sql) {
    $res = wm_query($sql);
    $list = array();
    $db = wm_db();
    if ($db['driver'] === 'mysqli') {
        while ($row = mysqli_fetch_assoc($res)) {
            $list[] = $row;
        }
    } else {
        while ($row = mysql_fetch_assoc($res)) {
            $list[] = $row;
        }
    }
    return $list;
}

function wm_fetch_one($sql) {
    $list = wm_fetch_all($sql);
    if (count($list) > 0) {
        return $list[0];
    }
    return null;
}

function wm_insert_id() {
    $db = wm_db();
    if ($db['driver'] === 'mysqli') {
        return intval(mysqli_insert_id($db['link']));
    }
    return intval(mysql_insert_id($db['link']));
}

function wm_affected() {
    $db = wm_db();
    if ($db['driver'] === 'mysqli') {
        return intval(mysqli_affected_rows($db['link']));
    }
    return intval(mysql_affected_rows($db['link']));
}

function wm_input($key, $default) {
    if ($default === null) {
        $default = '';
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    // JSON body
    static $json = null;
    if ($json === null) {
        $raw = file_get_contents('php://input');
        $json = array();
        if ($raw) {
            $tmp = json_decode($raw, true);
            if (is_array($tmp)) {
                $json = $tmp;
            }
        }
    }
    if (isset($json[$key])) {
        return $json[$key];
    }
    return $default;
}

function wm_header_token() {
    $token = '';
    if (isset($_SERVER['HTTP_TOKEN'])) {
        $token = $_SERVER['HTTP_TOKEN'];
    } else if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strtolower($k) === 'token') {
                    $token = $v;
                    break;
                }
            }
        }
    }
    if ($token === '' && isset($_GET['token'])) {
        $token = $_GET['token'];
    }
    if ($token === '' && isset($_POST['token'])) {
        $token = $_POST['token'];
    }
    return trim($token);
}

function wm_make_wx_token($user) {
    global $WM_CONFIG;
    return md5($user['id'] . '|' . strval($user['openid']) . '|' . $WM_CONFIG['token_salt']);
}

/**
 * 解析当前用户：
 * - 游客：token === anonymous_id（不存在则自动建号）
 * - 微信：token === make_wx_token(user)
 */
function wm_auth_user($auto_create_guest) {
    $token = wm_header_token();
    if ($token === '') {
        wm_err('缺少 token', 401);
    }

    // 微信 token
    $users = wm_fetch_all("SELECT * FROM `user` WHERE openid IS NOT NULL AND openid <> ''");
    $i = 0;
    $n = count($users);
    for ($i = 0; $i < $n; $i++) {
        if (wm_make_wx_token($users[$i]) === $token) {
            return $users[$i];
        }
    }

    // 游客 anonymous_id
    $aid = wm_escape($token);
    $guest = wm_fetch_one("SELECT * FROM `user` WHERE anonymous_id='{$aid}' LIMIT 1");
    if ($guest) {
        return $guest;
    }

    if ($auto_create_guest) {
        $now = date('Y-m-d H:i:s');
        wm_query("INSERT INTO `user` (openid, anonymous_id, nickname, avatar, create_time) VALUES (NULL, '{$aid}', '游客', '', '{$now}')");
        $id = wm_insert_id();
        return wm_fetch_one("SELECT * FROM `user` WHERE id={$id} LIMIT 1");
    }

    wm_err('登录无效，请重新进入', 401);
    return null;
}

function wm_http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
    $ctx = stream_context_create(array(
        'http' => array('timeout' => 10)
    ));
    return @file_get_contents($url, false, $ctx);
}

function wm_unlock_badge($user_id, $badge_key, $badge_name) {
    $uid = intval($user_id);
    $key = wm_escape($badge_key);
    $name = wm_escape($badge_name);
    $exist = wm_fetch_one("SELECT id FROM user_badge WHERE user_id={$uid} AND badge_key='{$key}' LIMIT 1");
    if ($exist) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    wm_query("INSERT INTO user_badge (user_id, badge_key, badge_name, unlock_time) VALUES ({$uid}, '{$key}', '{$name}', '{$now}')");
    return true;
}

/**
 * 保存对局后校验徽章
 */
function wm_check_badges($user_id, $right_count) {
    $uid = intval($user_id);
    $right_count = intval($right_count);

    // first_game
    wm_unlock_badge($uid, 'first_game', '初次捕鼠');

    // novice
    if ($right_count >= 6) {
        wm_unlock_badge($uid, 'novice', '初级捕鼠手');
    }

    // hunter
    if ($right_count >= 9) {
        wm_unlock_badge($uid, 'hunter', '单词猎手');
    }

    // persist5：连续 5 个自然日至少一局
    $rows = wm_fetch_all("SELECT DISTINCT DATE(create_time) AS d FROM game_record WHERE user_id={$uid} ORDER BY d DESC LIMIT 10");
    $need = array();
    $t = time();
    for ($i = 0; $i < 5; $i++) {
        $need[] = date('Y-m-d', $t - $i * 86400);
    }
    $have = array();
    $j = 0;
    for ($j = 0; $j < count($rows); $j++) {
        $have[$rows[$j]['d']] = 1;
    }
    $ok = true;
    for ($i = 0; $i < 5; $i++) {
        if (!isset($have[$need[$i]])) {
            $ok = false;
            break;
        }
    }
    if ($ok) {
        wm_unlock_badge($uid, 'persist5', '坚持达人');
    }
}

function wm_user_public($user) {
    $token = '';
    if ($user['openid']) {
        $token = wm_make_wx_token($user);
    } else {
        $token = strval($user['anonymous_id']);
    }
    return array(
        'id' => intval($user['id']),
        'nickname' => $user['nickname'] ? $user['nickname'] : '游客',
        'avatar' => $user['avatar'] ? $user['avatar'] : '',
        'is_guest' => $user['openid'] ? 0 : 1,
        'token' => $token
    );
}
