<?php 
/*
* @link http://kodcloud.com/
* @author warlee | e-mail:kodcloud@qq.com
* @copyright warlee 2014.(Shanghai)Co.,Ltd
* @license http://kodcloud.com/tools/license/license.txt
*/

class explorerUserShare extends Controller{
	private $model;
	function __construct(){
		parent::__construct();
		$this->model  = Model('Share');
	}

	/**
	 * 通过文档获取分享；没有则返回false;
	 */
	public function get(){
		$path = Input::get('path','require');
		$sourceID = KodIO::sourceID($path);
		$share = $this->model->getInfoByPath($sourceID);
		show_json($share);
	}

	/**
	 * 我的分享列表
	 * 点击进入对应文档目录；
	 */
	public function myShare(){
		$list = $this->model->listData();
		$list = $this->_listApplySource($list);
		return $list;
	}
	private function _listApplySource($list){
		$sourceArray = array_to_keyvalue($list['list'],'','sourceID');
		$sourceShare = array_to_keyvalue($list['list'],'sourceID');
		if(!$sourceArray) return;
		
		$where = array(
			'sourceID' => array('in',$sourceArray),
			'isDelete' => 0,
		);
		$listSource  = Model('Source')->listSource($where);
		foreach ($listSource as $key => &$keyList) {
			if($key != 'folderList' && $key != 'fileList' ) continue;
			foreach ($keyList as &$source) {
				$source['shareInfo'] = $sourceShare[$source['sourceID']];
			}
		}
		return $listSource;
	}
	
	public function shareToMe(){
		$list = $this->model->listToMe();
		$sourceArray = array_to_keyvalue($list['list'],'','sourceID');//分享列表的文档id 数组
		$sourceShare = array_to_keyvalue($list['list'],'sourceID');
		if(!$sourceArray) return;
		
		$where = array(
			'sourceID' => array('in',$sourceArray),
			'isDelete' => 0,
		);
		$listSource  = Model('Source')->listSource($where);//权限检测
		foreach ($listSource as $key => &$keyList) {
			if($key != 'folderList' && $key != 'fileList' ) continue;
			foreach ($keyList as &$source) {
				$shareInfo = $sourceShare[$source['sourceID']];
				$source = $this->_shareItemeParse($source,$shareInfo);
			}
		}
		return $listSource;
	}
	
	public function sharePathInfo($shareID,$sourceID){
		$shareInfo	= $this->model->getInfo($shareID);
		$sourceInfo = Model('Source')->pathInfo($sourceID);
		if(!$this->shareIncludeCheck($shareInfo,$sourceInfo)) return false;
		$sourceInfo = $this->_shareItemeParse($sourceInfo,$shareInfo);
		return $sourceInfo;
	}
	
	// 检测附带文档是否归属于该分享;
	private function shareIncludeCheck($shareInfo,$sourceInfo){
		// pr_trace($shareInfo,$sourceInfo);exit;
		if(!$shareInfo || !$sourceInfo) return false;
		
		$shareSource = $shareInfo['sourceInfo'];
		// 分享目标为文件,追加字内容必须是自己;
		if( $shareSource['type'] == 'file' &&
			$shareSource['sourceID'] != $sourceInfo['sourceID']){
			return false; 
		}
		if( $shareSource['type'] == 'folder' &&
			strpos($sourceInfo['parentLevel'],$shareSource['parentLevel']) !== 0 ){
			return false; 
		}
		return true;
	}
	
	
	public function sharePathList($parseInfo){
		$shareID  	= $parseInfo['id'];
		$param    	= explode('/',trim($parseInfo['param'],'/'));
		$sourceID 	= $param[0];
		$shareInfo	= $this->model->getInfo($shareID);
		$sourceInfo = Model('Source')->pathInfo($sourceID);
		if(!$this->shareIncludeCheck($shareInfo,$sourceInfo)) return false;
		
		$list  = Model('Source')->listSource(array('parentID' => $sourceID));
		foreach ($list as $key => &$keyList) {
			if($key != 'folderList' && $key != 'fileList' ) continue;
			foreach ($keyList as &$source) {
				$source = $this->_shareItemeParse($source,$shareInfo);
			}
		}

		$list['current'] = $this->_shareItemeParse($sourceInfo,$shareInfo);
		// pr($parent,$shareInfo,$list);exit;
		return $list;
	}

	/**
	 * 处理source到分享列表
	 * 去除无关字段；处理parentLevel，pathDisplay
	 */
	public function _shareItemeParse($source,$share){
		$user = Model('User')->getInfoSimpleOuter($share['userID']);
		$source['auth']			= Model("SourceAuth")->authMake($share['authList']);//覆盖原来文档权限;每次进行计算
		$source['shareUser']	= $user;
		$source['path'] 		= KodIO::makeShare($share['shareID'],$source['sourceID']);
		$source['shareCreateTime'] 	= $share['createTime'];
		$source['shareModifyTime'] 	= $share['modifyTime'];
		$source['shareID'] = $share['shareID'];
		
		$parentLevel = explode(',',trim($source['parentLevel'],','));
		$pathDisplay = explode('/',trim($source['pathDisplay'],'/'));
		// unset($source['sourceInfo']);

		$index = array_search($share['sourceID'],$parentLevel);
		$parentLevel = array_slice($parentLevel,$index);
		$pathDisplay = array_slice($pathDisplay,$index-1);
		$pathDisplay[0] = _get($share,'sourceInfo.name');

		
		// 分享者名字;
		$displayUser = $user['nickName'] ? $user['nickName']:$user['name'];
		$displayUser = '['.$displayUser.']'.LNG('common.share').'-';
		if($share['userID'] == USER_ID){
			$displayUser = '['.LNG('explorer.toolbar.myShare').']-';
		}
		
		$source['parentLevel'] = implode(',',$parentLevel);
		$source['pathDisplay'] = $displayUser.implode('/',$pathDisplay);
		if($index == false){
			$source['parentLevel'] = $share['sourceID'];
		}
		if($source['type'] == 'folder'){
			$source['pathDisplay'] .= '/';
		}
		
		// 读写权限;
		if($source['auth']){
			$source['isWriteable'] = AuthModel::authCheckEdit($source['auth']['authValue']);
			$source['isReadable']  = AuthModel::authCheckView($source['auth']['authValue']);
		}
		return $source;
	}
	
	/**
	 * 添加分享;
	 */
	public function add(){
		$data = $this->_getParam('sourceID');
		$sourceID = KodIO::sourceID($data['path']);
		$result = $this->model->shareAdd($sourceID,$data);
		if(!$result) show_json(LNG('explorer.error'),false);

		$shareInfo = $this->model->getInfo($result);
		show_json($shareInfo,true);
	}

	/**
	 * 编辑分享
	 */
	public function edit(){
		$data = $this->_getParam('shareID');
		$result = $this->model->shareEdit($data['shareID'],$data);
		if(!$result) show_json(LNG('explorer.error'),false);

		$shareInfo = $this->model->getInfo($data['shareID']);
		show_json($shareInfo,true);
	}
	
	/**
	 * 添加/编辑分享;
	 * shareType: 
	 * 		0: 暂未指定分享
	 * 		1: 内部指定用户分享
	 * 		2: 外链分享
	 * 		3: 内部指定、外链分享同时包含
	 * 
	 * 外链分享; title,password,timeTo,options
	 * authTo: [
	 * 		{"targetType":"1","targetID":"23","authID":"1"},
	 * 		{"targetType":"2","targetID":"3","authDefine":"512"}
	 * ]
	 * param: title,password,timeTo,options
	 */
	private function _getParam($key='shareID'){
		$keys = array(
			"isLink"	=> array("check"=>"bool",	"default"=>0),
			"isShareTo"	=> array("check"=>"bool",	"default"=>0),
			"title"		=> array("check"=>"require","default"=>''),
			"password"	=> array("default"=>''),//密码设置为空处理;
			"timeTo"	=> array("check"=>"require","default"=>0),
			"options"	=> array("check"=>"json",	"default"=>''),
			"authTo"	=> array("check"=>"json", 	"default"=>''),
		);
		//修改，默认值为null不修改；
		if($key == 'shareID'){
			$keys['shareID'] = array("check"=>"int");
			foreach ($keys as $key => &$value) {
				$value['default'] = null;
			}
		}else{//添加时，目录值
			$keys['path'] = array("check"=>"require");
		}
		$data = Input::getArray($keys);
		return $data;
	}

	/**
	 * 删除
	 */
	public function del() {
		$list  = Input::get('dataArr','json');
		$res   = $this->model->remove($list);
		$msg  = !!$res ? LNG('explorer.success'): LNG('explorer.error');
		show_json($msg,!!$res);
	}
}
