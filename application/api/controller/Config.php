<?php
namespace app\api\controller;

use think\Db;

class Config extends Base
{
    public function index()
    {
        $rows = Db::name('system_config')->column('config_value', 'config_key');
        $this->jsonOut(1, 'ok', [
            'site_name'   => isset($rows['site_name']) ? $rows['site_name'] : '',
            'site_notice' => isset($rows['site_notice']) ? $rows['site_notice'] : '',
        ]);
    }

    public function save()
    {
        $siteName = trim(input('site_name', ''));
        $siteNotice = trim(input('site_notice', ''));
        if ($siteName === '') {
            $this->jsonOut(0, '请输入站点名称');
        }

        $this->saveConfig('site_name', $siteName);
        $this->saveConfig('site_notice', $siteNotice);
        $this->jsonOut(1, '保存成功');
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
