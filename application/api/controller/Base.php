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
            if ($token === '') {
                $this->jsonOut(401, '请先登录');
            }
            $user = $this->userByToken($token);
            if (!$user) {
                $this->jsonOut(401, '登录已失效，请重新登录');
            }
            if ((int)$user['status'] !== 1) {
                $this->jsonOut(401, '账号已被禁用');
            }
            $this->user = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'nickname' => $user['nickname'],
            ];
        }
    }

    protected function allowCors()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, token, Authorization, X-Requested-With');
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

    protected function makeToken($user)
    {
        return md5($user['id'] . '|' . $user['username'] . '|' . $user['password'] . '|zeabur_pro');
    }

    protected function userByToken($token)
    {
        $list = Db::name('user_db')->where('status', 1)->select();
        foreach ($list as $user) {
            if ($this->makeToken($user) === $token) {
                return $user;
            }
        }
        return null;
    }

    protected function jsonOut($code, $msg, $data = null)
    {
        $payload = ['code' => $code, 'msg' => $msg];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        exit(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
