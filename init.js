/* global fox, Plugins, dojo, xhrPost, App, __ */

Plugins.Af_Img_Phash = {
	showSimilar: function(elem) {
		const url = elem.getAttribute("data-check-url");

		const dialog = new fox.SingleUseDialog({
			title: __("Similar images"),
			content: __("Loading, please wait..."),
		});

		const tmph = dojo.connect(dialog, 'onShow', function () {
			dojo.disconnect(tmph);

			xhrPost("backend.php", App.getPhArgs("af_img_phash", "showsimilar", {url: url}) , (transport) => {
				dialog.attr('content', transport.responseText);
			});
		});

		dialog.show();
	}
};

