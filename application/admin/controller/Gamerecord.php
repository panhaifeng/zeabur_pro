<?php
namespace app\admin\controller;

use think\Db;

class Gamerecord extends Base
{
    public function index()
    {
        $libId = (int)input('get.lib_id', 0);
        $keyword = trim(input('get.keyword', ''));
        $difficult = trim(input('get.difficult', ''));
        $libs = Db::name('word_lib')->order('id', 'asc')->select();

        $query = Db::name('game_record')->alias('r')
            ->join('user u', 'r.user_id = u.id', 'LEFT')
            ->join('word_lib l', 'r.lib_id = l.id', 'LEFT')
            ->field('r.*, u.nickname, u.openid, u.anonymous_id, l.lib_name')
            ->order('r.id', 'desc');

        if ($libId > 0) {
            $query->where('r.lib_id', $libId);
        }
        if ($keyword !== '') {
            $query->where('u.nickname', 'like', '%' . $keyword . '%');
        }
        if ($difficult !== '') {
            $query->where('r.difficult', $difficult);
        }

        $list = $query->paginate(20, false, ['query' => request()->get()]);

        $this->assign([
            'list'      => $list,
            'libs'      => $libs,
            'lib_id'    => $libId,
            'keyword'   => $keyword,
            'difficult' => $difficult,
            'menu'      => 'gamerecord',
        ]);
        return $this->fetch();
    }
}
