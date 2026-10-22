<?php
namespace app\admin\controller;

use think\Controller;
use think\Db;

class Login extends Controller
{
    public function index()
    {
        if (session('admin_user')) {
            $this->redirect('admin/index/index');
        }
        return $this->fetch();
    }

    public function doLogin()
    {
        $username = trim(input('post.username', ''));
        $password = trim(input('post.password', ''));

        if ($username === '' || $password === '') {
            return json(['code' => 0, 'msg' => '请输入账号和密码']);
        }

        $user = Db::name('user_db')->where('username', $username)->find();
        if (!$user || $user['password'] !== md5($password)) {
            return json(['code' => 0, 'msg' => '账号或密码错误']);
        }
        if ((int)$user['status'] !== 1) {
            return json(['code' => 0, 'msg' => '账号已被禁用']);
        }

        session('admin_user', [
            'id'       => $user['id'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
        ]);

        return json(['code' => 1, 'msg' => '登录成功', 'url' => url('admin/index/index')]);
    }

    public function logout()
    {
        session('admin_user', null);
        $this->redirect('admin/login/index');
    }
}
