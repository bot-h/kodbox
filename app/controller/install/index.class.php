<?php 

/**
 * 初始化项目基础数据
 * 需先创建数据库，建表，并配置好config/setting_user.php
 * server/index.php?install/index/index
 */
class installIndex extends Controller {
    public $roleID;
    public $admin = array();
    public $installLock; 
    public $dbType;
    public $dbWasSet = 'dbWasSet';
	public function __construct() {
		parent::__construct();
		$this->checkAuth();
    }

    public function checkAuth(){
        if(MOD.'.'.ST == 'install.index'){
            if(!ACT || !in_array(ACT, array('env', 'save'))){
                show_json(LNG('common.illegalRequest'), false);
            }
            if($this->checkInstall()) show_json(LNG('admin.install.errorRequest'), false);
        }
    }

    public function check(){
        if(!defined('STATIC_PATH')){
            define('STATIC_PATH',$GLOBALS['config']['settings']['staticPath']);
        }
        if(!defined('INSTALL_PATH')){
            define('INSTALL_PATH', './app/controller/install/');
        }
        // 判断是否需要安装
        if($this->checkInstall()) return;
        if(ACTION == 'user.view.options'){
            $options = array(
                "kod"	=> array(
                    'systemOS'		=> $this->config['systemOS'],
                    'phpVersion'	=> PHP_VERSION,
                    'appApi'		=> rtrim(APP_HOST,'/').'/index.php?',
                    'APP_HOST'		=> APP_HOST,
                    'ENV_DEV'		=> !!STATIC_DEV,
                    'staticPath'	=> STATIC_PATH,
                    'version'		=> KOD_VERSION,
                    'build'			=> KOD_VERSION_BUILD,
                    'channel'		=> INSTALL_CHANNEL,
                ),
                "user"	=> array('config' => array()),
                "io"	=> array(),
                "lang"	=> I18n::getType(),
            );
            show_json($options);
        }
		$actions = array(
			'install.index.env', 
			'install.index.save',
			'user.view.lang',
		);
        if( in_array(ACTION, $actions) ){
			ActionCall(ACTION);exit;
        }
        $this->tpl = CONTROLLER_DIR . 'install/static/';
        $this->values = array('installPath' => INSTALL_PATH);
        $this->display('index.html');
        exit;
    }
    private function checkInstall(){
        $this->installLock = USER_SYSTEM . 'install.lock';
        $setting = BASIC_PATH . 'config/setting_user.php';
        if(@file_exists($this->installLock) && @file_exists($setting)) {
            if($this->dbDefault(true)) return true;
        }
        if(!@file_exists($setting)) del_file($this->installLock);
        return false;
    }
    // 获取数据库默认配置信息
    public function dbDefault($return=false){
        Cache::remove($this->dbWasSet);
        $data = array();
        // 1.获取文件数据
        if(!$dbConfig = $this->config['database']) return $data;
        if(!$dbConfig['DB_TYPE']) return $data;
        if($return) return $dbConfig;

        Cache::set($this->dbWasSet, 1);
        // 2.解析为form表单数据
        $database = array();
        unset($dbConfig['DB_SQL_LOG'],$dbConfig['DB_FIELDS_CACHE'],$dbConfig['DB_SQL_BUILD_CACHE']);
        foreach($dbConfig as $key => $value) {
            $keys = explode("_", strtolower($key));
            $key = $keys[0] . ucfirst($keys[1]);
            $database[$key] = strtolower($value);
        }
        // 2.1 pdo数据处理
        if($database['dbType'] == 'pdo') {
            $dsn = explode(":", $database['dbDsn']);
            $database['pdoType'] = $dsn[0];
            unset($database['dbDsn']);
            foreach($database as $key => $value) {
                if(in_array($key, array('dbType', 'pdoType'))) continue;
                $database['pdo'.ucfirst($key)] = $value;
                unset($database[$key]);
            }
        }
        if($database['dbType'] == 'mysqli') $database['dbType'] = 'mysql';
        if(in_array($database['dbType'], array('sqlite', 'sqlite3'))) {
            if($database['dbType'] == 'sqlite3') $database['dbType'] = 'sqlite';
            $database['dbName'] = '';
        }
        if(!$cache = $this->config['cache']) return $database;  // 无cache

        $cacheType = $cache['cacheType'];
        $database['cacheType'] = $cacheType;
        if($cacheType == 'file') return $database;  // 文件cache
        $database[$cacheType.'Host'] = $cache[$cacheType]['host'];
        $database[$cacheType.'Port'] = $cache[$cacheType]['port'];

        return $database;
    }

    // 环境检测
    public function env(){
        if(isset($this->in['db']) && $this->in['db'] == 1) {
            $data = $this->dbDefault();
            show_json($data);
        }
        $env = array(
            'path_writable'     => array(),
            'php_version'       => phpversion(),
            // 'file_get_contents' => function_exists('file_get_contents') || false,
            'allow_url_fopen'   => ini_get('allow_url_fopen') || false,
            'php_bit'           => phpBuild64() ? 64 : 32,
            'iconv'             => function_exists('iconv') || false,
            'mb_string'         => function_exists('mb_convert_encoding') || false,
            'json'              => function_exists('json_encode') || false,
            'curl'              => function_exists('curl_init') || false,
            'shell_exec'        => (function_exists('shell_exec') && function_exists('exec')) || false,
            'gd'                => true,
            'path_list'         => check_list_dir() || false,
        );
        if( !function_exists('imagecreatefromjpeg')||
            !function_exists('imagecreatefromgif')||
            !function_exists('imagecreatefrompng')||
            !function_exists('imagecolorallocate')){
            $env['gd'] = false;
        }
		$arr_check = array(
            BASIC_PATH,
			DATA_PATH,
			DATA_PATH.'system',
        );
        $parent = get_path_father(BASIC_PATH);
        foreach ($arr_check as $value) {
            $path = str_replace($parent,'',$value);
            $env['path_writable'][$path] = path_writeable($value) || false;
        }
        show_json($env);
    }

    /**
     * 数据库、管理员账号提交
     *
     * @return void
     */
    public function save(){
        $action = Input::get('action','require', null);
        switch($action){
            case 'db':$this->saveDb();break;
            case 'user':$this->saveUser();break;
            default:break;
        }
    }

    /**
     * 1. 数据库配置
     */
    private function saveDb(){
        if(Cache::get($this->dbWasSet)) show_json(LNG('explorer.success'));
        // 1.1 获取db配置信息
        $data = $this->dbConfig();
        $dbName = $data['DB_NAME'];
        $cacheType = Input::get('cacheType', 'in', null, array('file', 'redis', 'memcached'));

        // 1.2 连接数据库
        // 如果用include引入配置文件，$config的值会是上次请求的（？），所以直接用$data赋值
        $GLOBALS['config']['database'] = $data;
        // $GLOBALS['config']['cache']['sessionType'] = $cacheType;
        // $GLOBALS['config']['cache']['cacheType'] = $cacheType;
        think_config($GLOBALS['config']['databaseDefault']);
        think_config($GLOBALS['config']['database']);
        CacheLock::initReset();
        Cache::initReset();
        Cache::remove($this->dbWasSet);
        if($this->dbType == 'mysql'){
            $data['DB_NAME'] = '';  // mysql连接，先不指定数据库，配置错误时会报错
            think_config($data);
            $db = Model()->db();
            $dbexist = $db->execute("show databases like '{$dbName}'");
        }

        // 1.3 检测缓存配置
        // 判断所需缓存配置是否有效——redis、memcached
        if(in_array($cacheType, array('redis', 'memcached'))){
            if(!extension_loaded($cacheType)){
                show_json(sprintf(LNG('common.env.invalidExt'), "[{$cacheType}]"), false);
            }
            $type = ucfirst($cacheType);
            $handle = new $type();
            try{
                $host = Input::get("{$cacheType}Host", 'require');
                $port = Input::get("{$cacheType}Port", 'require');
                if($cacheType == 'redis') {
                    $conn = $handle->connect($host, $port, 1);
                }else{
                    $conn = $handle->addServer($host, $port);
                }
                if(!$conn) show_json(sprintf(LNG('admin.install.cacheError'),"[{$cacheType}]"), false);
            }catch(Exception $e){
                show_json(sprintf(LNG('admin.install.cacheConnectError'),"[{$cacheType}]"), false);
            }
        }

        // 1.4 创建数据库
        if($this->dbType == 'mysql'){
            if(!$dbexist){
                // host=localhost时，root空密码默认没有权限，会创建失败
                $db->execute("create database `{$dbName}`");
            }
            $db->execute("use `{$dbName}`");
            $tableCnt = $db->execute("show tables from `{$dbName}`");
            if(!empty($tableCnt)){
                if(empty($this->in['del_table'])){
                    show_json(LNG('admin.install.ifDelDb'), false, 10001);
                }
                // 删除数据表
                $res = $db->query("SELECT table_name FROM information_schema.`TABLES` WHERE table_schema='{$dbName}'");
                foreach($res as $value){
                    $sql = "drop table `{$value['table_name']}`";
                    $db->execute($sql);
                }
            }
        }else{
            $db = Model()->db();
        }

        // 1.5 写入配置文件：数据库、缓存
        $data['DB_NAME'] = $dbName;
        if($data['DB_TYPE'] == 'pdo'){
            $dbDsn = explode(':', $data['DB_DSN']);
            if($dbDsn[0] == 'mysql'){
                $data['DB_DSN'] .= ';dbname=' . $data['DB_NAME'];
            }
        }
        $database = var_export($data, true);
        $text = array(
            "<?php ",
            "\$config['database'] = {$database};",
            "\$config['cache']['sessionType'] = '{$cacheType}';",
            "\$config['cache']['cacheType'] = '{$cacheType}';"
        );
        if(isset($host) && isset($port)){
            $text[] = "\$config['cache']['{$cacheType}']['host'] = '{$host}';";
            $text[] = "\$config['cache']['{$cacheType}']['port'] = '{$port}';";
        }
        $file = BASIC_PATH . 'config/setting_user.php';
        if(!@file_exists($file)) @touch($file);
        $content = file_get_contents($file);
		$pre = '';
		if(stripos(trim($content),"<?php") > -1) {
            $pre = PHP_EOL;
            unset($text[0]);
        }
		$content = implode(PHP_EOL, $text);
		$replaceFrom = "'DB_NAME' => '".USER_SYSTEM;
		$replaceTo   = "'DB_NAME' => USER_SYSTEM.'";
		$content = str_replace($replaceFrom,$replaceTo,$content);		
        if(!file_put_contents($file,$pre.$content, FILE_APPEND)) {
            $msg = LNG('admin.install.dbSetError');
            $tmp = explode('<br/>', LNG('common.env.pathPermissionError'));
            $msg .= "<br/>" . $tmp[0];
            show_json($msg, false, 10000);
		}

        // 1.6 创建数据表
        $this->createTable($db);
        show_json(LNG('explorer.success'));
    }

    /**
     * 创建数据表
     * @param [type] $db
     * @return void
     */
    private function createTable($db){
        $dbFile = INSTALL_PATH . "data/{$this->dbType}.sql";
		if (!@file_exists($dbFile)) {
			show_json(LNG('admin.install.dbFileError'), false);
		}
        $sql = file_get_contents($dbFile);
        $sqls = $this->_sqlSplite($sql);
        foreach($sqls as $sql){
            $res = $db->execute($sql);
        }
    }

    private function _sqlSplite($sql){
        $num = 0;
        $ret = array();
        $sql = str_replace("\r", "\n", $sql);
        $queriesArray = explode(";\n", trim($sql));
        unset($sql);
        foreach($queriesArray as $query){
            $ret[$num] = '';
            $queries = explode("\n", trim($query));
            $queries = array_filter($queries);
            foreach($queries as $query){
                $str1 = substr($query, 0, 1);
                if($str1 != '#' && $str1 != '-') $ret[$num] .= $query;
            }
            $num++;
        }
        return $ret;
    }

    /**
     * 获取数据库配置信息
     */
    private function dbConfig(){
        $this->dbType = $dbType = Input::get('dbType', 'in', null, array('sqlite', 'mysql', 'pdo'));
        $dbList = $this->dbList();

        $init = array('db_type' => $dbType);
        if($dbType == 'mysql'){
            $data = Input::getArray(array(
                'dbHost'    => array('aliasKey' => 'db_host', 'check'   => 'require'),
                'db_port'   => array('default'  => 3306),
                'dbUser'    => array('aliasKey' => 'db_user', 'check'   => 'require'),
                'dbPwd'     => array('aliasKey' => 'db_pwd', 'check'    => 'require', 'default' => ''),
                'dbName'    => array('aliasKey' => 'db_name', 'check'   => 'require'),
            ));
            $dbType = in_array('mysqli', $dbList) ? 'mysqli' : 'mysql';
        }else if($dbType == 'pdo'){
            $this->dbType = $pdoType = Input::get('pdoType', 'in', null, array('sqlite', 'mysql'));
            if($pdoType == 'mysql'){
                $data = Input::getArray(array(
                    'db_dsn'    => array('default'  => ''),
                    'pdoDbHost' => array('aliasKey' => 'db_host', 'check'   => 'require'),
                    'db_port'   => array('default'  => 3306),
                    'pdoDbUser' => array('aliasKey' => 'db_user', 'check'   => 'require'),
                    'pdoDbPwd'  => array('aliasKey' => 'db_pwd', 'check'    => 'require', 'default' => ''),
                    'pdoDbName' => array('aliasKey' => 'db_name', 'check'   => 'require'),
                ));
                $data['db_dsn'] = "mysql:host={$data['db_host']}";
            }else{
                $data['db_dsn'] = "sqlite:" . $this->sqliteDbFile();
            }
            $dbType .= '_' . $pdoType;
        }else{
            $dbType = in_array('sqlite3', $dbList) ? 'sqlite3' : 'sqlite';
            $data = array('db_name' => $this->sqliteDbFile());
        }
        if(!in_array($dbType, $dbList)){
            if(stripos($dbType, 'sqlite') === 0 || 
            (isset($data['db_dsn']) && stripos($data['db_dsn'], 'sqlite') === 0)) {
                del_file($data['db_name']);
            }
            show_json(sprintf(LNG('admin.install.dbTypeError'),$dbType), false);
        }
        if($init['db_type'] != 'pdo') $init['db_type'] = $dbType;

        return array_change_key_case(array_merge($init, $data, array(
            'DB_SQL_LOG'            => true,		// SQL执行错误日志记录	
            'DB_FIELDS_CACHE'       => true,		// 启用字段缓存
            'DB_SQL_BUILD_CACHE'	=> false,		// sql生成build缓存
        )), CASE_UPPER);
    }

    /**
     * sqlite database文件
     */
    private function sqliteDbFile(){
        $dbFile = USER_SYSTEM . rand_string(12) . '.php';
        @touch($dbFile);
        return $dbFile;
    }

    /**
     * 支持的数据库类型列表
     * @return void
     */
    private function dbList(){
        $db_exts = array('sqlite', 'sqlite3', 'mysql', 'mysqli', 'pdo_sqlite', 'pdo_mysql');
        $dblist = array_map(function($ext){
            if (extension_loaded($ext)){
                return $ext;
            }
        }, $db_exts);
        return array_filter($dblist);
    }

    /**
     * 2. 账号设置
     * @return void
     */
    private function saveUser(){
        $data = Input::getArray(array(
            'name' => array('check' => 'require'),
            'password' => array('check' => 'require')
        ));
        think_config($GLOBALS['config']['databaseDefault']);
        think_config($GLOBALS['config']['database']);

        $userID = 1;
        if(Cache::get($this->dbWasSet) && Model('User')->find($userID)) {
            if(!Model('User')->userEdit($userID, $data)) show_json(LNG('user.bindUpdateError'), false);
            Cache::remove($this->dbWasSet);
            @touch($this->installLock);
            show_json(LNG('admin.install.updateSuccess'), true, $userID);
        }
        $this->admin = $data;
        $this->init();
    }

    /**
     * 初始化数据
     */
    public function init(){
        define('USER_ID',1);
        Cache::deleteAll();
        $this->systemDefault();
		$this->storageDefault();
        $this->initLightApp();
        $this->initPluginList();

        $GLOBALS['SHOW_JSON_NOT_EXIT'] = 1;
        $this->addGroup();
        $this->addAuth();
        $this->roleID = $this->getRoleID();
		$this->addUser();
		KodIO::initSystemPath();
        $GLOBALS['SHOW_JSON_NOT_EXIT'] = 0;

        @touch($this->installLock);
        show_json(LNG('admin.install.createSuccess'), true);
    }

    /**
     * 系统默认设置
     */
    public function systemDefault(){
        $default = $this->config['settingSystemDefault'];
        $res = Model('SystemOption')->set($default);
        
        if(!$res) show_json(LNG('admin.install.defSetError'), false);
    }
    /**
     * 默认存储配置
     */
    public function storageDefault(){
        $driver = KodIO::defaultDriver();
        if($driver) return $driver['id'];

        $dataPath = './data/files/';
        if(!is_dir($dataPath)) @mk_dir($dataPath);
        $data = array (
            'name' => LNG('admin.storage.localStore'),
            'sizeMax' => '0',
            'driver' => 'Local',
            'default' => '1',
            'system' => '1',
            'config' => json_encode(array(
                "basePath" => $dataPath
            )),
        );
        $res = Model('Storage')->add($data);
        if(!$res) show_json(LNG('admin.install.defStoreError'), false);
	}
	
    /**
     * 轻应用列表初始化
     */
    public function initLightApp(){
        $model = Model('SystemLightApp');
		$list = $model->clear();
        $data = FileCache::load(DATA_PATH.'system/apps.php');
		foreach ($data as $app) {
			$type = $app['type'] == 'app' ? 'js' : $app['type'];
			$item = array(
				'name' 		=> $app['name'],
				'group'		=> $app['group'],
				'desc'		=> $app['desc'],
				'content'	=>  array(
					'type'		=> $type,
					'value'		=> $app['content'],
					'icon'		=> $app['icon'],
					'options' => array(
						"width"  	=> $app['width'],
						"height" 	=> $app['height'],
						"simple" 	=> $app['simple'],
						"resize" 	=> $app['resize']
					),
				)
			);
			$model->add($item);
        }
    }

    /**
     * 初始化插件列表
     */
    public function initPluginList(){
        Model('Plugin')->viewList();
        $list = Model('Plugin')->loadList();
        foreach($list as $app => $item) {
            Model('Plugin')->changeStatus($app, 1);
        }
    }

    /**
     * 添加根部门
     */
    public function addGroup(){
        $this->in = array(
            "groupID"   => 1,
            "name" 		=> $this->config['settingSystemDefault']['groupRootName'],
			"sizeMax" 	=> 0,
			"parentID"	=> 0,
        );
        $res = ActionCallHook('admin.group.add');
        if(!$res['code']){
            $GLOBALS['SHOW_JSON_NOT_EXIT'] = 0;
            show_json(LNG('admin.install.defGroupError'), false);
        }
    }

    /**
     * 文档权限
     */
    public function addAuth(){
        Model('Auth')->initData();
    }

    /**
     * 系统内置角色
     */
    public function getRoleID(){
        $list = Model('SystemRole')->listData();
        $roleList = array_to_keyvalue($list, 'administrator', 'id');

        $administrator = 1;
        if(!isset($roleList[$administrator])){
            $roleID = $this->roleDefault();
            if(!$roleID) show_json(LNG('admin.install.defRoleError'), false);
        }else{
            $roleID = $roleList[$administrator];
        }
        return $roleID;
    }

    /**
     * 添加角色——Administrator、default
     */
    private function roleDefault(){
        $administrator = array (
            'name' => '系统管理员',
            'display' => 1,
            'system' => 1,
            'administrator' => 1,
            'auth' => 'explorer.add,explorer.upload,explorer.view,explorer.download,explorer.share,explorer.remove,explorer.edit,explorer.move,explorer.serverDownload,explorer.search,explorer.unzip,explorer.zip,user.edit,user.fav,admin.index.dashboard,admin.index.setting,admin.index.loginLog,admin.index.log,admin.index.server,admin.role.list,admin.role.edit,admin.job.list,admin.job.edit,admin.member.list,admin.member.userEdit,admin.member.groupEdit,admin.auth.list,admin.auth.edit,admin.plugin.list,admin.plugin.edit,admin.storage.list,admin.storage.edit,admin.autoTask.list,admin.autoTask.edit',
            'label' => 'label-green-deep',
            'sort' => 0,
        );

        $default = array (
            'name' => '普通用户',
            'display' => 1,
            'system' => 1,
            'auth' => 'explorer.add,explorer.upload,explorer.view,explorer.download,explorer.share,explorer.remove,explorer.edit,explorer.move,explorer.serverDownload,explorer.search,explorer.unzip,explorer.zip,user.edit,user.fav',
            'label' => 'label-blue-normal',
            'sort' => 1,
        );

        $administrator = Model('SystemRole')->add($administrator);
        Model('SystemRole')->add($default);
        return $administrator;
    }

    /**
     * 新增用户-管理员
     */
    public function addUser(){
        $this->in = array(
            "userID"    => 1,
			"name" 		=> !empty($this->admin['name']) ? $this->admin['name'] : 'admin',
			"nickName" 	=> '管理员',
			"password" 	=> !empty($this->admin['password']) ? $this->admin['password'] : 'admin',
            "roleID"	=> $this->roleID,
            "groupInfo" => json_encode(array('1'=>'1')),
			"sizeMax" 	=> 0,
        );
        $res = ActionCallHook('admin.member.add');
        if(!$res) return;
        if(!$res['code']){
            show_json($res['data'], false);
        }
    }
}