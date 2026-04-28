<?php
/**
 * EYOUCMS 微信公众号定时同步脚本
 * 使用：cd /www/wwwroot/网站目录 && php cli/wx_sync_cron.php
 */

error_reporting(E_ALL ^ E_NOTICE);

define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ROOT_DIR', ROOT_PATH);

require ROOT_PATH . 'core/start.php';

$logic = new \weapp\WxMpSync\logic\WxMpSyncLogic();
$config = $logic->getConfig();

if (empty($config['auto_sync'])) {
    echo '[' . date('Y-m-d H:i:s') . "] auto_sync=0，任务结束\n";
    exit(0);
}

$result = $logic->syncLatest(20);
echo '[' . date('Y-m-d H:i:s') . "] success={$result['success']} fail={$result['fail']}\n";
