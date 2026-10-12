<?php
namespace app\api\controller;

use think\Db;

class Index extends Base
{
    public function index()
    {
        $notice = Db::name('system_config')->where('config_key', 'site_notice')->value('config_value');
        $siteName = Db::name('system_config')->where('config_key', 'site_name')->value('config_value');
        $userCount = Db::name('user_db')->count();

        $this->jsonOut(1, 'ok', [
            'site_name'  => $siteName ? $siteName : '后台管理系统',
            'notice'     => $notice ? $notice : '',
            'user_count' => $userCount,
            'user'       => $this->user,
        ]);
    }
}
