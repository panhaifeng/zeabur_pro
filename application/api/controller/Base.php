<?php
namespace app\api\controller;

use think\Controller;
use think\Db;

class Base extends Controller
{
    protected $user = null;
    protected $needLogin = true;

    protected function initialize()
    {
        $this->allowCors();

        if (request()->isOptions()) {
            exit;
        }

        if ($this->needLogin) {
            $token = $this->getToken();
            $user = null;
            if ($token !== '') {
                $user = $this->userByToken($token);
            }
            // 游客无 token 时，用 anonymous_id 自动建号（免登录直接玩）
            if (!$user) {
                $aid = $this->getAnonymousId();
                if ($aid !== '') {
                    $user = $this->ensureGuestByAnonymous($aid);
                }
            }
            if (!$user) {
                $this->jsonOut(401, '请先进入小程序');
            }
            $this->user = $user;
        }
    }

    protected function allowCors()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, token, Authorization, X-Requested-With, anonymous_id');
    }

    protected function getToken()
    {
        $token = request()->header('token', '');
        if ($token === '') {
            $auth = request()->header('authorization', '');
            if (stripos($auth, 'Bearer ') === 0) {
                $token = substr($auth, 7);
            }
        }
        if ($token === '') {
            $token = input('token', '');
        }
        return trim($token);
    }

    protected function getAnonymousId()
    {
        $aid = request()->header('anonymous_id', '');
        if ($aid === '') {
            $aid = input('anonymous_id', '');
        }
        return trim($aid);
    }

    protected function makeToken($user)
    {
        $openid = isset($user['openid']) ? $user['openid'] : '';
        $aid = isset($user['anonymous_id']) ? $user['anonymous_id'] : '';
        return md5($user['id'] . '|' . $openid . '|' . $aid . '|word_mole');
    }

    protected function userByToken($token)
    {
        $list = Db::name('user')->limit(5000)->select();
        foreach ($list as $user) {
            if ($this->makeToken($user) === $token) {
                return $user;
            }
        }
        return null;
    }

    protected function ensureGuestByAnonymous($anonymousId)
    {
        if ($anonymousId === '') {
            return null;
        }
        $user = Db::name('user')->where('anonymous_id', $anonymousId)->find();
        if ($user) {
            return $user;
        }
        $id = Db::name('user')->insertGetId(array(
            'openid'       => null,
            'anonymous_id' => $anonymousId,
            'nickname'     => '游客',
            'avatar'       => '',
            'create_time'  => date('Y-m-d H:i:s'),
        ));
        return Db::name('user')->where('id', $id)->find();
    }

    protected function formatUser($user)
    {
        return array(
            'id'           => intval($user['id']),
            'openid'       => $user['openid'],
            'anonymous_id' => $user['anonymous_id'],
            'nickname'     => $user['nickname'] ? $user['nickname'] : '游客',
            'avatar'       => $user['avatar'] ? $user['avatar'] : '',
            'is_guest'     => empty($user['openid']) ? 1 : 0,
            'token'        => $this->makeToken($user),
        );
    }

    protected function jsonOut($code, $msg, $data = null)
    {
        $payload = array('code' => $code, 'msg' => $msg);
        if ($data !== null) {
            $payload['data'] = $data;
        }
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
