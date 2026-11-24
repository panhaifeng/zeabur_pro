<?php
namespace app\admin\controller;

use think\Db;

class Suggestion extends Base
{
    public function index()
    {
        $keyword = trim(input('get.keyword', ''));
        $status = input('get.status', '');

        $query = Db::name('suggestion')->alias('s')
            ->join('user u', 's.user_id = u.id', 'LEFT')
            ->field('s.*, u.nickname, u.openid, u.anonymous_id')
            ->order('s.id', 'desc');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('s.content', 'like', '%' . $keyword . '%')
                    ->whereOr('s.contact', 'like', '%' . $keyword . '%')
                    ->whereOr('u.nickname', 'like', '%' . $keyword . '%');
            });
        }
        if ($status !== '' && $status !== null) {
            $query->where('s.status', intval($status));
        }

        $list = $query->paginate(20, false, ['query' => request()->get()]);
        $unread = Db::name('suggestion')->where('status', 0)->count();

        $this->assign([
            'list'    => $list,
            'keyword' => $keyword,
            'status'  => $status,
            'unread'  => $unread,
            'menu'    => 'suggestion',
        ]);
        return $this->fetch();
    }

    /**
     * 标记已读 / 未读
     */
    public function markRead()
    {
        $id = intval(input('post.id', 0));
        $status = intval(input('post.status', 1));
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => '参数错误']);
        }
        if (!in_array($status, [0, 1], true)) {
            $status = 1;
        }
        $ok = Db::name('suggestion')->where('id', $id)->update(['status' => $status]);
        if ($ok === false) {
            return json(['code' => 0, 'msg' => '操作失败']);
        }
        return json(['code' => 1, 'msg' => $status === 1 ? '已标记已读' : '已标记未读']);
    }

    public function delete()
    {
        $id = intval(input('post.id', 0));
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => '参数错误']);
        }
        $ok = Db::name('suggestion')->where('id', $id)->delete();
        if (!$ok) {
            return json(['code' => 0, 'msg' => '删除失败']);
        }
        return json(['code' => 1, 'msg' => '删除成功']);
    }
}
