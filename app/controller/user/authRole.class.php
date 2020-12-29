<?php
/*
 * @link http://kodcloud.com/
 * @author warlee | e-mail:kodcloud@qq.com
 * @copyright warlee 2014.(Shanghai)Co.,Ltd
 * @license http://kodcloud.com/tools/license/license.txt
 */

/**
 * 用户角色，全局权限拦截；
 */
class userAuthRole extends Controller {
	protected static $authRole;
	function __construct() {
		parent::__construct();
	}
	public function authCanDownload(){
		return $this->authCan('explorer.download');
	}
	public function isRoot(){return !!$GLOBALS['isRoot'];} 
	public function authCanSearch(){return $this->authCan('explorer.search');}
	public function authCanRead(){return $this->authCan('explorer.view');}
	public function authCanEdit(){return $this->authCan('explorer.edit');}
	public function authCan($action){
		if($this->isRoot()) return true;
		$userRole = $this->userRoleAuth();
		$action   = strtolower($action);
		if($userRole['roleList'][$action] == 1){
			return true;
		}
		return false;
	}
	
	private function requestCheck(){
		$action   = strtolower(ACTION);
		$theMod   = strtolower(MOD);
		$notAllow = array(
			'user.view.options',
			'user.index.accessTokenGet',
		);
		foreach ($notAllow as &$val) {
			$val = strtolower($val);
		}
		// 不允许jsonp的方法;
		if( $theMod == 'explorer' || 
			$theMod == 'admin' ||
			in_array($action,$notAllow) 			
		){
			$GLOBALS['config']['jsonpAllow'] = false;
		}
		
		// 强制使用POST的接口;
		$mustPost = array(
			'user.setting.setConfig',
			'user.setting.setUserInfo',
			'user.regist.regist',
		);
		foreach ($mustPost as &$val) {
			$val = strtolower($val);
		}
		if( $theMod == 'admin' ||
			in_array($action,$mustPost)
		){
			if(!GLOBAL_DEBUG && REQUEST_METHOD != 'POST'){
				return show_json('REQUEST_METHOD must be POST!',false);
			}
		}
	}
	
	// 未登录：允许的 控制器方法;
	// 已登录：不允许的 控制器&方法；
	// 其他的可以通过内部Action进行方法调用转发；
	public function autoCheck(){
		$theMod 	= strtolower(MOD);
		$theST 		= strtolower(ST);
		$theAction 	= strtolower(ACTION);
		$authNotNeedLogin = $this->config['authNotNeedLogin'];
		foreach ($authNotNeedLogin as &$val) {
			$val = strtolower($val);
		}
		$this->requestCheck();
		if(in_array($theAction,$authNotNeedLogin)) return;
		foreach ($authNotNeedLogin as $value) {
			$item = explode('.',$value); //MOD,ST,ACT
			if( count($item) == 2 && 
				$item[0] === $theMod && $item[1] === '*'){
				return;
			}
			if( count($item) == 3 && 
				$item[0] === $theMod && $item[1] === $theST  &&$item[2] === '*'){
				return;
			}
		}
		// 排除不需要登录的方法；其他的都需要登录
		$user = Session::get("kodUser");
		if(!$user){
			show_json(LNG('user.loginFirst'),ERROR_CODE_LOGOUT);
		}
		//系统管理员不受权限限制
		if($this->isRoot()) return true;
		
		$userRole = $this->userRoleAuth();
		$allowAction = $userRole['allowAction'];
		// pr($allowAction[$theAction],$theAction,$user,$userRole);exit;
		if(!$allowAction[$theAction]){ //不存在该方法或
			show_json(LNG('explorer.noPermissionAction'),false,1004);
		}
	}
	
	// 用户权限解析处理;处理成最终动作
	public function userRoleAuth(){
		if(self::$authRole) return self::$authRole;
		$authAllowAction 	= $this->config['authAllowAction'];
		$roleAction 		= $this->config['authRoleAction'];
		
		$user = Session::get('kodUser');
		if(!$user || !$user['roleID']) return false;
		$roleInfo = Model('SystemRole')->listData($user['roleID']);
		
		$userRoleAllow 	= explode(',',trim($roleInfo['auth'],','));	
		$authRoleList 	= array();
		$allowAction 	= array();
		foreach ($authAllowAction as $action) {
			$allowAction[strtolower($action)] = 1;
		}
		foreach ($roleAction as $role => $modelActions) {
			$enable = intval(in_array($role,$userRoleAllow));
			$authRoleList[$role] = $enable;
			if(!$modelActions || !is_array($modelActions)){
				continue;
			}
			$actionArray = array();
			foreach ($modelActions as $controller => $stActions) {
				if(!$stActions) continue;
				$stActions = explode(',',trim($stActions,','));
				foreach ($stActions as $action) {
					$actionArray[] = $controller.'.'.$action;
				}
			}
			foreach ($actionArray as $action) {
				$action = strtolower($action);//统一转为小写
				if(!isset($allowAction[$action])){
					$allowAction[$action] = $enable;
				}
			}
		}
		self::$authRole = array(
			'info'			=> $roleInfo,
			'allowAction'	=> $allowAction,
			'roleList'		=> $authRoleList
		);
		return self::$authRole;
	}
}
