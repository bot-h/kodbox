kodReady.push(function(){
	if(!$.supportCanvas()) return;
	Events.bind('explorer.kodApp.before',function(appList){
		appList.push({
			name:"photoSwipe",
			title:"photoSwipe Image",
			ext:"{{config.fileExt}}",
			sort:"{{config.fileSort}}",
			icon:"x-item-icon x-jpg",
			callback:function(path,ext,name){
				var appStatic = "{{pluginHost}}static/";
				var appStaticDefault = "{{pluginHostDefault}}static/";
				requireAsync(appStatic+'page.js',function(app){
					app(path,ext,name,appStatic,appStaticDefault);
					bindTouch();
				});
			}
		});
		
		if($.isWap){
			$.addStyle(".pswp--supports-fs .pswp__button--fs{display:none !important;}")
		}
	});
	var bindTouch = function(){
		$('.pswp_content').bind('touchstart',function(e){
			return stopPP(e);
		})
	}
});