<?php
namespace app\api\controller;

use think\Db;

class Game extends Base
{
    /**
     * 开始对局，随机抽取 10 词
     * POST /api/game/start  lib_id, difficult
     */
    public function start()
    {
        $libId = intval(input('lib_id', 0));
        $difficult = trim(input('difficult', '普通'));
        if ($libId <= 0) {
            $this->jsonOut(1, '请选择词库');
        }
        $allow = array('简单', '普通', '困难');
        if (!in_array($difficult, $allow)) {
            $difficult = '普通';
        }

        $lib = Db::name('word_lib')->where('id', $libId)->where('status', 1)->find();
        if (!$lib) {
            $this->jsonOut(1, '词库不存在或已关闭');
        }

        $all = Db::name('word_item')->where('lib_id', $libId)->select();
        if (!$all || count($all) < 1) {
            $this->jsonOut(1, '词库暂无单词');
        }
        shuffle($all);
        $rows = array_slice($all, 0, 10);

        $list = array();
        foreach ($rows as $row) {
            $distract = array();
            if (!empty($row['distract'])) {
                $parts = explode(',', $row['distract']);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p !== '') {
                        $distract[] = $p;
                    }
                }
            }
            $list[] = array(
                'id'       => intval($row['id']),
                'word'     => $row['word'],
                'phonetic' => $row['phonetic'],
                'meaning'  => $row['meaning'],
                'distract' => $distract,
            );
        }

        $holeMap = array('简单' => 3, '普通' => 4, '困难' => 5);
        $stayMap = array('简单' => 2500, '普通' => 1800, '困难' => 1200);

        $this->jsonOut(0, 'success', array(
            'lib_id'     => $libId,
            'lib_name'   => $lib['lib_name'],
            'difficult'  => $difficult,
            'hole_count' => $holeMap[$difficult],
            'stay_ms'    => $stayMap[$difficult],
            'words'      => $list,
        ));
    }

    /**
     * 保存对局
     * POST /api/game/save-round
     */
    public function saveRound()
    {
        // 兼容 form / json
        $payload = input('post.');
        if (!$payload || !isset($payload['lib_id'])) {
            $raw = file_get_contents('php://input');
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $payload = $json;
            }
        }
        $libId = intval(isset($payload['lib_id']) ? $payload['lib_id'] : 0);
        $difficult = trim(isset($payload['difficult']) ? $payload['difficult'] : '普通');
        $score = intval(isset($payload['score']) ? $payload['score'] : 0);
        $rightCount = intval(isset($payload['right_count']) ? $payload['right_count'] : 0);
        $totalCount = intval(isset($payload['total_count']) ? $payload['total_count'] : 10);
        $errorWordIds = isset($payload['error_word_ids']) ? $payload['error_word_ids'] : array();
        if (!is_array($errorWordIds)) {
            $errorWordIds = array();
        }

        if ($libId <= 0) {
            $this->jsonOut(1, '参数错误');
        }
        if ($totalCount <= 0) {
            $totalCount = 10;
        }
        if ($rightCount < 0) {
            $rightCount = 0;
        }
        if ($rightCount > $totalCount) {
            $rightCount = $totalCount;
        }

        $userId = intval($this->user['id']);

        Db::name('game_record')->insert(array(
            'user_id'     => $userId,
            'lib_id'      => $libId,
            'difficult'   => $difficult,
            'score'       => $score,
            'right_count' => $rightCount,
            'total_count' => $totalCount,
            'create_time' => date('Y-m-d H:i:s'),
        ));

        // 生词入库（去重）
        if (is_array($errorWordIds)) {
            foreach ($errorWordIds as $wid) {
                $wid = intval($wid);
                if ($wid <= 0) {
                    continue;
                }
                $exists = Db::name('user_error_word')
                    ->where('user_id', $userId)
                    ->where('word_item_id', $wid)
                    ->find();
                if (!$exists) {
                    Db::name('user_error_word')->insert(array(
                        'user_id'      => $userId,
                        'word_item_id' => $wid,
                        'add_time'     => date('Y-m-d H:i:s'),
                    ));
                }
            }
        }

        $newBadges = $this->checkAndUnlockBadges($userId, $rightCount);

        $this->jsonOut(0, 'success', array(
            'new_badges' => $newBadges,
        ));
    }

    protected function checkAndUnlockBadges($userId, $rightCount)
    {
        $unlocked = array();

        // first_game
        if ($this->unlockBadge($userId, 'first_game', '初次捕鼠')) {
            $unlocked[] = array('badge_key' => 'first_game', 'badge_name' => '初次捕鼠');
        }
        // novice
        if ($rightCount >= 6) {
            if ($this->unlockBadge($userId, 'novice', '初级捕鼠手')) {
                $unlocked[] = array('badge_key' => 'novice', 'badge_name' => '初级捕鼠手');
            }
        }
        // hunter
        if ($rightCount >= 9) {
            if ($this->unlockBadge($userId, 'hunter', '单词猎手')) {
                $unlocked[] = array('badge_key' => 'hunter', 'badge_name' => '单词猎手');
            }
        }
        // persist5
        if ($this->hasPersist5($userId)) {
            if ($this->unlockBadge($userId, 'persist5', '坚持达人')) {
                $unlocked[] = array('badge_key' => 'persist5', 'badge_name' => '坚持达人');
            }
        }

        return $unlocked;
    }

    protected function unlockBadge($userId, $key, $name)
    {
        $exists = Db::name('user_badge')
            ->where('user_id', $userId)
            ->where('badge_key', $key)
            ->find();
        if ($exists) {
            return false;
        }
        Db::name('user_badge')->insert(array(
            'user_id'     => $userId,
            'badge_key'   => $key,
            'badge_name'  => $name,
            'unlock_time' => date('Y-m-d H:i:s'),
        ));
        return true;
    }

    /**
     * 连续 5 个自然日至少各完成一局
     */
    protected function hasPersist5($userId)
    {
        $days = Db::name('game_record')
            ->where('user_id', $userId)
            ->field("DATE(create_time) as d")
            ->group('d')
            ->order('d', 'desc')
            ->limit(10)
            ->select();
        if (!$days || count($days) < 5) {
            return false;
        }
        $expect = date('Y-m-d');
        // 今天刚玩完，从今天往前数
        $hit = 0;
        $dateMap = array();
        foreach ($days as $row) {
            $dateMap[$row['d']] = 1;
        }
        for ($i = 0; $i < 5; $i++) {
            $d = date('Y-m-d', strtotime($expect . ' -' . $i . ' day'));
            if (!isset($dateMap[$d])) {
                return false;
            }
            $hit++;
        }
        return $hit >= 5;
    }
}
