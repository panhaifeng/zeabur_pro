<?php
namespace app\admin\controller;

use think\Controller;
use think\Db;

class Base extends Controller
{
    protected function initialize()
    {
        $user = session('admin_user');
        if (empty($user)) {
            $this->redirect('admin/login/index');
        }
        $displayName = !empty($user['nickname']) ? $user['nickname'] : $user['username'];
        $avatar = function_exists('mb_substr')
            ? mb_substr($displayName, 0, 1, 'UTF-8')
            : substr($displayName, 0, 1);
        $this->assign('adminUser', $user);
        $this->assign('adminDisplayName', $displayName);
        $this->assign('adminAvatar', $avatar);
        $this->assign('siteName', $this->siteName());
    }

    protected function siteName()
    {
        $name = Db::name('system_config')->where('config_key', 'site_name')->value('config_value');
        return $name ? $name : '后台管理系统';
    }
}
