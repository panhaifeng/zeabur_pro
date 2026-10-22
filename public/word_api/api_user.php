<?php

function api_user_get_info() {
    $user = wm_auth_user(true);
    $uid = intval($user['id']);
    $badges = wm_fetch_all("SELECT badge_key, badge_name, unlock_time FROM user_badge WHERE user_id={$uid} ORDER BY unlock_time ASC");
    $info = wm_user_public($user);
    $info['badges'] = $badges;
    wm_ok($info);
}

function api_user_error_word() {
    $user = wm_auth_user(true);
    $uid = intval($user['id']);
    $sql = "SELECT ue.id AS bind_id, w.id AS word_item_id, w.word, w.phonetic, w.meaning, ue.add_time
            FROM user_error_word ue
            INNER JOIN word_item w ON w.id = ue.word_item_id
            WHERE ue.user_id={$uid}
            ORDER BY ue.add_time DESC";
    $list = wm_fetch_all($sql);
    wm_ok(array('list' => $list));
}

function api_user_remove_word() {
    $user = wm_auth_user(true);
    $uid = intval($user['id']);
    $word_item_id = intval(wm_input('word_item_id', 0));
    if ($word_item_id <= 0) {
        wm_err('参数错误', 1);
    }
    wm_query("DELETE FROM user_error_word WHERE user_id={$uid} AND word_item_id={$word_item_id}");
    wm_ok(array('removed' => 1));
}

function api_user_record_list() {
    $user = wm_auth_user(true);
    $uid = intval($user['id']);
    $page = intval(wm_input('page', 1));
    $pageSize = intval(wm_input('pageSize', 10));
    if ($page < 1) {
        $page = 1;
    }
    if ($pageSize < 1) {
        $pageSize = 10;
    }
    if ($pageSize > 50) {
        $pageSize = 50;
    }
    $offset = ($page - 1) * $pageSize;

    $totalRow = wm_fetch_one("SELECT COUNT(*) AS c FROM game_record WHERE user_id={$uid}");
    $total = $totalRow ? intval($totalRow['c']) : 0;

    $list = wm_fetch_all("SELECT r.id, r.lib_id, l.lib_name, r.difficult, r.score, r.right_count, r.total_count, r.create_time
        FROM game_record r
        LEFT JOIN word_lib l ON l.id = r.lib_id
        WHERE r.user_id={$uid}
        ORDER BY r.id DESC
        LIMIT {$offset}, {$pageSize}");

    // 战绩统计
    $stat = wm_fetch_one("SELECT MAX(score) AS max_score, COUNT(*) AS total_rounds, SUM(right_count) AS total_right FROM game_record WHERE user_id={$uid}");
    $max_score = $stat && $stat['max_score'] !== null ? intval($stat['max_score']) : 0;
    $total_rounds = $stat ? intval($stat['total_rounds']) : 0;
    $total_right = $stat && $stat['total_right'] !== null ? intval($stat['total_right']) : 0;

    // 连续游玩天数（从今天往前数）
    $days = wm_fetch_all("SELECT DISTINCT DATE(create_time) AS d FROM game_record WHERE user_id={$uid} ORDER BY d DESC LIMIT 60");
    $streak = 0;
    $t = time();
    $map = array();
    $i = 0;
    for ($i = 0; $i < count($days); $i++) {
        $map[$days[$i]['d']] = 1;
    }
    for ($i = 0; $i < 60; $i++) {
        $d = date('Y-m-d', $t - $i * 86400);
        if (isset($map[$d])) {
            $streak++;
        } else {
            if ($i === 0) {
                // 今天还没玩，从昨天开始算
                continue;
            }
            break;
        }
    }

    wm_ok(array(
        'list' => $list,
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => $total,
        'stat' => array(
            'max_score' => $max_score,
            'total_rounds' => $total_rounds,
            'total_right' => $total_right,
            'streak_days' => $streak
        )
    ));
}

function api_user_badge() {
    $user = wm_auth_user(true);
    $uid = intval($user['id']);
    $all = array(
        array('badge_key' => 'first_game', 'badge_name' => '初次捕鼠', 'desc' => '完成任意一局游戏'),
        array('badge_key' => 'novice', 'badge_name' => '初级捕鼠手', 'desc' => '单局答对≥6题'),
        array('badge_key' => 'hunter', 'badge_name' => '单词猎手', 'desc' => '单局答对≥9题'),
        array('badge_key' => 'persist5', 'badge_name' => '坚持达人', 'desc' => '连续5天至少完成一局')
    );
    $owned = wm_fetch_all("SELECT badge_key, unlock_time FROM user_badge WHERE user_id={$uid}");
    $map = array();
    $i = 0;
    for ($i = 0; $i < count($owned); $i++) {
        $map[$owned[$i]['badge_key']] = $owned[$i]['unlock_time'];
    }
    $list = array();
    for ($i = 0; $i < count($all); $i++) {
        $item = $all[$i];
        $item['unlocked'] = isset($map[$item['badge_key']]) ? 1 : 0;
        $item['unlock_time'] = isset($map[$item['badge_key']]) ? $map[$item['badge_key']] : '';
        $list[] = $item;
    }
    wm_ok(array('list' => $list));
}

function api_user_wx_login() {
    global $WM_CONFIG;
    $code = trim(strval(wm_input('code', '')));
    if ($code === '') {
        wm_err('缺少 code', 1);
    }

    $guest_token = wm_header_token();
    $guest_user = null;
    if ($guest_token !== '') {
        $aid = wm_escape($guest_token);
        $guest_user = wm_fetch_one("SELECT * FROM `user` WHERE anonymous_id='{$aid}' LIMIT 1");
    }

    $appid = $WM_CONFIG['wx_appid'];
    $secret = $WM_CONFIG['wx_secret'];
    $openid = '';

    if ($appid === '' || $secret === '') {
        // 本地/未配置时：用 code 模拟 openid，便于联调（上线前务必配置真实 AppID）
        $openid = 'dev_' . md5($code);
    } else {
        $url = 'https://api.weixin.qq.com/sns/jscode2session?appid=' . urlencode($appid)
            . '&secret=' . urlencode($secret)
            . '&js_code=' . urlencode($code)
            . '&grant_type=authorization_code';
        $raw = wm_http_get($url);
        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['openid']) || !$json['openid']) {
            $errmsg = is_array($json) && isset($json['errmsg']) ? $json['errmsg'] : '微信登录失败';
            wm_err($errmsg, 1);
        }
        $openid = $json['openid'];
    }

    $oid = wm_escape($openid);
    $user = wm_fetch_one("SELECT * FROM `user` WHERE openid='{$oid}' LIMIT 1");
    $now = date('Y-m-d H:i:s');

    if (!$user) {
        // 若当前是游客，把游客账号升级为微信账号（迁移数据无需再搬）
        if ($guest_user && (!$guest_user['openid'] || $guest_user['openid'] === '')) {
            $gid = intval($guest_user['id']);
            wm_query("UPDATE `user` SET openid='{$oid}', anonymous_id=NULL, nickname='微信用户', create_time=IF(create_time IS NULL,'{$now}',create_time) WHERE id={$gid}");
            $user = wm_fetch_one("SELECT * FROM `user` WHERE id={$gid} LIMIT 1");
        } else {
            wm_query("INSERT INTO `user` (openid, anonymous_id, nickname, avatar, create_time) VALUES ('{$oid}', NULL, '微信用户', '', '{$now}')");
            $newId = wm_insert_id();
            // 若游客是另一个账号，迁移数据
            if ($guest_user && intval($guest_user['id']) !== $newId) {
                wm_migrate_guest_to_user(intval($guest_user['id']), $newId);
            }
            $user = wm_fetch_one("SELECT * FROM `user` WHERE id={$newId} LIMIT 1");
        }
    } else {
        // 已有 openid 账号，把游客数据迁过去
        if ($guest_user && intval($guest_user['id']) !== intval($user['id']) && (!$guest_user['openid'] || $guest_user['openid'] === '')) {
            wm_migrate_guest_to_user(intval($guest_user['id']), intval($user['id']));
        }
    }

    wm_ok(wm_user_public($user));
}

function wm_migrate_guest_to_user($from_uid, $to_uid) {
    $from_uid = intval($from_uid);
    $to_uid = intval($to_uid);
    if ($from_uid <= 0 || $to_uid <= 0 || $from_uid === $to_uid) {
        return;
    }
    // 对局
    wm_query("UPDATE game_record SET user_id={$to_uid} WHERE user_id={$from_uid}");
    // 生词（去重：先插忽略冲突，再删游客）
    $words = wm_fetch_all("SELECT word_item_id, add_time FROM user_error_word WHERE user_id={$from_uid}");
    $i = 0;
    for ($i = 0; $i < count($words); $i++) {
        $wid = intval($words[$i]['word_item_id']);
        $at = wm_escape($words[$i]['add_time'] ? $words[$i]['add_time'] : date('Y-m-d H:i:s'));
        $exist = wm_fetch_one("SELECT id FROM user_error_word WHERE user_id={$to_uid} AND word_item_id={$wid} LIMIT 1");
        if (!$exist) {
            wm_query("INSERT INTO user_error_word (user_id, word_item_id, add_time) VALUES ({$to_uid}, {$wid}, '{$at}')");
        }
    }
    wm_query("DELETE FROM user_error_word WHERE user_id={$from_uid}");
    // 徽章
    $badges = wm_fetch_all("SELECT badge_key, badge_name, unlock_time FROM user_badge WHERE user_id={$from_uid}");
    for ($i = 0; $i < count($badges); $i++) {
        $bk = wm_escape($badges[$i]['badge_key']);
        $bn = wm_escape($badges[$i]['badge_name']);
        $ut = wm_escape($badges[$i]['unlock_time'] ? $badges[$i]['unlock_time'] : date('Y-m-d H:i:s'));
        $exist = wm_fetch_one("SELECT id FROM user_badge WHERE user_id={$to_uid} AND badge_key='{$bk}' LIMIT 1");
        if (!$exist) {
            wm_query("INSERT INTO user_badge (user_id, badge_key, badge_name, unlock_time) VALUES ({$to_uid}, '{$bk}', '{$bn}', '{$ut}')");
        }
    }
    wm_query("DELETE FROM user_badge WHERE user_id={$from_uid}");
    // 清空游客 anonymous，避免重复
    wm_query("UPDATE `user` SET anonymous_id=NULL WHERE id={$from_uid}");
}
