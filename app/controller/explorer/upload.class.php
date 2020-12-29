<?php
/*
* @link http://kodcloud.com/
* @author warlee | e-mail:kodcloud@qq.com
* @copyright warlee 2014.(Shanghai)Co.,Ltd
* @license http://kodcloud.com/tools/license/license.txt
*/

class explorerUpload extends Controller{
	private $model;
	public function __construct(){
		parent::__construct();
		$this->model = Model("Source");
	}
	public function pathAllowReplace($path){
		$notAllow = array('\\', ':', '*', '?', '"', '<', '>', '|');//去除/;
		return str_replace($notAllow,'_',$path);
	}
	
	
	public function fileUploadTemp(){
		$this->in["chunkSize"] = '0';
		$this->in["size"] = '0';
		
		$uploader  = new Uploader();
		$localFile = $uploader->upload();
		$uploader->statusSet(false);
		return $localFile;
	}
	
	/**
	 * 上传,三个阶段
	 * checkMd5:上传前;秒传处理、前端上传处理
	 * uploadLinkSuccess: 前端上传完成处理;
	 * 其他: 正常通过后端上传上传到后端;
	 */
	public function fileUpload(){
		$this->authorizeCheck();
		$uploader = new Uploader();
		$savePath = $this->in['path'];
		if ( $this->in['fullPath'] ) {//带文件夹的上传
			$fullPath = KodIO::clear($this->in['fullPath']);
			$fullPath = $this->pathAllowReplace($fullPath);
			$fullPath = get_path_father($fullPath);
			$savePath = IO::mkdir(rtrim($savePath,'/').'/'.$fullPath);
		}
		
		$uploader->fileName = $this->pathAllowReplace($uploader->fileName);
		$savePath = rtrim($savePath,'/').'/'.$uploader->fileName;
		$repeat = Model('UserOption')->get('fileRepeat');
		$repeat = isset($this->in['repeatType']) ? $this->in['repeatType'] : $repeat;
		
		// 第三方存储上传完成
		if( isset($this->in['uploadLinkSuccess']) ){
			$this->fileUploadByClient($savePath,$repeat);
		}
		if( isset($this->in['checkType']) ){
			$this->fileUploadCheckExist($uploader,$savePath,$repeat);
		}
		
		// 通过服务器上传;
		$localFile = $uploader->upload();
		$path = IO::upload($savePath,$localFile,true,$repeat);//本地到本地则用move的方式;
		$uploader->clearData();//清空上传临时文件;
		// pr($localFile,$path,$savePath,$uploader,$this->in);exit;
		if($path){
			show_json(LNG("explorer.upload.success"),true,$path);
		}else{
			show_json(LNG("explorer.upload.error"),false);
		}
	}
	
	// 第三方上传获取凭证
	private function authorizeCheck(){
		if( !isset($this->in['authorize']) ) return;
		$inPath = $this->in['path'];
		if(IO::isType($inPath, "DB")){
			$path = KodIO::defaultIO().$inPath;
		}else{
			$pathBase = substr($inPath, 0, stripos($inPath, '/'));
			$path = (!$pathBase ? $inPath : $pathBase) . '/' . $inPath;
		}
		$paramMore = $this->getParamMore();
		$result = IO::multiUploadAuthData($path, $paramMore);
		show_json($result, true);
	}

	// 获取paramMore，兼容json和数组
	private function getParamMore(){
		if(!isset($this->in['paramMore'])) return array();
		if(is_array($this->in['paramMore'])) return $this->in['paramMore'];
		if($paramMore = json_decode($this->in['paramMore'], true)) return $paramMore;
		return array();
	}

	//秒传及断点续传处理
	private function fileUploadCheckExist($uploader,$savePath,$repeat){
		$size = $this->in['size'];
		$isSource   = false;
		$hashSimple = isset($this->in['checkHashSimple']) ? $this->in['checkHashSimple']:false;
		$hashMd5    = isset($this->in['checkHashMd5']) ? $this->in['checkHashMd5']:false;
		if(IO::isType($savePath, "DB") && $hashSimple ){
			$isSource = true;
			$file = Model("File")->findByHash($hashSimple,$hashMd5);
		}else{
			$file = array('hashSimple' => null, 'hashMd5' => null);	// 非绑定数据库存储不检查秒传
		}
		
		$default  = KodIO::defaultDriver();
		$infoData = array(
			"checkChunkArray"	=> $uploader->checkChunk(),
			"checkFileHash"		=> array(
				"hashSimple"=>$file['hashSimple'],
				"hashMd5"	=>$file['hashMd5']
			),
			"uploadLinkInfo"	=> IO::uploadLink($savePath, $size),//前端上传信息获取;
			"uploadToKod"		=> $isSource,
			"kodDriverType"		=> $default['driver'],
		);
		$linkInfo = &$infoData['uploadLinkInfo'];
		if(isset($linkInfo['host'])){
		    $linkInfo['host'] = str_replace("http://",'//',$linkInfo['host']);
		}
		
		if( $this->in['checkType'] == 'matchMd5' && 
			!empty($this->in['checkHashMd5']) && 
			!empty($file['hashMd5']) && 
			$this->in['checkHashMd5'] == $file['hashMd5']
		){
			$path = IO::uploadFileByID($savePath,$file['fileID'],$repeat);
			$uploader->clearData();//清空上传临时文件;
			show_json(LNG('explorer.upload.secPassSuccess'),true,$path);
		}else{
			show_json(LNG('explorer.success'),true,$infoData);
		}
	}

	/**
	 * 前端上传,完成后记录并处理;
	 * 
	 * $key是完整路径，type为DB（即为默认io）时，$savePath={source:x}/$key，
	 * 获取默认io判断：{io:n}/$key
	 * 否则，$savePath={io:x}/$key，直接判断
	 */
	private function fileUploadByClient($savePath,$repeat){
		$key  		= $this->in['key'];
		$paramMore  = $this->getParamMore();
		$default  	= KodIO::defaultDriver();
		$basePath 	= trim($default['config']['basePath'], '/');
		$tmpPath  	= (!empty($basePath)) ? str_replace($basePath, '', $key) : $key;
		$remotePath = KodIO::defaultIO() . trim($tmpPath, '/');

		// 耗时操作;
		if(!IO::exist($remotePath)){
			show_json(LNG("explorer.upload.error"), false);
		}
		$path = IO::addFileByRemote($savePath, $remotePath,$paramMore,$this->in['name'],$repeat);
		show_json(LNG("explorer.upload.success"),true,$path);
	}

	// 远程下载
	public function serverDownload() {
		if(!$this->in['uuid']){
			$this->in['uuid'] = md5($this->in['url']);
		}
		$uuid = 'download_'.$this->in['uuid'];
		$this->serverDownloadCheck($uuid);
		$url 	= $this->in['url'];
		$path   = rtrim($this->in['path'],'/').'/';
		$header = url_header($url);
		if (!$header){
			show_json(LNG('download_error_exists'),false);
		}

		$filename = _get($this->in,'name',$header['name']);
		$filename = unzip_filter_ext($filename);
		$saveFile = TEMP_FILES.md5($uuid);
		mk_dir(TEMP_FILES);
		Session::set($uuid,array(
			'supportRange'	=> $header['supportRange'],
			'length'		=> $header['length'],
			'path'			=> $saveFile,
			'name'			=> $filename,
		));
		$result = Downloader::start($url,$saveFile);
		if($result['code']){
			$outPath = IO::move($saveFile,$path,REPEAT_REPLACE);
			$outPath = IO::rename($outPath,$filename);
			$pathInfo = IO::info($outPath);
			show_json(LNG('explorer.downloaded'),true,$pathInfo);
		}else{
			show_json($result['data'],false);
		}
	}
	private function serverDownloadCheck($uuid){
		if ($this->in['type'] == 'percent') {
			$data = Session::get($uuid);
			if (!$data) show_json('uuid error',false);
			$result = array(
				'supportRange' => $data['supportRange'],
				'uuid'      => $this->in['uuid'],
				'length'    => intval($data['length']),
				'name'		=> $data['name'],
				'size'      => intval(@filesize($data['path'].'.downloading')),
				'time'      => mtime()
			);
			show_json($result);
		}else if($this->in['type'] == 'remove'){//取消下载;文件被删掉则自动停止
			if($data){
				IO::remove($data['path'].'.downloading');
				IO::remove($data['path'].'.download.cfg');
				Session::remove($uuid);
			}
			show_json('');
		}
	}
}