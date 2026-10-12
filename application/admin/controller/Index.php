<?php
namespace app\admin\controller;

use think\Db;

class Index extends Base
{
    public function index()
    {
        $userCount = Db::name('user_db')->count();
        $notice = Db::name('system_config')->where('config_key', 'site_notice')->value('config_value');
        $this->assign([
            'userCount' => $userCount,
            'notice'    => $notice ? $notice : '',
            'menu'      => 'index',
        ]);
        return $this->fetch();
    }
}
