<?php

function api_game_start() {
    wm_auth_user(true);
    $lib_id = intval(wm_input('lib_id', 0));
    $difficult = trim(strval(wm_input('difficult', '普通')));
    if ($lib_id <= 0) {
        wm_err('请选择词库', 1);
    }
    if ($difficult !== '简单' && $difficult !== '普通' && $difficult !== '困难') {
        $difficult = '普通';
    }

    $lib = wm_fetch_one("SELECT id FROM word_lib WHERE id={$lib_id} AND status=1 LIMIT 1");
    if (!$lib) {
        wm_err('词库不存在或已关闭', 1);
    }

    $rows = wm_fetch_all("SELECT id, word, phonetic, meaning, distract FROM word_item WHERE lib_id={$lib_id}");
    if (count($rows) < 10) {
        wm_err('词库单词不足10个', 1);
    }

    shuffle($rows);
    $pick = array_slice($rows, 0, 10);
    $list = array();
    $i = 0;
    for ($i = 0; $i < count($pick); $i++) {
        $d = $pick[$i]['distract'] ? $pick[$i]['distract'] : '';
        $arr = array();
        if ($d !== '') {
            $tmp = explode(',', $d);
            $j = 0;
            for ($j = 0; $j < count($tmp); $j++) {
                $t = trim($tmp[$j]);
                if ($t !== '') {
                    $arr[] = $t;
                }
            }
        }
        // 保证至少 3 个干扰项
        while (count($arr) < 3) {
            $arr[] = '选项' . (count($arr) + 1);
        }
        if (count($arr) > 3) {
            $arr = array_slice($arr, 0, 3);
        }
        $list[] = array(
            'id' => intval($pick[$i]['id']),
            'word' => $pick[$i]['word'],
            'phonetic' => $pick[$i]['phonetic'] ? $pick[$i]['phonetic'] : '',
            'meaning' => $pick[$i]['meaning'],
            'distract' => $arr
        );
    }

    wm_ok(array(
        'lib_id' => $lib_id,
        'difficult' => $difficult,
        'words' => $list
    ));
}

function api_game_save_round() {
    $user = wm_auth_user(true);
    $uid = intval($user['id']);

    $lib_id = intval(wm_input('lib_id', 0));
    $difficult = trim(strval(wm_input('difficult', '普通')));
    $score = intval(wm_input('score', 0));
    $right_count = intval(wm_input('right_count', 0));
    $total_count = intval(wm_input('total_count', 10));
    $error_word_ids = wm_input('error_word_ids', array());

    if ($lib_id <= 0) {
        wm_err('参数错误', 1);
    }
    if ($difficult !== '简单' && $difficult !== '普通' && $difficult !== '困难') {
        $difficult = '普通';
    }
    if ($total_count <= 0) {
        $total_count = 10;
    }
    if (!is_array($error_word_ids)) {
        // 兼容逗号字符串
        if (is_string($error_word_ids) && $error_word_ids !== '') {
            $tmp = explode(',', $error_word_ids);
            $error_word_ids = array();
            $k = 0;
            for ($k = 0; $k < count($tmp); $k++) {
                $error_word_ids[] = intval($tmp[$k]);
            }
        } else {
            $error_word_ids = array();
        }
    }

    $now = date('Y-m-d H:i:s');
    $diff = wm_escape($difficult);
    wm_query("INSERT INTO game_record (user_id, lib_id, difficult, score, right_count, total_count, create_time)
              VALUES ({$uid}, {$lib_id}, '{$diff}', {$score}, {$right_count}, {$total_count}, '{$now}')");
    $record_id = wm_insert_id();

    // 生词去重入库
    $i = 0;
    for ($i = 0; $i < count($error_word_ids); $i++) {
        $wid = intval($error_word_ids[$i]);
        if ($wid <= 0) {
            continue;
        }
        $exist = wm_fetch_one("SELECT id FROM user_error_word WHERE user_id={$uid} AND word_item_id={$wid} LIMIT 1");
        if (!$exist) {
            wm_query("INSERT INTO user_error_word (user_id, word_item_id, add_time) VALUES ({$uid}, {$wid}, '{$now}')");
        }
    }

    wm_check_badges($uid, $right_count);

    $badges = wm_fetch_all("SELECT badge_key, badge_name, unlock_time FROM user_badge WHERE user_id={$uid} ORDER BY unlock_time DESC");

    wm_ok(array(
        'record_id' => $record_id,
        'badges' => $badges
    ));
}
