<?php
namespace app\api\controller;

use think\Db;

class Word extends Base
{
    /**
     * 词库列表
     * GET /api/word/lib-list
     */
    public function libList()
    {
        $list = Db::name('word_lib')
            ->where('status', 1)
            ->field('id,lib_name,lib_desc,status')
            ->order('id', 'asc')
            ->select();
        $this->jsonOut(0, 'success', array('list' => $list ? $list : array()));
    }
}
