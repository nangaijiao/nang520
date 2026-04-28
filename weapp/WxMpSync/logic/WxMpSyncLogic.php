<?php
namespace weapp\WxMpSync\logic;

use think\Db;

/**
 * 公众号同步核心逻辑
 */
class WxMpSyncLogic
{
    const STATUS_PENDING = 0;
    const STATUS_DRAFT = 1;
    const STATUS_PUBLISH = 2;
    const STATUS_FAIL = -1;

    /**
     * 配置键默认值
     */
    private $defaultConfig = [
        'appid' => '',
        'appsecret' => '',
        'site_domain' => '',
        'default_author' => '',
        'auto_sync' => 0,
        'auto_publish' => 0,
        'default_channel' => '',
        'default_litpic' => '',
    ];

    public function getConfig()
    {
        $rows = Db::name('weapp_wxmp_sync_config')->column('value', 'name');
        $config = $this->defaultConfig;
        foreach ($rows as $name => $value) {
            $config[$name] = $value;
        }
        $config['auto_sync'] = intval($config['auto_sync']);
        $config['auto_publish'] = intval($config['auto_publish']);
        return $config;
    }

    public function saveConfig($data)
    {
        $config = $this->defaultConfig;
        foreach ($config as $name => $default) {
            $value = isset($data[$name]) ? $data[$name] : $default;
            if (is_string($value)) {
                $value = trim($value);
            }
            Db::name('weapp_wxmp_sync_config')->where('name', $name)->delete();
            Db::name('weapp_wxmp_sync_config')->insert([
                'name' => $name,
                'value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value,
            ]);
        }
    }

    /**
     * 同步文章
     */
    public function syncArticle($aid, $isAuto = false, $options = [])
    {
        $aid = intval($aid);
        if ($aid <= 0) {
            return $this->errorResult($aid, '文章不存在');
        }

        $config = $this->getConfig();
        if (empty($config['appid']) || empty($config['appsecret'])) {
            return $this->errorResult($aid, 'AppID 或 AppSecret 未配置');
        }

        $force = !empty($options['force']);
        $logRow = Db::name('weapp_wxmp_sync_log')->where('aid', $aid)->find();
        if (!$force && !empty($logRow) && in_array(intval($logRow['status']), [self::STATUS_DRAFT, self::STATUS_PUBLISH], true)) {
            return $this->errorResult($aid, '文章已经同步过', $logRow);
        }

        $article = $this->getArticle($aid);
        if (empty($article)) {
            return $this->errorResult($aid, '文章不存在');
        }
        if (intval($article['status']) !== 1) {
            return $this->errorResult($aid, '未发布文章不同步', $logRow, $article['title']);
        }
        if (empty($article['content'])) {
            return $this->errorResult($aid, '正文为空', $logRow, $article['title']);
        }

        $this->upsertLog($aid, $article['title'], [
            'status' => self::STATUS_PENDING,
            'err_msg' => '',
        ]);

        $token = $this->getAccessToken($config['appid'], $config['appsecret']);
        if (empty($token['code'])) {
            return $this->errorResult($aid, $token['msg'], $logRow, $article['title']);
        }
        $accessToken = $token['data']['access_token'];

        $coverPath = $this->resolveImagePath($article['litpic'], $config['default_litpic']);
        if (empty($coverPath) || !is_file($coverPath)) {
            return $this->errorResult($aid, '封面图不存在', $logRow, $article['title']);
        }

        $thumb = $this->uploadPermanentMaterial($accessToken, $coverPath, 'image');
        if (empty($thumb['code'])) {
            return $this->errorResult($aid, '封面图上传失败：' . $thumb['msg'], $logRow, $article['title']);
        }

        $contentRes = $this->processContentImages($article['content'], $accessToken, $config);
        if (empty($contentRes['code'])) {
            return $this->errorResult($aid, $contentRes['msg'], $logRow, $article['title']);
        }

        $author = !empty($article['author']) ? $article['author'] : $config['default_author'];
        $digest = !empty($article['seo_description']) ? $article['seo_description'] : mb_substr(strip_tags($contentRes['data']['content']), 0, 120, 'utf-8');

        $draft = $this->createDraft($accessToken, [
            'title' => $article['title'],
            'author' => $author,
            'digest' => $digest,
            'content' => $contentRes['data']['content'],
            'thumb_media_id' => $thumb['data']['media_id'],
            'content_source_url' => rtrim($config['site_domain'], '/') . '/index.php?m=home&c=View&a=index&aid=' . $aid,
            'need_open_comment' => 0,
            'only_fans_can_comment' => 0,
        ]);

        if (empty($draft['code'])) {
            return $this->errorResult($aid, '草稿创建失败：' . $draft['msg'], $logRow, $article['title']);
        }

        $logData = [
            'status' => self::STATUS_DRAFT,
            'media_id' => $draft['data']['media_id'],
            'err_msg' => '',
        ];

        $shouldPublish = isset($options['auto_publish']) ? intval($options['auto_publish']) : intval($config['auto_publish']);
        if ($shouldPublish === 1) {
            $publish = $this->submitPublish($accessToken, $draft['data']['media_id']);
            if (empty($publish['code'])) {
                $this->upsertLog($aid, $article['title'], [
                    'status' => self::STATUS_FAIL,
                    'media_id' => $draft['data']['media_id'],
                    'err_msg' => '自动发布失败：' . $publish['msg'],
                ]);
                return ['code' => 0, 'msg' => '自动发布失败：' . $publish['msg']];
            }
            $logData['status'] = self::STATUS_PUBLISH;
            $logData['publish_id'] = $publish['data']['publish_id'];
        }

        $this->upsertLog($aid, $article['title'], $logData);
        return ['code' => 1, 'msg' => '同步成功', 'data' => $logData];
    }

    /**
     * 扫描最近发布文章并同步
     */
    public function syncLatest($limit = 20)
    {
        $list = Db::name('archives')->alias('a')
            ->leftJoin(config('database.prefix') . 'weapp_wxmp_sync_log l', 'l.aid = a.aid')
            ->where('a.status', 1)
            ->whereNull('l.id')
            ->order('a.aid desc')
            ->limit(intval($limit))
            ->field('a.aid')
            ->select();

        $result = ['success' => 0, 'fail' => 0];
        foreach ($list as $item) {
            $res = $this->syncArticle($item['aid'], false);
            if (!empty($res['code'])) {
                $result['success']++;
            } else {
                $result['fail']++;
            }
        }
        return $result;
    }

    private function getArticle($aid)
    {
        $row = Db::name('archives')->alias('a')
            ->leftJoin(config('database.prefix') . 'article_content ac', 'ac.aid = a.aid')
            ->field('a.aid,a.title,a.litpic,a.author,a.seo_description,a.pubdate,a.typeid,a.status,ac.content')
            ->where('a.aid', intval($aid))
            ->find();
        return $row;
    }

    private function getAccessToken($appid, $appsecret)
    {
        $cacheFile = RUNTIME_PATH . 'cache/wxmp_sync_token_' . md5($appid) . '.php';
        if (is_file($cacheFile)) {
            $cache = include $cacheFile;
            if (!empty($cache['access_token']) && !empty($cache['expire_time']) && $cache['expire_time'] > time() + 60) {
                return ['code' => 1, 'data' => $cache];
            }
        }

        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=' . urlencode($appid) . '&secret=' . urlencode($appsecret);
        $res = $this->curlGet($url);
        if (empty($res['code'])) {
            return $res;
        }
        $data = $res['data'];
        if (empty($data['access_token'])) {
            return ['code' => 0, 'msg' => 'access_token 获取失败：' . json_encode($data, JSON_UNESCAPED_UNICODE)];
        }

        $payload = [
            'access_token' => $data['access_token'],
            'expire_time' => time() + intval($data['expires_in']),
        ];
        if (!is_dir(dirname($cacheFile))) {
            @mkdir(dirname($cacheFile), 0755, true);
        }
        file_put_contents($cacheFile, '<?php return ' . var_export($payload, true) . ';');
        return ['code' => 1, 'data' => $payload];
    }

    private function uploadPermanentMaterial($accessToken, $filePath, $type = 'image')
    {
        $url = 'https://api.weixin.qq.com/cgi-bin/material/add_material?access_token=' . urlencode($accessToken) . '&type=' . urlencode($type);
        $post = ['media' => new \CURLFile(realpath($filePath))];
        return $this->curlPost($url, $post, true);
    }

    private function uploadContentImage($accessToken, $filePath)
    {
        $url = 'https://api.weixin.qq.com/cgi-bin/media/uploadimg?access_token=' . urlencode($accessToken);
        $post = ['media' => new \CURLFile(realpath($filePath))];
        return $this->curlPost($url, $post, true);
    }

    private function createDraft($accessToken, $article)
    {
        $url = 'https://api.weixin.qq.com/cgi-bin/draft/add?access_token=' . urlencode($accessToken);
        $payload = ['articles' => [$article]];
        return $this->curlPost($url, json_encode($payload, JSON_UNESCAPED_UNICODE), false, ['Content-Type: application/json']);
    }

    private function submitPublish($accessToken, $mediaId)
    {
        $url = 'https://api.weixin.qq.com/cgi-bin/freepublish/submit?access_token=' . urlencode($accessToken);
        $payload = ['media_id' => $mediaId];
        return $this->curlPost($url, json_encode($payload, JSON_UNESCAPED_UNICODE), false, ['Content-Type: application/json']);
    }

    private function processContentImages($html, $accessToken, $config)
    {
        $pattern = '/<img[^>]+src=["\']?([^"\' >]+)["\']?[^>]*>/i';
        if (!preg_match_all($pattern, $html, $matches)) {
            return ['code' => 1, 'data' => ['content' => $html]];
        }

        foreach ($matches[1] as $src) {
            $filePath = $this->resolveImagePath($src, '');
            if (empty($filePath) || !is_file($filePath)) {
                continue;
            }
            $upload = $this->uploadContentImage($accessToken, $filePath);
            if (empty($upload['code']) || empty($upload['data']['url'])) {
                return ['code' => 0, 'msg' => '图片上传失败：' . $src];
            }
            $html = str_replace($src, $upload['data']['url'], $html);
        }

        return ['code' => 1, 'data' => ['content' => $html]];
    }

    private function resolveImagePath($src, $fallback)
    {
        $src = trim((string)$src);
        if (empty($src)) {
            $src = trim((string)$fallback);
        }
        if (empty($src)) {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $src)) {
            return '';
        }
        if (strpos($src, ROOT_DIR) === 0 && is_file($src)) {
            return $src;
        }
        $src = '/' . ltrim($src, '/');
        return ROOT_PATH . ltrim($src, '/');
    }

    private function upsertLog($aid, $title, $data = [])
    {
        $now = getTime();
        $base = [
            'aid' => $aid,
            'title' => $title,
            'update_time' => $now,
        ];
        $base = array_merge($base, $data);

        $exists = Db::name('weapp_wxmp_sync_log')->where('aid', $aid)->find();
        if ($exists) {
            Db::name('weapp_wxmp_sync_log')->where('aid', $aid)->update($base);
        } else {
            $base['add_time'] = $now;
            Db::name('weapp_wxmp_sync_log')->insert($base);
        }
    }

    private function errorResult($aid, $msg, $logRow = [], $title = '')
    {
        $title = $title ?: (!empty($logRow['title']) ? $logRow['title'] : '');
        if ($aid > 0) {
            $this->upsertLog($aid, $title, [
                'status' => self::STATUS_FAIL,
                'err_msg' => $msg,
            ]);
        }
        return ['code' => 0, 'msg' => $msg];
    }

    private function curlGet($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            return ['code' => 0, 'msg' => 'curl请求失败：' . $error];
        }
        $data = json_decode($result, true);
        if (!is_array($data)) {
            return ['code' => 0, 'msg' => '返回数据解析失败'];
        }
        if (isset($data['errcode']) && intval($data['errcode']) !== 0) {
            return ['code' => 0, 'msg' => '微信接口返回错误：' . $data['errmsg'] . '(' . $data['errcode'] . ')', 'data' => $data];
        }
        return ['code' => 1, 'data' => $data];
    }

    private function curlPost($url, $postData, $isForm = false, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (!$isForm && is_array($postData)) {
            $postData = http_build_query($postData);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            return ['code' => 0, 'msg' => 'curl请求失败：' . $error];
        }
        $data = json_decode($result, true);
        if (!is_array($data)) {
            return ['code' => 0, 'msg' => '返回数据解析失败'];
        }
        if (isset($data['errcode']) && intval($data['errcode']) !== 0) {
            return ['code' => 0, 'msg' => '微信接口返回错误：' . $data['errmsg'] . '(' . $data['errcode'] . ')', 'data' => $data];
        }
        return ['code' => 1, 'data' => $data];
    }
}
