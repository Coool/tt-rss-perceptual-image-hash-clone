<?php
require_once "imagehash/ImageHash.php";
require_once "imagehash/Implementation.php";
require_once "imagehash/Implementations/PerceptualHash.php";

use Jenssegers\ImageHash\Implementations\PerceptualHash;
use Jenssegers\ImageHash\ImageHash;

class Af_Zz_Img_Phash extends Plugin {

	private $host;
	private $default_domains_list = "imgur.com i.reddituploads.com pbs.twimg.com i.redd.it i.sli.mg media.tumblr.com";
	private $default_similarity = 2;
	private $cache_max_age = 7;
	private $cache_dir;

	function about() {
		return array(1.0,
			"Filter duplicate images using perceptual hashing (requires GD)",
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
		$host->add_hook($host::HOOK_RENDER_ARTICLE_API, $this);

	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__('Filter similar images')."\">";

		if (DB_TYPE == "pgsql") {
			$result = db_query("select 'unique_1bits'::regproc");
			if (db_num_rows($result) == 0) {
				print_error("Required function from count_bits extension not found.");
			}
		}

		$similarity = (int) $this->host->get($this, "similarity", $this->default_similarity);
		$domains_list = $this->host->get($this, "domains_list", $this->default_domains_list);
		$enable_globally = $this->host->get($this, "enable_globally");

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

		print_hidden("op", "pluginhandler");
		print_hidden("method", "save");
		print_hidden("plugin", "af_zz_img_phash");

		print "<p>" . format_notice("Lower hamming distance value indicates images being more similar.");

		print "<h3>" . __("Global settings") . "</h3>";

		print "<table>";

		print "<tr><td width=\"40%\">".__("Limit to domains (space-separated):")."</td>";
		print "<td>
			<textarea dojoType=\"dijit.form.SimpleTextarea\" style=\"height: 100px;\"
			required=\"1\" name=\"domains_list\">$domains_list</textarea></td></tr>";
		print "<tr><td width=\"40%\">".__("Maximum hamming distance:")."</td>";
		print "<td>
			<input dojoType=\"dijit.form.ValidationTextBox\"
			placeholder=\"5\"
			required=\"1\" name=\"similarity\" value=\"$similarity\"></td></tr>";
		print "<tr><td width=\"40%\">".__("Enable for all feeds:")."</td>";
		print "<td>";
		print_checkbox("enable_globally", $enable_globally);
		print "</td></tr>";

		print "</table>";

		print "<p>"; print_button("submit", __("Save"));

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

	private function rewrite_duplicate($doc, $elem, $api_mode = false) {

		if ($elem->hasAttribute("src")) {
			$uri = $this->absolutize_url($elem->getAttribute("src"));
			$check_uri = $uri;
		} else if ($elem->hasAttribute("poster")) {
			$check_uri = $this->absolutize_url($elem->getAttribute("poster"));

			$video_source = $elem->getElementsByTagName("source")->item(0);

			if ($video_source) {
				$uri = $video_source->getAttribute("src");
			}
		}

		if ($check_uri && $uri) {

			$p = $doc->createElement('p');

			$a = $doc->createElement("a");
			$a->setAttribute("href", $uri);
			$a->setAttribute("target", "_blank");
			$a->appendChild(new DOMText(truncate_middle($uri, 48, "...")));

			$p->appendChild($a);

			if (!$api_mode) {
				$b = $doc->createElement("a");
				$b->setAttribute("href", "#");
				$b->setAttribute("onclick", "showPhashSimilar(this)");
				$b->setAttribute("data-check-url", $this->absolutize_url($check_uri));
				$b->appendChild(new DOMText("(similar)"));

				$p->appendChild(new DOMText(" "));
				$p->appendChild($b);
			}

			$elem->parentNode->replaceChild($p, $elem);
		}
	}

	function hook_article_filter($article) {

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

			$imgs = $xpath->query("//img[@src]|//video[@poster]");

			foreach ($imgs as $img) {

				$src = $img->tagName == "video" ? $img->getAttribute("poster") : $img->getAttribute("src");
				$src = $this->absolutize_url(rewrite_relative_url($article["link"], $src));

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

						$data_resource = @imagecreatefromstring($data);

						if ($data_resource) {
							$hash = $hasher->hash($data_resource);

							_debug("phash: calculated perceptual hash: $hash");

							if ($hash) {
								$hash_escaped = db_escape_string(base_convert($hash, 16, 10));

								db_query("INSERT INTO ttrss_plugin_img_phash_urls (url, article_guid, owner_uid, phash) VALUES
									('$src_escaped', '$article_guid', $owner_uid, '$hash_escaped')");
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

	function hook_render_article_api($headline) {


		return $this->hook_render_article_cdm($headline["headline"], true);
	}

	function hook_render_article_cdm($article, $api_mode = false) {

		if (DB_TYPE == "pgsql") {
			$result = db_query("select 'unique_1bits'::regproc");
			if (db_num_rows($result) == 0) return $article;
		}

		$owner_uid = $_SESSION["uid"];

		$doc = new DOMDocument();

		$domains_list = $this->host->get($this, "domains_list", $this->default_domains_list);

		$domains_list = explode(" ", $domains_list);

		$need_saving = false;

		$similarity = (int) $this->host->get($this, "similarity", $this->default_similarity);

		$article_guid = db_escape_string($article["guid"]);

		if (@$doc->loadHTML($article["content"])) {
			$xpath = new DOMXPath($doc);

			$imgs = $xpath->query("//img[@src]|//video[@poster]");

			foreach ($imgs as $img) {

				$src = $img->tagName == "video" ? $img->getAttribute("poster") : $img->getAttribute("src");
				$src = $this->absolutize_url(rewrite_relative_url($article["link"], $src, $api_mode));

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

						$this->rewrite_duplicate($doc, $img, $api_mode);

						continue;
					}

					// check using perceptual hash duplicates

					$result = db_query("SELECT phash FROM ttrss_plugin_img_phash_urls WHERE
						owner_uid = $owner_uid AND
						url = '$src_escaped' LIMIT 1");

					if (db_num_rows($result) > 0) {
						$phash = db_escape_string(db_fetch_result($result, 0, "phash"));

						//$similarity = 15;

						$result = db_query("SELECT article_guid FROM ttrss_plugin_img_phash_urls WHERE
							owner_uid = $owner_uid AND
							created_at >= ".$this->interval_days(30)." AND
							".$this->bitcount_func($phash)." <= $similarity ORDER BY created_at LIMIT 1");

						if (db_num_rows($result) > 0) {

							$test_guid = db_fetch_result($result, 0, "article_guid");

							if ($test_guid != $article_guid) {
								$need_saving = true;

								$this->rewrite_duplicate($doc, $img, $api_mode);
							}
						}
					}
				}
			}
		}

		if ($need_saving) $article["content"] = $doc->saveXML();

		return $article;
	}


	function hook_house_keeping() {
		$files = glob($this->cache_dir . "/*.png", GLOB_NOSORT);

		foreach ($files as $file) {
			if (filemtime($file) < time() - 86400 * $this->cache_max_age) {
				unlink($file);
			}
		}

		db_query("DELETE FROM ttrss_plugin_img_phash_urls WHERE created_at < ".$this->interval_days(180));
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

	private function guid_to_article_title($article_guid, $owner_uid) {
		$result = db_query("SELECT feed_id, title, updated 
			FROM ttrss_entries, ttrss_user_entries 
			WHERE ref_id = id AND 
				guid = '$article_guid' AND
				owner_uid = $owner_uid");

		if (db_num_rows($result) != 0) {
			$article_title = db_fetch_result($result, 0, "title");
			$feed_id = db_fetch_result($result, 0, "feed_id");
			$updated = db_fetch_result($result, 0, "updated");

			$article_title = "<span title='$article_guid'>$article_title</span>";

			$article_title .= " in <a href='#' onclick='viewfeed({feed: $feed_id})'>" . getFeedTitle($feed_id) . "</a>";

			$article_title .= " (" . make_local_datetime($updated, true) . ")";

		} else {
			$article_title = "N/A ($article_guid)";
		}

		return $article_title;
	}

	function showsimilar() {
		$url = db_escape_string($_REQUEST["param"]);
		$url_htmlescaped = htmlspecialchars($url);

		$owner_uid = $_SESSION["uid"];

		$similarity = (int) $this->host->get($this, "similarity", $this->default_similarity);

		print "<img style='float : right; max-width : 64px; max-height : 64px; height : auto; width : auto;' src=\"$url_htmlescaped\">";

		print "<h2><a target=\"_blank\" href=\"$url_htmlescaped\">".truncate_middle($url_htmlescaped, 48)."</a></h2>";

		$result = db_query("SELECT phash FROM ttrss_plugin_img_phash_urls WHERE
			owner_uid = $owner_uid AND
			url = '$url' LIMIT 1");

		if (db_num_rows($result) != 0) {

			$phash = db_escape_string(db_fetch_result($result, 0, "phash"));

			$result = db_query("SELECT article_guid FROM ttrss_plugin_img_phash_urls WHERE
							owner_uid = $owner_uid AND
							created_at >= ".$this->interval_days(30)." AND
							".$this->bitcount_func($phash)." <= $similarity ORDER BY created_at LIMIT 1");

			$article_guid = db_fetch_result($result, 0, "article_guid");

			$article_title = $this->guid_to_article_title($article_guid, $owner_uid);

			print "<p>Perceptual hash: " . base_convert($phash, 10, 16) . "<br/>";
			print "Registered to: " . $article_title . "</p>";

			$result = db_query("SELECT url, article_guid, ".$this->bitcount_func($phash)." AS distance
				FROM ttrss_plugin_img_phash_urls WHERE				
				".$this->bitcount_func($phash)." <= $similarity
				ORDER BY distance LIMIT 30");

			print "<ul class=\"browseFeedList\" style=\"border-width : 1px\">";

			while ($line = db_fetch_assoc($result)) {
				print "<li>";
				$url = htmlspecialchars($line["url"]);
				$distance = $line["distance"];
				$rel_article_guid = db_escape_string($line["article_guid"]);
				$article_title = $this->guid_to_article_title($rel_article_guid, $owner_uid);

				$ref_image = ($rel_article_guid == $article_guid) ? "score_high.png" : "score_neutral.png";

				print "<img src='images/$ref_image' style='vertical-align : top'> ";
				print "<div style='display : inline-block'><a target=\"_blank\" href=\"$url\">".truncate_middle($url, 48)."</a> ($distance)";
				print "<br/>$article_title";
				print "<br/><img style='max-width : 64px; max-height : 64px; height : auto; width : auto;' src=\"$url\"></div>";

				print "</li>";
			}

			print "</ul>";
		}


	}

	private function absolutize_url($src) {
		if (strpos($src, "//") === 0)
			$src = "https:" . $src;

		return $src;
	}

	private function interval_days($days) {
		if (DB_TYPE == "pgsql") {
			return "NOW() - INTERVAL '$days days' ";
		} else {
			return "DATE_SUB(NOW(), INTERVAL $days DAY) ";
		}
	}

	private function bitcount_func($phash) {
		if (DB_TYPE == "pgsql") {
			return "unique_1bits('$phash', phash)";
		} else {
			return "bit_count('$phash' ^ phash)";
		}
	}
}
?>
