<?php

/*
 * @link http://kodcloud.com/
 * @author warlee | e-mail:kodcloud@qq.com
 * @copyright warlee 2014.(Shanghai)Co.,Ltd
 * @license http://kodcloud.com/tools/license/license.txt
 */

class userBind extends Controller {
	const BIND_META_INFO = 'Info';
	const BIND_META_UNIONID = 'Unionid';
	private $addUser;
	private $withApp;
	public $typeList = array();

	public function __construct() {
		parent::__construct();
		$this->typeList = array(
			'qq' => 'QQ',
			'github' => 'GitHub',
			'weixin' => LNG('common.wechat')
		);
		$this->addUser = $this->withApp = false;
	}

	/**
	 * 第三方验证
	 * data string {type;openid;unionid;nickName;sex;avatar}
	 * type string qq|weixin|github
	 */
	public function bindApi() {
		// api固定参数:type、sign、kodid、timestamp、data
		$input = Input::getArray(array(
			'type'		 => array('check' => 'require'),
			'kodid'		 => array('check' => 'require'),
			'timestamp'	 => array('check' => 'require'),
			'data'		 => array('check' => 'require')
		));
		$type = $input['type'];
		if (!isset($this->typeList[$type])) {
			show_json(LNG('common.invalidRequest'), false);
		}
		// 验证签名
		$sign = Input::get('sign','require');
		$_sign = $this->makeSign($input['kodid'], $input);
		if ($sign !== $_sign) show_json(LNG('user.signError'), false);

		// 解析data参数
		$data = unserialize(base64_decode($input['data']));

		// 服务端secret为空,直接返回
		if (!$data && is_string($input['data'])) {
			Model('SystemOption')->set('systemSecret', '');
			// $secret = $this->apiSecret();	// TODO 获取不到原始请求数据，无法再次发动请求
			return $this->bindHtml($type, $data, false, array('bind', 'sign_error'));
		}

		// 返回提示信息
		return $this->bindDisplay($type, $data);
	}

	/**
	 * 第三方绑定返回信息
	 * @param type $type	qq|github|weixin
	 * @param type $data
	 */
	public function bindDisplay($type, $data) {
		$unionid = $data['unionid'];
		$client = Input::get('client','require',1); // 前后端
		$data['client'] = $client;

		// 判断是否已绑定
		if ($bind = $this->isBind($type, $unionid, $client)) {
			// 前端:已绑定,直接跳转登录
			// 后端:已绑定(别的账号),提示绑定失败
			if ($client) {
				$data['bind'] = true;
				if(is_array($bind) && $bind[0]){
					$success = true;
					$msg = array('login');	// 已绑定且已开启，直接登录
				}else{
					$msg = array('invalid');
					$success = false;
				}
			} else {
				$data['bind'] = false; // 可不传
				$msg = array('bind', 'bind_others', $bind);
				$success = false;	// $bind=true，说明已绑定其他账号——update:bind=name
			}
			return $this->bindHtml($type, $data, $success, $msg);
		}

		// 未绑定,前、后端处理
		$function = $client ? 'bindFront' : 'bindBack';
		return $this->$function($type, $data);
	}

	/**
	 * app端后台绑定
	 */
	public function bind(){
		$data = Input::getArray(array(
			'type'		=> array('check' => 'in', 'param' => array_keys($this->typeList)),
			'openid' 	=> array('check' => 'require'),
			'unionid' 	=> array('check' => 'require'),
			'nickName' 	=> array('check' => 'require'),
			'sex' 		=> array('check' => 'require', 'default' => 0),
			'avatar' 	=> array('check' => 'require', 'default' => ''),
		));
		$this->in['client'] = 0;
		$res = ActionCallHook('user.bind.bindDisplay', $data['type'], $data);
		$msg = $res['data'];
		if(isset($msg['msg'])){
			$msg = explode(';', $msg['msg']);
			$msg = isset($msg[1]) ? $msg[1] : $msg[0];
		}
		// 操作失败
		if(!$res['code']) show_json($msg, false);
		// success/bind同时为false；bind为true——更新用户信息失败
		if(!$res['data']['success']){	// bind
			show_json($msg, false);
		}
		$this->bindToServer($data);
		show_json(LNG('common.bindSuccess'));
	}

	/**
	 * 通过app端绑定
	 */
	public function bindWithApp($data){
		$this->withApp = true;
		if(empty($data['openid'])) show_json(LNG('common.invalidParam') . ':openid', false);
		if(empty($data['unionid'])) show_json(LNG('common.invalidParam') . ':unionid', false);
		$res = ActionCallHook('user.bind.bindDisplay', $data['type'], $data);
		$msg = $res['data'];	// bindHtml前就已经报错时，只打印了第一个错误信息
		if(isset($msg['msg'])){
			$msg = explode(';', $msg['msg']);
			$msg = isset($msg[1]) ? $msg[1] : $msg[0];
		}
		// 操作失败
		if(!$res['code']) show_json($msg, false);
		// 绑定成功但结果失败-未启用
		if(!$res['data']['success']){
			$code = $res['data']['bind'] ? ERROR_CODE_USER_INVALID : false;
			show_json($msg, $code);
		}
		// 未注册用户，直接返回登录
		if(!$this->addUser) return true;
		$this->bindToServer($data);
		return true;
	}
	// app端绑定信息写入api服务器
	private function bindToServer($data){
		// 写入api服务器
		$param = array(
			'type'		=> $data['type'],
			'nickName'	=> $data['name'],
			'avatar'	=> $data['avatar'],
			'sex'		=> isset($data['sex']) ? $data['sex'] : 0,
			'openid'	=> $data['openid'],
			'unionid'	=> $data['unionid'],
		);
		$this->apiRequest('bind', $param);	// TODO 这里不管成功与否，登录信息已存储
	}

	/**
	 * 第三方绑定返回信息-前端
	 * @param type $type
	 * @param type $data
	 */
	private function bindFront($type, $data) {
		$data['bind'] = false;
		// 1.判断是否开放了注册
		$regist = Model('SystemOption')->get('regist');
		if(!(int) $regist['openRegist']){
			return $this->bindHtml($type, $data, false, array('login'));
		}
		// 2. 自动注册
		$regist = $this->bindRegist($type, $data);
		if(!$regist['code']){
			return $this->bindHtml($type, $data, false, array('bind', $regist['data']));
		}
		$data['bind'] = true;
		// 自动登录
		$userID = $regist['data'];
		$user = Model("User")->getInfo($userID);
		if($user['status']) {
			Model('User')->userEdit( $userID,array("lastLogin"=>time()) );
			Action('user.index')->loginSuccess($user);
		}
		if($this->withApp) {	// bindHtml会直接打印，故在此return
			return array(
				'code' => true,
				'data' => array('success' => true)
			);
		}
		return $this->bindHtml($type, $data, true, array('connect'));
	}

	/**
	 * 绑定（自动）注册
	 */
	private function bindRegist($type, $data){
		$typeList = array(
			'qq' 	 => 'qq',
			'weixin' => 'wx',
			'github' => 'gh',
		);
		// 1.写入用户主信息
		$param = array(
			'name'		 => $typeList[$type] . substr(guid(), 0, 10),
			'nickName'	 => $data['nickName'],
			'password'	 => rand_string(6, 1)
		);
		$res = Action("user.regist")->addUser($param);
		if (!$res['code']) return $res;
		// 2.更新账户名
		$data['userID'] = $res['info'];
		$update = array(
			'userID'	=> $data['userID'],
			'name'		=> strtoupper($typeList[$type]) . '1' . str_pad($data['userID'], 8, 0, STR_PAD_LEFT),
			'avatar'	=> $data['avatar'],
		);
		Model('User')->userEdit($update['userID'], $update);
		// 3.密码置为空
		Model('User')->metaSet($data['userID'],'passwordSalt','');
		Model('User')->where(array('userID' => $data['userID']))->save(array('password' => ''));
		// 4.写入用户meta信息
		if (!$this->bindSave($data)) return array('code' => false, 'data' => LNG('user.bindUpdateError'));
		$this->addUser = true;
		return array('code' => true, 'data' => $data['userID']);
	}

	/**
	 * 第三方绑定返回信息-后端
	 * @param type $type
	 * @param type $data
	 */
	private function bindBack($type, $data) {
		$data['bind'] = true;
		// 绑定信息存储
		if (!$ret = $this->bindSave($data, true)) {
			return $this->bindHtml($type, $data, false, array('bind', 'update_error'));
		}
		return $this->bindHtml($type, $data, true, array('bind'));
	}

	/**
	 * TODO api返回操作结果信息
	 * @param type $type	// qq|github|weixin
	 * @param type $succ	//
	 * @param type $act	// connect|bind|login
	 * @param type $msg		// sign_error|update_error|bind_others
	 * @return type
	 */
	private function bindInfo($type, $success, $msgData = array()) {
		$act = $msgData[0];
		$msg = isset($msgData[1]) ? $msgData[1] : '';
		if ($success) {
			return LNG('common.congrats') . $this->typeList[$type] . LNG('common.' . $act . 'Success');
		}
		$errTit = LNG('common.sorry') . $this->typeList[$type];
		if ($act == 'login') {
			return $errTit . LNG('common.loginError') . ';'.$this->typeList[$type] . LNG('user.thirdBindFirst');
		}
		// 2.2 尚未启用
		if ($act == 'invalid') {
			return $errTit . LNG('common.loginError') . ';' . LNG('user.userEnabled');
		}
		// 2.3 其他失败
		$errList = array(
			'sign_error'	 => LNG('user.bindSignError'),
			'update_error'	 => LNG('user.bindUpdateError'),
			'bind_others'	 => $this->typeList[$type] . LNG('user.bindOthers') . "[{$msgData[2]}]"
		);
		return $errTit . LNG('common.bindError') .';' . (isset($errList[$msg]) ? $errList[$msg] : $msg);
	}

	/**
	 * 
	 * @param type $type
	 * @param type $data
	 * @param type $success
	 * @param type $msgData
	 */
	private function bindHtml($type, $data, $success, $msgData) {
		$return = array(
			'type'		 => $type, // 绑定类型
			'typeTit'	 => $this->typeList[$type], // 绑定类型名称
			'success'	 => (int) $success, // 绑定结果
			'bind'		 => $data['bind'], // 是否已绑定
			'client'	 => (int) $data['client'], // 前后端
			'name'		 => isset($data['nickName']) ? $data['nickName'] : '',
			'avatar'	 => isset($data['avatar']) ? $data['avatar'] : '', // 头像
			'imgUrl'	 => './static/images/file_icon/icon_others/error.png', // 结果标识(头像orX)
			'title'		 => LNG('explorer.error'), // 结果标题
			'msg'		 => $this->bindInfo($type, $success, $msgData), // 结果说明
		);
		if ($success) {
			$return['title'] = LNG('explorer.success');
			$return['imgUrl'] = $data['avatar'];
		}
		return show_json($return);
	}

	/**
	 * 发送信息(验证码)-短信、邮件	当前只有个人设置绑定使用,暂时只记为绑定
	 */
	public function sendMsg() {
		$data = Input::getArray(array(
				'type'	 => array('check' => 'require'),
				'input'	 => array('check' => 'require'),
		));
		$type = $data['type'];

		// 0. 发送短信,先检查图片验证码
		if ($type == 'phone') {
			$checkCode = Input::get('checkCode', 'require', '');
			Action('user.setting')->checkImgCode($checkCode);
		}

		// 1.1 判断邮箱是否已绑定-自己
		$userInfo = Session::get("kodUser");
		if ($userInfo[$data['type']] == $data['input']) {
			show_json(LNG('common.' . $type) . LNG('user.binded'), false);
		}
		// 1.2 判断邮箱是否已绑定-他人
		if ($res = Model('User')->userSearch(array($type => $data['input']), 'name,nickName')) {
			$name = $res['nickName'] ? $res['nickName'] : $res['name'];
			show_json(LNG('common.' . $type) . LNG('user.bindOthers') . "[{$name}]", false);
		}

		// 2.1 发送邮件
		if ($type == 'email') {
			$res = $this->sendEmail('email_bind', array('address' => $data['input']));
			if (!$res['code']) {
				show_json(LNG('user.sendFail') . ': ' . $res['data'], false);
			}
		}
		// 2.2 发送短信
		if ($type == 'phone') {
			$res = $this->sendSms('phone_bind', $data['input']);
			if (!$res['code']) {
				show_json(LNG('user.sendFail') . ': ' . $res['data'], false);
			}
		}

		// 3. 存储验证码
		$param = array(
			'type' => 'setting',
			'input' => $data['input']
		);
		Action("user.regist")->msgCodeExec($type, $res['data'], $param, true);
		show_json(LNG('user.sendSuccess'), true);
	}

	/**
	 * 发送邮件
	 * @param type $type
	 * @param type $param
	 * @return type
	 */
	public function sendEmail($type, $param) {
		if (is_string($param)) {
			$param = array('address' => $param);
		}
		if (!Input::check($param['address'], 'email')) {
			return array('code' => false, 'data' => LNG('common.invalidFormat'));
		}
		// 邮件发送方式:0.系统默认;1.自定义
		if (isset($param['emailType'])) {
			$emailType = $param['emailType'];
		} else {
			$emailType = Model('SystemOption')->get('emailType');
		}
		// 自定义发送
		if ((int) $emailType) {
			return $this->sendEmailCustom($param);
		}
		// 系统默认发送
		return $this->sendEmailSystem($type, $param);
	}

	/**
	 * 发送邮件-自定义(邮件服务器)
	 * @param type $param
	 * @return type
	 */
	private function sendEmailCustom($param) {
		// 内容为空,则默认为发送验证码
		if (isset($param['content'])) {
			$content = $param['content'];
		} else {
			$content = array(
				'type'	 => 'code',
				'data'	 => array()
			);
		}
		// 如果是发送验证码,在这里生成,发送成功再返回code值
		if ($content['type'] == 'code') {
			$content['data']['code'] = rand_string(6);
		}
		// 发送测试——提供邮件服务器账号信息
		$tmp = array();
		if (isset($param['test'])) {
			$tmp = array(
				'host'		 => $param['host'],
				'email'		 => $param['email'],
				'password'	 => $param['password'],
			);
		}
		// 参数拼接
		$systemName = Model('SystemOption')->get('systemName'); // 落款-系统名称
		$subject = isset($param['subject']) ? $param['subject'] : LNG('user.emailVerify'); // 主题
		$data = array(
			'address'	 => $param['address'], // 收件人
			'subject'	 => "[{$systemName}]" . $subject, // 主题
			'content'	 => $this->emailContent($content), // 内容
			'html'		 => 1
		);
		// 邮件发送
		$mail = new Mailer();
		$res = $mail->send(array_merge($tmp, $data));
		if ($res['code']) $res['data'] = $content['data']['code'];
		return $res;
	}

	/**
	 * 发送邮件-系统默认(邮件服务器)
	 * @param type $type
	 * @param type $param
	 * @return type
	 */
	private function sendEmailSystem($type, $param) {
		$data = array(
			'type'		 => $type,
			'input'		 => $param['address'], // 邮箱or手机
			'language'	 => i18n::getType(),
			'company'	 => Model('SystemOption')->get('systemName')
		);
		return $this->apiRequest('email', $data);
	}

	/**
	 * 生成邮件内容 (html)
	 * @param type $content
	 * @return type
	 */
	public function emailContent($content) {
		$tmp = array(
			'code'		 => isset($content['data']['code']) ? $content['data']['code'] : '',
			'url'		 => isset($content['data']['url']) ? $content['data']['url'] : '',
			'systemName' => isset($content['data']['systemName']) ? $content['data']['systemName'] :Model('SystemOption')->get('systemName'),
			'nickname'	 => isset($content['data']['nickname']) ? $content['data']['nickname'] : '',
			'date'		 => date("Y-m-d"),
		);
		$type = $content['type'];

		$data = array(
			'type' => $type,
			'dear' => LNG('admin.dearUser'),
			'codeDesc' => sprintf(LNG('admin.emailThxUse'), $tmp['systemName']) . LNG('admin.emailVerifyCode'),
			'code' => $tmp['code'],
			'codeTips' => LNG('admin.emailVerifyInTime'),
			'dearName' => LNG('admin.dear') . $tmp['nickname'],
			'linkDesc' => sprintf(LNG('admin.emailResetLink'), $tmp['systemName']),
			'link' => $tmp['url'],
			'linkTips' => LNG('admin.emailExpireTime'),
			'name' => $tmp['systemName'],
			'date' => $tmp['date'],
		);
		ob_end_clean();
		ob_start();
		extract(array('data' => $data));
		require(TEMPLATE . '/user/email.html');
		$html = ob_get_contents();
		ob_end_clean();
		return $html;
	}

	/**
	 * 通过kod api发送(验证码)短信
	 * @param type $type
	 * @param type $phone
	 * @return type
	 */
	public function sendSms($type, $phone) {
		if (!Input::check($phone, 'phone')) {
			return array('code' => false, 'data' => LNG('common.invalidFormat'));
		}
		$data = array(
			'type'		 => $type,
			'input'		 => $phone, // 邮箱or手机
			'language'	 => i18n::getType(),
		);
		return $this->apiRequest('sms', $data);
	}

	/**
	 * 请求Kodapi服务器
	 * @param type $type
	 * @param type $data
	 * @return type
	 */
	private function apiRequest($type, $data = array()) {
		$kodid = md5(BASIC_PATH . Model('SystemOption')->get('systemPassword'));
		$post = array(
			'type'		 => $type,
			'kodid'		 => $kodid,
			'timestamp'	 => time(),
			'data'		 => is_array($data) ? json_encode($data) : $data
		);
		$post['sign'] = $this->makeSign($kodid, $post);
		$url = $this->config['settings']['kodApiServer'] . 'plugin/platform/';
		$response = url_request($url, 'GET', $post);
		if ($response['status']) {
			return json_decode($response['data'], true);
		}
		// Network error. Please check whether the server can access the external network.
		return array('code' => false, 'data' => 'network error.');
	}

	/**
	 * kodapi请求参数签名
	 * @param type $kodid
	 * @param type $post
	 * @return type
	 */
	private function makeSign($kodid, $post) {
		// 获取secret
		if (!$secret = Model('SystemOption')->get('systemSecret')) {
			// 本地没有,先去kodapi请求获取secret(此处请求secret以Kodid代替)
			if ($post['type'] != 'secret') {
				$secret = $this->apiSecret();
			} else {
				$secret = $kodid;
			}
		}
		ksort($post);
		$tmp = array();
		$post = stripslashes_deep($post);
		foreach ($post as $key => $value) {
			$tmp[] = $key . '=' . $value;
		}
		$md5 = md5(sha1(implode('&', $tmp) . $secret));
		return strtoupper($md5); //生成签名
	}

	/**
	 * 向api请求secret
	 * @return type
	 */
	private function apiSecret() {
		$res = $this->apiRequest('secret');
		if (!$res['code']) {
			$msg = 'Api secret error' . (!empty($res['data']) ? ': ' . $res['data'] : '');
			show_json($msg, false);
		}
		Model('SystemOption')->set('systemSecret', $res['data']);
		return $res['data'];
	}

	/**
	 * 请求kodapi url参数处理——前端第三方登录、后端绑定
	 */
	public function oauth() {
		$data = Input::getArray(array(
			'type'	 	=> array('check'   => 'require'),
			'action' 	=> array('check'   => 'require'),
			'client' 	=> array('default' => 1),
			'platform'	=> array('default' => 'open'),
		));
		if (!isset($this->typeList[$data['type']])) {
			show_json(LNG('common.invalidParam'), false);
		}

		$client = isset($data['client']) ? "&client={$data['client']}" : "";
		$link = Input::get('link');
		$link = !$link ? APP_HOST . '#user/bindInfo' : $link;
		$post = array(
			"type"		 => $data['type'],
			'kodid'		 => md5(BASIC_PATH . Model('SystemOption')->get('systemPassword')),
			'timestamp'	 => time(),
			"data"		 => json_encode(array(
				'action' => $data['type'] . '_' . $data['action'],
				'link'	 => $link . $client
			))
		);
		$post['sign'] = $this->makeSign($post['kodid'], $post);

		// 获取微信appid
		$appId = '';
		if($data['type'] == 'weixin'){
			if(!$appId = $this->appid($data['platform'])) show_json(LNG('user.bindWxConfigError'), false);
		}
		show_json(http_build_query($post), true, $appId);
	}

	// 获取应用appid
	private function appid($platform){
		$res = $this->apiRequest('appid', array('type' => 'weixin', 'platform' => $platform));
		return $res['code'] ? $res['data'] : '';
	}

	/**
	 * 第三方账号解绑-后端
	 */
	public function unbind() {
		$type = Input::get('type','require', '');
		if(!isset($this->typeList[$type])){
			show_json(LNG('user.bindTypeError'), false);
		}
		$info = Session::get('kodUser');
		if($this->isEmptyPwd($info['userID'])) show_json(LNG('user.unbindWarning'), false);

		Model('User')->startTrans();
		$del = Model('User')->metaSet($info['userID'], $type . self::BIND_META_INFO);
		$del = Model('User')->metaSet($info['userID'], $type . self::BIND_META_UNIONID);
		Model('User')->commit();

		if ($del === false) {
			show_json(LNG('explorer.error'), false);
		}
		$this->updateInfo($info['userID']);
		show_json(LNG('explorer.success'), true);
	}

	/**
	 * 根据unionid判断对应账号是否已绑定
	 * @param type $key
	 * @param type $unionid
	 * @param type $client
	 * @return boolean
	 */
	private function isBind($key, $unionid, $client = 1) {
		// 根据metadata.unionid获取用户信息
		$user = Model('User')->getInfoByMeta($key . self::BIND_META_UNIONID, $unionid);
		if (empty($user)) return false;
		// 后端,要判断该微信/QQ是否已经绑定了其他账号
		// 通过绑定信息获取到的用户，不是当前登录用户，说明已绑定其他账号
		if (!$client) {
			if($user['userID'] != Session::get("kodUser.userID")) {
				return $user['nickName'] ? $user['nickName'] : $user['name'];
			}
			return false;
		}
		// 前端,用户存在,则直接登录
		if($user['status']) Action('user.index')->loginSuccess($user);
		return array($user['status']);	// true
	}

	/**
	 * 第三方信息绑定保存
	 */
	private function bindSave($data, $back=false) {
		// 更新头像、meta信息
		$userID = isset($data['userID']) ? $data['userID'] : Session::get("kodUser.userID");
		if($back) Model("User")->userEdit($userID, array("avatar" => $data['avatar']));

		$metadata = array(
			$data['type'] . self::BIND_META_UNIONID	 => $data['unionid'],
			$data['type'] . self::BIND_META_INFO	 => json_encode($data)
		);
		$ret = Model('User')->metaSet($userID, $metadata);
		if ($ret && !$data['client']) {
			$this->updateInfo($userID);	// 后端绑定，更新用户信息
		}
		return $ret;
	}

	// 更新userInfo缓存
	private function updateInfo($id) {
		Model('User')->cacheFunctionClear('getInfo',$id);
		Session::set('kodUser', Model('User')->getInfo($id));
	}

	/**
	 * 用户是否绑定
	 */
	public function bindMetaInfo(){
		$userInfo = Session::get('kodUser');
		$metaInfo = $userInfo['metaInfo'];
		$bindInfo = array();
		$bindList = array('weixinUnionid', 'qqUnionid', 'githubUnionid');
		foreach($bindList as $bind){
			$type = str_replace('Unionid', '', $bind);
			$bindInfo[$type . 'Bind'] = isset($metaInfo[$bind]) ? 1 : 0;
		}
		// 密码是否为空
		$data = array('bind' => $bindInfo, 'emptyPwd' => 0);
		if(array_sum($bindInfo)){
			$data['emptyPwd'] = (int) $this->isEmptyPwd($userInfo['userID']);
		}
		show_json($data);
	}
	private function isEmptyPwd($userID){
		$info = Model('User')->getInfoSimple($userID);
		return empty($info['password']);
	}
}
