<?php
namespace app\admin\controller;

use think\Db;

class Config extends Base
{
    public function index()
    {
        if (request()->isPost()) {
            $siteName = trim(input('post.site_name', ''));
            $siteNotice = trim(input('post.site_notice', ''));
            if ($siteName === '') {
                return json(['code' => 0, 'msg' => '请输入站点名称']);
            }
            $this->saveConfig('site_name', $siteName);
            $this->saveConfig('site_notice', $siteNotice);
            return json(['code' => 1, 'msg' => '保存成功']);
        }

        $rows = Db::name('system_config')->column('config_value', 'config_key');
        $this->assign([
            'site_name'   => isset($rows['site_name']) ? $rows['site_name'] : '',
            'site_notice' => isset($rows['site_notice']) ? $rows['site_notice'] : '',
            'menu'        => 'config',
        ]);
        return $this->fetch();
    }

    private function saveConfig($key, $value)
    {
        $exists = Db::name('system_config')->where('config_key', $key)->find();
        if ($exists) {
            Db::name('system_config')->where('config_key', $key)->update(['config_value' => $value]);
        } else {
            Db::name('system_config')->insert([
                'config_key'   => $key,
                'config_value' => $value,
            ]);
        }
    }
}
