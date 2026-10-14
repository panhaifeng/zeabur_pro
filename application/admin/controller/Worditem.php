<?php
namespace app\admin\controller;

use think\Db;

class Worditem extends Base
{
    public function index()
    {
        $libId = (int)input('get.lib_id', 0);
        $keyword = trim(input('get.keyword', ''));
        $libs = Db::name('word_lib')->order('id', 'asc')->select();

        $query = Db::name('word_item')->alias('w')
            ->join('word_lib l', 'w.lib_id = l.id', 'LEFT')
            ->field('w.*, l.lib_name')
            ->order('w.id', 'desc');
        if ($libId > 0) {
            $query->where('w.lib_id', $libId);
        }
        if ($keyword !== '') {
            $query->where('w.word|w.meaning', 'like', '%' . $keyword . '%');
        }
        $list = $query->paginate(15, false, ['query' => request()->get()]);

        $this->assign([
            'list'    => $list,
            'libs'    => $libs,
            'lib_id'  => $libId,
            'keyword' => $keyword,
            'menu'    => 'worditem',
        ]);
        return $this->fetch();
    }

    public function add()
    {
        $libs = Db::name('word_lib')->order('id', 'asc')->select();
        if (!$libs) {
            $this->error('请先创建词库', url('admin/wordlib/add'));
        }

        if (request()->isPost()) {
            $data = $this->getFormData();
            if (is_string($data)) {
                return json(['code' => 0, 'msg' => $data]);
            }
            Db::name('word_item')->insert($data);
            $url = url('admin/worditem/index', ['lib_id' => $data['lib_id']]);
            return json(['code' => 1, 'msg' => '添加成功', 'url' => $url]);
        }

        $this->assign([
            'libs'   => $libs,
            'lib_id' => (int)input('get.lib_id', 0),
            'menu'   => 'worditem',
        ]);
        return $this->fetch();
    }

    public function edit()
    {
        $id = (int)input('id', 0);
        $info = Db::name('word_item')->where('id', $id)->find();
        if (!$info) {
            $this->error('单词不存在');
        }
        $libs = Db::name('word_lib')->order('id', 'asc')->select();

        if (request()->isPost()) {
            $data = $this->getFormData();
            if (is_string($data)) {
                return json(['code' => 0, 'msg' => $data]);
            }
            Db::name('word_item')->where('id', $id)->update($data);
            $url = url('admin/worditem/index', ['lib_id' => $data['lib_id']]);
            return json(['code' => 1, 'msg' => '保存成功', 'url' => $url]);
        }

        $this->assign([
            'info' => $info,
            'libs' => $libs,
            'menu' => 'worditem',
        ]);
        return $this->fetch();
    }

    public function delete()
    {
        $id = (int)input('id', 0);
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => '参数错误']);
        }
        Db::startTrans();
        try {
            Db::name('user_error_word')->where('word_item_id', $id)->delete();
            Db::name('word_item')->where('id', $id)->delete();
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 0, 'msg' => '删除失败']);
        }
        return json(['code' => 1, 'msg' => '删除成功']);
    }

    private function getFormData()
    {
        $libId = (int)input('post.lib_id', 0);
        $word = trim(input('post.word', ''));
        $phonetic = trim(input('post.phonetic', ''));
        $meaning = trim(input('post.meaning', ''));
        $distract = trim(input('post.distract', ''));

        if ($libId <= 0) {
            return '请选择词库';
        }
        $lib = Db::name('word_lib')->where('id', $libId)->find();
        if (!$lib) {
            return '词库不存在';
        }
        if ($word === '') {
            return '请输入英文单词';
        }
        if ($meaning === '') {
            return '请输入正确释义';
        }
        if ($distract === '') {
            return '请输入干扰释义（逗号分隔，建议3个）';
        }

        // 统一为英文逗号
        $distract = str_replace('，', ',', $distract);
        $parts = array_values(array_filter(array_map('trim', explode(',', $distract))));
        if (count($parts) < 1) {
            return '请至少填写1个干扰释义';
        }
        $distract = implode(',', $parts);

        return [
            'lib_id'   => $libId,
            'word'     => $word,
            'phonetic' => $phonetic,
            'meaning'  => $meaning,
            'distract' => $distract,
        ];
    }
}
