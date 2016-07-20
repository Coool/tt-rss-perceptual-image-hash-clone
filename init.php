<?php
require_once "imagehash/ImageHash.php";
require_once "imagehash/Implementation.php";
require_once "imagehash/Implementations/PerceptualHash.php";

use Jenssegers\ImageHash\Implementations\PerceptualHash;
use Jenssegers\ImageHash\ImageHash;

class Af_Zz_Img_Phash extends Plugin {

	private $host;
	private $default_domains_list = "imgur.com i.reddituploads.com i.redd.it";
	private $default_similarity = 3;
	private $cache_dir;

	function about() {
		return array(1.0,
			"Filter duplicate images using perceptual hashing (requires GD, PostgreSQL)",
			"fox");
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/init.js");
	}

	function save() {
		$similarity = (int) $_POST["similarity"];
		$domains_list = $_POST["domains_list"];

		$enable_globally = checkbox_to_sql_bool($_POST["enable_globally"]) == "true";

		if ($similarity < 0) $similarity = 0;

		$this->host->set($this, "similarity", $similarity);
		$this->host->set($this, "enable_globally", $enable_globally);
		$this->host->set($this, "domains_list", $domains_list);

		echo T_sprintf("Data saved (%s, %s, %d)", $similarity, $domains_list, $enable_globally);
	}

	function init($host) {
		$this->host = $host;

		$this->cache_dir = CACHE_DIR . "/af_zz_img_phash/";

		if (!is_dir($this->cache_dir)) {
			mkdir($this->cache_dir);
			chmod($this->cache_dir, 0777);
		}

		if (is_dir($this->cache_dir)) {

			if (!is_writable($this->cache_dir))
				chmod($this->cache_dir, 0777);
		}

		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
		$host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);

	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__('Filter similar images')."\">";

		if (DB_TYPE != "pgsql") {
			print_error("Database type not supported.");
		}

		$similarity = (int) $this->host->get($this, "similarity");
		$domains_list = $this->host->get($this, "domains_list");

		$enable_globally = $this->host->get($this, "enable_globally");

		if (!$similarity) $similarity = $this->default_similarity;
		if (!$domains_list) $domains_list = $this->default_domains_list;

		$enable_globally_checked = $enable_globally ? "checked" : "";

		print "<form dojoType=\"dijit.form.Form\">";

		print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
			evt.preventDefault();
			if (this.validate()) {
				console.log(dojo.objectToQuery(this.getValues()));
				new Ajax.Request('backend.php', {
					parameters: dojo.objectToQuery(this.getValues()),
					onComplete: function(transport) {
						notify_info(transport.responseText);
					}
				});
				//this.reset();
			}
			</script>";

		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"save\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"af_zz_img_phash\">";

		print "<p>" . format_notice("Lower hamming distance value indicates images being more similar.");

		print "<h3>" . __("Global settings") . "</h3>";

		print "<table>";

		print "<tr><td width=\"40%\">".__("Limit to domains (space-separated):")."</td>";
		print "<td>
			<input dojoType=\"dijit.form.ValidationTextBox\"
			placeholder=\"imgur.com\"
			required=\"1\" name=\"domains_list\" value=\"$domains_list\"></td></tr>";
		print "<tr><td width=\"40%\">".__("Maximum hamming distance:")."</td>";
		print "<td>
			<input dojoType=\"dijit.form.ValidationTextBox\"
			placeholder=\"5\"
			required=\"1\" name=\"similarity\" value=\"$similarity\"></td></tr>";
		print "<tr><td width=\"40%\">".__("Enable for all feeds:")."</td>";
		print "<td>
			<input dojoType=\"dijit.form.CheckBox\"
			$enable_globally_checked name=\"enable_globally\"></td></tr>";

		print "</table>";

		print "<p><button dojoType=\"dijit.form.Button\" type=\"submit\">".
			__("Save")."</button>";

		print "</form>";

		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!array($enabled_feeds)) $enabled_feeds = array();

		$enabled_feeds = $this->filter_unknown_feeds($enabled_feeds);
		$this->host->set($this, "enabled_feeds", $enabled_feeds);

		if (count($enabled_feeds) > 0) {
			print "<h3>" . __("Currently enabled for (click to edit):") . "</h3>";

			print "<ul class=\"browseFeedList\" style=\"border-width : 1px\">";
			foreach ($enabled_feeds as $f) {
				print "<li>" .
					"<img src='images/pub_set.png'
						style='vertical-align : middle'> <a href='#'
						onclick='editFeed($f)'>".
					getFeedTitle($f) . "</a></li>";
			}
			print "</ul>";
		}

		print "</div>";
	}

	function hook_prefs_edit_feed($feed_id) {
		print "<div class=\"dlgSec\">".__("Similar images")."</div>";
		print "<div class=\"dlgSecCont\">";

		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!array($enabled_feeds)) $enabled_feeds = array();

		$key = array_search($feed_id, $enabled_feeds);
		$checked = $key !== FALSE ? "checked" : "";

		print "<hr/><input dojoType=\"dijit.form.CheckBox\" type=\"checkbox\" id=\"phash_similarity_enabled\"
			name=\"phash_similarity_enabled\"
			$checked>&nbsp;<label for=\"phash_similarity_enabled\">".__('Filter similar images')."</label>";

		print "</div>";
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->host->get($this, "enabled_feeds");
		if (!is_array($enabled_feeds)) $enabled_feeds = array();

		$enable = checkbox_to_sql_bool($_POST["phash_similarity_enabled"]) == 'true';
		$key = array_search($feed_id, $enabled_feeds);

		if ($enable) {
			if ($key === FALSE) {
				array_push($enabled_feeds, $feed_id);
			}
		} else {
			if ($key !== FALSE) {
				unset($enabled_feeds[$key]);
			}
		}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
	}

	private function rewrite_duplicate($doc, $img) {
		$src = $img->getAttribute("src");

		$p = $doc->createElement('p');

		$a = $doc->createElement("a");
		$a->setAttribute("href", $src);
		$a->appendChild(new DOMText("$src"));

		$b = $doc->createElement("a");
		$b->setAttribute("href", "#");
		$b->setAttribute("onclick", "showPhashSimilar(this)");
		$b->setAttribute("data-check-url", $src);
		$b->appendChild(new DOMText("(phash)"));

		$p->appendChild($a);
		$p->appendChild(new DOMText(" "));
		$p->appendChild($b);

		$img->parentNode->replaceChild($p, $img);
	}

	function hook_article_filter($article) {

		if (DB_TYPE != "pgsql") return $article;

		$enable_globally = $this->host->get($this, "enable_globally");
		$domains_list = $this->host->get($this, "domains_list");

		if (!$domains_list) $domains_list = $this->default_domains_list;

		$domains_list = explode(" ", $domains_list);

		if (!$enable_globally) {
			$enabled_feeds = $this->host->get($this, "enabled_feeds");
			$key = array_search($article["feed"]["id"], $enabled_feeds);
			if ($key === FALSE) return $article;
		}

		$owner_uid = $article["owner_uid"];

		$article_guid = db_escape_string($article["guid_hashed"]);

		$doc = new DOMDocument();

		if (@$doc->loadHTML($article["content"])) {
			$xpath = new DOMXPath($doc);

			$imgs = $xpath->query("//img[@src]");

			foreach ($imgs as $img) {

				$src = $img->getAttribute("src");
				$src = rewrite_relative_url($article["link"], $src);

				// let's absolutize schema-less urls to reduce database clutter a bit
				if (strpos($src, "//") === 0)
					$src = "https:" . $src;

				$domain_found = $this->check_src_domain($src, $domains_list);

				if ($domain_found) {

					_debug("phash: checking $src");

					$src_escaped = db_escape_string($src);

					$result = db_query("SELECT id FROM ttrss_plugin_img_phash_urls WHERE
					owner_uid = $owner_uid AND url = '$src_escaped' LIMIT 1");

					if (db_num_rows($result) != 0) {
						_debug("phash: url already stored, not processing");
						continue;
					}

					_debug("phash: downloading and calculating hash...");

					if (is_writable($this->cache_dir)) {
						$cached_file = $this->cache_dir . "/" . sha1($src) . ".png";

						if (!file_exists($cached_file) || filesize($cached_file) == 0) {
							$data = fetch_file_contents(array("url" => $src));

							if ($data) {
								file_put_contents($cached_file, $data);
							}
						} else {
							_debug("phash: reading from local cache: $cached_file");

							$data = file_get_contents($cached_file);
						}
					} else {
						_debug("phash: cache directory is not writable");

						$data = fetch_file_contents(array("url" => $src));
					}

					if ($data) {

						$implementation = new PerceptualHash();
						$hasher = new ImageHash($implementation);

						$data_resource = imagecreatefromstring($data);

						if ($data_resource) {
							$hash = $hasher->hash($data_resource);

							_debug("phash: calculated perceptual hash: $hash");

							if ($hash) {
								$hash_escaped = db_escape_string($hash);

								db_query("INSERT INTO ttrss_plugin_img_phash_urls (url, article_guid, owner_uid, phash) VALUES
									('$src_escaped', '$article_guid', $owner_uid, x'$hash_escaped'::bigint)");
							}

						} else {
							_debug("phash: unable to load image: $src");
						}

					} else {
						_debug("phash: unable to fetch: $src");
					}
				}
			}

		}

		return $article;
	}

	function api_version() {
		return 2;
	}

	private function filter_unknown_feeds($enabled_feeds) {
		$tmp = array();

		foreach ($enabled_feeds as $feed) {

			$result = db_query("SELECT id FROM ttrss_feeds WHERE id = '$feed' AND owner_uid = " . $_SESSION["uid"]);

			if (db_num_rows($result) != 0) {
				array_push($tmp, $feed);
			}
		}

		return $tmp;
	}

	function hook_render_article($article) {

		return $this->hook_render_article_cdm($article);
	}

	function hook_render_article_cdm($article) {

		$owner_uid = $_SESSION["uid"];

		$doc = new DOMDocument();

		$domains_list = $this->host->get($this, "domains_list");

		if (!$domains_list) $domains_list = $this->default_domains_list;

		$domains_list = explode(" ", $domains_list);

		$need_saving = false;

		$similarity = (int) $this->host->get($this, "similarity");
		if (!$similarity) $similarity = $this->default_similarity;

		$article_guid = db_escape_string($article["guid"]);

		if (@$doc->loadHTML($article["content"])) {
			$xpath = new DOMXPath($doc);

			$imgs = $xpath->query("//img[@src]");

			foreach ($imgs as $img) {

				$src = $img->getAttribute("src");
				$src = rewrite_relative_url($article["link"], $src);

				// let's absolutize schema-less urls to reduce database clutter a bit
				if (strpos($src, "//") === 0)
					$src = "https:" . $src;

				$domain_found = $this->check_src_domain($src, $domains_list);

				if ($domain_found) {
					$src_escaped = db_escape_string($src);

					// check for URL duplicates first

					$result = db_query("SELECT id FROM ttrss_plugin_img_phash_urls WHERE
							owner_uid = $owner_uid AND
							url = '$src_escaped' AND
							article_guid != '$article_guid' LIMIT 1");

					if (db_num_rows($result) > 0) {
						$need_saving = true;

						$this->rewrite_duplicate($doc, $img);

						continue;
					}

					// check using perceptual hash duplicates

					$result = db_query("SELECT phash FROM ttrss_plugin_img_phash_urls WHERE
						owner_uid = $owner_uid AND
						url = '$src_escaped' LIMIT 1");

					if (db_num_rows($result) > 0) {
						$phash = db_escape_string(db_fetch_result($result, 0, "phash"));

						//$similarity = 15;

						$result = db_query("SELECT COUNT(*) AS csim FROM ttrss_plugin_img_phash_urls WHERE
							owner_uid = $owner_uid AND
							url != '$src_escaped' AND
							article_guid != '$article_guid' AND
							ttrss_plugin_img_phash_bitcount($phash # phash) <= $similarity");

						$csim = db_fetch_result($result, 0, "csim");

						if ($csim > 0) {
							$need_saving = true;

							$this->rewrite_duplicate($doc, $img);
						}
					}
				}
			}
		}

		if ($need_saving) $article["content"] = $doc->saveXML();

		return $article;
	}


	function hook_house_keeping() {
		$files = glob($this->cache_dir . "/*.png", GLOB_BRACE);

		foreach ($files as $file) {
			if (filemtime($file) < time() - 86400*14) {
				unlink($file);
			}
		}
	}

	private function check_src_domain($src, $domains_list) {
		$src_domain = parse_url($src, PHP_URL_HOST);

		foreach ($domains_list as $domain) {
			if (strstr($src_domain, $domain) !== FALSE) {
				return true;
			}
		}

		return false;
	}

	function showsimilar() {
		$url = db_escape_string($_REQUEST["param"]);
		$url_htmlescaped = htmlspecialchars($url);

		$owner_uid = $_SESSION["uid"];

		print "<img style='float : right; max-width : 64px; max-height : 64px; height : auto; width : auto;' src=\"$url_htmlescaped\">";

		print "<h2><a target=\"_blank\" href=\"$url_htmlescaped\">$url</a></h2>";

		$result = db_query("SELECT phash FROM ttrss_plugin_img_phash_urls WHERE
			owner_uid = $owner_uid AND
			url = '$url' LIMIT 1");

		if (db_num_rows($result) != 0) {
			$phash = db_escape_string(db_fetch_result($result, 0, "phash"));

			print "<p>Perceptual hash: " . sprintf("%x", $phash) . "</p>";

			$result = db_query("SELECT url, ttrss_plugin_img_phash_bitcount($phash # phash) AS distance
				FROM ttrss_plugin_img_phash_urls WHERE
				url != '$url' ORDER BY distance LIMIT 30");

			print "<ul class=\"browseFeedList\" style=\"border-width : 1px\">";

			while ($line = db_fetch_assoc($result)) {
				print "<li>";
				$url = htmlspecialchars($line["url"]);
				$distance = $line["distance"];

				print "<a target=\"_blank\" href=\"$url\">$url</a> ($distance)";

				print "</li>";
			}

			print "</ul>";
		}


	}
}
?>
