<?php
namespace weapp\WxMpSync\controller;

use app\common\controller\Weapp;
use think\Db;
use think\Request;
use weapp\WxMpSync\logic\WxMpSyncLogic;

/**
 * 插件后台控制器
 */
class WxMpSync extends Weapp
{
    /**
     * 插件首页（含手动同步入口）
     */
    public function index()
    {
        $logic = new WxMpSyncLogic();
        $config = $logic->getConfig();

        if (Request::instance()->isPost()) {
            $aid = intval(input('post.aid/d', 0));
            $autoPublish = intval(input('post.auto_publish/d', 0));
            $result = $logic->syncArticle($aid, false, ['auto_publish' => $autoPublish]);
            return json($result);
        }

        $this->assign('config', $config);
        return $this->fetch('index');
    }

    /**
     * 配置页面
     */
    public function config()
    {
        $logic = new WxMpSyncLogic();
        if (Request::instance()->isPost()) {
            $post = input('post.');
            $logic->saveConfig($post);
            $this->success('保存成功');
        }

        $this->assign('config', $logic->getConfig());
        return $this->fetch('config');
    }

    /**
     * 日志页面
     */
    public function log()
    {
        $list = Db::name('weapp_wxmp_sync_log')->order('id desc')->paginate(20, false, [
            'query' => input('param.'),
        ]);
        $this->assign('list', $list);
        $this->assign('page', $list->render());
        return $this->fetch('log');
    }

    /**
     * 重试同步
     */
    public function retry()
    {
        $aid = intval(input('aid/d', 0));
        $logic = new WxMpSyncLogic();
        $result = $logic->syncArticle($aid, false, ['force' => true]);
        if (!empty($result['code'])) {
            $this->success($result['msg']);
        }
        $this->error($result['msg']);
    }
}
