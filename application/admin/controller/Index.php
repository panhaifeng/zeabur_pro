<?php
namespace app\admin\controller;

use think\Db;

class Index extends Base
{
    public function index()
    {
        $userCount = Db::name('user_db')->count();
        $libCount = Db::name('word_lib')->count();
        $wordCount = Db::name('word_item')->count();
        $recordCount = 0;
        try {
            $recordCount = Db::name('game_record')->count();
        } catch (\Exception $e) {
            $recordCount = 0;
        }
        $notice = Db::name('system_config')->where('config_key', 'site_notice')->value('config_value');
        $this->assign([
            'userCount'   => $userCount,
            'libCount'    => $libCount,
            'wordCount'   => $wordCount,
            'recordCount' => $recordCount,
            'notice'      => $notice ? $notice : '',
            'menu'        => 'index',
        ]);
        return $this->fetch();
    }
}
