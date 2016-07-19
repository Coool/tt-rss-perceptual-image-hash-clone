function showPhashSimilar(elem) {
	try {

		var url = elem.getAttribute("data-check-url");

		var query = "backend.php?op=pluginhandler&plugin=af_zz_img_phash&method=showsimilar&param=" + param_escape(url);

		if (dijit.byId("phashSimilarDlg"))
			dijit.byId("phashSimilarDlg").destroyRecursive();

		dialog = new dijit.Dialog({
			id: "phashSimilarDlg",
			title: __("Similar images"),
			style: "width: 600px",
			execute: function() {

			},
			href: query,
		});

		dialog.show();

	} catch (e) {
		exception_error("showPhashSimilar", e);
	}
}

