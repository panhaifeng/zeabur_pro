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
     * 优先落库 avatar_bin（不依赖容器磁盘持久化），并返回可展示的绝对地址
     */
    public function uploadAvatar()
    {
        $this->requireLogin();
        if (empty($this->user['openid'])) {
            $this->jsonOut(1, '请先微信登录');
        }

        $parsed = $this->readUploadAvatarBinary();
        if (is_string($parsed)) {
            $this->jsonOut(1, $parsed);
        }
        $binary = $parsed['binary'];
        $ext = $parsed['ext'];
        $mime = $parsed['mime'];

        $this->ensureAvatarBinColumn();

        $uid = intval($this->user['id']);
        $url = '/api/user/avatar-img?id=' . $uid . '&v=' . time();

        // 优先写入数据库，避免 Zeabur 容器磁盘不可写/重启丢失
        $dbOk = false;
        try {
            Db::execute(
                'UPDATE `user` SET `avatar` = ?, `avatar_bin` = ? WHERE `id` = ?',
                array($url, $binary, $uid)
            );
            $dbOk = true;
        } catch (\Exception $e) {
            $dbOk = false;
        }

        // 磁盘备份（失败不影响主流程）
        $diskUrl = $this->saveAvatarBinary($binary, $ext);
        if (!$dbOk) {
            if ($diskUrl === '') {
                $this->jsonOut(1, '头像保存失败：数据库与磁盘均不可写');
            }
            Db::name('user')->where('id', $uid)->update(array('avatar' => $diskUrl));
            $url = $diskUrl;
        }

        $user = Db::name('user')->where('id', $uid)->find();
        $this->jsonOut(0, 'success', array(
            'avatar' => $this->formatAvatarUrl($url),
            'mime'   => $mime,
            'user'   => $this->formatUser($user),
        ));
    }

    /**
     * 输出头像图片（公开）
     * GET /api/user/avatar-img?id=1
     */
    public function avatarImg()
    {
        $id = intval(input('id', 0));
        if ($id <= 0) {
            http_response_code(404);
            exit('not found');
        }

        $this->ensureAvatarBinColumn();
        $row = null;
        try {
            $row = Db::query('SELECT `avatar`, `avatar_bin` FROM `user` WHERE `id` = ? LIMIT 1', array($id));
            $row = $row ? $row[0] : null;
        } catch (\Exception $e) {
            $row = Db::name('user')->where('id', $id)->field('avatar')->find();
        }

        if (!$row) {
            http_response_code(404);
            exit('not found');
        }

        $bin = '';
        if (!empty($row['avatar_bin'])) {
            $bin = $row['avatar_bin'];
        } elseif (!empty($row['avatar']) && strpos($row['avatar'], '/uploads/') === 0) {
            $path = ROOT_PATH . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $row['avatar']);
            if (is_file($path)) {
                $bin = @file_get_contents($path);
            }
        }

        if ($bin === '' || $bin === false) {
            http_response_code(404);
            exit('not found');
        }

        $mime = 'image/jpeg';
        if (strncmp($bin, "\x89PNG", 4) === 0) {
            $mime = 'image/png';
        } elseif (strncmp($bin, 'GIF8', 4) === 0) {
            $mime = 'image/gif';
        } elseif (strncmp($bin, 'RIFF', 4) === 0) {
            $mime = 'image/webp';
        }

        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }

    /**
     * 读取上传头像二进制：优先 $_FILES（兼容微信 uploadFile）
     * @return array|string  array{binary,ext,mime} 或错误文案
     */
    protected function readUploadAvatarBinary()
    {
        $binary = '';
        $ext = 'jpg';
        $mime = 'image/jpeg';

        if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
            $f = $_FILES['file'];
            if (!empty($f['error']) && intval($f['error']) !== UPLOAD_ERR_OK) {
                return '头像上传失败，错误码 ' . intval($f['error']);
            }
            $tmp = isset($f['tmp_name']) ? $f['tmp_name'] : '';
            if ($tmp === '' || !is_file($tmp)) {
                return '头像临时文件不存在';
            }
            $size = isset($f['size']) ? intval($f['size']) : filesize($tmp);
            if ($size <= 0) {
                return '头像文件无效';
            }
            if ($size > 2 * 1024 * 1024) {
                return '头像不能超过2MB';
            }
            $binary = @file_get_contents($tmp);
            if ($binary === false || strlen($binary) < 32) {
                return '读取头像失败';
            }
            if (!empty($f['type'])) {
                $mime = strtolower((string)$f['type']);
            }
            $name = isset($f['name']) ? strtolower((string)$f['name']) : '';
            if (preg_match('/\.(jpe?g|png|gif|webp)$/', $name, $m)) {
                $ext = ($m[1] === 'jpeg') ? 'jpg' : $m[1];
            }
        } else {
            $file = request()->file('file');
            if (!$file) {
                return '请选择头像文件';
            }
            $size = intval($file->getSize());
            if ($size <= 0) {
                return '头像文件无效';
            }
            if ($size > 2 * 1024 * 1024) {
                return '头像不能超过2MB';
            }
            $mime = strtolower((string)$file->getMime());
            $tmp = $file->getRealPath();
            if (!$tmp) {
                $tmp = $file->getPathname();
            }
            if (!$tmp || !is_file($tmp)) {
                return '头像临时文件不存在';
            }
            $binary = @file_get_contents($tmp);
            if ($binary === false || strlen($binary) < 32) {
                return '读取头像失败';
            }
        }

        $extMap = array(
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        );
        if (isset($extMap[$mime])) {
            $ext = $extMap[$mime];
        }
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
        }

        return array(
            'binary' => $binary,
            'ext'    => $ext,
            'mime'   => $mime,
        );
    }

    protected function ensureAvatarBinColumn()
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            Db::query('SELECT `avatar_bin` FROM `user` LIMIT 1');
        } catch (\Exception $e) {
            try {
                Db::execute('ALTER TABLE `user` ADD COLUMN `avatar_bin` MEDIUMBLOB NULL COMMENT \'头像二进制\' AFTER `avatar`');
            } catch (\Exception $e2) {
                // ignore
            }
        }
    }

    /**
     * 将头像二进制写入 public/uploads/avatar（备份）
     */
    protected function saveAvatarBinary($binary, $ext = 'jpg')
    {
        $ext = strtolower((string)$ext);
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
            $ext = 'jpg';
        }
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $baseDir = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatar';
        $subDir = date('Ymd');
        $savePath = $baseDir . DIRECTORY_SEPARATOR . $subDir;
        if (!is_dir($savePath) && !@mkdir($savePath, 0777, true)) {
            return '';
        }

        $filename = 'av_' . date('His') . '_' . uniqid() . '.' . $ext;
        $dest = $savePath . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($dest, $binary) === false) {
            return '';
        }
        @chmod($dest, 0644);

        return '/uploads/avatar/' . $subDir . '/' . $filename;
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
