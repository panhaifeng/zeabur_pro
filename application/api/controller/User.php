<?php
namespace app\api\controller;

use think\Db;

class User extends Base
{
    protected $needLogin = false;

    /**
     * 获取用户信息（无 token 时可用 anonymous_id 自动建游客）
     * GET /api/user/get-info
     */
    public function getInfo()
    {
        $token = $this->getToken();
        $user = null;
        if ($token !== '') {
            $user = $this->userByToken($token);
        }
        if (!$user) {
            $aid = $this->getAnonymousId();
            if ($aid === '') {
                $this->jsonOut(1, '缺少 anonymous_id');
            }
            $user = $this->ensureGuestByAnonymous($aid);
            if (!$user) {
                $this->jsonOut(1, '游客初始化失败');
            }
        }

        $badges = Db::name('user_badge')
            ->where('user_id', $user['id'])
            ->order('unlock_time', 'desc')
            ->select();

        $stats = $this->buildStats($user['id']);

        $this->jsonOut(0, 'success', array(
            'user'   => $this->formatUser($user),
            'badges' => $badges ? $badges : array(),
            'stats'  => $stats,
        ));
    }

    /**
     * 微信登录
     * POST /api/user/wx-login  code
     */
    public function wxLogin()
    {
        $code = trim(input('code', ''));
        if ($code === '') {
            $this->jsonOut(1, '缺少 code');
        }

        $appid = config('wechat.appid');
        $secret = config('wechat.appsecret');
        if (!$appid || !$secret) {
            $this->jsonOut(1, '请先配置小程序 AppID / AppSecret');
        }

        $url = 'https://api.weixin.qq.com/sns/jscode2session?appid=' . urlencode($appid)
            . '&secret=' . urlencode($secret)
            . '&js_code=' . urlencode($code)
            . '&grant_type=authorization_code';
        $resp = $this->httpGet($url);
        $json = json_decode($resp, true);
        if (!$json || empty($json['openid'])) {
            $errmsg = isset($json['errmsg']) ? $json['errmsg'] : '微信登录失败';
            $this->jsonOut(1, $errmsg);
        }
        $openid = $json['openid'];

        $wxUser = Db::name('user')->where('openid', $openid)->find();
        if (!$wxUser) {
            $id = Db::name('user')->insertGetId(array(
                'openid'       => $openid,
                'anonymous_id' => null,
                'nickname'     => '微信用户',
                'avatar'       => '',
                'create_time'  => date('Y-m-d H:i:s'),
            ));
            $wxUser = Db::name('user')->where('id', $id)->find();
        }

        // 游客数据迁移
        $guest = null;
        $token = $this->getToken();
        if ($token !== '') {
            $guest = $this->userByToken($token);
        }
        if (!$guest) {
            $aid = $this->getAnonymousId();
            if ($aid !== '') {
                $guest = Db::name('user')->where('anonymous_id', $aid)->find();
            }
        }

        if ($guest && intval($guest['id']) !== intval($wxUser['id']) && empty($guest['openid'])) {
            $this->migrateGuestToWx($guest['id'], $wxUser['id']);
            Db::name('user')->where('id', $guest['id'])->delete();
        }

        // 登录成功后 openid 用户 anonymous_id 置空
        Db::name('user')->where('id', $wxUser['id'])->update(array('anonymous_id' => null));
        $wxUser = Db::name('user')->where('id', $wxUser['id'])->find();

        $this->jsonOut(0, 'success', array(
            'user' => $this->formatUser($wxUser),
        ));
    }

    /**
     * 更新昵称/头像（个人小程序需用户主动填写，无法静默拉取）
     * POST /api/user/update-profile  nickname, avatar
     */
    public function updateProfile()
    {
        $this->requireLogin();
        if (empty($this->user['openid'])) {
            $this->jsonOut(1, '请先微信登录');
        }

        $nickname = trim(input('nickname', ''));
        $avatar = trim(input('avatar', ''));

        $update = array();
        if ($nickname !== '') {
            // 微信昵称长度限制
            if (mb_strlen($nickname, 'UTF-8') > 32) {
                $nickname = mb_substr($nickname, 0, 32, 'UTF-8');
            }
            $update['nickname'] = $nickname;
        }
        if ($avatar !== '') {
            $update['avatar'] = $avatar;
        }
        if (!$update) {
            $this->jsonOut(1, '没有可更新的内容');
        }

        Db::name('user')->where('id', $this->user['id'])->update($update);
        $user = Db::name('user')->where('id', $this->user['id'])->find();
        $this->jsonOut(0, 'success', array(
            'user' => $this->formatUser($user),
        ));
    }

    /**
     * 上传头像
     * POST /api/user/upload-avatar  file
     * 说明：微信 chooseAvatar 临时文件常无扩展名，不能只靠 ext 校验
     */
    public function uploadAvatar()
    {
        $this->requireLogin();
        if (empty($this->user['openid'])) {
            $this->jsonOut(1, '请先微信登录');
        }

        $file = request()->file('file');
        if (!$file) {
            $this->jsonOut(1, '请选择头像文件');
        }

        $size = intval($file->getSize());
        if ($size <= 0) {
            $this->jsonOut(1, '头像文件无效');
        }
        if ($size > 2 * 1024 * 1024) {
            $this->jsonOut(1, '头像不能超过2MB');
        }

        $mime = strtolower((string)$file->getMime());
        $extMap = array(
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        );
        $ext = isset($extMap[$mime]) ? $extMap[$mime] : '';
        if ($ext === '') {
            $orig = strtolower((string)$file->getInfo('name'));
            if (preg_match('/\.(jpe?g|png|gif|webp)$/', $orig, $m)) {
                $ext = ($m[1] === 'jpeg') ? 'jpg' : $m[1];
            } else {
                // 微信临时头像多为 jpeg，无后缀时按 jpg 保存
                $ext = 'jpg';
            }
        }

        $baseDir = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatar';
        $subDir = date('Ymd');
        $savePath = $baseDir . DIRECTORY_SEPARATOR . $subDir;
        if (!is_dir($savePath) && !@mkdir($savePath, 0777, true)) {
            $this->jsonOut(1, '头像目录创建失败');
        }

        $filename = 'av_' . date('His') . '_' . uniqid() . '.' . $ext;
        $dest = $savePath . DIRECTORY_SEPARATOR . $filename;
        $tmp = $file->getRealPath();
        if (!$tmp) {
            $tmp = $file->getPathname();
        }

        $moved = false;
        try {
            $info = $file->move($savePath, $filename, true);
            if ($info) {
                $moved = true;
                $filename = $info->getFilename();
            }
        } catch (\Exception $e) {
            $moved = false;
        }

        if (!$moved) {
            if (!$tmp || !is_file($tmp) || !@copy($tmp, $dest)) {
                $err = $file->getError();
                $this->jsonOut(1, $err ? $err : '头像上传失败');
            }
        }

        $url = '/uploads/avatar/' . $subDir . '/' . $filename;
        Db::name('user')->where('id', $this->user['id'])->update(array('avatar' => $url));
        $user = Db::name('user')->where('id', $this->user['id'])->find();

        $this->jsonOut(0, 'success', array(
            'avatar' => $url,
            'user'   => $this->formatUser($user),
        ));
    }

    /**
     * 生词本
     * GET /api/user/error-word
     */
    public function errorWord()
    {
        $this->requireLogin();
        $list = Db::name('user_error_word')->alias('e')
            ->join('word_item w', 'e.word_item_id = w.id')
            ->where('e.user_id', $this->user['id'])
            ->field('e.id as error_id,e.word_item_id,e.add_time,w.word,w.phonetic,w.meaning,w.lib_id')
            ->order('e.add_time', 'desc')
            ->select();
        $this->jsonOut(0, 'success', array('list' => $list ? $list : array()));
    }

    /**
     * 移出生词本
     * POST /api/user/remove-word  word_item_id
     */
    public function removeWord()
    {
        $this->requireLogin();
        $wordItemId = intval(input('word_item_id', 0));
        if ($wordItemId <= 0) {
            $this->jsonOut(1, '参数错误');
        }
        Db::name('user_error_word')
            ->where('user_id', $this->user['id'])
            ->where('word_item_id', $wordItemId)
            ->delete();
        $this->jsonOut(0, 'success');
    }

    /**
     * 对局历史
     * GET /api/user/record-list  page pageSize
     */
    public function recordList()
    {
        $this->requireLogin();
        $page = max(1, intval(input('page', 1)));
        $pageSize = max(1, min(50, intval(input('pageSize', 10))));

        $query = Db::name('game_record')->alias('r')
            ->join('word_lib l', 'r.lib_id = l.id', 'LEFT')
            ->where('r.user_id', $this->user['id'])
            ->field('r.*,l.lib_name')
            ->order('r.id', 'desc');

        $total = Db::name('game_record')->where('user_id', $this->user['id'])->count();
        $list = $query->page($page, $pageSize)->select();

        $this->jsonOut(0, 'success', array(
            'list'     => $list ? $list : array(),
            'total'    => intval($total),
            'page'     => $page,
            'pageSize' => $pageSize,
        ));
    }

    /**
     * 徽章列表
     * GET /api/user/badge
     */
    public function badge()
    {
        $this->requireLogin();
        $list = Db::name('user_badge')
            ->where('user_id', $this->user['id'])
            ->order('unlock_time', 'desc')
            ->select();
        $this->jsonOut(0, 'success', array('list' => $list ? $list : array()));
    }

    protected function requireLogin()
    {
        $token = $this->getToken();
        if ($token === '') {
            $this->jsonOut(401, '请先进入小程序');
        }
        $user = $this->userByToken($token);
        if (!$user) {
            $this->jsonOut(401, '登录已失效');
        }
        $this->user = $user;
    }

    protected function buildStats($userId)
    {
        $maxScore = Db::name('game_record')->where('user_id', $userId)->max('score');
        $totalRound = Db::name('game_record')->where('user_id', $userId)->count();
        $totalRight = Db::name('game_record')->where('user_id', $userId)->sum('right_count');
        $days = Db::name('game_record')
            ->where('user_id', $userId)
            ->field("DATE(create_time) as d")
            ->group('d')
            ->order('d', 'desc')
            ->select();
        $streak = $this->calcStreak($days);

        return array(
            'max_score'    => intval($maxScore),
            'total_round'  => intval($totalRound),
            'total_right'  => intval($totalRight),
            'streak_days'  => intval($streak),
        );
    }

    protected function calcStreak($days)
    {
        if (!$days) {
            return 0;
        }
        $dateList = array();
        foreach ($days as $row) {
            $dateList[] = $row['d'];
        }
        $streak = 0;
        $expect = date('Y-m-d');
        // 若今天没玩，从昨天开始算连续
        if (!in_array($expect, $dateList)) {
            $expect = date('Y-m-d', strtotime('-1 day'));
        }
        foreach ($dateList as $d) {
            if ($d === $expect) {
                $streak++;
                $expect = date('Y-m-d', strtotime($expect . ' -1 day'));
            } else {
                break;
            }
        }
        return $streak;
    }

    protected function migrateGuestToWx($guestId, $wxUserId)
    {
        // 对局
        Db::name('game_record')->where('user_id', $guestId)->update(array('user_id' => $wxUserId));

        // 生词去重迁移
        $errors = Db::name('user_error_word')->where('user_id', $guestId)->select();
        if ($errors) {
            foreach ($errors as $row) {
                $exists = Db::name('user_error_word')
                    ->where('user_id', $wxUserId)
                    ->where('word_item_id', $row['word_item_id'])
                    ->find();
                if (!$exists) {
                    Db::name('user_error_word')->where('id', $row['id'])->update(array('user_id' => $wxUserId));
                } else {
                    Db::name('user_error_word')->where('id', $row['id'])->delete();
                }
            }
        }

        // 徽章去重迁移
        $badges = Db::name('user_badge')->where('user_id', $guestId)->select();
        if ($badges) {
            foreach ($badges as $row) {
                $exists = Db::name('user_badge')
                    ->where('user_id', $wxUserId)
                    ->where('badge_key', $row['badge_key'])
                    ->find();
                if (!$exists) {
                    Db::name('user_badge')->where('id', $row['id'])->update(array('user_id' => $wxUserId));
                } else {
                    Db::name('user_badge')->where('id', $row['id'])->delete();
                }
            }
        }
    }

    protected function httpGet($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $result = curl_exec($ch);
            curl_close($ch);
            return $result;
        }
        return file_get_contents($url);
    }
}
