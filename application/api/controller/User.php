<?php
namespace app\api\controller;

use think\Db;

class User extends Base
{
    public function index()
    {
        $keyword = trim(input('keyword', ''));
        $query = Db::name('user_db')
            ->field('id,username,nickname,status,create_time')
            ->order('id', 'desc');

        if ($keyword !== '') {
            $query->where('username|nickname', 'like', '%' . $keyword . '%');
        }

        $list = $query->select();
        $this->jsonOut(1, 'ok', ['list' => $list]);
    }
}
