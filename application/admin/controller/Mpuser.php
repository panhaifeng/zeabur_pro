<?php
namespace app\admin\controller;

use think\Db;

class Mpuser extends Base
{
    public function index()
    {
        $keyword = trim(input('get.keyword', ''));
        $userType = trim(input('get.user_type', ''));

        $query = Db::name('user')->alias('u')
            ->field('u.*, (SELECT COUNT(*) FROM game_record g WHERE g.user_id = u.id) AS record_count')
            ->order('u.id', 'desc');

        if ($keyword !== '') {
            $query->where('u.nickname|u.openid|u.anonymous_id', 'like', '%' . $keyword . '%');
        }
        if ($userType === 'wx') {
            $query->whereNotNull('u.openid')->where('u.openid', '<>', '');
        } elseif ($userType === 'guest') {
            $query->where(function ($q) {
                $q->whereNull('openid')->whereOr('openid', '');
            });
        }

        $list = $query->paginate(20, false, ['query' => request()->get()]);
        $items = $list->items();
        foreach ($items as &$row) {
            $row['avatar_url'] = $this->buildAvatarUrl(isset($row['avatar']) ? $row['avatar'] : '', (int)$row['id']);
        }
        unset($row);

        $this->assign([
            'list'      => $list,
            'items'     => $items,
            'keyword'   => $keyword,
            'user_type' => $userType,
            'menu'      => 'mpuser',
        ]);
        return $this->fetch();
    }

    private function buildAvatarUrl($avatar, $userId)
    {
        $avatar = trim((string)$avatar);
        if ($avatar === '') {
            return '';
        }
        if (stripos($avatar, 'http://') === 0 || stripos($avatar, 'https://') === 0) {
            return $avatar;
        }
        if ($avatar[0] !== '/') {
            $avatar = '/' . $avatar;
        }
        // 兼容本地子目录部署与线上根域名
        $root = rtrim(request()->root(true), '/');
        return $root . $avatar;
    }
}
