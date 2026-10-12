<?php
namespace app\admin\controller;

use think\Db;

class User extends Base
{
    public function index()
    {
        $keyword = trim(input('get.keyword', ''));
        $query = Db::name('user_db')->order('id', 'desc');
        if ($keyword !== '') {
            $query->where('username|nickname', 'like', '%' . $keyword . '%');
        }
        $list = $query->paginate(10, false, ['query' => request()->get()]);
        $this->assign([
            'list'    => $list,
            'keyword' => $keyword,
            'menu'    => 'user',
        ]);
        return $this->fetch();
    }

    public function add()
    {
        if (request()->isPost()) {
            $data = $this->getFormData();
            if (is_string($data)) {
                return json(['code' => 0, 'msg' => $data]);
            }
            $exists = Db::name('user_db')->where('username', $data['username'])->find();
            if ($exists) {
                return json(['code' => 0, 'msg' => '用户名已存在']);
            }
            $data['create_time'] = date('Y-m-d H:i:s');
            Db::name('user_db')->insert($data);
            return json(['code' => 1, 'msg' => '添加成功', 'url' => url('admin/user/index')]);
        }
        $this->assign('menu', 'user');
        return $this->fetch();
    }

    public function edit()
    {
        $id = (int)input('id', 0);
        $user = Db::name('user_db')->where('id', $id)->find();
        if (!$user) {
            $this->error('用户不存在');
        }

        if (request()->isPost()) {
            $data = $this->getFormData(false);
            if (is_string($data)) {
                return json(['code' => 0, 'msg' => $data]);
            }
            $exists = Db::name('user_db')
                ->where('username', $data['username'])
                ->where('id', '<>', $id)
                ->find();
            if ($exists) {
                return json(['code' => 0, 'msg' => '用户名已存在']);
            }
            if ($data['password'] === '') {
                unset($data['password']);
            }
            Db::name('user_db')->where('id', $id)->update($data);
            return json(['code' => 1, 'msg' => '保存成功', 'url' => url('admin/user/index')]);
        }

        $this->assign([
            'info' => $user,
            'menu' => 'user',
        ]);
        return $this->fetch();
    }

    public function delete()
    {
        $id = (int)input('id', 0);
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => '参数错误']);
        }
        if ($id === (int)session('admin_user.id')) {
            return json(['code' => 0, 'msg' => '不能删除当前登录用户']);
        }
        Db::name('user_db')->where('id', $id)->delete();
        return json(['code' => 1, 'msg' => '删除成功']);
    }

    private function getFormData($requirePassword = true)
    {
        $username = trim(input('post.username', ''));
        $password = trim(input('post.password', ''));
        $nickname = trim(input('post.nickname', ''));
        $status = (int)input('post.status', 1);

        if ($username === '') {
            return '请输入用户名';
        }
        if ($requirePassword && $password === '') {
            return '请输入密码';
        }
        if ($password !== '' && strlen($password) < 6) {
            return '密码至少6位';
        }

        $data = [
            'username' => $username,
            'nickname' => $nickname,
            'status'   => $status === 1 ? 1 : 0,
            'password' => $password === '' ? '' : md5($password),
        ];
        return $data;
    }
}
