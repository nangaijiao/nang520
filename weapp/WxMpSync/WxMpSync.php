<?php
/**
 * WxMpSync 插件入口文件
 */
namespace weapp\WxMpSync;

use think\Db;
use think\Page;

class WxMpSync
{
    /**
     * 基本信息
     * @var array
     */
    public $weappInfo = [];

    /**
     * 构造
     */
    public function __construct()
    {
        $this->weappInfo = [
            'name'        => 'WxMpSync',
            'code'        => 'WxMpSync',
            'version'     => '1.0.0',
            'min_version' => '1.6.0',
            'author'      => 'Codex',
            'description' => '文章自动同步微信公众号草稿箱插件',
        ];
    }

    /**
     * 安装
     */
    public function install()
    {
        $sql = file_get_contents(dirname(__FILE__) . '/data/install.sql');
        if (!empty($sql)) {
            $sql = str_replace('`ey_', '`' . config('database.prefix'), $sql);
            foreach (explode(';', $sql) as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    Db::execute($statement);
                }
            }
        }
        return true;
    }

    /**
     * 卸载
     */
    public function uninstall()
    {
        $sql = file_get_contents(dirname(__FILE__) . '/data/uninstall.sql');
        if (!empty($sql)) {
            $sql = str_replace('`ey_', '`' . config('database.prefix'), $sql);
            foreach (explode(';', $sql) as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    Db::execute($statement);
                }
            }
        }
        return true;
    }

    /**
     * 插件后台入口
     */
    public function execute()
    {
        $controller = new controller\WxMpSync;
        return $controller->index();
    }

    /**
     * 文章发布后钩子（按 EYOUCMS 钩子名称自行绑定）
     */
    public function afterPublishArticle($params = [])
    {
        try {
            $aid = isset($params['aid']) ? intval($params['aid']) : 0;
            if ($aid <= 0) {
                return true;
            }
            $logic = new logic\WxMpSyncLogic;
            $config = $logic->getConfig();
            if (empty($config['auto_sync'])) {
                return true;
            }
            $logic->syncArticle($aid, true);
        } catch (\Exception $e) {
            // 钩子异常不影响主流程
        }
        return true;
    }
}
