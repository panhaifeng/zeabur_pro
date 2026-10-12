<?php
namespace app\api\controller;

use think\Db;

class Login extends Base
{
    protected $needLogin = false;

    public function doLogin()
    {
        $username = trim(input('username', ''));
        $password = trim(input('password', ''));

        if ($username === '' || $password === '') {
            $this->jsonOut(0, '请输入账号和密码');
        }

        $user = Db::name('user_db')->where('username', $username)->find();
        if (!$user || $user['password'] !== md5($password)) {
            $this->jsonOut(0, '账号或密码错误');
        }
        if ((int)$user['status'] !== 1) {
            $this->jsonOut(0, '账号已被禁用');
        }

        $token = $this->makeToken($user);
        $this->jsonOut(1, '登录成功', [
            'token' => $token,
            'user'  => [
                'id'       => $user['id'],
                'username' => $user['username'],
                'nickname' => $user['nickname'],
            ],
        ]);
    }

    public function logout()
    {
        $this->jsonOut(1, '已退出');
    }
}
