kodReady.push(function () {
	var isAllow = parseInt("{{isAllow}}");
	Events.bind("admin.leftMenu.before",function(menuList){
		menuList.push({
			title:LNG['webdav.meta.name'],
			icon:"icon-hard-drive1",
			link:"admin/storage/webdav",
			after:'admin/storage/index',//after/before; 插入菜单所在位置;
			sort:100,
			pluginName:"{{pluginName}}",
		});
	});
	
	Events.bind("user.leftMenu.before",function(menuList){
		if(!isAllow) return;
		menuList.push({
			title:LNG['webdav.meta.name'],
			icon:"icon-hard-drive1",
			link:"setting/user/webdav",
			pluginName:"{{pluginName}}",
			sort:100,
			fileSrc:'{{pluginHost}}static/user.js',
		});
	});
});