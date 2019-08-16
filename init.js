Plugins.Af_Img_Phash = {
	showSimilar: function(elem) {
		try {

			const url = elem.getAttribute("data-check-url");
			const query = "backend.php?op=pluginhandler&plugin=af_img_phash&method=showsimilar&param=" + encodeURIComponent(url);

			if (dijit.byId("phashSimilarDlg"))
				dijit.byId("phashSimilarDlg").destroyRecursive();

			const dialog = new dijit.Dialog({
				id: "phashSimilarDlg",
				title: __("Similar images"),
				style: "width: 600px",
				execute: function() {
					//
				},
				href: query,
			});

			dialog.show();

		} catch (e) {
			exception_error("showSimilar", e);
		}
	}
};

