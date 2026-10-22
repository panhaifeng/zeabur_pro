<?php
namespace app\admin\controller;

use think\Db;

class Wordlib extends Base
{
    public function index()
    {
        $keyword = trim(input('get.keyword', ''));
        $query = Db::name('word_lib')->order('id', 'asc');
        if ($keyword !== '') {
            $query->where('lib_name|lib_desc', 'like', '%' . $keyword . '%');
        }
        $list = $query->paginate(15, false, ['query' => request()->get()]);
        $counts = Db::name('word_item')->group('lib_id')->column('count(*)', 'lib_id');
        $this->assign([
            'list'    => $list,
            'counts'  => $counts,
            'keyword' => $keyword,
            'menu'    => 'wordlib',
        ]);
        return $this->fetch();
    }

    public function add()
    {
        if (request()->isPost()) {
            $data = $this->getFormData();
            if (is_string($data)) {
                return json(['code' => 0, 'msg' => $data]);
            }
            Db::name('word_lib')->insert($data);
            return json(['code' => 1, 'msg' => '添加成功', 'url' => url('admin/wordlib/index')]);
        }
        $this->assign('menu', 'wordlib');
        return $this->fetch();
    }

    public function edit()
    {
        $id = (int)input('id', 0);
        $info = Db::name('word_lib')->where('id', $id)->find();
        if (!$info) {
            $this->error('词库不存在');
        }

        if (request()->isPost()) {
            $data = $this->getFormData();
            if (is_string($data)) {
                return json(['code' => 0, 'msg' => $data]);
            }
            Db::name('word_lib')->where('id', $id)->update($data);
            return json(['code' => 1, 'msg' => '保存成功', 'url' => url('admin/wordlib/index')]);
        }

        $this->assign([
            'info' => $info,
            'menu' => 'wordlib',
        ]);
        return $this->fetch();
    }

    public function delete()
    {
        $id = (int)input('id', 0);
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => '参数错误']);
        }
        $wordIds = Db::name('word_item')->where('lib_id', $id)->column('id');
        Db::startTrans();
        try {
            if ($wordIds) {
                Db::name('user_error_word')->where('word_item_id', 'in', $wordIds)->delete();
                Db::name('word_item')->where('lib_id', $id)->delete();
            }
            Db::name('word_lib')->where('id', $id)->delete();
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 0, 'msg' => '删除失败']);
        }
        return json(['code' => 1, 'msg' => '删除成功']);
    }

    private function getFormData()
    {
        $libName = trim(input('post.lib_name', ''));
        $libDesc = trim(input('post.lib_desc', ''));
        $status = (int)input('post.status', 1);

        if ($libName === '') {
            return '请输入词库名称';
        }

        return [
            'lib_name' => $libName,
            'lib_desc' => $libDesc,
            'status'   => $status === 1 ? 1 : 0,
        ];
    }
}
